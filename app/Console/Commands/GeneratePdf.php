<?php

namespace App\Console\Commands;

use App\Http\Controllers\GeneratePdfController;
use Illuminate\Console\Command;
use App\UseCases\GeneratePdf\GeneratePdfUseCase;
use App\Repositories\GeneratePdfRepository;
use App\Http\Requests\GeneratePdf\GeneratePdfDataRequest;
class GeneratePdf extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-pdf';

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

        $controller = new GeneratePdfController;
        $GeneratePdfRepository = new GeneratePdfRepository;
        $GeneratePdfDataRequest = new GeneratePdfDataRequest;
        
        $GeneratePdfUseCase = new GeneratePdfUseCase($GeneratePdfRepository);

        $response = $GeneratePdfUseCase->generatePdf();

        error_log(json_encode($response));

    }
}
