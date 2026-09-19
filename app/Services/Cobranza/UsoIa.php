<?php

namespace App\Services\Cobranza;

use App\Models\CobranzaConfig;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto usa cada empresa la IA de cobranza, por día y por clave (la propia o
 * la de Netvula). Sirve para el tope de prueba con la clave de Netvula y para
 * verlo en la consola.
 */
class UsoIa
{
    public static function clave(int $companyId): string
    {
        return CobranzaConfig::deEmpresa($companyId)->tieneClavePropia() ? 'propia' : 'netvula';
    }

    public static function consulta(int $companyId): void
    {
        self::sumar($companyId, 'consultas');
    }

    public static function conversacion(int $companyId): void
    {
        self::sumar($companyId, 'conversaciones');
    }

    /** Conversaciones empezadas hoy con la clave de Netvula. */
    public static function conversacionesDePruebaHoy(int $companyId): int
    {
        try {
            return (int) DB::table('cobranza_uso_ia')->where('company_id', $companyId)
                ->where('fecha', now('America/Bogota')->toDateString())->where('clave', 'netvula')
                ->value('conversaciones');
        } catch (\Throwable) {
            return 0; // migración pendiente
        }
    }

    /** ¿Puede empezar otra conversación hoy? Con clave propia, siempre. */
    public static function puedeEmpezar(int $companyId): bool
    {
        return self::clave($companyId) === 'propia'
            || self::conversacionesDePruebaHoy($companyId) < Ia::LIMITE_PRUEBA;
    }

    private static function sumar(int $companyId, string $campo): void
    {
        try {
            $clave = self::clave($companyId);
            $fecha = now('America/Bogota')->toDateString();

            DB::table('cobranza_uso_ia')->insertOrIgnore([
                'company_id' => $companyId, 'fecha' => $fecha, 'clave' => $clave,
                'consultas' => 0, 'conversaciones' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('cobranza_uso_ia')->where('company_id', $companyId)->where('fecha', $fecha)->where('clave', $clave)
                ->update([$campo => DB::raw("{$campo} + 1"), 'updated_at' => now()]);
        } catch (\Throwable) {
            // Sin la tabla (migración pendiente) no se cuenta; la cobranza sigue.
        }
    }
}
