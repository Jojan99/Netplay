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
use App\Services\NotificationRouterService;
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
        $query = DetFacturation::select(
            'id','cab_id','date_facturation','number_facture','date_create_facturation',
            'total','price_total','porcentage_discount','days_facture','discount',
            'price_discount','create_facture_manual','paid','price_abone','abone',
            DB::raw('price_total - price_abone as balance')
        )->where('cab_id', $data['cab_id']);

        if ($data['value'] == 2) $query->where('paid', 1);
        elseif ($data['value'] == 3) $query->where('paid', 0);

        return $query->get();
    }

    public function getDateFacturePending(GetDateFacturePendingnRequest $data): mixed
    {
        return DetFacturation::select(
            'id','cab_id','date_facturation','number_facture','date_create_facturation',
            'total','price_total','porcentage_discount','days_facture','discount',
            'price_discount','create_facture_manual','paid','price_abone','abone',
            DB::raw('price_total - price_abone as balance'),
            DB::raw('(SELECT SUM(price_total - price_abone) FROM det_facturations WHERE cab_id = '.$data['cab_id'].' AND paid = 0) as total_pending'),
            DB::raw('(SELECT COUNT(*) FROM det_facturations WHERE cab_id = '.$data['cab_id'].' AND paid = 0) as months_pending')
        )
        ->where('cab_id', $data['cab_id'])
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
            $prefix    = ($company && $company->invoice_prefix) ? $company->invoice_prefix : 'GL';

            // Bloquea filas de la empresa para que dos procesos simultáneos no obtengan el mismo consecutivo
            DB::table('cab_facturations')->where('company_id', $companyId)->lockForUpdate()->count();

            $number = $prefix . $this->getConsecutiveFacture($companyId);

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

    public function getConsecutiveFacture(int $companyId): mixed
    {
        $last = DetFacturation::join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', $companyId)
            ->orderBy('det_facturations.id', 'desc')
            ->select('det_facturations.number_facture')
            ->first();

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
    public function getClientsPaginated(?string $search, int $page, int $perPage): object
    {
        $companyId = getSessionCompanyId();

        $base = DB::table('user_data as us')
            ->join('cab_facturations as cb', 'cb.user_id', '=', 'us.user_id')
            ->join('det_facturations as dt', 'cb.id', '=', 'dt.cab_id')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->where('users.company_id', $companyId)
            ->where('dt.paid', 0);

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
                DB::raw('SUM(dt.price_total - dt.price_discount - dt.price_abone) as total_pending'),
                DB::raw('COUNT(dt.id) as months_pending'),
            ])
            ->groupBy('us.user_id','us.names','us.lastname','us.dni','us.phone','us.email','us.address',
                      'cb.id','cb.date_init_facturation')
            ->orderByDesc('total_pending')
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
    public function payInvoice(int $detId, string $clientName, ?int $paymentMethodId = null): bool
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
        ]);

        return true;
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
        $typeLabel = $isPaid ? 'Pago completo' : 'Abono';
        NotificationRouterService::dispatch(
            getSessionCompanyId(),
            'payment',
            "💰 *{$typeLabel} registrado*\n\nCliente: *{$clientName}*\nFactura: *{$det->number_facture}*\nValor: *$" . number_format($amount, 0, ',', '.') . " COP*"
        );

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
                ->select('ud.phone', 'ud.user_id')
                ->first();

            if (!$row || empty($row->phone)) return;

            if (!WhatsAppService::isEnabledForUser($row->user_id)) return;

            $wa      = new WhatsAppService($companyId);
            $amountF = number_format($amount, 0, ',', '.');

            if ($type === 'completo') {
                $msg = "✅ *Pago recibido*\n\nHola {$clientName}, confirmamos el pago completo de la factura *{$invoiceNumber}* por *\${$amountF} COP*.\n\nGracias por tu pago. 🙏";
            } else {
                $msg = "💰 *Abono registrado*\n\nHola {$clientName}, registramos un abono de *\${$amountF} COP* en la factura *{$invoiceNumber}*.\n\nCualquier duda, contáctanos.";
            }

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
