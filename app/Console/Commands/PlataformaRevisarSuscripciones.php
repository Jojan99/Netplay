<?php

namespace App\Console\Commands;

use App\Services\Plataforma\EstadoDeCuenta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Pruebas y cobros vencidos de las empresas: avisa primero, suspende después. */
class PlataformaRevisarSuscripciones extends Command
{
    protected $signature = 'plataforma:revisar-suscripciones {--solo-correos : No revisa ni suspende: sólo muestra a quién se le escribiría hoy}';
    protected $description = 'Anota el aviso de las suscripciones vencidas y suspende a las que agotaron los días de gracia';

    public function handle(): int
    {
        if ($this->option('solo-correos')) {
            $this->correos(true);

            return self::SUCCESS;
        }

        $r = EstadoDeCuenta::revisar();

        foreach (['avisadas' => 'Primer aviso', 'en_gracia' => 'En gracia', 'suspendidas' => 'Suspendidas'] as $clave => $titulo) {
            $this->line($titulo . ': ' . ($r[$clave] ? implode(', ', $r[$clave]) : 'ninguna'));
        }

        if ($r['avisadas'] || $r['suspendidas']) {
            Log::info('[Plataforma] revisión de suscripciones', $r);
        }

        $this->correos(false);

        return self::SUCCESS;
    }

    /** Los correos de cobro a las empresas: recordatorio, vencido, último aviso y suspendida. */
    private function correos(bool $simular): void
    {
        $activos = \App\Services\Plataforma\AvisosDeCuentaPorCorreo::activos();
        $avisos = \App\Services\Plataforma\AvisosDeCuentaPorCorreo::revisar($simular || !$activos);

        $this->line('Correos de cobro: ' . ($activos ? ($simular ? 'ACTIVOS (esto es una simulación, no se envía)' : 'ACTIVOS') : 'APAGADOS (PLATAFORMA_AVISOS_CORREO): sólo se muestra a quién se le escribiría'));

        foreach ($avisos as $a) {
            $this->line(sprintf('  · %-28s %-20s → %s  [%s]', mb_substr($a['empresa'], 0, 28), $a['tipo'], $a['para'] ? implode(', ', $a['para']) : '(sin correo)', $a['enviado'] ? 'enviado' : $a['detalle']));
        }

        if (!$avisos) {
            $this->line('  · hoy no hay ninguno para enviar');
        }
    }
}
