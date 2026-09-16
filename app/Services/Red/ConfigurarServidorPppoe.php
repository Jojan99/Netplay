<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Deja el router listo para atender clientes PPPoE.
 *
 * Montar PPPoE a mano son cuatro cosas en cuatro pantallas distintas de
 * Winbox, y si falta una nada funciona: hace falta un rango de IP para
 * repartir (pool), un perfil que diga de dónde salen esas IP y cuál es la
 * puerta de enlace, y un servidor escuchando en la interfaz por donde llegan
 * los clientes.
 *
 * Acá se hace todo junto y en el orden correcto. Es idempotente: si algo ya
 * existe se actualiza en vez de duplicarse, así se puede volver a correr sin
 * romper lo que ya andaba.
 */
class ConfigurarServidorPppoe
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private string $token,
    ) {}

    /**
     * Qué hace falta decidir antes de montarlo.
     *
     * @return array{interfaces:array, pools:array, perfiles:array, servidores:array, sugerencia:array}
     */
    public function opciones(): array
    {
        $api = $this->api();

        $interfaces = array_map(fn ($i) => [
            'nombre' => $i['name'] ?? '',
            'tipo'   => $i['type'] ?? '',
        ], $this->leer($api, '/interface/print'));

        return [
            'interfaces' => $interfaces,
            'pools'      => array_map(fn ($p) => [
                'nombre' => $p['name'] ?? '',
                'rangos' => $p['ranges'] ?? '',
            ], $this->leer($api, '/ip/pool/print')),
            'perfiles'   => array_map(fn ($p) => [
                'nombre'    => $p['name'] ?? '',
                'velocidad' => $p['rate-limit'] ?? null,
            ], $this->leer($api, '/ppp/profile/print')),
            'servidores' => array_map(fn ($s) => [
                'nombre'   => $s['service-name'] ?? '',
                'interfaz' => $s['interface'] ?? '',
            ], $this->leer($api, '/interface/pppoe-server/server/print')),
            // Por dónde sale el tráfico a internet, para el NAT.
            'salidas' => $this->salidas($api),
            // Un perfil por plan: en PPPoE la velocidad la fija el perfil.
            'planes'  => $this->planesPropuestos(),
            // Las direcciones del router: el rango nuevo no puede pisarlas.
            'direcciones' => array_values(array_filter(array_map(
                fn ($a) => ['red' => $a['address'] ?? '', 'interfaz' => $a['interface'] ?? ''],
                $this->leer($api, '/ip/address/print')
            ), fn ($a) => $a['red'] !== '' && !str_ends_with($a['red'], '/32'))),
            // Nombres y rango libres: proponer algo que ya existe lo pisaría.
            'sugerencia' => $this->sugerenciaLibre($api),
        ];
    }

    /**
     * Un perfil por cada plan de internet, con la velocidad del plan.
     *
     * MikroTik espera «subida/bajada» desde el punto de vista del cliente, y
     * los planes ya tienen las dos velocidades en megas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function planesPropuestos(): array
    {
        return DB::table('internet_plans')
            ->where('company_id', getSessionCompanyId())
            ->where('active', 1)
            ->orderBy('plan_name')
            ->get(['id', 'plan_name', 'download_speed', 'upload_speed', 'pppoe_profile'])
            ->map(function ($plan) {
                $bajada = (int) $plan->download_speed;
                $subida = (int) $plan->upload_speed ?: $bajada;

                return [
                    'plan_id'   => (int) $plan->id,
                    'plan'      => $plan->plan_name,
                    'bajada'    => $bajada,
                    'subida'    => $subida,
                    'perfil'    => $plan->pppoe_profile ?: $this->nombrePerfil($plan->plan_name),
                    'velocidad' => $bajada ? "{$subida}M/{$bajada}M" : null,
                    'ya_creado' => !empty($plan->pppoe_profile),
                ];
            })
            ->all();
    }

    /** Un nombre de perfil válido a partir del nombre del plan. */
    private function nombrePerfil(string $plan): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower(trim($plan)));

        return 'plan-' . trim($limpio, '-');
    }

    /**
     * Interfaces y listas por donde puede salir el tráfico a internet.
     *
     * @return array<int,array{nombre:string, tipo:string}>
     */
    private function salidas($api): array
    {
        $salidas = [];

        // Las listas son lo más común en configuraciones armadas con cuidado:
        // ahí suele estar agrupada la WAN.
        foreach ($this->leer($api, '/interface/list/print') as $l) {
            $nombre = $l['name'] ?? '';

            if ($nombre !== '' && !in_array($nombre, ['all', 'none', 'dynamic', 'static'], true)) {
                $salidas[] = ['nombre' => $nombre, 'tipo' => 'lista'];
            }
        }

        foreach ($this->leer($api, '/interface/print') as $i) {
            if (($i['type'] ?? '') === 'ether') {
                $salidas[] = ['nombre' => $i['name'] ?? '', 'tipo' => 'interfaz'];
            }
        }

        return $salidas;
    }

    /**
     * Monta pool, perfil y servidor.
     *
     * @param  array{interfaz:string, pool?:string, rango?:string, gateway?:string,
     *               perfil?:string, servicio?:string}  $datos
     * @return array{ok:bool, pasos:array<int,string>, error?:string}
     */
    public function montar(array $datos): array
    {
        $interfaz = trim((string) ($datos['interfaz'] ?? ''));

        if ($interfaz === '') {
            return ['ok' => false, 'pasos' => [], 'error' => 'Falta elegir la interfaz por donde llegan los clientes.'];
        }

        $pool     = trim((string) ($datos['pool'] ?? 'pool-pppoe'));
        $rango    = trim((string) ($datos['rango'] ?? '10.20.0.2-10.20.3.254'));
        $gateway  = trim((string) ($datos['gateway'] ?? '10.20.0.1'));
        $perfil   = trim((string) ($datos['perfil'] ?? 'perfil-pppoe'));
        $servicio = trim((string) ($datos['servicio'] ?? 'pppoe-netplay'));

        $choques = $this->choques($interfaz, $pool, $rango, $gateway, $perfil, $servicio);

        if ($choques) {
            return ['ok' => false, 'pasos' => [], 'error' => implode(' ', $choques)];
        }

        $pasos = [];

        try {
            $this->pool($pool, $rango);
            $pasos[] = "Rango de IP «{$pool}» listo ({$rango}).";

            // Su red va al túnel VPN en el mismo momento: si no, el TR-069 no
            // les llega de vuelta a los equipos de estos clientes.
            $router = \App\Models\ConectionRouter::where('token', $this->token)->first();
            $tunel = $router ? \App\Services\Vpn\RedesEnElTunel::asegurar($router, $rango) : null;
            if ($tunel && $tunel['detalle'] !== '') {
                $pasos[] = ($tunel['ok'] ? 'Túnel VPN: ' : 'Atención, túnel VPN: ') . $tunel['detalle'];
            }

            $this->perfil($perfil, $gateway, $pool);
            $pasos[] = "Perfil «{$perfil}» listo, entregando IP de «{$pool}».";

            $this->servidor($servicio, $interfaz, $perfil);
            $pasos[] = "Servidor PPPoE escuchando en «{$interfaz}».";

            // Los perfiles de plan sacan IP del rango de planes, no del de esta
            // VLAN: el mismo plan sirve a clientes de cualquier VLAN.
            $rangoPlanes = !empty($datos['perfiles'])
                ? PerfilesPorPlan::asegurarRango($this->api(), \App\Models\ConectionRouter::where('token', $this->token)->first())
                : null;

            foreach ($datos['perfiles'] ?? [] as $plan) {
                $nombre = trim((string) ($plan['perfil'] ?? ''));
                $rate   = trim((string) ($plan['velocidad'] ?? ''));

                if ($nombre === '') {
                    continue;
                }

                PerfilesPorPlan::asegurarPerfil($this->api(), $nombre, ServicioPppoe::velocidadParaElRouter($rate), $rangoPlanes);

                // Queda anotado en el plan: al dar de alta un cliente PPPoE se
                // elige solo el perfil que le corresponde.
                if (!empty($plan['plan_id'])) {
                    DB::table('internet_plans')
                        ->where('id', (int) $plan['plan_id'])
                        ->update(['pppoe_profile' => $nombre]);
                }

                $pasos[] = "Perfil «{$nombre}»" . ($rate ? " a {$rate}" : '') . ' listo.';
            }

            $salida = trim((string) ($datos['salida'] ?? ''));

            if ($salida !== '') {
                $this->nat($pool, $salida);
                $pasos[] = "Salida a internet por «{$salida}» lista.";
            }

            Log::info('[PPPoE] Servidor montado', [
                'interfaz' => $interfaz, 'pool' => $pool, 'perfil' => $perfil,
            ]);

            return ['ok' => true, 'pasos' => $pasos];
        } catch (\Throwable $e) {
            Log::error('[PPPoE] No se pudo montar el servidor', [
                'interfaz' => $interfaz, 'error' => $e->getMessage(),
            ]);

            return [
                'ok'    => false,
                'pasos' => $pasos,
                'error' => 'El router rechazó la configuración: ' . $e->getMessage(),
            ];
        }
    }

    /* ── Que lo nuevo no pise lo que ya hay ───────────────────────────────── */

    /**
     * Nombres y rango que no existen todavía en el router.
     *
     * @return array<string,string>
     */
    private function sugerenciaLibre($api): array
    {
        $pools      = $this->leer($api, '/ip/pool/print');
        $perfiles   = $this->leer($api, '/ppp/profile/print');
        $servidores = $this->leer($api, '/interface/pppoe-server/server/print');
        $octeto     = self::octetoLibre(self::octetosUsados($pools, $this->leer($api, '/ip/address/print')));

        $libre = function (string $base, array $lista, string $campo) {
            $usados = array_map(fn ($x) => strtolower((string) ($x[$campo] ?? '')), $lista);
            $nombre = $base;

            for ($i = 2; in_array(strtolower($nombre), $usados, true); $i++) {
                $nombre = "{$base}-{$i}";
            }

            return $nombre;
        };

        return [
            'pool'     => $libre('pool-pppoe', $pools, 'name'),
            'rango'    => "10.{$octeto}.0.2-10.{$octeto}.3.254",
            'gateway'  => "10.{$octeto}.0.1",
            'perfil'   => $libre('perfil-pppoe', $perfiles, 'name'),
            'servicio' => $libre('pppoe-netplay', $servidores, 'service-name'),
        ];
    }

    /**
     * Qué de lo pedido choca con lo que ya tiene el router.
     *
     * Las piezas se crean «o se actualizan si ya existen»: con un nombre
     * repetido se reescribía el rango o el perfil de otra VLAN y sus clientes
     * empezaban a tomar IP de otro lado.
     *
     * @return list<string>
     */
    public function choques(string $interfaz, string $pool, string $rango, string $gateway, string $perfil, string $servicio): array
    {
        $api = $this->api();
        $mal = [];

        $pools      = $this->leer($api, '/ip/pool/print');
        $perfiles   = $this->leer($api, '/ppp/profile/print');
        $servidores = $this->leer($api, '/interface/pppoe-server/server/print');
        $direcciones = $this->leer($api, '/ip/address/print');

        $igual = fn (array $lista, string $campo, string $valor) => collect($lista)->first(fn ($x) => strcasecmp((string) ($x[$campo] ?? ''), $valor) === 0);

        if ($igual($pools, 'name', $pool)) {
            $mal[] = "Ya existe un rango llamado «{$pool}».";
        }

        if ($igual($perfiles, 'name', $perfil)) {
            $mal[] = "Ya existe un perfil llamado «{$perfil}».";
        }

        if ($igual($servidores, 'service-name', $servicio)) {
            $mal[] = "Ya hay un servidor PPPoE llamado «{$servicio}».";
        }

        if ($srv = $igual($servidores, 'interface', $interfaz)) {
            $mal[] = "La interfaz «{$interfaz}» ya tiene el servidor «" . ($srv['service-name'] ?? '') . '»: quedaría reemplazado.';
        }

        $nuevo = self::tramos($rango);

        if (!$nuevo) {
            $mal[] = 'El rango tiene que ir como 10.25.0.2-10.25.3.254.';
        }

        $gw = ip2long($gateway);

        if ($gw === false) {
            $mal[] = 'La puerta de enlace no es una IP válida.';
        } elseif (self::dentro($gw, $nuevo)) {
            $mal[] = 'La puerta de enlace no puede estar dentro del rango.';
        }

        foreach ($pools as $p) {
            $suyo = self::tramos((string) ($p['ranges'] ?? ''));

            if (self::cruzan($nuevo, $suyo) || ($gw !== false && self::dentro($gw, $suyo))) {
                $mal[] = "Choca con el rango «{$p['name']}» ({$p['ranges']}).";
            }
        }

        foreach ($direcciones as $a) {
            $red = (string) ($a['address'] ?? '');

            // Las /32 son las puntas de sesiones PPP ya conectadas: salen del
            // rango de algún pool, que ya se revisó arriba.
            if ($red === '' || str_ends_with($red, '/32')) {
                continue;
            }

            $suya = self::tramos($red);

            if (self::cruzan($nuevo, $suya) || ($gw !== false && self::dentro($gw, $suya))) {
                $mal[] = "Choca con la red {$red} de «{$a['interface']}».";
            }
        }

        return $mal;
    }

    /**
     * «10.20.0.2-10.20.3.254», «10.20.0.0/22» o una IP suelta, separados por
     * comas, como tramos [desde, hasta] en enteros.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function tramos(string $texto): array
    {
        $tramos = [];

        foreach (array_filter(array_map('trim', explode(',', $texto))) as $parte) {
            if (str_contains($parte, '/')) {
                [$ip, $bits] = explode('/', $parte, 2);
                $n = ip2long($ip);
                $bits = (int) $bits;

                if ($n === false || $bits < 0 || $bits > 32) {
                    return [];
                }

                $mascara = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
                $tramos[] = [$n & $mascara, ($n & $mascara) | (~$mascara & 0xFFFFFFFF)];
            } elseif (str_contains($parte, '-')) {
                [$a, $b] = array_map('trim', explode('-', $parte, 2));
                $a = ip2long($a);
                $b = ip2long($b);

                if ($a === false || $b === false || $a > $b) {
                    return [];
                }

                $tramos[] = [$a, $b];
            } else {
                $n = ip2long($parte);

                if ($n === false) {
                    return [];
                }

                $tramos[] = [$n, $n];
            }
        }

        return $tramos;
    }

    private static function cruzan(array $a, array $b): bool
    {
        foreach ($a as [$x1, $x2]) {
            foreach ($b as [$y1, $y2]) {
                if ($x1 <= $y2 && $y1 <= $x2) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function dentro(int $ip, array $tramos): bool
    {
        return self::cruzan([[$ip, $ip]], $tramos);
    }

    /* ── Automático: un servidor por VLAN ─────────────────────────────────── */

    /**
     * Lo que haría falta para que cada VLAN de clientes atienda PPPoE.
     *
     * Es el mismo armado que ya tienen las VLAN montadas a mano: un rango
     * 10.X.0.0/22 propio, un perfil que reparte de ahí y un servidor sobre la
     * VLAN. Se proponen segundos octetos que no usa nadie en el router, así
     * dos VLAN nunca reparten las mismas IP.
     *
     * @return array<string,mixed>
     */
    public function propuestaPorVlan(): array
    {
        $api = $this->api();

        $vlans      = $this->leer($api, '/interface/vlan/print');
        $direcciones = $this->leer($api, '/ip/address/print');
        $pools      = $this->leer($api, '/ip/pool/print');
        $perfiles   = $this->leer($api, '/ppp/profile/print');
        $servidores = $this->leer($api, '/interface/pppoe-server/server/print');
        $secrets    = $this->leer($api, '/ppp/secret/print');

        $gestion = (int) \App\Models\GestionRemota::where('company_id', getSessionCompanyId())->value('vlan');
        $ocupados = self::octetosUsados($pools, $direcciones);

        $porNombre = fn (array $lista, string $nombre) => collect($lista)->first(fn ($x) => ($x['name'] ?? '') === $nombre);
        $usan = fn (string $perfil) => count(array_filter($secrets, fn ($x) => ($x['profile'] ?? '') === $perfil));

        $filas = [];

        foreach ($vlans as $v) {
            $nombre = $v['name'] ?? '';
            $id     = (int) ($v['vlan-id'] ?? 0);

            // La de gestión es sólo para hablar con las ONT: ahí no va PPPoE.
            if ($nombre === '' || ($v['disabled'] ?? 'false') === 'true' || ($gestion && $id === $gestion)) {
                continue;
            }

            $red = collect($direcciones)->first(fn ($a) => ($a['interface'] ?? '') === $nombre)['address'] ?? null;
            $srv = collect($servidores)->first(fn ($x) => ($x['interface'] ?? '') === $nombre);

            $fila = [
                'interfaz' => $nombre,
                'vlan'     => $id,
                'sobre'    => $v['interface'] ?? '',
                'red'      => $red,
            ];

            if ($srv) {
                $perfil = $srv['default-profile'] ?? '';
                $pool   = $porNombre($perfiles, $perfil)['remote-address'] ?? '';

                $filas[] = $fila + [
                    'tiene' => true,
                    'servicio' => $srv['service-name'] ?? '',
                    'perfil'   => $perfil,
                    'rango'    => $porNombre($pools, $pool)['ranges'] ?? $pool,
                    'activo'   => ($srv['disabled'] ?? 'false') !== 'true',
                ];
                continue;
            }

            // Si ya quedó armado su perfil de otra vez (sin el servidor), se
            // reusa con su rango: otro rango dejaría dos para la misma VLAN.
            $previo = $porNombre($perfiles, "perfil-pppoe-{$id}");
            $poolPrevio = $previo ? $porNombre($pools, (string) ($previo['remote-address'] ?? '')) : null;

            if ($previo && $poolPrevio && !empty($previo['local-address'])) {
                $filas[] = $fila + [
                    'tiene'    => false,
                    'reusa'    => true,
                    'servicio' => "pppoe-netplay-{$id}",
                    'perfil'   => $previo['name'],
                    'pool'     => $poolPrevio['name'],
                    'rango'    => $poolPrevio['ranges'] ?? '',
                    'gateway'  => $previo['local-address'],
                ];
                continue;
            }

            $octeto = self::octetoLibre($ocupados);
            $ocupados[] = $octeto;

            $filas[] = $fila + [
                'reusa'    => false,
                'tiene'    => false,
                'servicio' => "pppoe-netplay-{$id}",
                'perfil'   => "perfil-pppoe-{$id}",
                'pool'     => "pool-pppoe-vlan-{$id}",
                'rango'    => "10.{$octeto}.0.2-10.{$octeto}.3.254",
                'gateway'  => "10.{$octeto}.0.1",
            ];
        }

        usort($filas, fn ($a, $b) => $a['vlan'] <=> $b['vlan']);

        // Una velocidad sin unidad el MikroTik la toma en bits por segundo:
        // «200/200» deja al cliente conectado pero sin poder navegar.
        $sinUnidad = [];

        foreach ($perfiles as $p) {
            $rate = trim((string) ($p['rate-limit'] ?? ''));

            if ($rate !== '' && preg_match('#^\d+(/\d+)?$#', $rate)) {
                $sinUnidad[] = [
                    'perfil'    => $p['name'] ?? '',
                    'actual'    => $rate,
                    'corregida' => ServicioPppoe::velocidadParaElRouter($rate),
                    'usan'      => $usan($p['name'] ?? ''),
                ];
            }
        }

        $wan = $this->interfacesDeInternet($api);

        return [
            'vlans'      => $filas,
            'sin_unidad' => $sinUnidad,
            'nat'        => [
                'general' => $this->hayNatGeneral($api, $wan),
                'wan'     => $wan,
            ],
        ];
    }

    /**
     * Crea el PPPoE de las VLAN elegidas con lo que propone
     * {@see propuestaPorVlan()}. Lo recalcula acá: los rangos no se toman de
     * lo que manda el navegador.
     *
     * @param  list<string>  $interfaces
     * @param  list<string>  $corregir  perfiles con la velocidad sin unidad
     * @return array{ok:bool, pasos:list<array{paso:string, ok:bool, detalle:string}>}
     */
    public function montarPorVlan(array $interfaces, array $corregir = []): array
    {
        $propuesta = $this->propuestaPorVlan();
        $router    = \App\Models\ConectionRouter::where('token', $this->token)->first();
        $pasos     = [];

        foreach ($propuesta['vlans'] as $v) {
            if ($v['tiene'] || !in_array($v['interfaz'], $interfaces, true)) {
                continue;
            }

            try {
                // Lo que ya estaba se deja como está: sólo falta el servidor.
                if (!$v['reusa']) {
                    $this->pool($v['pool'], $v['rango']);
                    $this->perfil($v['perfil'], $v['gateway'], $v['pool']);
                }
                $this->servidor($v['servicio'], $v['interfaz'], $v['perfil']);

                $detalle = "Reparte {$v['rango']} con el perfil {$v['perfil']}.";

                // Su red va al túnel: si no, el TR-069 no les llega a esas ONT.
                $tunel = $router ? \App\Services\Vpn\RedesEnElTunel::asegurar($router, $v['rango']) : null;

                if ($tunel && !$tunel['ok'] && $tunel['detalle'] !== '') {
                    $detalle .= ' Atención, túnel VPN: ' . $tunel['detalle'];
                }

                // Sin una salida general a internet, cada rango necesita la suya.
                if (!$propuesta['nat']['general'] && $propuesta['nat']['wan']) {
                    $this->nat($v['pool'], $propuesta['nat']['wan'][0]);
                    $detalle .= " Sale a internet por {$propuesta['nat']['wan'][0]}.";
                }

                $pasos[] = ['paso' => "PPPoE en la VLAN {$v['vlan']}", 'ok' => true, 'detalle' => $detalle];
            } catch (\Throwable $e) {
                $pasos[] = ['paso' => "PPPoE en la VLAN {$v['vlan']}", 'ok' => false, 'detalle' => 'El router lo rechazó: ' . $e->getMessage()];
            }
        }

        foreach ($propuesta['sin_unidad'] as $p) {
            if (!in_array($p['perfil'], $corregir, true)) {
                continue;
            }

            try {
                $api = $this->api();
                $q = new Query('/ppp/profile/set');
                $q->equal('.id', $this->buscar($api, '/ppp/profile/print', 'name', $p['perfil']) ?? '');
                $q->equal('rate-limit', $p['corregida']);
                $api->query($q)->read();

                $pasos[] = ['paso' => "Velocidad de {$p['perfil']}", 'ok' => true, 'detalle' => "De {$p['actual']} a {$p['corregida']}. Los conectados la toman al reconectarse."];
            } catch (\Throwable $e) {
                $pasos[] = ['paso' => "Velocidad de {$p['perfil']}", 'ok' => false, 'detalle' => 'El router lo rechazó: ' . $e->getMessage()];
            }
        }

        Log::info('[PPPoE] Automático por VLAN', ['interfaces' => $interfaces, 'corregir' => $corregir]);

        return ['ok' => !collect($pasos)->contains('ok', false), 'pasos' => $pasos];
    }

    /** Segundos octetos de 10.X que ya usa algún rango o dirección. */
    public static function octetosUsados(array $pools, array $direcciones): array
    {
        $texto = implode(' ', array_merge(
            array_map(fn ($p) => $p['ranges'] ?? '', $pools),
            array_map(fn ($a) => $a['address'] ?? '', $direcciones),
        ));

        preg_match_all('#\b10\.(\d{1,3})\.#', $texto, $m);

        return array_map('intval', $m[1]);
    }

    public static function octetoLibre(array $ocupados): int
    {
        for ($x = 20; $x < 250; $x++) {
            if (!in_array($x, $ocupados, true)) {
                return $x;
            }
        }

        throw new \RuntimeException('No quedan redes 10.X libres en el router.');
    }

    /** Las interfaces por donde salen las rutas por defecto. */
    private function interfacesDeInternet($api): array
    {
        $wan = [];

        foreach ($this->leer($api, '/ip/route/print') as $r) {
            if (($r['dst-address'] ?? '') !== '0.0.0.0/0') {
                continue;
            }

            // «181.48.150.41%ether1»: la interfaz va después del %.
            $gw = (string) ($r['immediate-gw'] ?? $r['gateway'] ?? '');

            if (str_contains($gw, '%')) {
                $wan[] = substr($gw, strrpos($gw, '%') + 1);
            }
        }

        return array_values(array_unique($wan));
    }

    /** Si ya hay un NAT que saca a internet a cualquier red, sin mirar el origen. */
    private function hayNatGeneral($api, array $wan): bool
    {
        foreach ($this->leer($api, '/ip/firewall/nat/print') as $n) {
            if (($n['chain'] ?? '') !== 'srcnat' || ($n['disabled'] ?? 'false') === 'true') {
                continue;
            }

            if (!in_array($n['action'] ?? '', ['masquerade', 'src-nat'], true) || !empty($n['src-address'])) {
                continue;
            }

            if (!empty($n['out-interface-list']) || in_array($n['out-interface'] ?? '', $wan, true)) {
                return true;
            }
        }

        return false;
    }

    /* ── Piezas ───────────────────────────────────────────────────────────── */

    /** El rango de direcciones que se les reparte a los clientes. */
    private function pool(string $nombre, string $rango): void
    {
        $api = $this->api();
        $id  = $this->buscar($api, '/ip/pool/print', 'name', $nombre);

        $q = new Query($id ? '/ip/pool/set' : '/ip/pool/add');
        if ($id) $q->equal('.id', $id);
        $q->equal('name', $nombre);
        $q->equal('ranges', $rango);
        $api->query($q)->read();
    }

    /**
     * El perfil: de dónde salen las IP y cuál es la puerta de enlace.
     *
     * La velocidad no se fija acá sino en el perfil de cada plan; este es el
     * perfil base con el que arranca todo el mundo.
     */
    private function perfil(string $nombre, string $gateway, string $pool): void
    {
        $api = $this->api();
        $id  = $this->buscar($api, '/ppp/profile/print', 'name', $nombre);

        $q = new Query($id ? '/ppp/profile/set' : '/ppp/profile/add');
        if ($id) $q->equal('.id', $id);
        $q->equal('name', $nombre);
        $q->equal('local-address', $gateway);
        $q->equal('remote-address', $pool);
        // Sin esto dos clientes con el mismo usuario pueden conectarse a la vez.
        $q->equal('only-one', 'yes');
        $api->query($q)->read();
    }

    private function servidor(string $servicio, string $interfaz, string $perfil): void
    {
        $api = $this->api();
        $id  = $this->buscar($api, '/interface/pppoe-server/server/print', 'interface', $interfaz);

        $q = new Query($id ? '/interface/pppoe-server/server/set' : '/interface/pppoe-server/server/add');
        if ($id) $q->equal('.id', $id);
        $q->equal('service-name', $servicio);
        $q->equal('interface', $interfaz);
        $q->equal('default-profile', $perfil);
        $q->equal('disabled', 'no');
        // Con uno solo alcanza y es lo que entienden todos los equipos.
        $q->equal('authentication', 'pap,chap');
        $q->equal('one-session-per-host', 'yes');
        $api->query($q)->read();
    }

    /**
     * Qué se llevaría por delante desmontar PPPoE.
     *
     * Se mira antes de tocar nada porque borrar el servidor deja sin internet
     * a todo el que esté conectado, y borrar las credenciales es irreversible.
     *
     * @return array<string,mixed>
     */
    public function queSeBorra(string $pool = 'pool-pppoe'): array
    {
        $api = $this->api();

        $servidores = $this->leer($api, '/interface/pppoe-server/server/print');
        $secrets    = array_filter(
            $this->leer($api, '/ppp/secret/print'),
            fn ($s) => ($s['service'] ?? '') === 'pppoe'
        );
        $activas = array_filter(
            $this->leer($api, '/ppp/active/print'),
            fn ($a) => ($a['service'] ?? '') === 'pppoe'
        );

        // Sólo los perfiles que quedaron apuntando a este pool: los demás son
        // de otra cosa —una VPN, por ejemplo— y no hay que tocarlos.
        $perfiles = array_values(array_filter(
            $this->leer($api, '/ppp/profile/print'),
            fn ($p) => ($p['remote-address'] ?? '') === $pool && ($p['default'] ?? 'false') !== 'true'
        ));

        return [
            'servidores' => array_map(fn ($s) => [
                'nombre'   => $s['service-name'] ?? '',
                'interfaz' => $s['interface'] ?? '',
            ], $servidores),
            'perfiles'  => array_map(fn ($p) => [
                'nombre'    => $p['name'] ?? '',
                'velocidad' => $p['rate-limit'] ?? null,
            ], $perfiles),
            'usuarios'  => array_values(array_map(fn ($s) => [
                'usuario'    => $s['name'] ?? '',
                'documento'  => $s['comment'] ?? null,
                'perfil'     => $s['profile'] ?? null,
                'conectado'  => false,
            ], $secrets)),
            'conectados' => count($activas),
            'pool'       => $pool,
        ];
    }

    /**
     * Desmonta PPPoE del router.
     *
     * Va de lo más externo a lo más interno para no dejar referencias rotas:
     * primero el servidor —que es lo que deja de aceptar conexiones—, después
     * las credenciales, después los perfiles y por último el rango.
     *
     * @param  array{usuarios?:bool, perfiles?:bool, pool?:bool, nombre_pool?:string}  $opciones
     * @return array{ok:bool, pasos:array<int,string>, error?:string}
     */
    public function desmontar(array $opciones): array
    {
        $pool  = trim((string) ($opciones['nombre_pool'] ?? 'pool-pppoe'));
        $pasos = [];

        try {
            $api = $this->api();

            foreach ($this->leer($api, '/interface/pppoe-server/server/print') as $srv) {
                $this->borrar($api, '/interface/pppoe-server/server/remove', $srv['.id'] ?? '');
                $pasos[] = 'Servidor «' . ($srv['service-name'] ?? '') . '» eliminado.';
            }

            if (!empty($opciones['usuarios'])) {
                $n = 0;

                foreach ($this->leer($api, '/ppp/secret/print') as $sec) {
                    if (($sec['service'] ?? '') !== 'pppoe') {
                        continue;
                    }

                    // Primero se corta la sesión: si no, sigue navegando con
                    // la credencial ya borrada hasta que se desconecte.
                    $this->cortar($api, $sec['name'] ?? '');
                    $this->borrar($api, '/ppp/secret/remove', $sec['.id'] ?? '');
                    $n++;
                }

                $pasos[] = $n ? "{$n} credencial(es) de cliente eliminadas." : 'No había credenciales PPPoE.';
            }

            if (!empty($opciones['perfiles'])) {
                $n = 0;

                foreach ($this->leer($api, '/ppp/profile/print') as $perf) {
                    // Los que no reparten de este pool son de otra cosa.
                    if (($perf['remote-address'] ?? '') !== $pool || ($perf['default'] ?? 'false') === 'true') {
                        continue;
                    }

                    $this->borrar($api, '/ppp/profile/remove', $perf['.id'] ?? '');
                    $n++;
                }

                $pasos[] = $n ? "{$n} perfil(es) eliminados." : 'No había perfiles de este rango.';

                DB::table('internet_plans')
                    ->where('company_id', getSessionCompanyId())
                    ->update(['pppoe_profile' => null]);
            }

            if (!empty($opciones['pool'])) {
                $id = $this->buscar($api, '/ip/pool/print', 'name', $pool);

                if ($id) {
                    $this->borrar($api, '/ip/pool/remove', $id);
                    $pasos[] = "Rango «{$pool}» eliminado.";
                }

                $natId = $this->buscar($api, '/ip/firewall/nat/print', 'comment', "netplay-pppoe-{$pool}");

                if ($natId) {
                    $this->borrar($api, '/ip/firewall/nat/remove', $natId);
                    $pasos[] = 'Regla de salida a internet eliminada.';
                }
            }

            Log::info('[PPPoE] Servidor desmontado', ['opciones' => $opciones]);

            return ['ok' => true, 'pasos' => $pasos];
        } catch (\Throwable $e) {
            Log::error('[PPPoE] No se pudo desmontar', ['error' => $e->getMessage()]);

            return ['ok' => false, 'pasos' => $pasos, 'error' => 'El router rechazó el cambio: ' . $e->getMessage()];
        }
    }

    private function borrar($api, string $comando, string $id): void
    {
        if ($id === '') {
            return;
        }

        $q = new Query($comando);
        $q->equal('.id', $id);
        $api->query($q)->read();
    }

    private function cortar($api, string $usuario): void
    {
        if ($usuario === '') {
            return;
        }

        try {
            $q = new Query('/ppp/active/print');
            $q->where('name', $usuario);
            $q->add('=.proplist=.id');

            foreach ($api->query($q)->read() as $sesion) {
                $this->borrar($api, '/ppp/active/remove', $sesion['.id'] ?? '');
            }
        } catch (\Throwable $e) {
            // Que no se pueda cortar la sesión no impide borrar la credencial.
        }
    }

    /**
     * Deja salir a internet a los clientes PPPoE.
     *
     * Sin esto se conectan y toman IP, pero no navegan. Se apunta al pool
     * completo y no a cada cliente, y se marca con un comentario para poder
     * reconocerla y no duplicarla.
     */
    private function nat(string $pool, string $salida): void
    {
        $api = $this->api();
        $comentario = "netplay-pppoe-{$pool}";

        $id = $this->buscar($api, '/ip/firewall/nat/print', 'comment', $comentario);

        $q = new Query($id ? '/ip/firewall/nat/set' : '/ip/firewall/nat/add');
        if ($id) $q->equal('.id', $id);
        $q->equal('chain', 'srcnat');
        $q->equal('action', 'masquerade');
        $q->equal('src-address', $this->redDelPool($api, $pool));
        $q->equal('comment', $comentario);

        // La salida puede ser una lista de interfaces o una sola.
        $esLista = collect($this->leer($api, '/interface/list/print'))
            ->contains(fn ($l) => ($l['name'] ?? '') === $salida);

        $esLista
            ? $q->equal('out-interface-list', $salida)
            : $q->equal('out-interface', $salida);

        $api->query($q)->read();
    }

    /** La red que abarca el pool, para el NAT. */
    private function redDelPool($api, string $pool): string
    {
        $rangos = null;

        foreach ($this->leer($api, '/ip/pool/print') as $p) {
            if (($p['name'] ?? '') === $pool) {
                $rangos = $p['ranges'] ?? null;
                break;
            }
        }

        if (!$rangos) {
            return '0.0.0.0/0';
        }

        // "10.20.0.2-10.20.3.254" → se toma el primero y se arma su /24, que
        // es lo que alcanza para la regla; si ya viene en CIDR se usa tal cual.
        if (str_contains($rangos, '/')) {
            return trim(explode(',', $rangos)[0]);
        }

        $primera = trim(explode('-', explode(',', $rangos)[0])[0]);
        $partes  = explode('.', $primera);

        if (count($partes) !== 4) {
            return '0.0.0.0/0';
        }

        return "{$partes[0]}.{$partes[1]}.0.0/16";
    }

    /* ── Interno ──────────────────────────────────────────────────────────── */

    private function api()
    {
        return $this->conexion->conection($this->token);
    }

    /** @return array<int,array<string,mixed>> */
    private function leer($api, string $comando): array
    {
        try {
            return $api->query(new Query($comando))->read();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function buscar($api, string $comando, string $campo, string $valor): ?string
    {
        try {
            $q = new Query($comando);
            $q->where($campo, $valor);
            $q->add('=.proplist=.id');

            return $api->query($q)->read()[0]['.id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
