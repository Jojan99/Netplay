<?php

namespace App\Services\PaymentGateways;

use App\Models\CabFacturation;
use App\Models\Company;
use App\Models\DetFacturation;
use App\Models\PaymentLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Links de pago compartibles (WhatsApp, correo, panel).
 *
 * El link no guarda el checkout de la pasarela: guarda a quién pertenece y qué
 * debe cobrar. El cobro se genera en el instante en que alguien lo abre, así el
 * monto es siempre el saldo vigente y no uno congelado al momento de enviarlo.
 */
class PaymentLinkService
{
    /** Vigencia por defecto de un link enviado al cliente. */
    private const DEFAULT_TTL_DAYS = 7;

    public function __construct(private PaymentInitiationService $initiator) {}

    // ─── Creación ────────────────────────────────────────────────────────────

    /**
     * @param  array<int>|null  $invoiceIds  null = todas las facturas pendientes al abrirlo.
     */
    public function create(
        Company $company,
        int $clientUserId,
        ?array $invoiceIds = null,
        string $createdVia = 'bot',
        ?int $ttlDays = null,
    ): PaymentLink {
        return PaymentLink::create([
            'company_id'  => $company->id,
            'user_id'     => $clientUserId,
            'token'       => $this->generateToken(),
            'scope'       => $invoiceIds === null ? 'all_pending' : 'invoice',
            'invoice_ids' => $invoiceIds !== null ? array_values($invoiceIds) : null,
            'created_via' => $createdVia,
            'expires_at'  => now()->addDays($ttlDays ?? self::DEFAULT_TTL_DAYS)->endOfDay(),
        ]);
    }

    public function publicUrl(PaymentLink $link): string
    {
        return url('/api/pay/' . $link->token);
    }

    // ─── Resolución al abrirlo ───────────────────────────────────────────────

    /**
     * Convierte el token en una URL de checkout lista para redirigir.
     *
     * @return array{url: string, reference: string, amount: float}
     * @throws \RuntimeException con un mensaje apto para mostrarle al cliente
     */
    public function resolveToCheckout(string $token): array
    {
        $link = PaymentLink::where('token', $token)->first();

        if (!$link) {
            throw new \RuntimeException('Este link de pago no es válido.');
        }

        if ($link->isExpired()) {
            throw new \RuntimeException('Este link de pago ya venció. Pídele uno nuevo a tu proveedor.');
        }

        if ($link->isExhausted()) {
            throw new \RuntimeException('Este link de pago ya fue utilizado.');
        }

        $company = Company::find($link->company_id);
        if (!$company || !$company->pg_active || !$company->pg_gateway) {
            throw new \RuntimeException('El pago en línea no está disponible en este momento.');
        }

        $invoices = $this->pendingInvoices($link);

        if ($invoices->isEmpty()) {
            throw new \RuntimeException('No tienes facturas pendientes por pagar. ¡Estás al día!');
        }

        $amount = round(
            $invoices->sum(fn($inv) => $inv->price_total - (float) ($inv->abone ?? 0)),
            2
        );

        if ($amount <= 0) {
            throw new \RuntimeException('No tienes facturas pendientes por pagar. ¡Estás al día!');
        }

        $result = $this->initiator->initiate(
            company:      $company,
            clientUserId: $link->user_id,
            invoices:     $invoices,
            amount:       $amount,
            redirectUrl:  url('/portal/facturas'),
            // El cobro no puede sobrevivir al link que lo originó.
            limitDate:    optional($link->expires_at)->format('Y-m-d'),
            origin:       'payment_link',
        );

        $link->increment('used_count');
        $link->update([
            'last_reference' => $result['reference'],
            'last_used_at'   => now(),
        ]);

        return [
            'url'       => $result['payment_url'],
            'reference' => $result['reference'],
            'amount'    => $result['amount'],
        ];
    }

    /**
     * Saldo vigente del cliente: solo facturas suyas y sin pagar, de la más
     * antigua a la más nueva (el mismo orden en que se aplican los abonos).
     */
    public function pendingInvoices(PaymentLink $link): Collection
    {
        $cabIds = CabFacturation::where('company_id', $link->company_id)
            ->where('user_id', $link->user_id)
            ->pluck('id');

        if ($cabIds->isEmpty()) {
            return collect();
        }

        $query = DetFacturation::whereIn('cab_id', $cabIds)->where('paid', 0);

        // Si el link se creó para facturas concretas, no puede cobrar otras.
        if ($link->scope === 'invoice' && !empty($link->invoice_ids)) {
            $query->whereIn('id', $link->invoice_ids);
        }

        return $query->orderBy('date_facturation')->orderBy('id')->get()
            ->filter(fn($inv) => round($inv->price_total - (float) ($inv->abone ?? 0), 2) > 0)
            ->values();
    }

    /** Token con suficiente entropía para no ser adivinable por fuerza bruta. */
    private function generateToken(): string
    {
        do {
            $token = Str::lower(Str::random(40));
        } while (PaymentLink::where('token', $token)->exists());

        return $token;
    }
}
