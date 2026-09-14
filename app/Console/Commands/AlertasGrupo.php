<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Alertas\AvisosAlGrupo;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Asocia el grupo de WhatsApp donde llegan las alertas de la red.
 *
 * Sin nombre, lista los grupos de la línea. Con nombre, busca el que coincida
 * (sin importar mayúsculas, tildes ni emojis), lo guarda y manda una prueba.
 */
class AlertasGrupo extends Command
{
    protected $signature = 'alertas:grupo {empresa : Id de la empresa} {nombre? : Nombre o parte del nombre del grupo}
                            {--quitar : Deja de mandar alertas al grupo}
                            {--sin-prueba : No manda el mensaje de prueba}';

    protected $description = 'Asocia el grupo de WhatsApp que recibe las alertas de la red';

    public function handle(): int
    {
        $empresaId = (int) $this->argument('empresa');
        $empresa = Company::find($empresaId);

        if (!$empresa) {
            $this->error('No existe esa empresa.');
            return self::FAILURE;
        }

        if ($this->option('quitar')) {
            $empresa->forceFill(['alertas_grupo_jid' => null, 'alertas_grupo_nombre' => null])->save();
            $this->info('Listo: ya no se mandan alertas a ningún grupo.');
            return self::SUCCESS;
        }

        $avisos = new AvisosAlGrupo($empresaId);

        try {
            $grupos = $avisos->gruposDeLaLinea();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $nombre = (string) $this->argument('nombre');

        if ($nombre === '') {
            $this->table(['Grupo', 'Participantes'], array_map(fn ($g) => [$g['nombre'], $g['participantes']], $grupos));
            return self::SUCCESS;
        }

        $buscado = self::normalizar($nombre);
        $coinciden = array_values(array_filter($grupos, fn ($g) => str_contains(self::normalizar($g['nombre']), $buscado)));

        if (count($coinciden) !== 1) {
            $this->error(count($coinciden) === 0
                ? 'Ningún grupo de la línea coincide. ¿La línea de WhatsApp Web está dentro del grupo?'
                : 'Coinciden varios grupos: ' . implode(', ', array_column($coinciden, 'nombre')) . '. Escribí un nombre más exacto.');
            return self::FAILURE;
        }

        $grupo = $coinciden[0];
        $empresa->forceFill(['alertas_grupo_jid' => $grupo['jid'], 'alertas_grupo_nombre' => $grupo['nombre']])->save();
        $this->info("Asociado: {$grupo['nombre']} ({$grupo['participantes']} participantes).");

        if (!$this->option('sin-prueba')) {
            $avisos->probar()
                ? $this->info('Mensaje de prueba enviado al grupo.')
                : $this->warn('No se pudo mandar la prueba: revisá que la línea siga conectada (ver el log).');
        }

        return self::SUCCESS;
    }

    /** Sin tildes, emojis ni signos: "🚨 Netplay · Alertas de Red" → "netplay alertas de red". */
    private static function normalizar(string $texto): string
    {
        $ascii = Str::ascii($texto);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', strtolower($ascii))));
    }
}
