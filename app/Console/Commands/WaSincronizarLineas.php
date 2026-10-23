<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\WhatsApp\LineasDeWhatsApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pone al día el estado de las líneas de WhatsApp.
 *
 * `wa_lineas.estado` sólo se refrescaba cuando alguien abría la pantalla de la
 * empresa, así que entre visita y visita quedaba viejo: se vio una línea
 * marcada «disconnected» que estaba conectada y enviando. El operador mira el
 * panel, la ve caída y va a escanear un QR que no hacía falta —o peor, da por
 * perdida una línea que funciona—.
 *
 * El estado de verdad lo tiene el servicio de Node; aquí sólo se copia.
 */
class WaSincronizarLineas extends Command
{
    protected $signature = 'wa:sincronizar-lineas {--empresa= : Sólo esta empresa}';

    protected $description = 'Copia del servicio de WhatsApp el estado real de cada línea';

    public function handle(): int
    {
        $empresas = Company::query()
            ->where('active', true)
            ->whereNotNull('wa_api_key')
            ->when($this->option('empresa'), fn ($q, $id) => $q->where('id', (int) $id))
            ->get(['id', 'name']);

        if ($empresas->isEmpty()) {
            $this->info('Ninguna empresa con servicio de WhatsApp propio.');

            return self::SUCCESS;
        }

        $lineas = new LineasDeWhatsApp();
        $cambios = 0;

        foreach ($empresas as $empresa) {
            $antes = DB::table('wa_lineas')->where('company_id', $empresa->id)
                ->pluck('estado', 'instance_id')->all();
            $filas = DB::table('wa_lineas')->where('company_id', $empresa->id)->get()->keyBy('instance_id');

            try {
                $lineas->sincronizar((int) $empresa->id);
            } catch (\Throwable $e) {
                // Que una empresa falle no puede dejar a las demás sin
                // actualizar: casi siempre es su servicio que no contesta.
                Log::warning('[Líneas WA] No se pudo sincronizar', ['empresa' => $empresa->id, 'error' => $e->getMessage()]);
                $this->warn("{$empresa->name}: {$e->getMessage()}");

                continue;
            }

            $despues = DB::table('wa_lineas')->where('company_id', $empresa->id)
                ->pluck('estado', 'instance_id')->all();

            foreach ($despues as $instancia => $estado) {
                if (($antes[$instancia] ?? null) !== $estado) {
                    $cambios++;
                    $this->line("  {$empresa->name} · {$instancia}: " . ($antes[$instancia] ?? 'nueva') . " → {$estado}");
                    $this->anotarCambio((int) $empresa->id, $filas[$instancia] ?? null, $antes[$instancia] ?? null, (string) $estado);
                }
            }
        }

        $this->info("Listo. {$empresas->count()} empresa(s), {$cambios} cambio(s) de estado.");

        return self::SUCCESS;
    }

    /**
     * Queda en la historia y, si es una línea en uso, se avisa al grupo: que se cayó (con qué
     * hacer) y, cuando vuelve, cuánto estuvo caída. Antes una línea caída no la notaba nadie
     * hasta que un cliente se quejaba de que no le respondían.
     */
    private function anotarCambio(int $empresaId, ?object $linea, ?string $antes, string $estado): void
    {
        $linea ??= DB::table('wa_lineas')->where('company_id', $empresaId)->where('estado', $estado)->orderByDesc('updated_at')->first();

        if (!$linea || !\Illuminate\Support\Facades\Schema::hasTable('wa_lineas_eventos')) {
            return;
        }

        $nombre = trim(($linea->nombre ?: 'Línea') . ($linea->telefono ? ' (' . $linea->telefono . ')' : ''));
        $cayo = $antes === 'connected' && $estado !== 'connected';
        $volvio = $estado === 'connected' && $antes !== null && $antes !== 'connected';
        $texto = null;

        if ((int) $linea->activa === 1 && $cayo) {
            $texto = "🔴 *Línea de WhatsApp desconectada* · {$nombre}\n"
                . 'Los mensajes que salgan por ella van a fallar hasta que se reconecte. '
                . 'Revise el teléfono o escanee el QR en *Configuración › Líneas de WhatsApp*.';
        } elseif ((int) $linea->activa === 1 && $volvio) {
            // Sólo se avisa la vuelta de una caída que se avisó.
            $caida = DB::table('wa_lineas_eventos')->where('linea_id', $linea->id)->where('avisado', 1)
                ->where('estado', '<>', 'connected')->orderByDesc('id')->first();
            $yaVolvio = $caida && DB::table('wa_lineas_eventos')->where('linea_id', $linea->id)->where('id', '>', $caida->id)->where('estado', 'connected')->exists();

            if ($caida && !$yaVolvio) {
                $duro = \Carbon\Carbon::parse($caida->created_at)->diffForHumans(now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]);
                $texto = "🟢 *Línea de WhatsApp conectada otra vez* · {$nombre}\nEstuvo caída {$duro}.";
            }
        }

        $avisado = $texto ? (new \App\Services\Alertas\AvisosAlGrupo($empresaId))->avisar($texto) : false;

        DB::table('wa_lineas_eventos')->insert([
            'company_id' => $empresaId, 'linea_id' => $linea->id, 'antes' => $antes, 'estado' => $estado,
            'avisado' => $avisado || ($texto !== null && $volvio), 'created_at' => now(),
        ]);
    }
}
