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
                }
            }
        }

        $this->info("Listo. {$empresas->count()} empresa(s), {$cambios} cambio(s) de estado.");

        return self::SUCCESS;
    }
}
