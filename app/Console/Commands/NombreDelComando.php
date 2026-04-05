<?php

namespace App\Console\Commands;

use App\Repositories\FacturationRepository;
use App\UseCases\Notifications\SendNotificationUseCase;
use Illuminate\Console\Command;

class NombreDelComando extends Command
{
    protected $signature = 'NombreDelComando'; // Definimos el nombre del comando

    protected $description = 'Envía un mensaje a la consola cada hora específica';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $FacturationRepository = new FacturationRepository();

        $SendNotificationUseCase = new SendNotificationUseCase($FacturationRepository);

        $SendNotificationUseCase->sendNotificationReminder();
    }
}
