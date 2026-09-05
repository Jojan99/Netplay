<?php

namespace App\Services\PaymentGateways;

use App\Models\Company;

class PaymentGatewayFactory
{
    public static function make(Company $company): PaymentGatewayInterface
    {
        return match ($company->pg_gateway) {
            'wompi'    => new WompiGateway($company),
            'epayco'   => new EPaycoGateway($company),
            'zonapago' => new ZonaPagoGateway($company),
            'efipay'   => new EfiPayGateway($company),
            default    => throw new \RuntimeException(
                "Pasarela de pago '{$company->pg_gateway}' no configurada."
            ),
        };
    }

    public static function availableGateways(): array
    {
        return [
            ['id' => 'wompi',    'name' => 'Wompi (Bancolombia)', 'fields' => ['public_key', 'private_key', 'events_secret', 'integrity_secret']],
            ['id' => 'epayco',   'name' => 'ePayco',              'fields' => ['client_id', 'private_key']],
            ['id' => 'zonapago', 'name' => 'ZonaPago',            'fields' => ['public_key', 'private_key']],
            ['id' => 'efipay',   'name' => 'EfiPay',              'fields' => ['private_key', 'events_secret', 'office_id']],
        ];
    }
}
