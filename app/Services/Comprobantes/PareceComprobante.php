<?php

namespace App\Services\Comprobantes;

/**
 * ¿El texto leído de una imagen es el de un comprobante de pago?
 *
 * Las mismas reglas que el lector del servicio de WhatsApp Web (services/ocrService.js): una
 * sola señal nunca alcanza —un volante de precios tiene importes, la app del banco sin
 * transacción tiene el nombre del banco, una pantalla de inicio de sesión tiene «usuario» y
 * «contraseña»—. Hace falta saber QUÉ pasó (un envío, una transferencia) y CUÁNTO.
 */
class PareceComprobante
{
    /** Quién movió la plata. */
    private const ENTIDADES = [
        'nequi', 'daviplata', 'bancolombia', 'davivienda', 'bbva', 'banco de bogota', 'banco de bogotá', 'caja social',
        'colpatria', 'av villas', 'banco agrario', 'scotiabank', 'itau', 'itaú', 'falabella', 'pibank', 'lulo bank',
        'movii', 'rappipay', 'powwi', 'pse', 'wompi', 'corresponsal', 'efecty', 'su red', 'sured', 'baloto', 'pagatodo', 'supergiros', 'bre-b', 'breb',
    ];

    /** Que esto pasó. */
    private const HECHO = [
        'comprobante', 'transferencia', 'consignacion', 'consignación', 'voucher', 'transaccion exitosa', 'transacción exitosa',
        'pago exitoso', 'envio exitoso', 'envío exitoso', 'te enviaron', 'enviaste', 'recibiste', 'detalle del movimiento',
        'envio realizado', 'envío realizado', 'pago realizado', 'resumen de pago', 'transferencia exitosa', 'verificar tu envio', 'verificar tu envío', 'salio la plata', 'salió la plata',
    ];

    /** Rastro de la operación: solas no alcanzan, pero suman. */
    private const RASTRO = [
        'referencia', 'autorizacion', 'autorización', 'aprobada', 'aprobado', 'exitosa', 'exitoso', 'realizada', 'realizado',
        'recibo', 'pagado', 'cuenta de ahorros', 'cuenta corriente', 'numero de cuenta', 'número de cuenta', 'destinatario',
        'beneficiario', 'titular', 'n° de transaccion', 'cus', 'transacción', 'transaccion', 'monto', 'destino', 'producto origen', 'payment', 'receipt', 'transfer', 'transaction', 'approved', 'successful',
    ];

    /**
     * Pedazos de la pantalla de WhatsApp o del teléfono. Una captura de un chat no es un
     * comprobante aunque adentro se vea uno reenviado (casi siempre viejo).
     */
    private const CHAT = [
        'cuenta de empresa', 'reenviado', 'es un contacto', 'duracion de los mensajes', 'duración de los mensajes',
        'mensajes nuevos desapareceran', 'mensajes nuevos desaparecerán', 'se unio en', 'se unió en',
        'numero de telefono de colombia', 'número de teléfono de colombia', 'bloquear perfil', 'escribe un mensaje',
        'escribiendo...', 'cifrado de extremo a extremo', 'visto por ultima vez', 'visto por última vez',
        'responde con el numero de cedula', 'respondé con el número de cédula', 'recibimos tu comprobante',
        'redes disponibles', 'sin acceso a internet', 'conectado sin internet',
    ];

    public static function es(string $texto): bool
    {
        $t = mb_strtolower($texto);

        if (self::cuantas($t, self::CHAT) > 0) {
            return false;
        }

        $entidades = self::cuantas($t, self::ENTIDADES);
        $hechos = self::cuantas($t, self::HECHO);
        $rastros = self::cuantas($t, self::RASTRO);
        $importe = self::traeImporte($t);
        // Una captura del saldo tiene banco, importe y «cuenta de ahorros», pero nadie transfirió nada.
        $esSaldo = (bool) preg_match('/saldo\s+(disponible|total|en cuenta|actual)/u', $t);

        return ($hechos > 0 && $importe)
            || ($entidades > 0 && $importe && $rastros >= 2 && !$esSaldo)
            || ($hechos > 0 && $entidades > 0)
            || ($rastros >= 3 && $importe && !$esSaldo)
            // Fotos tomadas a otra pantalla: el valor se lee mal, pero el resto del comprobante no.
            || $hechos >= 2
            || ($entidades > 0 && $rastros >= 3 && !$esSaldo);
    }

    private static function cuantas(string $t, array $lista): int
    {
        return count(array_filter($lista, fn ($k) => str_contains($t, $k)));
    }

    private static function traeImporte(string $t): bool
    {
        return (bool) (preg_match('/\$\s?\d{1,3}(?:[.,]\d{3})+/u', $t)
            || preg_match('/\b\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{2})?\b/u', $t)
            || preg_match('/\b(?:cop|cop\$)\s?\d{3,}/u', $t)
            || preg_match('/(?:valor|monto|total|importe)\s*[:$]?\s*\d{3,}/u', $t));
    }
}
