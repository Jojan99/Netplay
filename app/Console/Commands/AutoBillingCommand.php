<?php

namespace App\Console\Commands;

use App\Models\CompanyBillingSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class AutoBillingCommand extends Command
{
    protected $signature   = 'billing:auto';
    protected $description = 'Revisa cada hora qué empresas tienen proceso de facturación programado y lo ejecuta';

    public function handle(): int
    {
        $now  = Carbon::now();
        $day  = $now->day;
        $hour = $now->hour;

        // Buscar schedules activos cuyo día y hora coincidan con ahora.
        // En el último día del mes entran también los grupos con un día que ese
        // mes no existe (29, 30 o 31 en febrero): si no, se saltarían el mes.
        $ultimo = $now->daysInMonth;

        $schedules = CompanyBillingSchedule::with('company')
            ->where(fn ($q) => $q->where('billing_day', $day)
                ->when($day === $ultimo, fn ($q2) => $q2->orWhere('billing_day', '>', $ultimo)))
            ->where('billing_hour', $hour)
            ->where('active', true)
            ->whereHas('company', fn($q) => $q->where('active', true))
            ->get();

        if ($schedules->isEmpty()) {
            $this->info("Sin procesos programados para hoy día {$day} a las {$hour}:00.");
            return Command::SUCCESS;
        }

        foreach ($schedules as $schedule) {
            $this->info("Ejecutando: Empresa {$schedule->company_id} | Grupo {$schedule->grupo} | Día factura: {$schedule->billing_day}");

            // El canal sale de lo que la empresa tiene encendido. Antes iba
            // siempre 'whatsapp' —el valor por omisión del comando— así que
            // ninguna factura salió nunca por correo desde el proceso
            // automático, aunque la empresa tuviera el correo activado.
            $canal = $this->canalDe($schedule->company);

            if (!$canal) {
                $this->warn("Empresa {$schedule->company_id}: sin WhatsApp ni correo activados, no se envía nada.");
                Log::warning('[AUTO_BILLING] Sin canal de envío', ['company_id' => $schedule->company_id]);
            }

            try {
                Artisan::call('post:create', [
                    'company_id'  => $schedule->company_id,
                    'periodo'     => $schedule->grupo,
                    'billing_day' => $schedule->billing_day,
                    '--channel'   => $canal ?: 'whatsapp',
                ]);

                Log::info('[AUTO_BILLING] Proceso ejecutado', [
                    'company_id'  => $schedule->company_id,
                    'grupo'       => $schedule->grupo,
                    'billing_day' => $schedule->billing_day,
                    'hora'        => $hour,
                    'canal'       => $canal,
                ]);

            } catch (\Throwable $e) {
                $this->error("Error empresa {$schedule->company_id} grupo {$schedule->grupo}: {$e->getMessage()}");
                Log::error('[AUTO_BILLING] Error', [
                    'company_id' => $schedule->company_id,
                    'grupo'      => $schedule->grupo,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->info('Proceso automático finalizado.');
        return Command::SUCCESS;
    }

    /**
     * Por dónde manda las facturas esta empresa.
     *
     * Lo decide lo que tiene encendido, no un valor fijo del comando. Si el
     * correo está activado pero la empresa no tiene su cuenta propia de envío,
     * igual se intenta: el servicio lo anota como «correo no configurado», que
     * es una queja visible, en vez de no intentarlo nunca y que nadie se
     * entere.
     */
    private function canalDe(?\App\Models\Company $empresa): ?string
    {
        if (!$empresa) {
            return null;
        }

        $wa = (bool) $empresa->whatsapp_enabled;
        $correo = (bool) $empresa->email_enabled;

        return match (true) {
            $wa && $correo => 'both',
            $wa            => 'whatsapp',
            $correo        => 'email',
            default        => null,
        };
    }
}
