<?php

namespace App\Services\Pagos;

/**
 * El sobre cifrado con el que Meta habla con nuestros Flows.
 *
 * Meta no manda los datos en claro: cifra el cuerpo con una clave AES de un
 * solo uso, y esa clave viaja cifrada con nuestra llave pública RSA. La
 * respuesta va cifrada con la misma clave AES, pero con el vector de
 * inicialización invertido bit a bit —así lo pide Meta, y si no se hace, el
 * teléfono muestra «algo salió mal» sin decir más.
 *
 * La llave privada vive en el .env (WHATSAPP_FLOW_PRIVATE_KEY); la pública se
 * sube una vez al número de WhatsApp desde el panel de Meta.
 */
class SobreCifrado
{
    /**
     * Abre lo que mandó Meta.
     *
     * @return array{datos: array, clave: string, iv: string}
     */
    public static function abrir(array $cuerpo): array
    {
        $privada = self::llavePrivada();

        $claveCifrada = base64_decode((string) ($cuerpo['encrypted_aes_key'] ?? ''));
        $datos        = base64_decode((string) ($cuerpo['encrypted_flow_data'] ?? ''));
        $iv           = base64_decode((string) ($cuerpo['initial_vector'] ?? ''));

        if (!$claveCifrada || !$datos || !$iv) {
            throw new \RuntimeException('El sobre de Meta vino incompleto.');
        }

        $clave = '';

        if (!openssl_private_decrypt($claveCifrada, $clave, $privada, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new \RuntimeException('No se pudo abrir la clave: ¿la llave pública que subiste a Meta es la de este servidor?');
        }

        // Los últimos 16 bytes son la etiqueta de autenticidad.
        $etiqueta = substr($datos, -16);
        $cuerpoCifrado = substr($datos, 0, -16);

        $claro = openssl_decrypt($cuerpoCifrado, 'aes-128-gcm', $clave, OPENSSL_RAW_DATA, $iv, $etiqueta);

        if ($claro === false) {
            throw new \RuntimeException('El contenido no se pudo descifrar.');
        }

        return [
            'datos' => json_decode($claro, true) ?: [],
            'clave' => $clave,
            'iv'    => $iv,
        ];
    }

    /** Cierra la respuesta con la misma clave y el vector invertido. */
    public static function cerrar(array $respuesta, string $clave, string $iv): string
    {
        $invertido = $iv ^ str_repeat("\xff", strlen($iv));
        $etiqueta = '';

        $cifrado = openssl_encrypt(
            json_encode($respuesta),
            'aes-128-gcm',
            $clave,
            OPENSSL_RAW_DATA,
            $invertido,
            $etiqueta,
        );

        return base64_encode($cifrado . $etiqueta);
    }

    /** @return \OpenSSLAsymmetricKey */
    private static function llavePrivada()
    {
        $pem = (string) config('services.whatsapp_flow.private_key', env('WHATSAPP_FLOW_PRIVATE_KEY', ''));
        $pem = str_replace('\\n', "\n", $pem);

        if (!$pem) {
            throw new \RuntimeException('Falta WHATSAPP_FLOW_PRIVATE_KEY en el .env.');
        }

        $llave = openssl_pkey_get_private($pem, (string) env('WHATSAPP_FLOW_PASSPHRASE', ''));

        if (!$llave) {
            throw new \RuntimeException('La llave privada del Flow no se pudo leer.');
        }

        return $llave;
    }
}
