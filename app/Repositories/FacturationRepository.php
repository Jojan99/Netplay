<?php

namespace App\Repositories;

use App\Models\Company;
use App\Models\DetFacturation;
use App\Models\CabFacturation;
use App\Models\PaymentLog;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Http\Requests\Facturation\GetDateFacturePendingnRequest;
use App\Models\UserData;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\Services\WhatsAppService;
use App\Services\Avisos\MensajeDeAviso;
use Illuminate\Support\Facades\DB;
use Throwable;

class FacturationRepository implements FacturationRepositoryInterface
{
    // ── Existing methods (kept intact) ────────────────────────────────────

    public function getCabUserFacturation(string $id_user): mixed
    {
        return CabFacturation::where('user_id', $id_user)
            ->where('company_id', getSessionCompanyId())
            ->first();
    }

    public function getuserFacture1($periodo, ?int $companyId = null): mixed
    {
        $companyId = $companyId ?? getSessionCompanyId();
        return CabFacturation::select('internet_plans.monthly_price','cab_facturations.id',
            'cab_facturations.user_id','cab_facturations.date_init_facturation','cab_facturations.created_at')
            ->join('user_data', 'user_data.user_id', '=', 'cab_facturations.user_id')
            ->join('users', 'users.id', '=', 'user_data.user_id')
            ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
            ->where('cab_facturations.group', $periodo)
            ->where('user_data.active', 1)
            ->where('users.company_id', $companyId)
            ->whereNotIn('users.profile_id', function ($q) use ($companyId) {
                $q->select('id')->from('profiles')
                    ->where('company_id', $companyId)
                    ->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']);
            })
            ->get();
    }

    public function getuserFactureCreate($idUser): mixed
    {
        $companyId = getSessionCompanyId();
        return CabFacturation::select('internet_plans.monthly_price','cab_facturations.id',
            'cab_facturations.user_id','cab_facturations.date_init_facturation','cab_facturations.created_at')
            ->join('user_data', 'user_data.user_id', '=', 'cab_facturations.user_id')
            ->join('users', 'users.id', '=', 'user_data.user_id')
            ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
            ->where('user_data.user_id', $idUser)
            ->where('user_data.active', 1)
            ->where('users.company_id', $companyId)
            ->whereNotIn('users.profile_id', function ($q) use ($companyId) {
                $q->select('id')->from('profiles')
                    ->where('company_id', $companyId)
                    ->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']);
            })
            ->get();
    }

    public function getDatePayFacture(GetDateFacturePendingnRequest $data): mixed
    {
        // Las anuladas se muestran acá —marcadas— para que no parezca que la
        // factura se esfumó; en el resto de la plataforma siguen sin contar.
        $query = DetFacturation::conAnuladas()->select(
            'det_facturations.id','det_facturations.cab_id','date_facturation','number_facture','date_create_facturation',
            'total','price_total','porcentage_discount','days_facture','discount',
            'price_discount','create_facture_manual','paid','price_abone','abone',
            'paid_at','observacion','anulada_en','anulada_motivo',
            DB::raw('price_total - price_abone as balance'),
            // Con qué se pagó: del último movimiento de esa factura.
            DB::raw("(SELECT pm.name FROM payment_logs pl
                        LEFT JOIN payment_methods pm ON pm.id = pl.payment_method_id
                       WHERE pl.det_facturation_id = det_facturations.id AND pl.amount > 0
                    ORDER BY pl.created_at DESC LIMIT 1) as metodo_pago"),
            DB::raw("(SELECT pl.notes FROM payment_logs pl
                       WHERE pl.det_facturation_id = det_facturations.id AND pl.amount > 0
                    ORDER BY pl.created_at DESC LIMIT 1) as nota_pago")
        )->where('cab_id', $data['cab_id']);

        if (!self::cabeceraDeLaEmpresa((int) $data['cab_id'])) {
            return collect();
        }

        if ($data['value'] == 2) $query->where('paid', 1);
        elseif ($data['value'] == 3) $query->where('paid', 0);

        return $query->get();
    }

    /**
     * La cabecera de facturación es de la empresa del operador. Los cab_id son
     * correlativos: sin esto se veían facturas y pagos de otras empresas.
     */
    private static function cabeceraDeLaEmpresa(int $cabId): bool
    {
        $empresa = getSessionCompanyId();

        return $empresa && DB::table('cab_facturations')->where('id', $cabId)->where('company_id', $empresa)->exists();
    }

    public function getDateFacturePending(GetDateFacturePendingnRequest $data): mixed
    {
        // El cab_id va como binding y no concatenado: la validación de la Request
        // hoy lo deja en entero, pero concatenar aquí deja la puerta abierta a
        // inyección si mañana se reutiliza este método desde otro origen.
        $cabId = (int) $data['cab_id'];

        if (!self::cabeceraDeLaEmpresa($cabId)) {
            return collect();
        }

        return DetFacturation::select(
            'id','cab_id','date_facturation','number_facture','date_create_facturation',
            'total','price_total','porcentage_discount','days_facture','discount',
            'price_discount','create_facture_manual','paid','price_abone','abone',
            DB::raw('price_total - price_abone as balance'),
            DB::raw('(SELECT SUM(price_total - price_abone) FROM det_facturations WHERE cab_id = ? AND paid = 0) as total_pending'),
            DB::raw('(SELECT COUNT(*) FROM det_facturations WHERE cab_id = ? AND paid = 0) as months_pending')
        )
        ->addBinding([$cabId, $cabId], 'select')
        ->where('cab_id', $cabId)
        ->where('paid', 0)
        ->get();
    }

    public function getDateFacturePendingById($det_id): mixed
    {
        return DetFacturation::where('det_facturations.id', $det_id)
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', getSessionCompanyId())
            ->select('det_facturations.*')
            ->first();
    }

    public function getDataInfoPenddingFacture(): mixed
    {
        $resultados = DB::table('user_data as us')
            ->join('cab_facturations as cb', 'cb.user_id', '=', 'us.user_id')
            ->join('det_facturations as dt', 'cb.id', '=', 'dt.cab_id')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->where('users.company_id', getSessionCompanyId())
            ->where('dt.paid', 0)
            ->select(['cb.date_init_facturation','us.names','us.lastname','us.dni',
                'us.user_id','us.phone','us.email','us.address','dt.paid',
                'dt.price_total','dt.id','dt.cab_id','dt.price_discount','dt.price_abone'])
            ->get();

        $groupedResults = [];
        foreach ($resultados as $item) {
            $userId = $item->user_id;
            if (!isset($groupedResults[$userId])) {
                $groupedResults[$userId] = $item;
                $groupedResults[$userId]->monthPedding = 1;
            } else {
                $groupedResults[$userId]->price_discount += $item->price_discount;
                $groupedResults[$userId]->price_total    += $item->price_total;
                $groupedResults[$userId]->monthPedding   += 1;
            }
        }
        return array_values($groupedResults);
    }

    public function getPricePlan(string $id_user): mixed
    {
        return UserData::select('internet_plans.monthly_price')
            ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
            ->where('user_data.user_id', $id_user)->first();
    }

    public function getDateLast(string $id_user): mixed
    {
        return DetFacturation::select('det_facturations.date_facturation')
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.user_id', $id_user)
            ->orderBy('det_facturations.date_facturation', 'desc')->first();
    }

    public function createCabFacturation(string $id_user, int $group, string $fecha): mixed
    {
        return CabFacturation::create([
            'user_id'               => $id_user,
            'company_id'            => getSessionCompanyId(),
            'date_init_facturation' => $fecha,
            'group'                 => $group,
        ]);
    }

    public function createDetFacturation(CreateFacturationRequest $data): mixed
    {
        return DB::transaction(function () use ($data) {
            $cab       = CabFacturation::find($data['cab_id']);
            $companyId = $cab ? $cab->company_id : getSessionCompanyId();
            $company   = Company::find($companyId);
            $prefix    = $this->prefijoFactura($company, (int) $companyId);

            // Bloquea filas de la empresa para que dos procesos simultáneos no obtengan el mismo consecutivo
            DB::table('cab_facturations')->where('company_id', $companyId)->lockForUpdate()->count();

            $number = $prefix . $this->getConsecutiveFacture((int) $companyId, $prefix);

            return DetFacturation::create([
                'cab_id'                  => $data['cab_id'],
                'date_facturation'        => $data['date_facturation'],
                'number_facture'          => $number,
                'date_create_facturation' => $data['date_create_facturation'],
                'total'                   => $data['total'],
                'price_total'             => $data['price_total'],
                'price_abone'             => 0,
                'discount'                => $data['discount'],
                'price_discount'          => $data['price_discount'],
                'days_facture'            => $data['days_facture'],
                'paid'                    => 0,
                'create_facture_manual'   => $data['create_facture_manual'],
                'porcentage_discount'     => $data['porcentage_discount'],
            ]);
        });
    }

    public function updateDetFacturation(CreateFacturationRequest $data): mixed
    {
        $det = DetFacturation::where('det_facturations.id', $data['id_facture'])
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', getSessionCompanyId())
            ->select('det_facturations.*')->first();
        if ($det) $det->update([
            'total'               => 0,
            'price_total'         => intval($data['price_total']),
            'price_abone'         => intval($data['price_abone']),
            'discount'            => $data['discount'],
            'price_discount'      => intval($data['price_discount']),
            'days_facture'        => $data['days_facture'],
            'porcentage_discount' => $data['porcentage_discount'],
        ]);
        return true;
    }

    public function createpaidFacturation(CreatePaidFacturationRequest $data): mixed
    {
        $det = DetFacturation::where('det_facturations.id', $data['det_id'])
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', getSessionCompanyId())
            ->select('det_facturations.*')->first();
        if ($det) $det->update(['paid' => 1, 'log_id' => $data['log_id'],
            'paid_at' => now(), 'paid_by_user_id' => getSessionUserId()]);
        return true;
    }

    /**
     * Prefijo de factura de la empresa. Sin prefijo configurado antes todas
     * salían con 'GL' y se mezclaban entre empresas; ahora cada empresa sin
     * prefijo toma uno propio (F{id}-), salvo las que ya facturaron con GL,
     * que siguen con GL para no romper su consecutivo.
     */
    private function prefijoFactura(?Company $company, int $companyId): string
    {
        $prefix = trim((string) ($company?->invoice_prefix ?? ''));
        if ($prefix !== '') {
            return $prefix;
        }

        $tieneGL = DetFacturation::join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', $companyId)
            ->where('det_facturations.number_facture', 'like', 'GL%')
            ->exists();

        return $tieneGL ? 'GL' : 'F' . $companyId . '-';
    }

    public function getConsecutiveFacture(int $companyId, ?string $prefix = null): mixed
    {
        $base = DetFacturation::join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', $companyId)
            ->orderBy('det_facturations.id', 'desc')
            ->select('det_facturations.number_facture');

        // Se sigue la serie del prefijo actual. Tomar la última factura sin mirar
        // el prefijo hacía que una factura de prueba de la pasarela (TEST260916150703)
        // disparara el consecutivo a NT260916150704.
        $last = null;
        if ($prefix !== null && $prefix !== '') {
            $last = (clone $base)
                ->whereRaw('det_facturations.number_facture REGEXP ?', ['^' . preg_quote($prefix) . '[0-9]+$'])
                ->first();
        }
        // Prefijo nuevo: sigue la numeración anterior de la empresa (sin las de prueba).
        $last ??= (clone $base)->where('det_facturations.number_facture', 'not like', 'TEST%')->first();

        if (!$last) return 1;

        // Extrae los dígitos finales del número (funciona con cualquier prefijo: GL5, NP87, ISP-100...)
        preg_match('/(\d+)$/', $last->number_facture, $matches);
        return isset($matches[1]) ? ((int) $matches[1] + 1) : 1;
    }

    public function getConsecutiveFacture1(): mixed
    {
        $last = DetFacturation::orderBy('id', 'desc')->first();
        return $last ? $last->id : null;
    }

    public function createAboneFacturation(CreatePaidFacturationRequest $data): mixed
    {
        $det = DetFacturation::where('det_facturations.id', $data['det_id'])
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', getSessionCompanyId())
            ->select('det_facturations.*')->first();
        if ($det) $det->update([
            'paid'        => $data['paid'],
            'abone'       => $data['abone'],
            'log_id'      => $data['log_id'],
            'price_abone' => $data['price_total'] ?? 0,
        ]);
        return true;
    }

    // ── NEW METHODS ───────────────────────────────────────────────────────

    /**
     * Paginated client list showing pending balances.
     * Returns stdClass with items[], total, per_page, current_page, last_page.
     */
    /**
     * Los clientes de la cartera.
     *
     * Por defecto sólo los que deben algo, que es lo que se viene a cobrar.
     * Con $incluirAlDia entran también los que están al día: antes, un cliente
     * que terminaba de pagar desaparecía de la pantalla y no había manera de
     * abrir su historial de facturas desde acá.
     */
    public function getClientsPaginated(?string $search, int $page, int $perPage, bool $incluirAlDia = false): object
    {
        $companyId = getSessionCompanyId();

        // El pendiente se cuenta con un join condicionado en vez de un WHERE:
        // así el cliente sin deuda sigue apareciendo, con cero.
        $pendiente = fn ($join) => $join->on('cb.id', '=', 'dt.cab_id')
            ->where('dt.paid', 0)
            ->whereNull('dt.anulada_en');

        $base = DB::table('user_data as us')
            ->join('cab_facturations as cb', 'cb.user_id', '=', 'us.user_id')
            ->leftJoin('det_facturations as dt', $pendiente)
            ->join('users', 'users.id', '=', 'us.user_id')
            ->where('users.company_id', $companyId)
            ->when(!$incluirAlDia, fn ($q) => $q->whereNotNull('dt.id'));

        if ($search) {
            $base->where(function ($q) use ($search) {
                $q->where('us.names', 'like', "%{$search}%")
                  ->orWhere('us.lastname', 'like', "%{$search}%")
                  ->orWhere('us.dni', 'like', "%{$search}%")
                  ->orWhere('us.phone', 'like', "%{$search}%");
            });
        }

        // Cuenta pares (user_id, cab_id) para que coincida con los items agrupados
        $total = (clone $base)
            ->selectRaw('COUNT(DISTINCT us.user_id, cb.id) as cnt')
            ->value('cnt') ?? 0;

        $items = (clone $base)
            ->select([
                'us.user_id', 'us.names', 'us.lastname', 'us.dni', 'us.phone', 'us.email', 'us.address',
                'cb.id as cab_id', 'cb.date_init_facturation',
                DB::raw('COALESCE(SUM(dt.price_total - dt.price_discount - dt.price_abone), 0) as total_pending'),
                DB::raw('COUNT(dt.id) as months_pending'),
            ])
            ->groupBy('us.user_id','us.names','us.lastname','us.dni','us.phone','us.email','us.address',
                      'cb.id','cb.date_init_facturation')
            ->orderByDesc('total_pending')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // Resumen de mora (sin filtro de búsqueda): clientes con 1, 2 y 3+ facturas pendientes
        $grouped = DB::table('user_data as us')
            ->join('cab_facturations as cb', 'cb.user_id', '=', 'us.user_id')
            ->join('det_facturations as dt', 'cb.id', '=', 'dt.cab_id')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->where('users.company_id', $companyId)
            ->where('dt.paid', 0)
            ->whereNull('dt.anulada_en')
            ->select('us.user_id', 'cb.id as cab_id',
                DB::raw('COUNT(dt.id) as months'),
                DB::raw('SUM(dt.price_total - dt.price_discount - dt.price_abone) as pending'))
            ->groupBy('us.user_id', 'cb.id');

        $summary = DB::query()->fromSub($grouped, 'g')
            ->selectRaw('SUM(g.months = 1) as m1, SUM(g.months = 2) as m2, SUM(g.months >= 3) as m3, SUM(g.pending) as total_debt')
            ->first();

        return (object)[
            'items'        => $items,
            'total'        => (int) $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
            'summary'      => [
                'm1'         => (int) ($summary->m1 ?? 0),
                'm2'         => (int) ($summary->m2 ?? 0),
                'm3'         => (int) ($summary->m3 ?? 0),
                'total_debt' => (float) ($summary->total_debt ?? 0),
            ],
        ];
    }

    /**
     * All invoices for a client (pending + paid) with traceability.
     */
    public function getClientInvoices(int $cabId): array
    {
        $companyId = getSessionCompanyId();

        // Verify belongs to company
        $cab = CabFacturation::where('id', $cabId)->where('company_id', $companyId)->first();
        if (!$cab) return [];

        $invoices = DetFacturation::select(
            'det_facturations.id', 'det_facturations.number_facture',
            'det_facturations.date_facturation', 'det_facturations.price_total',
            'det_facturations.price_abone', 'det_facturations.price_discount',
            'det_facturations.abone', 'det_facturations.paid',
            'det_facturations.paid_at', 'det_facturations.paid_by_user_id',
            'det_facturations.days_facture', 'det_facturations.porcentage_discount',
            'det_facturations.created_at',
            DB::raw('det_facturations.price_total - det_facturations.price_abone - det_facturations.price_discount as balance'),
            DB::raw("COALESCE(payer.username,'—') as paid_by_name")
        )
        ->leftJoin('users as payer', 'payer.id', '=', 'det_facturations.paid_by_user_id')
        ->where('det_facturations.cab_id', $cabId)
        ->orderByDesc('det_facturations.date_facturation')
        ->get();

        $logs = PaymentLog::with('paymentMethod:id,name')
            ->where('cab_id', $cabId)
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->get();

        return ['invoices' => $invoices, 'logs' => $logs];
    }

    /**
     * Pay an invoice fully and log the event.
     */
    public function payInvoice(int $detId, string $clientName, ?int $paymentMethodId = null, ?string $observacion = null): bool
    {
        $det = DetFacturation::where('det_facturations.id', $detId)
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', getSessionCompanyId())
            ->where('det_facturations.paid', 0)
            ->select('det_facturations.*', 'cab_facturations.id as cab_id_val')
            ->first();

        if (!$det) return false;

        $det->update([
            'paid'             => 1,
            'paid_at'          => now(),
            'paid_by_user_id'  => getSessionUserId(),
        ]);

        $amountPaid = $det->price_total - $det->price_discount - ($det->price_abone ?? 0);

        PaymentLog::create([
            'company_id'          => getSessionCompanyId(),
            'det_facturation_id'  => $detId,
            'cab_id'              => $det->cab_id,
            'number_facture'      => $det->number_facture,
            'client_name'         => $clientName,
            'recorded_by_user_id' => getSessionUserId(),
            'amount'              => max(0, $amountPaid),
            'type'                => 'pago_completo',
            'payment_method_id'   => $paymentMethodId,
            'notes'               => $observacion,
        ]);

        if ($observacion !== null && $observacion !== '') {
            $det->update(['observacion' => mb_substr($observacion, 0, 500)]);
        }

        return true;
    }

    /**
     * Deshacer un pago.
     *
     * No se borra nada: la factura vuelve a quedar pendiente y el movimiento
     * queda anotado como reverso, con quién lo hizo y por qué. Si alguien
     * pregunta el mes que viene por qué una factura pagada volvió a deber,
     * la respuesta está en el historial.
     */
    public function revertirPago(int $detId, string $motivo): array
    {
        $empresa = getSessionCompanyId();

        $det = DetFacturation::where('det_facturations.id', $detId)
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', $empresa)
            ->select('det_facturations.*', 'cab_facturations.user_id')
            ->first();

        if (!$det) {
            return ['ok' => false, 'mensaje' => 'No encontramos esa factura.'];
        }

        if (!$det->paid) {
            return ['ok' => false, 'mensaje' => 'Esa factura no está pagada.'];
        }

        $pagado = (float) $det->price_total - (float) $det->price_discount - (float) ($det->price_abone ?? 0);

        $det->update([
            'paid'            => 0,
            'paid_at'         => null,
            'paid_by_user_id' => null,
        ]);

        PaymentLog::create([
            'company_id'          => $empresa,
            'det_facturation_id'  => $det->id,
            'cab_id'              => $det->cab_id,
            'number_facture'      => $det->number_facture,
            'client_name'         => (string) DB::table('user_data')->where('user_id', $det->user_id)
                ->selectRaw("TRIM(CONCAT(COALESCE(names,''),' ',COALESCE(lastname,''))) n")->value('n'),
            'recorded_by_user_id' => getSessionUserId(),
            // Negativo: así la suma de los movimientos sigue dando lo cobrado
            // de verdad, sin tener que acordarse de descontar los reversos.
            'amount'              => -max(0, $pagado),
            'type'                => 'reverso',
            'notes'               => $motivo,
        ]);

        return ['ok' => true, 'mensaje' => 'El pago se revirtió y la factura volvió a quedar pendiente.'];
    }

    /**
     * Anular una factura.
     *
     * Una factura mal hecha no se borra: se anula. Así no se pierde el
     * consecutivo ni el rastro de que existió, deja de contar en la cartera y
     * queda dicho por qué.
     */
    public function anularFactura(int $detId, string $motivo): array
    {
        $empresa = getSessionCompanyId();

        $det = DetFacturation::where('det_facturations.id', $detId)
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', $empresa)
            ->select('det_facturations.*')
            ->first();

        if (!$det) {
            return ['ok' => false, 'mensaje' => 'No encontramos esa factura.'];
        }

        if ($det->anulada_en) {
            return ['ok' => false, 'mensaje' => 'Esa factura ya estaba anulada.'];
        }

        if ($det->paid) {
            return ['ok' => false, 'mensaje' => 'Está pagada: primero hay que revertir el pago.'];
        }

        $det->update([
            'anulada_en'     => now(),
            'anulada_por'    => getSessionUserId(),
            'anulada_motivo' => mb_substr($motivo, 0, 255),
        ]);

        return ['ok' => true, 'mensaje' => 'La factura quedó anulada.'];
    }

    /**
     * Pay multiple invoices at once (bulk liquidation).
     * Returns number of invoices paid.
     */
    public function liquidateBulk(array $detIds, string $clientName, ?int $paymentMethodId = null): int
    {
        $companyId = getSessionCompanyId();
        $paid = 0;

        foreach ($detIds as $detId) {
            $det = DetFacturation::where('det_facturations.id', $detId)
                ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
                ->where('cab_facturations.company_id', $companyId)
                ->where('det_facturations.paid', 0)
                ->select('det_facturations.*')
                ->first();

            if (!$det) continue;

            $det->update([
                'paid'            => 1,
                'paid_at'         => now(),
                'paid_by_user_id' => getSessionUserId(),
            ]);

            PaymentLog::create([
                'company_id'          => $companyId,
                'det_facturation_id'  => $detId,
                'cab_id'              => $det->cab_id,
                'number_facture'      => $det->number_facture,
                'client_name'         => $clientName,
                'recorded_by_user_id' => getSessionUserId(),
                'amount'              => max(0, $det->price_total - $det->price_discount - ($det->price_abone ?? 0)),
                'type'                => 'pago_completo',
                'payment_method_id'   => $paymentMethodId,
            ]);

            $paid++;
        }

        return $paid;
    }

    /**
     * Record a partial payment and log the event.
     */
    public function abonarInvoice(int $detId, float $amount, string $clientName, ?int $paymentMethodId = null): bool
    {
        $det = DetFacturation::where('det_facturations.id', $detId)
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', getSessionCompanyId())
            ->select('det_facturations.*', 'cab_facturations.id as cab_id_val')
            ->first();

        if (!$det || $det->paid || $amount <= 0) return false;

        $saldo    = max(0, $det->price_total - $det->price_discount - ($det->price_abone ?? 0));
        $amount   = min($amount, $saldo);
        $newAbone = ($det->price_abone ?? 0) + $amount;
        $isPaid   = $newAbone >= ($det->price_total - $det->price_discount);

        $det->update([
            'price_abone'      => $newAbone,
            'abone'            => 1,
            'paid'             => $isPaid ? 1 : 0,
            'paid_at'          => $isPaid ? now() : null,
            'paid_by_user_id'  => getSessionUserId(),
        ]);

        PaymentLog::create([
            'company_id'          => getSessionCompanyId(),
            'det_facturation_id'  => $detId,
            'cab_id'              => $det->cab_id,
            'number_facture'      => $det->number_facture,
            'client_name'         => $clientName,
            'recorded_by_user_id' => getSessionUserId(),
            'amount'              => $amount,
            'type'                => $isPaid ? 'pago_completo' : 'abono',
            'payment_method_id'   => $paymentMethodId,
        ]);

       // $this->notifyPayment($det->cab_id, $clientName, $det->number_facture, $amount, $isPaid ? 'completo' : 'abono');
        $saldoNuevo = max(0, ($det->price_total - $det->price_discount) - $newAbone);

        MensajeDeAviso::nuevo($isPaid ? 'Pago registrado' : 'Abono registrado', getSessionCompanyId(), '💰')
            ->dato('Cliente', $clientName)
            ->dato('Factura', $det->number_facture)
            ->dinero('Valor recibido', $amount)
            ->dinero($isPaid ? 'Total de la factura' : 'Saldo pendiente', $isPaid ? ($det->price_total - $det->price_discount) : $saldoNuevo)
            ->dato('Estado', $isPaid ? 'Factura al día' : 'Factura con saldo')
            ->fecha('Registrado', now())
            ->enviar('payment');

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a WhatsApp confirmation to the client after a payment.
     * Silently swallows any error so payment is never blocked.
     */
    private function notifyPayment(int $cabId, string $clientName, string $invoiceNumber, float $amount, string $type): void
    {
        try {
            $companyId = getSessionCompanyId();

            $row = DB::table('user_data as ud')
                ->join('cab_facturations as cb', 'cb.user_id', '=', 'ud.user_id')
                ->where('cb.id', $cabId)
                ->where('cb.company_id', $companyId)
                ->select('ud.phone', 'ud.user_id', 'ud.names', 'ud.lastname')
                ->first();

            if (!$row || empty($row->phone)) return;

            if (!WhatsAppService::isEnabledForUser($row->user_id)) return;

            $wa        = new WhatsAppService($companyId);
            $humanizer = new \App\Services\WhatsAppMessageHumanizerService();
            $amountF   = number_format($amount, 0, ',', '.');

            // 🎭 Mensaje ÚNICO y humanizado para cada pago
            $msg = $humanizer->generatePaymentConfirmation([
                'names'       => $row->names ?? $clientName,
                'lastname'    => $row->lastname ?? '',
                'number_bill' => $invoiceNumber,
                'amount'      => $amountF . ' COP',
                'type'        => $type,
            ]);

            $wa->mensajeInformativo($row->phone, $msg);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[WA_PAYMENT] Error al enviar notificación', [
                'cab_id'  => $cabId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update invoice fields.
     */
    public function updateInvoice(int $detId, array $data): bool
    {
        $det = DetFacturation::where('det_facturations.id', $detId)
            ->join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', getSessionCompanyId())
            ->select('det_facturations.*')->first();

        if (!$det) return false;

        $det->update([
            'price_total'         => $data['price_total']         ?? $det->price_total,
            'price_discount'      => $data['price_discount']      ?? $det->price_discount,
            'porcentage_discount' => $data['porcentage_discount']  ?? $det->porcentage_discount,
            'days_facture'        => $data['days_facture']         ?? $det->days_facture,
            'date_facturation'    => $data['date_facturation']     ?? $det->date_facturation,
        ]);

        return true;
    }

    /**
     * Export payments as a flat array for CSV generation.
     */
    public function exportPayments(?string $from, ?string $to, ?int $cabId): array
    {
        $companyId = getSessionCompanyId();
        $query = DB::table('det_facturations as dt')
            ->join('cab_facturations as cb', 'cb.id', '=', 'dt.cab_id')
            ->join('user_data as us', 'us.user_id', '=', 'cb.user_id')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->where('users.company_id', $companyId)
            ->select([
                'dt.number_facture', 'dt.date_facturation', 'dt.price_total',
                'dt.price_abone', 'dt.price_discount', 'dt.paid', 'dt.abone',
                'dt.paid_at', 'dt.created_at',
                DB::raw("CONCAT(us.names,' ',us.lastname) as cliente"),
                'us.dni', 'us.phone',
            ])
            ->orderByDesc('dt.created_at');

        if ($from) $query->whereDate('dt.created_at', '>=', $from);
        if ($to)   $query->whereDate('dt.created_at', '<=', $to);
        if ($cabId) $query->where('cb.id', $cabId);

        return $query->get()->toArray();
    }

    /**
     * Paginated payment logs (trazabilidad).
     */
    public function getPaymentLogsPaginated(?string $search, ?string $from, ?string $to, int $page, int $perPage): object
    {
        $companyId = getSessionCompanyId();

        $base = DB::table('payment_logs as pl')
            ->leftJoin('users as rec', 'rec.id', '=', 'pl.recorded_by_user_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'pl.payment_method_id')
            ->where('pl.company_id', $companyId);

        if ($search) {
            $base->where(function ($q) use ($search) {
                $q->where('pl.client_name', 'like', "%{$search}%")
                  ->orWhere('pl.number_facture', 'like', "%{$search}%");
            });
        }
        if ($from) $base->whereDate('pl.created_at', '>=', $from);
        if ($to)   $base->whereDate('pl.created_at', '<=', $to);

        $total = (clone $base)->count();

        $items = (clone $base)
            ->select([
                'pl.*',
                DB::raw("COALESCE(rec.username,'—') as recorded_by_name"),
                DB::raw("pm.name as payment_method_name"),
            ])
            ->orderByDesc('pl.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return (object)[
            'items'        => $items,
            'total'        => (int) $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
