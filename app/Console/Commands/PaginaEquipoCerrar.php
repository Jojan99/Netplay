<?php

namespace App\Console\Commands;

use App\Services\Red\PaginaDelEquipo;
use Illuminate\Console\Command;

/**
 * Vuelve a cerrar por la WAN la página de los Sagemcom que se abrieron para el puente
 * y nadie usa hace media hora (ver PaginaDelEquipo::abrirWanSagemcom).
 */
class PaginaEquipoCerrar extends Command
{
    protected $signature = 'pagina-equipo:cerrar';

    protected $description = 'Cierra la página web por la WAN de los equipos abiertos para el puente que ya no se usan';

    public function handle(): int
    {
        $n = PaginaDelEquipo::cerrarSagemcomSinUso();

        if ($n) {
            $this->info("Se cerraron {$n} equipo(s).");
        }

        return self::SUCCESS;
    }
}
