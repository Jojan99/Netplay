<?php

namespace App\Services\FacturaElectronica\Proveedores;

/**
 * Un proveedor tecnológico de facturación electrónica (Siigo, Alegra).
 *
 * La plataforma arma un documento con sus propios nombres y cada proveedor lo
 * traduce a su API. Todos devuelven el resultado con la misma forma, así el
 * resto del sistema no sabe con cuál está hablando.
 *
 * Resultado de emitir() y notaCredito():
 *   estado       emitida | rechazada | pendiente
 *   externo_id   id del documento en el proveedor (null si no se llegó a crear)
 *   numero       número completo con prefijo
 *   cufe         CUFE (o CUDE en la nota crédito)
 *   estado_dian  el estado tal como lo nombra el proveedor
 *   pdf_url      enlace para ver el documento, si el proveedor da uno
 *   qr           contenido del QR, si lo entrega
 *   error        por qué no quedó emitida, en palabras que se le puedan mostrar a alguien
 *   respuesta    la respuesta cruda, para dejar rastro
 */
interface ProveedorDeFacturaElectronica
{
    /** Los datos que hay que pedirle a la empresa para conectarse: [clave => [etiqueta, secreto, ayuda]]. */
    public static function campos(): array;

    /** @return array{ok:bool, detalle:string} */
    public function probar(): array;

    /**
     * Lo que la empresa tiene configurado en su cuenta del proveedor y hay
     * que elegir: numeraciones, impuestos, formas de pago, productos...
     * Cada lista es [[id, nombre, ...]].
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public function catalogos(): array;

    public function emitir(array $documento, array $ajustes): array;

    public function notaCredito(array $documento, array $original, array $ajustes): array;

    /** Vuelve a preguntar por un documento que quedó sin respuesta de la DIAN. */
    public function consultar(string $externoId, string $tipo = 'factura'): array;

    /** El PDF del documento, en binario. Null si el proveedor no lo entrega. */
    public function pdf(string $externoId, string $tipo = 'factura'): ?string;
}
