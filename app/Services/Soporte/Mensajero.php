<?php

namespace App\Services\Soporte;

use App\Services\WhatsAppService;

/**
 * Lo que el asistente de soporte hace hacia afuera por WhatsApp: escribirle al
 * cliente y callar (o soltar) al otro bot. Sale por el mismo canal y la misma
 * línea por la que escribió el cliente: WhatsApp Web o la API de Meta.
 *
 * Separado para poder probar el asistente sin escribirle a nadie.
 */
class Mensajero
{
    /** Devuelve el id que WhatsApp le da al mensaje, o null. */
    public function enviar(int $companyId, string $provider, ?string $instancia, string $telefono, string $texto): ?string
    {
        // El asistente guarda su propio mensaje en el CRM.
        return \App\Services\Cobranza\Mensajero::idDe(
            \App\Services\Crm\SalidasDeMetaAlCrm::sinAnotar(fn () => (new WhatsAppService($companyId, false, $provider, $instancia))->mensajeInformativo($telefono, $texto))
        );
    }

    /** El bot de WhatsApp Web corre en otro servicio y lleva su propia lista de pausas. */
    public function pausarBotWeb(int $companyId, ?string $instancia, string $telefono, bool $pausado): void
    {
        (new WhatsAppService($companyId, false, 'netplay', $instancia))->setBotPaused($telefono, $pausado);
    }
}
