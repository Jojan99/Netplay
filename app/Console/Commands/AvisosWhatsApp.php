<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\ReminderService;
use Illuminate\Console\Command;

/**
 * Manda los avisos que salen solos: recordatorio de pago y suspensión por mora.
 *
 * Corre una vez al día. Cuándo le toca a cada cliente lo decide la
 * configuración de cada empresa, no este comando.
 */
class AvisosWhatsApp extends Command
{
    protected $signature = 'wa:avisos
                            {--empresa= : Correr solo para esta empresa}
                            {--simulacion : Contar a quiénes les tocaría, sin enviar nada}';

    protected $description = 'Envía los recordatorios de pago y avisos de suspensión por WhatsApp';

    public function handle(ReminderService $avisos): int
    {
        $simulacion = (bool) $this->option('simulacion');

        if ($simulacion) {
            $this->warn('Modo simulación: no se envía ningún mensaje.');
        }

        $resumen = $avisos->run(
            $this->option('empresa') ? (int) $this->option('empresa') : null,
            $simulacion
        );

        if ($resumen === []) {
            $this->line('Hoy no le toca a nadie.');
            return self::SUCCESS;
        }

        foreach ($resumen as $clave => $cuantos) {
            [$empresa, $evento] = explode(':', $clave);
            $verbo = $simulacion ? 'le tocaría a' : 'enviados a';
            $this->info("Empresa {$empresa} · {$evento}: {$verbo} {$cuantos} cliente(s).");
        }

        return self::SUCCESS;
    }
}
