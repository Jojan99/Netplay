<?php

namespace App\Services\Cobranza;

use App\Models\CabFacturation;
use App\Models\DetFacturation;
use Illuminate\Support\Collection;

/**
 * Lo que debe un cliente: las facturas sin pagar con saldo, de la más vieja a
 * la más nueva. Es la misma cuenta que usa el bot de facturas (saldo = total −
 * descuento − abonos), así el asistente nunca dice una cifra distinta.
 */
class Deuda
{
    /** @param Collection<int, DetFacturation> $facturas */
    private function __construct(public readonly Collection $facturas) {}

    public static function de(int $companyId, int $userId): self
    {
        $cabs = CabFacturation::where('company_id', $companyId)->where('user_id', $userId)->pluck('id');

        $facturas = $cabs->isEmpty() ? collect() : DetFacturation::whereIn('cab_id', $cabs)
            ->where('paid', 0)
            ->orderBy('date_facturation')->orderBy('id')
            ->get()
            ->filter(fn (DetFacturation $f) => $f->outstanding() > 0)
            ->values();

        return new self($facturas);
    }

    public function total(): float
    {
        return round((float) $this->facturas->sum(fn (DetFacturation $f) => $f->outstanding()), 2);
    }

    public function cantidad(): int
    {
        return $this->facturas->count();
    }

    /** Días desde la factura más vieja sin pagar. */
    public function diasMora(): int
    {
        $vieja = $this->facturas->first()?->date_facturation;

        return $vieja ? max(0, (int) \Carbon\Carbon::parse($vieja)->startOfDay()->diffInDays(now()->startOfDay(), false)) : 0;
    }

    /** Para el asistente y para el panel: número, fecha y saldo de cada una. */
    public function detalle(): array
    {
        return $this->facturas->map(fn (DetFacturation $f) => [
            'id'     => (int) $f->id,
            'numero' => (string) ($f->number_facture ?: $f->id),
            'fecha'  => $f->date_facturation ? \Carbon\Carbon::parse($f->date_facturation)->format('Y-m-d') : null,
            'saldo'  => round($f->outstanding(), 2),
        ])->all();
    }

    public static function pesos(float $valor): string
    {
        return '$' . number_format($valor, 0, ',', '.');
    }
}
