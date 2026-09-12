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
    public function aplicar(int $planId, ?int $routerId = null, bool $reconectar = false): array
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

        $perfiles = $this->perfilesDelPlan($api, $plan);
        $perfil   = reset($perfiles) ?: null;
        $pppoe    = $this->aplicarAPppoe($api, $plan, $perfiles, $avisos);
        $cortadas = $reconectar ? $this->reconectar($api, $plan, $avisos) : 0;
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

        return compact('perfil', 'pppoe', 'colas', 'saltados', 'avisos', 'cortadas');
    }

    /**
     * Baja las sesiones PPPoE del plan para que tomen el perfil nuevo.
     *
     * MikroTik aplica el perfil al iniciar la sesión: si el cliente ya estaba
     * conectado, sigue con la velocidad vieja hasta que reconecte. El corte
     * dura unos segundos y el equipo vuelve solo.
     *
     * @param  list<string>  $avisos
     */
    private function reconectar($api, object $plan, array &$avisos): int
    {
        $usuarios = DB::table('user_data')
            ->where('company_id', $this->companyId)->where('active', 1)
            ->where('internet_plans_id', $plan->id)
            ->where('connection_type', 'pppoe')
            ->where('control_velocidad', 'plan')
            ->whereNotNull('pppoe_user')
            ->pluck('pppoe_user');

        $cortadas = 0;

        foreach ($usuarios as $usuario) {
            try {
                foreach ($api->query((new Query('/ppp/active/print'))->where('name', $usuario)->add('=.proplist=.id'))->read() as $sesion) {
                    $api->query((new Query('/ppp/active/remove'))->equal('.id', $sesion['.id']))->read();
                    $cortadas++;
                }
            } catch (\Throwable $e) {
                $avisos[] = "No se pudo reconectar a {$usuario}: " . $e->getMessage();
            }
        }

        return $cortadas;
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

        if ($plan->rafaga && $plan->rafaga_bajada_mbps) {
            $rSube = $plan->rafaga_subida_mbps ?: $sube;
            $rBaja = $plan->rafaga_bajada_mbps;
            $uSube = max(1, (int) round($sube * self::UMBRAL));
            $uBaja = max(1, (int) round($baja * self::UMBRAL));
            $t = $plan->rafaga_segundos;

            return "{$sube}M/{$baja}M {$rSube}M/{$rBaja}M {$uSube}M/{$uBaja}M {$t}/{$t} {$plan->prioridad}";
        }

        // Sin ráfaga y con prioridad normal alcanza con las dos velocidades.
        if ((int) $plan->prioridad === 8) {
            return "{$sube}M/{$baja}M";
        }

        // La prioridad es el quinto campo del renglón y no se puede poner sola:
        // "20M/20M 8" hace que el router lea 8 bits de ráfaga, la dé por menor
        // que el límite y rechace la cola, tumbando la sesión del cliente. Si
        // hay que fijar prioridad, se rellenan los campos de ráfaga con la
        // misma velocidad y tiempo cero, que es ráfaga desactivada.
        return "{$sube}M/{$baja}M {$sube}M/{$baja}M {$sube}M/{$baja}M 0/0 {$plan->prioridad}";
    }

    /**
     * Los perfiles PPP del plan, uno por cada perfil base que usen sus clientes.
     *
     * Un perfil PPP no es sólo velocidad: también dice de qué pool sale la IP
     * del cliente. Si se crea uno sólo con rate-limit, el cliente se queda sin
     * IP y la sesión no levanta —el router lo registra como "port-error"—, o
     * sea que el cliente se queda sin internet. Por eso cada perfil del plan
     * se hace copiando el que el cliente ya usaba, que en esta red cambia
     * según la VLAN por donde entra.
     *
     * @return array<string,string>  perfil base → perfil del plan
     */
    private function perfilesDelPlan($api, object $plan): array
    {
        $limite = $this->rateLimit($plan);
        $mapa   = [];

        foreach ($this->basesDeLosClientes($api, $plan) as $base => $datos) {
            $sufijo = $base === $this->basePorDefecto($api) ? '' : '-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($base)), '-');
            $nombre = 'plan-' . $this->slug($plan) . $sufijo;

            $existe = $api->query((new Query('/ppp/profile/print'))->where('name', $nombre)->add('=.proplist=.id'))->read();
            $q = new Query($existe ? '/ppp/profile/set' : '/ppp/profile/add');

            if ($existe) {
                $q->equal('.id', $existe[0]['.id']);
            }

            $q->equal('name', $nombre);
            $q->equal('rate-limit', $limite);
            $q->equal('comment', "Netplay velocidad · base {$base}");

            // Lo que hace que la sesión funcione: de dónde sale la IP y con qué
            // DNS. Se copia tal cual del perfil que el cliente ya tenía.
            foreach (['local-address', 'remote-address', 'dns-server', 'change-tcp-mss', 'use-encryption', 'only-one'] as $campo) {
                if (($datos[$campo] ?? '') !== '') {
                    $q->equal($campo, $datos[$campo]);
                }
            }

            $api->query($q)->read();
            $mapa[$base] = $nombre;
        }

        return $mapa;
    }

    /**
     * Qué perfiles usan hoy los clientes del plan, con sus datos.
     *
     * @return array<string, array<string,mixed>>
     */
    private function basesDeLosClientes($api, object $plan): array
    {
        $perfiles = [];

        foreach ($api->query(new Query('/ppp/profile/print'))->read() as $p) {
            $perfiles[$p['name']] = $p;
        }

        $usuarios = DB::table('user_data')
            ->where('company_id', $this->companyId)->where('active', 1)
            ->where('internet_plans_id', $plan->id)
            ->where('connection_type', 'pppoe')
            ->whereNotNull('pppoe_user')
            ->pluck('pppoe_user');

        $bases = [];

        foreach ($usuarios as $usuario) {
            $secret = $api->query((new Query('/ppp/secret/print'))->where('name', $usuario))->read();
            $actual = $secret[0]['profile'] ?? null;

            if (!$actual || !isset($perfiles[$actual])) {
                continue;
            }

            // Si ya está en un perfil nuestro, su base quedó anotada al crearlo.
            if (preg_match('/base (\S+)/', $perfiles[$actual]['comment'] ?? '', $m) && isset($perfiles[$m[1]])) {
                $actual = $m[1];
            }

            $bases[$actual] = $perfiles[$actual];
        }

        // Sin clientes todavía, se usa el perfil con el que atiende el servidor.
        if (!$bases) {
            $porDefecto = $this->basePorDefecto($api);
            $bases[$porDefecto] = $perfiles[$porDefecto] ?? [];
        }

        return $bases;
    }

    /** El perfil con el que el servidor PPPoE atiende por defecto. */
    private function basePorDefecto($api): string
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $servidores = $api->query(new Query('/interface/pppoe-server/server/print'))->read();

        return $cache = $servidores[0]['default-profile'] ?? 'default';
    }

    /**
     * Pone a los clientes PPPoE del plan en su perfil.
     *
     * @param  list<string>  $avisos
     */
    private function aplicarAPppoe($api, object $plan, array $perfiles, array &$avisos): int
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
                $secret = $api->query((new Query('/ppp/secret/print'))->where('name', $c->pppoe_user))->read();

                if (!$secret) {
                    $avisos[] = "{$c->pppoe_user} no tiene credencial en el router.";
                    continue;
                }

                // Cada cliente va al perfil del plan que sale de su perfil
                // actual: así conserva su pool de IP y su VLAN.
                $actual = $secret[0]['profile'] ?? '';
                $perfil = $perfiles[$actual] ?? (in_array($actual, $perfiles, true) ? $actual : reset($perfiles));

                if (!$perfil) {
                    $avisos[] = "No se pudo decidir el perfil de {$c->pppoe_user}.";
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
     * Los clientes de IP fija, con el modelo que este router ya usa: una lista
     * de direcciones por plan, marcas de mangle y una rama del árbol con PCQ.
     *
     * Se eligió así y no con una cola por cliente porque es lo que el router
     * ya tenía montado —había listas "50MB" y "100MB" con cientos de IP— y
     * porque escala: son tres reglas por plan en vez de una cola por abonado.
     * El PCQ reparte el límite por dirección IP, así que cada cliente de la
     * lista recibe su velocidad completa.
     *
     * La ráfaga no entra acá: PCQ no la tiene. Sólo aplica a los PPPoE.
     *
     * @param  list<string>  $avisos
     * @return int  clientes alcanzados
     */
    private function aplicarAIpFija($api, object $plan, array &$avisos): int
    {
        $slug  = $this->slug($plan);
        $lista = "vel-{$slug}";

        if ($plan->rafaga) {
            $avisos[] = 'La ráfaga sólo se aplica a los clientes PPPoE: los de IP fija se limitan por lista y ahí no existe.';
        }

        $this->asegurarPcq($api, "{$lista}-down", (int) $plan->bajada_mbps, 'dst-address');
        $this->asegurarPcq($api, "{$lista}-up", (int) $plan->subida_mbps, 'src-address');

        $this->asegurarMarca($api, 'forward', 'src-address-list', $lista, "{$slug}-up");
        $this->asegurarMarca($api, 'postrouting', 'dst-address-list', $lista, "{$slug}-down");

        $this->asegurarRama($api, "{$lista}-down", "{$slug}-down", "{$lista}-down");
        $this->asegurarRama($api, "{$lista}-up", "{$slug}-up", "{$lista}-up");

        return $this->sincronizarLista($api, $plan, $lista, $avisos);
    }

    /** El tipo de cola que reparte el límite por cliente. */
    private function asegurarPcq($api, string $nombre, int $mbps, string $clasificador): void
    {
        $existe = $api->query((new Query('/queue/type/print'))->where('name', $nombre)->add('=.proplist=.id'))->read();

        $q = new Query($existe ? '/queue/type/set' : '/queue/type/add');

        if ($existe) {
            $q->equal('.id', $existe[0]['.id']);
        }

        $q->equal('name', $nombre);
        $q->equal('kind', 'pcq');
        $q->equal('pcq-rate', ($mbps * 1000000) . '');
        $q->equal('pcq-classifier', $clasificador);
        $api->query($q)->read();
    }

    /** La regla que marca el tráfico de esa lista. */
    private function asegurarMarca($api, string $cadena, string $campoLista, string $lista, string $marca): void
    {
        $existe = $api->query((new Query('/ip/firewall/mangle/print'))
            ->where('comment', "Netplay velocidad {$marca}")->add('=.proplist=.id'))->read();

        $q = new Query($existe ? '/ip/firewall/mangle/set' : '/ip/firewall/mangle/add');

        if ($existe) {
            $q->equal('.id', $existe[0]['.id']);
        }

        $q->equal('chain', $cadena);
        $q->equal($campoLista, $lista);
        $q->equal('action', 'mark-packet');
        $q->equal('new-packet-mark', $marca);
        $q->equal('passthrough', 'no');
        $q->equal('comment', "Netplay velocidad {$marca}");
        $api->query($q)->read();
    }

    /** La rama del árbol que aplica el PCQ a lo marcado. */
    private function asegurarRama($api, string $nombre, string $marca, string $tipoDeCola): void
    {
        $existe = $api->query((new Query('/queue/tree/print'))->where('name', $nombre)->add('=.proplist=.id'))->read();

        $q = new Query($existe ? '/queue/tree/set' : '/queue/tree/add');

        if ($existe) {
            $q->equal('.id', $existe[0]['.id']);
        }

        $q->equal('name', $nombre);
        $q->equal('parent', 'global');
        $q->equal('packet-mark', $marca);
        $q->equal('queue', $tipoDeCola);
        $api->query($q)->read();
    }

    /**
     * Deja en la lista exactamente las IP de los clientes del plan.
     *
     * @param  list<string>  $avisos
     */
    private function sincronizarLista($api, object $plan, string $lista, array &$avisos): int
    {
        $clientes = DB::table('user_data as ud')
            ->join('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('ud.company_id', $this->companyId)->where('ud.active', 1)
            ->where('ud.internet_plans_id', $plan->id)
            ->where(fn ($q) => $q->where('ud.connection_type', '!=', 'pppoe')->orWhereNull('ud.connection_type'))
            ->where('ud.control_velocidad', 'plan')
            ->whereNotNull('t.ip')
            ->get(['ud.dni', 't.ip']);

        $enElRouter = [];

        foreach ($api->query((new Query('/ip/firewall/address-list/print'))->where('list', $lista))->read() as $fila) {
            $enElRouter[$fila['address']] = $fila['.id'];
        }

        // Otras listas que también marcan tráfico: si un cliente está en una de
        // ellas, esa marca gana y el límite del plan no se aplica.
        $otras = $this->listasQueMarcan($api, $lista);

        foreach ($clientes as $c) {
            if (!isset($enElRouter[$c->ip])) {
                $api->query((new Query('/ip/firewall/address-list/add'))
                    ->equal('list', $lista)->equal('address', $c->ip)->equal('comment', (string) $c->dni))->read();
            }

            unset($enElRouter[$c->ip]);

            foreach ($otras as $otra => $ips) {
                if (in_array($c->ip, $ips, true)) {
                    $avisos[] = "La IP {$c->ip} ({$c->dni}) también está en la lista «{$otra}», que tiene su propia regla: ahí manda esa y no el plan.";
                }
            }
        }

        // Lo que sobra en la lista ya no es de este plan.
        foreach ($enElRouter as $ip => $id) {
            $api->query((new Query('/ip/firewall/address-list/remove'))->equal('.id', $id))->read();
        }

        return $clientes->count();
    }

    /**
     * Qué listas de direcciones se usan en reglas de marcado, aparte de la del
     * plan. Sirve para avisar de choques con lo que ya había en el router.
     *
     * @return array<string, list<string>>
     */
    private function listasQueMarcan($api, string $propia): array
    {
        $listas = [];

        foreach ($api->query(new Query('/ip/firewall/mangle/print'))->read() as $regla) {
            if (($regla['action'] ?? '') !== 'mark-packet') {
                continue;
            }

            foreach (['src-address-list', 'dst-address-list'] as $campo) {
                $nombre = $regla[$campo] ?? null;

                if ($nombre && $nombre !== $propia) {
                    $listas[$nombre] ??= [];
                }
            }
        }

        foreach (array_keys($listas) as $nombre) {
            $listas[$nombre] = array_column(
                $api->query((new Query('/ip/firewall/address-list/print'))->where('list', $nombre))->read(),
                'address'
            );
        }

        return $listas;
    }

    /** "INTERNET 200MG PLUS" → "internet-200mg-plus" */
    private function slug(object $plan): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($plan->plan_name)), '-');
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
