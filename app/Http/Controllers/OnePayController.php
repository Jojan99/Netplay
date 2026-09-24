<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\OnlinePaymentTransaction;
use App\Services\PaymentGateways\OnePay\CobroPorWhatsapp;
use App\Services\PaymentGateways\OnePay\OnePayApi;
use App\Services\PaymentGateways\OnePay\OnePayError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Cobrar por WhatsApp con OnePay, desde la ficha del cliente y desde cartera.
 *
 * Todo lo que puede fallar devuelve error 0 con el motivo escrito para que se
 * pueda leer en pantalla: «el cliente no tiene celular», «esa plantilla no
 * sirve», «la llave es de pruebas y la empresa está en producción». Un
 * «no se pudo» a secas obliga a venir a mirar los registros, y eso no escala.
 */
class OnePayController extends Controller
{
    public function __construct(private CobroPorWhatsapp $cobros) {}

    /** Las plantillas de WhatsApp con las que se puede cobrar. */
    public function plantillas(): JsonResponse
    {
        return $this->responder(fn (Company $c) => $this->cobros->plantillas($c));
    }

    /** Manda el cobro al WhatsApp del cliente. */
    public function cobrar(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'     => 'required|integer|exists:users,id',
            'facturas'    => 'nullable|array',
            'facturas.*'  => 'integer',
            'template_id' => 'nullable|integer',
            // Cobrar menos de lo que debe: un abono acordado. Más, nunca.
            'monto'       => 'nullable|numeric|min:1',
        ]);

        return $this->responder(fn (Company $c) => $this->cobros->enviar(
            $c,
            (int) $request->input('user_id'),
            array_map('intval', (array) $request->input('facturas', [])),
            $request->filled('template_id') ? (int) $request->input('template_id') : null,
            $request->filled('monto') ? (float) $request->input('monto') : null,
        ));
    }

    /** Vuelve a mandar un cobro que ya existe. */
    public function reenviar(Request $request, int $txId): JsonResponse
    {
        $request->validate(['template_id' => 'nullable|integer']);

        return $this->responder(function (Company $c) use ($request, $txId) {
            $tx = OnlinePaymentTransaction::where('company_id', $c->id)->findOrFail($txId);

            return $this->cobros->reenviar(
                $c,
                $tx,
                $request->filled('template_id') ? (int) $request->input('template_id') : null,
            );
        });
    }

    /**
     * El estado del cobro preguntándole a OnePay.
     *
     * Sirve cuando el aviso no llegó: OnePay reintenta unos nueve minutos y
     * después deja de insistir. Sin esto un pago hecho podía quedar figurando
     * como pendiente para siempre.
     */
    public function estado(int $txId): JsonResponse
    {
        return $this->responder(function (Company $c) use ($txId) {
            $tx = OnlinePaymentTransaction::where('company_id', $c->id)->findOrFail($txId);

            if (!$tx->gateway_transaction_id) {
                throw new OnePayError('Ese cobro no tiene identificador de OnePay.');
            }

            $cobro = (new OnePayApi($c))->verCobro((string) $tx->gateway_transaction_id);

            return [
                'referencia' => $tx->reference,
                'estado'     => \App\Services\PaymentGateways\OnePayGateway::normalizar((string) ($cobro['status'] ?? '')),
                'estado_onepay' => $cobro['status'] ?? null,
                'monto'      => $cobro['amount'] ?? null,
                'pagado_en'  => $cobro['paid_at'] ?? null,
                'link'       => $cobro['payment_link'] ?? null,
                'es_prueba'  => $cobro['is_test'] ?? null,
            ];
        });
    }

    /**
     * Revisa que la configuración sirva, sin cobrarle a nadie.
     *
     * Pide el listado de plantillas: es la llamada más barata que igual exige
     * una llave buena y la cuenta verificada.
     */
    public function diagnostico(): JsonResponse
    {
        return $this->responder(function (Company $c) {
            $api  = new OnePayApi($c);
            $malo = $api->problemaDeEntorno();

            $revisiones = [
                ['que' => 'Pasarela elegida', 'ok' => $c->pg_gateway === 'onepay',
                 'detalle' => $c->pg_gateway === 'onepay' ? 'OnePay' : "Está en «{$c->pg_gateway}»"],
                ['que' => 'Pasarela encendida', 'ok' => (bool) $c->pg_active,
                 'detalle' => $c->pg_active ? 'Sí' : 'Apagada: no se puede cobrar'],
                ['que' => 'Llave y entorno', 'ok' => $malo === null,
                 'detalle' => $malo ?? ($c->pg_sandbox ? 'Llave de pruebas, empresa en pruebas' : 'Llave de producción, empresa en producción')],
                ['que' => 'Firma del aviso', 'ok' => (bool) trim((string) $c->pg_events_secret),
                 'detalle' => trim((string) $c->pg_events_secret) ? 'Cargada' : 'Falta: los avisos de pago se van a rechazar'],
                ['que' => 'Token del aviso', 'ok' => true,
                 'detalle' => trim((string) $c->pg_webhook_token) ? 'Cargado' : 'Sin token: se valida sólo con la firma'],
            ];

            try {
                $plantillas = $this->cobros->plantillas($c);
                $revisiones[] = ['que' => 'Plantillas de WhatsApp', 'ok' => count($plantillas) > 0,
                    'detalle' => count($plantillas)
                        ? count($plantillas) . ' disponibles para cobrar'
                        : 'Ninguna: hay que aprobar una plantilla de categoría PAYMENT en Meta'];
            } catch (\Throwable $e) {
                $revisiones[] = ['que' => 'Plantillas de WhatsApp', 'ok' => false, 'detalle' => $e->getMessage()];
            }

            $revisiones[] = ['que' => 'URL para los avisos', 'ok' => true,
                'detalle' => url('/api/webhooks/onepay/' . $c->slug)];

            return [
                'todo_bien'  => !collect($revisiones)->contains(fn ($r) => !$r['ok']),
                'revisiones' => $revisiones,
            ];
        });
    }

    // ── Interno ─────────────────────────────────────────────────────────────

    private function responder(callable $fn): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $company = Company::find($companyId);

        if (!$company) {
            return standardApiReponse('Empresa no encontrada', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            return standardApiReponse('OK', $fn($company), 0, JsonResponse::HTTP_OK);
        } catch (OnePayError $e) {
            // Rechazo con motivo: es información para el operador, no una falla.
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return standardApiReponse('Ese cobro no existe o no es de esta empresa', null, 1, JsonResponse::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            Log::error('[OnePay] ' . $e->getMessage(), ['empresa' => $companyId]);

            return standardApiReponse('No se pudo completar la operación con OnePay', null, 1, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
