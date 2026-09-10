<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
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
