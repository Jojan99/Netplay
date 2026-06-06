<?php

namespace App\Http\Controllers\Client;

use App\Constants\ApiResponseConstants;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Facturas del cliente autenticado en el portal.
 * Solo devuelve facturas que pertenezcan al propio cliente (user_id del JWT).
 */
class ClientInvoiceController extends Controller
{
    /**
     * GET /api/client/invoices
     * Lista todas las facturas del cliente con estado calculado.
     */
    public function index(Request $request): JsonResponse
    {
        $user = JWTAuth::user();

        // Buscar el cab_facturacion del usuario en esta empresa
        $cab = DB::table('cab_facturations')
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->first();

        if (!$cab) {
            return response()->json([
                'message' => 'No se encontraron registros de facturación',
                'data'    => [],
                'status'  => ApiResponseConstants::SUCCESS,
            ], JsonResponse::HTTP_OK);
        }

        $today = now()->toDateString();

        $invoices = DB::table('det_facturations')
            ->where('cab_id', $cab->id)
            ->orderByDesc('date_facturation')
            ->get()
            ->map(function ($invoice) use ($today) {
                if ($invoice->paid) {
                    $invoice->invoice_status = 'paid';
                } elseif ($invoice->date_facturation < $today) {
                    $invoice->invoice_status = 'overdue';
                } else {
                    $invoice->invoice_status = 'pending';
                }
                $invoice->payment_gateway = null;
                $invoice->payment_sandbox = null;
                return $invoice;
            });

        // Enriquecer con info de pago online (una sola query para evitar N+1)
        $invoiceIds = $invoices->pluck('id')->all();
        if (!empty($invoiceIds)) {
            $txMap = [];
            DB::table('online_payment_transactions')
                ->whereIn('det_facturation_id', $invoiceIds)
                ->where('status', 'approved')
                ->get(['det_facturation_id', 'gateway', 'sandbox'])
                ->each(function ($tx) use (&$txMap) { $txMap[$tx->det_facturation_id] = $tx; });

            $invoices = $invoices->map(function ($inv) use ($txMap) {
                if (isset($txMap[$inv->id])) {
                    $inv->payment_gateway = $txMap[$inv->id]->gateway;
                    $inv->payment_sandbox = (bool) $txMap[$inv->id]->sandbox;
                }
                return $inv;
            });
        }

        // Resumen de estado
        $summary = [
            'total'   => $invoices->count(),
            'paid'    => $invoices->where('invoice_status', 'paid')->count(),
            'pending' => $invoices->where('invoice_status', 'pending')->count(),
            'overdue' => $invoices->where('invoice_status', 'overdue')->count(),
        ];

        // Indicar si el pago en línea está disponible para esta empresa
        $company = Company::find($user->company_id);
        $paymentAvailable = $company && $company->pg_active && $company->pg_gateway;

        return response()->json([
            'message' => 'Facturas obtenidas correctamente',
            'data'    => [
                'summary'           => $summary,
                'invoices'          => $invoices->values(),
                'payment_available' => (bool) $paymentAvailable,
            ],
            'status' => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * GET /api/client/invoices/{id}
     * Detalle de una factura específica (solo si pertenece al cliente).
     */
    public function show(int $id): JsonResponse
    {
        $user = JWTAuth::user();

        $invoice = DB::table('det_facturations as df')
            ->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
            ->where('df.id', $id)
            ->where('cf.user_id', $user->id)
            ->where('cf.company_id', $user->company_id)
            ->select('df.*', 'cf.user_id', 'cf.company_id')
            ->first();

        if (!$invoice) {
            return response()->json([
                'message' => 'Factura no encontrada',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $today = now()->toDateString();
        if ($invoice->paid) {
            $invoice->invoice_status = 'paid';
        } elseif ($invoice->date_facturation < $today) {
            $invoice->invoice_status = 'overdue';
        } else {
            $invoice->invoice_status = 'pending';
        }

        // Info de pago online si existe
        $invoice->payment_gateway       = null;
        $invoice->payment_reference     = null;
        $invoice->payment_sandbox       = null;
        $invoice->payment_gateway_tx_id = null;
        $invoice->payment_date          = null;

        $onlineTx = DB::table('online_payment_transactions')
            ->where('det_facturation_id', $id)
            ->where('status', 'approved')
            ->first(['gateway', 'reference', 'sandbox', 'paid_at', 'gateway_transaction_id']);

        if ($onlineTx) {
            $invoice->payment_gateway       = $onlineTx->gateway;
            $invoice->payment_reference     = $onlineTx->reference;
            $invoice->payment_sandbox       = (bool) $onlineTx->sandbox;
            $invoice->payment_gateway_tx_id = $onlineTx->gateway_transaction_id;
            $invoice->payment_date          = $onlineTx->paid_at;
        }

        return response()->json([
            'message' => 'OK',
            'data'    => $invoice,
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * GET /api/client/invoices/{id}/pdf-url
     * Devuelve la URL del PDF de una factura (reutiliza endpoint existente).
     * El frontend usará esta URL para descargar directamente.
     */
    public function pdfUrl(int $id): JsonResponse
    {
        $user = JWTAuth::user();

        // Verificar que la factura pertenece al cliente y obtener number_facture
        $invoice = DB::table('det_facturations as df')
            ->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
            ->where('df.id', $id)
            ->where('cf.user_id', $user->id)
            ->where('cf.company_id', $user->company_id)
            ->select('df.id', 'df.number_facture')
            ->first();

        if (!$invoice) {
            return response()->json([
                'message' => 'Factura no encontrada',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        // generatePdfbyId acepta number_facture (ej: NT9607) y es público (sin JWT).
        // Funciona para facturas pagadas y pendientes por igual.
        $pdfEndpoint = url('/api/generatePdf/generatePdfbyId/' . $invoice->number_facture);

        return response()->json([
            'message' => 'URL generada',
            'data'    => ['pdf_url' => $pdfEndpoint],
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/client/invoices/{id}/send-whatsapp
     * Envía la factura al número de WhatsApp del cliente.
     * Reutiliza el endpoint existente de GeneratePdfController.
     */
    public function sendWhatsapp(int $id): JsonResponse
    {
        return $this->forwardToGeneratePdf($id, 'sendInvoiceByWhatsApp');
    }

    /**
     * POST /api/client/invoices/{id}/send-email
     * Envía la factura por correo electrónico del cliente.
     */
    public function sendEmail(int $id): JsonResponse
    {
        return $this->forwardToGeneratePdf($id, 'sendInvoiceByEmail');
    }

    /**
     * POST /api/client/invoices/{id}/send
     * Envía la factura por el canal especificado (whatsapp, email, both).
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $channel = $request->query('channel', 'whatsapp');

        if (!in_array($channel, ['whatsapp', 'email', 'both'])) {
            return response()->json([
                'message' => "Canal '{$channel}' no válido. Use: whatsapp, email o both",
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $user = JWTAuth::user();

        // Verificar propiedad de la factura
        $invoice = DB::table('det_facturations as df')
            ->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
            ->where('df.id', $id)
            ->where('cf.user_id', $user->id)
            ->where('cf.company_id', $user->company_id)
            ->select('df.id')
            ->first();

        if (!$invoice) {
            return response()->json([
                'message' => 'Factura no encontrada',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $internalRequest = Request::create(
            '/api/generatePdf/sendInvoice/' . $id . '?channel=' . $channel,
            'POST',
            [],
            [],
            [],
            ['HTTP_Authorization' => request()->header('Authorization')]
        );

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($internalRequest);

        $body = json_decode($response->getContent(), true);

        return response()->json([
            'message' => $body['message'] ?? 'Factura enviada',
            'data'    => $body['data'] ?? null,
            'status'  => $body['status'] ?? ApiResponseConstants::SUCCESS,
        ], $response->getStatusCode());
    }

    /**
     * Helper: reenvía la petición al GeneratePdfController internamente.
     */
    private function forwardToGeneratePdf(int $id, string $action): JsonResponse
    {
        $user = JWTAuth::user();

        // Verificar propiedad de la factura
        $invoice = DB::table('det_facturations as df')
            ->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
            ->where('df.id', $id)
            ->where('cf.user_id', $user->id)
            ->where('cf.company_id', $user->company_id)
            ->select('df.id')
            ->first();

        if (!$invoice) {
            return response()->json([
                'message' => 'Factura no encontrada',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $internalRequest = Request::create(
            '/api/generatePdf/' . $action . '/' . $id,
            'POST',
            [],
            [],
            [],
            ['HTTP_Authorization' => request()->header('Authorization')]
        );

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($internalRequest);

        $body = json_decode($response->getContent(), true);

        return response()->json([
            'message' => $body['message'] ?? 'Factura enviada',
            'data'    => $body['data'] ?? null,
            'status'  => $body['status'] ?? ApiResponseConstants::SUCCESS,
        ], $response->getStatusCode());
    }
}
