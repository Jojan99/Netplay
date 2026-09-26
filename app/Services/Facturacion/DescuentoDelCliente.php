<?php

namespace App\Services\Facturacion;

use Carbon\Carbon;

/**
 * El trato especial que un cliente tiene todos los meses.
 *
 * El descuento existía por factura: había que ponerlo a mano, una por una. Un
 * acuerdo de «a este le cobramos la mitad» dependía de que alguien se
 * acordara cada mes, y el mes que no, el cliente pagaba de más y había que
 * devolverle.
 *
 * Aquí se resuelve una sola vez y lo aplican los dos caminos que crean
 * facturas —el proceso mensual y la factura suelta—, para que no puedan
 * calcular distinto.
 */
class DescuentoDelCliente
{
    public const PORCENTAJE = 'porcentaje';
    public const VALOR = 'valor';

    /**
     * Cuánto se le descuenta a una factura de $precio.
     *
     * Devuelve siempre las tres cosas que la factura necesita: el porcentaje
     * (informativo, es lo que se imprime), el monto a restar y el motivo.
     *
     * @param  object|array|null  $ficha  la fila de user_data del cliente
     * @return array{porcentaje: float, monto: float, motivo: ?string}
     */
    public static function paraFactura(object|array|null $ficha, float $precio): array
    {
        $nada = ['porcentaje' => 0.0, 'monto' => 0.0, 'motivo' => null];

        if (!$ficha || $precio <= 0) {
            return $nada;
        }

        $dato = static fn (string $campo) => is_array($ficha) ? ($ficha[$campo] ?? null) : ($ficha->{$campo} ?? null);

        $tipo = trim((string) $dato('descuento_tipo'));
        $valor = (float) ($dato('descuento_valor') ?? 0);

        if ($valor <= 0 || !in_array($tipo, [self::PORCENTAJE, self::VALOR], true)) {
            return $nada;
        }

        // Vencido: el trato tenía fecha de fin y ya pasó. Se compara contra el
        // final del día para que «hasta el 30» incluya el 30.
        $hasta = $dato('descuento_hasta');

        if ($hasta && Carbon::parse($hasta)->endOfDay()->isPast()) {
            return $nada;
        }

        $monto = $tipo === self::PORCENTAJE
            ? $precio * (min($valor, 100) / 100)
            : $valor;

        // Nunca más que la factura: un descuento mayor que el precio dejaría un
        // saldo negativo, y a partir de ahí la cartera, el bot y la pasarela
        // muestran números imposibles.
        $monto = round(min($monto, $precio), 2);

        return [
            'porcentaje' => $precio > 0 ? round($monto * 100 / $precio, 2) : 0.0,
            'monto'      => $monto,
            'motivo'     => $dato('descuento_motivo') ?: null,
        ];
    }

    /** Si el cliente tiene un trato vigente hoy. */
    public static function vigente(object|array|null $ficha): bool
    {
        return self::paraFactura($ficha, 1_000_000)['monto'] > 0;
    }

    /**
     * Revisa lo que llega del formulario.
     *
     * @return ?string el reclamo, o null si está bien
     */
    public static function problema(?string $tipo, float $valor, ?string $hasta): ?string
    {
        if ($tipo === null || $tipo === '') {
            return null;   // quitar el descuento siempre vale
        }

        if (!in_array($tipo, [self::PORCENTAJE, self::VALOR], true)) {
            return 'El tipo de descuento tiene que ser porcentaje o valor.';
        }

        if ($valor <= 0) {
            return 'El descuento tiene que ser mayor que cero. Para quitarlo, seleccione «sin descuento».';
        }

        if ($tipo === self::PORCENTAJE && $valor > 100) {
            return 'Un porcentaje no puede pasar de 100.';
        }

        if ($hasta && Carbon::parse($hasta)->endOfDay()->isPast()) {
            return 'Esa fecha ya pasó: el descuento no se aplicaría a ninguna factura.';
        }

        return null;
    }
}
