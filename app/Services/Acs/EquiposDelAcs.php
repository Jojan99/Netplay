<?php

namespace App\Services\Acs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Los equipos del ACS que son de una empresa, y lo que se puede hacer con ellos.
 *
 * GenieACS es uno solo para toda la plataforma y no sabe de empresas: un
 * equipo se reconoce como de la empresa cuando su IP de WAN es la de uno de
 * sus clientes, su usuario PPPoE es el de uno de sus clientes o su serial
 * está en una de sus OLT. Lo que no se reconoce no se muestra: en el ACS hay
 * además decenas de "equipos" que registran los escáneres de internet.
 */
class EquiposDelAcs
{
    /** Cada cuánto se vuelve a leer la lista del ACS. */
    private const VIGENCIA = 60;

    /** Sin reportar en este tiempo, el equipo se muestra como sin conexión con el ACS. */
    public const REPORTA_CADA = 2 * 3600;

    private const FABRICANTES = [
        'huawei' => 'Huawei', 'cdt' => 'C-Data', 'cdtc' => 'C-Data', 'zte' => 'ZTE',
        'fiberhome' => 'FiberHome', 'tp-link' => 'TP-Link', 'nokia' => 'Nokia', 'vsol' => 'V-SOL',
    ];

    public function __construct(private int $companyId, private ?GenieAcs $acs = null)
    {
        $this->acs ??= GenieAcs::make();
    }

    // ── Lista ─────────────────────────────────────────────────────────────

    /**
     * Los equipos de la empresa, con su cliente.
     *
     * @return list<array<string,mixed>>
     */
    public function lista(): array
    {
        $vinculos = $this->vinculos();
        $filas = [];

        foreach ($this->todos() as $d) {
            $fila = $this->resumen($d, $vinculos);

            if ($fila) {
                $filas[] = $fila;
            }
        }

        usort($filas, fn ($a, $b) => strcmp((string) $b['ultimo_reporte'], (string) $a['ultimo_reporte']));

        return $filas;
    }

    /** El equipo del cliente, si el ACS tiene uno que se le pueda atribuir. */
    public function deCliente(int $userId): ?array
    {
        foreach ($this->lista() as $fila) {
            if (($fila['cliente']['user_id'] ?? null) === $userId) {
                return $this->detalle($fila['id']);
            }
        }

        return null;
    }

    // ── Detalle ───────────────────────────────────────────────────────────

    /**
     * Todo lo que el ACS sabe del equipo, si es de la empresa.
     *
     * @return array<string,mixed>|null
     */
    public function detalle(string $id): ?array
    {
        $resumen = $this->propio($id);

        if (!$resumen) {
            return null;
        }

        $d = $this->acs->dispositivo($id);

        if (!$d) {
            return null;
        }

        $raiz = isset($d['InternetGatewayDevice']) ? 'InternetGatewayDevice' : 'Device';

        return array_merge($resumen, [
            'hardware'  => self::v($d, "{$raiz}.DeviceInfo.HardwareVersion"),
            'software'  => self::v($d, "{$raiz}.DeviceInfo.SoftwareVersion"),
            'encendido_hace' => self::entero(self::v($d, "{$raiz}.DeviceInfo.UpTime")),
            'ultimo_arranque' => $d['_lastBoot'] ?? null,
            'registrado' => $d['_registered'] ?? null,
            'wan'       => self::wan($d, $raiz),
            'wifi'      => self::wifi($d, $raiz),
            'equipos'   => self::hosts($d, $raiz),
            'optica'    => self::optica($d),
            'url_acs'   => self::v($d, "{$raiz}.ManagementServer.URL"),
            'intervalo' => self::entero(self::v($d, "{$raiz}.ManagementServer.PeriodicInformInterval")),
        ]);
    }

    /**
     * El documento completo del equipo, si es de la empresa.
     *
     * Trae todo lo que el equipo publica —incluidas las claves de telnet y ssh
     * del fabricante—, así que es para uso interno del servidor: no se manda
     * al panel ni al portal.
     *
     * @return array<string,mixed>|null
     */
    public function documento(string $id): ?array
    {
        return $this->propio($id) ? $this->acs->dispositivo($id) : null;
    }

    // ── Acciones ──────────────────────────────────────────────────────────

    /**
     * Le pide al equipo los datos que muestra el panel.
     *
     * No se pide el árbol entero: hay equipos con una rama que responde error
     * —una C-Data contesta 9002 en X_CMS_PrivateNode— y esa falla corta la
     * sesión, así que las órdenes que venían detrás nunca llegaban y se
     * acumulaban. Se piden las ramas que se usan, y antes se limpia lo que
     * haya quedado trabado.
     */
    public function refrescar(string $id): array
    {
        $this->exigirPropio($id);
        $raiz = $this->raiz($id);

        $this->limpiarCola($id);

        $ramas = $raiz === 'InternetGatewayDevice'
            ? [
                'InternetGatewayDevice.DeviceInfo',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration',
                'InternetGatewayDevice.LANDevice.1.Hosts',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice',
            ]
            : ['Device.DeviceInfo', 'Device.WiFi', 'Device.Hosts', 'Device.IP'];

        $r = ['hecha' => true, 'en_cola' => false, 'estado' => 200, 'instancia' => null];

        foreach ($ramas as $rama) {
            $paso = $this->acs->tarea($id, ['name' => 'refreshObject', 'objectName' => $rama]);

            // Con que una quede en cola, el conjunto no está completo.
            if (!($paso['hecha'] ?? false)) {
                $r = $paso;
            }
        }

        return $r;
    }

    /** Borra las tareas viejas del equipo: una trabada bloquea a las demás. */
    private function limpiarCola(string $id): void
    {
        try {
            foreach ($this->acs->tareasPendientes($id) as $tarea) {
                $this->acs->borrarTarea((string) $tarea['_id']);
            }
        } catch (\Throwable $e) {
            Log::warning('[ACS] No se pudo limpiar la cola del equipo', ['equipo' => $id, 'error' => $e->getMessage()]);
        }
    }

    public function reiniciar(string $id): array
    {
        $this->exigirPropio($id);

        return $this->acs->tarea($id, ['name' => 'reboot']);
    }

    /**
     * Cambia el nombre y/o la clave de una red WiFi.
     *
     * La ruta de la clave depende del fabricante: Huawei la tiene en
     * PreSharedKey.1.KeyPassphrase y C-Data directamente en KeyPassphrase. Se
     * usa la que el equipo publica.
     */
    public function cambiarWifi(string $id, int $indice, ?string $ssid, ?string $clave): array
    {
        $this->exigirPropio($id);

        $d = $this->acs->dispositivo($id);
        $red = collect(self::wifi($d, $this->raiz($id, $d)))->firstWhere('indice', $indice);

        if (!$red) {
            throw new \InvalidArgumentException('El equipo no tiene esa red WiFi.');
        }

        $valores = [];

        if ($ssid !== null && $ssid !== '') {
            if (mb_strlen($ssid) > 32) {
                throw new \InvalidArgumentException('El nombre de la red admite hasta 32 caracteres.');
            }
            $valores[] = [$red['ruta_ssid'], $ssid, 'xsd:string'];
        }

        if ($clave !== null && $clave !== '') {
            if (strlen($clave) < 8 || strlen($clave) > 63) {
                throw new \InvalidArgumentException('La contraseña WiFi debe tener entre 8 y 63 caracteres.');
            }
            if (!$red['ruta_clave']) {
                throw new \InvalidArgumentException('Este equipo no permite cambiar la contraseña por TR-069.');
            }
            $valores[] = [$red['ruta_clave'], $clave, 'xsd:string'];
        }

        if (!$valores) {
            throw new \InvalidArgumentException('No hay nada que cambiar.');
        }

        return $this->acs->tarea($id, ['name' => 'setParameterValues', 'parameterValues' => $valores]);
    }

    // ── Pertenencia ───────────────────────────────────────────────────────

    /** El resumen del equipo si es de la empresa; null si no. */
    private function propio(string $id): ?array
    {
        foreach ($this->lista() as $fila) {
            if ($fila['id'] === $id) {
                return $fila;
            }
        }

        return null;
    }

    private function exigirPropio(string $id): void
    {
        if (!$this->propio($id)) {
            throw new \InvalidArgumentException('Ese equipo no es de tu empresa.');
        }
    }

    private function raiz(string $id, ?array $d = null): string
    {
        $d ??= $this->acs->dispositivo($id);

        return isset($d['InternetGatewayDevice']) ? 'InternetGatewayDevice' : 'Device';
    }

    /**
     * Lo que permite reconocer un equipo como de la empresa.
     *
     * @return array{ips:array<string,object>, pppoe:array<string,object>, series:array<string,object>}
     */
    private function vinculos(): array
    {
        return Cache::remember("acs:vinculos:{$this->companyId}", self::VIGENCIA, function () {
            $clientes = DB::table('user_data as ud')
                ->join('users as u', 'u.id', '=', 'ud.user_id')
                ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
                ->where('u.company_id', $this->companyId)
                ->where('ud.active', 1)
                ->get(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.pppoe_user', 't.ip']);

            $ips = [];
            $pppoe = [];

            foreach ($clientes as $c) {
                $c->nombre = trim($c->names . ' ' . $c->lastname);

                if ($c->ip) {
                    $ips[$c->ip] = $c;
                }

                if ($c->pppoe_user) {
                    $pppoe[strtolower($c->pppoe_user)] = $c;
                }
            }

            $porUsuario = $clientes->keyBy('user_id');
            $series = [];

            foreach (DB::table('olt_onts as o')
                ->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
                ->where('a.company_id', $this->companyId)
                ->get(['o.serial', 'o.fsp', 'o.ont_id', 'o.user_data_id', 'a.name as olt']) as $o) {
                $o->cliente = $o->user_data_id ? ($porUsuario[$o->user_data_id] ?? null) : null;
                $series[self::serial((string) $o->serial)] = $o;
            }

            return compact('ips', 'pppoe', 'series');
        });
    }

    /**
     * Todos los equipos del ACS con lo necesario para reconocerlos. Es la
     * misma lista para todas las empresas, así que se guarda una sola vez.
     *
     * @return list<array<string,mixed>>
     */
    private function todos(): array
    {
        return Cache::remember('acs:equipos', self::VIGENCIA, fn () => $this->acs->dispositivos([], [
            '_id', '_deviceId', '_lastInform', '_lastBoot', '_registered',
            'InternetGatewayDevice.WANDevice', 'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'Device.PPP.Interface', 'Device.IP.Interface', 'Device.DeviceInfo.SoftwareVersion',
        ]));
    }

    /** @return array<string,mixed>|null  null si el equipo no es de la empresa */
    private function resumen(array $d, array $vinculos): ?array
    {
        $ips = [];
        $usuarios = [];

        foreach (self::parametros($d) as $ruta => $valor) {
            if ($valor === '' || $valor === null) {
                continue;
            }
            if (preg_match('/(ExternalIPAddress|IPv4Address\.\d+\.IPAddress)$/', $ruta)) {
                $ips[] = (string) $valor;
            }
            if (preg_match('/(WANPPPConnection\.\d+|PPP\.Interface\.\d+)\.Username$/', $ruta)) {
                $usuarios[] = strtolower((string) $valor);
            }
        }

        $cliente = null;
        $ont = null;
        $via = null;

        foreach ($usuarios as $u) {
            if (isset($vinculos['pppoe'][$u])) {
                [$cliente, $via] = [$vinculos['pppoe'][$u], 'pppoe'];
                break;
            }
        }

        if (!$cliente) {
            foreach ($ips as $ip) {
                if (isset($vinculos['ips'][$ip])) {
                    [$cliente, $via] = [$vinculos['ips'][$ip], 'ip'];
                    break;
                }
            }
        }

        $o = $vinculos['series'][self::serial((string) ($d['_deviceId']['_SerialNumber'] ?? ''))] ?? null;

        if ($o) {
            $ont = ['olt' => $o->olt, 'fsp' => $o->fsp, 'ont_id' => $o->ont_id];
            $cliente ??= $o->cliente;
            $via ??= 'serial';
        }

        if (!$cliente && !$ont) {
            return null;
        }

        $ultimo = $d['_lastInform'] ?? null;

        return [
            'id'             => $d['_id'],
            'fabricante'     => self::fabricante((string) ($d['_deviceId']['_Manufacturer'] ?? '')),
            'modelo'         => $d['_deviceId']['_ProductClass'] ?? null,
            'serial'         => $d['_deviceId']['_SerialNumber'] ?? null,
            'ultimo_reporte' => $ultimo,
            'reportando'     => $ultimo && (time() - strtotime($ultimo)) < self::REPORTA_CADA,
            'ip_wan'         => $ips[0] ?? null,
            'pppoe'          => $usuarios[0] ?? null,
            'cliente'        => $cliente ? [
                'user_id'   => (int) $cliente->user_id,
                'nombre'    => $cliente->nombre,
                'documento' => $cliente->dni,
            ] : null,
            'ont'            => $ont,
            'vinculo'        => $via,
        ];
    }

    // ── Lectura del árbol de parámetros ───────────────────────────────────

    /** @return list<array<string,mixed>> */
    private static function wan(array $d, string $raiz): array
    {
        $conexiones = [];

        if ($raiz === 'InternetGatewayDevice') {
            foreach (self::hijos($d, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice') as $c) {
                foreach (['WANPPPConnection' => 'PPPoE', 'WANIPConnection' => 'IP'] as $tipo => $nombre) {
                    foreach (self::hijos($d, "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$c}.{$tipo}") as $i) {
                        $b = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$c}.{$tipo}.{$i}";
                        $conexiones[] = [
                            'tipo'    => $nombre,
                            'nombre'  => self::v($d, "{$b}.Name"),
                            'estado'  => self::v($d, "{$b}.ConnectionStatus"),
                            'ip'      => self::v($d, "{$b}.ExternalIPAddress") ?: null,
                            'mac'     => self::v($d, "{$b}.MACAddress"),
                            'usuario' => self::v($d, "{$b}.Username"),
                            'modo'    => self::v($d, "{$b}.AddressingType"),
                        ];
                    }
                }
            }
        }

        return array_values(array_filter($conexiones, fn ($c) => $c['ip'] || $c['estado'] || $c['usuario']));
    }

    /** @return list<array<string,mixed>> */
    private static function wifi(array $d, string $raiz): array
    {
        $redes = [];

        if ($raiz === 'InternetGatewayDevice') {
            foreach (self::hijos($d, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration') as $i) {
                $b = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}";
                $conPsk = self::existe($d, "{$b}.PreSharedKey.1.KeyPassphrase");
                $estandar = self::v($d, "{$b}.Standard");

                $redes[] = [
                    'indice'    => (int) $i,
                    'ssid'      => self::v($d, "{$b}.SSID"),
                    'activo'    => self::booleano(self::v($d, "{$b}.Enable")),
                    'clave'     => (self::v($d, "{$b}.KeyPassphrase") ?: self::v($d, "{$b}.PreSharedKey.1.KeyPassphrase")) ?: null,
                    'banda'     => self::banda(self::v($d, "{$b}.OperatingFrequencyBand"), $estandar, (int) $i),
                    'estandar'  => $estandar,
                    'canal'     => self::v($d, "{$b}.Channel"),
                    'seguridad' => self::v($d, "{$b}.BeaconType"),
                    'conectados' => self::entero(self::v($d, "{$b}.TotalAssociations")),
                    'leido_en'  => self::marca($d, "{$b}.SSID"),
                    'ruta_ssid' => "{$b}.SSID",
                    'ruta_clave' => $conPsk ? "{$b}.PreSharedKey.1.KeyPassphrase"
                        : (self::existe($d, "{$b}.KeyPassphrase") ? "{$b}.KeyPassphrase" : null),
                ];
            }
        } else {
            foreach (self::hijos($d, 'Device.WiFi.SSID') as $i) {
                $redes[] = [
                    'indice'    => (int) $i,
                    'ssid'      => self::v($d, "Device.WiFi.SSID.{$i}.SSID"),
                    'activo'    => self::booleano(self::v($d, "Device.WiFi.SSID.{$i}.Enable")),
                    'clave'     => self::v($d, "Device.WiFi.AccessPoint.{$i}.Security.KeyPassphrase") ?: null,
                    'banda'     => null,
                    'estandar'  => null,
                    'canal'     => null,
                    'seguridad' => self::v($d, "Device.WiFi.AccessPoint.{$i}.Security.ModeEnabled"),
                    'conectados' => self::entero(self::v($d, "Device.WiFi.AccessPoint.{$i}.AssociatedDeviceNumberOfEntries")),
                    'leido_en'  => self::marca($d, "Device.WiFi.SSID.{$i}.SSID"),
                    'ruta_ssid' => "Device.WiFi.SSID.{$i}.SSID",
                    'ruta_clave' => "Device.WiFi.AccessPoint.{$i}.Security.KeyPassphrase",
                ];
            }
        }

        return array_values(array_filter($redes, fn ($r) => $r['ssid'] !== null && $r['ssid'] !== ''));
    }

    /** @return list<array<string,mixed>> */
    private static function hosts(array $d, string $raiz): array
    {
        $base = $raiz === 'InternetGatewayDevice' ? 'InternetGatewayDevice.LANDevice.1.Hosts.Host' : 'Device.Hosts.Host';
        $equipos = [];

        foreach (self::hijos($d, $base) as $i) {
            $b = "{$base}.{$i}";
            $mac = self::v($d, "{$b}.MACAddress") ?: self::v($d, "{$b}.PhysAddress");

            if (!$mac) {
                continue;
            }

            $equipos[] = [
                'nombre'   => self::v($d, "{$b}.HostName") ?: null,
                'ip'       => self::v($d, "{$b}.IPAddress") ?: null,
                'mac'      => strtoupper((string) $mac),
                'activo'   => self::booleano(self::v($d, "{$b}.Active")),
                'interfaz' => self::v($d, "{$b}.InterfaceType") ?: self::v($d, "{$b}.Layer2Interface"),
            ];
        }

        usort($equipos, fn ($a, $b) => ($b['activo'] <=> $a['activo']) ?: strcmp((string) $a['nombre'], (string) $b['nombre']));

        return $equipos;
    }

    /** La potencia óptica, si el fabricante la publica (cada uno en su rama). */
    private static function optica(array $d): ?array
    {
        $o = [];

        foreach (self::parametros($d) as $ruta => $valor) {
            if (!is_numeric($valor)) {
                continue;
            }
            if (preg_match('/\.(RXPower|RxPower)$/', $ruta)) {
                $o['rx'] ??= (float) $valor;
            } elseif (preg_match('/\.(TXPower|TxPower)$/', $ruta)) {
                $o['tx'] ??= (float) $valor;
            } elseif (preg_match('/(Pon|Gpon|Epon|Optical).*\.Temperature$/i', $ruta)) {
                $o['temperatura'] ??= (float) $valor;
            }
        }

        return $o ?: null;
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    /** El valor de un parámetro por su ruta con puntos. */
    private static function v(array $d, string $ruta): mixed
    {
        $n = self::nodo($d, $ruta);

        return is_array($n) && array_key_exists('_value', $n) ? $n['_value'] : null;
    }

    private static function existe(array $d, string $ruta): bool
    {
        return is_array(self::nodo($d, $ruta));
    }

    /** Cuándo el ACS leyó ese parámetro por última vez. */
    private static function marca(array $d, string $ruta): ?string
    {
        $n = self::nodo($d, $ruta);

        return is_array($n) ? ($n['_timestamp'] ?? null) : null;
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

    /** Los índices numéricos bajo una rama: "1", "2", "5"… */
    private static function hijos(array $d, string $ruta): array
    {
        $n = self::nodo($d, $ruta);

        if (!is_array($n)) {
            return [];
        }

        $hijos = array_values(array_filter(array_keys($n), fn ($k) => ctype_digit((string) $k)));
        sort($hijos, SORT_NUMERIC);

        return $hijos;
    }

    /** @return iterable<string,mixed>  ruta => valor de todas las hojas */
    private static function parametros(array $d, string $prefijo = ''): iterable
    {
        foreach ($d as $k => $v) {
            if (!is_array($v) || (str_starts_with((string) $k, '_') && $prefijo === '')) {
                continue;
            }

            $ruta = $prefijo === '' ? (string) $k : "{$prefijo}.{$k}";

            if (array_key_exists('_value', $v)) {
                yield $ruta => $v['_value'];
            } elseif (!str_starts_with((string) $k, '_')) {
                yield from self::parametros($v, $ruta);
            }
        }
    }

    /** Serial comparable: GPON "HWTC1234ABCD" → "485754431234ABCD", MAC sin separadores. */
    public static function serial(string $s): string
    {
        $k = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $s));

        if (preg_match('/^([A-Z]{4})([0-9A-F]{8})$/', $k, $m)) {
            return strtoupper(bin2hex($m[1])) . $m[2];
        }

        return $k;
    }

    private static function fabricante(string $crudo): string
    {
        $k = strtolower(trim($crudo));

        foreach (self::FABRICANTES as $clave => $nombre) {
            if (str_starts_with($k, $clave)) {
                return $nombre;
            }
        }

        return $crudo;
    }

    private static function banda(mixed $declarada, mixed $estandar, int $indice): string
    {
        if (is_string($declarada) && $declarada !== '') {
            return str_contains($declarada, '5') ? '5 GHz' : '2.4 GHz';
        }

        $e = strtolower((string) $estandar);

        if ($e !== '' && preg_match('/\b(a|ac|ax|a\/n|n\/ac|ac\/ax)\b/', $e) && !preg_match('/[bg]/', $e)) {
            return '5 GHz';
        }

        // Huawei numera las redes de 5 GHz desde la 5.
        return $indice >= 5 ? '5 GHz' : '2.4 GHz';
    }

    private static function booleano(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'up', 'yes'], true);
    }

    private static function entero(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
