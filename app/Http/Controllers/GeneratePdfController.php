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
     * Body: { ids: [...], billing_day?: number, channel?: 'whatsapp'|'email'|'both' }
     * La empresa sale de la sesión; un company_id en el body se ignora.
     */
    public function generatePdf(
        GeneratePdfUseCaseInterface $generatePdfUseCaseInterface,
        Request $request
    ): object {
        try {
            // Antes se tomaba del body: cualquiera podía disparar la facturación de otra empresa.
            $companyId  = (int) getSessionCompanyId();
            if (!$companyId) {
                return response()->json(['status' => 'error', 'message' => 'Sin empresa en sesión.'], 403);
            }
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
     * Empresa del operador conectado. Las facturas se buscan por número y los
     * números se repiten entre empresas: sin este filtro un operador podía bajar
     * o reenviar la factura de otra empresa con el mismo número.
     */
    private function empresaDelOperador(): ?int
    {
        $empresa = getSessionCompanyId() ?: (\Tymon\JWTAuth\Facades\JWTAuth::user()->company_id ?? null);

        return $empresa ? (int) $empresa : null;
    }

    /**
     * GET /api/generatePdf/generatePdfbyId/{userid}
     * Sólo panel. Clientes y WhatsApp usan el enlace firmado (InvoiceLinkController).
     */
    public function generatePdfbyId(
        GeneratePdfByIdFacturesUseCaseInterface $generatePdfByIdFacturesUseCaseInterface,
        $user_id
    ): object {
        try {
            $empresa = $this->empresaDelOperador();
            if (!$empresa) {
                return response()->json(['message' => 'Factura no encontrada', 'status' => 1], 404);
            }

            $response = $generatePdfByIdFacturesUseCaseInterface->generatePdfByIdFacture($user_id, $empresa);

            // El PDF y también el 404 JSON de "no encontrada" (antes salía como 500).
            if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
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

            if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
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

            if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
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
            $empresa = $this->empresaDelOperador();
            $data = $empresa ? $pdfRepo->generatePdfById($invoiceId, $empresa) : null;

            if (!$data) {
                return response()->json(['status' => 'error', 'message' => 'Factura no encontrada'], 404);
            }

            // Verificar que la empresa tiene habilitado el envío de facturas por WhatsApp
            // Antes esto se saltaba en silencio: company_id no venía en la
            // consulta, Company::find(0) daba null y la condición nunca se
            // evaluaba. Se resuelve también por la sesión.
            $company = Company::find(($data['company_id'] ?? 0) ?: $empresa);
            if ($company && !$company->invoice_whatsapp_enabled) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'El envío de facturas por WhatsApp está apagado. Se activa en Configuración de facturación → Plantilla de factura.',
                    'error_code' => 'WA_DISABLED',
                ], 403);
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
            $pdf->loadHtml($templatesPdf->PdfFacturas($data, $saldoAnt, (int) (($data['company_id'] ?? 0) ?: $empresa)));
            $pdf->render();
            $pdfContent = $pdf->output();
            $base64Pdf  = base64_encode($pdfContent);

            $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['number_facture']) . '_' . $data['dni'] . '.pdf';
            $names    = $data['names'] . ' ' . $data['lastname'];
            $caption  = "Estimado/a {$names}, adjuntamos su factura #{$data['number_facture']}.\n\nTotal a pagar: $" . number_format($data['price_total'] - $data['price_discount'], 0, '.', ',') . "\n\nFecha límite: {$data['date_facturation']}";

            // ── Por dónde sale y de qué forma ──────────────────────────
            //
            // Antes se instanciaba WhatsAppService sin canal, con lo que salía
            // por el proveedor por defecto de la empresa. Si ese es la API de
            // Meta y el cliente no escribió en las últimas 24 h, Meta rechaza
            // el envío; insistir fuera de ventana es lo que termina costando
            // el bloqueo de la línea.
            // La empresa sale de la factura y, si faltara, de la sesión: sin
            // ella no se puede decidir el canal y todo envío se rechazaba con
            // "la empresa no tiene línea vinculada".
            $companyId = (int) ($data['company_id'] ?? 0) ?: (int) $empresa;
            $decision  = (new \App\Services\WhatsApp\CanalDeEnvio($companyId))
                ->evaluar(request()->input('canal'), $phone);

            if ($decision['modo'] === \App\Services\WhatsApp\CanalDeEnvio::IMPOSIBLE) {
                $this->logSend($data['id'], 'whatsapp', 'error', $decision['motivo'], $phone, null);
                return response()->json([
                    'status' => 'error', 'message' => $decision['motivo'], 'error_code' => 'CANAL_NO_DISPONIBLE',
                ], 422);
            }

            $porPlantilla = $decision['modo'] === \App\Services\WhatsApp\CanalDeEnvio::PLANTILLA;

            if ($porPlantilla) {
                // Fuera de ventana el PDF no se puede mandar: Meta solo acepta
                // una plantilla aprobada, que además lleva el botón al enlace
                // firmado de la factura.
                $meta   = new \App\Services\MetaWhatsAppService($companyId);
                $vence  = $data['date_facturation'] ?? '';
                $total  = number_format(($data['price_total'] ?? 0) - ($data['price_discount'] ?? 0), 0, ',', '.');

                $parametros = [
                    $names ?: 'Cliente',
                    (string) ($data['number_facture'] ?? ''),
                    $total,
                    $data['date_create_facturation'] ?? now()->format('Y-m-d'),
                    $vence,
                    $company?->invoice_business_name ?: ($company?->name ?? ''),
                ];

                $valores = [];
                $botones = $meta->dynamicUrlButtons($meta->invoiceTemplateName(), 'es_CO');
                if ($botones !== []) {
                    // det_id es la factura; 'id' es la cabecera de facturación.
                    $token = \App\Http\Controllers\InvoiceLinkController::tokenFor((int) ($data['det_id'] ?? $data['id']));
                    foreach (array_keys($botones) as $indice) {
                        $valores[$indice] = $token;
                    }
                }

                $meta->sendInvoiceTemplate($phone, $parametros, $valores);
                $detalle = "Factura enviada por plantilla de Meta al número {$phone} (el cliente estaba fuera de la ventana de 24 h)";
            } else {
                (new WhatsAppService($companyId, true, $decision['canal']))
                    ->sendDocumentData($phone, $base64Pdf, $filename, $caption);

                $canalNombre = $decision['canal'] === \App\Services\WhatsApp\CanalDeEnvio::META ? 'API de Meta' : 'WhatsApp Web';
                $detalle = "Factura enviada por {$canalNombre} al número {$phone}";
            }

            $this->logSend($data['id'], 'whatsapp', 'ok', $detalle, $phone, null);

            return response()->json([
                'status'      => 'ok',
                'message'     => $detalle,
                'canal'       => $decision['canal'],
                'por_plantilla' => $porPlantilla,
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
            $empresa = $this->empresaDelOperador();
            $data = $empresa ? $pdfRepo->generatePdfById($invoiceId, $empresa) : null;

            if (!$data) {
                return response()->json(['status' => 'error', 'message' => 'Factura no encontrada'], 404);
            }

            // Verificar que la empresa tiene habilitado Email
            $company = Company::find(($data['company_id'] ?? 0) ?: $empresa);
            if (!$company) {
                return response()->json(['status' => 'error', 'message' => 'Factura no encontrada'], 404);
            }
            if (!$company->email_enabled) {
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
            $pdf->loadHtml($templatesPdf->PdfFacturas($data, $saldoAnt, (int) $company->id));
            $pdf->render();
            $pdfContent = $pdf->output();

            $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['number_facture']) . '_' . $data['dni'] . '.pdf';

            $emailService = new InvoiceEmailService($company);
            $result = $emailService->sendInvoice($data->toArray(), $pdfContent, $filename);

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
        $empresa = $this->empresaDelOperador();
        $data = $empresa ? $pdfRepo->generatePdfById($invoiceId, $empresa) : null;
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
     * GET /api/generatePdf/send-logs
     * Lista paginada de logs de envío de facturas con filtros.
     *
     * Query params:
     *  - channel: whatsapp|email|both
     *  - status: ok|error|partial
     *  - date_from: YYYY-MM-DD
     *  - date_to: YYYY-MM-DD
     *  - sent_to_email: string (partial match)
     *  - number_facture: string (partial match)
     *  - page: int
     *  - per_page: int (max 100)
     */
    public function sendLogs(Request $request): JsonResponse
    {
        $page     = max(1, (int) $request->query('page', 1));
        $perPage  = min(100, max(1, (int) $request->query('per_page', 25)));
        $channel  = $request->query('channel');
        $status   = $request->query('status');
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');
        $email    = $request->query('sent_to_email');
        $facture  = $request->query('number_facture');

        // Aislamiento por empresa: sin este filtro la pantalla de Envíos mostraba
        // los correos y teléfonos de los clientes de todas las empresas.
        $companyId = getSessionCompanyId();
        if (!$companyId) {
            return response()->json(['message' => 'Sin empresa en sesión.', 'data' => null, 'error' => 1], JsonResponse::HTTP_FORBIDDEN);
        }

        $query = DB::table('invoice_send_logs')
            ->select('invoice_send_logs.*', 'det_facturations.number_facture')
            ->leftJoin('det_facturations', 'det_facturations.id', '=', 'invoice_send_logs.det_facturation_id')
            ->where('invoice_send_logs.company_id', $companyId);

        if ($channel) {
            $query->where('invoice_send_logs.channel', $channel);
        }
        if ($status) {
            $query->where('invoice_send_logs.status', $status);
        }
        if ($dateFrom) {
            $query->whereDate('invoice_send_logs.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('invoice_send_logs.created_at', '<=', $dateTo);
        }
        if ($email) {
            $query->where('invoice_send_logs.sent_to_email', 'like', "%{$email}%");
        }
        if ($facture) {
            $query->where('det_facturations.number_facture', 'like', "%{$facture}%");
        }

        $total = $query->count();

        $logs = $query
            ->orderByDesc('invoice_send_logs.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'status' => 'ok',
            'data' => [
                'items'      => $logs,
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
                'last_page'  => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/generatePdf/sendHistory/{invoiceId}
     * Obtiene el historial de envíos de una factura.
     */
    public function sendHistory(string $invoiceId): JsonResponse
    {
        // Los números de factura son correlativos por empresa: sin filtrar por la
        // empresa en sesión se podía leer el historial de envíos de otra empresa.
        $companyId = getSessionCompanyId();

        $data = DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->where(function ($q) use ($invoiceId) {
                $q->where('d.id', $invoiceId)->orWhere('d.number_facture', $invoiceId);
            })
            ->when($companyId, fn($q) => $q->where('c.company_id', $companyId))
            ->first(['d.id']);

        if (!$data) {
            return response()->json(['status' => 'error', 'message' => 'Factura no encontrada'], 404);
        }

        $logs = DB::table('invoice_send_logs')
            ->where('det_facturation_id', $data->id)
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
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
            // La empresa se toma de la factura; si no está, de la sesión del usuario
            $companyId = DB::table('det_facturations as d')
                ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
                ->where('d.id', $detFacturationId)
                ->value('c.company_id') ?? ($user->company_id ?? getSessionCompanyId());

            DB::table('invoice_send_logs')->insert([
                'company_id' => $companyId,
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
