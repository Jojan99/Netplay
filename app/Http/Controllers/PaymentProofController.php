<?php

namespace App\Http\Controllers;

use App\Models\DetFacturation;
use App\Models\PaymentProof;
use App\Models\PaymentProofAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentProofController extends Controller
{
    /**
     * Todos los comprobantes son de la empresa en sesión. Sin este filtro la
     * auditoría mostraba (y dejaba aprobar) los comprobantes de otras empresas.
     */
    private function scoped()
    {
        $companyId = getSessionCompanyId() ?: (\Tymon\JWTAuth\Facades\JWTAuth::user()->company_id ?? null);
        if (!$companyId) {
            abort(response()->json(['status' => 'error', 'message' => 'Sin empresa en sesión.'], 403));
        }
        return PaymentProof::query()->where('company_id', $companyId);
    }

    /** Busca un comprobante de la empresa en sesión o corta con 404. */
    private function findOwned(int $id): PaymentProof
    {
        $proof = $this->scoped()->find($id);
        if (!$proof) {
            abort(response()->json(['status' => 'error', 'message' => 'Comprobante no encontrado.'], 404));
        }
        return $proof;
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped()->with(['user', 'invoice', 'audits'])->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        if ($request->filled('client')) {
            $term = trim($request->string('client')->toString());
            $query->whereHas('user', function ($userQuery) use ($term) {
                $userQuery->where('dni', 'like', "%{$term}%")
                    ->orWhere('names', 'like', "%{$term}%")
                    ->orWhere('lastname', 'like', "%{$term}%");
            });
        }

        if ($request->filled('amount')) {
            $amount = (float) preg_replace('/[^0-9.]/', '', $request->amount);
            $query->where(function ($amountQuery) use ($amount) {
                $amountQuery->where('reported_amount', $amount)->orWhere('detected_amount', $amount);
            });
        }

        if ($request->filled('reference')) {
            $query->where('reference_number', 'like', '%' . trim($request->reference) . '%');
        }

        if ($request->filled('bank')) {
            $query->where('bank_name', 'like', '%' . trim($request->bank) . '%');
        }

        $proofs = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'status' => 'success',
            'data' => $proofs,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $proof = $this->findOwned($id)->load(['user', 'invoice', 'audits']);

        return response()->json([
            'status' => 'success',
            'data' => $proof,
        ]);
    }

    public function markSuspicious(int $id, Request $request): JsonResponse
    {
        $proof = $this->findOwned($id);
        $previous = $proof->status;

        $proof->update([
            'status' => 'suspicious',
            'rejection_reason' => $request->input('reason', 'Comprobante sospechoso por revisión humana.'),
            'reviewed_by' => $request->input('reviewed_by', Auth::id() ?? null),
            'reviewed_at' => now(),
        ]);

        $this->audit($proof, $previous, 'suspicious', $request->input('reason', 'Comprobante sospechoso por revisión humana.'), [
            'reviewed_by' => $request->input('reviewed_by', Auth::id() ?? null),
            'source' => 'manual_review',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Se marcó el comprobante como sospechoso.',
            'data' => $proof->fresh(),
        ]);
    }

    /**
     * El medio de pago de un comprobante, según el banco que se leyó.
     *
     * Igual que con la pasarela, no se reutilizan las cuentas cargadas por la
     * empresa: se usa uno propio —«Comprobante · Bancolombia»— que se crea la
     * primera vez y queda inactivo, para leerlo en el historial sin que
     * aparezca al cobrar a mano.
     */
    private function medioDelComprobante(PaymentProof $proof): ?int
    {
        $banco = trim((string) $proof->bank_name);

        if ($banco === '') {
            return null;
        }

        $nombre = mb_substr('Comprobante · ' . $banco, 0, 100);

        try {
            return DB::table('payment_methods')
                ->where('company_id', $proof->company_id)->where('name', $nombre)->value('id')
                ?: DB::table('payment_methods')->insertGetId([
                    'company_id' => $proof->company_id,
                    'name'       => $nombre,
                    'active'     => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function approve(int $id, Request $request): JsonResponse
    {
        $proof = $this->findOwned($id);
        $previous = $proof->status;

        $invoice = $proof->invoice;

        // Quien revisa puede corregir el monto: si el lector de la imagen no
        // lo encontró, o lo leyó mal, se escribe a mano.
        $proofAmount = $request->filled('amount')
            ? (float) $request->input('amount')
            : (float) ($proof->reported_amount ?? $proof->detected_amount ?? 0);

        // Aprobar con monto cero no aplicaba nada y decía «aprobado»: la
        // factura seguía debiendo y nadie se enteraba hasta el reclamo.
        if ($proofAmount <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No sabemos de cuánto es el pago: escribí el monto para aprobarlo.',
            ], 422);
        }

        if ($invoice) {
            $baseDebt = (float) ($invoice->price_total ?? 0) - (float) ($invoice->price_discount ?? 0);
            $currentAbone = (float) ($invoice->price_abone ?? 0);
            $newAbone = max(0, $currentAbone + $proofAmount);
            $invoice->price_abone = $newAbone;
            $invoice->paid = $newAbone >= max(0, $baseDebt) ? 1 : 0;
            $invoice->abone = $invoice->paid ? 1 : 0;
            $invoice->paid_at = $invoice->paid ? now() : null;
            $invoice->paid_by_user_id = $proof->user?->user_id;
            $invoice->save();

            // El pago tiene que verse en los movimientos de la factura: sin
            // esto, un comprobante aprobado no dejaba ningún rastro contable.
            \App\Models\PaymentLog::create([
                'company_id'          => $proof->company_id,
                'det_facturation_id'  => $invoice->id,
                'cab_id'              => $invoice->cab_id,
                'number_facture'      => $invoice->number_facture,
                'client_name'         => trim((string) \Illuminate\Support\Facades\DB::table('user_data')
                    ->where('user_id', $proof->user_id)
                    ->selectRaw("TRIM(CONCAT(COALESCE(names,''),' ',COALESCE(lastname,''))) n")->value('n')),
                'recorded_by_user_id' => $request->input('reviewed_by', Auth::id()),
                'amount'              => $proofAmount,
                'type'                => $invoice->paid ? 'pago_completo' : 'abono',
                'payment_method_id'   => $this->medioDelComprobante($proof),
                'notes'               => trim('Comprobante por WhatsApp'
                    . ($proof->reference_number ? ". Ref: {$proof->reference_number}" : '')),
            ]);
        }

        $proof->update([
            'status' => 'approved',
            'reviewed_by' => $request->input('reviewed_by', Auth::id() ?? null),
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        $this->audit($proof, $previous, 'approved', $request->input('reason', 'Aprobado por revisión manual.'), [
            'reviewed_by' => $request->input('reviewed_by', Auth::id() ?? null),
            'approved_amount' => $proofAmount,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Comprobante aprobado.',
            'data' => $proof->fresh(),
        ]);
    }

    public function reject(int $id, Request $request): JsonResponse
    {
        $proof = $this->findOwned($id);
        $previous = $proof->status;

        $proof->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason', 'Comprobante rechazado por inconsistencias.'),
            'reviewed_by' => $request->input('reviewed_by', Auth::id() ?? null),
            'reviewed_at' => now(),
        ]);

        $this->audit($proof, $previous, 'rejected', $request->input('reason', 'Comprobante rechazado por inconsistencias.'), [
            'reviewed_by' => $request->input('reviewed_by', Auth::id() ?? null),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Comprobante rechazado.',
            'data' => $proof->fresh(),
        ]);
    }

    public function revert(int $id, Request $request): JsonResponse
    {
        $proof = $this->findOwned($id);
        $previous = $proof->status;

        $invoice = $proof->invoice;
        if ($invoice) {
            $proofAmount = (float) ($proof->reported_amount ?? $proof->detected_amount ?? 0);
            $currentAbone = (float) ($invoice->price_abone ?? 0);
            $invoice->price_abone = max(0, $currentAbone - $proofAmount);
            $invoice->paid = $invoice->price_abone >= max(0, ((float) ($invoice->price_total ?? 0) - (float) ($invoice->price_discount ?? 0))) ? 1 : 0;
            $invoice->abone = $invoice->paid ? 1 : 0;
            $invoice->paid_at = $invoice->paid ? now() : null;
            $invoice->save();
        }

        $proof->update([
            'status' => 'reverted',
            'rejection_reason' => $request->input('reason', 'Pago revertido por revisión humana.'),
            'reviewed_by' => $request->input('reviewed_by', Auth::id() ?? null),
            'reviewed_at' => now(),
        ]);

        $this->audit($proof, $previous, 'reverted', $request->input('reason', 'Pago revertido por revisión humana.'), [
            'reviewed_by' => $request->input('reviewed_by', Auth::id() ?? null),
            'reverted_amount' => $proof->reported_amount ?? $proof->detected_amount ?? 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pago revertido.',
            'data' => $proof->fresh(),
        ]);
    }

    private function audit(PaymentProof $proof, string $oldStatus, string $newStatus, string $reason, array $metadata = []): void
    {
        PaymentProofAudit::create([
            'payment_proof_id' => $proof->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $metadata['reviewed_by'] ?? null,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
