<?php

namespace App\Services\Acs;

/**
 * El router del cliente visto desde el portal: lo que el propio cliente puede
 * ver y cambiar de su equipo.
 *
 * Se apoya en EquiposDelAcs, que ya sólo deja ver equipos de la empresa, y
 * encima filtra al equipo de ese cliente. Nunca devuelve lo que es del
 * operador —contraseñas de telnet o ssh del equipo, usuario PPPoE, la URL del
 * ACS—: el portal es del cliente, no de la administración.
 *
 * El panel aparece únicamente si el equipo del cliente reporta al TR-069, así
 * que se habilita solo a medida que se van configurando los equipos.
 */
class RouterDelCliente
{
    public function __construct(private int $userId, private int $companyId) {}

    /** @return array<string,mixed> */
    public function panel(): array
    {
        $equipo = $this->miEquipo();

        if (!$equipo) {
            return ['activo' => false];
        }

        $acs = new EquiposDelAcs($this->companyId);
        $d   = $acs->detalle($equipo['id']);
        $raw = $acs->documento($equipo['id']) ?? [];

        if (!$d) {
            return ['activo' => false];
        }

        return [
            'activo'  => true,
            'equipo'  => [
                'marca'          => $d['fabricante'],
                'modelo'         => $d['modelo'],
                'reportando'     => $d['reportando'],
                'ultimo_reporte' => $d['ultimo_reporte'],
                'encendido_hace' => $d['encendido_hace'] ?? null,
            ],
            'redes'         => $this->redes($d),
            'dispositivos'  => $this->dispositivos($d, $raw),
            'consumo'       => $this->consumo($d, $raw),
            'puede_bloquear' => (bool) $this->filtro($raw),
        ];
    }

    /** Cambia el nombre o la contraseña de una de sus redes WiFi. */
    public function cambiarWifi(int $indice, ?string $ssid, ?string $clave): array
    {
        $equipo = $this->exigirEquipo();

        return (new EquiposDelAcs($this->companyId))->cambiarWifi($equipo['id'], $indice, $ssid, $clave);
    }

    /** Le pide al equipo que mande sus datos al momento. */
    public function refrescar(): array
    {
        $equipo = $this->exigirEquipo();

        return (new EquiposDelAcs($this->companyId))->refrescar($equipo['id']);
    }

    /**
     * Bloquea un equipo de la casa por su MAC.
     *
     * El filtro por MAC no es igual en todos los equipos: cada fabricante lo
     * pone en su propia rama. Se usa la del equipo si la tiene y, si no, el
     * portal ni siquiera ofrece el botón.
     */
    public function bloquear(string $mac): array
    {
        return $this->filtrar($mac, true);
    }

    public function desbloquear(string $mac): array
    {
        return $this->filtrar($mac, false);
    }

    // ── Interno ───────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function miEquipo(): ?array
    {
        foreach ((new EquiposDelAcs($this->companyId))->lista() as $fila) {
            if (($fila['cliente']['user_id'] ?? null) === $this->userId) {
                return $fila;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function exigirEquipo(): array
    {
        return $this->miEquipo() ?? throw new \InvalidArgumentException('No tienes un equipo conectado al sistema.');
    }

    /**
     * Sus redes WiFi. La contraseña casi nunca llega: los equipos la devuelven
     * en blanco a propósito. Se puede cambiar aunque no se pueda leer.
     *
     * @return list<array<string,mixed>>
     */
    private function redes(array $d): array
    {
        return array_values(array_map(fn ($r) => [
            'indice'      => $r['indice'],
            'nombre'      => $r['ssid'],
            'banda'       => $r['banda'],
            'activa'      => $r['activo'] !== false,
            'clave'       => $r['clave'],
            'conectados'  => $r['conectados'],
            'puede_cambiar_clave' => (bool) $r['ruta_clave'],
        ], array_filter($d['wifi'] ?? [], fn ($r) => $r['activo'] !== false)));
    }

    /**
     * Lo que hay conectado en la casa, separando WiFi de cable.
     *
     * @return list<array<string,mixed>>
     */
    private function dispositivos(array $d, array $raw): array
    {
        $bloqueadas = $this->macsBloqueadas($raw);

        return array_values(array_map(function ($h) use ($bloqueadas) {
            $tipo = strtolower((string) ($h['interfaz'] ?? ''));

            return [
                'nombre'    => $h['nombre'] ?: 'Equipo sin nombre',
                'mac'       => $h['mac'],
                'ip'        => $h['ip'],
                'conexion'  => str_contains($tipo, '802.11') || str_contains($tipo, 'wifi') || str_contains($tipo, 'wlan')
                    ? 'wifi'
                    : (str_contains($tipo, 'ethernet') || str_contains($tipo, '802.3') ? 'cable' : 'desconocida'),
                'conectado' => (bool) $h['activo'],
                'bloqueado' => in_array(strtoupper((string) $h['mac']), $bloqueadas, true),
            ];
        }, $d['equipos'] ?? []));
    }

    /**
     * Cuánto ha pasado por el equipo. Son contadores del propio equipo desde
     * que se encendió, no el consumo del mes: se dice así en el portal.
     *
     * @return array<string,mixed>|null
     */
    private function consumo(array $d, array $raw): ?array
    {
        $pon = ['bajada' => null, 'subida' => null];
        $wan = ['bajada' => null, 'subida' => null];

        foreach (self::hojas($raw) as $ruta => $valor) {
            if (!is_numeric($valor)) {
                continue;
            }

            // El contador del enlace de fibra es el bueno: cuenta todo lo que
            // entró y salió de la casa. El de la conexión PPPoE se reinicia
            // cada vez que la sesión se cae y se vuelve a levantar.
            $destino = preg_match('/(Epon|Gpon|Pon)InterfaceConfig/i', $ruta) ? 'pon'
                : (str_contains($ruta, 'WANCommonInterfaceConfig') ? 'wan' : null);

            if (!$destino) {
                continue;
            }

            if (preg_match('/BytesReceived$/i', $ruta)) {
                ${$destino}['bajada'] ??= (float) $valor;
            } elseif (preg_match('/BytesSent$/i', $ruta)) {
                ${$destino}['subida'] ??= (float) $valor;
            }
        }

        $c = $pon['bajada'] !== null || $pon['subida'] !== null ? $pon : $wan;

        if ($c['bajada'] === null && $c['subida'] === null) {
            return null;
        }

        return [
            'bajada_bytes'   => $c['bajada'],
            'subida_bytes'   => $c['subida'],
            // Los contadores arrancan de cero cada vez que se enciende.
            'encendido_hace' => $d['encendido_hace'] ?? null,
        ];
    }

    /**
     * Dónde tiene este equipo el filtro por MAC, si lo tiene.
     *
     * @return array{lista:string, enable:string, modo:?string, campo:string}|null
     */
    private function filtro(array $raw): ?array
    {
        // C-Data y compatibles con el modelo de China Telecom.
        if (self::nodo($raw, 'InternetGatewayDevice.Services.X_CT-COM_USER.MACFilter') !== null) {
            return [
                'lista'  => 'InternetGatewayDevice.Services.X_CT-COM_USER.MACFilter.MACFilterList.',
                'enable' => 'InternetGatewayDevice.Services.X_CT-COM_USER.MACFilter.MACFilterEnable',
                'modo'   => 'InternetGatewayDevice.Services.X_CT-COM_USER.MACFilter.MACFilterMode',
                'campo'  => 'MACAddress',
            ];
        }

        // Huawei publica el filtro de WiFi en su propia rama.
        if (self::nodo($raw, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WlanMacFilter') !== null) {
            return [
                'lista'  => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WlanMacFilter.',
                'enable' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.MACAddressControlEnabled',
                'modo'   => null,
                'campo'  => 'MACAddress',
            ];
        }

        return null;
    }

    /** @return list<string> MAC en mayúsculas que el equipo tiene filtradas */
    private function macsBloqueadas(array $raw): array
    {
        $filtro = $this->filtro($raw);

        if (!$filtro) {
            return [];
        }

        $macs = [];

        foreach (self::hojas($raw) as $ruta => $valor) {
            if (str_starts_with($ruta, $filtro['lista']) && str_ends_with($ruta, '.' . $filtro['campo']) && $valor) {
                $macs[] = strtoupper((string) $valor);
            }
        }

        return $macs;
    }

    /** @return array{ok:bool, mensaje:string} */
    private function filtrar(string $mac, bool $bloquear): array
    {
        $mac = strtoupper(trim($mac));

        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
            throw new \InvalidArgumentException('Esa dirección MAC no tiene el formato correcto.');
        }

        $equipo = $this->exigirEquipo();
        $acs    = new EquiposDelAcs($this->companyId);
        $raw    = $acs->documento($equipo['id']) ?? [];
        $filtro = $this->filtro($raw);

        if (!$filtro) {
            throw new \InvalidArgumentException('Tu equipo no permite bloquear dispositivos desde aquí.');
        }

        $api = GenieAcs::make();

        if (!$bloquear) {
            foreach (self::hojas($raw) as $ruta => $valor) {
                if (str_starts_with($ruta, $filtro['lista']) && str_ends_with($ruta, '.' . $filtro['campo'])
                    && strtoupper((string) $valor) === $mac) {
                    $objeto = substr($ruta, 0, -strlen('.' . $filtro['campo'])) . '.';
                    $r = $api->tarea($equipo['id'], ['name' => 'deleteObject', 'objectName' => $objeto]);

                    return ['ok' => true, 'mensaje' => self::cuando($r, 'El dispositivo quedó desbloqueado')];
                }
            }

            return ['ok' => true, 'mensaje' => 'Ese dispositivo no estaba bloqueado.'];
        }

        $nuevo = $api->tarea($equipo['id'], ['name' => 'addObject', 'objectName' => $filtro['lista']]);

        if (!($nuevo['instancia'] ?? null)) {
            return [
                'ok' => false,
                'mensaje' => 'Tu equipo está fuera de línea en este momento. Intenta de nuevo en unos minutos.',
            ];
        }

        $valores = [
            [$filtro['lista'] . $nuevo['instancia'] . '.' . $filtro['campo'], $mac, 'xsd:string'],
            [$filtro['enable'], 'true', 'xsd:boolean'],
        ];

        if ($filtro['modo']) {
            // En este modelo el modo es la lista negra: se bloquea lo que está.
            $valores[] = [$filtro['modo'], 'false', 'xsd:boolean'];
        }

        $r = $api->tarea($equipo['id'], ['name' => 'setParameterValues', 'parameterValues' => $valores]);

        return ['ok' => true, 'mensaje' => self::cuando($r, 'El dispositivo quedó bloqueado')];
    }

    private static function cuando(array $r, string $hecho): string
    {
        return ($r['hecha'] ?? false)
            ? "{$hecho}."
            : "{$hecho} en cuanto el equipo se vuelva a conectar.";
    }

    /** @return iterable<string,mixed> */
    private static function hojas(array $d, string $prefijo = ''): iterable
    {
        foreach ($d as $k => $v) {
            if (!is_array($v) || str_starts_with((string) $k, '_')) {
                continue;
            }

            $ruta = $prefijo === '' ? (string) $k : "{$prefijo}.{$k}";

            if (array_key_exists('_value', $v)) {
                yield $ruta => $v['_value'];
            } else {
                yield from self::hojas($v, $ruta);
            }
        }
    }

    private static function nodo(array $d, string $ruta): mixed
    {
        foreach (explode('.', $ruta) as $parte) {
            if (!is_array($d) || !array_key_exists($parte, $d)) {
                return null;
            }
            $d = $d[$parte];
        }

        return $d;
    }
}
