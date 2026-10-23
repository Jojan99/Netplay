<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El teléfono real detrás de un LID de WhatsApp.
 *
 * WhatsApp identifica a muchos contactos con un LID (88596786761837) en vez de su número. Los
 * mensajes del cliente traen las dos cosas, pero la copia de lo que sale por la línea (el bot,
 * el asesor desde el teléfono) llega sólo con el LID: el CRM abría con eso un chat «Cliente
 * 88596786761837» y la respuesta quedaba fuera de la conversación del cliente (22-10-2026:
 * la confirmación del comprobante de Vanessa Torres). El servicio de WhatsApp ya sabe la
 * equivalencia (wa_lid_cache, wa_phone_lookup); aquí se lee de ahí.
 */
class Lids
{
    /** @var array<string, ?string> */
    private static array $memoria = [];

    /** Un LID tiene 14 dígitos o más; un número de celular con indicativo, 12. */
    public static function pareceLid(?string $telefono): bool
    {
        $solo = preg_replace('/\D/', '', (string) $telefono);

        return strlen($solo) >= 14;
    }

    public static function telefono(?string $lid): ?string
    {
        $lid = preg_replace('/\D/', '', (string) $lid);

        if (!self::pareceLid($lid)) {
            return null;
        }

        if (array_key_exists($lid, self::$memoria)) {
            return self::$memoria[$lid];
        }

        $base = (string) config('services.netplay_whatsapp.database', 'whatsapp_service');

        try {
            $fila = DB::selectOne(
                "SELECT phone FROM `{$base}`.wa_lid_cache WHERE lid = ? AND phone <> '' "
                . "UNION ALL SELECT phone FROM `{$base}`.wa_phone_lookup WHERE lid = ? AND phone <> '' LIMIT 1",
                [$lid, $lid]
            );
        } catch (\Throwable $e) {
            Log::warning('[LID] No se pudo consultar la equivalencia', ['error' => $e->getMessage()]);

            return self::$memoria[$lid] = null;
        }

        $telefono = $fila ? preg_replace('/\D/', '', (string) $fila->phone) : '';

        return self::$memoria[$lid] = ($telefono !== '' && !self::pareceLid($telefono)) ? $telefono : null;
    }
}
