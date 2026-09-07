<?php

namespace App\Services\PaymentGateways;

use App\Models\CabFacturation;
use App\Models\Company;
use App\Models\DetFacturation;
use App\Models\OnlinePaymentTransaction;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Avisa al cliente por WhatsApp cómo terminó su pago en línea.
 *
 * Se dispara desde el webhook, que es el único punto por donde entra el
 * resultado real de una transacción, sin importar si el cobro nació en el
 * portal, en un link compartido o dentro del bot.
 *
 * Nunca lanza excepciones: un fallo avisando no puede tumbar la acreditación
 * del pago, que ya ocurrió antes de llegar aquí.
 */
class PaymentNotificationService
{
    /** Ventana en la que un mismo estado no se vuelve a notificar. */
    private const DEDUPE_TTL = 86400;

    public function notify(Company $company, OnlinePaymentTransaction $tx, string $status, array $payload = []): void
    {
        try {
            // EfiPay reintenta el webhook a los 10s y a los 100s. Un solo aviso
            // por transacción y estado; el cliente no debe recibir tres iguales.
            //
            // Se consulta y se marca por separado a propósito: si el caché no
            // puede escribir, el aviso igual sale. Un mensaje repetido molesta;
            // un pago acreditado sin avisar deja al cliente sin saber si pagó.
            $key = $this->dedupeKey($tx, $status);
            if (Cache::has($key)) return;
            Cache::add($key, 1, self::DEDUPE_TTL);

            $phone = $this->resolvePhone($tx);
            if (!$phone) {
                Log::info('Pago online: sin teléfono para notificar', [
                    'reference' => $tx->reference, 'company_id' => $company->id,
                ]);
                return;
            }

            $message = $this->buildMessage($tx, $status, $payload);
            if ($message === null) return;

            $result = (new WhatsAppService($company->id))->mensajeInformativo($phone, $message);

            if (($result['success'] ?? true) === false) {
                // La causa más común es la ventana de 24 h cerrada: el cliente
                // pagó desde el portal sin haber escrito antes por WhatsApp.
                Log::info('Pago online: no se pudo notificar por WhatsApp', [
                    'reference' => $tx->reference,
                    'status'    => $status,
                    'error'     => $result['error'] ?? 'sin detalle',
                ]);
                // Se libera el candado para poder reintentar en otro webhook.
                Cache::forget($this->dedupeKey($tx, $status));
            }
        } catch (\Throwable $e) {
            Log::error('Pago online: error notificando al cliente', [
                'reference' => $tx->reference ?? null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ─── Mensajes ────────────────────────────────────────────────────────────

    private function buildMessage(OnlinePaymentTransaction $tx, string $status, array $payload): ?string
    {
        $amount   = $this->money($tx->amount);
        $method   = $this->methodLabel($payload);
        $voucher  = $this->voucher($tx, $payload);

        return match ($status) {
            'approved'  => $this->approvedMessage($tx, $amount, $method, $voucher),
            'pending'   => $this->pendingMessage($tx, $amount, $method, $voucher),
            'declined',
            'failed'    => $this->declinedMessage($amount, $method, $voucher),
            'cancelled' => $this->cancelledMessage($amount, $method, $voucher),
            default     => null,
        };
    }

    private function approvedMessage(OnlinePaymentTransaction $tx, string $amount, string $method, string $voucher): string
    {
        $lines = [
            "✅ *Pago confirmado*",
            "",
            "Recibimos tu pago por *{$amount}*.",
            "",
            "Medio de pago: {$method}",
            "Comprobante: {$voucher}",
            "Fecha: " . now()->format('d/m/Y h:i a'),
        ];

        $detail = $this->invoiceLines($tx);
        if ($detail !== []) {
            $lines[] = "";
            $lines[] = count($detail) > 1 ? "Facturas cubiertas:" : "Factura:";
            foreach ($detail as $line) $lines[] = $line;
        }

        $lines[] = "";
        $lines[] = "Gracias por estar al día. Si necesitas algo, respóndenos por aquí.";

        return implode("\n", $lines);
    }

    private function pendingMessage(OnlinePaymentTransaction $tx, string $amount, string $method, string $voucher): string
    {
        return implode("\n", [
            "⏳ *Pago en proceso*",
            "",
            "Registramos tu pago por *{$amount}*, pero todavía no se ha acreditado.",
            "",
            "Medio de pago: {$method}",
            "Comprobante: {$voucher}",
            "",
            "Si pagaste en efectivo o por transferencia, la confirmación puede tardar unas horas.",
            "Te avisamos por este mismo chat apenas se acredite. No necesitas volver a pagar.",
        ]);
    }

    private function declinedMessage(string $amount, string $method, string $voucher): string
    {
        return implode("\n", [
            "❌ *Pago no completado*",
            "",
            "Tu pago por *{$amount}* con {$method} no pudo procesarse.",
            "Comprobante: {$voucher}",
            "",
            "*No se te hizo ningún cobro* y tu factura sigue pendiente.",
            "Puedes intentarlo de nuevo con otro medio de pago, o escribirnos por aquí si necesitas ayuda.",
        ]);
    }

    private function cancelledMessage(string $amount, string $method, string $voucher): string
    {
        return implode("\n", [
            "⚠️ *Pago anulado*",
            "",
            "El pago por *{$amount}* con {$method} fue anulado.",
            "Comprobante: {$voucher}",
            "",
            "Si el dinero salió de tu cuenta, se te devuelve automáticamente.",
            "Tu factura sigue pendiente. Escríbenos por aquí si tienes dudas.",
        ]);
    }

    // ─── Detalle de facturas ─────────────────────────────────────────────────

    /**
     * Estado de cada factura después de aplicar el pago. Se lee de la base y no
     * del monto notificado, para que el cliente vea su saldo real.
     *
     * @return array<string>
     */
    private function invoiceLines(OnlinePaymentTransaction $tx): array
    {
        $ids = !empty($tx->invoice_ids) ? $tx->invoice_ids : [$tx->det_facturation_id];
        $ids = array_values(array_filter((array) $ids));
        if ($ids === []) return [];

        $invoices = DetFacturation::whereIn('id', $ids)->get()
            ->sortBy(fn ($inv) => array_search($inv->id, $ids))
            ->values();

        $lines = [];
        foreach ($invoices as $invoice) {
            $owed = $invoice->outstanding();
            $lines[] = $owed <= 0
                ? "• {$invoice->number_facture} — pagada"
                : "• {$invoice->number_facture} — queda " . $this->money($owed);
        }

        return $lines;
    }

    // ─── Datos de la pasarela ────────────────────────────────────────────────

    /** Nombre entendible del medio de pago, desde lo que reporta la pasarela. */
    private function methodLabel(array $payload): string
    {
        $source = trim((string) data_get($payload, 'transaction.payment_method_source', ''));
        $kind   = mb_strtolower(trim((string) data_get($payload, 'transaction.payment_method', '')));

        if ($source === '') {
            return match ($kind) {
                'credit' => 'Tarjeta de crédito',
                'debit'  => 'Tarjeta débito',
                'pse'    => 'PSE',
                'cash'   => 'Efectivo',
                default  => 'Pago en línea',
            };
        }

        if (mb_strtolower($source) === 'pse') return 'PSE';

        return match ($kind) {
            'credit' => "Tarjeta {$source}",
            'cash'   => "Efectivo ({$source})",
            default  => $source,
        };
    }

    /** Comprobante que el cliente puede citarnos: el id de la pasarela. */
    private function voucher(OnlinePaymentTransaction $tx, array $payload): string
    {
        $id = data_get($payload, 'transaction.transaction_id')
            ?? data_get($payload, 'checkout.pivot.transaction_id')
            ?? $tx->gateway_transaction_id;

        return $id ? (string) $id : (string) $tx->reference;
    }

    // ─── Utilidades ──────────────────────────────────────────────────────────

    /** Teléfono del dueño de la factura, no el que haya escrito el pago. */
    private function resolvePhone(OnlinePaymentTransaction $tx): ?string
    {
        $invoiceId = $tx->det_facturation_id
            ?: (!empty($tx->invoice_ids) ? ($tx->invoice_ids[0] ?? null) : null);

        if (!$invoiceId) return null;

        $invoice = DetFacturation::find($invoiceId);
        if (!$invoice) return null;

        $cab = CabFacturation::find($invoice->cab_id);
        if (!$cab) return null;

        $phone = DB::table('user_data')->where('user_id', $cab->user_id)->value('phone');
        $phone = preg_replace('/\D+/', '', (string) $phone);

        return strlen($phone) >= 10 ? $phone : null;
    }

    private function money(float|int|string|null $value): string
    {
        return '$' . number_format((float) $value, 0, ',', '.');
    }

    private function dedupeKey(OnlinePaymentTransaction $tx, string $status): string
    {
        return "pay:notify:{$tx->id}:{$status}";
    }
}
