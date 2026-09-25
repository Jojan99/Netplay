<?php

namespace App\Services\Cobranza;

use App\Services\WhatsAppService;

/**
 * Manda el mensaje del asistente por la línea de WhatsApp Web elegida.
 * Separado para poder probar la cobranza sin escribirle a nadie.
 */
class Mensajero
{
    /**
     * Devuelve el id que WhatsApp le da al mensaje, o null.
     *
     * Hace falta para guardarlo junto al mensaje del CRM: es lo que permite
     * reconocer su eco —WhatsApp sincroniza a todos los dispositivos también
     * lo que sale— y seguir los acuses de entregado y leído.
     */
    public function enviar(int $companyId, ?string $instancia, string $telefono, string $texto): ?string
    {
        return self::idDe((new WhatsAppService($companyId, false, 'netplay', $instancia))->mensajeInformativo($telefono, $texto));
    }

    /** El QR de pago de la empresa, con un pie de foto corto. */
    public function enviarImagen(int $companyId, ?string $instancia, string $telefono, string $url, string $pie = ''): ?string
    {
        return self::idDe((new WhatsAppService($companyId, false, 'netplay', $instancia))->sendImage($telefono, $url, $pie));
    }

    /** El id del mensaje dentro de lo que contesta el servicio. */
    public static function idDe(mixed $respuesta): ?string
    {
        if (!is_array($respuesta)) {
            return null;
        }

        return $respuesta['messageId'] ?? $respuesta['messages'][0]['id'] ?? null;
    }
}
