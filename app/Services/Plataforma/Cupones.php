<?php

namespace App\Services\Plataforma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos de descuento de la plataforma.
 *
 * Un cupón se puede usar al registrar la empresa o aplicarlo desde la consola
 * sobre una suscripción que ya existe. Cuando algo no da, se dice qué fue: un
 * "código inválido" a secas hace perder la tarde a quien lo tipeó bien.
 */
class Cupones
{
    public static function hayTabla(): bool
    {
        static $hay = null;

        return $hay ??= Schema::hasTable('plataforma_cupones');
    }

    /** Normaliza lo que escribió la persona: mayúsculas y sin espacios. */
    public static function normalizar(string $codigo): string
    {
        return strtoupper(trim(preg_replace('/\s+/', '', $codigo)));
    }

    public static function porCodigo(string $codigo): ?object
    {
        if (!self::hayTabla()) {
            return null;
        }

        return DB::table('plataforma_cupones')->whereRaw('UPPER(codigo) = ?', [self::normalizar($codigo)])->first();
    }

    /**
     * ¿Se puede usar este cupón?
     *
     * @return array{ok:bool, motivo:?string, cupon:?object}
     */
    public static function revisar(string $codigo, ?int $companyId = null, ?string $planClave = null, ?string $ciclo = null): array
    {
        if (!self::hayTabla()) {
            return ['ok' => false, 'motivo' => 'Los cupones todavía no están habilitados.', 'cupon' => null];
        }

        $cupon = self::porCodigo($codigo);

        if (!$cupon) {
            return ['ok' => false, 'motivo' => 'Ese código no existe. Revise cómo está escrito.', 'cupon' => null];
        }

        if (!$cupon->activo) {
            return ['ok' => false, 'motivo' => 'Ese código está desactivado.', 'cupon' => null];
        }

        $hoy = now()->toDateString();

        if ($cupon->desde && $hoy < $cupon->desde) {
            return ['ok' => false, 'motivo' => 'Ese código todavía no empieza a regir (desde el ' . $cupon->desde . ').', 'cupon' => null];
        }

        if ($cupon->hasta && $hoy > $cupon->hasta) {
            return ['ok' => false, 'motivo' => 'Ese código venció el ' . $cupon->hasta . '.', 'cupon' => null];
        }

        if ($cupon->usos_maximos !== null && (int) $cupon->usos >= (int) $cupon->usos_maximos) {
            return ['ok' => false, 'motivo' => 'Ese código ya se usó todas las veces disponibles.', 'cupon' => null];
        }

        if ($companyId && Schema::hasTable('plataforma_cupon_usos')) {
            $usados = DB::table('plataforma_cupon_usos')->where('cupon_id', $cupon->id)->where('company_id', $companyId)->count();

            if ($usados >= (int) $cupon->usos_por_empresa) {
                return ['ok' => false, 'motivo' => 'Esta empresa ya usó ese código.', 'cupon' => null];
            }
        }

        $planes = json_decode((string) $cupon->planes, true);

        if (is_array($planes) && $planes && $planClave && !in_array($planClave, $planes, true)) {
            return ['ok' => false, 'motivo' => 'Ese código no aplica al plan elegido.', 'cupon' => null];
        }

        $ciclos = json_decode((string) $cupon->ciclos, true);

        if (is_array($ciclos) && $ciclos && $ciclo && !in_array($ciclo, $ciclos, true)) {
            return ['ok' => false, 'motivo' => 'Ese código no aplica al ciclo ' . $ciclo . '.', 'cupon' => null];
        }

        return ['ok' => true, 'motivo' => null, 'cupon' => $cupon];
    }

    /** El descuento que hace ese cupón sobre un precio, sin pasarse del precio. */
    public static function descuentoSobre(object $cupon, float $precio): float
    {
        $bruto = $cupon->tipo === 'porcentaje'
            ? $precio * ((float) $cupon->valor / 100)
            : (float) $cupon->valor;

        return round(max(0, min($bruto, $precio)), 2);
    }

    /**
     * Pega el cupón a la suscripción y anota el uso.
     *
     * Los períodos que dura el beneficio quedan guardados en la suscripción:
     * cada cobro que lo usa descuenta uno.
     */
    public static function aplicarASuscripcion(object $cupon, int $companyId, string $origen = 'consola'): void
    {
        $periodos = match ($cupon->duracion) {
            'permanente'   => null,
            'n_periodos'   => max(1, (int) $cupon->periodos),
            default        => 1,
        };

        DB::table('plataforma_suscripciones')->where('company_id', $companyId)->update([
            'cupon_id'         => $cupon->id,
            'cupon_periodos'   => $periodos,
            'cupon_permanente' => $cupon->duracion === 'permanente',
            'updated_at'       => now(),
        ]);

        DB::table('plataforma_cupon_usos')->insert([
            'cupon_id'   => $cupon->id,
            'company_id' => $companyId,
            'origen'     => $origen,
            'user_id'    => Bitacora::idActual(),
            'created_at' => now(),
        ]);

        DB::table('plataforma_cupones')->where('id', $cupon->id)->increment('usos');
    }

    /** Saca el cupón de la suscripción (no borra el uso ya contado). */
    public static function quitarDeSuscripcion(int $companyId): void
    {
        DB::table('plataforma_suscripciones')->where('company_id', $companyId)->update([
            'cupon_id'         => null,
            'cupon_periodos'   => null,
            'cupon_permanente' => false,
            'updated_at'       => now(),
        ]);
    }
}
