<?php

namespace App\Services\PaymentGateways\OnePay;

use App\Models\Company;
use App\Models\DetFacturation;
use App\Models\OnlinePaymentTransaction;
use App\Services\PaymentGateways\OnePayGateway;
use App\Services\PaymentGateways\PaymentInitiationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cobrarle a un cliente por WhatsApp con OnePay.
 *
 * Todo lo que puede salir mal se revisa ANTES de crear el cobro, y por una
 * razón concreta: un cobro creado ya existe del lado de OnePay aunque el
 * mensaje no le llegue a nadie, y después hay que andar anulándolo a mano.
 * Es más barato negarse temprano y decir por qué.
 *
 * El envío lo hace OnePay con su canal de WhatsApp aprobado por Meta, no el
 * bot nuestro: no hay que armar el mensaje ni pedirle plantilla a Meta.
 */
class CobroPorWhatsapp
{
    public function __construct(private PaymentInitiationService $inicio) {}

    /**
     * Le manda el cobro de unas facturas al WhatsApp del cliente.
     *
     * @param  list<int>  $facturaIds  Vacío = todo lo que deba.
     * @return array<string,mixed>
     *
     * @throws OnePayError con el motivo exacto, listo para mostrar en pantalla.
     */
    public function enviar(Company $company, int $userId, array $facturaIds = [], ?int $plantilla = null, ?float $monto = null): array
    {
        $this->exigirPasarela($company);

        $cliente = DB::table('user_data')->where('user_id', $userId)
            ->where('company_id', $company->id)
            ->first(['names', 'lastname', 'email', 'phone']);

        if (!$cliente) {
            throw new OnePayError('Ese cliente no es de esta empresa.');
        }

        $telefono = $this->telefonoDelCliente($cliente);

        $facturas = $this->facturasPorCobrar($company, $userId, $facturaIds);

        if ($facturas->isEmpty()) {
            throw new OnePayError('El cliente no tiene facturas pendientes para cobrar.');
        }

        $deuda = round($facturas->sum(fn (DetFacturation $f) => $f->outstanding()), 2);
        $total = $monto !== null ? round($monto, 2) : $deuda;

        if ($total <= 0) {
            throw new OnePayError('No hay saldo pendiente: las facturas elegidas ya están pagas.');
        }

        if ($total > $deuda) {
            throw new OnePayError(
                'El monto a cobrar ($' . number_format($total, 0, ',', '.') . ') supera lo que el cliente debe ($'
                . number_format($deuda, 0, ',', '.') . ').'
            );
        }

        // Un cobro vivo por las mismas facturas: mandarle otro le deja dos
        // mensajes con dos links y termina pagando dos veces. Se reenvía el
        // que ya existe, que además conserva el estado.
        if ($vivo = $this->cobroVivo($company, $facturas->pluck('id')->all())) {
            return $this->reenviar($company, $vivo, $plantilla)
                + ['reutilizado' => true];
        }

        $plantilla = $this->plantillaValida($company, $plantilla);

        $r = $this->inicio->initiate(
            company: $company,
            clientUserId: $userId,
            invoices: $facturas,
            amount: $total,
            returnTo: 'whatsapp',
            origin: 'whatsapp_onepay',
            customerPhone: $telefono,
            porWhatsapp: true,
            plantillaWhatsapp: $plantilla,
            limitDate: optional($facturas->first())->date_facturation,
        );

        Log::info('[OnePay] Cobro mandado por WhatsApp', [
            'empresa'   => $company->id,
            'cliente'   => $userId,
            'facturas'  => $facturas->pluck('id')->all(),
            'monto'     => $total,
            'referencia' => $r['reference'] ?? null,
        ]);

        return $r + ['reutilizado' => false, 'telefono' => $telefono];
    }

    /**
     * Vuelve a mandar un cobro que ya existe.
     *
     * @return array<string,mixed>
     */
    public function reenviar(Company $company, OnlinePaymentTransaction $tx, ?int $plantilla = null): array
    {
        $this->exigirPasarela($company);

        if ($tx->company_id !== $company->id) {
            throw new OnePayError('Ese cobro no es de esta empresa.');
        }

        if ($tx->status === 'approved') {
            throw new OnePayError('Ese cobro ya fue pagado: no se puede volver a mandar.');
        }

        if (!$tx->gateway_transaction_id) {
            throw new OnePayError('Ese cobro no tiene identificador de OnePay: hay que generarlo de nuevo.');
        }

        $api = new OnePayApi($company);
        $api->reenviarCobro((string) $tx->gateway_transaction_id, $this->plantillaValida($company, $plantilla));

        Log::info('[OnePay] Cobro reenviado', ['empresa' => $company->id, 'referencia' => $tx->reference]);

        return [
            'reference'   => $tx->reference,
            'amount'      => (float) $tx->amount,
            'gateway'     => 'onepay',
            'sandbox'     => (bool) $tx->sandbox,
            'por_whatsapp' => true,
        ];
    }

    /**
     * Las plantillas con las que se puede cobrar.
     *
     * @return list<array<string,mixed>>
     */
    public function plantillas(Company $company): array
    {
        $this->exigirPasarela($company);

        $r = (new OnePayApi($company))->plantillas();

        return collect($r['data'] ?? [])
            ->map(fn (array $p) => [
                'id'        => $p['id'] ?? null,
                'nombre'    => $p['name'] ?? '',
                'idioma'    => $p['language'] ?? null,
                'texto'     => $p['body'] ?? '',
                'etapa'     => $p['stage'] ?? null,
                'de_onepay' => (bool) ($p['is_predefined'] ?? false),
            ])
            ->values()
            ->all();
    }

    // ── Validaciones ────────────────────────────────────────────────────────

    /** Que la empresa de verdad pueda cobrar por OnePay antes de intentarlo. */
    private function exigirPasarela(Company $company): void
    {
        if ($company->pg_gateway !== 'onepay') {
            throw new OnePayError('Esta empresa no tiene OnePay como pasarela de pago.');
        }

        if (!$company->pg_active) {
            throw new OnePayError('La pasarela de pago está apagada para esta empresa.');
        }

        if ($problema = (new OnePayApi($company))->problemaDeEntorno()) {
            throw new OnePayError($problema);
        }
    }

    /** El celular al que mandarle el cobro. */
    private function telefonoDelCliente(object $cliente): string
    {
        if ($tel = OnePayGateway::telefonoParaWhatsapp($cliente->phone ?? null)) {
            return $tel;
        }

        throw new OnePayError(
            'El cliente no tiene un celular válido en su ficha («' . ($cliente->phone ?: 'vacío')
            . '»). WhatsApp necesita un número colombiano de diez dígitos que empiece en 3.'
        );
    }

    /**
     * Las facturas que se van a cobrar, ya comprobadas como suyas y vivas.
     *
     * El scope global deja fuera las anuladas, así que una factura anulada
     * sencillamente no aparece; las pagas se filtran por saldo.
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int,DetFacturation>
     */
    private function facturasPorCobrar(Company $company, int $userId, array $ids)
    {
        // La factura no sabe de quién es ni de qué empresa: eso vive en su
        // cabecera. Sin esta unión se le podría cobrar a un cliente una
        // factura de otra empresa, que es el peor error posible acá.
        $q = DetFacturation::join('cab_facturations', 'cab_facturations.id', '=', 'det_facturations.cab_id')
            ->where('cab_facturations.company_id', $company->id)
            ->where('cab_facturations.user_id', $userId)
            ->select('det_facturations.*');

        if ($ids) {
            $q->whereIn('det_facturations.id', $ids);
        }

        $facturas = $q->orderBy('det_facturations.date_facturation')->get();

        if ($ids && $facturas->count() !== count(array_unique($ids))) {
            throw new OnePayError('Alguna de las facturas elegidas no existe, es de otro cliente o está anulada.');
        }

        return $facturas->filter(fn (DetFacturation $f) => $f->outstanding() > 0)->values();
    }

    /**
     * Un cobro pendiente por las mismas facturas, si lo hay.
     *
     * @param  list<int>  $facturaIds
     */
    private function cobroVivo(Company $company, array $facturaIds): ?OnlinePaymentTransaction
    {
        sort($facturaIds);

        return OnlinePaymentTransaction::where('company_id', $company->id)
            ->where('gateway', 'onepay')
            ->where('status', 'pending')
            ->whereNotNull('gateway_transaction_id')
            ->where('created_at', '>=', now()->subDays(3))
            ->orderByDesc('id')
            ->get()
            ->first(function (OnlinePaymentTransaction $t) use ($facturaIds) {
                $suyas = (array) ($t->invoice_ids ?? []);
                sort($suyas);

                return $suyas === $facturaIds;
            });
    }

    /**
     * La plantilla elegida, comprobada contra las que OnePay deja usar.
     *
     * Pedir una que no sea «selectable» es un 422 al crear el cobro, con el
     * agravante de que para entonces ya se gastó el intento. Y si OnePay no
     * contesta el listado no se bloquea el cobro: se manda sin plantilla y él
     * elige la que corresponda al canal.
     */
    private function plantillaValida(Company $company, ?int $plantilla): ?int
    {
        $plantilla ??= $company->pg_template_id ? (int) $company->pg_template_id : null;

        if ($plantilla === null) {
            return null;
        }

        try {
            $usables = collect((new OnePayApi($company))->plantillas()['data'] ?? [])
                ->pluck('id')->map(fn ($i) => (int) $i)->all();
        } catch (OnePayError $e) {
            Log::info('[OnePay] No se pudo comprobar la plantilla; se manda sin ella', ['error' => $e->getMessage()]);

            return null;
        }

        if ($usables && !in_array($plantilla, $usables, true)) {
            throw new OnePayError(
                "La plantilla {$plantilla} no se puede usar para cobrar: tiene que estar aprobada por Meta, ser de categoría PAYMENT y tener un canal de WhatsApp detrás."
            );
        }

        return $plantilla;
    }
}
