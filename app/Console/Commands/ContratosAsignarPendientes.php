<?php

namespace App\Console\Commands;

use App\Models\ClientContract;
use App\Services\Contratos\FechaDeInstalacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Asigna la plantilla de contrato a los clientes activos que todavía no tienen uno, con su fecha
 * de instalación (orden terminada o alta). No envía nada: quedan pendientes de firma para irlos
 * mandando de a pocos desde Contratos.
 */
class ContratosAsignarPendientes extends Command
{
    protected $signature = 'contratos:asignar-pendientes {--empresa= : Empresa} {--contrato= : Plantilla (contracts.id)} {--simular : Solo cuenta, no asigna}';

    protected $description = 'Asigna el contrato a los clientes activos que no tienen, con su fecha de instalación';

    public function handle(): int
    {
        $empresa = (int) $this->option('empresa');
        $contrato = (int) $this->option('contrato');

        if (!$empresa || !DB::table('contracts')->where('id', $contrato)->where('company_id', $empresa)->where('active', 1)->exists()) {
            $this->error('Indique --empresa y una --contrato activa de esa empresa.');
            return self::FAILURE;
        }

        $clientes = DB::table('users as u')
            ->join('user_data as ud', 'ud.user_id', '=', 'u.id')
            ->where('u.company_id', $empresa)->where('ud.active', 1)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('client_contracts as cc')->whereColumn('cc.user_id', 'u.id'))
            ->orderBy('u.id')
            ->pluck('u.id');

        $this->line("Clientes activos sin contrato: {$clientes->count()}");
        if ($this->option('simular')) {
            return self::SUCCESS;
        }

        $hechos = 0;
        foreach ($clientes as $id) {
            ClientContract::create([
                'company_id'        => $empresa,
                'contract_id'       => $contrato,
                'user_id'           => (int) $id,
                'fecha_instalacion' => FechaDeInstalacion::de((int) $id)?->toDateString(),
                'status'            => 'pending',
                'token'             => bin2hex(random_bytes(32)),
                'require_documents' => true,
            ]);
            $hechos++;
        }

        $this->info("Asignados: {$hechos}. Quedan pendientes de firma; se envían desde Contratos.");

        return self::SUCCESS;
    }
}
