<?php

namespace App\Console\Commands;

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
       error_log("MENSAJEEEEE"); // Imprimir el mensaje en la consola
    }
}
