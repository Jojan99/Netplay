<?php

namespace App\Http\Controllers;

use App\Services\Alegra\EspejoDeAlegra;
use App\Services\Alegra\OperacionesDeAlegra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * El módulo de Alegra: lo que la empresa tiene allá, cruzado con Netvula.
 *
 * Todo es de consulta. Lo que se muestra sale de la copia local (alegra_*), que refresca el
 * comando `alegra:sincronizar`; aquí no se crea ni se cambia nada en Alegra.
 */
class AlegraController extends Controller
{
    private const POR_PAGINA = 25;

    /**
     * Cómo queda cada factura de Alegra frente a su cuenta de cobro de Netvula.
     * `f` = alegra_facturas, `d` = det_facturations.
     */
    private const CRUCE = "CASE
        WHEN f.user_id IS NULL THEN 'sin_cliente'
        WHEN f.det_facturation_id IS NULL THEN 'sin_cuenta'
        WHEN d.anulada_en IS NOT NULL AND f.estado = 'open' THEN 'anulada_en_netvula'
        WHEN f.estado = 'open' AND d.paid = 1 THEN 'pagada_en_netvula'
        WHEN f.estado = 'closed' AND d.paid = 0 AND d.anulada_en IS NULL THEN 'pagada_en_alegra'
        WHEN f.estado = 'open' THEN 'pendiente_en_ambas'
        ELSE 'pagada_en_ambas' END";

    private function empresa(): int
    {
        return (int) getSessionCompanyId();
    }

    /** La plata la ven el administrador y el contador. */
    private function puedeVer(): bool
    {
        $perfil = strtoupper((string) DB::table('profiles')->where('id', getSessionUserProfileId())->value('name'));

        return in_array($perfil, ['ADMIN', 'CONTADOR'], true);
    }

    private function responder(string $mensaje, mixed $data = null, int $error = 0, int $http = 200): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $data, 'error' => $error], $http);
    }

    private function negado(): JsonResponse
    {
        return $this->responder('Sólo el administrador y el contador pueden ver el módulo de Alegra.', null, 1, 403);
    }

    private function pagina(Request $r): int
    {
        return max(1, (int) $r->query('pagina', 1));
    }

    private function facturas()
    {
        return DB::table('alegra_facturas as f')
            ->leftJoin('det_facturations as d', 'd.id', '=', 'f.det_facturation_id')
            ->where('f.company_id', $this->empresa());
    }

    // ── Estado y sincronización ──────────────────────────────────────────────

    /** GET api/alegra */
    public function estado(): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $espejo = new EspejoDeAlegra($this->empresa());

        return $this->responder('OK', ['conectado' => $espejo->conectado(), 'sincronizacion' => $espejo->estado()]);
    }

    /** POST api/alegra/sincronizar — en segundo plano: son unas 65 consultas a Alegra. */
    public function sincronizar(): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $espejo = new EspejoDeAlegra($this->empresa());

        if (!$espejo->conectado()) {
            return $this->responder('Alegra no está conectado: cargue el correo y el token en Factura electrónica → Conexión.', null, 1);
        }

        if (($espejo->estado()['estado'] ?? '') !== 'en_curso') {
            $php = (new PhpExecutableFinder())->find() ?: 'php';

            exec(sprintf('nohup %s %s alegra:sincronizar %d >> %s 2>&1 &',
                escapeshellarg($php), escapeshellarg(base_path('artisan')), $this->empresa(), escapeshellarg(storage_path('logs/alegra.log'))));
        }

        return $this->responder('Trayendo los datos de Alegra…', ['sincronizacion' => ['estado' => 'en_curso'] + $espejo->estado()]);
    }

    // ── Resumen ──────────────────────────────────────────────────────────────

    /** GET api/alegra/resumen */
    public function resumen(): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $empresa = $this->empresa();
        $hoy = now()->toDateString();
        $mes = now()->format('Y-m');

        $t = DB::table('alegra_facturas')->where('company_id', $empresa)->selectRaw("
            COUNT(*) facturas,
            SUM(estado = 'open') abiertas,
            SUM(CASE WHEN estado = 'open' THEN saldo ELSE 0 END) por_cobrar,
            SUM(estado = 'open' AND vence < ?) vencidas,
            SUM(CASE WHEN estado = 'open' AND vence < ? THEN saldo ELSE 0 END) vencido,
            SUM(CASE WHEN DATE_FORMAT(fecha, '%Y-%m') = ? THEN total ELSE 0 END) facturado_mes,
            SUM(DATE_FORMAT(fecha, '%Y-%m') = ?) facturas_mes,
            SUM(estado = 'draft') borradores", [$hoy, $hoy, $mes, $mes])->first();

        $cruce = $this->facturas()->selectRaw(self::CRUCE . ' as cruce, COUNT(*) n, SUM(f.saldo) saldo, SUM(f.total) total')
            ->groupBy('cruce')->get()->keyBy('cruce');

        $meses = DB::table('alegra_facturas')->where('company_id', $empresa)->whereNotNull('fecha')
            ->selectRaw("DATE_FORMAT(fecha, '%Y-%m') mes, COUNT(*) n, SUM(total) total, SUM(CASE WHEN estado = 'open' THEN saldo ELSE 0 END) saldo")
            ->groupBy('mes')->orderByDesc('mes')->limit(12)->get()->reverse()->values();

        return $this->responder('OK', [
            'totales' => $t,
            'cruce'   => $cruce,
            'meses'   => $meses,
            'dian'    => DB::table('alegra_facturas')->where('company_id', $empresa)->selectRaw("COALESCE(estado_dian, 'SIN_SELLO') sello, COUNT(*) n")->groupBy('sello')->pluck('n', 'sello'),
            'pagos_mes' => DB::table('alegra_pagos')->where('company_id', $empresa)->where('tipo', 'in')->whereRaw("DATE_FORMAT(fecha, '%Y-%m') = ?", [$mes])->selectRaw('COUNT(*) n, COALESCE(SUM(monto), 0) total')->first(),
            'ultimo_pago' => DB::table('alegra_pagos')->where('company_id', $empresa)->where('tipo', 'in')->max('fecha'),
            'contactos' => [
                'total' => DB::table('alegra_contactos')->where('company_id', $empresa)->count(),
                'sin_netvula' => DB::table('alegra_contactos')->where('company_id', $empresa)->whereNull('user_id')->count(),
                'netvula_sin_alegra' => $this->clientesSinContacto()->count(),
            ],
            'por_facturar' => (clone $this->porFacturarQuery(now()->subDays(45)->toDateString()))->selectRaw('COUNT(*) n, COALESCE(SUM(d.price_total - COALESCE(d.price_discount, 0)), 0) total')->first(),
        ]);
    }

    // ── Facturas de Alegra ───────────────────────────────────────────────────

    /** GET api/alegra/facturas?estado=&cruce=&q=&desde=&hasta=&vencidas=1&pagina= */
    public function listarFacturas(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $q = $this->facturas()
            ->leftJoin('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'f.user_id')->where('u.company_id', $this->empresa()))
            ->when($r->query('estado'), fn ($x, $e) => $x->where('f.estado', $e))
            ->when($r->query('cruce'), fn ($x, $c) => $x->whereRaw(self::CRUCE . ' = ?', [$c]))
            ->when($r->boolean('vencidas'), fn ($x) => $x->where('f.estado', 'open')->where('f.vence', '<', now()->toDateString()))
            ->when($r->query('desde'), fn ($x, $d) => $x->where('f.fecha', '>=', $d))
            ->when($r->query('hasta'), fn ($x, $h) => $x->where('f.fecha', '<=', $h))
            ->when(trim((string) $r->query('q')), fn ($x, $t) => $x->where(fn ($y) => $y
                ->where('f.numero', 'like', "%{$t}%")->orWhere('f.cliente_nombre', 'like', "%{$t}%")
                ->orWhere('f.cliente_identificacion', 'like', "%{$t}%")->orWhere('d.number_facture', 'like', "%{$t}%")));

        $suma  = (clone $q)->selectRaw('COUNT(*) n, COALESCE(SUM(f.total), 0) total, COALESCE(SUM(f.saldo), 0) saldo')->first();
        $filas = $q->orderByDesc('f.fecha')->orderByDesc('f.id')->forPage($this->pagina($r), self::POR_PAGINA)->get([
            'f.alegra_id', 'f.numero', 'f.fecha', 'f.vence', 'f.estado', 'f.cliente_nombre', 'f.cliente_identificacion',
            'f.total', 'f.pagado', 'f.saldo', 'f.estado_dian', 'f.concepto', 'f.user_id',
            'd.id as det_id', 'd.number_facture', 'd.paid', 'd.paid_at', 'd.anulada_en', 'd.price_total as netvula_total', 'd.price_discount as netvula_descuento',
            DB::raw("TRIM(CONCAT(COALESCE(u.names,''), ' ', COALESCE(u.lastname,''))) as cliente_netvula"),
            DB::raw(self::CRUCE . ' as cruce'),
        ]);

        return $this->responder('OK', ['total' => (int) $suma->n, 'suma_total' => (float) $suma->total, 'suma_saldo' => (float) $suma->saldo, 'por_pagina' => self::POR_PAGINA, 'facturas' => $filas]);
    }

    // ── Por facturar: lo que Netvula cobró y Alegra no tiene ─────────────────

    private function porFacturarQuery(string $desde)
    {
        $empresa = $this->empresa();

        return DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->leftJoin('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'c.user_id')->where('u.company_id', $empresa))
            ->where('c.company_id', $empresa)
            ->whereNull('d.anulada_en')
            ->where('d.date_facturation', '>=', $desde)
            ->whereRaw('(d.price_total - COALESCE(d.price_discount, 0)) > 0')
            ->whereNotExists(fn ($x) => $x->from('alegra_facturas as f')->whereColumn('f.det_facturation_id', 'd.id'));
    }

    /** GET api/alegra/por-facturar?desde=&fecha=&pagadas=si|no&marcados=1&q=&pagina= */
    public function porFacturar(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('desde')) ? (string) $r->query('desde') : now()->subDays(45)->toDateString();

        $q = $this->porFacturarQuery($desde)
            ->when($r->query('fecha'), fn ($x, $f) => $x->whereDate('d.date_facturation', $f))
            ->when($r->query('pagadas') === 'si', fn ($x) => $x->where('d.paid', 1))
            ->when($r->query('pagadas') === 'no', fn ($x) => $x->where('d.paid', 0))
            ->when($r->boolean('marcados'), fn ($x) => $x->where('c.billing_electronic', 1))
            ->when(trim((string) $r->query('q')), fn ($x, $t) => \App\Support\BusquedaPorPalabras::aplicar($x, $t, ['d.number_facture', 'u.dni', 'u.names', 'u.lastname'], ['u.dni']));

        $suma = (clone $q)->selectRaw('COUNT(*) n, COALESCE(SUM(d.price_total - COALESCE(d.price_discount, 0)), 0) total')->first();

        // Cuántas hay por cada emisión, para elegir el corte sin adivinar la fecha.
        $cortes = $this->porFacturarQuery($desde)->selectRaw('DATE(d.date_facturation) fecha, COUNT(*) n, SUM(d.paid) pagadas')
            ->groupBy('fecha')->orderByDesc('fecha')->limit(12)->get();

        $filas = $q->orderByDesc('d.date_facturation')->orderBy('u.names')->forPage($this->pagina($r), self::POR_PAGINA)->get([
            'd.id', 'd.number_facture', 'd.date_facturation', 'd.paid', 'd.paid_at', 'c.user_id', 'c.billing_electronic', 'u.dni',
            DB::raw('(d.price_total - COALESCE(d.price_discount, 0)) as total'),
            DB::raw("TRIM(CONCAT(COALESCE(u.names,''), ' ', COALESCE(u.lastname,''))) as cliente"),
            DB::raw("EXISTS (SELECT 1 FROM alegra_contactos k WHERE k.company_id = c.company_id AND k.user_id = c.user_id) as en_alegra"),
        ]);

        return $this->responder('OK', ['total' => (int) $suma->n, 'suma_total' => (float) $suma->total, 'por_pagina' => self::POR_PAGINA, 'desde' => $desde, 'cortes' => $cortes, 'cuentas' => $filas]);
    }

    // ── Pagos y contactos ────────────────────────────────────────────────────

    /** GET api/alegra/pagos?q=&desde=&hasta=&pagina= */
    public function pagos(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $q = DB::table('alegra_pagos')->where('company_id', $this->empresa())
            ->when($r->query('tipo'), fn ($x, $t) => $x->where('tipo', $t))
            ->when($r->query('desde'), fn ($x, $d) => $x->where('fecha', '>=', $d))
            ->when($r->query('hasta'), fn ($x, $h) => $x->where('fecha', '<=', $h))
            ->when(trim((string) $r->query('q')), fn ($x, $t) => $x->where(fn ($y) => $y
                ->where('cliente_nombre', 'like', "%{$t}%")->orWhere('cliente_identificacion', 'like', "%{$t}%")->orWhere('numero', 'like', "%{$t}%")));

        $suma  = (clone $q)->selectRaw('COUNT(*) n, COALESCE(SUM(monto), 0) total')->first();
        $filas = $q->orderByDesc('fecha')->orderByDesc('id')->forPage($this->pagina($r), self::POR_PAGINA)
            ->get(['alegra_id', 'numero', 'fecha', 'monto', 'tipo', 'metodo', 'banco', 'estado', 'cliente_nombre', 'cliente_identificacion', 'facturas'])
            ->map(function ($p) { $p->facturas = json_decode((string) $p->facturas, true) ?: []; return $p; });

        return $this->responder('OK', ['total' => (int) $suma->n, 'suma_total' => (float) $suma->total, 'por_pagina' => self::POR_PAGINA, 'pagos' => $filas]);
    }

    /** Clientes vigentes de Netvula que no tienen contacto en Alegra. */
    private function clientesSinContacto()
    {
        $empresa = $this->empresa();

        return DB::table('user_data as u')->join('users as us', 'us.id', '=', 'u.user_id')
            ->where('us.company_id', $empresa)->where('u.active', 1)
            ->whereNotIn('us.profile_id', fn ($q) => $q->select('id')->from('profiles')->where('company_id', $empresa)->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']))
            ->whereNotExists(fn ($x) => $x->from('alegra_contactos as k')->where('k.company_id', $empresa)->whereColumn('k.user_id', 'u.user_id'));
    }

    /** GET api/alegra/contactos?vinculo=sin_netvula|netvula_sin_alegra&q=&pagina= */
    public function contactos(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $t = trim((string) $r->query('q'));

        if ($r->query('vinculo') === 'netvula_sin_alegra') {
            $q = $this->clientesSinContacto()->when($t, fn ($x) => \App\Support\BusquedaPorPalabras::aplicar($x, $t, ['u.dni', 'u.names', 'u.lastname'], ['u.dni']));
            $total = (clone $q)->count();
            $filas = $q->orderBy('u.names')->forPage($this->pagina($r), self::POR_PAGINA)->get([
                DB::raw('NULL as alegra_id'), 'u.user_id', 'u.dni as identificacion', 'u.email', 'u.phone as telefono', DB::raw("'solo_netvula' as estado"),
                DB::raw("(SELECT o.estado FROM alegra_operaciones o WHERE o.company_id = us.company_id AND o.referencia = CONCAT('contacto:', u.user_id) AND o.estado IN ('propuesta','aprobada','aplicando') LIMIT 1) as pedido"),
                DB::raw("TRIM(CONCAT(COALESCE(u.names,''), ' ', COALESCE(u.lastname,''))) as nombre"), DB::raw('0 as facturas'), DB::raw('0 as saldo'),
            ]);

            return $this->responder('OK', ['total' => $total, 'por_pagina' => self::POR_PAGINA, 'contactos' => $filas]);
        }

        $empresa = $this->empresa();
        $q = DB::table('alegra_contactos as k')->where('k.company_id', $empresa)
            ->when($r->query('vinculo') === 'sin_netvula', fn ($x) => $x->whereNull('k.user_id'))
            ->when($t, fn ($x) => $x->where(fn ($y) => $y->where('k.nombre', 'like', "%{$t}%")->orWhere('k.identificacion', 'like', "%{$t}%")));

        $total = (clone $q)->count();
        $filas = $q->orderBy('k.nombre')->forPage($this->pagina($r), self::POR_PAGINA)->get([
            'k.alegra_id', 'k.user_id', 'k.nombre', 'k.identificacion', 'k.email', 'k.telefono', 'k.estado',
            DB::raw("(SELECT COUNT(*) FROM alegra_facturas f WHERE f.company_id = k.company_id AND f.cliente_alegra_id = k.alegra_id AND f.estado = 'open') as facturas"),
            DB::raw("(SELECT COALESCE(SUM(f.saldo), 0) FROM alegra_facturas f WHERE f.company_id = k.company_id AND f.cliente_alegra_id = k.alegra_id AND f.estado = 'open') as saldo"),
        ]);

        return $this->responder('OK', ['total' => $total, 'por_pagina' => self::POR_PAGINA, 'contactos' => $filas]);
    }

    // ── Facturas recurrentes: a quién le factura Alegra sola cada mes ────────

    /** Cómo está en Netvula el cliente de cada recurrente. `r` = alegra_recurrentes, `u` = user_data, `p` = internet_plans. */
    private const ALERTA = "CASE
        WHEN r.user_id IS NULL THEN 'no_cliente'
        WHEN u.active = 0 THEN 'retirado'
        WHEN u.status_internet_id = 2 THEN 'suspendido'
        WHEN ABS(r.total - COALESCE(p.monthly_price, r.total)) >= 1 THEN 'valor_distinto'
        ELSE 'ok' END";

    private function recurrentesQuery()
    {
        $empresa = $this->empresa();

        return DB::table('alegra_recurrentes as r')
            ->leftJoin('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'r.user_id')->where('u.company_id', $empresa))
            ->leftJoin('internet_plans as p', 'p.id', '=', 'u.internet_plans_id')
            ->where('r.company_id', $empresa);
    }

    /** Clientes vigentes de Netvula a los que Alegra no les genera factura. */
    private function clientesSinRecurrente()
    {
        $empresa = $this->empresa();

        return DB::table('user_data as u')->join('users as us', 'us.id', '=', 'u.user_id')
            ->leftJoin('internet_plans as p', 'p.id', '=', 'u.internet_plans_id')
            ->leftJoin('cab_facturations as c', fn ($j) => $j->on('c.user_id', '=', 'u.user_id')->where('c.company_id', $empresa))
            ->where('us.company_id', $empresa)->where('u.active', 1)
            ->whereNotIn('us.profile_id', fn ($q) => $q->select('id')->from('profiles')->where('company_id', $empresa)->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']))
            ->whereNotExists(fn ($x) => $x->from('alegra_recurrentes as r')->where('r.company_id', $empresa)->whereColumn('r.user_id', 'u.user_id'));
    }

    /** GET api/alegra/recurrentes?lado=sin_recurrente&alerta=&q=&pagina= */
    public function recurrentes(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $t = trim((string) $r->query('q'));
        $resumen = $this->recurrentesQuery()->selectRaw(self::ALERTA . ' as alerta, COUNT(*) n, SUM(r.total) total')->groupBy('alerta')->get()->keyBy('alerta');
        $base = [
            'por_alerta' => $resumen,
            'total_recurrentes' => (int) $resumen->sum('n'),
            'valor_mensual' => (float) $resumen->sum('total'),
            'proxima' => DB::table('alegra_recurrentes')->where('company_id', $this->empresa())->min('proxima'),
            'sin_recurrente' => $this->clientesSinRecurrente()->count(),
            'por_pagina' => self::POR_PAGINA,
        ];

        if ($r->query('lado') === 'sin_recurrente') {
            $q = $this->clientesSinRecurrente()->when($t, fn ($x) => \App\Support\BusquedaPorPalabras::aplicar($x, $t, ['u.dni', 'u.names', 'u.lastname'], ['u.dni']));

            return $this->responder('OK', $base + [
                'total' => (clone $q)->count(),
                'filas' => $q->orderBy('u.names')->forPage($this->pagina($r), self::POR_PAGINA)->get([
                    'u.user_id', 'u.dni', 'u.status_internet_id', 'p.plan_name', 'p.monthly_price', 'c.group as grupo', 'c.billing_electronic',
                    DB::raw("TRIM(CONCAT(COALESCE(u.names,''), ' ', COALESCE(u.lastname,''))) as cliente"),
                    DB::raw("EXISTS (SELECT 1 FROM alegra_contactos k WHERE k.company_id = us.company_id AND k.user_id = u.user_id) as en_alegra"),
                    DB::raw("(SELECT o.estado FROM alegra_operaciones o WHERE o.company_id = us.company_id AND o.referencia IN (CONCAT('crear:', u.user_id), CONCAT('contacto:', u.user_id)) AND o.estado IN ('propuesta','aprobada','aplicando') LIMIT 1) as pedido"),
                ]),
            ]);
        }

        $q = $this->recurrentesQuery()
            ->when($r->query('alerta'), fn ($x, $a) => $x->whereRaw(self::ALERTA . ' = ?', [$a]))
            ->when($t, fn ($x) => $x->where(fn ($y) => $y->where('r.cliente_nombre', 'like', "%{$t}%")->orWhere('u.dni', 'like', "%{$t}%")));

        return $this->responder('OK', $base + [
            'total' => (clone $q)->count(),
            'filas' => $q->orderBy('r.cliente_nombre')->forPage($this->pagina($r), self::POR_PAGINA)->get([
                'r.alegra_id', 'r.cliente_nombre', 'r.total', 'r.inicio', 'r.fin', 'r.ultima', 'r.proxima', 'r.cada_meses', 'r.user_id',
                'u.dni', 'u.status_internet_id', 'u.active', 'p.plan_name', 'p.monthly_price', DB::raw(self::ALERTA . ' as alerta'),
                DB::raw("(SELECT COUNT(*) FROM alegra_facturas f WHERE f.company_id = r.company_id AND f.cliente_alegra_id = r.cliente_alegra_id AND f.estado = 'open') as abiertas"),
                DB::raw("(SELECT COALESCE(SUM(f.saldo), 0) FROM alegra_facturas f WHERE f.company_id = r.company_id AND f.cliente_alegra_id = r.cliente_alegra_id AND f.estado = 'open') as saldo"),
                DB::raw("(SELECT o.estado FROM alegra_operaciones o WHERE o.company_id = r.company_id AND o.referencia = CONCAT('quitar:', r.alegra_id) AND o.estado IN ('propuesta','aprobada','aplicando') LIMIT 1) as pedido"),
            ]),
        ]);
    }

    // ── Bandeja de aprobación: lo que se va a escribir en Alegra ─────────────

    private function ops(): OperacionesDeAlegra
    {
        return new OperacionesDeAlegra($this->empresa());
    }

    /** Lanza en segundo plano lo aprobado: cada operación espera a Alegra y van de a una. */
    private function lanzarAplicar(): void
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        exec(sprintf('nohup %s %s alegra:aplicar %d >> %s 2>&1 &',
            escapeshellarg($php), escapeshellarg(base_path('artisan')), $this->empresa(), escapeshellarg(storage_path('logs/alegra.log'))));
    }

    /** @return list<int> */
    private function ids(Request $r): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $r->input('ids', [])))));
    }

    /** GET api/alegra/operaciones?estado=&tipo=&q=&pagina= */
    public function operaciones(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $empresa = $this->empresa();
        $estado  = (string) $r->query('estado', 'propuesta');
        $t       = trim((string) $r->query('q'));

        $q = DB::table('alegra_operaciones as o')
            ->leftJoin('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'o.aprobada_por'))
            ->where('o.company_id', $empresa)
            ->when($estado === 'en_curso', fn ($x) => $x->whereIn('o.estado', ['aprobada', 'aplicando']), fn ($x) => $x->where('o.estado', $estado))
            ->when($r->query('tipo'), fn ($x, $tipo) => $x->where('o.tipo', $tipo))
            ->when($t, fn ($x) => $x->where(fn ($y) => $y->where('o.cliente_nombre', 'like', "%{$t}%")->orWhere('o.cliente_identificacion', 'like', "%{$t}%")->orWhere('o.datos', 'like', "%{$t}%")));

        $suma  = (clone $q)->selectRaw('COUNT(*) n, COALESCE(SUM(o.monto), 0) total')->first();
        $filas = $q->orderBy($estado === 'propuesta' ? 'o.id' : 'o.updated_at', $estado === 'propuesta' ? 'asc' : 'desc')->forPage($this->pagina($r), self::POR_PAGINA)->get([
            'o.id', 'o.tipo', 'o.estado', 'o.cliente_nombre', 'o.cliente_identificacion', 'o.monto', 'o.datos', 'o.error', 'o.externo_id', 'o.automatica',
            'o.alegra_factura_id', 'o.alegra_recurrente_id', 'o.aprobada_en', 'o.aplicada_en', 'o.created_at',
            DB::raw("NULLIF(TRIM(CONCAT(COALESCE(u.names,''), ' ', COALESCE(u.lastname,''))), '') as aprobada_por"),
        ])->map(function ($o) { $o->datos = json_decode((string) $o->datos, true) ?: []; return $o; });

        $conteos = DB::table('alegra_operaciones')->where('company_id', $empresa)->selectRaw('tipo, estado, COUNT(*) n, COALESCE(SUM(monto), 0) total')->groupBy('tipo', 'estado')->get();

        return $this->responder('OK', [
            'total' => (int) $suma->n, 'suma_total' => (float) $suma->total, 'por_pagina' => self::POR_PAGINA, 'operaciones' => $filas,
            'conteos' => $conteos, 'ajustes' => $this->ops()->ajustes(), 'tipos' => OperacionesDeAlegra::TIPOS, 'medios' => OperacionesDeAlegra::MEDIOS,
            'aplicando' => DB::table('alegra_operaciones')->where('company_id', $empresa)->whereIn('estado', ['aprobada', 'aplicando'])->count(),
        ]);
    }

    /** POST api/alegra/operaciones/proponer — vuelve a mirar la copia y arma la bandeja. No escribe en Alegra. */
    public function proponer(): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $n = $this->ops()->proponer();

        return $this->responder(array_sum($n) ? array_sum($n) . ' propuesta(s) nuevas.' : 'No hay nada nuevo que proponer.', $n);
    }

    /** POST api/alegra/operaciones/aprobar {ids:[…]} | {tipo, todas:true} */
    public function aprobar(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $ids = $this->ids($r);

        if ($r->boolean('todas') && isset(OperacionesDeAlegra::TIPOS[(string) $r->input('tipo')])) {
            $ids = DB::table('alegra_operaciones')->where('company_id', $this->empresa())->where('estado', 'propuesta')->where('tipo', $r->input('tipo'))->pluck('id')->all();
        }

        if (!$ids) {
            return $this->responder('No hay nada seleccionado para aprobar.', null, 1);
        }

        $res = $this->ops()->aprobar($ids, getSessionUserId());

        if ($res['aprobadas'] > 0) {
            $this->lanzarAplicar();
        }

        $mensaje = $res['aprobadas'] > 0 ? "{$res['aprobadas']} operación(es) aprobada(s): se están enviando a Alegra." : 'No se aprobó ninguna.';

        return $this->responder(trim($mensaje . ' ' . implode(' ', $res['rechazadas'])), $res, $res['aprobadas'] > 0 ? 0 : 1);
    }

    /** POST api/alegra/operaciones/descartar {ids:[…]} */
    public function descartar(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $n = $this->ops()->descartar($this->ids($r));

        return $this->responder("{$n} descartada(s).", ['descartadas' => $n]);
    }

    /** POST api/alegra/operaciones/restaurar {ids:[…]} */
    public function restaurar(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        $n = $this->ops()->restaurar($this->ids($r));

        return $this->responder($n ? "{$n} de vuelta en la bandeja." : 'No se pudo devolver a la bandeja.', ['restauradas' => $n], $n ? 0 : 1);
    }

    /** POST api/alegra/operaciones/verificar {tipo} — «miré en Alegra y quedó bien». */
    public function verificar(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        try {
            return $this->responder('Listo: ese tipo de operación quedó habilitado para aprobar en lote y para el automático.', $this->ops()->verificar((string) $r->input('tipo')));
        } catch (\Throwable $e) {
            return $this->responder($e->getMessage(), null, 1);
        }
    }

    /** PUT api/alegra/ajustes {banco_id, medio_pago, auto:{tipo:bool}} */
    public function ajustes(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        try {
            $a = $this->ops()->configurar(
                $r->has('banco_id') ? (string) $r->input('banco_id') : null,
                $r->has('medio_pago') ? (string) $r->input('medio_pago') : null,
                (array) $r->input('auto', []),
            );

            return $this->responder('Ajustes guardados.', $a);
        } catch (\Throwable $e) {
            return $this->responder($e->getMessage(), null, 1);
        }
    }

    /** POST api/alegra/contactos/crear {user_id} | {todos:true} — deja la propuesta en la bandeja. */
    public function crearContacto(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        try {
            if ($r->boolean('todos')) {
                $res = $this->ops()->proponerContactos();
                $falta = count($res['incompletos']);

                return $this->responder("{$res['nuevas']} contacto(s) pasaron a la bandeja." . ($falta ? " {$falta} cliente(s) tienen la ficha incompleta y quedaron por fuera." : ''), $res);
            }

            $nueva = $this->ops()->proponerContacto((int) $r->input('user_id'));

            return $this->responder($nueva ? 'Quedó en la bandeja: apruébela para crear el contacto en Alegra.' : 'Ya estaba pedido: mírelo en la bandeja.', ['nueva' => $nueva]);
        } catch (\Throwable $e) {
            return $this->responder($e->getMessage(), null, 1);
        }
    }

    /** POST api/alegra/recurrentes/quitar {alegra_id, motivo} — deja la propuesta en la bandeja. */
    public function quitarRecurrente(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        try {
            $nueva = $this->ops()->proponerQuitar((string) $r->input('alegra_id'), trim((string) $r->input('motivo')));

            return $this->responder($nueva ? 'Quedó en la bandeja: apruébela para que Alegra deje de facturarle.' : 'Ya estaba pedida: mírela en la bandeja.', ['nueva' => $nueva]);
        } catch (\Throwable $e) {
            return $this->responder($e->getMessage(), null, 1);
        }
    }

    /** POST api/alegra/recurrentes/agregar {user_id, inicio} — deja la propuesta en la bandeja. */
    public function agregarRecurrente(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        try {
            $nueva = $this->ops()->proponerCrear((int) $r->input('user_id'), $r->input('inicio'));

            return $this->responder($nueva ? 'Quedó en la bandeja: apruébela para que Alegra empiece a facturarle.' : 'Ya estaba pedida: mírela en la bandeja.', ['nueva' => $nueva]);
        } catch (\Throwable $e) {
            return $this->responder($e->getMessage(), null, 1);
        }
    }

    // ── La cuenta ────────────────────────────────────────────────────────────

    /** GET api/alegra/cuenta — empresa, bancos, numeraciones, impuestos, ítems y notas crédito. */
    public function cuenta(Request $r): JsonResponse
    {
        if (!$this->puedeVer()) return $this->negado();

        try {
            return $this->responder('OK', (new EspejoDeAlegra($this->empresa()))->cuenta($r->boolean('refrescar')));
        } catch (\Throwable $e) {
            return $this->responder($e->getMessage(), null, 1);
        }
    }
}
