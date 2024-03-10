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
        $FacturationRepository = new FacturationRepository();
        $CreateDetFacturationUseCase = new CreateDetFacturationUseCase($FacturationRepository);
        $CreateFacturationRequest = new CreateFacturationRequest();
        $GeneratePdfRepository = new GeneratePdfRepository();
        $GeneratePdfUseCase = new GeneratePdfUseCase($GeneratePdfRepository);
        
        $response = $CreateDetFacturationUseCase->createProcesoDetFacturation($CreateFacturationRequest);

        $GeneratePdfUseCase->generatePdf(1);

        
    }
}
