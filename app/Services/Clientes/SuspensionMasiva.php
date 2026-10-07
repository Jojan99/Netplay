<?php

namespace App\Services\Clientes;

use App\Models\Company;
use App\Models\WaTemplateBinding;
use App\Services\AutoSuspendService;
use App\Services\WhatsApp\CampaignService;
use App\Services\WhatsApp\ClientAudience;
use App\Services\WhatsApp\ReminderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Suspensión masiva por grupo de corte.
 *
 * El operador ve a los clientes con facturas vencidas de un corte —con el detalle de lo que
 * debe cada uno—, quita a los que quiera y ordena una de tres cosas: avisarles por WhatsApp
 * (API de Meta), suspenderlos, o suspenderlos y avisarles.
 *
 * Nada pasa al abrir la pantalla: la lista es una consulta. La orden queda como un «lote» con
 * el resultado de cada cliente, y se trabaja en un proceso aparte porque suspender habla con
 * el router cliente por cliente y no puede ocupar un proceso de los que atienden la web.
 */
class SuspensionMasiva
{
    public const ACCIONES = ['avisar', 'suspender', 'suspender_y_avisar'];

    public function __construct(private int $companyId) {}

    // ── La lista ─────────────────────────────────────────────────────────────

    /**
     * Los clientes con facturas vencidas, con lo que debe cada uno.
     *
     * @param  array{grupo?:string, servicio?:string, min_facturas?:int, min_dias?:int} $f
     * @return list<array<string,mixed>>
     */
    public function candidatos(array $f): array
    {
        $grupo = (string) ($f['grupo'] ?? 'todos');
        $servicio = (string) ($f['servicio'] ?? 'activo');
        $hoy = now()->toDateString();

        $facturas = DB::table('det_facturations as df')
            ->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
            ->join('user_data as ud', fn ($j) => $j->on('ud.user_id', '=', 'cf.user_id')->where('ud.company_id', $this->companyId))
            ->where('cf.company_id', $this->companyId)
            ->where('ud.active', 1)
            ->where('df.paid', 0)->whereNull('df.anulada_en')
            ->whereRaw('(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0)) > 0')
            // Vencida: su fecha límite ya pasó. La del mes que todavía no vence no cuenta.
            ->whereDate('df.date_facturation', '<', $hoy)
            ->when($grupo !== 'todos' && $grupo !== '', fn ($q) => $q->where('cf.group', (int) $grupo))
            ->when($servicio === 'activo', fn ($q) => $q->where('ud.status_internet_id', 1))
            ->when($servicio === 'suspendido', fn ($q) => $q->where('ud.status_internet_id', 2))
            ->orderBy('df.date_facturation')
            ->get(['cf.user_id', 'cf.group', 'df.id', 'df.number_facture', 'df.date_facturation',
                DB::raw('(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0)) as saldo'), DB::raw('COALESCE(df.price_abone,0) as abonado')])
            ->groupBy('user_id');

        if ($facturas->isEmpty()) {
            return [];
        }

        $clientes = DB::table('user_data as ud')->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->where('ud.company_id', $this->companyId)->whereIn('ud.user_id', $facturas->keys())
            ->get(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.phone', 'ud.address', 'ud.status_internet_id', 'ud.connection_type', 'ud.no_reactivar_auto', DB::raw('ip.plan_name as plan')])
            ->keyBy('user_id');

        $minFacturas = max(1, (int) ($f['min_facturas'] ?? 1));
        $minDias = max(0, (int) ($f['min_dias'] ?? 0));
        $filas = [];

        foreach ($facturas as $userId => $suyas) {
            $c = $clientes[$userId] ?? null;
            $dias = (int) Carbon::parse($suyas->first()->date_facturation)->startOfDay()->diffInDays(now()->startOfDay());

            if (!$c || $suyas->count() < $minFacturas || $dias < $minDias) {
                continue;
            }

            $digitos = preg_replace('/\D/', '', (string) $c->phone);

            $filas[] = [
                'user_id' => (int) $userId,
                'nombre' => trim("{$c->names} {$c->lastname}"),
                'dni' => $c->dni,
                'telefono' => $c->phone,
                'telefono_valido' => (bool) preg_match('/^(57)?3\d{9}$/', $digitos),
                'direccion' => $c->address,
                'plan' => $c->plan,
                'grupo' => (int) $suyas->first()->group,
                'suspendido' => (int) $c->status_internet_id === 2,
                'tipo' => $c->connection_type === 'pppoe' ? 'PPPoE' : 'IP fija',
                'facturas' => $suyas->count(),
                'total' => round((float) $suyas->sum('saldo'), 2),
                'mas_vieja' => substr((string) $suyas->first()->date_facturation, 0, 10),
                'dias_mora' => $dias,
                'detalle' => $suyas->map(fn ($x) => ['id' => (int) $x->id, 'numero' => $x->number_facture, 'fecha' => substr((string) $x->date_facturation, 0, 10),
                    'saldo' => round((float) $x->saldo, 2), 'abonado' => round((float) $x->abonado, 2)])->values()->all(),
            ];
        }

        usort($filas, fn ($a, $b) => [$b['dias_mora'], $a['nombre']] <=> [$a['dias_mora'], $b['nombre']]);

        return $filas;
    }

    /** Lo que la pantalla necesita para armarse: grupos, plantillas con que se puede avisar y el tope de Meta. */
    public function opciones(): array
    {
        $company = Company::find($this->companyId);
        $conMeta = $company && $company->wa_access_token && $company->wa_phone_number_id && $company->wa_business_id;

        return [
            'grupos' => app(ClientAudience::class)->grupos($this->companyId),
            'meta' => (bool) $conMeta,
            'limite' => $conMeta ? app(CampaignService::class)->limiteDiario($this->companyId) : null,
            'avisos' => $conMeta ? array_values(array_filter([$this->plantillaDe('suspension'), $this->plantillaDe('suspension_v2'), $this->plantillaDe('informacion')])) : [],
        ];
    }

    /**
     * La plantilla con que se avisa, con su texto real y si se puede usar hoy.
     *
     * @return array<string,mixed>|null
     */
    private function plantillaDe(string $aviso): ?array
    {
        // «suspension_v2»: la plantilla que la empresa tiene enlazada al evento de suspensión
        // (en Netplay, suspendido_por_mora_v2, con «Consultar facturas» y «Pagar ahora»), como
        // opción aparte de la que crea el sistema.
        $evento = in_array($aviso, ['suspension', 'suspension_v2'], true) ? 'suspension_mora' : 'pago_pendiente';
        $b = WaTemplateBinding::where('company_id', $this->companyId)->where('event', $evento)->first();

        // Candidatas, en orden: la que el sistema crea para cada empresa (su botón abre el dominio
        // de la plataforma) y la que la empresa tenga enlazada a mano. Se usa la primera que sirva.
        $candidatas = [];
        $semilla = \App\Support\PlantillasSemilla::todas()[$evento] ?? null;

        if ($aviso === 'suspension' && $semilla) {
            $candidatas[] = ['nombre' => $semilla['nombre'], 'idioma' => $semilla['idioma'], 'variables' => $semilla['variables']];
        }

        if ($aviso === 'suspension_v2' && (!$b || !$b->template_name || ($semilla && $b->template_name === $semilla['nombre']))) {
            return null;
        }

        if ($b && $b->template_name && $aviso !== 'suspension') {
            $v = is_array($b->params) ? $b->params : (json_decode((string) $b->params, true) ?: []);
            $candidatas[] = ['nombre' => (string) $b->template_name, 'idioma' => $b->language ?: 'es_CO', 'variables' => is_string($v) ? (json_decode($v, true) ?: []) : $v];
        }

        $mejor = null;

        foreach ($candidatas as $c) {
            $r = $this->revisar($aviso, $c);

            if ($r['se_puede']) {
                return $r;
            }

            // Si ninguna sirve, se muestra la que ya existe en Meta (con su motivo), no una que ni se ha creado.
            if ($mejor === null || ($mejor['cuerpo'] === null && $r['cuerpo'] !== null)) {
                $mejor = $r;
            }
        }

        return $mejor;
    }

    /** @param array{nombre:string, idioma:string, variables:array} $c */
    private function revisar(string $aviso, array $c): array
    {
        $enMeta = $this->enMeta($c['nombre']);
        $variables = $c['variables'];
        $b = (object) ['template_name' => $c['nombre'], 'language' => $c['idioma']];
        $problema = null;

        if (!$enMeta) {
            $problema = 'La plantilla no aparece en la cuenta de Meta.';
        } elseif (($enMeta['status'] ?? '') !== 'APPROVED') {
            $problema = 'Meta todavía no la aprueba (estado: ' . ($enMeta['status'] ?? '?') . ').';
        } else {
            foreach ($enMeta['enlaces'] as $url) {
                if (!$this->responde($url)) {
                    $problema = 'El botón de la plantilla abre «' . parse_url($url, PHP_URL_HOST) . '», que no responde: los clientes tocarían un enlace roto.';
                }
            }
        }

        return [
            'id' => $aviso,
            'nombre' => match ($aviso) {
                'suspension' => 'Aviso de suspensión por mora',
                'suspension_v2' => 'Suspendido por mora (' . $c['nombre'] . ')',
                default => 'Información general (texto que usted escribe)',
            },
            'plantilla' => $b->template_name,
            'idioma' => $b->language ?: 'es_CO',
            'variables' => array_values($variables),
            'categoria' => $enMeta['category'] ?? null,
            'cuerpo' => $enMeta['cuerpo'] ?? null,
            'botones' => $enMeta['botones'] ?? [],
            'pide_fecha' => in_array('fecha_vencimiento', $variables, true),
            'pide_texto' => in_array('texto_libre', $variables, true),
            'se_puede' => $problema === null,
            'problema' => $problema,
        ];
    }

    /** La plantilla tal como está en Meta: estado, cuerpo, botones y los enlaces de sus botones. */
    private function enMeta(string $nombre): ?array
    {
        return Cache::store('redis')->remember("meta:plantilla:{$this->companyId}:{$nombre}", 300, function () use ($nombre) {
            $c = Company::find($this->companyId);

            try {
                $r = Http::withToken($c->wa_access_token)->timeout(15)->get("https://graph.facebook.com/v21.0/{$c->wa_business_id}/message_templates", ['name' => $nombre, 'limit' => 20, 'fields' => 'name,language,status,category,components']);
            } catch (\Throwable) {
                return null;
            }

            $t = collect($r->json('data') ?? [])->firstWhere('name', $nombre);

            if (!$t) {
                return null;
            }

            $componentes = collect($t['components'] ?? []);
            $botones = collect($componentes->firstWhere('type', 'BUTTONS')['buttons'] ?? []);

            return [
                'status' => $t['status'] ?? null, 'category' => $t['category'] ?? null,
                'cuerpo' => $componentes->firstWhere('type', 'BODY')['text'] ?? null,
                'botones' => $botones->map(fn ($b) => (string) ($b['text'] ?? ''))->values()->all(),
                'enlaces' => $botones->filter(fn ($b) => ($b['type'] ?? '') === 'URL' && !empty($b['url']))->map(fn ($b) => (string) $b['url'])->values()->all(),
            ];
        });
    }

    /** ¿El sitio del enlace del botón contesta? Un botón que abre un dominio caído no se le manda a nadie. */
    private function responde(string $url): bool
    {
        $base = preg_replace('/\{\{\d+\}\}.*$/', '', $url);
        $raiz = parse_url((string) $base, PHP_URL_SCHEME) . '://' . parse_url((string) $base, PHP_URL_HOST);

        return Cache::store('redis')->remember('enlace:responde:' . md5($raiz), 600, function () use ($raiz) {
            try {
                return Http::timeout(8)->withoutRedirecting()->get($raiz)->status() < 500;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    // ── La orden ─────────────────────────────────────────────────────────────

    /**
     * Deja la orden lista y arranca el proceso que la trabaja.
     *
     * @param  list<int> $userIds
     * @param  array{aviso?:?string, texto?:?string, fecha_limite?:?string, motivo?:?string, grupo?:?string} $o
     * @return int el id del lote
     */
    public function crear(string $accion, array $userIds, array $o, ?int $creadoPor): int
    {
        if (!in_array($accion, self::ACCIONES, true)) {
            throw new \DomainException('Acción no válida.');
        }

        // Sólo se toma lo que hoy sigue siendo candidato: la lista de la pantalla pudo quedar vieja
        // (alguien pagó mientras tanto) y a ése ni se le suspende ni se le escribe.
        $vigentes = collect($this->candidatos(['grupo' => 'todos', 'servicio' => $accion === 'avisar' ? 'todos' : 'activo']))->keyBy('user_id');
        $elegidos = collect(array_unique(array_map('intval', $userIds)))->filter(fn ($id) => isset($vigentes[$id]))->values();

        if ($elegidos->isEmpty()) {
            throw new \DomainException('Ninguno de los clientes elegidos tiene hoy una factura vencida' . ($accion === 'avisar' ? '.' : ' y servicio activo.'));
        }

        if (DB::table('suspension_lotes')->where('company_id', $this->companyId)->whereIn('estado', ['pendiente', 'en_curso'])->exists()) {
            throw new \DomainException('Ya hay una orden en curso. Espere a que termine o cancélela.');
        }

        $avisa = $accion !== 'suspender';
        $p = null;

        if ($avisa) {
            $p = $this->plantillaDe((string) ($o['aviso'] ?? ''));

            if (!$p) {
                throw new \DomainException('Elija con qué plantilla se les avisa.');
            }
            if (!$p['se_puede']) {
                throw new \DomainException('Esa plantilla no se puede usar: ' . $p['problema']);
            }
            if ($p['pide_texto'] && trim((string) ($o['texto'] ?? '')) === '') {
                throw new \DomainException('Escriba el mensaje que va en la plantilla.');
            }
            if ($p['pide_fecha'] && trim((string) ($o['fecha_limite'] ?? '')) === '') {
                throw new \DomainException('Indique la fecha límite de pago que va en el mensaje.');
            }

            $conTelefono = $elegidos->filter(fn ($id) => $vigentes[$id]['telefono_valido'])->count();
            $limite = app(CampaignService::class)->limiteDiario($this->companyId);

            if ($limite['maximo'] !== null && $conTelefono > $limite['maximo']) {
                throw new \DomainException("Son {$conTelefono} avisos y su número de WhatsApp puede iniciar conversación con {$limite['maximo']} clientes distintos cada 24 horas. Meta rechazaría el resto: quite clientes de la lista o avise en dos días.");
            }
        }

        $loteId = DB::transaction(function () use ($accion, $elegidos, $vigentes, $o, $creadoPor, $avisa, $p) {
            $id = DB::table('suspension_lotes')->insertGetId([
                'company_id' => $this->companyId, 'creado_por' => $creadoPor, 'accion' => $accion, 'grupo' => $o['grupo'] ?? null,
                'aviso' => $avisa ? $p['id'] : null, 'plantilla' => $avisa ? $p['plantilla'] : null, 'idioma' => $avisa ? $p['idioma'] : null,
                'variables' => $avisa ? json_encode($p['variables']) : null,
                'texto' => $avisa && $p['pide_texto'] ? mb_substr(trim((string) $o['texto']), 0, 900) : null,
                'fecha_limite' => $avisa ? (trim((string) ($o['fecha_limite'] ?? '')) ?: null) : null,
                'motivo' => mb_substr(trim((string) ($o['motivo'] ?? '')), 0, 250) ?: null,
                'total' => $elegidos->count(), 'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('suspension_lote_clientes')->insert($elegidos->map(fn ($uid) => [
                'lote_id' => $id, 'user_id' => $uid, 'nombre' => mb_substr($vigentes[$uid]['nombre'], 0, 160), 'deuda' => $vigentes[$uid]['total'], 'facturas' => $vigentes[$uid]['facturas'],
                'suspension' => $accion === 'avisar' ? null : 'pendiente', 'aviso' => $avisa ? 'pendiente' : null, 'created_at' => now(), 'updated_at' => now(),
            ])->all());

            return $id;
        });

        self::lanzar($loteId);

        return $loteId;
    }

    public function cancelar(int $loteId): bool
    {
        return (bool) DB::table('suspension_lotes')->where('company_id', $this->companyId)->where('id', $loteId)->whereIn('estado', ['pendiente', 'en_curso'])
            ->update(['estado' => 'cancelado', 'detalle' => 'Cancelada por el operador. Lo ya hecho no se deshace.', 'terminado_en' => now(), 'updated_at' => now()]);
    }

    /** @return array<string,mixed>|null */
    public function ver(int $loteId): ?array
    {
        $l = DB::table('suspension_lotes')->where('company_id', $this->companyId)->where('id', $loteId)->first();

        if (!$l) {
            return null;
        }

        $clientes = DB::table('suspension_lote_clientes')->where('lote_id', $loteId)->orderBy('nombre')->get(['user_id', 'nombre', 'deuda', 'facturas', 'suspension', 'aviso', 'error']);
        $hechos = $clientes->filter(fn ($c) => !in_array('pendiente', [$c->suspension, $c->aviso], true))->count();

        return (array) $l + ['clientes' => $clientes->all(), 'procesados' => $hechos,
            'quien' => $l->creado_por ? trim((string) DB::table('user_data')->where('user_id', $l->creado_por)->selectRaw("CONCAT(names, ' ', lastname) n")->value('n')) : null];
    }

    /** @return list<object> */
    public function historial(): array
    {
        return DB::table('suspension_lotes')->where('company_id', $this->companyId)->orderByDesc('id')->limit(30)
            ->get(['id', 'accion', 'grupo', 'aviso', 'plantilla', 'total', 'suspendidos', 'avisados', 'fallidos', 'estado', 'detalle', 'created_at', 'terminado_en'])->all();
    }

    // ── El trabajo (corre en un proceso aparte) ──────────────────────────────

    /** En las pruebas no se lanzan procesos. */
    public static bool $lanzarProcesos = true;

    public static function lanzar(int $loteId): void
    {
        if (!self::$lanzarProcesos) {
            return;
        }

        $php = (new PhpExecutableFinder())->find() ?: 'php';

        exec(sprintf('nohup %s %s clientes:suspender-lote %d >> %s 2>&1 &', escapeshellarg($php), escapeshellarg(base_path('artisan')), $loteId, escapeshellarg(storage_path('logs/suspension-masiva.log'))));
    }

    public static function procesar(int $loteId): void
    {
        $l = DB::table('suspension_lotes')->where('id', $loteId)->first();

        if (!$l || $l->estado !== 'pendiente') {
            return;
        }

        DB::table('suspension_lotes')->where('id', $loteId)->update(['estado' => 'en_curso', 'iniciado_en' => now(), 'updated_at' => now()]);
        $yo = new self((int) $l->company_id);
        $detalle = null;

        try {
            if ($l->accion !== 'avisar') {
                $detalle = $yo->suspender($l);
            }

            if ($l->accion !== 'suspender' && $yo->sigue($loteId)) {
                $yo->avisar($l);
            }
        } catch (\Throwable $e) {
            Log::error('[Suspensión masiva] Falló el lote', ['lote' => $loteId, 'error' => $e->getMessage()]);
            $detalle = 'Se interrumpió por un error: ' . mb_substr($e->getMessage(), 0, 200);
        }

        $yo->contar($loteId);

        if ($yo->sigue($loteId)) {
            DB::table('suspension_lotes')->where('id', $loteId)->update(['estado' => 'terminado', 'detalle' => $detalle, 'terminado_en' => now(), 'updated_at' => now()]);
        }
    }

    private function sigue(int $loteId): bool
    {
        return DB::table('suspension_lotes')->where('id', $loteId)->value('estado') === 'en_curso';
    }

    private function contar(int $loteId): void
    {
        $t = DB::table('suspension_lote_clientes')->where('lote_id', $loteId);

        DB::table('suspension_lotes')->where('id', $loteId)->update([
            'suspendidos' => (clone $t)->where('suspension', 'hecho')->count(),
            'avisados' => (clone $t)->where('aviso', 'enviado')->count(),
            'fallidos' => (clone $t)->where(fn ($q) => $q->whereIn('suspension', ['no_aplicado', 'error'])->orWhere('aviso', 'fallido'))->count(),
            'updated_at' => now(),
        ]);
    }

    /** Suspende de a pocos, para que el avance se vea y se pueda cancelar a mitad. */
    private function suspender(object $l): ?string
    {
        $servicio = app(AutoSuspendService::class);
        $texto = 'Suspensión masiva #' . $l->id . ($l->grupo && $l->grupo !== 'todos' ? ' (grupo ' . $l->grupo . ')' : '') . ($l->motivo ? ': ' . $l->motivo : ' por mora');

        while ($this->sigue((int) $l->id)) {
            $tanda = DB::table('suspension_lote_clientes')->where('lote_id', $l->id)->where('suspension', 'pendiente')->orderBy('id')->limit(12)->pluck('user_id')->map(fn ($i) => (int) $i)->all();

            if (!$tanda) {
                return null;
            }

            $r = $servicio->suspenderSeleccion($this->companyId, $tanda, $texto, $l->creado_por ? (int) $l->creado_por : null);
            $q = fn (array $ids) => DB::table('suspension_lote_clientes')->where('lote_id', $l->id)->whereIn('user_id', $ids ?: [0]);

            if ($r['router_caido']) {
                $q($tanda)->update(['suspension' => 'error', 'aviso' => DB::raw("IF(aviso = 'pendiente', NULL, aviso)"), 'error' => 'No hubo conexión con el router: no se suspendió.', 'updated_at' => now()]);
                // Sin router no tiene sentido seguir intentando con los demás.
                DB::table('suspension_lote_clientes')->where('lote_id', $l->id)->where('suspension', 'pendiente')
                    ->update(['suspension' => 'error', 'aviso' => DB::raw("IF(aviso = 'pendiente', NULL, aviso)"), 'error' => 'No hubo conexión con el router: no se suspendió.', 'updated_at' => now()]);
                $this->contar((int) $l->id);

                return 'El router no respondió: no se suspendió a nadie más. Los que ya estaban suspendidos, quedaron suspendidos.';
            }

            $q($r['suspendidos'])->update(['suspension' => 'hecho', 'updated_at' => now()]);
            // Al que no se pudo suspender tampoco se le avisa que quedó suspendido.
            $q(array_values(array_diff($tanda, $r['suspendidos'])))->update(['suspension' => 'no_aplicado', 'aviso' => DB::raw("IF(aviso = 'pendiente', NULL, aviso)"),
                'error' => 'El router no lo aplicó (no se encontró su IP o su usuario PPPoE, o ya no tenía servicio activo).', 'updated_at' => now()]);
            $this->contar((int) $l->id);
        }

        return null;
    }

    private function avisar(object $l): void
    {
        $company = Company::find($this->companyId);
        $avisos = app(ReminderService::class);
        $variables = json_decode((string) $l->variables, true) ?: [];
        $n = 0;

        foreach (DB::table('suspension_lote_clientes')->where('lote_id', $l->id)->where('aviso', 'pendiente')->orderBy('id')->pluck('user_id') as $userId) {
            if ($n++ % 10 === 0 && !$this->sigue((int) $l->id)) {
                return;
            }

            $cliente = DB::table('user_data as ud')->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
                ->where('ud.company_id', $this->companyId)->where('ud.user_id', $userId)->first(['ud.*', DB::raw('ip.plan_name as plan')]);
            $vieja = DB::table('det_facturations as df')->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
                ->where('cf.company_id', $this->companyId)->where('cf.user_id', $userId)->where('df.paid', 0)->whereNull('df.anulada_en')
                ->whereRaw('(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0)) > 0')
                ->orderBy('df.date_facturation')->first(['df.id', 'df.number_facture', 'df.date_facturation']);
            $fila = DB::table('suspension_lote_clientes')->where('lote_id', $l->id)->where('user_id', $userId);

            // Pagó mientras tanto: no se le escribe por una deuda que ya no tiene.
            if (!$cliente || !$vieja) {
                $fila->update(['aviso' => 'fallido', 'error' => 'Ya no tiene facturas pendientes: no se le escribió.', 'updated_at' => now()]);
                continue;
            }

            $emision = Carbon::parse($vieja->date_facturation)->startOfDay();
            $extra = array_filter([
                'invoice_id' => (int) $vieja->id,
                'factura' => (string) $vieja->number_facture,
                'fecha_emision' => $emision->format('d/m/Y'),
                // La fecha de vencimiento de la FACTURA y la fecha límite del aviso son dos cosas: cada
                // plantilla usa la suya.
                'fecha_vence' => $emision->format('d/m/Y'),
                'fecha_vencimiento' => $l->fecha_limite ?: $emision->format('d/m/Y'),
                'dias_mora' => (string) max(0, (int) $emision->diffInDays(now()->startOfDay(), false)),
                'texto_libre' => $l->texto,
            ], fn ($v) => $v !== null && $v !== '');

            $r = $avisos->enviarPlantilla($company, (string) $l->plantilla, (string) ($l->idioma ?: 'es_CO'), $variables, $cliente, $extra, $l->accion === 'avisar' ? 'aviso_suspension' : 'suspension_mora');
            $fila->update(['aviso' => $r['ok'] ? 'enviado' : 'fallido', 'error' => $r['ok'] ? DB::raw('error') : $r['error'], 'updated_at' => now()]);

            if ($n % 10 === 0) {
                $this->contar((int) $l->id);
            }

            // Sin apuro: 4 por segundo está muy por debajo de lo que Meta admite.
            usleep(250000);
        }
    }
}
