<?php

namespace App\Services\Vpn;

/**
 * Claves de WireGuard, generadas aquí mismo.
 *
 * WireGuard usa Curve25519, que es lo que hace `sodium_crypto_scalarmult_base`,
 * así que no hace falta tener instalado `wg` para dar de alta un túnel: la
 * plataforma puede crear el par de claves del router y entregarlo en el script
 * sin depender del binario del sistema.
 *
 * El recorte de la clave privada es el del propio algoritmo —los tres bits
 * bajos y los dos altos son fijos—; `wg genkey` hace exactamente esto.
 */
class ClavesWireguard
{
    /**
     * @return array{privada:string, publica:string}  ambas en base64
     */
    public static function par(): array
    {
        $privada = self::recortar(random_bytes(32));

        return [
            'privada' => base64_encode($privada),
            'publica' => base64_encode(sodium_crypto_scalarmult_base($privada)),
        ];
    }

    /** Clave simétrica extra (preshared): suma una capa contra criptoanálisis futuro. */
    public static function compartida(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** La pública que corresponde a una privada ya existente. */
    public static function publicaDe(string $privadaBase64): ?string
    {
        $privada = base64_decode($privadaBase64, true);

        if ($privada === false || strlen($privada) !== 32) {
            return null;
        }

        return base64_encode(sodium_crypto_scalarmult_base($privada));
    }

    /** ¿Tiene forma de clave de WireGuard? */
    public static function valida(?string $clave): bool
    {
        if (!$clave) {
            return false;
        }

        $bytes = base64_decode($clave, true);

        return $bytes !== false && strlen($bytes) === 32;
    }

    private static function recortar(string $bytes): string
    {
        $b = array_values(unpack('C32', $bytes));

        $b[0]  &= 248;
        $b[31] &= 127;
        $b[31] |= 64;

        return pack('C32', ...$b);
    }
}
