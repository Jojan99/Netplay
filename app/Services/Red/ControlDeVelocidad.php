<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\ConectionRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * La velocidad de los planes, llevada al MikroTik.
 *
 * El operador contesta tres cosas —cuánto baja, cuánto sube y si quiere
 * ráfaga— y acá se traduce a lo que el router entiende, que es bastante menos
 * amable: un renglón con seis pares de números en un orden que además está al
 * revés de como uno lo piensa (primero la subida del cliente, después la
 * bajada).
 *
 * Cada plan se atiende distinto según cómo se conecte el cliente:
 *   · PPPoE   → un perfil PPP por plan; la velocidad va en el perfil.
 *   · IP fija → una cola simple por cliente, apuntando a su IP.
 */
class ControlDeVelocidad
{
    /** A partir de qué porcentaje de la velocidad normal arranca la ráfaga. */
    private const UMBRAL = 0.8;

    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    // ── Lectura ───────────────────────────────────────────────────────────

    /**
     * Los planes con su velocidad y a cuántos clientes alcanza cada uno.
     *
     * @return list<array<string,mixed>>
     */
    public function planes(): array
    {
        $clientes = DB::table('user_data')
            ->where('company_id', $this->companyId)
            ->where('active', 1)
            ->select('internet_plans_id', 'connection_type', 'control_velocidad', DB::raw('COUNT(*) as n'))
            ->groupBy('internet_plans_id', 'connection_type', 'control_velocidad')
            ->get();

        return DB::table('internet_plans')
            ->where('company_id', $this->companyId)
            ->where('active', 1)
            ->orderBy('plan_name')
            ->get()
            ->map(function ($plan) use ($clientes) {
                $suyos = $clientes->where('internet_plans_id', $plan->id);

                return [
                    'id'        => (int) $plan->id,
                    'nombre'    => $plan->plan_name,
                    'precio'    => $plan->monthly_price,
                    'bajada'    => $plan->bajada_mbps,
                    'subida'    => $plan->subida_mbps,
                    'rafaga'    => (bool) $plan->rafaga,
                    'rafaga_bajada'   => $plan->rafaga_bajada_mbps,
                    'rafaga_subida'   => $plan->rafaga_subida_mbps,
                    'rafaga_segundos' => (int) $plan->rafaga_segundos,
                    'prioridad'       => (int) $plan->prioridad,
                    'perfil_ppp'      => $plan->pppoe_profile,
                    'aplicado_en'     => $plan->control_aplicado_en,
                    'clientes'        => [
                        'pppoe'      => (int) $suyos->where('connection_type', 'pppoe')->where('control_velocidad', 'plan')->sum('n'),
                        'ip_fija'    => (int) $suyos->where('connection_type', '!=', 'pppoe')->where('control_velocidad', 'plan')->sum('n'),
                        'sin_limite' => (int) $suyos->where('control_velocidad', 'sin_limite')->sum('n'),
                    ],
                    'resumen'   => $this->enPalabras($plan),
                ];
            })
            ->all();
    }

    /** Lo que va a sentir el cliente, dicho en una frase. */
    public function enPalabras(object $plan): ?string
    {
        if (!$plan->bajada_mbps || !$plan->subida_mbps) {
            return null;
        }

        $frase = "Baja a {$plan->bajada_mbps} Mb y sube a {$plan->subida_mbps} Mb.";

        if ($plan->rafaga && $plan->rafaga_bajada_mbps) {
            $frase .= " Los primeros {$plan->rafaga_segundos} segundos puede llegar a {$plan->rafaga_bajada_mbps} Mb de bajada"
                . ($plan->rafaga_subida_mbps ? " y {$plan->rafaga_subida_mbps} Mb de subida" : '')
                . ', así las páginas abren de golpe.';
        }

        return $frase;
    }

    // ── Escritura ─────────────────────────────────────────────────────────

    /**
     * Guarda la velocidad de un plan. No toca el router: eso se pide aparte,
     * para que nadie cambie la velocidad de cien clientes sin querer.
     */
    public function guardar(int $planId, array $datos): array
    {
        $plan = $this->plan($planId);

        $bajada = max(1, (int) ($datos['bajada'] ?? 0));
        $subida = max(1, (int) ($datos['subida'] ?? 0));
        $rafaga = (bool) ($datos['rafaga'] ?? false);

        $rafagaBajada = $rafaga ? max($bajada, (int) ($datos['rafaga_bajada'] ?? 0)) : null;
        $rafagaSubida = $rafaga ? max($subida, (int) ($datos['rafaga_subida'] ?? 0)) : null;

        DB::table('internet_plans')->where('id', $plan->id)->update([
            'bajada_mbps'        => $bajada,
            'subida_mbps'        => $subida,
            'rafaga'             => $rafaga,
            'rafaga_bajada_mbps' => $rafagaBajada,
            'rafaga_subida_mbps' => $rafagaSubida,
            'rafaga_segundos'    => max(1, min(60, (int) ($datos['rafaga_segundos'] ?? 8))),
            'prioridad'          => max(1, min(8, (int) ($datos['prioridad'] ?? 8))),
            'updated_at'         => now(),
        ]);

        return ['ok' => true, 'resumen' => $this->enPalabras($this->plan($planId))];
    }

    /**
     * Lleva la velocidad del plan al router.
     *
     * @return array{perfil:?string, pppoe:int, colas:int, saltados:int, avisos:list<string>}
     */
    public function aplicar(int $planId, ?int $routerId = null): array
    {
        $plan = $this->plan($planId);

        if (!$plan->bajada_mbps || !$plan->subida_mbps) {
            throw new \InvalidArgumentException('Primero definí cuánto baja y cuánto sube este plan.');
        }

        $router = ConectionRouter::where('company_id', $this->companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->orderBy('id')->first();

        if (!$router) {
            throw new \InvalidArgumentException('La empresa no tiene un MikroTik configurado.');
        }

        $api = $this->conexion->conection($router->token);
        $avisos = [];

        $perfil = $this->perfilDelPlan($api, $plan);
        $pppoe  = $this->aplicarAPppoe($api, $plan, $perfil, $avisos);
        $colas  = $this->aplicarAIpFija($api, $plan, $avisos);

        DB::table('internet_plans')->where('id', $plan->id)->update([
            'pppoe_profile'       => $perfil,
            'control_aplicado_en' => now(),
        ]);

        $saltados = DB::table('user_data')->where('company_id', $this->companyId)
            ->where('active', 1)->where('internet_plans_id', $plan->id)
            ->where('control_velocidad', 'sin_limite')->count();

        Log::info('[Velocidad] Plan aplicado', [
            'plan' => $plan->plan_name, 'perfil' => $perfil, 'pppoe' => $pppoe, 'colas' => $colas,
        ]);

        return compact('perfil', 'pppoe', 'colas', 'saltados', 'avisos');
    }

    // ── Traducción al router ──────────────────────────────────────────────

    /**
     * El renglón que entiende el MikroTik.
     *
     * Va al revés de como se piensa: primero lo que sube el cliente y después
     * lo que baja. El umbral es el 80% de la velocidad normal: mientras el
     * consumo esté por debajo, la ráfaga está disponible.
     */
    public function rateLimit(object $plan): string
    {
        $sube = $plan->subida_mbps;
        $baja = $plan->bajada_mbps;
        $renglon = "{$sube}M/{$baja}M";

        if ($plan->rafaga && $plan->rafaga_bajada_mbps) {
            $rSube = $plan->rafaga_subida_mbps ?: $sube;
            $rBaja = $plan->rafaga_bajada_mbps;
            $uSube = max(1, (int) round($sube * self::UMBRAL));
            $uBaja = max(1, (int) round($baja * self::UMBRAL));
            $t = $plan->rafaga_segundos;

            $renglon .= " {$rSube}M/{$rBaja}M {$uSube}M/{$uBaja}M {$t}/{$t}";
        }

        return $renglon . ' ' . $plan->prioridad;
    }

    /** Crea o actualiza el perfil PPP del plan y devuelve su nombre. */
    private function perfilDelPlan($api, object $plan): string
    {
        $nombre = 'plan-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($plan->plan_name)), '-');
        $limite = $this->rateLimit($plan);

        $existe = $api->query((new Query('/ppp/profile/print'))->where('name', $nombre)->add('=.proplist=.id'))->read();

        $q = new Query($existe ? '/ppp/profile/set' : '/ppp/profile/add');

        if ($existe) {
            $q->equal('.id', $existe[0]['.id']);
        }

        $q->equal('name', $nombre);
        $q->equal('rate-limit', $limite);
        $api->query($q)->read();

        return $nombre;
    }

    /**
     * Pone a los clientes PPPoE del plan en su perfil.
     *
     * @param  list<string>  $avisos
     */
    private function aplicarAPppoe($api, object $plan, string $perfil, array &$avisos): int
    {
        $clientes = DB::table('user_data')
            ->where('company_id', $this->companyId)->where('active', 1)
            ->where('internet_plans_id', $plan->id)
            ->where('connection_type', 'pppoe')
            ->where('control_velocidad', 'plan')
            ->whereNotNull('pppoe_user')
            ->get(['user_id', 'pppoe_user']);

        $puestos = 0;

        foreach ($clientes as $c) {
            try {
                $secret = $api->query((new Query('/ppp/secret/print'))->where('name', $c->pppoe_user)->add('=.proplist=.id'))->read();

                if (!$secret) {
                    $avisos[] = "{$c->pppoe_user} no tiene credencial en el router.";
                    continue;
                }

                $api->query((new Query('/ppp/secret/set'))->equal('.id', $secret[0]['.id'])->equal('profile', $perfil))->read();

                DB::table('user_data')->where('user_id', $c->user_id)->update(['pppoe_profile' => $perfil]);
                $puestos++;
            } catch (\Throwable $e) {
                $avisos[] = "No se pudo cambiar el perfil de {$c->pppoe_user}: " . $e->getMessage();
            }
        }

        return $puestos;
    }

    /**
     * Una cola por cliente de IP fija: en IP fija la velocidad no puede ir en
     * un perfil, hay que limitar su IP.
     *
     * @param  list<string>  $avisos
     */
    private function aplicarAIpFija($api, object $plan, array &$avisos): int
    {
        $clientes = DB::table('user_data as ud')
            ->join('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('ud.company_id', $this->companyId)->where('ud.active', 1)
            ->where('ud.internet_plans_id', $plan->id)
            ->where(fn ($q) => $q->where('ud.connection_type', '!=', 'pppoe')->orWhereNull('ud.connection_type'))
            ->where('ud.control_velocidad', 'plan')
            ->whereNotNull('t.ip')
            ->get(['ud.dni', 't.ip']);

        $limite = "{$plan->subida_mbps}M/{$plan->bajada_mbps}M";
        $hechas = 0;

        foreach ($clientes as $c) {
            try {
                $existe = $api->query((new Query('/queue/simple/print'))->where('comment', $c->dni)->add('=.proplist=.id'))->read();

                $q = new Query($existe ? '/queue/simple/set' : '/queue/simple/add');

                if ($existe) {
                    $q->equal('.id', $existe[0]['.id']);
                } else {
                    $q->equal('name', 'cliente-' . $c->dni);
                    $q->equal('comment', $c->dni);
                }

                $q->equal('target', $c->ip);
                $q->equal('max-limit', $limite);

                if ($plan->rafaga && $plan->rafaga_bajada_mbps) {
                    $rSube = $plan->rafaga_subida_mbps ?: $plan->subida_mbps;
                    $q->equal('burst-limit', "{$rSube}M/{$plan->rafaga_bajada_mbps}M");
                    $q->equal('burst-threshold', max(1, (int) round($plan->subida_mbps * self::UMBRAL)) . 'M/'
                        . max(1, (int) round($plan->bajada_mbps * self::UMBRAL)) . 'M');
                    $q->equal('burst-time', "{$plan->rafaga_segundos}s/{$plan->rafaga_segundos}s");
                }

                $api->query($q)->read();
                $hechas++;
            } catch (\Throwable $e) {
                $avisos[] = "No se pudo limitar a {$c->dni} ({$c->ip}): " . $e->getMessage();
            }
        }

        return $hechas;
    }

    private function plan(int $planId): object
    {
        $plan = DB::table('internet_plans')
            ->where('id', $planId)
            ->where('company_id', $this->companyId)
            ->first();

        return $plan ?? throw new \InvalidArgumentException('Ese plan no es de tu empresa.');
    }
}
