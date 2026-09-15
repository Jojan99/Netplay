<?php

namespace App\Services\Red;

use App\Models\Aprovisionamiento;
use App\Models\GestionRemota;
use App\Models\OltAdmin;
use App\Models\UserData;
use App\Services\Acs\EquiposDelAcs;
use App\Services\Acs\GenieAcs;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Aprovisionamiento automático de una ONT recién autorizada.
 *
 * Al autorizar, si la empresa lo tiene encendido, queda programado con los
 * datos del cliente vinculado: su conexión a internet (IP fija o PPPoE en la
 * VLAN elegida), el WiFi y la cuenta de administración del equipo. Cuando la
 * ONT aparece en el TR-069 —el acceso remoto le da la dirección— la tarea que
 * corre cada minuto se lo aplica y deja anotado cada paso.
 *
 * La conexión a internet por TR-069 está hecha para Huawei (rama X_HW). En las
 * demás marcas se configura el WiFi y la WAN queda indicada para cargarla en
 * el equipo: mandar parámetros que nadie probó puede dejar al cliente sin
 * servicio.
 */
class AprovisionamientoDeOnt
{
    /** Si en este tiempo el equipo no aparece en el TR-069, se deja de esperar. */
    public const ESPERA_MINUTOS = 90;

    private const DNS = '8.8.8.8,8.8.4.4';
    private const WAN = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';
    private const WLAN = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration';
    private const CUENTAS = 'InternetGatewayDevice.UserInterface.X_HW_WebUserInfo';

    public function __construct(private int $companyId) {}

    // ── Ajustes ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function ajustes(): array
    {
        $g = GestionRemota::firstOrCreate(['company_id' => $this->companyId]);

        return [
            'aprovisionar'    => (bool) $g->aprovisionar,
            'wan'             => (bool) $g->aprov_wan,
            'wifi'            => (bool) $g->aprov_wifi,
            'admin'           => (bool) $g->aprov_admin,
            'wifi_prefijo'    => $g->wifi_prefijo,
            'prefijo_sugerido' => $this->prefijoPorDefecto(),
            'admin_usuario'   => $g->onu_admin_usuario,
            // La clave no sale nunca: sólo si hay una guardada.
            'admin_con_clave' => (bool) $g->onu_admin_clave,
            'gestion_activa'  => (bool) $g->activa,
        ];
    }

    /** @param array<string,mixed> $d */
    public function guardarAjustes(array $d): array
    {
        $g = GestionRemota::firstOrCreate(['company_id' => $this->companyId]);

        $cambios = [
            'aprovisionar'      => (bool) ($d['aprovisionar'] ?? false),
            'aprov_wan'         => (bool) ($d['wan'] ?? true),
            'aprov_wifi'        => (bool) ($d['wifi'] ?? true),
            'aprov_admin'       => (bool) ($d['admin'] ?? false),
            'wifi_prefijo'      => mb_substr(self::limpiarSsid((string) ($d['wifi_prefijo'] ?? '')), 0, 20) ?: null,
            'onu_admin_usuario' => trim((string) ($d['admin_usuario'] ?? '')) ?: null,
        ];

        // Sólo se cambia si llega una: la pantalla no la recibe nunca.
        if ((string) ($d['admin_clave'] ?? '') !== '') {
            $cambios['onu_admin_clave'] = (string) $d['admin_clave'];
        }

        if ($cambios['aprov_admin'] && (!$cambios['onu_admin_usuario'] || !(($cambios['onu_admin_clave'] ?? null) ?: $g->onu_admin_clave))) {
            throw new \InvalidArgumentException('Para cambiar la cuenta de administración de los equipos hacen falta usuario y clave.');
        }

        $g->fill($cambios)->save();

        return $this->ajustes();
    }

    /** Los últimos programados, con sus pasos. @return list<array<string,mixed>> */
    public function ultimos(int $cuantos = 20): array
    {
        return Aprovisionamiento::where('company_id', $this->companyId)
            ->latest('id')->limit($cuantos)->get()
            ->map(fn (Aprovisionamiento $a) => [
                'id'         => $a->id,
                'olt_id'     => $a->olt_id,
                'ont'        => "{$a->fsp}:{$a->ont_id}",
                'serial'     => $a->serial,
                'cliente'    => $a->datos['cliente'] ?? null,
                'wan'        => $a->datos['wan'] ?? null,
                'wifi_ssid'  => $a->datos['wifi']['ssid'] ?? null,
                'wifi_clave' => $a->wifi_clave,
                'estado'     => $a->estado,
                'detalle'    => $a->detalle,
                'pasos'      => $a->pasos ?? [],
                'creado'     => $a->created_at,
                'listo_en'   => $a->listo_en,
            ])->all();
    }

    // ── Al autorizar ──────────────────────────────────────────────────────

    /**
     * Deja programado el aprovisionamiento de la ONT recién autorizada.
     *
     * Devuelve el paso para mostrar en el alta, o null si la empresa no lo
     * tiene encendido.
     *
     * @param array<string,mixed> $pedido gateway y máscara de la red elegida, y el WiFi escrito en el alta
     */
    public static function programar(int $oltId, string $fsp, int $ontId, string $serial, ?int $userId, ?int $vlan, array $pedido, bool $forzar = false): ?array
    {
        $companyId = (int) (OltAdmin::find($oltId)?->company_id ?: 0);
        $g = $companyId ? GestionRemota::where('company_id', $companyId)->first() : null;

        // $forzar: un equipo puntual, aunque la empresa lo tenga apagado.
        if (!$g || (!$g->aprovisionar && !$forzar) || trim($serial) === '') {
            return null;
        }

        $yo = new self($companyId);
        $cliente = $userId ? $yo->cliente($userId) : null;
        $datos = ['cliente' => $cliente['nombre'] ?? null];
        $avisos = [];

        if ($g->aprov_wan) {
            [$wan, $aviso] = $yo->wanDelCliente($cliente, $vlan, $pedido);

            if ($wan) {
                $datos['wan'] = $wan;
            }
            if ($aviso) {
                $avisos[] = $aviso;
            }
        }

        $clave = null;

        if ($g->aprov_wifi) {
            $ssid = self::limpiarSsid((string) ($pedido['wifi_ssid'] ?? '')) ?: $yo->ssidPorDefecto($g, $cliente, $serial);
            $clave = trim((string) ($pedido['wifi_clave'] ?? '')) ?: self::claveWifi();
            $datos['wifi'] = ['ssid' => $ssid];
        }

        if ($g->aprov_admin && $g->onu_admin_usuario && $g->onu_admin_clave) {
            $datos['admin'] = true;
        }

        if (empty($datos['wan']) && empty($datos['wifi']) && empty($datos['admin'])) {
            return $avisos
                ? ['paso' => 'Aprovisionamiento del equipo', 'ok' => false, 'omitido' => true, 'detalle' => implode(' ', $avisos)]
                : null;
        }

        // Si la misma ONT se había programado (se volvió a autorizar), vale la última.
        Aprovisionamiento::where('company_id', $companyId)->where('serial', $serial)
            ->whereIn('estado', ['esperando', 'aplicando'])
            ->update(['estado' => 'reemplazado', 'detalle' => 'Se volvió a autorizar la ONT.']);

        $a = Aprovisionamiento::create([
            'company_id' => $companyId,
            'olt_id'     => $oltId,
            'fsp'        => $fsp,
            'ont_id'     => $ontId,
            'serial'     => $serial,
            'user_id'    => $cliente['id'] ?? null,
            'datos'      => $datos + ['avisos' => $avisos],
            'wifi_clave' => $clave,
            'estado'     => 'esperando',
            'pasos'      => [],
            'detalle'    => 'Esperando que el equipo aparezca en el TR-069…',
        ]);

        $partes = [];
        if (!empty($datos['wan'])) {
            $partes[] = 'internet ' . self::resumenWan($datos['wan']);
        }
        if (!empty($datos['wifi'])) {
            $partes[] = "WiFi «{$datos['wifi']['ssid']}» con clave {$clave}";
        }
        if (!empty($datos['admin'])) {
            $partes[] = 'cuenta de administración';
        }

        return [
            'paso'    => 'Aprovisionamiento del equipo',
            'ok'      => true,
            'detalle' => 'Programado: ' . implode(' · ', $partes) . '. Se aplica solo cuando el equipo aparezca en el TR-069; el resultado queda en Acceso remoto.'
                . ($avisos ? ' ' . implode(' ', $avisos) : ''),
            'aprovisionamiento' => $a->id,
        ];
    }

    /** @return array{id:int, nombre:string, nombres:string, apellidos:string, tipo:string, pppoe_usuario:?string, ip:?string}|null */
    private function cliente(int $userId): ?array
    {
        $c = DB::table('users as u')
            ->join('user_data as ud', 'ud.user_id', '=', 'u.id')
            ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('u.id', $userId)
            ->where('u.company_id', $this->companyId)
            ->first(['u.id', 'ud.names', 'ud.lastname', 'ud.connection_type', 'ud.pppoe_user', 't.ip']);

        return $c ? [
            'id'            => (int) $c->id,
            'nombre'        => trim("{$c->names} {$c->lastname}"),
            'nombres'       => (string) $c->names,
            'apellidos'     => (string) $c->lastname,
            'tipo'          => $c->connection_type === 'pppoe' ? 'pppoe' : 'static',
            'pppoe_usuario' => $c->pppoe_user ?: null,
            'ip'            => $c->ip ?: null,
        ] : null;
    }

    /** @return array{0:?array, 1:?string} la WAN a configurar, o por qué no */
    private function wanDelCliente(?array $c, ?int $vlan, array $pedido): array
    {
        if (!$c) {
            return [null, 'Sin cliente vinculado no se configura la conexión a internet.'];
        }

        if (!$vlan) {
            return [null, 'Sin VLAN elegida no se configura la conexión a internet.'];
        }

        if ($c['tipo'] === 'pppoe') {
            return $c['pppoe_usuario']
                ? [['tipo' => 'pppoe', 'usuario' => $c['pppoe_usuario'], 'vlan' => $vlan], null]
                : [null, 'El cliente es PPPoE pero no tiene usuario cargado: la conexión a internet no se configura.'];
        }

        if (!$c['ip']) {
            return [null, 'El cliente no tiene IP asignada: la conexión a internet no se configura.'];
        }

        $gateway = trim((string) ($pedido['gateway'] ?? ''));
        $bits = (int) ltrim((string) ($pedido['mascara'] ?? '24'), '/');
        $bits = $bits >= 8 && $bits <= 30 ? $bits : 24;

        if (!filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [null, 'No llegó el gateway de la red elegida: la conexión a internet no se configura.'];
        }

        $mascara = (-1 << (32 - $bits)) & 0xFFFFFFFF;

        // Una IP de otra red dejaría al cliente sin internet: mejor no tocar.
        if ((ip2long($c['ip']) & $mascara) !== (ip2long($gateway) & $mascara)) {
            return [null, "La IP del cliente ({$c['ip']}) no es de la red elegida ({$gateway}/{$bits}): la conexión a internet no se configura."];
        }

        return [[
            'tipo'    => 'static',
            'ip'      => $c['ip'],
            'mascara' => long2ip($mascara),
            'gateway' => $gateway,
            'vlan'    => $vlan,
            'dns'     => self::DNS,
        ], null];
    }

    private function ssidPorDefecto(GestionRemota $g, ?array $c, string $serial): string
    {
        $prefijo = $g->wifi_prefijo ?: $this->prefijoPorDefecto();
        $quien = $c
            ? (strtok(trim($c['apellidos']), ' ') ?: strtok(trim($c['nombres']), ' '))
            : substr(preg_replace('/[^0-9A-Za-z]/', '', $serial), -4);

        return mb_substr(self::limpiarSsid($prefijo . '_' . mb_strtoupper((string) $quien)), 0, 32);
    }

    private function prefijoPorDefecto(): string
    {
        $nombre = (string) DB::table('companies')->where('id', $this->companyId)->value('name');
        $primera = strtok(trim($nombre), ' ') ?: 'WIFI';

        return mb_substr(self::limpiarSsid(mb_strtoupper($primera)), 0, 12) ?: 'WIFI';
    }

    private static function limpiarSsid(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/[^A-Za-z0-9_\-. ]/', '', Str::ascii(trim($s)))), 0, 32);
    }

    /** Diez letras y números sin los que se confunden (l, 1, o, 0). */
    private static function claveWifi(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyz23456789';
        $clave = '';

        for ($i = 0; $i < 10; $i++) {
            $clave .= $letras[random_int(0, strlen($letras) - 1)];
        }

        return $clave;
    }

    private static function resumenWan(array $wan): string
    {
        return $wan['tipo'] === 'pppoe'
            ? "PPPoE ({$wan['usuario']}) en la VLAN {$wan['vlan']}"
            : "IP {$wan['ip']} en la VLAN {$wan['vlan']}";
    }

    // ── Cuando el equipo aparece ──────────────────────────────────────────

    /** Lo llama la tarea de cada minuto. Devuelve cuántos revisó. */
    public static function trabajarPendientes(): int
    {
        $revisados = 0;

        Aprovisionamiento::whereIn('estado', ['esperando', 'aplicando'])->orderBy('id')->limit(20)->get()
            ->each(function (Aprovisionamiento $a) use (&$revisados) {
                $revisados++;

                try {
                    (new self((int) $a->company_id))->trabajar($a);
                } catch (\Throwable $e) {
                    Log::warning('[Aprovisionamiento] No se pudo avanzar', ['id' => $a->id, 'error' => $e->getMessage()]);
                    $a->intentos++;
                    $a->detalle = mb_substr('Reintentando: ' . $e->getMessage(), 0, 250);

                    if ($a->intentos >= 10) {
                        $a->estado = 'error';
                    }

                    $a->save();
                }
            });

        return $revisados;
    }

    public function trabajar(Aprovisionamiento $a): void
    {
        $acs = GenieAcs::deEmpresa($this->companyId);

        if ($a->estado === 'esperando') {
            if ($a->created_at->lt(now()->subMinutes(self::ESPERA_MINUTOS))) {
                $a->fill([
                    'estado'  => 'vencido',
                    'detalle' => 'El equipo no apareció en el TR-069 en ' . self::ESPERA_MINUTOS . ' minutos. Revisá su acceso remoto y volvé a autorizarlo, o configuralo a mano.',
                ])->save();

                return;
            }

            $id = $this->buscarEnElAcs($acs, $a);

            if (!$id) {
                return;
            }

            $a->fill([
                'acs_id'  => $id,
                'estado'  => 'aplicando',
                'pasos'   => [['paso' => 'El equipo apareció en el TR-069', 'ok' => true, 'detalle' => $id]],
                'detalle' => 'Leyendo la configuración del equipo…',
            ])->save();

            $this->refrescar($acs, $id, true);
        }

        $d = $acs->dispositivo((string) $a->acs_id);

        if (!$d) {
            throw new \RuntimeException('El equipo ya no está en el TR-069.');
        }

        $igd = isset($d['InternetGatewayDevice']);
        $leido = $igd
            ? (self::hijos($d, self::WAN) || self::hijos($d, self::WLAN))
            : isset($d['Device']['WiFi']);

        // Hasta que el equipo no manda su configuración no se puede decidir qué
        // conexión ajustar ni qué redes WiFi tiene.
        if (!$leido) {
            $a->intentos++;

            if ($a->intentos % 3 === 0) {
                $this->refrescar($acs, (string) $a->acs_id, $igd);
            }

            if ($a->intentos >= 15) {
                $a->estado = 'error';
                $a->detalle = 'El equipo está en el TR-069 pero no manda su configuración: no se aplicó nada.';
            } else {
                $a->detalle = 'Esperando que el equipo mande su configuración…';
            }

            $a->save();

            return;
        }

        $huawei = ($d['_deviceId']['_OUI'] ?? '') === '00259E'
            || str_contains(strtoupper((string) ($d['_deviceId']['_Manufacturer'] ?? '')), 'HUAWEI');

        $pasos = $a->pasos ?? [];

        if (!empty($a->datos['wan'])) {
            $pasos[] = $this->aplicarWan($acs, $a, $d, $huawei && $igd);
        }
        if (!empty($a->datos['wifi'])) {
            $pasos[] = $this->aplicarWifi($acs, $a, $d);
        }
        if (!empty($a->datos['admin'])) {
            $pasos[] = $this->aplicarCuenta($acs, $a, $d, $huawei && $igd);
        }

        $mal = collect($pasos)->filter(fn ($p) => !$p['ok'] && empty($p['omitido']));

        $a->fill([
            'pasos'    => $pasos,
            'estado'   => $mal->isEmpty() ? 'listo' : 'con_errores',
            'detalle'  => $mal->isEmpty() ? 'Equipo aprovisionado.' : ($mal->count() === 1 ? 'Un paso no se aplicó.' : "{$mal->count()} pasos no se aplicaron."),
            'listo_en' => now(),
        ])->save();
    }

    /** El equipo en el ACS, sólo si reportó después de autorizarse. */
    private function buscarEnElAcs(GenieAcs $acs, Aprovisionamiento $a): ?string
    {
        $crudo = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $a->serial));
        $series = array_values(array_unique([$crudo, EquiposDelAcs::serial($a->serial)]));

        $filas = $acs->dispositivos(['_deviceId._SerialNumber' => ['$in' => $series]], ['_id', '_lastInform']);
        usort($filas, fn ($x, $y) => strcmp((string) ($y['_lastInform'] ?? ''), (string) ($x['_lastInform'] ?? '')));
        $fila = $filas[0] ?? null;

        // Uno viejo en la base del ACS (el equipo estuvo en otro lado) no sirve:
        // hay que esperar a que reporte ya autorizado.
        if (!$fila || empty($fila['_lastInform']) || Carbon::parse($fila['_lastInform'])->lt($a->created_at->copy()->subMinutes(2))) {
            return null;
        }

        return (string) $fila['_id'];
    }

    private function refrescar(GenieAcs $acs, string $id, bool $igd): void
    {
        $ramas = $igd ? [self::WAN, self::WLAN, 'InternetGatewayDevice.UserInterface'] : ['Device.WiFi'];

        foreach ($ramas as $rama) {
            try {
                $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => $rama]);
            } catch (\Throwable $e) {
                Log::info('[Aprovisionamiento] No se pudo pedir la configuración', ['equipo' => $id, 'rama' => $rama, 'error' => $e->getMessage()]);
            }
        }
    }

    // ── Los pasos ─────────────────────────────────────────────────────────

    private function aplicarWan(GenieAcs $acs, Aprovisionamiento $a, array $d, bool $soportado): array
    {
        $wan = $a->datos['wan'];
        $titulo = 'Conexión a internet';

        if (!$soportado) {
            return ['paso' => $titulo, 'ok' => false, 'omitido' => true,
                'detalle' => 'En esta marca la conexión a internet todavía no se configura por TR-069: cargala en el equipo (' . self::resumenWan($wan) . ').'];
        }

        $vlanGestion = (int) (GestionRemota::where('company_id', $this->companyId)->value('vlan') ?: 0);
        $objeto = $wan['tipo'] === 'pppoe' ? 'WANPPPConnection' : 'WANIPConnection';

        // La conexión de gestión no se toca nunca: por ahí llega el TR-069.
        $todas = collect($this->conexiones($d));
        $conexiones = $todas
            ->reject(fn ($c) => ($vlanGestion && $c['vlan'] === $vlanGestion) || $c['servicios'] === 'TR069');

        // Las de otra VLAN vienen del dueño anterior del equipo: no dan servicio
        // y chocan con la del cliente. Se borra cada grupo que sea sólo ajeno.
        $ajenas = $conexiones->filter(fn ($c) => $c['vlan'] !== null && $c['vlan'] !== (int) $wan['vlan']);
        $borradas = [];
        $quitados = [];

        foreach ($ajenas->groupBy('dispositivo') as $grupo => $delGrupo) {
            if ($todas->where('dispositivo', $grupo)->count() !== $delGrupo->count()) {
                continue; // comparte grupo con la de gestión o con la del cliente
            }

            try {
                $r = $acs->tarea((string) $a->acs_id, ['name' => 'deleteObject', 'objectName' => self::WAN . ".{$grupo}"]);
            } catch (\Throwable $e) {
                $r = ['hecha' => false];
            }

            if ($r['hecha'] ?? false) {
                $quitados[] = (string) $grupo;
                $borradas = array_merge($borradas, $delGrupo->pluck('nombre')->all());
            }
        }

        $conexiones = $conexiones->reject(fn ($c) => in_array((string) $c['dispositivo'], $quitados, true));
        $nota = $borradas ? ' Se borraron las conexiones ajenas: ' . implode(', ', $borradas) . '.' : '';

        $existente = $conexiones->first(fn ($c) => $c['objeto'] === $objeto && $c['vlan'] === (int) $wan['vlan'])
            ?? $conexiones->first(fn ($c) => $c['objeto'] === $objeto && str_contains($c['servicios'], 'INTERNET'));

        if ($existente) {
            $ruta = $existente['ruta'];
            $como = 'Se ajustó la conexión que ya tenía';
            $servicios = $existente['servicios'];
        } else {
            $choca = $conexiones->first(fn ($c) => $c['vlan'] === (int) $wan['vlan'] || str_contains($c['servicios'], 'INTERNET'));

            if ($choca) {
                return ['paso' => $titulo, 'ok' => false,
                    'detalle' => "El equipo ya tiene la conexión {$choca['nombre']} de otro tipo para internet: no se creó otra encima. Borrala en el equipo o revisá el tipo de conexión del cliente."];
            }

            $nueva = $this->crearObjeto($acs, (string) $a->acs_id, self::WAN);
            $conexion = $nueva ? $this->crearObjeto($acs, (string) $a->acs_id, self::WAN . ".{$nueva}.{$objeto}") : null;

            if (!$conexion) {
                return ['paso' => $titulo, 'ok' => false,
                    'detalle' => 'El equipo no respondió al crear la conexión. Si está en línea, volvé a autorizarlo o configurala a mano (' . self::resumenWan($wan) . ').'];
            }

            $ruta = self::WAN . ".{$nueva}.{$objeto}.{$conexion}";
            $como = 'Se creó la conexión';
            $servicios = '';
        }

        $valores = [
            ["{$ruta}.Enable", 'true', 'xsd:boolean'],
            ["{$ruta}.ConnectionType", 'IP_Routed', 'xsd:string'],
            ["{$ruta}.NATEnabled", 'true', 'xsd:boolean'],
            ["{$ruta}.X_HW_VLAN", (string) $wan['vlan'], 'xsd:unsignedInt'],
        ];

        // Si ya lleva también el TR-069 ("TR069_INTERNET") se deja así: quitárselo
        // lo sacaría del servidor.
        if (!str_contains($servicios, 'TR069')) {
            $valores[] = ["{$ruta}.X_HW_SERVICELIST", 'INTERNET', 'xsd:string'];
        }

        if ($wan['tipo'] === 'pppoe') {
            $clave = (string) (UserData::where('user_id', $a->user_id)->value('pppoe_password') ?? '');
            $clave = $clave !== '' ? self::descifrar($clave) : '';

            $valores[] = ["{$ruta}.Username", $wan['usuario'], 'xsd:string'];
            if ($clave !== '') {
                $valores[] = ["{$ruta}.Password", $clave, 'xsd:string'];
            }
        } else {
            $valores[] = ["{$ruta}.AddressingType", 'Static', 'xsd:string'];
            $valores[] = ["{$ruta}.ExternalIPAddress", $wan['ip'], 'xsd:string'];
            $valores[] = ["{$ruta}.SubnetMask", $wan['mascara'], 'xsd:string'];
            $valores[] = ["{$ruta}.DefaultGateway", $wan['gateway'], 'xsd:string'];
            $valores[] = ["{$ruta}.DNSServers", $wan['dns'] ?? self::DNS, 'xsd:string'];
        }

        $r = $acs->tarea((string) $a->acs_id, ['name' => 'setParameterValues', 'parameterValues' => $valores]);

        return $this->resultado($acs, (string) $a->acs_id, $r, $titulo, "{$como}: " . self::resumenWan($wan) . ($nota ? ".{$nota}" : ''));
    }

    private function aplicarWifi(GenieAcs $acs, Aprovisionamiento $a, array $d): array
    {
        $ssid = (string) $a->datos['wifi']['ssid'];
        $clave = (string) $a->wifi_clave;
        $titulo = 'WiFi';

        $redes = array_values(array_filter(EquiposDelAcs::redesWifi($d), fn ($r) => $r['activo'] !== false));

        if (!$redes) {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => 'El equipo no informó redes WiFi encendidas: no se cambió.'];
        }

        $varias = count($redes) > 1;
        $valores = [];
        $nombres = [];

        foreach ($redes as $red) {
            $cinco = str_contains((string) ($red['banda'] ?? ''), '5') || (int) $red['indice'] >= 5;
            $nombre = $varias && $cinco ? mb_substr($ssid, 0, 29) . '-5G' : $ssid;

            $valores[] = [$red['ruta_ssid'], $nombre, 'xsd:string'];

            foreach ($red['rutas_clave'] ?? [] as $ruta) {
                $valores[] = [$ruta, $clave, 'xsd:string'];
            }

            $nombres[] = "«{$nombre}»";
        }

        if (!collect($redes)->pluck('ruta_clave')->filter()->count()) {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => 'El equipo no deja cambiar la clave WiFi por TR-069.'];
        }

        $r = $acs->tarea((string) $a->acs_id, ['name' => 'setParameterValues', 'parameterValues' => $valores]);

        return $this->resultado($acs, (string) $a->acs_id, $r, $titulo, 'Redes ' . implode(' y ', $nombres) . ' con la clave del aprovisionamiento');
    }

    private function aplicarCuenta(GenieAcs $acs, Aprovisionamiento $a, array $d, bool $soportado): array
    {
        $titulo = 'Cuenta de administración del equipo';
        $g = GestionRemota::where('company_id', $this->companyId)->first();

        if (!$soportado) {
            return ['paso' => $titulo, 'ok' => false, 'omitido' => true, 'detalle' => 'En esta marca todavía no se cambia por TR-069.'];
        }

        $cuentas = self::hijos($d, self::CUENTAS);

        if (!$cuentas || !$g?->onu_admin_usuario || !$g->onu_admin_clave) {
            return ['paso' => $titulo, 'ok' => false, 'omitido' => true, 'detalle' => 'El equipo no informó sus cuentas de acceso web: no se cambió.'];
        }

        // La de nivel 0 es la de administración.
        $cuenta = collect($cuentas)->first(fn ($i) => (string) self::v($d, self::CUENTAS . ".{$i}.UserLevel") === '0');

        if ($cuenta === null) {
            return ['paso' => $titulo, 'ok' => false, 'omitido' => true, 'detalle' => 'No se encontró la cuenta de administración del equipo: no se cambió.'];
        }

        $r = $acs->tarea((string) $a->acs_id, ['name' => 'setParameterValues', 'parameterValues' => [
            [self::CUENTAS . ".{$cuenta}.UserName", $g->onu_admin_usuario, 'xsd:string'],
            [self::CUENTAS . ".{$cuenta}.Password", $g->onu_admin_clave, 'xsd:string'],
        ]]);

        return $this->resultado($acs, (string) $a->acs_id, $r, $titulo, "Usuario {$g->onu_admin_usuario} con la clave de la empresa");
    }

    /** Crea un objeto y devuelve su número; si quedó en cola se cancela, para que no aparezca vacío después. */
    private function crearObjeto(GenieAcs $acs, string $id, string $objeto): ?string
    {
        $r = $acs->tarea($id, ['name' => 'addObject', 'objectName' => $objeto]);

        if ($r['instancia'] ?? null) {
            return (string) $r['instancia'];
        }

        if ($r['id'] ?? null) {
            $acs->borrarTarea((string) $r['id']);
        }

        return null;
    }

    private function resultado(GenieAcs $acs, string $id, array $r, string $titulo, string $hecho): array
    {
        if ($r['hecha'] ?? false) {
            return ['paso' => $titulo, 'ok' => true, 'detalle' => "{$hecho}."];
        }

        $falla = ($r['id'] ?? null) ? $acs->fallaDeTarea($id, (string) $r['id']) : null;

        if ($falla) {
            // Una tarea rechazada se reintenta en cada reporte: se saca.
            $acs->borrarTarea((string) $r['id']);

            return ['paso' => $titulo, 'ok' => false, 'detalle' => "El equipo rechazó el cambio: {$falla}"];
        }

        return ['paso' => $titulo, 'ok' => true, 'detalle' => "{$hecho}. Se aplica en cuanto el equipo vuelva a reportar: no se le pudo avisar al momento."];
    }

    /** @return list<array{ruta:string, objeto:string, vlan:?int, servicios:string, nombre:string}> */
    private function conexiones(array $d): array
    {
        $lista = [];

        foreach (self::hijos($d, self::WAN) as $i) {
            foreach (['WANIPConnection', 'WANPPPConnection'] as $objeto) {
                foreach (self::hijos($d, self::WAN . ".{$i}.{$objeto}") as $j) {
                    $b = self::WAN . ".{$i}.{$objeto}.{$j}";
                    $vlan = self::v($d, "{$b}.X_HW_VLAN");

                    $lista[] = [
                        'ruta'      => $b,
                        'objeto'    => $objeto,
                        'vlan'      => $vlan !== null && $vlan !== '' ? (int) $vlan : null,
                        'servicios' => strtoupper((string) self::v($d, "{$b}.X_HW_SERVICELIST")),
                        'nombre'    => (string) (self::v($d, "{$b}.Name") ?: $b),
                        'dispositivo' => (string) $i,
                    ];
                }
            }
        }

        return $lista;
    }

    /** La clave PPPoE se guarda cifrada; si la columna trae texto plano se usa tal cual. */
    private static function descifrar(string $valor): string
    {
        try {
            return (string) decrypt($valor, false);
        } catch (\Throwable) {
            return $valor;
        }
    }

    private static function v(array $d, string $ruta): mixed
    {
        $x = $d;

        foreach (explode('.', $ruta) as $k) {
            if (!is_array($x) || !array_key_exists($k, $x)) {
                return null;
            }
            $x = $x[$k];
        }

        return is_array($x) && array_key_exists('_value', $x) ? $x['_value'] : null;
    }

    /** @return list<string> los números de instancia bajo un objeto */
    private static function hijos(array $d, string $ruta): array
    {
        $x = $d;

        foreach (explode('.', $ruta) as $k) {
            if (!is_array($x) || !isset($x[$k])) {
                return [];
            }
            $x = $x[$k];
        }

        return array_values(array_map('strval', array_filter(array_keys($x), fn ($k) => ctype_digit((string) $k))));
    }
}
