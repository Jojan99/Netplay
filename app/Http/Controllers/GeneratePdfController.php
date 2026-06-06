<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Models\Company;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\Resources\Templates\TemplatesPdf;
use App\Services\WhatsAppService;
use App\Services\InvoiceEmailService;
use App\UseCases\GeneratePdf\GeneratePdfUseCase;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdFacturesUseCaseInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\GeneratePdf\GeneratePdfDataRequest;
use App\UseCases\GeneratePdf\Interfaces\GeneratePayPdfByIdFacturesUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfTicketByIdUseCaseInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class GeneratePdfController extends Controller
{

    /**
     * POST /api/generatePdf/generatePdf
     * Genera y envía facturas masivamente.
     *
     * Body: { ids: [...], company_id?: number, billing_day?: number, channel?: 'whatsapp'|'email'|'both' }
     */
    public function generatePdf(
        GeneratePdfUseCaseInterface $generatePdfUseCaseInterface,
        Request $request
    ): object {
        try {
            $companyId  = (int) $request->input('company_id', 0);
            $billingDay = (int) $request->input('billing_day', 0);
            $channel    = $request->input('channel', 'whatsapp');
            $ids        = $request->input('ids', [3]);

            // Validar canal permitido
            if (!in_array($channel, ['whatsapp', 'email', 'both'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Canal '{$channel}' no válido. Use: whatsapp, email o both"
                ], 400);
            }

            $response = $generatePdfUseCaseInterface->generatePdf($ids, $companyId, $billingDay, $channel);

            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }

            return response()->json($response, $response['status'] == 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (JWTException $e) {
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * GET /api/generatePdf/generatePdfbyId/{userid}
     */
    public function generatePdfbyId(
        GeneratePdfByIdFacturesUseCaseInterface $generatePdfByIdFacturesUseCaseInterface,
        $user_id
    ): object {
        try {
            $response = $generatePdfByIdFacturesUseCaseInterface->generatePdfByIdFacture($user_id);

            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }

            return response()->json($response, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (JWTException $e) {
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $response['message'],
            $response['data'],
            $response['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * GET /api/generatePdf/generatePdfTicketbyId/{id}
     */
    public function generatePdfTicketbyId(
        GeneratePdfTicketByIdUseCaseInterface $generatePdfTicketByIdUseCaseInterface,
        $user_id
    ): object {
        try {
            $response = $generatePdfTicketByIdUseCaseInterface->generatePdfTicketbyId($user_id);

            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }

            return response()->json($response, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (JWTException $e) {
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $response['message'],
            $response['data'],
            $response['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * GET /api/generatePdf/generatePaidPdfbyId/{idFacture}
     */
    public function generatePaidPdfbyId(
        GeneratePayPdfByIdFacturesUseCaseInterface $generatePayPdfByIdFacturesUseCaseInterface,
        $id_facture, Request $request
    ): object {
        try {
            $extraParam = $request->query('extraParam');

            $response = $generatePayPdfByIdFacturesUseCaseInterface->generatePayPdfByIdFacture($id_facture, $extraParam);

            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }

            return response()->json($response, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (JWTException $e) {
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $response;
    }

    /**
     * POST /api/generatePdf/sendInvoiceByWhatsApp/{invoiceId}
     * Genera el PDF de factura y lo envía por WhatsApp.
     */
    public function sendInvoiceByWhatsApp(
        GeneratePdfRepositoryInterface $pdfRepo,
        TemplatesPdf $templatesPdf,
        string $invoiceId
    ): JsonResponse {
        try {
            $data = $pdfRepo->generatePdfById($invoiceId);

            if (!$data) {
                return response()->json(['status' => 'error', 'message' => 'Factura no encontrada'], 404);
            }

            // Verificar que la empresa tiene habilitado WhatsApp
            $company = Company::find($data['company_id'] ?? 0);
            if ($company && !$company->whatsapp_enabled) {
                return response()->json(['status' => 'error', 'message' => 'El envío por WhatsApp está deshabilitado para esta empresa', 'error_code' => 'WA_DISABLED'], 403);
            }

            $phone = trim($data['phone'] ?? '');
            if (empty($phone)) {
                $this->logSend($data['id'], 'whatsapp', 'error', 'El cliente no tiene teléfono registrado', null, null);
                return response()->json(['status' => 'error', 'message' => 'El cliente no tiene teléfono registrado', 'error_code' => 'NO_PHONE'], 422);
            }

            $saldoAnt = $pdfRepo->getSaldoAnt($data['id'], $data['number_facture']) ?? 0;

            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $pdf = new Dompdf($options);
            $pdf->loadHtml($templatesPdf->PdfFacturas($data, $saldoAnt));
            $pdf->render();
            $pdfContent = $pdf->output();
            $base64Pdf  = base64_encode($pdfContent);

            $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['number_facture']) . '_' . $data['dni'] . '.pdf';
            $names    = $data['names'] . ' ' . $data['lastname'];
            $caption  = "Estimado/a {$names}, adjuntamos su factura #{$data['number_facture']}.\n\nTotal a pagar: $" . number_format($data['price_total'] - $data['price_discount'], 0, '.', ',') . "\n\nFecha límite: {$data['date_facturation']}";

            $whatsapp = new WhatsAppService();
            $whatsapp->sendDocumentData($phone, $base64Pdf, $filename, $caption);

            $this->logSend($data['id'], 'whatsapp', 'ok', "Factura enviada por WhatsApp al número {$phone}", $phone, null);

            return response()->json([
                'status'  => 'ok',
                'message' => "Factura enviada por WhatsApp al número {$phone}",
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/generatePdf/sendInvoiceByEmail/{invoiceId}
     * Genera el PDF de factura y lo envía por correo electrónico con Mailjet.
     */
    public function sendInvoiceByEmail(
        GeneratePdfRepositoryInterface $pdfRepo,
        TemplatesPdf $templatesPdf,
        string $invoiceId
    ): JsonResponse {
        try {
            $data = $pdfRepo->generatePdfById($invoiceId);

            if (!$data) {
                return response()->json(['status' => 'error', 'message' => 'Factura no encontrada'], 404);
            }

            // Verificar que la empresa tiene habilitado Email
            $company = Company::find($data['company_id'] ?? 0);
            if ($company && !$company->email_enabled) {
                return response()->json(['status' => 'error', 'message' => 'El envío por correo está deshabilitado para esta empresa', 'error_code' => 'EMAIL_DISABLED'], 403);
            }

            $email = trim($data['email'] ?? '');
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->logSend($data['id'], 'email', 'error', 'El cliente no tiene correo válido registrado', null, $email ?: null);
                return response()->json(['status' => 'error', 'message' => 'El cliente no tiene correo válido registrado', 'error_code' => 'NO_EMAIL'], 422);
            }

            $saldoAnt = $pdfRepo->getSaldoAnt($data['id'], $data['number_facture']) ?? 0;

            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $pdf = new Dompdf($options);
            $pdf->loadHtml($templatesPdf->PdfFacturas($data, $saldoAnt));
            $pdf->render();
            $pdfContent = $pdf->output();

            $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['number_facture']) . '_' . $data['dni'] . '.pdf';

            $emailService = new InvoiceEmailService();
            $result = $emailService->sendInvoice($data, $pdfContent, $filename);

            $statusCode = $result['status'] === 'ok' ? 200 : 500;
            $this->logSend(
                $data['id'],
                'email',
                $result['status'] === 'ok' ? 'ok' : 'error',
                $result['message'],
                null,
                $email
            );

            return response()->json($result, $statusCode);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/generatePdf/sendInvoice/{invoiceId}
     * Envía factura por el canal especificado (whatsapp, email o both).
     *
     * Query param: channel=whatsapp|email|both (default: whatsapp)
     */
    public function sendInvoice(
        GeneratePdfRepositoryInterface $pdfRepo,
        TemplatesPdf $templatesPdf,
        Request $request,
        string $invoiceId
    ): JsonResponse {
        $channel = $request->query('channel', 'whatsapp');

        if (!in_array($channel, ['whatsapp', 'email', 'both'])) {
            return response()->json([
                'status' => 'error',
                'message' => "Canal '{$channel}' no válido. Use: whatsapp, email o both"
            ], 400);
        }

        $results = [];
        $data = $pdfRepo->generatePdfById($invoiceId);
        if (!$data) {
            return response()->json(['status' => 'error', 'message' => 'Factura no encontrada'], 404);
        }

        if (in_array($channel, ['whatsapp', 'both'])) {
            $waResponse = $this->sendInvoiceByWhatsApp($pdfRepo, $templatesPdf, $invoiceId);
            $results['whatsapp'] = json_decode($waResponse->getContent(), true);
        }

        if (in_array($channel, ['email', 'both'])) {
            $emailResponse = $this->sendInvoiceByEmail($pdfRepo, $templatesPdf, $invoiceId);
            $results['email'] = json_decode($emailResponse->getContent(), true);
        }

        // Determinar status general
        $allOk = collect($results)->every(fn($r) => ($r['status'] ?? 'error') === 'ok');
        $partial = collect($results)->some(fn($r) => ($r['status'] ?? 'error') === 'ok') && !$allOk;

        // Log unified
        $details = json_encode($results);
        $message = $allOk ? 'Factura enviada correctamente' : ($partial ? 'Envío parcial: algunos canales fallaron' : 'Error al enviar factura');
        $this->logSend(
            $data['id'],
            $channel,
            $allOk ? 'ok' : ($partial ? 'partial' : 'error'),
            $message,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $details
        );

        return response()->json([
            'status' => $allOk ? 'ok' : ($partial ? 'partial_error' : 'error'),
            'message' => $message,
            'results' => $results,
        ], $allOk ? 200 : ($partial ? 207 : 500));
    }

    /**
     * GET /api/generatePdf/sendHistory/{invoiceId}
     * Obtiene el historial de envíos de una factura.
     */
    public function sendHistory(string $invoiceId): JsonResponse
    {
        $data = DB::table('det_facturations')
            ->where('id', $invoiceId)
            ->orWhere('number_facture', $invoiceId)
            ->first(['id']);

        if (!$data) {
            return response()->json(['status' => 'error', 'message' => 'Factura no encontrada'], 404);
        }

        $logs = DB::table('invoice_send_logs')
            ->where('det_facturation_id', $data->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'status' => 'ok',
            'data' => $logs,
        ]);
    }

    /**
     * Helper: registra envío en invoice_send_logs.
     */
    private function logSend(
        int $detFacturationId,
        string $channel,
        string $status,
        string $message,
        ?string $sentToPhone = null,
        ?string $sentToEmail = null,
        ?string $details = null
    ): void {
        try {
            $user = Auth::user();
            DB::table('invoice_send_logs')->insert([
                'det_facturation_id' => $detFacturationId,
                'user_id' => $user ? $user->id : null,
                'channel' => $channel,
                'status' => $status,
                'message' => $message,
                'sent_to_phone' => $sentToPhone,
                'sent_to_email' => $sentToEmail,
                'details' => $details,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Silenciar error de logging para no interrumpir el envío
        }
    }
}
