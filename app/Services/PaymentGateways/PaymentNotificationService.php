<?php

namespace App\Services\PaymentGateways;

use App\Models\CabFacturation;
use App\Models\Company;
use App\Models\DetFacturation;
use App\Models\OnlinePaymentTransaction;
use App\Models\PaymentLink;
use App\Models\WaTemplateBinding;
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
            if ($this->alreadyNotified($tx, $status)) return;

            $phone = $this->resolvePhone($tx);
            if (!$phone) {
                Log::info('Pago online: sin teléfono para notificar', [
                    'reference' => $tx->reference, 'company_id' => $company->id,
                ]);
                return;
            }

            $message = $this->buildMessage($tx, $status, $payload);
            if ($message === null) return;

            $wa     = new WhatsAppService($company->id);
            $result = $wa->mensajeInformativo($phone, $message);

            // Si el cliente pagó desde el portal sin habernos escrito, la ventana
            // de 24 h está cerrada y Meta solo acepta una plantilla aprobada.
            if (($result['code'] ?? null) === 'META_WINDOW_CLOSED') {
                $result = $this->sendAsTemplate($wa, $phone, $company, $tx, $status, $payload);
            }

            if (($result['success'] ?? true) === false) {
                Log::info('Pago online: no se pudo notificar por WhatsApp', [
                    'reference' => $tx->reference,
                    'status'    => $status,
                    'error'     => $result['error'] ?? 'sin detalle',
                ]);
                // Se libera el candado para poder reintentar en otro webhook.
                $this->releaseNotice($tx, $status);
            }
        } catch (\Throwable $e) {
            Log::error('Pago online: error notificando al cliente', [
                'reference' => $tx->reference ?? null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ─── Respaldo por plantilla ──────────────────────────────────────────────

    /** Cada desenlace de la pasarela corresponde a un hecho del negocio. */
    private const EVENTS = [
        'approved'  => 'pago_aprobado',
        'pending'   => 'pago_pendiente',
        'declined'  => 'pago_fallido',
        'failed'    => 'pago_fallido',
        'cancelled' => 'pago_fallido',
    ];

    /**
     * Reenvía el aviso como plantilla, según lo configurado en el panel.
     *
     * El orden de los parámetros no lo decide el código: lo decide quien
     * redactó la plantilla en Meta y lo dejó guardado en el vínculo.
     */
    private function sendAsTemplate(WhatsAppService $wa, string $phone, Company $company, OnlinePaymentTransaction $tx, string $status, array $payload): array
    {
        $event = self::EVENTS[$status] ?? null;
        if (!$event) {
            return ['success' => false, 'error' => "Sin plantilla para el estado '{$status}'."];
        }

        $binding = WaTemplateBinding::where('company_id', $company->id)->where('event', $event)->first();

        if (!$binding || !$binding->isUsable()) {
            return ['success' => false, 'error' => "La plantilla de '{$event}' no está activada en el panel."];
        }

        $context = $this->templateContext($company, $tx, $payload);
        $params  = [];

        foreach ((array) $binding->params as $variable) {
            $params[] = $context[$variable] ?? '';
        }

        return $wa->sendTemplate($phone, $binding->template_name, $params, $binding->language ?: 'es_CO');
    }

    /**
     * Valor de cada variable que el panel ofrece para armar la plantilla.
     *
     * @return array<string, string>
     */
    private function templateContext(Company $company, OnlinePaymentTransaction $tx, array $payload): array
    {
        $ids      = $this->invoiceIds($tx);
        $invoices = $ids ? DetFacturation::whereIn('id', $ids)->get() : collect();

        $owed = round($invoices->sum(fn ($inv) => $inv->outstanding()), 2);

        $numbers = $invoices->pluck('number_facture')->filter()->values();
        $factura = match (true) {
            $numbers->isEmpty() => '',
            $numbers->count() === 1 => (string) $numbers->first(),
            default => $numbers->first() . ' y ' . ($numbers->count() - 1) . ' más',
        };

        return [
            'cliente'          => $this->firstName($tx),
            'cliente_completo' => trim((string) $tx->customer_name) ?: $this->firstName($tx),
            'valor'            => $this->money($tx->amount),
            'plan'             => $this->planName($tx),
            'factura'          => $factura,
            'referencia'       => $this->voucher($tx, $payload),
            'medio_pago'       => $this->methodLabel($payload),
            'saldo'            => $this->money($owed),
            'estado_facturas'  => $this->invoiceSummary($tx),
            'empresa'          => (string) ($company->name ?: 'Netplay'),
            'soporte'          => (string) ($company->phone ?: ''),
            'fecha'            => now()->format('d/m/Y'),
        ];
    }

    /** Plan contratado por el dueño de la factura. */
    private function planName(OnlinePaymentTransaction $tx): string
    {
        $ids = $this->invoiceIds($tx);
        if ($ids === []) return '';

        $invoice = DetFacturation::find($ids[0]);
        $cab     = $invoice ? CabFacturation::find($invoice->cab_id) : null;
        if (!$cab) return '';

        $planId = DB::table('user_data')->where('user_id', $cab->user_id)->value('internet_plans_id');

        return (string) (DB::table('internet_plans')->where('id', $planId)->value('plan_name') ?: '');
    }

    /** Solo el nombre de pila: "Hola Juan" se lee mejor que el nombre completo. */
    private function firstName(OnlinePaymentTransaction $tx): string
    {
        $name = trim((string) $tx->customer_name);
        if ($name === '') return 'Hola';

        $first = mb_convert_case(mb_strtolower(explode(' ', $name)[0]), MB_CASE_TITLE, 'UTF-8');

        return $first !== '' ? $first : 'Hola';
    }

    /** Estado de las facturas en una sola línea, apta para una variable. */
    private function invoiceSummary(OnlinePaymentTransaction $tx): string
    {
        $lines = $this->invoiceLines($tx);
        if ($lines === []) return 'tu factura quedó al día';

        $pending = array_values(array_filter($lines, fn ($l) => str_contains($l, 'queda ')));

        if ($pending === []) {
            return count($lines) === 1
                ? 'tu factura quedó pagada'
                : 'tus ' . count($lines) . ' facturas quedaron pagadas';
        }

        $owed = 0.0;
        foreach ($this->invoiceIds($tx) as $id) {
            $inv = DetFacturation::find($id);
            if ($inv) $owed += $inv->outstanding();
        }

        return 'te queda un saldo de ' . $this->money($owed);
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
        $ids = $this->invoiceIds($tx);
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

    /** @return array<int> Facturas de la transacción, en el orden en que se cobran. */
    private function invoiceIds(OnlinePaymentTransaction $tx): array
    {
        $ids = !empty($tx->invoice_ids) ? $tx->invoice_ids : [$tx->det_facturation_id];

        return array_values(array_filter((array) $ids));
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

        $source = $this->prettySource($source);

        return match ($kind) {
            'credit' => "Tarjeta {$source}",
            'cash'   => "Efectivo ({$source})",
            default  => $source,
        };
    }

    /**
     * La pasarela mezcla mayúsculas y minúsculas según el medio ("NEQUI",
     * "Bre-B", "pse"). Al cliente se le muestra siempre bien escrito.
     */
    private function prettySource(string $source): string
    {
        return match (mb_strtolower($source)) {
            'pse'                 => 'PSE',
            'nequi'               => 'Nequi',
            'daviplata'           => 'Daviplata',
            'bre-b', 'breb'       => 'Bre-B',
            'american express'    => 'American Express',
            'diners club'         => 'Diners Club',
            default => mb_strtoupper($source) === $source
                ? mb_convert_case(mb_strtolower($source), MB_CASE_TITLE, 'UTF-8')
                : $source,
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
        // Si el cobro nació en un chat, la confirmación va a ese chat. El
        // teléfono de la cuenta puede ser otro -- y con las cuentas sin
        // teléfono de WhatsApp casi siempre lo es.
        $chat = PaymentLink::where('last_reference', $tx->reference)->value('created_for');
        if ($chat) {
            return $chat;
        }

        $invoiceId = $tx->det_facturation_id
            ?: (!empty($tx->invoice_ids) ? ($tx->invoice_ids[0] ?? null) : null);

        if (!$invoiceId) return null;

        $invoice = DetFacturation::find($invoiceId);
        if (!$invoice) return null;

        $cab = CabFacturation::find($invoice->cab_id);
        if (!$cab) return null;

        $phone = DB::table('user_data')->where('user_id', $cab->user_id)->value('phone');
        $phone = preg_replace('/\D+/', '', (string) $phone);

        if (strlen($phone) < 10) return null;

        // Meta exige indicativo de país. En la base los números se guardan casi
        // siempre a diez dígitos, así que se antepone el de Colombia.
        return strlen($phone) === 10 ? '57' . $phone : $phone;
    }

    private function money(float|int|string|null $value): string
    {
        return '$' . number_format((float) $value, 0, ',', '.');
    }

    /**
     * ¿Ya se avisó este estado? El caché es solo una segunda barrera: el webhook
     * ya filtra por cambio de estado. Si el caché falla -- disco lleno, permisos
     * -- se avisa igual, porque callar un pago acreditado es peor que repetirlo.
     */
    private function alreadyNotified(OnlinePaymentTransaction $tx, string $status): bool
    {
        $key = $this->dedupeKey($tx, $status);

        try {
            if (Cache::has($key)) return true;
            Cache::add($key, 1, self::DEDUPE_TTL);
        } catch (\Throwable $e) {
            Log::warning('Pago online: el caché no pudo registrar el aviso, se envía igual', [
                'reference' => $tx->reference,
                'error'     => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function releaseNotice(OnlinePaymentTransaction $tx, string $status): void
    {
        try {
            Cache::forget($this->dedupeKey($tx, $status));
        } catch (\Throwable $e) {
            // Sin candado que liberar: el próximo webhook reintentará igual.
        }
    }

    private function dedupeKey(OnlinePaymentTransaction $tx, string $status): string
    {
        return "pay:notify:{$tx->id}:{$status}";
    }
}
