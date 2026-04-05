<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\WhatsAppApiService;
use Illuminate\Console\Command;

class ProvisionWhatsAppCompanies extends Command
{
    protected $signature   = 'whatsapp:provision-companies';
    protected $description = 'Crea una cuenta WA independiente para cada empresa que no tenga una.';

    public function handle(): void
    {
        $companies = Company::whereNull('wa_company_id')->get();

        if ($companies->isEmpty()) {
            $this->info('Todas las empresas ya tienen credenciales WA.');
            return;
        }

        $waService = new WhatsAppApiService();

        foreach ($companies as $company) {
            $this->line("Provisionando: [{$company->id}] {$company->name} ...");

            try {
                $email  = $company->email ?: "empresa{$company->id}@netplay.internal";
                try {
                    $result = $waService->provisionCompany($company->name, $email, 1);
                } catch (\Throwable $dup) {
                    if (str_contains($dup->getMessage(), 'Duplicate')) {
                        // Email ya registrado — usar alias único para esta empresa
                        $email  = "netplay.empresa{$company->id}@netplay.internal";
                        $result = $waService->provisionCompany($company->name, $email, 1);
                    } else {
                        throw $dup;
                    }
                }

                // El WA service puede responder con company.id/api_key o companyId/apiKey
                $waId  = $result['company']['id']      ?? $result['companyId'] ?? null;
                $waKey = $result['company']['api_key'] ?? $result['apiKey']    ?? null;

                if ($waId && $waKey) {
                    $company->update([
                        'wa_company_id' => $waId,
                        'wa_api_key'    => $waKey,
                    ]);
                    $this->info("  ✓ wa_company_id={$waId}");

                    // Activar suscripción (el WA service la activa automáticamente al crear,
                    // pero si por alguna razón no lo hizo, se activa explícitamente aquí)
                    try {
                        $waService->subscribeCompany($waId);
                        $this->info("  ✓ Suscripción activada");
                    } catch (\Throwable $subErr) {
                        $this->warn("  ! Suscripción ya activa o error: " . $subErr->getMessage());
                    }
                } else {
                    $this->warn("  ! Respuesta inesperada: " . json_encode($result));
                }
            } catch (\Throwable $e) {
                $this->error("  ✗ Error: " . $e->getMessage());
            }
        }

        $this->info('Listo.');
    }
}
