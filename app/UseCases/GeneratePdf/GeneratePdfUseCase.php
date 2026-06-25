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
use Illuminate\Support\Facades\DB;
use App\Models\Company;
use App\Models\DetFacturation;

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
    public function generatePdf($Periodo, int $companyId = 0, int $billingDay = 0, string $sendChannel = 'whatsapp', ?int $emailDailyLimit = null): mixed
    {
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '512M');

        try {
            // ── Verificar configuración de canales de la empresa ─────────────────────
            $company = $companyId > 0 ? Company::find($companyId) : null;
            $waEnabled = $company ? $company->invoice_whatsapp_enabled : true;
            $emailEnabled = $company ? $company->email_enabled : true;

            // Si ambos están desactivados, abortar
            if (!$waEnabled && !$emailEnabled) {
                return [
                    'message' => 'WhatsApp y Email están deshabilitados para esta empresa',
                    'status' => 1,
                ];
            }

            // Si WhatsApp está desactivado pero el canal incluye WhatsApp, solo enviar Email
            if (!$waEnabled && in_array($sendChannel, ['whatsapp', 'both'])) {
                $sendChannel = 'email';
            }

            // Si Email está desactivado pero el canal incluye Email, solo enviar WhatsApp
            if (!$emailEnabled && in_array($sendChannel, ['email', 'both'])) {
                $sendChannel = 'whatsapp';
            }

            $getUserPeriode1 = $this->generatePdfRepository->getUserPeriode1($Periodo, $companyId);
            $generatePdf     = $this->generatePdfRepository->generatePdf($getUserPeriode1);

            $fecha = date('Y-m-d', strtotime('+1 days'));
            $waService = $waEnabled ? new WhatsAppService($companyId, true) : null;
            $humanizer = new WhatsAppMessageHumanizerService();
            $emailService = new InvoiceEmailService();

            $waMessages = [];
            $emailInvoices = [];
            $remainingEmails = $this->remainingEmailLimit($companyId, $emailDailyLimit);

            foreach ($generatePdf as $user) {
                // Preparar mensaje de WhatsApp
                if ($waEnabled && in_array($sendChannel, ['whatsapp', 'both'])) {
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
                if ($emailEnabled && in_array($sendChannel, ['email', 'both'])) {
                    if ($remainingEmails !== null && $remainingEmails <= 0) {
                        continue; // límite diario alcanzado
                    }

                    $pdfContent = $this->generateIndividualPdf($user, 0);
                    $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $user['number_facture'] ?? '') . '_' . ($user['dni'] ?? '') . '.pdf';

                    $emailInvoices[] = [
                        'det_facturation_id' => $user['det_facturation_id'] ?? null,
                        'user' => $user,
                        'pdf_content' => $pdfContent,
                        'filename' => $filename,
                    ];

                    if ($remainingEmails !== null) {
                        $remainingEmails--;
                    }
                }
            }

            // Enviar por WhatsApp
            $waResult = ['queued' => 0, 'invalid' => 0, 'chunks' => 0];
            if ($waEnabled && in_array($sendChannel, ['whatsapp', 'both']) && count($waMessages) > 0) {
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
            if ($emailEnabled && in_array($sendChannel, ['email', 'both']) && count($emailInvoices) > 0) {
                $emailResult = $emailService->sendBulkInvoices($emailInvoices);
                Log::info('[EMAIL_BILLING] Envío masivo completado', [
                    'company_id' => $companyId,
                    'sent' => $emailResult['sent'],
                    'failed' => $emailResult['failed'],
                ]);
                $this->writeBillingLog($companyId, $Periodo, $emailInvoices, $emailResult, null, 'email');

                // Marcar facturas enviadas exitosamente para control de lote diario
                if (!empty($emailResult['successful_ids'])) {
                    DetFacturation::whereIn('id', $emailResult['successful_ids'])
                        ->update(['email_sent_at' => now()]);
                }
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
    public function generatePdfMeta($Periodo, int $companyId = 0, int $billingDay = 0, string $sendChannel = 'whatsapp', ?int $emailDailyLimit = null): mixed
    {
        try {
            $getUserPeriode1 = $this->generatePdfRepository->getUserPeriode1($Periodo, $companyId);
            $users           = $this->generatePdfRepository->generatePdf($getUserPeriode1);

            if ($billingDay > 0) {
                $fecha = Carbon::now()->setDay(min($billingDay, Carbon::now()->daysInMonth))->format('Y-m-d');
            } else {
                $fecha = Carbon::now()->format('Y-m-d');
            }

            $waService = new WhatsAppService($companyId, true);
            $humanizer = new WhatsAppMessageHumanizerService();
            $emailService = new InvoiceEmailService();

            $waMessages = [];
            $emailInvoices = [];
            $remainingEmails = $this->remainingEmailLimit($companyId, $emailDailyLimit);

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
                    if ($remainingEmails !== null && $remainingEmails <= 0) {
                        continue; // límite diario alcanzado
                    }

                    $pdfContent = $this->generateIndividualPdf($user, $Cab);
                    $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $user['number_facture'] ?? '') . '_' . ($user['dni'] ?? '') . '.pdf';

                    $emailInvoices[] = [
                        'det_facturation_id' => $user['det_facturation_id'] ?? null,
                        'user' => $user,
                        'pdf_content' => $pdfContent,
                        'filename' => $filename,
                    ];

                    if ($remainingEmails !== null) {
                        $remainingEmails--;
                    }
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

                // Marcar facturas enviadas exitosamente para control de lote diario
                if (!empty($emailResult['successful_ids'])) {
                    DetFacturation::whereIn('id', $emailResult['successful_ids'])
                        ->update(['email_sent_at' => now()]);
                }
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

    /**
     * Calcula cuántos emails aún se pueden enviar hoy para una empresa.
     *
     * @return int|null null si no hay límite configurado
     */
    private function remainingEmailLimit(int $companyId, ?int $emailDailyLimit): ?int
    {
        if ($emailDailyLimit === null || $emailDailyLimit <= 0) {
            return null; // sin límite
        }

        $sentToday = DB::table('det_facturations as dt')
            ->join('cab_facturations as cb', 'cb.id', '=', 'dt.cab_id')
            ->where('cb.company_id', $companyId)
            ->whereDate('dt.email_sent_at', now()->toDateString())
            ->count();

        return max(0, $emailDailyLimit - $sentToday);
    }
}
