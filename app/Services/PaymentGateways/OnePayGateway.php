<?php

namespace App\Services\PaymentGateways;

use App\Models\Company;
use App\Services\PaymentGateways\OnePay\OnePayApi;
use App\Services\PaymentGateways\OnePay\OnePayError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * OnePay: cobros por link y por WhatsApp.
 *
 * Se diferencia de las otras pasarelas en dos cosas que cambian cómo se usa:
 *
 *  1. El monto viaja en unidades enteras, no en centavos. Wompi pide 1400000
 *     para cobrar $14.000; OnePay pide 14000. Confundirlos es cobrar cien
 *     veces de más o de menos, así que el monto pasa por una sola función.
 *
 *  2. Si al cobro se le pone un teléfono y una plantilla, OnePay le manda el
 *     WhatsApp al cliente por su cuenta, con su propio canal aprobado por
 *     Meta. No hay que armar el mensaje ni tener plantilla propia.
 *
 * @see https://docs.onepay.la/client/payments/create
 */
class OnePayGateway implements PaymentGatewayInterface
{
    /** El id que OnePay le dio al cobro recién creado. */
    private ?string $lastGatewayReference = null;

    /** El último cobro completo, para quien quiera el link y el estado juntos. */
    private array $ultimoCobro = [];

    private OnePayApi $api;

    public function __construct(private Company $company)
    {
        $this->api = new OnePayApi($company);
    }

    public function api(): OnePayApi
    {
        return $this->api;
    }

    /**
     * Crea el cobro y devuelve el link donde el cliente paga.
     *
     * @param  array<string,mixed>  $data
     */
    public function generatePaymentLink(array $data): string
    {
        if ($problema = $this->api->problemaDeEntorno()) {
            throw new OnePayError($problema);
        }

        // Sin teléfono ni correo: para OnePay esos campos no son datos del
        // cliente, son «entregáselo vos». Acá el link lo entrega quien pidió
        // el cobro —el bot en su propio chat, el portal en pantalla—, así que
        // si además lo mandara OnePay al cliente le llegarían dos mensajes
        // con dos links distintos y podría pagar dos veces.
        $cobro = $this->armarCobro($data, false);

        // La misma referencia siempre pide el mismo cobro: si la red se corta
        // y se reintenta, OnePay devuelve el que ya creó en vez de crear otro
        // y cobrarle dos veces al cliente.
        $r = $this->api->crearCobro($cobro, 'netvula-' . $data['reference']);

        $this->ultimoCobro          = $r;
        $this->lastGatewayReference = $r['id'] ?? null;

        $link = $r['payment_link'] ?? null;

        if (!$link) {
            throw new OnePayError('OnePay creó el cobro pero no devolvió el link de pago.');
        }

        return $link;
    }

    /**
     * Cobra mandándole el WhatsApp al cliente desde OnePay.
     *
     * Devuelve el cobro entero: el link sirve igual por si hay que repetirlo
     * por otro lado, y el id hace falta para reenviarlo o consultarlo.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function cobrarPorWhatsapp(array $data, ?int $plantilla = null): array
    {
        if ($problema = $this->api->problemaDeEntorno()) {
            throw new OnePayError($problema);
        }

        $telefono = self::telefonoParaWhatsapp($data['customer_phone'] ?? null);

        if (!$telefono) {
            throw new OnePayError(
                'Para cobrar por WhatsApp hace falta el celular del cliente en formato colombiano (10 dígitos que empiezan en 3).'
            );
        }

        // Acá sí entrega OnePay: es lo que se le pidió.
        $cobro = ['phone' => $telefono] + $this->armarCobro($data, true);

        $plantilla ??= $this->company->pg_template_id ? (int) $this->company->pg_template_id : null;

        if ($plantilla) {
            $cobro['template_id'] = $plantilla;
        }

        $r = $this->api->crearCobro($cobro, 'netvula-' . $data['reference']);

        $this->ultimoCobro          = $r;
        $this->lastGatewayReference = $r['id'] ?? null;

        return $r;
    }

    /** @return array<string,mixed> */
    public function ultimoCobro(): array
    {
        return $this->ultimoCobro;
    }

    // ── Webhook ─────────────────────────────────────────────────────────────

    /**
     * Valida el aviso de OnePay: token fijo + firma sobre el cuerpo crudo.
     *
     * Las dos cosas importan. El token dice que el aviso viene de nuestra
     * suscripción; la firma, que el contenido no se tocó en el camino. Y la
     * firma se calcula sobre los bytes tal como llegaron: si se lee el JSON y
     * se vuelve a armar, aunque el contenido sea idéntico la firma no da.
     *
     * @see https://docs.onepay.la/guides/implementar-webhooks
     */
    public function verifyWebhook(Request $request): bool
    {
        $token = trim((string) $this->company->pg_webhook_token);
        $clave = trim((string) $this->company->pg_events_secret);

        if ($clave === '') {
            Log::warning('[OnePay] Webhook sin clave de firma configurada', ['empresa' => $this->company->id]);

            return false;
        }

        // El token es opcional en la configuración: hay cuentas que sólo firman.
        if ($token !== '' && !hash_equals($token, (string) $request->header('x-webhook-token', ''))) {
            Log::warning('[OnePay] Webhook con token equivocado', ['empresa' => $this->company->id, 'ip' => $request->ip()]);

            return false;
        }

        $esperada = hash_hmac('sha256', $request->getContent(), $clave);

        if (!hash_equals($esperada, (string) $request->header('Signature', ''))) {
            Log::warning('[OnePay] Webhook con firma inválida', ['empresa' => $this->company->id, 'ip' => $request->ip()]);

            return false;
        }

        return true;
    }

    public function getInvoiceReference(Request $request): string
    {
        // external_id primero: es el que lleva nuestra referencia entera.
        // «reference» es la que ve el cliente y va recortada a 30 caracteres,
        // así que no sirve para encontrar la transacción.
        return (string) ($request->input('payment.external_id')
            ?? $request->input('charge.external_id')
            ?? $request->input('payment.reference')
            ?? $request->input('charge.reference')
            ?? '');
    }

    public function isApproved(Request $request): bool
    {
        return $this->getTransactionStatus($request) === 'approved';
    }

    /**
     * El monto pagado, en pesos.
     *
     * OnePay ya lo manda en pesos: acá no se divide por cien. Es justo lo
     * contrario de Wompi, y es el error más fácil de cometer en este archivo.
     */
    public function getAmountPaid(Request $request): float
    {
        return (float) ($request->input('payment.amount')
            ?? $request->input('charge.amount')
            ?? 0);
    }

    /**
     * Quita del nombre lo que identifica a UN instrumento concreto.
     *
     * «Mastercard ·3222» es un dato del movimiento, no una forma de pago. Si
     * se deja, el catálogo de métodos crece una fila por cada tarjeta que
     * pase por la plataforma.
     */
    public static function sinInstrumento(string $nombre): string
    {
        // Los últimos dígitos, con punto medio o con asteriscos.
        $limpio = preg_replace('/\s*[·*•]+\s*\d{2,6}\s*$/u', '', $nombre);
        $limpio = preg_replace('/\s*\*{2,}\s*\d{2,6}\s*$/', '', (string) $limpio);

        return trim((string) $limpio) ?: $nombre;
    }

    /** approved | declined | cancelled | failed | pending */
    public function getTransactionStatus(Request $request): string
    {
        $evento = (string) $request->input('event.type', '');
        $estado = (string) ($request->input('payment.status') ?? $request->input('charge.status') ?? '');

        // El tipo de evento manda: es lo que OnePay afirma que pasó. El estado
        // del recurso se usa cuando el evento no dice nada concluyente.
        return match ($evento) {
            'payment.approved', 'charge.paid'   => 'approved',
            'payment.rejected', 'charge.failed' => 'declined',
            'payment.deleted'                   => 'cancelled',
            'payment.expired'                   => 'failed',
            'payment.created'                   => 'pending',
            default                             => self::normalizar($estado),
        };
    }

    /**
     * Con qué pagó el cliente: el medio y, si lo hay, el banco.
     *
     * El aviso de OnePay no siempre trae «payment.method» —con tarjeta a veces
     * sí, con los medios de prueba no viene nunca—, así que cuando falta se le
     * pregunta a la API por ese cobro. Sin esto el movimiento queda «sin
     * método» en el historial y nadie sabe cómo pagó el cliente.
     *
     * Devuelve tres cosas: el medio para el catálogo, el banco cuando lo hay,
     * y el detalle que identifica el instrumento («Mastercard ·3222»). El
     * detalle NO va al catálogo: si fuera, habría un método de pago por cada
     * tarjeta que use cualquier cliente y la lista sería impresentable a los
     * pocos meses. Va en la nota del movimiento, que es donde sirve.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} medio, banco, detalle
     */
    public function medioYBanco(Request $request): array
    {
        $metodo = $request->input('payment.method') ?? $request->input('charge.method');

        if (!is_array($metodo)) {
            $id = $request->input('payment.id') ?? $request->input('charge.id');

            // Los dos sueltos: en algunos avisos vienen así, sin el objeto.
            $suelto = $request->input('payment.payment_method_label')
                ?? $request->input('payment.payment_method_type');

            if (!$suelto && $id) {
                try {
                    $metodo = $this->api->verCobro((string) $id)['method'] ?? null;
                } catch (\Throwable $e) {
                    Log::info('[OnePay] No se pudo leer el medio de pago', ['cobro' => $id, 'error' => $e->getMessage()]);
                }
            } elseif ($suelto) {
                return [self::sinInstrumento((string) $suelto), null, (string) $suelto];
            }
        }

        // Ni en el aviso ni en el cobro: entonces en los intentos de pago,
        // que es donde queda el medio con el que se pagó de verdad.
        if (!is_array($metodo)) {
            $id = $request->input('payment.id') ?? $request->input('charge.id');

            if ($id) {
                try {
                    $intentos = collect($this->api->intentosDeCobro((string) $id)['data'] ?? []);
                    $pagado = $intentos->first(fn ($i) => in_array(($i['status'] ?? ''), ['paid', 'approved', 'succeeded'], true))
                        ?? $intentos->last();

                    if ($pagado) {
                        $etiqueta = $pagado['payment_method_label'] ?? $pagado['payment_method_type'] ?? null;

                        return [
                            $etiqueta ? self::sinInstrumento((string) $etiqueta) : null,
                            null,
                            $etiqueta ? (string) $etiqueta : null,
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::info('[OnePay] No se pudieron leer los intentos de pago', ['cobro' => $id, 'error' => $e->getMessage()]);
                }
            }

            $suelto = is_string($metodo) && $metodo !== '' ? $metodo : null;

            return [$suelto ? self::sinInstrumento($suelto) : null, null, $suelto];
        }

        // «label» es lo que el cliente reconoce («Mastercard ·3222»); para una
        // cuenta o PSE, el nombre del banco.
        // La marca manda para el catálogo: «Mastercard», «PSE», «Nequi».
        $medio = $metodo['brand'] ?? $metodo['type'] ?? $metodo['name'] ?? $metodo['label'] ?? null;
        // El banco sólo para cuentas y PSE. En una tarjeta repetiría la marca.
        $banco = $metodo['bank_name'] ?? $metodo['issuer'] ?? null;
        // Y el detalle, para la nota: la etiqueta completa con los últimos
        // dígitos, que es lo que el cliente reconoce en su extracto.
        $detalle = $metodo['label'] ?? null;

        if (!$detalle && ($medio || !empty($metodo['last_four']))) {
            $detalle = trim(((string) $medio) . (!empty($metodo['last_four']) ? ' ·' . $metodo['last_four'] : ''));
        }

        return [
            $medio ? self::sinInstrumento((string) $medio) : null,
            $banco ? (string) $banco : null,
            $detalle ?: null,
        ];
    }

    public function getLastGatewayReference(): ?string
    {
        return $this->lastGatewayReference;
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    /** Los estados de OnePay pasados a los nuestros. */
    public static function normalizar(string $estado): string
    {
        return match (strtolower($estado)) {
            'approved', 'succeeded', 'paid' => 'approved',
            'declined', 'rejected'          => 'declined',
            'cancelled', 'canceled'         => 'cancelled',
            'expired', 'partial_expired'    => 'failed',
            default                         => 'pending',
        };
    }

    /**
     * El celular en el formato que pide OnePay, o null si no sirve.
     *
     * Un número mal formado no da error al crear el cobro: OnePay lo acepta y
     * el WhatsApp no le llega a nadie. Por eso se revisa acá y se avisa, en
     * vez de dar por bueno un cobro que el cliente nunca va a ver.
     */
    public static function telefonoParaWhatsapp(?string $crudo): ?string
    {
        $solo = preg_replace('/\D+/', '', (string) $crudo);

        if ($solo === '') {
            return null;
        }

        // Con o sin el 57 adelante, y sin el 0 de larga distancia.
        $solo = preg_replace('/^0+/', '', $solo);

        if (strlen($solo) === 12 && str_starts_with($solo, '57')) {
            $solo = substr($solo, 2);
        }

        // Celular colombiano: diez dígitos que empiezan en 3.
        if (!preg_match('/^3\d{9}$/', $solo)) {
            return null;
        }

        return '+57' . $solo;
    }

    /**
     * La referencia corta que ve el cliente, dentro de los 30 caracteres.
     *
     * Se arma del concepto («Factura #1234» → «Factura-1234») porque es lo que
     * el cliente reconoce en su extracto. Si no queda nada legible, se usa la
     * cola de la nuestra, que es única por la marca de tiempo.
     */
    public static function referenciaVisible(string $nuestra, string $concepto): string
    {
        $limpio = trim((string) preg_replace('/-+/', '-', preg_replace('/[^A-Za-z0-9]+/', '-', \Illuminate\Support\Str::ascii($concepto))), '-');

        if (strlen($limpio) >= 4 && strlen($limpio) <= 30) {
            return $limpio;
        }

        if (strlen($limpio) > 30) {
            return substr($limpio, 0, 30);
        }

        return substr($nuestra, -30);
    }

    /**
     * El cuerpo del cobro, con todo lo que OnePay sabe aprovechar.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function armarCobro(array $data, bool $entrega): array
    {
        $monto = round((float) ($data['amount'] ?? 0), 2);

        if ($monto <= 0) {
            throw new OnePayError('El monto del cobro tiene que ser mayor que cero.');
        }

        $nuestra = (string) $data['reference'];

        $cobro = [
            // En unidades enteras: OnePay NO usa centavos.
            'amount'       => $monto,
            'currency'     => 'COP',
            'title'        => mb_substr((string) ($data['description'] ?? 'Pago de servicio'), 0, 60),
            // OnePay admite 30 caracteres acá y la nuestra es más larga
            // (empresa + factura + marca de tiempo + id). Ésta es la que ve el
            // cliente, así que se le pone algo legible; la que manda para
            // reconocer el pago es external_id, que va entera.
            'reference'    => self::referenciaVisible($nuestra, (string) ($data['description'] ?? '')),
            'external_id'  => $nuestra,
            'redirect_url' => (string) ($data['redirect_url'] ?? ''),
            // El impuesto lo lleva la factura, no el cobro: sin esto OnePay
            // asume 19 % y el cliente ve un desglose que no es el suyo.
            'tax'          => 0,
        ];

        // Sólo cuando la entrega es de OnePay. Ver el comentario de arriba:
        // estos dos campos son canales de envío, no datos del cliente.
        if ($entrega) {
            if (!empty($data['customer_email']) && filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
                $cobro['email'] = $data['customer_email'];
            }

            if ($tel = self::telefonoParaWhatsapp($data['customer_phone'] ?? null)) {
                $cobro['phone'] = $tel;
            }
        }

        // La fecha de corte del servicio: OnePay la usa para sus recordatorios
        // y para mostrarle al cliente hasta cuándo tiene. No bloquea el pago
        // —eso sería expiration_date—, porque una factura vencida se sigue
        // pudiendo pagar y de hecho es la que más urge cobrar.
        if (!empty($data['limit_date'])) {
            try {
                $cobro['due_date'] = \Carbon\Carbon::parse($data['limit_date'])->toDateString();
            } catch (\Throwable) {
                // Una fecha ilegible no puede tumbar el cobro.
            }
        }

        // Para reconocer el pago cuando vuelve por el aviso, y para que quede
        // rastro de quién lo generó. OnePay los quiere como lista, no objeto.
        $cobro['metadata'] = [
            ['key' => 'netvula_empresa',    'value' => (string) $this->company->id],
            ['key' => 'netvula_referencia', 'value' => $nuestra],
        ];

        return $cobro;
    }
}
