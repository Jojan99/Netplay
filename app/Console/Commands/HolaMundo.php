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
use App\Services\WhatsAppService;
use Carbon\Carbon;

class HolaMundo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'post:create {company_id : ID de la empresa} {periodo : Grupo a procesar (1-4)} {billing_day : Día del mes al que corresponde la factura} {billing_month? : Mes (1-12), por defecto el actual} {billing_year? : Año, por defecto el actual}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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

        $this->info("Empresa ID: {$companyId} | Periodo: {$resultado} | Fecha factura: {$billingYear}-{$billingMonth}-{$billingDay}");

        $whatsapp = new WhatsAppService($companyId);
        $hora     = Carbon::now()->toDateTimeString();

        try {
            $whatsapp->mensajeInformativo('3245127869', "⚠️ *Se inicia Proceso* '{$hora}'.");
        } catch (\Throwable) {}

        $FacturationRepository        = new FacturationRepository();
        $CreateDetFacturationUseCase  = new CreateDetFacturationUseCase($FacturationRepository);
        $CreateFacturationRequest     = new CreateFacturationRequest();
        $GeneratePdfRepository        = new GeneratePdfRepository();
        $GeneratePdfUseCase           = new GeneratePdfUseCase($GeneratePdfRepository, $FacturationRepository);

        $CreateDetFacturationUseCase->createProcesoDetFacturation(
            $CreateFacturationRequest, $resultado, $companyId, $billingDay, $billingMonth, $billingYear
        );
        //$GeneratePdfUseCase->generatePdf($resultado, $companyId, $billingDay);

        $this->info('Proceso finalizado.');
        try {
                $whatsapp->mensajeInformativo(
                '3245127869',
                "✅ *Se Finaliza Proceso* '{$hora}', '{$resultado}', '{$companyId}', '{$billingDay}'."
            );
        } catch (\Throwable) {}
    }
}
