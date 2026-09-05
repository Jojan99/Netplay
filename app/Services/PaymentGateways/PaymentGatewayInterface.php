<?php

namespace App\Services\PaymentGateways;

use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Genera la URL a la que se redirige al cliente para pagar.
     *
     * @param array{
     *   reference: string,
     *   amount: float,
     *   description: string,
     *   customer_email: string,
     *   customer_name: string,
     *   redirect_url: string,
     * } $data
     */
    public function generatePaymentLink(array $data): string;

    /** Valida que la firma del webhook sea legítima. */
    public function verifyWebhook(Request $request): bool;

    /** Extrae la referencia de factura desde el payload del webhook. */
    public function getInvoiceReference(Request $request): string;

    /** Retorna true si el pago fue aprobado. */
    public function isApproved(Request $request): bool;

    /** Monto pagado (en pesos, no centavos). */
    public function getAmountPaid(Request $request): float;

    /** Retorna el estado normalizado: approved | declined | cancelled | failed | pending */
    public function getTransactionStatus(Request $request): string;

    /**
     * Identificador que la pasarela asignó al pago en la última llamada a
     * generatePaymentLink(). Se persiste para poder conciliar por API.
     * Null si la pasarela no entrega uno en ese momento.
     */
    public function getLastGatewayReference(): ?string;
}
