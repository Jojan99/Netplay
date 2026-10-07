<?php

namespace App\Console\Commands;

use App\Http\Controllers\PaymentProofController;
use App\Models\PaymentProof;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aprueba varios comprobantes de Auditoría de pagos de una vez, por la misma ruta que el botón
 * «Aprobar» de la pantalla (aplica el pago a su factura y deja la auditoría).
 *
 * Sólo toca comprobantes de la empresa que sigan pendientes, con valor y con una factura que
 * todavía deba. Si la factura ya está pagada, o ya hay un pago aplicado por el mismo valor
 * después de la fecha del comprobante, lo deja por fuera: aprobarlo cobraría dos veces.
 *
 * Sin --aplicar sólo muestra lo que haría.
 */
class ComprobantesAprobarLote extends Command
{
    protected $signature = 'comprobantes:aprobar-lote {empresa : Id de la empresa}
        {ids : Números de comprobante separados por coma}
        {--por= : Id del usuario que aprueba (queda en la auditoría)}
        {--motivo=Aprobado en lote antes de la suspensión masiva. : Motivo que queda en la auditoría}
        {--aplicar : Aprobar y aplicar los pagos (sin esto, sólo simula)}';

    protected $description = 'Aprueba en lote comprobantes pendientes de Auditoría de pagos';

    public function handle(): int
    {
        $empresa = (int) $this->argument('empresa');
        $aplicar = (bool) $this->option('aplicar');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->argument('ids'))))));

        if ($aplicar && !$this->option('por')) {
            $this->error('Indique quién aprueba con --por=<id de usuario>.');

            return self::FAILURE;
        }

        $controlador = app(PaymentProofController::class);
        $aprobar = new \ReflectionMethod($controlador, 'aplicarYAprobar');
        $aprobar->setAccessible(true);

        $tabla = [];
        $total = 0;

        foreach ($ids as $id) {
            $p = PaymentProof::where('company_id', $empresa)->find($id);
            $cliente = $p ? trim((string) DB::table('user_data')->where('user_id', $p->user_id)->selectRaw("CONCAT(names, ' ', lastname) n")->value('n')) : '';
            $monto = $p ? (float) ($p->reported_amount ?? $p->detected_amount ?? 0) : 0;
            $factura = $p?->invoice;
            $motivo = match (true) {
                !$p => 'no existe en esta empresa',
                $p->status !== 'pending' => "ya está «{$p->status}»",
                $monto <= 0 => 'sin valor: apruébelo a mano escribiendo el monto',
                !$factura => 'sin factura asociada: revíselo a mano',
                (int) $factura->paid === 1 => "la factura {$factura->number_facture} ya está pagada",
                $this->yaAplicado($p, $monto) => 'ya hay un pago aplicado por ese valor después de la fecha del comprobante',
                default => null,
            };

            if ($motivo) {
                $tabla[] = [$id, $cliente, '$' . number_format($monto, 0, ',', '.'), $factura->number_facture ?? '—', 'NO: ' . $motivo];
                continue;
            }

            if ($aplicar) {
                try {
                    DB::transaction(fn () => $aprobar->invoke($controlador, $p, $monto, (int) $this->option('por'), (string) $this->option('motivo'), false));
                    $estado = 'APROBADO · factura ' . ((int) $factura->fresh()->paid === 1 ? 'pagada' : 'con abono');
                } catch (\Throwable $e) {
                    $estado = 'ERROR: ' . mb_substr($e->getMessage(), 0, 80);
                }
            } else {
                $estado = 'se aprobaría';
            }

            $total += $monto;
            $tabla[] = [$id, $cliente, '$' . number_format($monto, 0, ',', '.'), $factura->number_facture, $estado];
        }

        $this->line(($aplicar ? 'APLICADO' : 'SIMULACIÓN') . ' · empresa ' . $empresa);
        $this->table(['Comprobante', 'Cliente', 'Valor', 'Factura', 'Resultado'], $tabla);
        $this->line('Valor a aplicar: $' . number_format($total, 0, ',', '.'));

        if (!$aplicar) {
            $this->line('Nada se aprobó. Para aprobar: repita con --aplicar --por=<su id de usuario>.');
        }

        return self::SUCCESS;
    }

    private function yaAplicado(PaymentProof $p, float $monto): bool
    {
        $desde = $p->payment_date ? substr((string) $p->payment_date, 0, 10) : substr((string) $p->created_at, 0, 10);

        return DB::table('payment_logs as pl')->join('cab_facturations as cf', 'cf.id', '=', 'pl.cab_id')
            ->where('cf.user_id', $p->user_id)->where('pl.company_id', $p->company_id)
            ->whereBetween('pl.created_at', [$desde . ' 00:00:00', date('Y-m-d 23:59:59', strtotime($desde . ' +12 days'))])
            ->whereBetween('pl.amount', [$monto - 1000, $monto + 1000])->exists();
    }
}
