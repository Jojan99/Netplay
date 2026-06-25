<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Services\InvoiceEmailService;
use App\Resources\Templates\TemplatesPdf;
use App\Repositories\GeneratePdfRepository;

class SendInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $detFacturationId,
        public int $companyId,
    ) {
    }

    public function handle(): void
    {
        try {
            $data = $this->getInvoiceData();

            if (!$data) {
                Log::warning('[EMAIL_JOB] Factura no encontrada', [
                    'det_id' => $this->detFacturationId,
                    'company_id' => $this->companyId,
                ]);
                return;
            }

            $saldoAnt = (new GeneratePdfRepository())->getSaldoAnt($data['cab_id'], $data['number_facture']) ?? 0;

            $pdfContent = $this->generatePdf($data, $saldoAnt);
            $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['number_facture']) . '_' . $data['dni'] . '.pdf';

            $emailService = new InvoiceEmailService();
            $result = $emailService->sendInvoice($data, $pdfContent, $filename);

            if ($result['status'] === 'ok') {
                DB::table('det_facturations')
                    ->where('id', $this->detFacturationId)
                    ->update(['email_sent_at' => now()]);

                DB::table('invoice_send_logs')->insert([
                    'det_facturation_id' => $this->detFacturationId,
                    'user_id' => null,
                    'channel' => 'email',
                    'status' => 'ok',
                    'message' => $result['message'],
                    'sent_to_email' => $data['email'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Log::info('[EMAIL_JOB] Factura enviada correctamente', [
                    'det_id' => $this->detFacturationId,
                    'email' => $data['email'] ?? null,
                ]);
            } else {
                Log::error('[EMAIL_JOB] Error enviando factura', [
                    'det_id' => $this->detFacturationId,
                    'error' => $result['message'],
                ]);
                throw new \Exception($result['message']);
            }
        } catch (\Throwable $e) {
            Log::error('[EMAIL_JOB] Excepción', [
                'det_id' => $this->detFacturationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function getInvoiceData(): ?array
    {
        $row = DB::table('det_facturations as dt')
            ->join('cab_facturations as cb', 'cb.id', '=', 'dt.cab_id')
            ->join('users', 'users.id', '=', 'cb.user_id')
            ->join('user_data', 'user_data.user_id', '=', 'users.id')
            ->join('internet_plans', 'internet_plans.id', '=', 'user_data.internet_plans_id')
            ->where('dt.id', $this->detFacturationId)
            ->where('cb.company_id', $this->companyId)
            ->where('user_data.active', 1)
            ->select(
                'user_data.names',
                'user_data.lastname',
                'user_data.dni',
                'internet_plans.plan_name',
                'internet_plans.monthly_price',
                'user_data.address',
                'user_data.phone',
                'user_data.email',
                'cb.date_init_facturation',
                'dt.price_discount',
                'dt.number_facture',
                'dt.date_facturation',
                'dt.price_total',
                'dt.days_facture',
                'dt.create_facture_manual',
                'dt.porcentage_discount',
                'cb.id as cab_id',
                'dt.price_abone',
                'dt.abone',
                'dt.updated_at'
            )
            ->first();

        return $row ? (array) $row : null;
    }

    private function generatePdf(array $data, float $saldoAnt): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);

        $pdfT = new TemplatesPdf();
        $pdf = new Dompdf($options);

        $pdf->loadHtml($pdfT->PdfFacturas($data, $saldoAnt));
        $pdf->render();

        return $pdf->output();
    }
}
