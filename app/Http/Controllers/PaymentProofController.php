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
    public function index(Request $request): JsonResponse
    {
        $query = PaymentProof::with(['user', 'invoice', 'audits'])->orderByDesc('created_at');

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
        $proof = PaymentProof::with(['user', 'invoice', 'audits'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $proof,
        ]);
    }

    public function markSuspicious(int $id, Request $request): JsonResponse
    {
        $proof = PaymentProof::findOrFail($id);
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

    public function approve(int $id, Request $request): JsonResponse
    {
        $proof = PaymentProof::findOrFail($id);
        $previous = $proof->status;

        $invoice = $proof->invoice;
        $proofAmount = (float) ($proof->reported_amount ?? $proof->detected_amount ?? 0);

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
        $proof = PaymentProof::findOrFail($id);
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
        $proof = PaymentProof::findOrFail($id);
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
