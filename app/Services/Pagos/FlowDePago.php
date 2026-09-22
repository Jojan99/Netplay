<?php

namespace App\Services\Pagos;

use App\Models\Company;
use App\Models\OnlinePaymentTransaction;
use App\Services\PaymentGateways\PaymentInitiationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El pago dentro de WhatsApp: pantallas de Meta, cobro por Nequi.
 *
 * Meta abre una pantalla dentro del chat («Flow»), el cliente confirma su
 * número y nosotros disparamos el cobro. La aprobación ocurre en su app de
 * Nequi —eso no lo podemos evitar, es el banco quien autoriza— pero el cliente
 * nunca abre un navegador ni sale de la conversación.
 *
 * Meta manda y espera todo cifrado; de eso se encarga SobreCifrado.
 */
class FlowDePago
{
    /** Lo que se le muestra al abrir: su deuda y su número. */
    public function pantallaInicial(int $companyId, int $userId): array
    {
        $deuda = $this->deuda($companyId, $userId);

        if (!$deuda['facturas']) {
            return [
                'screen' => 'LISTO',
                'data'   => [
                    'encabezado' => 'No tenés nada pendiente',
                    'mensaje'    => 'Tus facturas están al día. ¡Gracias!',
                    'nota'       => '',
                ],
            ];
        }

        $celular = (string) DB::table('user_data')->where('user_id', $userId)->value('phone');

        return [
            'screen' => 'RESUMEN',
            'data'   => [
                'titulo'  => $deuda['cuantas'] === 1
                    ? 'Tenés 1 factura pendiente'
                    : "Tenés {$deuda['cuantas']} facturas pendientes",
                'total'   => '$ ' . number_format($deuda['total'], 0, ',', '.'),
                'detalle' => implode(' · ', $deuda['numeros']),
                'celular' => substr(preg_replace('/\D/', '', $celular), -10),
            ],
        ];
    }

    /**
     * El cliente tocó «Enviar el cobro»: se crea contra su número de Nequi.
     */
    public function enviarCobro(int $companyId, int $userId, string $celular): array
    {
        $company = Company::find($companyId);
        $deuda   = $this->deuda($companyId, $userId);
        $numero  = substr(preg_replace('/\D/', '', $celular), -10);

        if (!$company || !$deuda['facturas']) {
            return $this->falla('No encontramos facturas pendientes para cobrar.');
        }

        if (strlen($numero) !== 10) {
            return $this->falla('Ese número no parece un celular colombiano. Revisalo e intentá de nuevo.');
        }

        try {
            $r = app(PaymentInitiationService::class)->initiate(
                company:      $company,
                clientUserId: $userId,
                invoices:     $deuda['facturas'],
                amount:       $deuda['total'],
                origin:       'whatsapp_flow',
                returnTo:     'whatsapp',
                paymentMethods: ['NEQUI'],
                // El número que confirmó en la pantalla manda sobre el de su ficha.
                customerPhone: $numero,
            );
        } catch (\Throwable $e) {
            Log::error('[Flow de pago] No se pudo crear el cobro', ['empresa' => $companyId, 'error' => $e->getMessage()]);

            return $this->falla('No pudimos enviar el cobro ahora. Escribinos y te ayudamos.');
        }

        $hecho = OnlinePaymentTransaction::where('reference', $r['reference'] ?? '')->exists();

        if (!$hecho) {
            return $this->falla('No pudimos enviar el cobro ahora. Escribinos y te ayudamos.');
        }

        $plata = '$ ' . number_format($deuda['total'], 0, ',', '.');

        return [
            'screen' => 'LISTO',
            'data'   => [
                'encabezado' => 'Te enviamos el cobro',
                'mensaje'    => "Abrí tu app de Nequi y aprobá el cobro de {$plata}. Te llegó al {$numero}.",
                'nota'       => 'Apenas lo apruebes, tus facturas quedan al día y te avisamos por acá.',
            ],
        ];
    }

    /** Lo que debe el cliente, con las facturas para cobrar. */
    private function deuda(int $companyId, int $userId): array
    {
        $facturas = \App\Services\Cobranza\Deuda::de($companyId, $userId)->facturas;

        return [
            'facturas' => $facturas,
            'cuantas'  => $facturas->count(),
            'total'    => (float) $facturas->sum(fn ($f) => $f->outstanding()),
            'numeros'  => $facturas->take(4)->pluck('number_facture')->all(),
        ];
    }

    private function falla(string $mensaje): array
    {
        return [
            'screen' => 'LISTO',
            'data'   => ['encabezado' => 'No se pudo', 'mensaje' => $mensaje, 'nota' => ''],
        ];
    }
}
