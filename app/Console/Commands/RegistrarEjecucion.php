<?php

namespace App\Console\Commands;

use App\Repositories\GeneratePdfRepository;
use App\UseCases\Notifications\SendNotificationRememberUseCase;
use Illuminate\Console\Command;
use Carbon\Carbon;

class RegistrarEjecucion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:registrar-ejecucion';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Registra la hora de ejecución en un archivo';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $GeneratePdfRepository = new GeneratePdfRepository();

        $SendNotificationUseCase = new SendNotificationRememberUseCase($GeneratePdfRepository);

        $SendNotificationUseCase->sendNotificationRemeReminder();
    }
}
