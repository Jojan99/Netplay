<?php

namespace App\Console\Commands;

use App\Models\WaCampaign;
use App\Services\WhatsApp\CampaignService;
use Illuminate\Console\Command;

/**
 * Despacha por tandas los envíos masivos que estén en marcha.
 *
 * El envío no puede ocurrir en la petición web: novecientos mensajes no caben
 * en el tiempo de una respuesta, y esta instalación no tiene cola de trabajos.
 */
class EnviarCampanasWhatsApp extends Command
{
    protected $signature = 'wa:enviar-campanas
                            {--tanda= : Cuántos mensajes mandar en esta pasada}';

    protected $description = 'Manda la siguiente tanda de los envíos masivos de WhatsApp en curso';

    public function handle(CampaignService $campañas): int
    {
        $tanda = (int) ($this->option('tanda') ?: CampaignService::POR_TANDA);

        $enCurso = WaCampaign::where('status', WaCampaign::ENVIANDO)->orderBy('id')->get();

        if ($enCurso->isEmpty()) {
            $this->line('No hay envíos en curso.');
            return self::SUCCESS;
        }

        foreach ($enCurso as $campaign) {
            $hechos = $campañas->dispatchBatch($campaign, $tanda);

            $this->info($hechos === 0
                ? "Envío #{$campaign->id}: terminado."
                : "Envío #{$campaign->id}: {$hechos} mensaje(s) en esta tanda.");
        }

        return self::SUCCESS;
    }
}
