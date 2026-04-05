<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\Resources\Templates\TemplatesPdf;
use App\Services\WhatsAppService;
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
class GeneratePdfController extends Controller
{

  /**
     * @param GeneratePdfUseCaseInterface $generatePdfUseCaseInterface
     * @return object
     */
    public function generatePdf(
        GeneratePdfUseCaseInterface $generatePdfUseCaseInterface,
        GeneratePdfDataRequest $request
    ): object {
        try {

            
            $data = [
                'ids' => [3]
            ];

            $response = $generatePdfUseCaseInterface->generatePdf($data);

            // Verifica si la respuesta es un objeto Response
            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }
            // Si no es una respuesta HTTP, entonces asume que es una respuesta de error
            return response()->json($response, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
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
     * @param GeneratePdfUseCaseInterface $generatePdfUseCaseInterface
     * @param int id_user
     * @return object
     */
    public function generatePdfbyId(
        GeneratePdfByIdFacturesUseCaseInterface $generatePdfByIdFacturesUseCaseInterface,
        $user_id
    ): object {
        try {
            $response = $generatePdfByIdFacturesUseCaseInterface->generatePdfByIdFacture($user_id);

            // Verifica si la respuesta es un objeto Response
            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }
            // Si no es una respuesta HTTP, entonces asume que es una respuesta de error
            return response()->json($response, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
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
     * @param GeneratePdfTicketByIdUseCaseInterface $generatePdfTicketByIdUseCaseInterface
     * @param int id_user
     * @return object
     */
    public function generatePdfTicketbyId(
        GeneratePdfTicketByIdUseCaseInterface $generatePdfTicketByIdUseCaseInterface,
        $user_id
    ): object {
        try {
            $response = $generatePdfTicketByIdUseCaseInterface->generatePdfTicketbyId($user_id);

            // Verifica si la respuesta es un objeto Response
            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }
            // Si no es una respuesta HTTP, entonces asume que es una respuesta de error
            return response()->json($response, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
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

    public function generatePaidPdfbyId(
        GeneratePayPdfByIdFacturesUseCaseInterface $generatePayPdfByIdFacturesUseCaseInterface,
        $id_facture, Request $request
    ): object {
        try {


            $idFacture = $id_facture;

            // Acceder al parámetro de consulta 'extraParam'
            $extraParam = $request->query('extraParam');

            error_log(">>>>>>>>>>>>>>><<<<<".$extraParam);

            $response = $generatePayPdfByIdFacturesUseCaseInterface->generatePayPdfByIdFacture($id_facture,$extraParam);

            // Verifica si la respuesta es un objeto Response
            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }
            // Si no es una respuesta HTTP, entonces asume que es una respuesta de error
            return response()->json($response, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
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
     * Generates the invoice PDF and sends it to the client's phone via WhatsApp.
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

            $phone = trim($data['phone'] ?? '');
            if (empty($phone)) {
                return response()->json(['status' => 'error', 'message' => 'El cliente no tiene teléfono registrado'], 422);
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

            return response()->json([
                'status'  => 'ok',
                'message' => "Factura enviada por WhatsApp al número {$phone}",
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
