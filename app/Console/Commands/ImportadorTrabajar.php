<?php

namespace App\Console\Commands;

use App\Models\Importacion;
use App\Services\Importador\ImportadorDeClientes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Trabaja una importación de clientes en segundo plano: leer la API de origen
 * (y dejar la vista previa lista) o importar lo que el administrador confirmó.
 *
 * La lanza la petición web y termina sola; el avance queda en la tabla
 * importaciones, que es lo que consulta la pantalla.
 */
class ImportadorTrabajar extends Command
{
    protected $signature = 'importador:trabajar {id}';

    protected $description = 'Lee o importa en segundo plano los clientes de una importación (WispHub, Mikrowisp)';

    public function handle(): int
    {
        set_time_limit(0);

        $imp = Importacion::find((int) $this->argument('id'));

        if (!$imp) {
            $this->error('No existe la importación.');

            return self::FAILURE;
        }

        try {
            match ($imp->estado) {
                'leyendo' => ImportadorDeClientes::leerApi($imp),
                'en_cola' => ImportadorDeClientes::ejecutar($imp),
                default   => $this->line("La importación {$imp->id} está en estado {$imp->estado}: nada que hacer."),
            };
        } catch (\Throwable $e) {
            Log::error('[Importador] Falló', ['importacion' => $imp->id, 'error' => $e->getMessage(), 'en' => $e->getFile() . ':' . $e->getLine()]);

            $imp->refresh();
            $imp->update([
                'estado'       => $imp->estado === 'leyendo' ? 'error' : ($imp->estado === 'cancelando' ? 'cancelada' : 'error'),
                'api_token'    => null,
                'detalle'      => mb_substr($e->getMessage(), 0, 255),
                'terminada_en' => now(),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
