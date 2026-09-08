<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\WaTemplateBinding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Enlaza las plantillas aprobadas en Meta con los avisos del sistema.
 *
 * Meta obliga a aprobar cada plantilla por nombre; cuando se corrige una hay que
 * publicarla como `_v2`. Este comando lee las aprobadas de cada empresa, elige la
 * mejor para cada evento (prefiere la versión más nueva) y deja los parámetros en
 * el orden que espera el cuerpo del mensaje.
 */
class SyncMetaTemplates extends Command
{
    protected $signature = 'wa:sync-templates {--company= : Sólo esta empresa} {--force : Reemplaza los enlaces ya configurados} {--enable : Deja los avisos activados}';
    protected $description = 'Enlaza las plantillas aprobadas de Meta con los eventos del sistema';

    /**
     * Para cada evento: nombres candidatos (de más a menos preferido) y el orden de
     * variables que espera el cuerpo de la plantilla.
     */
    private const MAPA = [
        'envio_factura' => [
            'nombres' => ['envio_factura_v2', 'envio_factura'],
            'params'  => ['cliente', 'factura', 'valor', 'fecha_emision', 'fecha_vencimiento', 'empresa'],
        ],
        'recordatorio_pago' => [
            'nombres' => ['recordatorio_de_pago_v2', 'recordatorio_de_pago', 'recordatorio_pago'],
            'params'  => ['cliente', 'plan', 'valor', 'fecha_vencimiento', 'soporte'],
        ],
        'suspension_mora' => [
            'nombres' => ['suspendido_por_mora_v2', 'suspendido_por_mora', 'suspension_mora'],
            'params'  => ['cliente', 'plan', 'total_pendiente', 'dias_mora', 'fecha_vencimiento', 'soporte'],
        ],
        'pago_aprobado' => [
            'nombres' => ['pago_exitoso', 'pago_aprobado'],
            'params'  => ['cliente', 'valor', 'plan', 'factura', 'referencia'],
        ],
        'pago_fallido' => [
            'nombres' => ['pago_cancelado', 'pago_rechazado', 'pago_fallido'],
            'params'  => ['cliente', 'valor', 'plan', 'factura', 'referencia', 'soporte'],
        ],
        'servicio_reactivado' => [
            'nombres' => ['servicio_reactivado', 'reactivacion_servicio'],
            'params'  => ['cliente', 'plan', 'soporte'],
        ],
        'pago_pendiente' => [
            'nombres' => ['pago_pendiente', 'informacion_general'],
            'params'  => ['cliente', 'plan', 'texto_libre', 'soporte'],
        ],
    ];

    public function handle(): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn($q, $id) => $q->where('id', $id))
            ->where('wa_provider', 'meta')
            ->whereNotNull('wa_business_id')
            ->whereNotNull('wa_access_token')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('No hay empresas con WhatsApp de Meta configurado.');
            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $this->line("→ {$company->name}");
            $aprobadas = $this->plantillasAprobadas($company);

            if (!$aprobadas) {
                $this->warn('  No se pudieron leer las plantillas de Meta.');
                continue;
            }
            $this->line('  Aprobadas: ' . implode(', ', array_keys($aprobadas)));

            foreach (self::MAPA as $evento => $cfg) {
                $elegida = null;
                foreach ($cfg['nombres'] as $nombre) {
                    if (isset($aprobadas[$nombre])) { $elegida = $aprobadas[$nombre]; break; }
                }
                if (!$elegida) {
                    $this->line("  · $evento: sin plantilla aprobada");
                    continue;
                }

                $binding = WaTemplateBinding::firstOrNew(['company_id' => $company->id, 'event' => $evento]);
                if ($binding->exists && $binding->template_name && !$this->option('force')) {
                    // Ya configurado a mano: sólo se corrige si la plantilla dejó de existir
                    if (isset($aprobadas[$binding->template_name])) {
                        $this->line("  · $evento: ya enlazado a {$binding->template_name}");
                        continue;
                    }
                    $this->line("  · $evento: {$binding->template_name} ya no está aprobada, se cambia");
                }

                // Recorta los parámetros a las variables reales del cuerpo
                $params = array_slice($cfg['params'], 0, $elegida['vars']);

                $binding->template_name = $elegida['name'];
                $binding->language      = $elegida['language'] ?: 'es_CO';
                $binding->params        = $params;
                if ($this->option('enable')) $binding->enabled = true;
                if ($binding->config === null && !empty(WaTemplateBinding::EVENTS[$evento]['programado'])) {
                    $binding->config = WaTemplateBinding::CONFIG_POR_DEFECTO;
                }
                $binding->save();

                $this->info("  ✓ $evento → {$elegida['name']} ({$elegida['vars']} variables)");
            }
        }

        $this->newLine();
        $this->info('Listo. Revisá Comunicación › WhatsApp › Plantillas para activarlos.');
        return self::SUCCESS;
    }

    /** @return array<string, array{name:string, language:string, vars:int}> */
    private function plantillasAprobadas(Company $company): array
    {
        try {
            $res = Http::withToken($company->wa_access_token)
                ->timeout(20)
                ->get("https://graph.facebook.com/v21.0/{$company->wa_business_id}/message_templates", ['limit' => 200]);

            if (!$res->successful()) return [];

            $out = [];
            foreach ($res->json('data', []) as $t) {
                if (($t['status'] ?? '') !== 'APPROVED') continue;
                $body = '';
                foreach ($t['components'] ?? [] as $c) {
                    if (($c['type'] ?? '') === 'BODY') $body = $c['text'] ?? '';
                }
                preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
                $out[$t['name']] = [
                    'name'     => $t['name'],
                    'language' => $t['language'] ?? 'es_CO',
                    'vars'     => $m[1] ? max(array_map('intval', $m[1])) : 0,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            $this->warn('  ' . $e->getMessage());
            return [];
        }
    }
}
