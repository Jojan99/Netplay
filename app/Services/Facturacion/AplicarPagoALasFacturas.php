<?php

namespace App\Services\Facturacion;

use App\Models\PaymentLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aplica un pago recibido a las facturas pendientes del cliente, por cédula.
 *
 * Reparte de la más vieja a la más nueva (o, con $exactaPrimero, empieza por la
 * factura cuyo saldo es EXACTAMENTE el pago: quien paga su mensualidad de 70.000
 * no debería ver ese pago gastado en un residuo viejo de 9.333 y quedar debiendo
 * 9.333 de este mes):
 *   - alcanza para una factura entera  → queda pagada, y sigue con la próxima;
 *   - no alcanza                        → queda como abono en esa factura;
 *   - sobra plata sin factura           → NO se aplica: se devuelve como sobrante.
 *     Inventar un saldo a favor es una decisión contable que no se toma sola.
 *
 * Cada factura toca exactamente lo mismo que el abono del panel
 * (FacturationRepository::abonarInvoice): price_abone, la bandera abone, paid y
 * paid_at, más un renglón en payment_logs. Con la diferencia de que acá no hay
 * sesión —quien lo registra se dice expresamente— y no se manda el aviso interno
 * de «pago registrado» por cada uno: en un lote serían cientos.
 *
 * Es idempotente: cada pago lleva una marca única en payment_logs.notes, y si esa
 * marca ya existe no se vuelve a aplicar. Correrlo dos veces no cobra dos veces.
 *
 * Con $aplicar = false no escribe nada: calcula exactamente lo que haría. Dos
 * pagos del mismo cliente en la misma corrida no gastan la misma plata dos veces,
 * porque la simulación recuerda lo que ya «abonó».
 */
class AplicarPagoALasFacturas
{
    /** detId => lo que la simulación ya abonó en esta corrida. */
    private array $virtual = [];

    public function __construct(
        private int $companyId,
        private ?int $registradoPor,
        private ?int $metodoId,
        private ?Carbon $fecha,
        private bool $aplicar,
        private bool $exactaPrimero = false,
    ) {}

    /**
     * @return array{estado: string, user_id: ?int, cliente: ?string, movimientos: list<array<string,mixed>>, sobrante: float, detalle: ?string}
     */
    public function pagar(string $cedula, float $monto, string $marca): array
    {
        $vacio = fn (string $estado, ?string $detalle = null, ?int $userId = null, ?string $cliente = null) => [
            'estado' => $estado, 'user_id' => $userId, 'cliente' => $cliente,
            'movimientos' => [], 'sobrante' => $estado === 'sin_facturas' ? round($monto, 2) : 0.0, 'detalle' => $detalle,
        ];

        if ($monto <= 0) return $vacio('monto_invalido', 'El valor no es mayor que cero.');

        if (PaymentLog::where('company_id', $this->companyId)->where('notes', 'like', $marca . '%')->exists()) {
            return $vacio('ya_aplicado', 'Ese pago ya se había aplicado.');
        }

        // ── Quién es ────────────────────────────────────────────────────────
        $clientes = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $this->companyId)
            ->where('ud.dni', trim($cedula))
            ->get(['ud.user_id', 'ud.names', 'ud.lastname'])
            ->unique('user_id')->values();

        if ($clientes->isEmpty()) return $vacio('sin_cliente', "No hay un cliente con la cédula {$cedula}.");
        if ($clientes->count() > 1) return $vacio('ambiguo', "La cédula {$cedula} la tienen {$clientes->count()} clientes.");

        $userId = (int) $clientes[0]->user_id;
        $nombre = trim($clientes[0]->names . ' ' . $clientes[0]->lastname);

        // ── Qué debe ────────────────────────────────────────────────────────
        // Con DB::table y no con el modelo: el modelo esconde las anuladas por un
        // scope global, y acá se quiere que sea explícito en la consulta.
        $consulta = fn () => DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->where('cb.company_id', $this->companyId)
            ->where('cb.user_id', $userId)
            ->where('df.paid', 0)
            ->whereNull('df.anulada_en')
            ->orderBy('df.date_facturation')->orderBy('df.id')
            ->select('df.*');

        $pendientes = $consulta()->get();

        $saldoDe = fn ($f) => round((float) $f->price_total - (float) ($f->price_discount ?? 0) - (float) ($f->price_abone ?? 0) - ($this->virtual[$f->id] ?? 0), 2);

        // Con $exactaPrimero, si una factura debe justo lo que se pagó, es ésa.
        if ($this->exactaPrimero) {
            $exacta = $pendientes->first(fn ($f) => abs($saldoDe($f) - $monto) < 0.01);
            if ($exacta) $pendientes = collect([$exacta])->concat($pendientes->reject(fn ($f) => $f->id === $exacta->id));
        }

        $restante = round($monto, 2);
        $movimientos = [];

        foreach ($pendientes as $f) {
            if ($restante <= 0.004) break;

            $saldo = $saldoDe($f);
            if ($saldo <= 0.004) continue;

            $abono = round(min($restante, $saldo), 2);
            $completa = $abono >= $saldo - 0.004;

            $movimientos[] = [
                'det_id'        => (int) $f->id,
                'factura'       => $f->number_facture,
                'fecha'         => $f->date_facturation,
                'saldo_antes'   => $saldo,
                'monto'         => $abono,
                'tipo'          => $completa ? 'pago_completo' : 'abono',
                'saldo_despues' => round($saldo - $abono, 2),
            ];

            $restante = round($restante - $abono, 2);
        }

        if (!$movimientos) return $vacio('sin_facturas', 'El cliente no tiene facturas pendientes: no hay a qué aplicar el pago.', $userId, $nombre);

        // ── Aplicar (o sólo recordar, si es simulación) ─────────────────────
        if ($this->aplicar) {
            DB::transaction(function () use ($movimientos, $userId, $nombre, $marca) {
                foreach ($movimientos as $m) {
                    // Se vuelve a leer con candado: entre la lectura y la escritura
                    // alguien pudo haber pagado esa misma factura desde el panel.
                    $f = DB::table('det_facturations')->where('id', $m['det_id'])->lockForUpdate()->first();

                    $saldoReal = round((float) $f->price_total - (float) ($f->price_discount ?? 0) - (float) ($f->price_abone ?? 0), 2);

                    if ($f->paid || $saldoReal + 0.004 < $m['monto']) {
                        throw new \RuntimeException("La factura {$m['factura']} cambió mientras se aplicaba el pago (saldo {$saldoReal}). No se aplicó nada de este cliente.");
                    }

                    $nuevoAbono = round((float) ($f->price_abone ?? 0) + $m['monto'], 2);
                    $pagada = $nuevoAbono >= round((float) $f->price_total - (float) ($f->price_discount ?? 0), 2) - 0.004;

                    DB::table('det_facturations')->where('id', $m['det_id'])->update([
                        'price_abone'     => $nuevoAbono,
                        'abone'           => 1,
                        'paid'            => $pagada ? 1 : 0,
                        'paid_at'         => $pagada ? ($this->fecha ?? now()) : null,
                        'paid_by_user_id' => $this->registradoPor,
                        'updated_at'      => now(),
                    ]);

                    $log = new PaymentLog([
                        'company_id'          => $this->companyId,
                        'det_facturation_id'  => $m['det_id'],
                        'cab_id'              => $f->cab_id,
                        'number_facture'      => $f->number_facture,
                        'client_name'         => $nombre,
                        'recorded_by_user_id' => $this->registradoPor,
                        'amount'              => $m['monto'],
                        'type'                => $pagada ? 'pago_completo' : 'abono',
                        'payment_method_id'   => $this->metodoId,
                        'notes'               => mb_substr($marca, 0, 255),
                    ]);
                    if ($this->fecha) { $log->created_at = $this->fecha; $log->updated_at = $this->fecha; }
                    $log->save();
                }
            });
        } else {
            foreach ($movimientos as $m) $this->virtual[$m['det_id']] = ($this->virtual[$m['det_id']] ?? 0) + $m['monto'];
        }

        // ¿Cuánto le queda debiendo? Es lo que dice si el pago lo dejó al día.
        $tocado = collect($movimientos)->pluck('monto', 'det_id');
        $quedan = $pendientes->filter(fn ($f) => $saldoDe($f) - ($this->aplicar ? 0 : 0) - ($tocado[$f->id] ?? 0) > 0.004)->count();
        if ($this->aplicar) $quedan = $pendientes->filter(fn ($f) => $saldoDe($f) - ($tocado[$f->id] ?? 0) > 0.004)->count();

        return [
            'estado'      => $this->aplicar ? 'aplicado' : 'simulado',
            'quedan'      => $quedan,
            'user_id'     => $userId,
            'cliente'     => $nombre,
            'movimientos' => $movimientos,
            'sobrante'    => $restante,
            'detalle'     => $restante > 0.004 ? 'Sobró plata: el cliente no tiene más facturas pendientes.' : null,
        ];
    }
}
