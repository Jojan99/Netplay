<?php

namespace App\Services\Cobranza;

use App\Services\WhatsAppService;

/**
 * Manda el mensaje del asistente por la línea de WhatsApp Web elegida.
 * Separado para poder probar la cobranza sin escribirle a nadie.
 */
class Mensajero
{
    public function enviar(int $companyId, ?string $instancia, string $telefono, string $texto): void
    {
        (new WhatsAppService($companyId, false, 'netplay', $instancia))->mensajeInformativo($telefono, $texto);
    }
}
