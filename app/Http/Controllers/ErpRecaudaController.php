<?php

namespace App\Http\Controllers;

use App\Models\CabFacturation;
use App\Models\Company;
use App\Models\DetFacturation;
use App\Models\OnlinePaymentTransaction;
use App\Models\UserData;
use App\Services\PaymentGateways\EfiPayGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Cobro ERP de EfiPay: ellos preguntan, nosotros contestamos qué debe el cliente.
 *
 * Invierte quién atiende al cliente. En el flujo normal el cliente escribe la
 * cédula en nuestro bot o en el portal y nosotros armamos el checkout; aquí la
 * escribe en un canal de EfiPay y EfiPay nos consulta a nosotros. Devolvemos una
 * "recauda" por factura pendiente y EfiPay las cobra.
 *
 * El pago vuelve por el webhook de siempre (/api/webhooks/efipay/{slug}), porque
 * nosotros mismos le indicamos a EfiPay a dónde notificar y con qué referencia.
 * Por eso no hay una segunda ruta de acreditación: es la misma que ya está en
 * producción.
 *
 * Seguridad: la documentación de EfiPay no contempla ninguna autenticación para
 * esta URL, y con sola la cédula se sabría cuánto debe alguien. Como somos
 * nosotros quienes le entregamos la URL, el secreto va dentro de la propia ruta;
 * sin el token la ruta no existe.
 *
 * @see https://efipay.co/docs/1.0/DocumentationErp
 */
class ErpRecaudaController extends Controller
{
    /** Tope de facturas por consulta: EfiPay las pinta todas en una lista. */
    private const MAX_RECAUDAS = 10;

    /** Días que EfiPay mantiene cobrable cada recauda. */
    private const DIAS_VIGENCIA = 30;

    /** Marca en la referencia que el cobro nació del ERP y no de un link nuestro. */
    private const TAG = 'ERP';

    public function recaudas(Request $request, string $companySlug, string $token): JsonResponse
    {
        $company = Company::where('slug', $companySlug)
            ->where('pg_gateway', 'efipay')
            ->where('pg_active', true)
            ->first();

        // Un token errado y una empresa inexistente responden igual, para que
        // nadie pueda averiguar qué slugs existen probando la URL.
        if (!$company || !hash_equals(self::tokenFor($company), $token)) {
            Log::warning('Cobro ERP: consulta rechazada', [
                'slug' => $companySlug,
                'ip'   => $request->ip(),
            ]);

            return $this->noEncontrado();
        }

        $idNumber = preg_replace('/\D+/', '', (string) $request->input('id_number', ''));

        if (strlen($idNumber) < 5 || strlen($idNumber) > 20) {
            return $this->noEncontrado();
        }

        $cliente = UserData::where('company_id', $company->id)
            ->where('dni', $idNumber)
            ->first();

        if (!$cliente) {
            Log::info('Cobro ERP: cédula sin cliente', [
                'company_id' => $company->id,
                'id_number'  => $idNumber,
            ]);

            return $this->noEncontrado();
        }

        $facturas = $this->facturasPendientes($company, (int) $cliente->user_id);

        if ($facturas->isEmpty()) {
            Log::info('Cobro ERP: cliente al día', [
                'company_id' => $company->id,
                'id_number'  => $idNumber,
            ]);

            return $this->noEncontrado();
        }

        $webhook = (new EfiPayGateway($company))->webhookUrl();
        $limite  = now()->addDays(self::DIAS_VIGENCIA)->toDateString();
        $pagador = $this->nombrePagador($cliente, $idNumber);

        $payments = [];

        foreach ($facturas as $factura) {
            $saldo = $this->saldo($factura);
            $tx    = $this->transaccionPara($company, $factura, $saldo, $pagador, $cliente->email);

            $payments[] = [
                'recauda_information' => [
                    'description'     => $this->descripcion($factura, $company),
                    'amount'          => $saldo,
                    'currency_type'   => 'COP',
                    'id_number'       => $idNumber,
                    'payer_name'      => $pagador,
                    'ref_payment'     => $tx->reference,
                    'expiration_date' => $limite,
                    'metadata'        => [
                        'invoice_id'     => (int) $factura->id,
                        'invoice_number' => (string) $factura->number_facture,
                        'company_id'     => (int) $company->id,
                    ],
                ],
                'advanced_options' => [
                    // EfiPay nos devuelve estas referencias en el webhook; es lo
                    // que permite emparejar el pago con la factura.
                    'references'   => [$tx->reference],
                    'result_urls'  => ['webhook' => $webhook],
                    'limit_date'   => $limite,
                    'has_comments' => false,
                ],
            ];
        }

        Log::info('Cobro ERP: recaudas entregadas', [
            'company_id' => $company->id,
            'id_number'  => $idNumber,
            'facturas'   => count($payments),
            'total'      => array_sum(array_column(array_column($payments, 'recauda_information'), 'amount')),
        ]);

        return response()->json(['payments' => $payments]);
    }

    /**
     * URL que se pega en «Configurar cobro ERP» de EfiPay.
     * Vive aquí, junto al token, para que no puedan quedar desincronizados.
     */
    public static function urlFor(Company $company): string
    {
        return url('/api/erp/efipay/' . $company->slug . '/' . self::tokenFor($company));
    }

    /**
     * Token derivado, no almacenado: no hace falta migración ni hay un secreto
     * más que guardar. Cambia solo si cambia APP_KEY, que es justo lo que se
     * querría para revocarlo.
     */
    public static function tokenFor(Company $company): string
    {
        $material = 'erp-recauda:' . $company->id . ':' . $company->slug;

        return substr(hash_hmac('sha256', $material, (string) config('app.key')), 0, 40);
    }

    // ─── Interno ─────────────────────────────────────────────────────────────

    /**
     * EfiPay espera 404 cuando no hay nada que cobrar. Se responde lo mismo ante
     * un token inválido, una cédula ajena o un cliente al día: quien consulte a
     * ciegas no distingue ningún caso del otro.
     */
    private function noEncontrado(): JsonResponse
    {
        return response()->json(['payments' => []], 404);
    }

    /** Facturas con saldo, de la más antigua a la más nueva. */
    private function facturasPendientes(Company $company, int $clientUserId)
    {
        $cabIds = CabFacturation::where('company_id', $company->id)
            ->where('user_id', $clientUserId)
            ->pluck('id');

        if ($cabIds->isEmpty()) {
            return collect();
        }

        return DetFacturation::whereIn('cab_id', $cabIds)
            ->where('paid', 0)
            ->orderBy('date_facturation')
            ->orderBy('id')
            ->get()
            ->filter(fn ($factura) => $this->saldo($factura) > 0)
            ->take(self::MAX_RECAUDAS)
            ->values();
    }

    /** Lo que falta por pagar, descontando descuentos y abonos. */
    private function saldo(object $factura): float
    {
        return round(max(0, (float) $factura->price_total
            - (float) ($factura->price_discount ?? 0)
            - (float) ($factura->price_abone ?? 0)), 2);
    }

    /**
     * Transacción contra la que se acreditará el pago.
     *
     * El webhook exige que la referencia ya exista en `online_payment_transactions`
     * (PaymentGatewayController::processWebhook), y en cobro ERP nadie la creó
     * antes: el cliente puede consultar hoy y pagar dentro de tres días. Por eso
     * la creamos al momento de responder.
     *
     * Se reutiliza la que ya esté pendiente por el mismo valor, para no sembrar
     * una fila nueva cada vez que el cliente consulta. Si el saldo cambió (entró
     * un abono), se abre una referencia nueva: EfiPay exige `ref_payment` único y
     * reciclarla con otro valor sería pedirle que cambie algo ya registrado.
     */
    private function transaccionPara(
        Company $company,
        object $factura,
        float $saldo,
        string $pagador,
        ?string $email
    ): OnlinePaymentTransaction {
        $vigente = OnlinePaymentTransaction::where('company_id', $company->id)
            ->where('det_facturation_id', $factura->id)
            ->where('status', 'pending')
            ->where('reference', 'like', $company->slug . '-' . self::TAG . '-%')
            ->orderByDesc('id')
            ->first();

        if ($vigente && abs((float) $vigente->amount - $saldo) < 0.01) {
            return $vigente;
        }

        return OnlinePaymentTransaction::create([
            'company_id'         => $company->id,
            'det_facturation_id' => $factura->id,
            'invoice_ids'        => [(int) $factura->id],
            'reference'          => $company->slug . '-' . self::TAG . '-' . time() . '-' . $factura->id,
            'gateway'            => 'efipay',
            'sandbox'            => (bool) $company->pg_sandbox,
            'amount'             => $saldo,
            'status'             => 'pending',
            'customer_name'      => $pagador,
            'customer_email'     => (string) ($email ?? ''),
            'initiated_at'       => now(),
        ]);
    }

    /** EfiPay exige entre 5 y 191 caracteres. */
    private function nombrePagador(UserData $cliente, string $idNumber): string
    {
        $nombre = trim(($cliente->names ?? '') . ' ' . ($cliente->lastname ?? ''));

        if (mb_strlen($nombre) < 5) {
            $nombre = 'Cliente ' . $idNumber;
        }

        return mb_substr($nombre, 0, 191);
    }

    /** Lo que el cliente lee en la pantalla de EfiPay. EfiPay exige 4-191. */
    private function descripcion(object $factura, Company $company): string
    {
        $texto = 'Factura ' . $factura->number_facture;

        if (!empty($factura->date_facturation)) {
            $texto .= ' - ' . $factura->date_facturation;
        }

        return mb_substr($texto . ' - ' . $company->name, 0, 191);
    }
}
