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
            // Un rango que no suele chocar con lo que ya haya armado.
            'sugerencia' => [
                'pool'           => 'pool-pppoe',
                'rango'          => '10.20.0.2-10.20.3.254',
                'gateway'        => '10.20.0.1',
                'perfil'         => 'perfil-pppoe',
                'servicio'       => 'pppoe-netplay',
            ],
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

        $pasos = [];

        try {
            $this->pool($pool, $rango);
            $pasos[] = "Rango de IP «{$pool}» listo ({$rango}).";

            $this->perfil($perfil, $gateway, $pool);
            $pasos[] = "Perfil «{$perfil}» listo, entregando IP de «{$pool}».";

            $this->servidor($servicio, $interfaz, $perfil);
            $pasos[] = "Servidor PPPoE escuchando en «{$interfaz}».";

            foreach ($datos['perfiles'] ?? [] as $plan) {
                $nombre = trim((string) ($plan['perfil'] ?? ''));
                $rate   = trim((string) ($plan['velocidad'] ?? ''));

                if ($nombre === '') {
                    continue;
                }

                $this->perfilDePlan($nombre, $gateway, $pool, $rate);

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
     * El perfil de un plan: mismo pool y puerta de enlace que el base, pero
     * con la velocidad del plan.
     */
    private function perfilDePlan(string $nombre, string $gateway, string $pool, string $rate): void
    {
        $api = $this->api();
        $id  = $this->buscar($api, '/ppp/profile/print', 'name', $nombre);

        $q = new Query($id ? '/ppp/profile/set' : '/ppp/profile/add');
        if ($id) $q->equal('.id', $id);
        $q->equal('name', $nombre);
        $q->equal('local-address', $gateway);
        $q->equal('remote-address', $pool);
        $q->equal('only-one', 'yes');

        if ($rate !== '') {
            $q->equal('rate-limit', $rate);
        }

        $api->query($q)->read();
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
