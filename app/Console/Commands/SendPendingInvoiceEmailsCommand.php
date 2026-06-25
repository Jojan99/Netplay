<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendInvoiceEmailJob;

class SendPendingInvoiceEmailsCommand extends Command
{
    protected $signature = 'invoices:send-pending-emails
                            {company_id? : ID de la empresa (omite para procesar todas)}
                            {--limit= : Máximo de facturas a enviar (usa email_daily_limit de la empresa si no se indica)}
                            {--periodo= : Grupo/período de facturación (opcional)}
                            {--sync : Enviar sincrónicamente sin usar colas}';

    protected $description = 'Envía facturas pendientes por correo electrónico en lotes diarios (por defecto 200)';

    public function handle(): int
    {
        $companyId = $this->argument('company_id');
        $periodo = $this->option('periodo');
        $useSync = $this->option('sync') || config('queue.default') === 'sync';

        // Si no se especifica company_id, procesar TODAS las empresas con email habilitado
        if ($companyId === null) {
            $companies = \App\Models\Company::where('email_enabled', true)
                ->where('email_daily_limit', '>', 0)
                ->get();

            if ($companies->isEmpty()) {
                $this->info('No hay empresas con email habilitado y límite diario configurado.');
                return Command::SUCCESS;
            }

            $totalSent = 0;
            foreach ($companies as $company) {
                $sent = $this->processCompany($company->id, $company->email_daily_limit, $periodo, $useSync);
                $totalSent += $sent;
            }

            $this->info("Proceso completado. Total de facturas enviadas/encoladas: {$totalSent}");
            return Command::SUCCESS;
        }

        // Procesar una sola empresa
        $companyId = (int) $companyId;
        $company = \App\Models\Company::find($companyId);
        $limit = $this->option('limit');
        if ($limit === null) {
            $limit = $company ? ($company->email_daily_limit ?: 200) : 200;
        } else {
            $limit = (int) $limit;
        }

        $sent = $this->processCompany($companyId, $limit, $periodo, $useSync);
        $this->info("Proceso completado. Facturas enviadas/encoladas: {$sent}");

        return Command::SUCCESS;
    }

    /**
     * Procesa las facturas pendientes de una empresa.
     *
     * @return int Cantidad de facturas procesadas
     */
    private function processCompany(int $companyId, int $limit, ?string $periodo, bool $useSync): int
    {
        if ($limit < 1 || $limit > 1000) {
            $this->error("Empresa {$companyId}: El límite debe estar entre 1 y 1000.");
            return 0;
        }

        $query = DB::table('det_facturations as dt')
            ->join('cab_facturations as cb', 'cb.id', '=', 'dt.cab_id')
            ->where('cb.company_id', $companyId)
            ->whereNull('dt.email_sent_at')
            ->where('dt.paid', 0)
            ->orderBy('dt.id');

        if ($periodo) {
            $query->where('cb.group', (int) $periodo);
        }

        $pendings = $query->select('dt.id as det_facturation_id')
            ->limit($limit)
            ->get();

        if ($pendings->isEmpty()) {
            return 0;
        }

        $this->info("Empresa {$companyId}: {$pendings->count()} facturas pendientes. Enviando...");

        foreach ($pendings as $p) {
            try {
                if ($useSync) {
                    (new SendInvoiceEmailJob($p->det_facturation_id, $companyId))->handle();
                } else {
                    SendInvoiceEmailJob::dispatch($p->det_facturation_id, $companyId);
                }
            } catch (\Throwable $e) {
                Log::error('[SEND_PENDING_EMAILS] Error procesando factura', [
                    'company_id' => $companyId,
                    'det_id' => $p->det_facturation_id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Empresa {$companyId} - Error en factura {$p->det_facturation_id}: {$e->getMessage()}");
            }
        }

        return $pendings->count();
    }
}
