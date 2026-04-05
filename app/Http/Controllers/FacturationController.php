<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Constants\StatusConstants;
use App\Constants\TimeConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Http\Requests\Facturation\GetDateFacturePendingnRequest;
use App\UseCases\Facturation\Interfaces\CreateAboneFacturationUseCaseInterface;
use App\UseCases\Facturation\Interfaces\CreateDetFacturationUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDateFacturePendingUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDataInfoPenddingFactureUseCaseInterface;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\PaymentCommitment;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\Facturation\Interfaces\CreatePaidFacturationUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDatePayFactureUseCaseInterface;

class FacturationController extends Controller
{

    /**
     * @param CreateFacturationRequest $createFacturationRequest
     * @param CreateDetFacturationUseCaseInterface $createDetFacturationUseCaseInterface
     * @return object
     */
    public function updateDetFacturation(
        CreateFacturationRequest $createFacturationRequest,
        CreateDetFacturationUseCaseInterface $createDetFacturationUseCaseInterface
    ): object {
        try {
            $createDetFacturations = $createDetFacturationUseCaseInterface->updateDetFacturation($createFacturationRequest);
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
            $createDetFacturations['message'],
            $createDetFacturations['data'],
            $createDetFacturations['status'],
            JsonResponse::HTTP_OK
        );
    }

      /**
     * @param CreateFacturationRequest $createFacturationRequest
     * @param CreateDetFacturationUseCaseInterface $createDetFacturationUseCaseInterface
     * @return object
     */
    public function createDetFacturation(
        CreateFacturationRequest $createFacturationRequest,
        CreateDetFacturationUseCaseInterface $createDetFacturationUseCaseInterface
    ): object {
        try {
            $createDetFacturations = $createDetFacturationUseCaseInterface->createDetFacturation($createFacturationRequest);
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
            $createDetFacturations['message'],
            $createDetFacturations['data'],
            $createDetFacturations['status'],
            JsonResponse::HTTP_OK
        );
    }

       /**
     * @param GetDateFacturePendingUseCaseInterface $getDateFacturePendingUseCaseInterface
     * @return object
     */
    public function getDateFacturePending(
        GetDateFacturePendingnRequest $GetDateFacturePendingnRequest,
        GetDateFacturePendingUseCaseInterface $getDateFacturePendingUseCaseInterface
   
    ): object {
        try {
            $result = $getDateFacturePendingUseCaseInterface->getDateFacturePending($GetDateFacturePendingnRequest);
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
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }


        /**
     * @param GetDatePayFactureUseCaseInterface $getDatePayFactureUseCaseInterface
     * @return object
     */
    public function getDatePayFacture(
        GetDateFacturePendingnRequest $GetDateFacturePendingnRequest,
        GetDatePayFactureUseCaseInterface $getDatePayFactureUseCaseInterface
   
    ): object {
        try {
            $result = $getDatePayFactureUseCaseInterface->getDatePayFacture($GetDateFacturePendingnRequest);
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
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

         /**
     * @param GetDataInfoPenddingFactureUseCaseInterface $getDataInfoPenddingFactureUseCaseInterface
     * @return object
     */
    public function getDataInfoPenddingFacture(
        GetDataInfoPenddingFactureUseCaseInterface $getDataInfoPenddingFactureUseCaseInterface
    ): object {
        try {
            $result = $getDataInfoPenddingFactureUseCaseInterface->getDataInfoPenddingFacture();
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
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }


    /**
     * @param CreatePaidFacturationRequest $createPaidFacturationRequest
     * @param CreatePaidFacturationUseCaseInterface $createPaidFacturationUseCaseInterface
     * @return object
     */
    public function createpaidFacturation(
        CreatePaidFacturationRequest $createPaidFacturationRequest,
        CreatePaidFacturationUseCaseInterface $createPaidFacturationUseCaseInterface
    ): object {
        try {
            $result = $createPaidFacturationUseCaseInterface->createpaidFacturation($createPaidFacturationRequest);
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
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param CreatePaidFacturationRequest $createPaidFacturationRequest
     * @param CreateAboneFacturationUseCaseInterface $createAboneFacturationUseCaseInterface
     * @return object
     */
    public function createDiscountFacturation(
        CreatePaidFacturationRequest $createPaidFacturationRequest,
        CreateAboneFacturationUseCaseInterface $createAboneFacturationUseCaseInterface
    ): object {
        try {
            $createDetFacturations = $createAboneFacturationUseCaseInterface->createAboneFacturation($createPaidFacturationRequest);
        } catch (JWTException $e) {
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
        return standardApiReponse(
            $createDetFacturations['message'],
            $createDetFacturations['data'],
            $createDetFacturations['status'],
            JsonResponse::HTTP_OK
        );
    }

    // ── Finance v2 ──────────────────────────────────────────────────────────

    public function clientsPaginated(Request $request, FacturationRepositoryInterface $repo): object
    {
        $data = $repo->getClientsPaginated(
            $request->query('search'),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 15)
        );
        return standardApiReponse('OK', $data, 0, JsonResponse::HTTP_OK);
    }

    public function clientInvoices(int $cabId, FacturationRepositoryInterface $repo): object
    {
        $data = $repo->getClientInvoices($cabId);
        return standardApiReponse('OK', $data, 0, JsonResponse::HTTP_OK);
    }

    public function payInvoice(Request $request, int $detId, FacturationRepositoryInterface $repo): object
    {
        $ok = $repo->payInvoice($detId, $request->input('client_name', ''));
        return standardApiReponse($ok ? 'Pago registrado' : 'Factura no encontrada', null, $ok ? 0 : 1, JsonResponse::HTTP_OK);
    }

    public function abonarInvoice(Request $request, int $detId, FacturationRepositoryInterface $repo): object
    {
        $ok = $repo->abonarInvoice($detId, (float) $request->input('amount', 0), $request->input('client_name', ''));
        return standardApiReponse($ok ? 'Abono registrado' : 'Factura no encontrada', null, $ok ? 0 : 1, JsonResponse::HTTP_OK);
    }

    public function updateInvoiceNew(Request $request, int $detId, FacturationRepositoryInterface $repo): object
    {
        $ok = $repo->updateInvoice($detId, $request->all());
        return standardApiReponse($ok ? 'Factura actualizada' : 'Factura no encontrada', null, $ok ? 0 : 1, JsonResponse::HTTP_OK);
    }

    public function exportPaymentsCSV(Request $request, FacturationRepositoryInterface $repo)
    {
        $rows = $repo->exportPayments(
            $request->query('from'),
            $request->query('to'),
            $request->query('cab_id') ? (int) $request->query('cab_id') : null
        );
        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['#Factura','Fecha','Cliente','DNI','Teléfono','Total','Abono','Descuento','Estado','Pagado en']);
            foreach ($rows as $r) {
                $r = (array) $r;
                fputcsv($h, [
                    $r['number_facture'] ?? '',
                    $r['date_facturation'] ?? '',
                    $r['cliente'] ?? '',
                    $r['dni'] ?? '',
                    $r['phone'] ?? '',
                    $r['price_total'] ?? 0,
                    $r['price_abone'] ?? 0,
                    $r['price_discount'] ?? 0,
                    ($r['paid'] ?? 0) ? 'Pagado' : (($r['abone'] ?? 0) ? 'Abono' : 'Pendiente'),
                    $r['paid_at'] ?? '',
                ]);
            }
            fclose($h);
        }, 'pagos_' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function paymentLogs(Request $request, FacturationRepositoryInterface $repo): object
    {
        $data = $repo->getPaymentLogsPaginated(
            $request->query('search'),
            $request->query('from'),
            $request->query('to'),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 15)
        );
        return standardApiReponse('OK', $data, 0, JsonResponse::HTTP_OK);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Payment Commitments (compromisos de pago)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/facturation/commitments?cab_id=X
     * List all commitments for a client, with their linked invoices.
     */
    public function listCommitments(Request $request): JsonResponse
    {
        $cabId = (int) $request->query('cab_id');

        $commitments = PaymentCommitment::with(['invoices:id,number_facture,price_total,price_discount,price_abone,paid,date_facturation'])
            ->where('company_id', getSessionCompanyId())
            ->where('cab_id', $cabId)
            ->orderBy('commitment_date', 'desc')
            ->get();

        return standardApiReponse('OK', $commitments, 0, JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/facturation/commitments
     * Create a new payment commitment linked to specific invoices.
     * body: { cab_id, user_id, commitment_date, invoice_ids: number[], notes?, auto_suspend }
     */
    public function createCommitment(Request $request): JsonResponse
    {
        $companyId  = getSessionCompanyId();
        $invoiceIds = $request->input('invoice_ids', []);

        if (empty($invoiceIds)) {
            return standardApiReponse(
                'Debes seleccionar al menos una factura para el compromiso.',
                null, true, JsonResponse::HTTP_OK
            );
        }

        // Only one active commitment per client
        $existing = PaymentCommitment::where('company_id', $companyId)
            ->where('cab_id', $request->input('cab_id'))
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return standardApiReponse(
                'Este cliente ya tiene un compromiso de pago activo.',
                null, true, JsonResponse::HTTP_OK
            );
        }

        // Calculate total from the selected invoices
        $invoices = \App\Models\DetFacturation::whereIn('id', $invoiceIds)->get();
        $total = $invoices->sum(fn($i) => max(0, $i->price_total - $i->price_abone - $i->price_discount));

        $commitment = PaymentCommitment::create([
            'company_id'       => $companyId,
            'cab_id'           => $request->input('cab_id'),
            'user_id'          => $request->input('user_id'),
            'commitment_date'  => $request->input('commitment_date'),
            'amount_committed' => $total,   // auto-calculated, not manually entered
            'notes'            => $request->input('notes'),
            'auto_suspend'     => (bool) $request->input('auto_suspend', true),
            'status'           => 'pending',
            'created_by'       => getSessionUserId(),
        ]);

        // Link the invoices
        $commitment->invoices()->attach($invoiceIds);

        return standardApiReponse(
            'Compromiso de pago creado.',
            $commitment->load('invoices:id,number_facture,price_total,price_discount,price_abone,paid,date_facturation'),
            0, JsonResponse::HTTP_OK
        );
    }

    /**
     * PUT /api/facturation/commitments/{id}/cancel
     * Cancel a pending commitment.
     */
    public function cancelCommitment(int $id): JsonResponse
    {
        $commitment = PaymentCommitment::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->where('status', 'pending')
            ->firstOrFail();

        $commitment->update(['status' => 'cancelled']);

        return standardApiReponse('Compromiso cancelado.', null, 0, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/facturation/commitments/{id}/fulfill
     * Manually mark a commitment as fulfilled.
     */
    public function fulfillCommitment(int $id): JsonResponse
    {
        $commitment = PaymentCommitment::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->where('status', 'pending')
            ->firstOrFail();

        $commitment->update([
            'status'       => 'fulfilled',
            'fulfilled_at' => now(),
        ]);

        return standardApiReponse('Compromiso marcado como cumplido.', null, 0, JsonResponse::HTTP_OK);
    }
}
