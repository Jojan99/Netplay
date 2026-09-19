<?php

namespace App\Services\Plataforma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * El programa de referidos: cada empresa reparte su código y gana cuando la
 * que trajo empieza a pagar.
 *
 * El beneficio del que refiere NO se acredita al registrarse la otra empresa,
 * sino cuando ésta paga su primer período: si no, se regala plata por
 * registros vacíos. Es configurable, pero ese es el valor de fábrica.
 */
class Referidos
{
    /** Lo que trae el programa de fábrica, hasta que el dueño lo cambie. */
    public const AJUSTES = [
        'activo' => true,
        // Lo que recibe la empresa nueva: se aplica como cupón al primer cobro.
        'beneficio_referido' => [
            'tipo'     => 'porcentaje',   // porcentaje | monto
            'valor'    => 25,
            'periodos' => 1,
        ],
        // Lo que recibe quien refirió: crédito a favor.
        'beneficio_referidor' => [
            'tipo'  => 'monto',           // monto | porcentaje (sobre lo que pagó el referido)
            'valor' => 50000,
        ],
        // registro | primer_pago
        'acreditar_en' => 'primer_pago',
    ];

    public static function hayTablas(): bool
    {
        static $hay = null;

        return $hay ??= Schema::hasTable('plataforma_referidos') && Schema::hasTable('plataforma_creditos');
    }

    /** Los ajustes del programa: los guardados, sobre los de fábrica. */
    public static function ajustes(): array
    {
        if (!Schema::hasTable('plataforma_ajustes')) {
            return self::AJUSTES;
        }

        $guardado = DB::table('plataforma_ajustes')->where('clave', 'referidos')->value('valor');
        $guardado = $guardado ? json_decode((string) $guardado, true) : null;

        return is_array($guardado) ? array_replace_recursive(self::AJUSTES, $guardado) : self::AJUSTES;
    }

    public static function guardarAjustes(array $ajustes): void
    {
        DB::table('plataforma_ajustes')->updateOrInsert(
            ['clave' => 'referidos'],
            ['valor' => json_encode(array_replace_recursive(self::AJUSTES, $ajustes), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]
        );
    }

    /** La empresa dueña de ese código de referido, si existe. */
    public static function empresaDelCodigo(string $codigo): ?int
    {
        if (!SuscripcionDeEmpresa::hayTablas()) {
            return null;
        }

        $id = DB::table('plataforma_suscripciones')
            ->whereRaw('UPPER(codigo_referido) = ?', [strtoupper(trim($codigo))])
            ->value('company_id');

        return $id ? (int) $id : null;
    }

    /**
     * Anota que una empresa nueva llegó con el código de otra.
     *
     * Se llama al registrar la empresa. No acredita nada todavía: el
     * beneficio del que refiere espera al primer pago.
     */
    public static function registrar(int $referidaId, string $codigo): bool
    {
        if (!self::hayTablas()) {
            return false;
        }

        $ajustes = self::ajustes();

        if (empty($ajustes['activo'])) {
            return false;
        }

        $referidorId = self::empresaDelCodigo($codigo);

        // Nadie se refiere a sí mismo, y un código que no es de nadie no vale.
        if (!$referidorId || $referidorId === $referidaId) {
            return false;
        }

        if (DB::table('plataforma_referidos')->where('referida_company_id', $referidaId)->exists()) {
            return false;
        }

        DB::table('plataforma_referidos')->insert([
            'referidor_company_id' => $referidorId,
            'referida_company_id'  => $referidaId,
            'codigo'               => strtoupper(trim($codigo)),
            'estado'               => 'registrado',
            'beneficio_referido'   => json_encode($ajustes['beneficio_referido'], JSON_UNESCAPED_UNICODE),
            'beneficio_referidor'  => json_encode($ajustes['beneficio_referidor'], JSON_UNESCAPED_UNICODE),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        DB::table('plataforma_suscripciones')->where('company_id', $referidaId)->update([
            'referida_por' => $referidorId,
            'updated_at'   => now(),
        ]);

        // Si el dueño eligió acreditar al registrarse, se hace ya.
        if (($ajustes['acreditar_en'] ?? 'primer_pago') === 'registro') {
            self::acreditar($referidaId, null);
        }

        return true;
    }

    /**
     * Acredita al que refirió, si corresponde.
     *
     * Se llama cuando la empresa referida paga un cobro. Sólo la primera vez.
     *
     * @param  float|null  $pagado  lo que pagó, para el beneficio por porcentaje
     * @return float  crédito otorgado
     */
    public static function acreditar(int $referidaId, ?float $pagado): float
    {
        if (!self::hayTablas()) {
            return 0.0;
        }

        $referido = DB::table('plataforma_referidos')
            ->where('referida_company_id', $referidaId)
            ->whereNull('acreditado_en')
            ->where('estado', '!=', 'anulado')
            ->first();

        if (!$referido) {
            return 0.0;
        }

        $beneficio = json_decode((string) $referido->beneficio_referidor, true) ?: self::AJUSTES['beneficio_referidor'];

        $monto = ($beneficio['tipo'] ?? 'monto') === 'porcentaje'
            ? round(((float) ($beneficio['valor'] ?? 0) / 100) * (float) ($pagado ?? 0), 2)
            : round((float) ($beneficio['valor'] ?? 0), 2);

        if ($monto <= 0) {
            DB::table('plataforma_referidos')->where('id', $referido->id)->update([
                'estado' => 'activo', 'acreditado_en' => now(), 'updated_at' => now(),
            ]);

            return 0.0;
        }

        DB::table('plataforma_creditos')->insert([
            'company_id'  => $referido->referidor_company_id,
            'monto'       => $monto,
            'motivo'      => 'Referido',
            'referido_id' => $referido->id,
            'nota'        => 'Por la empresa ' . (DB::table('companies')->where('id', $referidaId)->value('name') ?? $referidaId),
            'user_id'     => Bitacora::idActual(),
            'created_at'  => now(),
        ]);

        DB::table('plataforma_referidos')->where('id', $referido->id)->update([
            'estado'           => 'activo',
            'acreditado_en'    => now(),
            'credito_otorgado' => $monto,
            'updated_at'       => now(),
        ]);

        Bitacora::anotar('referido.acreditado', (int) $referido->referidor_company_id, [
            'referida' => $referidaId, 'monto' => $monto,
        ], 'referido', $referido->id);

        return $monto;
    }

    /**
     * Convierte el beneficio del referido en un cupón propio suyo.
     *
     * Se hace al momento de asignarle el plan o de generar su primer cobro:
     * el descuento de bienvenida viaja por el mismo camino que cualquier otro
     * cupón, así el detalle del cobro se lee igual.
     */
    public static function cuponDeBienvenida(int $referidaId): ?object
    {
        if (!self::hayTablas() || !Cupones::hayTabla()) {
            return null;
        }

        $referido = DB::table('plataforma_referidos')->where('referida_company_id', $referidaId)->first();

        if (!$referido) {
            return null;
        }

        $b = json_decode((string) $referido->beneficio_referido, true) ?: self::AJUSTES['beneficio_referido'];

        if ((float) ($b['valor'] ?? 0) <= 0) {
            return null;
        }

        $codigo = 'REF-' . strtoupper($referido->codigo);

        $cupon = DB::table('plataforma_cupones')->where('codigo', $codigo)->first();

        if (!$cupon) {
            try {
                $id = DB::table('plataforma_cupones')->insertGetId([
                    'codigo'           => $codigo,
                    'descripcion'      => 'Bienvenida por referido',
                    'tipo'             => $b['tipo'] ?? 'porcentaje',
                    'valor'            => (float) ($b['valor'] ?? 0),
                    'usos_por_empresa' => 1,
                    'duracion'         => ((int) ($b['periodos'] ?? 1)) > 1 ? 'n_periodos' : 'primer_periodo',
                    'periodos'         => max(1, (int) ($b['periodos'] ?? 1)),
                    'activo'           => true,
                    'notas'            => 'Generado por el programa de referidos.',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                $cupon = DB::table('plataforma_cupones')->where('id', $id)->first();
            } catch (\Throwable $e) {
                Log::warning('[Referidos] no se pudo crear el cupón de bienvenida', ['codigo' => $codigo, 'error' => $e->getMessage()]);

                return null;
            }
        }

        return $cupon;
    }
}
