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
    protected $signature = 'post:create';

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

        $whatsapp = new WhatsAppService('u99eyqpz5jwn5h4w', 'instance106490');

        
        
                $hora = Carbon::now()->toDateTimeString();
                // Mensaje de error
                $message = "⚠️ *Se inicia Proceso*, .'$hora'.";

             
                    
                    $response = $whatsapp->mensajeInformativo('3245127869', $message);
                    $this->info("Mensaje enviado a: - Respuesta: $response");
        $fechaActual = Carbon::now();
        // Obtener el día del mes de la fecha actual
        $diaDelMes = $fechaActual->day;

        $resultado = ($diaDelMes > 20) ? 1 : 1;
        $this->info('aca.');
        
        $FacturationRepository = new FacturationRepository();
        $CreateDetFacturationUseCase = new CreateDetFacturationUseCase($FacturationRepository);
        $CreateFacturationRequest = new CreateFacturationRequest();
        $GeneratePdfRepository = new GeneratePdfRepository();
        $GeneratePdfUseCase = new GeneratePdfUseCase($GeneratePdfRepository,$FacturationRepository);
        //$CreateDetFacturationUseCase->createProcesoDetFacturation($CreateFacturationRequest,$resultado);
        $GeneratePdfUseCase->generatePdf($resultado);
        $this->info('aca abajo.');
        $message = "⚠️ *Se Finaliza Proceso*, .'$hora'.";
        $response = $whatsapp->mensajeInformativo('3245127869', $message);
        $this->info("Mensaje enviado a: - Respuesta: $response");
    }
}
