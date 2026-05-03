<?php

namespace App\Services\PaymentGateways;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * ZonaPago no publica documentación de su API — se entrega solo a comercios
 * registrados. Esta clase está lista para implementarse cuando tengas las
 * credenciales y la documentación de Zona Virtual.
 *
 * Contacto: infozonapagos@zonavirtual.com.co
 * Recursos: https://zonavirtual.com/desarrolladores/
 */
class ZonaPagoGateway implements PaymentGatewayInterface
{
    public function __construct(private Company $company) {}

    public function generatePaymentLink(array $data): string
    {
        throw new \RuntimeException(
            'ZonaPago requiere credenciales y documentación de Zona Virtual. ' .
            'Contáctalos en infozonapagos@zonavirtual.com.co'
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        // TODO: implementar verificación de firma cuando se tenga la documentación
        return false;
    }

    public function getInvoiceReference(Request $request): string
    {
        return $request->input('reference', '');
    }

    public function isApproved(Request $request): bool
    {
        return false;
    }

    public function getAmountPaid(Request $request): float
    {
        return 0.0;
    }

    public function getTransactionStatus(Request $request): string
    {
        return 'pending';
    }
}
