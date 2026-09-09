<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Services\MetaWhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Decide por dónde sale un envío manual de WhatsApp y si puede salir.
 *
 * El problema que resuelve: los envíos manuales (factura, comprobante, avisos
 * sueltos) se hacían con el proveedor por defecto de la empresa. Si ese
 * proveedor es la API de Meta y el cliente no escribió en las últimas 24 h, el
 * envío se rechaza — y repetir intentos fuera de ventana es lo que termina
 * costando el bloqueo de la línea.
 *
 * Con esto la decisión se toma ANTES de intentar:
 *   - WhatsApp Web no tiene ventana: sale siempre.
 *   - Meta con ventana abierta: sale como mensaje normal.
 *   - Meta con ventana cerrada: hay que usar una plantilla aprobada.
 */
class CanalDeEnvio
{
    public const WEB   = 'netplay';
    public const META  = 'meta';

    /** Qué se puede hacer con este destinatario por este canal. */
    public const LIBRE     = 'libre';      // mensaje normal, sin restricción
    public const PLANTILLA = 'plantilla';  // solo plantilla aprobada
    public const IMPOSIBLE = 'imposible';  // el canal no está disponible

    public function __construct(private int $companyId) {}

    /** Los canales que la empresa tiene realmente configurados. */
    public function disponibles(): array
    {
        $company = Company::find($this->companyId);

        return [
            self::WEB  => (bool) ($company?->wa_instance_id),
            self::META => (bool) ($company?->wa_phone_number_id),
        ];
    }

    /** El canal por defecto de la empresa, si no se elige uno. */
    public function porDefecto(): string
    {
        $company = Company::find($this->companyId);

        return $company?->wa_provider === self::META ? self::META : self::WEB;
    }

    /**
     * Evalúa un envío antes de intentarlo.
     *
     * @return array{canal:string, modo:string, motivo:?string}
     */
    public function evaluar(?string $canal, string $telefono): array
    {
        $canal = in_array($canal, [self::WEB, self::META], true) ? $canal : $this->porDefecto();
        $hay   = $this->disponibles();

        if (!($hay[$canal] ?? false)) {
            return [
                'canal'  => $canal,
                'modo'   => self::IMPOSIBLE,
                'motivo' => $canal === self::META
                    ? 'La empresa no tiene configurada la API de Meta.'
                    : 'La empresa no tiene una línea de WhatsApp Web vinculada.',
            ];
        }

        // WhatsApp Web no tiene ventana de 24 h
        if ($canal === self::WEB) {
            return ['canal' => self::WEB, 'modo' => self::LIBRE, 'motivo' => null];
        }

        try {
            $abierta = (new MetaWhatsAppService($this->companyId))->hasOpenCustomerWindow($telefono);
        } catch (\Throwable $e) {
            Log::warning('[CanalDeEnvio] No se pudo evaluar la ventana de Meta', [
                'company_id' => $this->companyId, 'error' => $e->getMessage(),
            ]);
            // Ante la duda se asume cerrada: mandar una plantilla de más es
            // inofensivo, insistir fuera de ventana es lo que bloquea la línea.
            $abierta = false;
        }

        return [
            'canal'  => self::META,
            'modo'   => $abierta ? self::LIBRE : self::PLANTILLA,
            'motivo' => $abierta ? null : 'El cliente no escribió en las últimas 24 horas, así que Meta solo acepta una plantilla aprobada.',
        ];
    }
}
