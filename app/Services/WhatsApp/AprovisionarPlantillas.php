<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\WaTemplateBinding;
use App\Support\PlantillasSemilla;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le crea a una empresa las plantillas que el sistema necesita para operar.
 *
 * Una plantilla aprobada solo sirve en la cuenta de Meta donde fue aprobada,
 * así que no se pueden compartir entre empresas. Lo que se comparte es el
 * texto: aquí se crean en la cuenta de la empresa y quedan en revisión de Meta.
 *
 * Es idempotente: si la plantilla ya existe en la cuenta no se vuelve a crear,
 * solo se deja el vínculo con su evento.
 */
class AprovisionarPlantillas
{
    public function __construct(private int $companyId) {}

    /**
     * Crea las que falten y vincula cada una a su evento.
     *
     * @param  array<int,string>|null  $eventos  Solo estos; null = todas.
     * @return array{ok:bool, creadas:array, existentes:array, errores:array}
     */
    public function ejecutar(?array $eventos = null): array
    {
        $company = Company::find($this->companyId);

        if (!$company || !$company->wa_access_token || !$company->wa_business_id) {
            return [
                'ok'         => false,
                'creadas'    => [],
                'existentes' => [],
                'errores'    => ['La empresa no tiene la API de Meta configurada (falta el token o el business id).'],
            ];
        }

        $yaEnMeta   = $this->nombresExistentes($company);
        $creadas    = [];
        $existentes = [];
        $errores    = [];

        foreach (PlantillasSemilla::todas() as $evento => $def) {
            if ($eventos !== null && !in_array($evento, $eventos, true)) {
                continue;
            }

            try {
                if (in_array(strtolower($def['nombre']), $yaEnMeta, true)) {
                    $existentes[] = $def['nombre'];
                } else {
                    $this->crearEnMeta($company, $def);
                    $creadas[] = $def['nombre'];
                }

                $this->vincular($evento, $def);
            } catch (\Throwable $e) {
                $errores[] = "{$def['nombre']}: {$e->getMessage()}";
                Log::warning('[Plantillas] No se pudo aprovisionar', [
                    'company_id' => $this->companyId,
                    'evento'     => $evento,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return [
            'ok'         => $errores === [],
            'creadas'    => $creadas,
            'existentes' => $existentes,
            'errores'    => $errores,
        ];
    }

    /**
     * Estado de cada plantilla del catálogo en la cuenta de la empresa.
     *
     * Meta tarda de minutos a un día en aprobar, así que el panel tiene que
     * poder mostrar en qué va cada una y el motivo si la rechazan.
     *
     * @return array<int, array<string,mixed>>
     */
    public function estado(bool $refrescar = true): array
    {
        $company = Company::find($this->companyId);
        $enMeta  = [];
        $consultado = false;

        if ($refrescar && $company && $company->wa_access_token && $company->wa_business_id) {
            foreach ($this->plantillasDeMeta($company) as $t) {
                $enMeta[strtolower($t['name'] ?? '')] = $t;
                $consultado = true;
            }
        }

        $vinculos = WaTemplateBinding::where('company_id', $this->companyId)->get()->keyBy('event');

        $salida = [];

        foreach (PlantillasSemilla::todas() as $evento => $def) {
            $binding   = $vinculos[$evento] ?? null;
            $vinculada = $binding?->template_name;

            // Se mira la que está vinculada, que puede ser una propia de la
            // empresa y no la del catálogo.
            $enCuenta = $enMeta[strtolower($vinculada ?: $def['nombre'])] ?? null;

            // Lo último que se supo, guardado en el propio vínculo. Así la
            // pantalla abre al instante y no depende de que Meta responda;
            // el botón Actualizar es el que vuelve a preguntarle.
            $guardado = $this->guardadoDe($binding);

            if ($consultado) {
                $estado = $enCuenta['status'] ?? 'NO_CREADA';
                $motivo = $enCuenta['rejected_reason'] ?? null;
                $this->guardarEstado($binding, $evento, $def, $estado, $motivo);
            } else {
                $estado = $guardado['estado'] ?? 'NO_CREADA';
                $motivo = $guardado['motivo'] ?? null;
            }

            $salida[] = [
                'evento'      => $evento,
                'label'       => WaTemplateBinding::EVENTS[$evento]['label'] ?? $def['descripcion'],
                'descripcion' => $def['descripcion'],
                'sugerida'    => $def['nombre'],
                'vinculada'   => $vinculada,
                'propia'      => $vinculada !== null && strtolower($vinculada) !== strtolower($def['nombre']),
                'estado'      => $estado,
                'motivo'      => $motivo,
                'revisado'    => $consultado ? now()->toDateTimeString() : ($guardado['revisado'] ?? null),
                'variables'   => $def['variables'],
            ];
        }

        return $salida;
    }

    /** Lo último que se supo del estado de esta plantilla en Meta. */
    private function guardadoDe(?WaTemplateBinding $binding): array
    {
        if (!$binding || !$binding->config) {
            return [];
        }

        $config = is_array($binding->config) ? $binding->config : json_decode((string) $binding->config, true);

        return is_array($config['meta'] ?? null) ? $config['meta'] : [];
    }

    /**
     * Guarda el estado en el propio vínculo, para no tener que preguntarle a
     * Meta cada vez que alguien abre la pantalla.
     */
    private function guardarEstado(?WaTemplateBinding $binding, string $evento, array $def, string $estado, ?string $motivo): void
    {
        try {
            $binding = $binding ?: WaTemplateBinding::firstOrNew([
                'company_id' => $this->companyId,
                'event'      => $evento,
            ]);

            $config = is_array($binding->config)
                ? $binding->config
                : (json_decode((string) $binding->config, true) ?: []);

            $config['meta'] = [
                'estado'   => $estado,
                'motivo'   => $motivo,
                'revisado' => now()->toDateTimeString(),
            ];

            $binding->config = $config;

            // Un vínculo nuevo nace apagado y apuntando a la del catálogo:
            // que exista el registro no significa que el aviso esté activo.
            if (!$binding->exists) {
                $binding->template_name = $binding->template_name ?: $def['nombre'];
                $binding->language      = $binding->language ?: $def['idioma'];
                $binding->enabled       = false;
            }

            $binding->save();
        } catch (\Throwable $e) {
            Log::warning('[Plantillas] No se pudo guardar el estado', [
                'company_id' => $this->companyId, 'evento' => $evento, 'error' => $e->getMessage(),
            ]);
        }
    }

    /* ── Meta ────────────────────────────────────────────────────────── */

    private function crearEnMeta(Company $company, array $def): void
    {
        $url = 'https://graph.facebook.com/' . config('services.meta_whatsapp.api_version', 'v21.0')
             . "/{$company->wa_business_id}/message_templates";

        $r = Http::withToken($company->wa_access_token)
            ->timeout(30)
            ->post($url, PlantillasSemilla::payloadMeta($def));

        if ($r->failed()) {
            throw new \RuntimeException($r->json('error.error_user_msg') ?? $r->json('error.message') ?? 'Meta rechazó la plantilla.');
        }
    }

    /** @return array<int,string> nombres en minúscula */
    private function nombresExistentes(Company $company): array
    {
        return array_map(
            fn ($t) => strtolower($t['name'] ?? ''),
            $this->plantillasDeMeta($company)
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function plantillasDeMeta(Company $company): array
    {
        try {
            $url = 'https://graph.facebook.com/' . config('services.meta_whatsapp.api_version', 'v21.0')
                 . "/{$company->wa_business_id}/message_templates";

            $r = Http::withToken($company->wa_access_token)->timeout(30)->get($url, ['limit' => 200]);

            return $r->successful() ? ($r->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('[Plantillas] No se pudo leer el catálogo de Meta', [
                'company_id' => $this->companyId, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /* ── Vínculo con el evento ───────────────────────────────────────── */

    /**
     * No pisa un vínculo que la empresa ya haya elegido a mano: si apuntó el
     * evento a una plantilla propia, esa manda.
     */
    private function vincular(string $evento, array $def): void
    {
        $actual = WaTemplateBinding::where('company_id', $this->companyId)
            ->where('event', $evento)
            ->first();

        if ($actual && $actual->template_name && strtolower($actual->template_name) !== strtolower($def['nombre'])) {
            return;
        }

        WaTemplateBinding::updateOrCreate(
            ['company_id' => $this->companyId, 'event' => $evento],
            [
                'template_name' => $def['nombre'],
                'language'      => $def['idioma'],
                'params'        => json_encode($def['variables']),
                'enabled'       => $actual->enabled ?? false,
            ]
        );
    }
}
