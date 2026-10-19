<?php

namespace App\Console\Commands;

use App\Services\Acs\EquiposDelAcs;
use App\Services\Acs\GenieAcs;
use App\Services\Red\GestionRemotaDeOnt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Les devuelve sola la gestión remota a las ONT que están en línea en la OLT pero dejaron
 * de reportar al TR-069 (ver GestionRemotaDeOnt::recuperarGestion).
 *
 * Sin esto, una ONT reiniciada podía quedar navegando pero sin TR-069 para siempre: la
 * plataforma ya no leía su WiFi ni le aplicaba cambios, y nadie se enteraba (CARMEN
 * URBANEJA, 19-10).
 */
class RedRecuperarGestion extends Command
{
    protected $signature = 'red:recuperar-gestion {--empresa= : Sólo esta empresa} {--maximo=5 : Equipos por vuelta}';

    protected $description = 'Devuelve la gestión remota a las ONT en línea que dejaron de reportar al TR-069';

    /** Sin reportar hace más de esto se considera perdida (los equipos reportan cada 15 min a 1 h). */
    private const HORAS_MUDO = 2;

    public function handle(): int
    {
        $empresas = $this->option('empresa')
            ? [(int) $this->option('empresa')]
            : DB::table('gestion_remota')->where('activa', 1)->whereNotNull('vlan')->pluck('company_id')->map(fn ($c) => (int) $c)->all();

        foreach ($empresas as $empresa) {
            $this->revisar($empresa, max(1, (int) $this->option('maximo')));
        }

        return self::SUCCESS;
    }

    private function revisar(int $empresa, int $maximo): void
    {
        try {
            $acs = GenieAcs::deEmpresa($empresa);
            $lista = (new EquiposDelAcs($empresa, $acs))->lista();
        } catch (\Throwable $e) {
            $this->warn("Empresa {$empresa}: no se pudo leer el ACS ({$e->getMessage()}).");

            return;
        }

        $limite = now()->subHours(self::HORAS_MUDO)->timestamp;

        // Mudos en el ACS, con la ONT ubicada en la OLT; de ahí, los que la OLT ve en línea.
        $mudos = collect($lista)
            ->filter(fn ($f) => !empty($f['ont']['olt']) && $f['ultimo_reporte'] && strtotime($f['ultimo_reporte']) < $limite)
            ->filter(fn ($f) => !empty($f['cliente']['user_id']));

        $hechos = 0;

        foreach ($mudos as $f) {
            if ($hechos >= $maximo) {
                break;
            }

            $ont = DB::table('olt_onts as o')->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
                ->where('a.company_id', $empresa)->where('a.name', $f['ont']['olt'])->where('o.fsp', $f['ont']['fsp'])->where('o.ont_id', $f['ont']['ont_id'])
                ->first(['o.olt_id', 'o.fsp', 'o.ont_id', 'o.status', 'a.brand']);

            // Apagada en la OLT: no es gestión perdida, es un equipo apagado.
            if (!$ont || $ont->status !== 'online' || strtolower((string) $ont->brand) !== 'huawei') {
                continue;
            }

            // Una vez por día por equipo: si no se arregla con esto, no se insiste cada hora.
            $clave = "red:recuperar-gestion:{$ont->olt_id}:{$ont->fsp}:{$ont->ont_id}";
            if (cache()->has($clave)) {
                continue;
            }
            cache()->put($clave, 1, now()->addDay());

            $hechos++;
            $r = (new GestionRemotaDeOnt($empresa, app(\App\Managers\Interfaces\ConectionRouterManagerInterface::class)))
                ->recuperarGestion((int) $ont->olt_id, (string) $ont->fsp, (int) $ont->ont_id);

            $nombre = $f['cliente']['nombre'] ?? '';
            $this->line(($r['ok'] ? '  ✓ ' : '  ! ') . "{$nombre} ({$ont->fsp}:{$ont->ont_id}): {$r['detalle']}");

            DB::table('user_audit_logs')->insert([
                'user_id' => $f['cliente']['user_id'], 'changed_by' => 0, 'company_id' => $empresa,
                'field_changed' => 'equipo', 'old_value' => null, 'new_value' => null,
                'description' => ($r['ok'] ? 'Gestión remota recuperada sola: ' : 'No se pudo recuperar sola la gestión remota: ') . $r['detalle'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            Log::warning('[Red] Recuperar gestión remota', ['empresa' => $empresa, 'ont' => "{$ont->olt_id} {$ont->fsp}:{$ont->ont_id}", 'ok' => $r['ok'], 'detalle' => $r['detalle']]);
        }

        $this->line("Empresa {$empresa}: " . $mudos->count() . ' equipos sin reportar hace más de ' . self::HORAS_MUDO . " h; se intentó con {$hechos}.");
    }
}
