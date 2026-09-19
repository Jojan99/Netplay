<?php

namespace App\Services\Plataforma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


/**
 * La suscripción de una empresa con Netvula: plan, ciclo, precio pactado,
 * estado de pago y su saldo a favor.
 *
 * La fila se crea sola la primera vez que se la pide: las 12 empresas que ya
 * están registradas no la tienen y nadie va a cargarlas a mano.
 */
class SuscripcionDeEmpresa
{
    public static function hayTablas(): bool
    {
        static $hay = null;

        return $hay ??= Schema::hasTable('plataforma_suscripciones');
    }

    /** La suscripción de la empresa; la crea en prueba si todavía no existe. */
    public static function asegurar(int $companyId): ?object
    {
        if (!self::hayTablas()) {
            return null;
        }

        $fila = DB::table('plataforma_suscripciones')->where('company_id', $companyId)->first();

        if ($fila) {
            // Empresas viejas: les falta el código de referido.
            if (!$fila->codigo_referido) {
                $codigo = self::codigoLibre($companyId);
                DB::table('plataforma_suscripciones')->where('id', $fila->id)->update(['codigo_referido' => $codigo, 'updated_at' => now()]);
                $fila->codigo_referido = $codigo;
            }

            return $fila;
        }

        $empresa = DB::table('companies')->where('id', $companyId)->first(['id', 'created_at']);

        if (!$empresa) {
            return null;
        }

        $inicio = $empresa->created_at ? \Carbon\Carbon::parse($empresa->created_at)->toDateString() : now()->toDateString();

        DB::table('plataforma_suscripciones')->insert([
            'company_id'      => $companyId,
            'plan_id'         => null,
            'ciclo'           => 'mensual',
            'estado'          => 'prueba',
            'inicio'          => $inicio,
            'prueba_hasta'    => \Carbon\Carbon::parse($inicio)->addDays((int) config('plataforma.prueba_dias', 15))->toDateString(),
            'codigo_referido' => self::codigoLibre($companyId),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return DB::table('plataforma_suscripciones')->where('company_id', $companyId)->first();
    }

    /**
     * Le abre la suscripción a toda empresa que todavía no la tenga.
     *
     * Las que ya estaban registradas antes de la consola no tienen fila, y sin
     * fila no tienen ni estado ni código de referido. Se hace una sola vez por
     * empresa: después esta consulta no encuentra nada y no escribe.
     *
     * @return int cuántas se crearon
     */
    public static function asegurarTodas(): int
    {
        if (!self::hayTablas()) {
            return 0;
        }

        $faltantes = DB::table('companies as c')
            ->leftJoin('plataforma_suscripciones as s', 's.company_id', '=', 'c.id')
            ->whereNull('s.id')
            ->pluck('c.id');

        foreach ($faltantes as $id) {
            self::asegurar((int) $id);
        }

        return $faltantes->count();
    }

    /** Saldo a favor de la empresa (créditos otorgados menos consumidos). */
    public static function credito(int $companyId): float
    {
        if (!Schema::hasTable('plataforma_creditos')) {
            return 0.0;
        }

        return round((float) DB::table('plataforma_creditos')->where('company_id', $companyId)->sum('monto'), 2);
    }

    /**
     * Fin del período que empieza en esa fecha.
     *
     * Un mes o un año menos un día: el período de un cobro mensual del 5 va
     * del 5 al 4 del mes siguiente.
     */
    public static function finDePeriodo(string $inicio, string $ciclo): string
    {
        $d = \Carbon\Carbon::parse($inicio);

        return ($ciclo === 'anual' ? $d->addYear() : $d->addMonth())->subDay()->toDateString();
    }

    /** El siguiente período a cobrar de una suscripción. */
    public static function siguientePeriodo(object $suscripcion): string
    {
        if ($suscripcion->proxima_facturacion) {
            return \Carbon\Carbon::parse($suscripcion->proxima_facturacion)->toDateString();
        }

        // Nunca se le facturó: arranca cuando termina la prueba, o el día de alta.
        $desde = $suscripcion->prueba_hasta ?: $suscripcion->inicio ?: now()->toDateString();

        return \Carbon\Carbon::parse($desde)->toDateString();
    }

    /** Días que faltan para la próxima facturación (negativo si ya pasó). */
    public static function diasParaVencer(?string $proxima): ?int
    {
        if (!$proxima) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($proxima)->startOfDay(), false);
    }

    /**
     * Un código de referido corto, legible y único.
     *
     * Sin caracteres que se confundan al dictarlo por teléfono (0/O, 1/I).
     */
    public static function codigoLibre(int $companyId): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) DB::table('companies')->where('id', $companyId)->value('slug')));
        $base = substr($base ?: 'NETVULA', 0, 8) ?: 'NETVULA';

        // Sin 0/O ni 1/I: son las que se confunden al dictar el código.
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($i = 0; $i < 20; $i++) {
            $cola = '';

            for ($j = 0; $j < 4; $j++) {
                $cola .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }

            $codigo = $base . '-' . $cola;

            if (!DB::table('plataforma_suscripciones')->where('codigo_referido', $codigo)->exists()) {
                return $codigo;
            }
        }

        return $base . '-' . $companyId;
    }
}
