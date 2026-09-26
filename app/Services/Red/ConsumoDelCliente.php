<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\ConectionRouter;
use App\Services\Acs\EquiposDelAcs;
use App\Services\Acs\GenieAcs;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Cuántos datos usa un cliente y a qué velocidad navega ahora.
 *
 *  · Consumo: sale de los contadores que la ONT le reporta al TR-069 cada
 *    hora. El equipo cuenta desde que se encendió, así que se guarda la última
 *    lectura y lo que creció se suma al día.
 *  · Velocidad ahora: se mide unos segundos en el MikroTik, sobre la IP o la
 *    sesión PPPoE del cliente. Sirve para todos, tenga o no TR-069.
 */
class ConsumoDelCliente
{
    /** Un salto mayor a esto en una hora es un contador que se volvió loco. */
    private const SALTO_MAXIMO = 5 * 1024 ** 4;

    /** Contadores de 32 bits: dan la vuelta al llegar a 4 GB. */
    private const VUELTA_32 = 4294967296;

    private const SEGUNDOS_DE_MEDICION = 3;

    public function __construct(private int $companyId) {}

    // ── Registro horario ──────────────────────────────────────────────────

    /** @return array{equipos:int, con_contador:int, sumados:int} */
    public function registrar(): array
    {
        $deClientes = collect((new EquiposDelAcs($this->companyId))->lista())
            ->filter(fn ($e) => $e['cliente']['user_id'] ?? null)
            ->keyBy('id');

        $resultado = ['equipos' => $deClientes->count(), 'con_contador' => 0, 'sumados' => 0];

        if ($deClientes->isEmpty()) {
            return $resultado;
        }

        $documentos = GenieAcs::deEmpresa($this->companyId)->dispositivos(
            ['_id' => ['$in' => $deClientes->keys()->values()->all()]],
            ['_id', 'InternetGatewayDevice.WANDevice'],
        );

        foreach ($documentos as $d) {
            $equipo = $deClientes[$d['_id']] ?? null;
            $lectura = self::contadores($d);

            if (!$equipo || !$lectura) {
                continue;
            }

            $resultado['con_contador']++;

            if ($this->anotar((int) $equipo['cliente']['user_id'], $d['_id'], $lectura)) {
                $resultado['sumados']++;
            }
        }

        return $resultado;
    }

    /** Guarda la lectura y suma al día lo nuevo. true si sumó algo. */
    private function anotar(int $userId, string $acsId, array $l): bool
    {
        $antes = DB::table('consumo_contadores')->where('user_id', $userId)->first();
        $ahora = now();

        $fila = [
            'company_id' => $this->companyId, 'acs_id' => $acsId, 'fuente' => $l['fuente'],
            'bajada' => $l['bajada'], 'subida' => $l['subida'], 'medido_en' => $l['medido_en'], 'updated_at' => $ahora,
        ];

        if (!$antes) {
            DB::table('consumo_contadores')->insert($fila + ['user_id' => $userId, 'created_at' => $ahora]);
            return false;
        }

        // El equipo no informó nada nuevo desde la lectura anterior.
        if ($antes->medido_en && $l['medido_en'] && Carbon::parse($antes->medido_en)->equalTo($l['medido_en'])) {
            return false;
        }

        DB::table('consumo_contadores')->where('id', $antes->id)->update($fila);

        // Otro equipo u otro contador: la lectura nueva es el punto de partida.
        if ($antes->acs_id !== $acsId || $antes->fuente !== $l['fuente']) {
            return false;
        }

        $bajada = self::diferencia((float) $antes->bajada, $l['bajada']);
        $subida = self::diferencia((float) $antes->subida, $l['subida']);

        if ($bajada === null || $subida === null || ($bajada + $subida) <= 0) {
            return false;
        }

        $fecha = ($l['medido_en'] ?? $ahora)->copy()->timezone(config('app.timezone'))->toDateString();

        DB::statement(
            'INSERT INTO consumo_diario (company_id, user_id, fecha, bajada_bytes, subida_bytes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE bajada_bytes = bajada_bytes + VALUES(bajada_bytes),
                                     subida_bytes = subida_bytes + VALUES(subida_bytes),
                                     updated_at = VALUES(updated_at)',
            [$this->companyId, $userId, $fecha, (int) $bajada, (int) $subida, $ahora, $ahora],
        );

        return true;
    }

    /** Lo que creció el contador; null si el salto no es creíble. */
    private static function diferencia(float $antes, float $ahora): ?float
    {
        if ($ahora >= $antes) {
            $d = $ahora - $antes;
        } elseif ($antes < self::VUELTA_32 && $antes > self::VUELTA_32 * 0.75) {
            // Contador de 32 bits que dio la vuelta.
            $d = self::VUELTA_32 - $antes + $ahora;
        } else {
            // Se reinició el equipo: lo contado desde el encendido es todo nuevo.
            $d = $ahora;
        }

        return $d > self::SALTO_MAXIMO ? null : $d;
    }

    /**
     * Los contadores de un documento del ACS. El del enlace de fibra es el
     * bueno (cuenta todo lo de la casa); el de la conexión de internet se
     * reinicia cada vez que la sesión PPPoE se cae.
     *
     * @return array{fuente:string, bajada:float, subida:float, medido_en:?Carbon}|null
     */
    public static function contadores(array $d): ?array
    {
        $hallado = ['pon' => [], 'wan' => []];

        foreach (self::hojas($d) as $ruta => [$valor, $cuando]) {
            if (!is_numeric($valor)) {
                continue;
            }

            $fuente = preg_match('/(Epon|Gpon|Pon)InterfaceConfig/i', $ruta) ? 'pon'
                : (str_contains($ruta, 'WANCommonInterfaceConfig') ? 'wan' : null);

            if (!$fuente) {
                continue;
            }

            $sentido = preg_match('/BytesReceived$/i', $ruta) ? 'bajada' : (preg_match('/BytesSent$/i', $ruta) ? 'subida' : null);

            if ($sentido && !isset($hallado[$fuente][$sentido])) {
                $hallado[$fuente][$sentido] = (float) $valor;
                $hallado[$fuente]['cuando'] = $cuando;
            }
        }

        foreach (['pon', 'wan'] as $fuente) {
            $h = $hallado[$fuente];

            if (isset($h['bajada'], $h['subida'])) {
                return [
                    'fuente'    => $fuente,
                    'bajada'    => $h['bajada'],
                    'subida'    => $h['subida'],
                    'medido_en' => !empty($h['cuando']) ? Carbon::parse($h['cuando']) : null,
                ];
            }
        }

        return null;
    }

    /** @return iterable<string, array{0:mixed, 1:?string}> */
    private static function hojas(array $d, string $prefijo = ''): iterable
    {
        foreach ($d as $k => $v) {
            if (!is_array($v) || str_starts_with((string) $k, '_')) {
                continue;
            }

            $ruta = $prefijo === '' ? (string) $k : "{$prefijo}.{$k}";

            if (array_key_exists('_value', $v)) {
                yield $ruta => [$v['_value'], $v['_timestamp'] ?? null];
            } else {
                yield from self::hojas($v, $ruta);
            }
        }
    }

    // ── Lo que ve el cliente ──────────────────────────────────────────────

    /** Los últimos 30 días, el total del mes y la velocidad de su plan. */
    public function historial(int $userId): array
    {
        $desde = now()->subDays(29)->startOfDay();

        $dias = DB::table('consumo_diario')
            ->where('user_id', $userId)
            ->where('company_id', $this->companyId)
            ->where('fecha', '>=', $desde->toDateString())
            ->get(['fecha', 'bajada_bytes', 'subida_bytes'])
            ->keyBy(fn ($f) => (string) $f->fecha);

        $serie = [];
        for ($i = 0; $i < 30; $i++) {
            $fecha = $desde->copy()->addDays($i)->toDateString();
            $f = $dias[$fecha] ?? null;
            $serie[] = ['fecha' => $fecha, 'bajada' => (int) ($f->bajada_bytes ?? 0), 'subida' => (int) ($f->subida_bytes ?? 0)];
        }

        $mes = DB::table('consumo_diario')
            ->where('user_id', $userId)
            ->where('company_id', $this->companyId)
            ->where('fecha', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('COALESCE(SUM(bajada_bytes),0) as bajada, COALESCE(SUM(subida_bytes),0) as subida')
            ->first();

        $contador = DB::table('consumo_contadores')->where('user_id', $userId)->first(['created_at', 'medido_en']);

        return [
            'midiendo_desde' => $contador?->created_at,
            'ultima_lectura' => $contador?->medido_en,
            'mes'            => ['bajada' => (int) $mes->bajada, 'subida' => (int) $mes->subida],
            'dias'           => $serie,
            'plan'           => $this->plan($userId),
        ];
    }

    /** @return array{nombre:?string, bajada_mbps:?float, subida_mbps:?float} */
    private function plan(int $userId): array
    {
        $p = DB::table('user_data as ud')
            ->leftJoin('internet_plans as p', 'p.id', '=', 'ud.internet_plans_id')
            ->where('ud.user_id', $userId)
            ->where('ud.company_id', $this->companyId)
            ->first(['p.plan_name', 'p.bajada_mbps', 'p.subida_mbps']);

        return [
            'nombre'      => $p?->plan_name,
            'bajada_mbps' => $p?->bajada_mbps !== null ? (float) $p->bajada_mbps : null,
            'subida_mbps' => $p?->subida_mbps !== null ? (float) $p->subida_mbps : null,
        ];
    }

    // ── Velocidad ahora ───────────────────────────────────────────────────

    /**
     * Mide unos segundos el tráfico del cliente en el MikroTik.
     *
     * @return array{ok:bool, bajada_mbps?:float, subida_mbps?:float, segundos?:int, detalle?:string}
     */
    public function velocidadAhora(int $userId): array
    {
        $cliente = DB::table('user_data as ud')
            ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('ud.user_id', $userId)
            ->where('ud.company_id', $this->companyId)
            ->first(['ud.router_id', 'ud.connection_type', 'ud.pppoe_user', 't.ip']);

        if (!$cliente || (!$cliente->ip && !$cliente->pppoe_user)) {
            return ['ok' => false, 'detalle' => 'Su servicio no tiene una conexión registrada para medir.'];
        }

        $pppoe = $cliente->connection_type === 'pppoe' && $cliente->pppoe_user;

        foreach ($this->routersPosibles($userId, $cliente->router_id) as $router) {
            try {
                $api = app(ConectionRouterManagerInterface::class)->conection($router->token);
                $medida = $pppoe ? $this->medirPppoe($api, (string) $cliente->pppoe_user) : $this->medirIp($api, (string) $cliente->ip);
            } catch (\Throwable $e) {
                // Un router que no responde no se vuelve a probar por un rato.
                Cache::put("consumo:router-caido:{$router->id}", true, now()->addMinutes(10));
                Log::info('[Consumo] Router sin respuesta al medir velocidad', ['router' => $router->id, 'error' => $e->getMessage()]);
                continue;
            }

            if ($medida) {
                Cache::put("consumo:router-de:{$userId}", $router->id, now()->addDay());
                return ['ok' => true, 'segundos' => self::SEGUNDOS_DE_MEDICION] + $medida;
            }
        }

        return ['ok' => false, 'detalle' => 'Su conexión no aparece activa en este momento. Si no tienes internet, reporta la falla.'];
    }

    /** @return iterable<ConectionRouter> primero el que ya lo encontró, después el suyo, después el resto */
    private function routersPosibles(int $userId, ?int $suyo): iterable
    {
        $routers = ConectionRouter::where('company_id', $this->companyId)->orderBy('id')->get()
            ->reject(fn ($r) => Cache::has("consumo:router-caido:{$r->id}"));

        $primero = Cache::get("consumo:router-de:{$userId}") ?? $suyo;

        return $routers->sortBy(fn ($r) => $r->id === $primero ? 0 : 1)->values();
    }

    /** IP fija: torch sobre la interfaz donde el router ve al cliente. */
    private function medirIp($api, string $ip): ?array
    {
        $arp = $api->query((new Query('/ip/arp/print'))->where('address', $ip))->read();
        $interfaz = $arp[0]['interface'] ?? null;

        if (!$interfaz) {
            return null;
        }

        $filas = $api->query((new Query('/tool/torch'))
            ->equal('interface', $interfaz)
            ->equal('src-address', "{$ip}/32")
            ->equal('duration', (string) self::SEGUNDOS_DE_MEDICION))->read();

        // Hacia el cliente sale ("tx") por su interfaz; lo que manda entra ("rx").
        $tx = 0; $rx = 0;
        foreach ($filas as $f) {
            $tx += (int) ($f['tx'] ?? 0);
            $rx += (int) ($f['rx'] ?? 0);
        }

        return ['bajada_mbps' => round($tx / 1e6, 2), 'subida_mbps' => round($rx / 1e6, 2)];
    }

    /** PPPoE: el tráfico de la interfaz dinámica de su sesión. */
    private function medirPppoe($api, string $usuario): ?array
    {
        $activa = $api->query((new Query('/ppp/active/print'))->where('name', $usuario))->read();

        if (!$activa) {
            return null;
        }

        $r = $api->query((new Query('/interface/monitor-traffic'))
            ->equal('interface', "<pppoe-{$usuario}>")
            ->equal('duration', (string) self::SEGUNDOS_DE_MEDICION))->read();

        $bajadas = array_filter(array_map(fn ($f) => $f['tx-bits-per-second'] ?? null, $r), 'is_numeric');
        $subidas = array_filter(array_map(fn ($f) => $f['rx-bits-per-second'] ?? null, $r), 'is_numeric');

        if (!$bajadas) {
            return null;
        }

        return [
            'bajada_mbps' => round(max($bajadas) / 1e6, 2),
            'subida_mbps' => round(($subidas ? max($subidas) : 0) / 1e6, 2),
        ];
    }
}
