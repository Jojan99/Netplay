<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\FacturationController;
use App\UseCases\Facturation\GetDateFacturePendingUseCase;
use App\UseCases\Facturation\Interfaces\GetDateFacturePendingUseCaseInterface;
use App\Repositories\FacturationRepository;
use App\UseCases\Facturation\CreateDetFacturationUseCase;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\UseCases\GeneratePdf\GeneratePdfUseCase;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\Repositories\GeneratePdfRepository;
use Carbon\Carbon;

class HolaMundo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'post:create {company_id : ID de la empresa} {periodo : Grupo a procesar (1-4)} {billing_day : Día del mes al que corresponde la factura} {billing_month? : Mes (1-12), por defecto el actual} {billing_year? : Año, por defecto el actual} {--channel=whatsapp : Canal de envío: whatsapp, email, both}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera y envía facturas masivamente por WhatsApp, Email o ambos.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando el proceso.');

        $companyId    = (int) $this->argument('company_id');
        $resultado    = (int) $this->argument('periodo');
        $billingDay   = (int) $this->argument('billing_day');
        $billingMonth = $this->argument('billing_month') ? (int) $this->argument('billing_month') : Carbon::now()->month;
        $billingYear  = $this->argument('billing_year')  ? (int) $this->argument('billing_year')  : Carbon::now()->year;
        $channel      = $this->option('channel');

        // Validar canal
        if (!in_array($channel, ['whatsapp', 'email', 'both'])) {
            $this->error("Canal '{$channel}' no válido. Use: whatsapp, email o both");
            return 1;
        }

        $this->info("Empresa ID: {$companyId} | Periodo: {$resultado} | Fecha factura: {$billingYear}-{$billingMonth}-{$billingDay} | Canal: {$channel}");

        // Ya no se avisa por WhatsApp al número de Netplay (3245127869): se
        // hacía con la línea de cada empresa y le llegaba a Netplay el proceso
        // de todas. El seguimiento queda en la salida del comando.

        $FacturationRepository        = new FacturationRepository();
        $CreateDetFacturationUseCase  = new CreateDetFacturationUseCase($FacturationRepository);
        $CreateFacturationRequest     = new CreateFacturationRequest();
        $GeneratePdfRepository        = new GeneratePdfRepository();
        $GeneratePdfUseCase           = new GeneratePdfUseCase($GeneratePdfRepository, $FacturationRepository);

        // Leer límite diario de emails configurado para la empresa
        $company = \App\Models\Company::find($companyId);
        $emailDailyLimit = $company ? $company->email_daily_limit : null;

        $CreateDetFacturationUseCase->createProcesoDetFacturation(
            $CreateFacturationRequest, $resultado, $companyId, $billingDay, $billingMonth, $billingYear
        );
        $result = $GeneratePdfUseCase->generatePdf($resultado, $companyId, $billingDay, $channel, $emailDailyLimit);

        $this->info('Proceso finalizado.');
        //$this->info("Resultado: " . json_encode($result));

        return 0;
    }
}
