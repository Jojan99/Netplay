<?php

namespace App\UseCases\GeneratePdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use Carbon\Carbon;
use App\Resources\Templates\TemplatesPdf;
use App\Services\WhatsAppService;
use App\Services\WhatsAppMessageHumanizerService;
use App\Services\InvoiceEmailService;
use Illuminate\Support\Facades\Log;

/**
 *
 * @package App\UseCases\GeneratePdf
 * @author NetPlay <atencionalcliente@netplay.com.co
 * @copyright 2023/09/29
 */
class GeneratePdfUseCase implements GeneratePdfUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param GeneratePdfRepositoryInterface $generatePdfRepository
     */
    public function __construct(
        private GeneratePdfRepositoryInterface $generatePdfRepository,
        private ?FacturationRepositoryInterface $facturationRepository = null,
    ) {
    }

    /**
     * Escribe un log detallado del envío de facturas.
     */
    private function writeBillingLog(int $companyId, int $periodo, array $messages, array $result, ?string $error = null, string $channel = 'whatsapp'): void
    {
        try {
            $logPath = storage_path('logs/billing');
            if (!is_dir($logPath)) {
                mkdir($logPath, 0777, true);
            }

            $logFile = $logPath . '/billing_' . $channel . '_' . date('Y-m-d_H-i-s') . '.json';

            $logData = [
                'fecha_proceso'     => Carbon::now()->toDateTimeString(),
                'company_id'        => $companyId,
                'periodo'           => $periodo,
                'canal'             => $channel,
                'total_mensajes'    => count($messages),
                'resultado_batch'   => $result,
                'error'             => $error,
                'detalle_envios'    => $messages,
            ];

            file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            Log::info("[BILLING_LOG] Log detallado guardado", ['file' => $logFile, 'channel' => $channel]);
        } catch (\Throwable $e) {
            Log::warning("[BILLING_LOG] No se pudo escribir log detallado", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Generar y enviar facturas masivamente.
     *
     * @param mixed $Periodo
     * @param int $companyId
     * @param int $billingDay
     * @param string $sendChannel 'whatsapp' | 'email' | 'both'
     * @return mixed
     */
    public function generatePdf($Periodo, int $companyId = 0, int $billingDay = 0, string $sendChannel = 'whatsapp'): mixed
    {
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '512M');

        try {
            $getUserPeriode1 = $this->generatePdfRepository->getUserPeriode1($Periodo, $companyId);
            $generatePdf     = $this->generatePdfRepository->generatePdf($getUserPeriode1);

            $fecha = date('Y-m-d', strtotime('+1 days'));
            $waService = new WhatsAppService($companyId);
            $humanizer = new WhatsAppMessageHumanizerService();
            $emailService = new InvoiceEmailService();

            $waMessages = [];
            $emailInvoices = [];

            foreach ($generatePdf as $user) {
                // Preparar mensaje de WhatsApp
                if (in_array($sendChannel, ['whatsapp', 'both'])) {
                    $phoneNumbers = explode(' - ', $user['phone']);
                    foreach ($phoneNumbers as $phone) {
                        $phone = trim($phone);
                        if (empty($phone)) continue;

                        $msgBody = $humanizer->generateInvoiceMessage([
                            'names' => $user['names'] ?? '',
                            'lastname' => $user['lastname'] ?? '',
                            'number_bill' => $user['number_facture'] ?? '',
                            'monthly_price' => '$' . number_format($user['monthly_price'] ?? 0, 0, ',', '.'),
                            'date_finish_bill' => $fecha,
                            'billing_electronic' => $user['billing_electronic'] ?? 0,
                        ]);

                        $waMessages[] = [
                            'number' => $phone,
                            'message' => $msgBody,
                            'type' => 'text',
                        ];
                    }
                }

                // Preparar correo electrónico
                if (in_array($sendChannel, ['email', 'both'])) {
                    $pdfContent = $this->generateIndividualPdf($user, 0);
                    $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $user['number_facture'] ?? '') . '_' . ($user['dni'] ?? '') . '.pdf';

                    $emailInvoices[] = [
                        'user' => $user,
                        'pdf_content' => $pdfContent,
                        'filename' => $filename,
                    ];
                }
            }

            // Enviar por WhatsApp
            $waResult = ['queued' => 0, 'invalid' => 0, 'chunks' => 0];
            if (in_array($sendChannel, ['whatsapp', 'both']) && count($waMessages) > 0) {
                if (!$waService) {
                    return [
                        'message' => 'WhatsApp no configurado para esta empresa',
                        'status' => 1,
                    ];
                }
                $waResult = $waService->sendBulk($waMessages);
                Log::info('[WA_BILLING] Batch encolado en whatsapp-service', [
                    'company_id' => $companyId,
                    'queued' => $waResult['queued'] ?? 0,
                    'invalid' => $waResult['invalid'] ?? 0,
                ]);
                $this->writeBillingLog($companyId, $Periodo, $waMessages, $waResult, null, 'whatsapp');
            }

            // Enviar por Correo
            $emailResult = ['sent' => 0, 'failed' => 0, 'errors' => []];
            if (in_array($sendChannel, ['email', 'both']) && count($emailInvoices) > 0) {
                $emailResult = $emailService->sendBulkInvoices($emailInvoices);
                Log::info('[EMAIL_BILLING] Envío masivo completado', [
                    'company_id' => $companyId,
                    'sent' => $emailResult['sent'],
                    'failed' => $emailResult['failed'],
                ]);
                $this->writeBillingLog($companyId, $Periodo, $emailInvoices, $emailResult, null, 'email');
            }

            return [
                'message' => "Facturas procesadas. WhatsApp: " . ($waResult['queued'] ?? 0) . " encolados. Email: " . ($emailResult['sent'] ?? 0) . " enviados.",
                'status' => 0,
                'whatsapp' => $waResult,
                'email' => $emailResult,
            ];

        } catch (QueryException $err) {
            Log::error('[BILLING] Error general', ['error' => $err->getMessage()]);
            $this->writeBillingLog($companyId, $Periodo, $waMessages ?? [], $waResult ?? [], $err->getMessage(), $sendChannel);
            return [
                'message' => 'Error generando/enviando facturas',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL,
            ];
        }
    }

    /**
     * Generar y enviar facturas con saldo anterior.
     *
     * @param mixed $Periodo
     * @param int $companyId
     * @param int $billingDay
     * @param string $sendChannel 'whatsapp' | 'email' | 'both'
     * @return mixed
     */
    public function generatePdfMeta($Periodo, int $companyId = 0, int $billingDay = 0, string $sendChannel = 'whatsapp'): mixed
    {
        try {
            $getUserPeriode1 = $this->generatePdfRepository->getUserPeriode1($Periodo, $companyId);
            $users           = $this->generatePdfRepository->generatePdf($getUserPeriode1);

            if ($billingDay > 0) {
                $fecha = Carbon::now()->setDay(min($billingDay, Carbon::now()->daysInMonth))->format('Y-m-d');
            } else {
                $fecha = Carbon::now()->format('Y-m-d');
            }

            $waService = new WhatsAppService($companyId);
            $humanizer = new WhatsAppMessageHumanizerService();
            $emailService = new InvoiceEmailService();

            $waMessages = [];
            $emailInvoices = [];

            foreach ($users as $user) {
                $Cab = $this->generatePdfRepository->getSaldoAnt($user['id'], $user['number_facture']);
                $Cab = $Cab ?? 0;
                $total = $Cab > 0 ? $Cab + ($user['total'] ?? 0) : ($user['total'] ?? 0);

                if (in_array($sendChannel, ['whatsapp', 'both'])) {
                    $phoneNumbers = explode(' - ', $user['phone']);
                    foreach ($phoneNumbers as $phone) {
                        $phone = trim($phone);
                        if (empty($phone)) continue;

                        $msgBody = $humanizer->generateInvoiceMessage([
                            'names' => $user['names'] ?? '',
                            'lastname' => $user['lastname'] ?? '',
                            'number_bill' => $user['number_facture'] ?? '',
                            'monthly_price' => '$' . number_format($total, 0, ',', '.') . ' COP',
                            'date_finish_bill' => $fecha,
                            'billing_electronic' => $user['billing_electronic'] ?? 0,
                        ]);

                        $waMessages[] = [
                            'number' => $phone,
                            'message' => $msgBody,
                            'type' => 'text',
                        ];
                    }
                }

                if (in_array($sendChannel, ['email', 'both'])) {
                    $pdfContent = $this->generateIndividualPdf($user, $Cab);
                    $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $user['number_facture'] ?? '') . '_' . ($user['dni'] ?? '') . '.pdf';

                    $emailInvoices[] = [
                        'user' => $user,
                        'pdf_content' => $pdfContent,
                        'filename' => $filename,
                    ];
                }
            }

            $waResult = ['queued' => 0, 'invalid' => 0, 'chunks' => 0];
            if (in_array($sendChannel, ['whatsapp', 'both']) && count($waMessages) > 0) {
                if (!$waService) {
                    return [
                        'message' => 'WhatsApp no configurado para esta empresa',
                        'status' => 1,
                    ];
                }
                $waResult = $waService->sendBulk($waMessages);
                Log::info('[WA_BILLING META] Batch encolado en whatsapp-service', [
                    'company_id' => $companyId,
                    'queued' => $waResult['queued'] ?? 0,
                    'invalid' => $waResult['invalid'] ?? 0,
                ]);
                $this->writeBillingLog($companyId, $Periodo, $waMessages, $waResult, null, 'whatsapp');
            }

            $emailResult = ['sent' => 0, 'failed' => 0, 'errors' => []];
            if (in_array($sendChannel, ['email', 'both']) && count($emailInvoices) > 0) {
                $emailResult = $emailService->sendBulkInvoices($emailInvoices);
                Log::info('[EMAIL_BILLING META] Envío masivo completado', [
                    'company_id' => $companyId,
                    'sent' => $emailResult['sent'],
                    'failed' => $emailResult['failed'],
                ]);
                $this->writeBillingLog($companyId, $Periodo, $emailInvoices, $emailResult, null, 'email');
            }

            return [
                'message' => "Facturas procesadas. WhatsApp: " . ($waResult['queued'] ?? 0) . " encolados. Email: " . ($emailResult['sent'] ?? 0) . " enviados.",
                'status' => 0,
                'whatsapp' => $waResult,
                'email' => $emailResult,
            ];

        } catch (\Exception $err) {
            Log::error('[BILLING META] Error', ['error' => $err->getMessage()]);
            $this->writeBillingLog($companyId, $Periodo, $waMessages ?? [], $waResult ?? [], $err->getMessage(), $sendChannel);
            return [
                'message' => 'Error en el proceso',
                'status'  => 1,
                'error'   => $err->getMessage(),
            ];
        }
    }

    /**
     * Enviar una factura individual por correo electrónico.
     *
     * @param string $invoiceId
     * @return array
     */
    public function sendInvoiceByEmail(string $invoiceId): array
    {
        try {
            $data = $this->generatePdfRepository->generatePdfById($invoiceId);

            if (!$data) {
                return ['status' => 'error', 'message' => 'Factura no encontrada'];
            }

            $email = trim($data['email'] ?? '');
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['status' => 'error', 'message' => 'El cliente no tiene un correo válido registrado'];
            }

            $saldoAnt = $this->generatePdfRepository->getSaldoAnt($data['id'], $data['number_facture']) ?? 0;

            $pdfContent = $this->generateIndividualPdf($data, $saldoAnt);
            $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['number_facture']) . '_' . $data['dni'] . '.pdf';

            $emailService = new InvoiceEmailService();
            return $emailService->sendInvoice($data, $pdfContent, $filename);

        } catch (\Throwable $e) {
            Log::error('[EMAIL_SINGLE] Error enviando factura individual', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function generateIndividualPdf($user, $Cab)
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);

        $pdfT = new TemplatesPdf();
        $pdf = new Dompdf($options);

        $html = $pdfT->PdfFacturas($user, $Cab);
        $pdf->loadHtml($html);
        $pdf->render();

        return $pdf->output();
    }
}
