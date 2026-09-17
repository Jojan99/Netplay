<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\ConectionRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Un perfil PPP por plan, todos repartiendo IP de un mismo rango.
 *
 * El perfil existe para administrar el plan: fija la velocidad. La VLAN por
 * donde entra el cliente la decide el servidor PPPoE de esa interfaz, no el
 * perfil, así que no hace falta un perfil por plan y por VLAN. Pero un perfil
 * sin rango deja al cliente sin IP ("port-error"): por eso los de plan sacan
 * IP de un rango propio, aparte de los de cada VLAN.
 *
 * Nunca se corta una sesión: al cliente conectado el perfil nuevo le toma
 * efecto la próxima vez que se conecta.
 */
class PerfilesPorPlan
{
    public const POOL = 'pool-pppoe-planes';
    public const MARCA = 'Netvula · perfil del plan'; // sólo se escribe; la búsqueda es por nombre

    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    /**
     * El rango de los planes: el que ya hay o uno 10.X.0.0/22 libre.
     *
     * @return array{pool:string, rango:string, gateway:string, existe:bool}
     */
    public static function rangoDePlanes($api): array
    {
        $pools = $api->query(new Query('/ip/pool/print'))->read();

        foreach ($pools as $p) {
            if (($p['name'] ?? '') === self::POOL) {
                $rango = (string) ($p['ranges'] ?? '');
                $desde = ip2long(trim(explode('-', explode(',', $rango)[0])[0]));

                return ['pool' => self::POOL, 'rango' => $rango, 'gateway' => long2ip($desde - 1), 'existe' => true];
            }
        }

        $octeto = ConfigurarServidorPppoe::octetoLibre(
            ConfigurarServidorPppoe::octetosUsados($pools, $api->query(new Query('/ip/address/print'))->read())
        );

        return ['pool' => self::POOL, 'rango' => "10.{$octeto}.0.2-10.{$octeto}.3.254", 'gateway' => "10.{$octeto}.0.1", 'existe' => false];
    }

    /** Crea el rango si falta y lo suma al túnel VPN. */
    public static function asegurarRango($api, ?ConectionRouter $router): array
    {
        $r = self::rangoDePlanes($api);

        if (!$r['existe']) {
            $api->query((new Query('/ip/pool/add'))->equal('name', $r['pool'])->equal('ranges', $r['rango']))->read();
        }

        if ($router) {
            // Sin la ruta por el túnel, el TR-069 no les llega a esas ONT.
            \App\Services\Vpn\RedesEnElTunel::asegurar($router, $r['rango']);
        }

        return $r;
    }

    /** Crea o ajusta el perfil de un plan sobre el rango de los planes. */
    public static function asegurarPerfil($api, string $nombre, string $velocidad, array $rango): void
    {
        $existe = $api->query((new Query('/ppp/profile/print'))->where('name', $nombre)->add('=.proplist=.id'))->read();
        $q = new Query($existe ? '/ppp/profile/set' : '/ppp/profile/add');

        if ($existe) {
            $q->equal('.id', $existe[0]['.id']);
        }

        $q->equal('name', $nombre)
            ->equal('local-address', $rango['gateway'])
            ->equal('remote-address', $rango['pool'])
            ->equal('only-one', 'yes')
            ->equal('comment', self::MARCA);

        if ($velocidad !== '') {
            $q->equal('rate-limit', $velocidad);
        }

        $api->query($q)->read();
    }

    /** "INTERNET 200MG PLUS" → "plan-internet-200mg-plus" */
    public static function nombreDelPerfil(string $plan): string
    {
        return 'plan-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($plan)), '-');
    }

    /**
     * Deja un perfil por plan y a cada cliente PPPoE en el de su plan.
     *
     * Con $aplicar=false sólo cuenta lo que haría.
     *
     * @return array<string,mixed>
     */
    public function ordenar(bool $aplicar, ?int $routerId = null): array
    {
        $router = ConectionRouter::where('company_id', $this->companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->orderBy('id')->first();

        if (!$router) {
            throw new \InvalidArgumentException('La empresa no tiene un MikroTik configurado.');
        }

        $api = $this->conexion->conection($router->token);
        $rango = $aplicar ? self::asegurarRango($api, $router) : self::rangoDePlanes($api);

        $perfilesRouter = collect($api->query(new Query('/ppp/profile/print'))->read())->keyBy('name');
        $secrets = collect($api->query(new Query('/ppp/secret/print'))->read())->keyBy('name');
        $activas = collect($api->query(new Query('/ppp/active/print'))->read())->keyBy('name');

        $planes = DB::table('internet_plans')->where('company_id', $this->companyId)->where('active', 1)
            ->orderBy('plan_name')->get(['id', 'plan_name', 'download_speed', 'upload_speed', 'pppoe_profile']);

        $perfiles = [];
        $porPlan = [];

        foreach ($planes as $plan) {
            $nombre = $plan->pppoe_profile ?: self::nombreDelPerfil($plan->plan_name);
            $bajada = (int) $plan->download_speed;
            $subida = (int) $plan->upload_speed ?: $bajada;
            $velocidad = $bajada ? ServicioPppoe::velocidadParaElRouter("{$subida}/{$bajada}") : '';
            $antes = $perfilesRouter[$nombre] ?? null;

            $perfiles[] = [
                'plan'      => $plan->plan_name,
                'perfil'    => $nombre,
                'velocidad' => $velocidad ?: 'sin límite',
                'antes'     => $antes ? ($antes['remote-address'] ?? '—') . ' · ' . ($antes['rate-limit'] ?? 'sin límite') : 'no existía',
            ];

            if ($aplicar) {
                self::asegurarPerfil($api, $nombre, $velocidad, $rango);
                DB::table('internet_plans')->where('id', $plan->id)->update(['pppoe_profile' => $nombre]);
            }

            $porPlan[$plan->id] = $nombre;
        }

        $clientes = [];
        $clientesDb = DB::table('user_data')->where('company_id', $this->companyId)->where('active', 1)
            ->where('connection_type', 'pppoe')->whereNotNull('pppoe_user')
            ->get(['user_id', 'names', 'lastname', 'pppoe_user', 'internet_plans_id']);

        foreach ($clientesDb as $c) {
            $secret = $secrets[$c->pppoe_user] ?? null;
            $destino = $porPlan[$c->internet_plans_id] ?? null;

            if (!$secret || !$destino) {
                $clientes[] = ['usuario' => $c->pppoe_user, 'cliente' => trim("{$c->names} {$c->lastname}"),
                    'de' => $secret['profile'] ?? '—', 'a' => null,
                    'nota' => !$secret ? 'no tiene credencial en el router' : 'no tiene plan activo'];
                continue;
            }

            $de = $secret['profile'] ?? 'default';

            if ($de !== $destino && $aplicar) {
                // Sólo la credencial: la sesión sigue como está.
                $api->query((new Query('/ppp/secret/set'))->equal('.id', $secret['.id'])->equal('profile', $destino))->read();
            }

            if ($aplicar) {
                DB::table('user_data')->where('user_id', $c->user_id)->update(['pppoe_profile' => $destino]);
            }

            $clientes[] = [
                'usuario'   => $c->pppoe_user,
                'cliente'   => trim("{$c->names} {$c->lastname}"),
                'de'        => $de,
                'a'         => $destino,
                'conectado' => isset($activas[$c->pppoe_user]),
                'cambia'    => $de !== $destino,
            ];
        }

        if ($aplicar) {
            Log::info('[PPPoE] Un perfil por plan', ['rango' => $rango['rango'], 'clientes' => count(array_filter($clientes, fn ($x) => $x['cambia'] ?? false))]);
        }

        return ['rango' => $rango, 'perfiles' => $perfiles, 'clientes' => $clientes];
    }
}
