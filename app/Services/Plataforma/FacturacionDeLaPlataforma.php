<?php

namespace App\Services\Plataforma;

use Illuminate\Support\Facades\DB;

/**
 * El cobro de Netvula a cada empresa: generarlo, registrar el pago y saber
 * quién está por vencer y quién está en mora.
 *
 * No hay pasarela: todo es registro manual, con su comprobante. El día que se
 * integre una, entra por aquí y el resto no se entera.
 */
class FacturacionDeLaPlataforma
{
    /** Días desde que se emite hasta que se considera vencido. */
    public const DIAS_PARA_PAGAR = 10;

    /**
     * Genera el cobro del período con el desglose de la simulación.
     *
     * @return array{ok:bool, motivo:?string, cobro_id:?int, detalle:?array}
     */
    public static function generar(int $companyId, ?string $periodoInicio = null): array
    {
        $calculo = CalculadoraDeCobro::simular($companyId, $periodoInicio);

        if (!($calculo['ok'] ?? false)) {
            return ['ok' => false, 'motivo' => $calculo['motivo'], 'cobro_id' => null, 'detalle' => null];
        }

        if ($calculo['ya_cobrado']) {
            return ['ok' => false, 'motivo' => 'Ese período ya está cobrado.', 'cobro_id' => null, 'detalle' => $calculo];
        }

        $suscripcion = SuscripcionDeEmpresa::asegurar($companyId);

        $cobroId = DB::transaction(function () use ($companyId, $calculo, $suscripcion) {
            $id = DB::table('plataforma_cobros')->insertGetId([
                'company_id'       => $companyId,
                'suscripcion_id'   => $suscripcion->id,
                'periodo_inicio'   => $calculo['periodo_inicio'],
                'periodo_fin'      => $calculo['periodo_fin'],
                'ciclo'            => $calculo['ciclo'],
                'precio_lista'     => $calculo['precio'],
                'descuento'        => $calculo['descuento'],
                'cupon_id'         => $calculo['cupon']['id'] ?? null,
                'cupon_codigo'     => $calculo['cupon']['codigo'] ?? null,
                'credito_aplicado' => $calculo['credito_aplicado'],
                'total'            => $calculo['total'],
                'vence'            => \Carbon\Carbon::parse($calculo['periodo_inicio'])->addDays(self::DIAS_PARA_PAGAR)->toDateString(),
                'estado'           => $calculo['total'] > 0 ? 'pendiente' : 'pagado',
                'pagado'           => $calculo['total'] > 0 ? 0 : 0,
                'pagado_en'        => $calculo['total'] > 0 ? null : now(),
                'detalle'          => json_encode($calculo['renglones'], JSON_UNESCAPED_UNICODE),
                'creado_por'       => Bitacora::idActual(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // El crédito consumido se anota en el libro: el saldo es la suma.
            if ($calculo['credito_aplicado'] > 0) {
                DB::table('plataforma_creditos')->insert([
                    'company_id' => $companyId,
                    'monto'      => -$calculo['credito_aplicado'],
                    'motivo'     => 'Aplicado al cobro',
                    'cobro_id'   => $id,
                    'user_id'    => Bitacora::idActual(),
                    'created_at' => now(),
                ]);
            }

            // El cupón gasta un período; el permanente no se gasta nunca.
            if (!empty($calculo['cupon']) && !$suscripcion->cupon_permanente && $suscripcion->cupon_periodos !== null) {
                $quedan = max(0, (int) $suscripcion->cupon_periodos - 1);

                DB::table('plataforma_suscripciones')->where('id', $suscripcion->id)->update([
                    'cupon_periodos' => $quedan,
                    'updated_at'     => now(),
                ]);
            }

            // La próxima facturación se corre un período.
            $proxima = \Carbon\Carbon::parse($calculo['periodo_fin'])->addDay()->toDateString();

            DB::table('plataforma_suscripciones')->where('id', $suscripcion->id)->update([
                'proxima_facturacion' => $proxima,
                'updated_at'          => now(),
            ]);

            DB::table('plataforma_cupon_usos')
                ->where('company_id', $companyId)
                ->where('cupon_id', $calculo['cupon']['id'] ?? 0)
                ->whereNull('cobro_id')
                ->limit(1)
                ->update(['cobro_id' => $id]);

            return $id;
        });

        // Un cobro en cero (todo cubierto con crédito) ya cuenta como pagado.
        if ($calculo['total'] <= 0) {
            self::alPagarse($companyId, 0.0);
        }

        Bitacora::anotar('cobro.generado', $companyId, [
            'periodo' => $calculo['periodo_inicio'] . ' → ' . $calculo['periodo_fin'],
            'total'   => $calculo['total'],
            'cupon'   => $calculo['cupon']['codigo'] ?? null,
            'credito' => $calculo['credito_aplicado'],
        ], 'cobro', $cobroId);

        return ['ok' => true, 'motivo' => null, 'cobro_id' => $cobroId, 'detalle' => $calculo];
    }

    /**
     * Registra un pago recibido contra un cobro.
     *
     * @return array{ok:bool, motivo:?string, saldo:float}
     */
    public static function registrarPago(int $cobroId, float $monto, string $fecha, string $metodo, ?string $referencia, ?string $comprobante, ?string $nota): array
    {
        $cobro = DB::table('plataforma_cobros')->where('id', $cobroId)->first();

        if (!$cobro) {
            return ['ok' => false, 'motivo' => 'Ese cobro no existe.', 'saldo' => 0];
        }

        if ($cobro->estado === 'anulado') {
            return ['ok' => false, 'motivo' => 'Ese cobro está anulado.', 'saldo' => 0];
        }

        if ($monto <= 0) {
            return ['ok' => false, 'motivo' => 'El monto tiene que ser mayor que cero.', 'saldo' => 0];
        }

        $saldo = round((float) $cobro->total - (float) $cobro->pagado, 2);

        if ($monto > $saldo + 0.01) {
            return ['ok' => false, 'motivo' => 'El pago ($' . number_format($monto, 0, ',', '.') . ') es mayor que el saldo del cobro ($' . number_format($saldo, 0, ',', '.') . ').', 'saldo' => $saldo];
        }

        $quedaEnCero = DB::transaction(function () use ($cobro, $monto, $fecha, $metodo, $referencia, $comprobante, $nota) {
            DB::table('plataforma_pagos')->insert([
                'cobro_id'    => $cobro->id,
                'company_id'  => $cobro->company_id,
                'monto'       => $monto,
                'fecha'       => $fecha,
                'metodo'      => $metodo,
                'referencia'  => $referencia,
                'comprobante' => $comprobante,
                'nota'        => $nota,
                'user_id'     => Bitacora::idActual(),
                'created_at'  => now(),
            ]);

            $pagado = round((float) $cobro->pagado + $monto, 2);
            $listo  = $pagado >= (float) $cobro->total - 0.01;

            DB::table('plataforma_cobros')->where('id', $cobro->id)->update([
                'pagado'     => $pagado,
                'estado'     => $listo ? 'pagado' : 'pendiente',
                'pagado_en'  => $listo ? now() : null,
                'updated_at' => now(),
            ]);

            return $listo;
        });

        if ($quedaEnCero) {
            self::alPagarse((int) $cobro->company_id, (float) $cobro->total);
        }

        Bitacora::anotar('pago.registrado', (int) $cobro->company_id, [
            'cobro' => $cobro->id, 'monto' => $monto, 'metodo' => $metodo, 'referencia' => $referencia,
        ], 'pago', $cobro->id);

        return ['ok' => true, 'motivo' => null, 'saldo' => round($saldo - $monto, 2)];
    }

    /**
     * Lo que pasa cuando un cobro queda saldado: la empresa vuelve a estar al
     * día y, si vino por un referido, se le acredita a quien la trajo.
     */
    private static function alPagarse(int $companyId, float $pagado): void
    {
        DB::table('plataforma_suscripciones')->where('company_id', $companyId)->update([
            'estado'     => 'al_dia',
            'updated_at' => now(),
        ]);

        if (Referidos::ajustes()['acreditar_en'] === 'primer_pago') {
            Referidos::acreditar($companyId, $pagado);
        }
    }

    /** Anula un cobro y devuelve el crédito que había consumido. */
    public static function anular(int $cobroId, string $motivo): array
    {
        $cobro = DB::table('plataforma_cobros')->where('id', $cobroId)->first();

        if (!$cobro) {
            return ['ok' => false, 'motivo' => 'Ese cobro no existe.'];
        }

        if ((float) $cobro->pagado > 0) {
            return ['ok' => false, 'motivo' => 'Ese cobro ya tiene pagos registrados. Revierta los pagos antes de anularlo.'];
        }

        DB::transaction(function () use ($cobro, $motivo) {
            DB::table('plataforma_cobros')->where('id', $cobro->id)->update([
                'estado' => 'anulado', 'updated_at' => now(),
            ]);

            if ((float) $cobro->credito_aplicado > 0) {
                DB::table('plataforma_creditos')->insert([
                    'company_id' => $cobro->company_id,
                    'monto'      => (float) $cobro->credito_aplicado,
                    'motivo'     => 'Devuelto por cobro anulado',
                    'cobro_id'   => $cobro->id,
                    'nota'       => $motivo,
                    'user_id'    => Bitacora::idActual(),
                    'created_at' => now(),
                ]);
            }
        });

        Bitacora::anotar('cobro.anulado', (int) $cobro->company_id, ['motivo' => $motivo], 'cobro', $cobro->id);

        return ['ok' => true, 'motivo' => null];
    }

    /**
     * Quién está por vencer y quién está en mora.
     *
     * @return array{por_vencer: list<array<string,mixed>>, en_mora: list<array<string,mixed>>}
     */
    public static function avisos(int $diasAviso = 7): array
    {
        $hoy = now()->toDateString();

        $vencidos = DB::table('plataforma_cobros as c')
            ->join('companies as e', 'e.id', '=', 'c.company_id')
            ->where('c.estado', 'pendiente')
            ->where('c.vence', '<', $hoy)
            ->orderBy('c.vence')
            ->get(['c.id', 'c.company_id', 'e.name as empresa', 'c.total', 'c.pagado', 'c.vence', 'c.periodo_inicio', 'c.periodo_fin']);

        $enMora = $vencidos->map(fn ($c) => [
            'cobro_id'   => (int) $c->id,
            'company_id' => (int) $c->company_id,
            'empresa'    => $c->empresa,
            'saldo'      => round((float) $c->total - (float) $c->pagado, 2),
            'vence'      => $c->vence,
            'dias'       => abs((int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($c->vence), false)),
            'periodo'    => $c->periodo_inicio . ' → ' . $c->periodo_fin,
        ])->all();

        $porVencer = DB::table('plataforma_suscripciones as s')
            ->join('companies as e', 'e.id', '=', 's.company_id')
            ->whereIn('s.estado', ['prueba', 'al_dia'])
            ->whereNotNull('s.proxima_facturacion')
            ->whereBetween('s.proxima_facturacion', [$hoy, now()->addDays($diasAviso)->toDateString()])
            ->orderBy('s.proxima_facturacion')
            ->get(['s.company_id', 'e.name as empresa', 's.proxima_facturacion', 's.ciclo', 's.estado'])
            ->map(fn ($s) => [
                'company_id' => (int) $s->company_id,
                'empresa'    => $s->empresa,
                'proxima'    => $s->proxima_facturacion,
                'ciclo'      => $s->ciclo,
                'estado'     => $s->estado,
                'dias'       => SuscripcionDeEmpresa::diasParaVencer($s->proxima_facturacion),
            ])->all();

        return ['por_vencer' => $porVencer, 'en_mora' => $enMora];
    }

    /**
     * Marca en mora a las suscripciones con cobros vencidos.
     *
     * Cambiar el estado no suspende nada: suspender es una decisión aparte
     * que toma el dueño desde la consola.
     */
    public static function marcarMoras(): int
    {
        $empresas = DB::table('plataforma_cobros')
            ->where('estado', 'pendiente')
            ->where('vence', '<', now()->toDateString())
            ->distinct()
            ->pluck('company_id');

        if ($empresas->isEmpty()) {
            return 0;
        }

        return DB::table('plataforma_suscripciones')
            ->whereIn('company_id', $empresas)
            ->whereIn('estado', ['prueba', 'al_dia'])
            ->update(['estado' => 'en_mora', 'updated_at' => now()]);
    }
}
