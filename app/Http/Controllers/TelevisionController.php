<?php

namespace App\Http\Controllers;

use App\Models\TvCanal;
use App\Models\TvLinea;
use App\Models\TvPanel;
use App\Services\NotificationRouterService;
use App\Services\Tv\LineasDeTv;
use App\Services\Tv\PanelXui;
use App\Services\Tv\PreviasDeCanales;
use Illuminate\Support\Facades\URL;
use App\Services\Tv\VigiaDeCanales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Televisión por IPTV (21-10-2026): la conexión con el panel XUI.one, los canales y sus alertas,
 * las líneas de los clientes y la tarjeta de TV en la ficha.
 */
class TelevisionController extends Controller
{
    private function empresa(): int { return (int) getSessionCompanyId(); }

    private function bien(string $mensaje, mixed $data = null): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $data, 'error' => 0]);
    }

    private function mal(string $mensaje, int $http = 200): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => null, 'error' => 1], $http);
    }

    private function panel(): ?TvPanel { return TvPanel::deLaEmpresa($this->empresa()); }

    /** Corre la operación y convierte lo que falle en un mensaje para la pantalla. */
    private function operar(string $ok, callable $fn): JsonResponse
    {
        try {
            return $this->bien($ok, $fn());
        } catch (\DomainException $e) {
            return $this->mal($e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('[TV] Falló la operación', ['empresa' => $this->empresa(), 'error' => $e->getMessage()]);

            return $this->mal(str_starts_with($e->getMessage(), 'El panel de TV') ? $e->getMessage() : 'No se pudo completar. Intente de nuevo.');
        }
    }

    private function lineas(): LineasDeTv
    {
        $p = $this->panel();
        if (!$p) {
            throw new \DomainException('Primero conecte el panel de TV en Televisión › Configuración.');
        }

        return new LineasDeTv($p);
    }

    // ── Configuración ────────────────────────────────────────────────────────

    /** GET api/tv/configuracion — el panel, sus bouquets, los planes y a dónde van las alertas. */
    public function configuracion(): JsonResponse
    {
        $p = $this->panel();
        $bouquets = [];
        $conexion = null;
        if ($p) {
            try {
                $bouquets = (new PanelXui($p))->bouquets();
                $conexion = 'ok';
            } catch (\Throwable $e) {
                $conexion = $e->getMessage();
            }
        }

        $planes = DB::table('internet_plans')->where('company_id', $this->empresa())->where('active', 1)->orderBy('monthly_price')
            ->get(['id', 'plan_name', 'monthly_price', 'tv_incluida', 'tv_bouquets', 'tv_pantallas'])
            ->map(fn ($x) => [
                'id' => (int) $x->id, 'nombre' => $x->plan_name, 'precio' => (float) $x->monthly_price, 'tv_incluida' => (bool) $x->tv_incluida,
                'tv_bouquets' => json_decode((string) $x->tv_bouquets, true) ?: [], 'tv_pantallas' => $x->tv_pantallas ? (int) $x->tv_pantallas : null,
                'clientes' => DB::table('user_data')->where('company_id', $this->empresa())->where('internet_plans_id', $x->id)->where('active', 1)->count(),
            ]);

        return $this->bien('OK', [
            'panel' => $p ? [
                'url' => $p->url, 'codigo_api' => $p->codigo_api, 'url_clientes' => $p->url_clientes, 'bouquets' => $p->bouquets ?: [],
                'pantallas' => (int) $p->pantallas, 'sincronizar_cartera' => $p->sincronizar_cartera, 'clave_fija' => $p->clave_fija,
                'ultima_conexion_en' => $p->ultima_conexion_en, 'ultimo_error' => $p->ultimo_error, 'tiene_llave' => (bool) $p->api_key,
            ] : null,
            'conexion' => $conexion,
            'bouquets' => $bouquets,
            'planes' => $planes,
            'cartera' => $p ? (new LineasDeTv($p))->previaDeCartera() : null,
            'destinos_alertas' => array_values(NotificationRouterService::destinos($this->empresa(), 'alerta_tv')),
        ]);
    }

    /** PUT api/tv/configuracion — guarda el panel; la llave solo si se escribe una nueva. */
    public function guardarConfiguracion(Request $r): JsonResponse
    {
        $d = $r->validate([
            'url' => 'required|url|max:200', 'codigo_api' => 'required|string|max:60|regex:/^[A-Za-z0-9]+$/',
            'api_key' => 'nullable|string|max:120', 'url_clientes' => 'nullable|url|max:200',
            'bouquets' => 'nullable|array', 'bouquets.*' => 'integer', 'pantallas' => 'nullable|integer|min:1|max:20',
            'sincronizar_cartera' => 'nullable|boolean', 'clave_fija' => 'nullable|string|max:20|regex:/^[A-Za-z0-9]+$/',
        ]);

        $p = $this->panel() ?? new TvPanel(['company_id' => $this->empresa()]);
        if (!$p->exists && empty($d['api_key'])) {
            return $this->mal('Falta la API key del panel.', 422);
        }

        $p->fill([
            'url' => rtrim($d['url'], '/'), 'codigo_api' => $d['codigo_api'], 'url_clientes' => $d['url_clientes'] ? rtrim($d['url_clientes'], '/') : null,
            'bouquets' => $d['bouquets'] ?? $p->bouquets, 'pantallas' => $d['pantallas'] ?? $p->pantallas ?? 2,
            'sincronizar_cartera' => (bool) ($d['sincronizar_cartera'] ?? $p->sincronizar_cartera),
            'clave_fija' => array_key_exists('clave_fija', $d) ? ($d['clave_fija'] ?: null) : $p->clave_fija,
        ]);
        if (!empty($d['api_key'])) {
            $p->api_key = trim($d['api_key']);
        }

        try {
            $admin = (new PanelXui($p))->probar();
        } catch (\Throwable $e) {
            return $this->mal('No se guardó: ' . $e->getMessage());
        }

        $p->fill(['ultima_conexion_en' => now(), 'ultimo_error' => null])->save();

        // El menú solo muestra lo que está en los módulos del perfil: el administrador ve Televisión.
        foreach (DB::table('profiles')->where('company_id', $this->empresa())->whereRaw('UPPER(name) = ?', ['ADMIN'])->pluck('id') as $perfil) {
            DB::table('profile_modules')->updateOrInsert(['profile_id' => $perfil, 'module' => 'television'], ['active' => 1, 'updated_at' => now(), 'created_at' => now()]);
        }

        return $this->bien("Conectado al panel como {$admin}.");
    }

    /** PUT api/tv/planes/{id} — si el plan lleva TV y con qué. */
    public function guardarPlan(int $id, Request $r): JsonResponse
    {
        $d = $r->validate(['tv_incluida' => 'required|boolean', 'tv_bouquets' => 'nullable|array', 'tv_bouquets.*' => 'integer', 'tv_pantallas' => 'nullable|integer|min:1|max:20']);
        $n = DB::table('internet_plans')->where('company_id', $this->empresa())->where('id', $id)->update([
            'tv_incluida' => $d['tv_incluida'], 'tv_bouquets' => $d['tv_incluida'] ? json_encode($d['tv_bouquets'] ?? []) : null,
            'tv_pantallas' => $d['tv_incluida'] ? ($d['tv_pantallas'] ?? null) : null, 'updated_at' => now(),
        ]);

        return $n ? $this->bien($d['tv_incluida'] ? 'El plan lleva TV: a los clientes nuevos con este plan se les crea la línea sola.' : 'El plan ya no lleva TV.') : $this->mal('Ese plan no existe.', 404);
    }

    // ── Canales ──────────────────────────────────────────────────────────────

    /** GET api/tv/canales — los canales con su estado, los fuera del aire primero. */
    public function canales(): JsonResponse
    {
        $canales = TvCanal::where('company_id', $this->empresa())->orderByRaw("estado = 'al_aire'")->orderBy('nombre')->get();
        PreviasDeCanales::alguienMira($this->empresa());
        $alertas = DB::table('alertas')->where('company_id', $this->empresa())->where('tipo', 'canal')->whereNull('cerrada_en')->pluck('avisada_en', 'clave');
        $p = $this->panel();

        return $this->bien('OK', [
            'revisado_en' => $canales->max('revisado_en'),
            'ultimo_error' => $p?->ultimo_error,
            'minutos_para_avisar' => VigiaDeCanales::MINUTOS_PARA_AVISAR,
            'avisa_al_grupo' => (bool) NotificationRouterService::destinos($this->empresa(), 'alerta_tv'),
            'canales' => $canales->map(fn (TvCanal $c) => [
                'id' => $c->id, 'stream_id' => $c->stream_id, 'nombre' => $c->nombre, 'estado' => $c->estado, 'vigilar' => $c->vigilar,
                'desde' => $c->estado_desde, 'bitrate' => $c->bitrate, 'espectadores' => $c->espectadores, 'fuente' => $c->fuente,
                'alerta' => $alertas->has("canal:{$c->stream_id}"), 'avisado' => (bool) ($alertas["canal:{$c->stream_id}"] ?? null),
                // Enlaces firmados: el <img> y el reproductor no mandan la sesión. La foto vence a una
                // hora redonda: el enlace no cambia en cada refresco y el navegador la reusa.
                'previa' => $c->previa_en ? URL::temporarySignedRoute('tv.previa', now()->startOfHour()->addHours(2), ['empresa' => $c->company_id, 'stream' => $c->stream_id, 'v' => $c->previa_en->timestamp], false) : null,
                'previa_en' => $c->previa_en,
                'vivo' => $c->estado === 'al_aire' ? URL::temporarySignedRoute('tv.vivo', now()->addMinutes(30), ['empresa' => $c->company_id, 'stream' => $c->stream_id], false) : null,
            ]),
        ]);
    }

    /** POST api/tv/canales/revisar — revisa ya, sin esperar los dos minutos. */
    public function revisarCanales(): JsonResponse
    {
        return $this->operar('Canales revisados.', function () {
            $p = $this->panel() ?? throw new \DomainException('Primero conecte el panel de TV.');

            return (new VigiaDeCanales($p))->revisar();
        });
    }

    /** PATCH api/tv/canales/{id} {vigilar} — un canal que ya no se da no debe avisar. */
    public function vigilarCanal(int $id, Request $r): JsonResponse
    {
        $d = $r->validate(['vigilar' => 'required|boolean']);
        $c = TvCanal::where('company_id', $this->empresa())->findOrFail($id);
        $c->update(['vigilar' => $d['vigilar']]);
        if (!$d['vigilar']) {
            DB::table('alertas')->where('company_id', $this->empresa())->where('clave', "canal:{$c->stream_id}")->whereNull('cerrada_en')
                ->update(['cerrada_en' => now(), 'cierre_avisado_en' => now()]);
        }

        return $this->bien($d['vigilar'] ? "Se vigila {$c->nombre}." : "{$c->nombre} ya no avisa cuando se cae.");
    }

    // ── Líneas ───────────────────────────────────────────────────────────────

    /** GET api/tv/lineas?filtro=&q=&pagina= */
    public function listaDeLineas(Request $r): JsonResponse
    {
        $empresa = $this->empresa();
        $base = TvLinea::where('tv_lineas.company_id', $empresa)->where('tv_lineas.estado', '!=', 'retirada');
        $conteo = [
            'todas' => (clone $base)->count(),
            'con_cliente' => (clone $base)->whereNotNull('user_id')->count(),
            'revisar' => (clone $base)->where('vinculo', 'revisar')->count(),
            'sin_cliente' => (clone $base)->whereNull('user_id')->count(),
            'suspendidas' => (clone $base)->whereIn('estado', ['suspendida', 'desactivada'])->count(),
            'pendientes' => (clone $base)->where('estado', 'pendiente')->count(),
        ];

        $q = trim((string) $r->query('q', ''));
        $filas = (clone $base)
            ->leftJoin('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'tv_lineas.user_id')->on('u.company_id', '=', 'tv_lineas.company_id'))
            ->leftJoin('user_data as s', fn ($j) => $j->on('s.user_id', '=', 'tv_lineas.sugerido_user_id')->on('s.company_id', '=', 'tv_lineas.company_id'))
            ->when($r->query('filtro') === 'con_cliente', fn ($w) => $w->whereNotNull('tv_lineas.user_id'))
            ->when($r->query('filtro') === 'revisar', fn ($w) => $w->where('tv_lineas.vinculo', 'revisar'))
            ->when($r->query('filtro') === 'sin_cliente', fn ($w) => $w->whereNull('tv_lineas.user_id'))
            ->when($r->query('filtro') === 'suspendidas', fn ($w) => $w->whereIn('tv_lineas.estado', ['suspendida', 'desactivada']))
            ->when($r->query('filtro') === 'pendientes', fn ($w) => $w->where('tv_lineas.estado', 'pendiente'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('tv_lineas.usuario', 'like', "%{$q}%")->orWhere('u.names', 'like', "%{$q}%")->orWhere('u.lastname', 'like', "%{$q}%")))
            ->orderByRaw("tv_lineas.vinculo = 'revisar' desc")->orderBy('tv_lineas.usuario');

        $total = (clone $filas)->count();
        $pagina = max(1, (int) $r->query('pagina', 1));
        $bouquets = $this->nombresDeBouquets();

        $lista = $filas->forPage($pagina, 50)->get([
            'tv_lineas.id', 'tv_lineas.user_id', 'tv_lineas.usuario', 'tv_lineas.pantallas', 'tv_lineas.bouquets', 'tv_lineas.estado', 'tv_lineas.origen',
            'tv_lineas.vinculo', 'tv_lineas.sugerido_user_id', 'tv_lineas.sugerido_motivo', 'tv_lineas.ultima_actividad_en', 'tv_lineas.error',
            DB::raw("TRIM(CONCAT(COALESCE(u.names,''),' ',COALESCE(u.lastname,''))) as cliente"), 'u.status_internet_id', 'u.active as cliente_activo',
            DB::raw("TRIM(CONCAT(COALESCE(s.names,''),' ',COALESCE(s.lastname,''))) as sugerido"),
        ])->map(function ($l) use ($bouquets) {
            $b = is_array($l->bouquets) ? $l->bouquets : (json_decode((string) $l->bouquets, true) ?: []);

            return [
                'id' => $l->id, 'user_id' => $l->user_id, 'usuario' => $l->usuario, 'pantallas' => (int) $l->pantallas, 'estado' => $l->estado, 'origen' => $l->origen,
                'paquete' => collect($b)->map(fn ($id) => $bouquets[$id] ?? "#{$id}")->implode(' + ') ?: '—',
                'vinculo' => $l->vinculo, 'sugerido_user_id' => $l->sugerido_user_id, 'sugerido' => $l->sugerido ?: null, 'motivo' => $l->sugerido_motivo,
                'cliente' => $l->cliente ?: null, 'cliente_suspendido' => (int) $l->status_internet_id === 2, 'cliente_retirado' => $l->user_id && (int) $l->cliente_activo !== 1,
                'ultima_actividad_en' => $l->ultima_actividad_en, 'error' => $l->error,
            ];
        });

        return $this->bien('OK', ['conteo' => $conteo, 'total' => $total, 'pagina' => $pagina, 'lineas' => $lista]);
    }

    /** POST api/tv/lineas/sincronizar — trae las líneas del panel ya. */
    public function sincronizarLineas(): JsonResponse
    {
        @set_time_limit(300);

        return $this->operar('Líneas al día con el panel.', fn () => $this->lineas()->traerDelPanel());
    }

    /** PATCH api/tv/lineas/{id}/cliente {user_id|null} */
    public function vincular(int $id, Request $r): JsonResponse
    {
        $d = $r->validate(['user_id' => 'nullable|integer']);

        return $this->operar($d['user_id'] ? 'Línea ligada al cliente.' : 'La línea quedó sin cliente.', fn () => $this->lineas()->vincular($this->linea($id), $d['user_id'] ?? null)->only(['id', 'user_id']));
    }

    /** GET api/tv/clientes?q= — para ligar una línea: los clientes que no tienen. */
    public function buscarClientes(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->bien('OK', []);
        }

        $conLinea = TvLinea::where('company_id', $this->empresa())->whereNotNull('user_id')->where('estado', '!=', 'retirada')->pluck('user_id');

        return $this->bien('OK', DB::table('user_data')->where('company_id', $this->empresa())->where('active', 1)
            ->whereNotIn('user_id', $conLinea)
            ->where(fn ($w) => $w->whereRaw("CONCAT(names,' ',lastname) LIKE ?", ["%{$q}%"])->orWhere('dni', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
            ->orderBy('names')->limit(15)
            ->get(['user_id', DB::raw("TRIM(CONCAT(names,' ',lastname)) as nombre"), 'dni', 'address']));
    }

    // ── La TV de un cliente (ficha) ──────────────────────────────────────────

    /** GET api/tv/cliente/{userId} */
    public function deCliente(int $userId): JsonResponse
    {
        $p = $this->panel();
        if (!$p) {
            return $this->bien('OK', ['conectado' => false]);
        }

        $s = new LineasDeTv($p);
        $linea = TvLinea::where('company_id', $this->empresa())->where('user_id', $userId)
            ->orderByRaw("estado = 'retirada'")->orderByDesc('id')->first();

        return $this->bien('OK', [
            'conectado' => true,
            'plan_con_tv' => $s->tvDelPlan($userId),
            'linea' => $linea ? $this->lineaParaLaFicha($s, $linea) : null,
        ]);
    }

    /** POST api/tv/cliente/{userId}/linea {bouquets?, pantallas?} — crearle la línea a mano. */
    public function crearDeCliente(int $userId, Request $r): JsonResponse
    {
        $d = $r->validate(['bouquets' => 'nullable|array|min:1', 'bouquets.*' => 'integer', 'pantallas' => 'nullable|integer|min:1|max:20']);

        return $this->operar('Línea creada en el panel.', function () use ($userId, $d) {
            $s = $this->lineas();
            if (TvLinea::where('company_id', $this->empresa())->where('user_id', $userId)->whereNotIn('estado', ['retirada', 'pendiente'])->exists()) {
                throw new \DomainException('Este cliente ya tiene línea de TV.');
            }
            $p = $this->panel();
            $tv = !empty($d['bouquets']) ? ['bouquets' => $d['bouquets'], 'pantallas' => (int) ($d['pantallas'] ?? $p->pantallas ?? 2)] : null;

            return $this->lineaParaLaFicha($s, $s->crearPara($userId, $tv));
        });
    }

    /** POST api/tv/lineas/{id}/clave */
    public function cambiarClave(int $id): JsonResponse
    {
        return $this->operar('Clave cambiada. Mándele los datos nuevos al cliente.', fn () => $this->lineaParaLaFicha($s = $this->lineas(), $s->cambiarClave($this->linea($id))));
    }

    /** POST api/tv/lineas/{id}/encender {prender} */
    public function encender(int $id, Request $r): JsonResponse
    {
        $d = $r->validate(['prender' => 'required|boolean']);

        return $this->operar($d['prender'] ? 'Línea activa.' : 'Línea apagada.', fn () => $this->lineaParaLaFicha($s = $this->lineas(), $s->encender($this->linea($id), $d['prender'])));
    }

    /** POST api/tv/lineas/{id}/paquete {bouquets, pantallas} */
    public function paquete(int $id, Request $r): JsonResponse
    {
        $d = $r->validate(['bouquets' => 'required|array|min:1', 'bouquets.*' => 'integer', 'pantallas' => 'required|integer|min:1|max:20']);

        return $this->operar('Paquete actualizado.', fn () => $this->lineaParaLaFicha($s = $this->lineas(), $s->cambiarPaquete($this->linea($id), $d['bouquets'], (int) $d['pantallas'])));
    }

    /** POST api/tv/lineas/{id}/whatsapp {tipo?, salida?} — con el formato de lista elegido en la ficha. */
    public function whatsapp(int $id, Request $r): JsonResponse
    {
        $d = $r->validate([
            'tipo' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(LineasDeTv::FORMATOS))],
            'salida' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(LineasDeTv::SALIDAS))],
        ]);

        try {
            $tel = $this->lineas()->enviarPorWhatsApp($this->linea($id), $d['tipo'] ?? 'm3u_plus', $d['salida'] ?? 'ts');

            return $this->bien("Datos enviados por WhatsApp al {$tel}.");
        } catch (\DomainException|\RuntimeException $e) {
            return $this->mal($e->getMessage());
        }
    }

    private function linea(int $id): TvLinea
    {
        return TvLinea::where('company_id', $this->empresa())->findOrFail($id);
    }

    private function nombresDeBouquets(): array
    {
        $p = $this->panel();
        if (!$p) {
            return [];
        }
        try {
            return \Illuminate\Support\Facades\Cache::remember("tv:bouquets:{$p->company_id}", 600, fn () => collect((new PanelXui($p))->bouquets())->pluck('nombre', 'id')->all());
        } catch (\Throwable) {
            return [];
        }
    }

    private function lineaParaLaFicha(LineasDeTv $s, TvLinea $l): array
    {
        $bouquets = $this->nombresDeBouquets();

        return [
            'id' => $l->id, 'usuario' => $l->usuario, 'clave' => $l->clave, 'estado' => $l->estado, 'origen' => $l->origen, 'vinculo' => $l->vinculo,
            'pantallas' => (int) $l->pantallas, 'bouquets' => $l->bouquets ?: [],
            'paquete' => collect($l->bouquets ?: [])->map(fn ($id) => $bouquets[$id] ?? "#{$id}")->implode(' + ') ?: '—',
            'error' => $l->error, 'ultima_actividad_en' => $l->ultima_actividad_en, 'creada_en' => $l->created_at,
            'en_el_panel' => (bool) $l->xui_id,
            'formatos' => collect(LineasDeTv::FORMATOS)->map(fn ($f, $k) => ['clave' => $k, 'nombre' => $f[0], 'para' => $f[1]])->values(),
            'salidas' => collect(LineasDeTv::SALIDAS)->map(fn ($n, $k) => ['clave' => $k, 'nombre' => $n])->values(),
        ] + $s->urls($l);
    }

    // ── Previas y en vivo (enlaces firmados, sin sesión) ─────────────────────

    /** GET api/tv/previa/{empresa}/{stream} — la última foto del canal. */
    public function previa(int $empresa, int $stream)
    {
        $f = PreviasDeCanales::archivo($empresa, $stream);
        abort_unless(is_file($f), 404);

        return response()->file($f, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=600']);
    }

    /** GET api/tv/vivo/{empresa}/{stream} — la lista HLS del canal, con los trozos por la plataforma. */
    public function vivo(int $empresa, int $stream)
    {
        $p = TvPanel::deLaEmpresa($empresa);
        abort_unless($p && TvCanal::where('company_id', $empresa)->where('stream_id', $stream)->exists(), 404);

        try {
            $lista = (new PreviasDeCanales($p))->listaEnVivo($stream, fn (string $url) => URL::temporarySignedRoute(
                'tv.trozo', now()->addMinutes(3), ['empresa' => $empresa, 'u' => rtrim(strtr(base64_encode($url), '+/', '-_'), '=')], false
            ));
        } catch (\Throwable $e) {
            Log::info('[TV] No se pudo abrir el canal en vivo', ['stream' => $stream, 'error' => $e->getMessage()]);
            abort(502, 'El panel no entregó el canal.');
        }

        return response($lista, 200, ['Content-Type' => 'application/vnd.apple.mpegurl', 'Cache-Control' => 'no-store']);
    }

    /** GET api/tv/trozo/{empresa}?u= — un trozo de video del panel, pasado tal cual. */
    public function trozo(int $empresa, Request $r)
    {
        $p = TvPanel::deLaEmpresa($empresa);
        $url = (string) base64_decode(strtr((string) $r->query('u'), '-_', '+/'));
        abort_unless($p && (new PreviasDeCanales($p))->esDelPanel($url), 404);

        $origen = Http::withOptions(['stream' => true])->timeout(25)->get($url);
        abort_unless($origen->successful(), 502);
        $cuerpo = $origen->toPsrResponse()->getBody();

        return response()->stream(function () use ($cuerpo) {
            while (!$cuerpo->eof()) {
                echo $cuerpo->read(65536);
                flush();
            }
        }, 200, ['Content-Type' => 'video/mp2t', 'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no']);
    }
}
