<?php

namespace App\Services\Facturacion;

use App\Models\Company;
use App\Services\Correo\Correo;

/**
 * Si la empresa puede entregar las facturas que va a generar, y qué le falta.
 *
 * El envío masivo tiene dos reglas y ninguna es opcional: por WhatsApp sólo
 * sale con la API de Meta y una plantilla aprobada —es el único camino que
 * Meta permite para escribirle primero a un cliente—, y por correo sólo con la
 * cuenta de envío propia de la empresa, porque son las facturas de su ISP y
 * van con su remitente.
 *
 * Vive acá y no dentro del proceso de facturación porque hacen falta dos
 * respuestas distintas a la misma pregunta: una al momento de facturar, para
 * explicar por qué no salió, y otra días antes, para avisar a tiempo. Si cada
 * una lo comprobara por su lado, tarde o temprano dirían cosas distintas.
 */
class CanalDeFacturacion
{
    /** Qué le falta para mandar por WhatsApp, o null si puede. */
    public static function porQueNoPuedeWhatsapp(?Company $empresa): ?string
    {
        if (!$empresa) {
            return 'no se encontró la empresa.';
        }

        if (!$empresa->whatsapp_enabled) {
            return 'tiene el envío por WhatsApp desactivado.';
        }

        if ($empresa->wa_provider !== 'meta') {
            return 'no tiene WhatsApp de Meta activo. El envío masivo sólo funciona con la API oficial: la línea propia no puede escribirle primero a un cliente.';
        }

        try {
            $meta = new \App\Services\MetaWhatsAppService((int) $empresa->id);

            if (!$meta->isInvoiceTemplateApproved()) {
                return "la plantilla «{$meta->invoiceTemplateName()}» no está aprobada por Meta. Mientras no lo esté, Meta rechaza el envío.";
            }
        } catch (\Throwable $e) {
            return 'no se pudo comprobar la plantilla con Meta: ' . $e->getMessage();
        }

        return null;
    }

    /** Qué le falta para mandar por correo, o null si puede. */
    public static function porQueNoPuedeCorreo(?Company $empresa): ?string
    {
        if (!$empresa) {
            return 'no se encontró la empresa.';
        }

        if (!$empresa->email_enabled) {
            return 'tiene el envío por correo desactivado.';
        }

        if (!Correo::tieneCuentaPropia($empresa)) {
            return 'no tiene su cuenta de envío activa y verificada. El correo de la plataforma no se usa para las facturas de un ISP.';
        }

        return null;
    }

    /**
     * ¿Hay algún camino por el que las facturas puedan salir?
     *
     * Alcanza con uno: una empresa que manda todo por WhatsApp no necesita el
     * correo.
     */
    public static function puedeEntregar(?Company $empresa): bool
    {
        return self::porQueNoPuedeWhatsapp($empresa) === null
            || self::porQueNoPuedeCorreo($empresa) === null;
    }

    /**
     * Lo que le falta a cada canal, para contarlo.
     *
     * @return array{whatsapp: ?string, correo: ?string}
     */
    public static function estado(?Company $empresa): array
    {
        return [
            'whatsapp' => self::porQueNoPuedeWhatsapp($empresa),
            'correo'   => self::porQueNoPuedeCorreo($empresa),
        ];
    }
}
