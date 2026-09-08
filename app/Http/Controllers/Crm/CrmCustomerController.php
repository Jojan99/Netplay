<?php

namespace App\Http\Controllers\Crm;

use App\Events\NewMessageEvent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\InvoiceLinkController;
use App\Models\Company;
use App\Models\CrmMessage;
use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\Resources\Templates\TemplatesPdf;
use App\Services\PaymentGateways\PaymentLinkService;
use App\Services\WhatsAppService;
use App\UseCases\Crm\Interfaces\SendMessageUseCaseInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ficha del cliente dentro del chat: cruza la conversación (crm_customers.phone) con el
 * cliente del ISP (user_data.phone, últimos 10 dígitos) para mostrar plan, deuda, facturas,
 * ticket abierto e historial, y para enviar la factura o un link de pago desde el chat.
 */
class CrmCustomerController extends Controller
{
    /* ── Resolución conversación → cliente ISP ───────────────────────── */
    private function conversation(int $conversationId): ?object
    {
        return DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.id', $conversationId)
            ->first(['c.id', 'c.company_id', 'c.provider', 'c.customer_id', 'c.status', 'cu.phone', 'cu.name as customer_name']);
    }

    private function ispUser(string $phone, int $companyId): ?object
    {
        $clean  = preg_replace('/[^0-9]/', '', $phone);
        $last10 = substr($clean, -10);
        if (strlen($last10) < 7) return null;

        return DB::table('user_data as ud')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'ud.status_internet_id')
            ->leftJoin('tabla_ips as tip', 'tip.id', '=', 'ud.ip_assignment_id')
            ->where('ud.company_id', $companyId)
            ->where(fn($q) => $q->where('ud.phone', 'like', '%' . $last10)->orWhere('ud.phone', $clean))
            ->orderByDesc('ud.id')
            ->first([
                'ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.address', 'ud.email', 'ud.phone',
                'tip.ip', 'tip.mac',
                DB::raw("COALESCE(ip.plan_name, 'Sin plan') as plan_name"),
                DB::raw("COALESCE(ip.monthly_price, 0) as monthly_price"),
                DB::raw("COALESCE(ist.name, 'Desconocido') as service_status"),
            ]);
    }

    private function pendingInvoices(int $userId, int $companyId, int $limit = 6)
    {
        return DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.user_id', $userId)->where('cab.company_id', $companyId)
            ->orderByDesc('d.id')
            ->limit($limit)
            ->get(['d.id', 'd.number_facture', 'd.date_facturation', 'd.price_total', 'd.price_discount', 'd.price_abone', 'd.paid', 'd.paid_at', 'd.created_at'])
            ->map(fn($d) => [
                'id'          => (int)$d->id,
                'number'      => $d->number_facture,
                'due_date'    => $d->date_facturation,
                'total'       => round((float)$d->price_total - (float)($d->price_discount ?? 0), 2),
                'outstanding' => $d->paid ? 0 : round(max(0, (float)$d->price_total - (float)($d->price_discount ?? 0) - (float)($d->price_abone ?? 0)), 2),
                'paid'        => (bool)$d->paid,
                'paid_at'     => $d->paid_at,
                'link'        => InvoiceLinkController::urlFor((int)$d->id),
            ]);
    }

    /* ── GET conversations/{id}/customer-summary ─────────────────────── */
    public function summary(int $conversationId): JsonResponse
    {
        $conv = $this->conversation($conversationId);
        if (!$conv) return response()->json(['ok' => false, 'error' => 'Conversación no encontrada'], 404);

        $company = Company::find($conv->company_id);
        $user    = $this->ispUser($conv->phone, (int)$conv->company_id);

        $previous = DB::table('crm_conversations')
            ->where('customer_id', $conv->customer_id)->where('id', '!=', $conv->id)->count();

        if (!$user) {
            return response()->json(['ok' => true, 'data' => [
                'linked' => false, 'phone' => $conv->phone, 'name' => $conv->customer_name,
                'previous_conversations' => $previous,
            ]]);
        }

        $invoices = $this->pendingInvoices((int)$user->user_id, (int)$conv->company_id);
        $unpaid   = $invoices->where('paid', false);
        $debtAll  = DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.user_id', $user->user_id)->where('cab.company_id', $conv->company_id)->where('d.paid', 0)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(GREATEST(0, d.price_total - COALESCE(d.price_discount,0) - COALESCE(d.price_abone,0))),0) as total, MIN(d.date_facturation) as oldest')
            ->first();

        $ticket = DB::table('tickets as t')
            ->leftJoin('ticket_status as ts', 'ts.id', '=', 't.status_id')
            ->leftJoin('ticket_type_services as sv', 'sv.id', '=', 't.service_id')
            ->where('t.user_id', $user->user_id)->where('t.company_id', $conv->company_id)
            ->where('t.status_id', '!=', 3)
            ->orderByDesc('t.id')
            ->first(['t.id', 't.observation', 't.created_at', 'ts.name as status', 'sv.name as service']);

        return response()->json(['ok' => true, 'data' => [
            'linked'         => true,
            'user_id'        => (int)$user->user_id,
            'name'           => trim($user->names . ' ' . $user->lastname),
            'dni'            => $user->dni,
            'phone'          => $user->phone,
            'email'          => $user->email,
            'address'        => $user->address,
            'plan'           => $user->plan_name,
            'plan_price'     => (float)$user->monthly_price,
            'ip'             => $user->ip,
            'mac'            => $user->mac,
            'service_status' => $user->service_status,
            'debt'           => ['total' => round((float)$debtAll->total, 2), 'count' => (int)$debtAll->n, 'oldest_due' => $debtAll->oldest],
            'invoices'       => $invoices->values(),
            'last_unpaid'    => $unpaid->first(),
            'open_ticket'    => $ticket ? ['id' => (int)$ticket->id, 'observation' => $ticket->observation, 'status' => $ticket->status, 'service' => $ticket->service, 'created_at' => $ticket->created_at] : null,
            'previous_conversations' => $previous,
            'pay_link_available'     => (bool)($company?->pg_active && $company?->pg_gateway),
            'invoice_whatsapp_enabled' => (bool)($company?->invoice_whatsapp_enabled),
        ]]);
    }

    /* ── GET conversations/{id}/history ──────────────────────────────── */
    public function history(int $conversationId): JsonResponse
    {
        $conv = $this->conversation($conversationId);
        if (!$conv) return response()->json(['ok' => false, 'error' => 'Conversación no encontrada'], 404);

        $rows = DB::table('crm_conversations as c')
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'c.assigned_user_id')
            ->where('c.customer_id', $conv->customer_id)->where('c.id', '!=', $conv->id)
            ->orderByDesc('c.id')->limit(30)
            ->get(['c.id', 'c.status', 'c.provider', 'c.created_at', 'c.updated_at', 'c.last_message_at', DB::raw("TRIM(CONCAT(COALESCE(ud.names,''),' ',COALESCE(ud.lastname,''))) as agent")]);

        $ids = $rows->pluck('id')->all();
        $counts = $ids ? DB::table('crm_messages')->whereIn('conversation_id', $ids)->groupBy('conversation_id')->selectRaw('conversation_id, COUNT(*) as n')->pluck('n', 'conversation_id') : collect();
        $lasts  = $ids ? DB::table('crm_messages as m')
            ->whereIn('m.conversation_id', $ids)
            ->whereRaw('m.id = (SELECT MAX(id) FROM crm_messages WHERE conversation_id = m.conversation_id)')
            ->get(['m.conversation_id', 'm.content', 'm.message_type'])->keyBy('conversation_id') : collect();

        return response()->json(['ok' => true, 'data' => $rows->map(fn($r) => [
            'id' => (int)$r->id, 'status' => $r->status, 'provider' => $r->provider, 'agent' => $r->agent ?: null,
            'created_at' => $r->created_at, 'closed_at' => $r->status === 'closed' ? $r->updated_at : null,
            'messages' => (int)($counts[$r->id] ?? 0),
            'last_message' => $lasts[$r->id]->content ?? ($lasts[$r->id]->message_type ?? null),
        ])->values()]);
    }

    /* ── POST conversations/{id}/send-invoice  { invoice_id } ─────────── */
    public function sendInvoice(int $conversationId, Request $request, GeneratePdfRepositoryInterface $pdfRepo, TemplatesPdf $templatesPdf, ConversationRepositoryInterface $repo): JsonResponse
    {
        $request->validate(['invoice_id' => 'required|integer']);
        $conv = $this->conversation($conversationId);
        if (!$conv) return response()->json(['ok' => false, 'error' => 'Conversación no encontrada'], 404);

        $user = $this->ispUser($conv->phone, (int)$conv->company_id);
        $owns = $user && DB::table('det_facturations as d')->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('d.id', $request->invoice_id)->where('cab.user_id', $user->user_id)->where('cab.company_id', $conv->company_id)->exists();
        if (!$owns) return response()->json(['ok' => false, 'error' => 'La factura no pertenece a este cliente'], 422);

        // El repositorio de PDF busca por número de factura, no por id
        $number = DB::table('det_facturations')->where('id', $request->invoice_id)->value('number_facture');
        $data = $number ? $pdfRepo->generatePdfById($number) : null;
        if (!$data) return response()->json(['ok' => false, 'error' => 'Factura no encontrada'], 404);

        try {
            $saldoAnt = $pdfRepo->getSaldoAnt($data['id'], $data['number_facture']) ?? 0;
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $pdf = new Dompdf($options);
            $pdf->loadHtml($templatesPdf->PdfFacturas($data, $saldoAnt));
            $pdf->render();
            $base64Pdf = base64_encode($pdf->output());

            $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$data['number_facture']) . '.pdf';
            $total    = number_format(($data['price_total'] ?? 0) - ($data['price_discount'] ?? 0), 0, '.', ',');
            $caption  = "Factura #{$data['number_facture']} · Total: \${$total} · Vence: {$data['date_facturation']}";

            $wa = new WhatsAppService((int)$conv->company_id, false, $conv->provider ?? 'netplay');
            $result = $wa->sendDocumentData($conv->phone, $base64Pdf, $filename, $caption);
            $failed = !is_array($result) || (($result['status'] ?? null) === 'error') || (isset($result['success']) && $result['success'] === false);
            if ($failed) {
                return response()->json(['ok' => false, 'error' => $result['error'] ?? $result['message'] ?? 'WhatsApp no aceptó el envío'], 502);
            }

            // Se refleja en el chat como documento (el PDF queda accesible por el link firmado)
            $message = $repo->storeMessage([
                'conversation_id' => $conv->id,
                'sender_type'     => 'agent',
                'sender_user_id'  => getSessionUserId(),
                'message_type'    => 'document',
                'content'         => $filename,
                'media_url'       => InvoiceLinkController::urlFor((int)$request->invoice_id),
                'mime_type'       => 'application/pdf',
                'extension'       => 'pdf',
                'original_name'   => $filename,
                'external_id'     => $result['messageId'] ?? ($result['messages'][0]['id'] ?? null),
                'status'          => 'sent',
            ]);
            $message->refresh();
            broadcast(new NewMessageEvent($message, (int)$conv->id));

            return response()->json(['ok' => true, 'data' => $message->toArray()]);
        } catch (\Throwable $e) {
            Log::error('[CRM sendInvoice]', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'No se pudo generar o enviar la factura'], 500);
        }
    }

    /* ── POST conversations/{id}/pay-link  { invoice_id? } ────────────── */
    public function payLink(int $conversationId, Request $request, PaymentLinkService $links, SendMessageUseCaseInterface $sendText): JsonResponse
    {
        $conv = $this->conversation($conversationId);
        if (!$conv) return response()->json(['ok' => false, 'error' => 'Conversación no encontrada'], 404);

        $company = Company::find($conv->company_id);
        if (!$company || !$company->pg_active || !$company->pg_gateway) {
            return response()->json(['ok' => false, 'error' => 'La empresa no tiene pasarela de pago activa'], 422);
        }
        $user = $this->ispUser($conv->phone, (int)$conv->company_id);
        if (!$user) return response()->json(['ok' => false, 'error' => 'Este número no está vinculado a un cliente'], 422);

        $invoiceIds = $request->filled('invoice_id') ? [(int)$request->invoice_id] : null;
        try {
            $link = $links->create($company, (int)$user->user_id, $invoiceIds, 'crm', null, $conv->phone);
            $url  = $links->publicUrl($link);
        } catch (\Throwable $e) {
            Log::error('[CRM payLink]', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'No se pudo generar el link de pago'], 502);
        }

        $text = $request->input('message') ?: "Podés pagar en línea desde este enlace seguro:\n{$url}";
        if ($request->boolean('send', true)) {
            $sendText->execute((int)$conv->id, $text, getSessionUserId());
        }

        return response()->json(['ok' => true, 'data' => ['url' => $url, 'expires_at' => $link->expires_at]]);
    }

    /* ── POST conversations/{id}/tech-note: guarda el estado técnico como nota interna ── */
    public function techNote(int $conversationId, ConversationRepositoryInterface $repo): JsonResponse
    {
        $conv = $this->conversation($conversationId);
        if (!$conv) return response()->json(['ok' => false, 'error' => 'Conversación no encontrada'], 404);
        $user = $this->ispUser($conv->phone, (int)$conv->company_id);
        if (!$user) return response()->json(['ok' => false, 'error' => 'Este número no está vinculado a un cliente'], 422);

        $content = sprintf("Estado técnico (%s)\nCliente: %s\nPlan: %s\nIP: %s\nEstado: %s\nDirección: %s",
            now()->format('d/m/Y H:i'), trim($user->names . ' ' . $user->lastname), $user->plan_name, $user->ip ?: '—', $user->service_status, $user->address ?: '—');

        $noteId = DB::table('crm_notes')->insertGetId([
            'conversation_id' => $conv->id, 'user_id' => getSessionUserId(), 'content' => $content,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'data' => ['id' => $noteId, 'content' => $content]]);
    }
}
