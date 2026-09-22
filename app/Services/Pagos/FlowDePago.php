<?php

namespace App\Services\Pagos;

use App\Models\Company;
use App\Models\OnlinePaymentTransaction;
use App\Services\Cobranza\Deuda;
use App\Services\PaymentGateways\PaymentInitiationService;
use App\Services\PaymentGateways\PaymentLinkService;
use App\Services\PaymentGateways\WompiGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El pago dentro de WhatsApp: las pantallas y lo que pasa en cada una.
 *
 * Cinco pantallas, como el flujo que ya conoce la gente: el detalle de lo que
 * va a pagar, con qué quiere pagarlo, y después lo que corresponda según el
 * medio. Con Nequi se queda en WhatsApp —el cobro le llega a su app—; con
 * Bancolombia o PSE hay que salir, y eso se le dice antes de que lo elija,
 * no después.
 *
 * Los medios que se ofrecen son los que la pasarela de la empresa acepta de
 * verdad: un botón que termina en un error del banco es peor que no tenerlo.
 */
class FlowDePago
{
    /**
     * Cómo se llama y qué se le advierte de cada medio.
     *
     * El orden importa: primero el que no obliga a salir de WhatsApp. Y la
     * advertencia es literal, porque descubrir a mitad de camino que hay que
     * salir de la app es lo que hace que la gente abandone el pago.
     */
    private const MEDIOS = [
        'nequi' => [
            'title'       => 'Nequi',
            'description' => 'Se aprueba al instante desde tu app, sin salir de WhatsApp',
            'wompi'       => 'NEQUI',
        ],
        'bancolombia' => [
            'title'       => 'Botón Bancolombia',
            'description' => 'Sales a tu banco para aprobar y vuelves al chat',
            'wompi'       => 'BANCOLOMBIA_TRANSFER',
        ],
        'daviplata' => [
            'title'       => 'Daviplata',
            'description' => 'Sales a Daviplata para autorizar el pago',
            'wompi'       => 'DAVIPLATA',
        ],
        'pse' => [
            'title'       => 'PSE',
            'description' => 'Sales a tu banco con tu usuario y clave',
            'wompi'       => 'PSE',
        ],
        'tarjeta' => [
            'title'       => 'Tarjeta de crédito o débito',
            'description' => 'Sales a una página segura para poner los datos de la tarjeta',
            'wompi'       => 'CARD',
        ],
    ];

    /**
     * Lo que se ve en la vista previa de Meta.
     *
     * Ahí no hay cliente: el Flow se abre sin el token que mandamos nosotros
     * en el mensaje. Antes eso mostraba «no pudimos reconocer tu cuenta», que
     * daba la impresión de que el Flow estaba roto cuando estaba bien.
     */
    public function pantallaDeEjemplo(): array
    {
        return [
            'screen' => 'DETALLE',
            'data'   => [
                'concepto' => 'Pago de 2 facturas',
                'total'    => '$ 140.000',
                'facturas' => 'NT15110 · NT15460',
                'empresa'  => 'tu proveedor de internet',
                'opciones' => array_values(array_map(
                    fn ($id, $m) => ['id' => $id, 'title' => $m['title'], 'description' => $m['description']],
                    array_keys(self::MEDIOS),
                    self::MEDIOS,
                )),
            ],
        ];
    }

    /** Primera pantalla: qué va a pagar y con qué puede pagarlo. */
    public function pantallaInicial(int $companyId, int $userId): array
    {
        $deuda = $this->deuda($companyId, $userId);

        if (!$deuda['cuantas']) {
            return $this->cerrar('No tienes nada pendiente', 'Tus facturas están al día. ¡Gracias!');
        }

        $empresa = Company::find($companyId);
        $medios  = $this->mediosDisponibles($empresa);

        if (!$medios) {
            return $this->cerrar(
                'Pago en línea no disponible',
                'Ahora mismo no podemos cobrarte por aquí. Escríbenos y te pasamos los datos de pago.',
            );
        }

        return [
            'screen' => 'DETALLE',
            'data'   => [
                'concepto' => $deuda['cuantas'] === 1 ? 'Pago de 1 factura' : "Pago de {$deuda['cuantas']} facturas",
                'total'    => $this->pesos($deuda['total']),
                'facturas' => implode(' · ', $deuda['numeros']),
                'empresa'  => (string) ($empresa->name ?? 'tu proveedor'),
                'opciones' => $medios,
            ],
        ];
    }

    /**
     * Eligió un medio.
     *
     * Con Nequi se pide el celular; con los demás se arma el enlace del banco
     * y se le avisa que va a salir de WhatsApp.
     */
    public function eligioMedio(int $companyId, int $userId, string $medio): array
    {
        $deuda = $this->deuda($companyId, $userId);

        if (!$deuda['cuantas']) {
            return $this->cerrar('Ya no hay nada que pagar', 'Tus facturas quedaron al día.');
        }

        if (!isset(self::MEDIOS[$medio])) {
            return $this->cerrar('No se pudo', 'Ese medio de pago no está disponible.');
        }

        if ($medio === 'nequi') {
            $celular = (string) DB::table('user_data')->where('user_id', $userId)->value('phone');

            return [
                'screen' => 'NEQUI',
                'data'   => [
                    'total'   => $this->pesos($deuda['total']),
                    'celular' => substr(preg_replace('/\D/', '', $celular), -10),
                ],
            ];
        }

        $enlace = $this->enlaceDelBanco($companyId, $userId, $medio);

        if (!$enlace) {
            return $this->cerrar('No se pudo', 'No pudimos armar el pago ahora. Escríbenos y te ayudamos.');
        }

        $banco = self::MEDIOS[$medio]['title'];

        return [
            'screen' => 'AFUERA',
            'data'   => [
                'banco'  => $banco,
                'total'  => $this->pesos($deuda['total']),
                'aviso'  => "Vas a salir de WhatsApp para aprobar el pago de {$this->pesos($deuda['total'])} en {$banco}.",
                'enlace' => $enlace,
            ],
        ];
    }

    /** Tocó «Enviar el cobro»: se dispara contra su Nequi. */
    public function enviarCobroNequi(int $companyId, int $userId, string $celular): array
    {
        $company = Company::find($companyId);
        $deuda   = $this->deuda($companyId, $userId);
        $numero  = substr(preg_replace('/\D/', '', $celular), -10);

        if (!$company || !$deuda['cuantas']) {
            return $this->cerrar('No se pudo', 'No encontramos facturas pendientes para cobrar.');
        }

        if (strlen($numero) !== 10) {
            return $this->cerrar('Revisa el número', 'Ese número no parece un celular colombiano. Inténtalo de nuevo.');
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
                customerPhone: $numero,
            );
        } catch (\Throwable $e) {
            Log::error('[Flow de pago] No se pudo crear el cobro de Nequi', ['empresa' => $companyId, 'error' => $e->getMessage()]);

            return $this->cerrar('No se pudo', 'No pudimos enviar el cobro ahora. Escríbenos y te ayudamos.');
        }

        if (!OnlinePaymentTransaction::where('reference', $r['reference'] ?? '')->exists()) {
            return $this->cerrar('No se pudo', 'No pudimos enviar el cobro ahora. Escríbenos y te ayudamos.');
        }

        return $this->cerrar(
            'Te enviamos el cobro',
            "Abre tu app de Nequi y aprueba el cobro de {$this->pesos($deuda['total'])}. Te llegó al {$numero}.",
            'Apenas lo apruebes, tus facturas quedan al día y te avisamos por aquí.',
        );
    }

    /** Dijo que ya abrió el banco: se queda esperando la confirmación. */
    public function esperandoAlBanco(): array
    {
        return $this->cerrar(
            'Estamos esperando el pago',
            'Cuando el banco lo confirme te avisamos por este chat y tus facturas quedan al día.',
            'Si algo falló, escribinos y lo revisamos.',
        );
    }

    // ── Adentro ───────────────────────────────────────────────────────────

    /** El enlace que lleva derecho al banco, con su monto y su referencia. */
    private function enlaceDelBanco(int $companyId, int $userId, string $medio): ?string
    {
        try {
            $servicio = app(PaymentLinkService::class);
            $deuda    = $this->deuda($companyId, $userId);

            $link = $servicio->create(
                Company::findOrFail($companyId),
                $userId,
                $deuda['facturas']->pluck('id')->all(),
                'whatsapp_flow',
                null,
                (string) DB::table('user_data')->where('user_id', $userId)->value('phone'),
            );

            return $servicio->publicUrl($link) . '?m=' . $medio;
        } catch (\Throwable $e) {
            Log::error('[Flow de pago] No se pudo armar el enlace del banco', ['medio' => $medio, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Los medios que la pasarela de la empresa acepta, en el formato que
     * espera la lista de la pantalla.
     *
     * @return list<array{id:string, title:string, description:string}>
     */
    private function mediosDisponibles(?Company $empresa): array
    {
        if (!$empresa || !$empresa->pg_active || strtolower((string) $empresa->pg_gateway) !== 'wompi') {
            return [];
        }

        try {
            $acepta = (new WompiGateway($empresa))->metodosAceptados();
        } catch (\Throwable $e) {
            return [];
        }

        $lista = [];

        foreach (self::MEDIOS as $id => $m) {
            if (in_array($m['wompi'], $acepta, true)) {
                $lista[] = ['id' => $id, 'title' => $m['title'], 'description' => $m['description']];
            }
        }

        return $lista;
    }

    /** @return array{facturas:\Illuminate\Support\Collection, cuantas:int, total:float, numeros:array} */
    private function deuda(int $companyId, int $userId): array
    {
        $facturas = Deuda::de($companyId, $userId)->facturas;

        return [
            'facturas' => $facturas,
            'cuantas'  => $facturas->count(),
            'total'    => (float) $facturas->sum(fn ($f) => $f->outstanding()),
            'numeros'  => $facturas->take(4)->pluck('number_facture')->all(),
        ];
    }

    private function cerrar(string $encabezado, string $mensaje, string $nota = ''): array
    {
        return [
            'screen' => 'LISTO',
            'data'   => ['encabezado' => $encabezado, 'mensaje' => $mensaje, 'nota' => $nota],
        ];
    }

    private function pesos(float $monto): string
    {
        return '$ ' . number_format($monto, 0, ',', '.');
    }
}
