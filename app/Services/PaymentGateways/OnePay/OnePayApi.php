<?php

namespace App\Services\PaymentGateways\OnePay;

use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El cliente HTTP de OnePay.
 *
 * OnePay usa un solo dominio para pruebas y para producción: lo que separa un
 * entorno del otro es la llave. Una llave «sk_test_…» toca la base de pruebas
 * y una de producción toca la real, con la misma URL. Eso es cómodo y es
 * peligroso a la vez, así que acá se revisa que la llave concuerde con el
 * interruptor de sandbox de la empresa: cobrarle de verdad a un cliente
 * creyendo que estabas probando es el error que no se puede permitir.
 *
 * @see https://docs.onepay.la/client/base/autentication
 */
class OnePayApi
{
    public const BASE = 'https://api.onepay.la/v1';

    /** Lo que tarda en darse por vencida una llamada normal. */
    private const ESPERA = 20;

    public function __construct(private Company $company) {}

    // ── Cobros ──────────────────────────────────────────────────────────────

    /**
     * Crea un cobro. Si lleva teléfono y plantilla, OnePay manda el WhatsApp.
     *
     * @param  array<string,mixed>  $cobro
     * @return array<string,mixed>
     */
    public function crearCobro(array $cobro, string $idempotencia): array
    {
        return $this->llamar('post', '/payments', $cobro, $idempotencia);
    }

    /** @return array<string,mixed> */
    public function verCobro(string $id): array
    {
        return $this->llamar('get', "/payments/{$id}");
    }

    /**
     * Vuelve a mandarle el cobro al cliente por WhatsApp.
     *
     * OnePay contesta 422 si el cobro ya está pagado: eso no es una falla
     * nuestra, es la respuesta correcta, y se deja pasar como tal.
     *
     * @return array<string,mixed>
     */
    public function reenviarCobro(string $id, ?int $plantilla = null): array
    {
        return $this->llamar('post', "/payments/{$id}", array_filter([
            'template_id' => $plantilla,
        ]), Str::uuid()->toString());
    }

    /**
     * Los intentos de pago de un cobro.
     *
     * Acá vive el medio con el que se pagó de verdad
     * («payment_method_label»). El cobro en sí lo trae a veces y a veces no,
     * así que ésta es la fuente que no falla.
     *
     * @return array<string,mixed>
     */
    public function intentosDeCobro(string $id): array
    {
        return $this->llamar('get', "/payments/{$id}/intents");
    }

    /** @return array<string,mixed> */
    public function anularCobro(string $id): array
    {
        return $this->llamar('delete', "/payments/{$id}");
    }

    // ── Plantillas de WhatsApp ──────────────────────────────────────────────

    /**
     * Las plantillas que se pueden usar para cobrar.
     *
     * Sólo las «selectable»: aprobadas por Meta, de categoría PAYMENT y con un
     * canal de WhatsApp detrás. Pedir una que no lo sea es un 422 seguro.
     *
     * @return array<string,mixed>
     */
    public function plantillas(bool $soloUsables = true): array
    {
        return $this->llamar('get', '/templates', array_filter([
            'filter' => array_filter([
                'selectable' => $soloUsables ? 1 : null,
                'status'     => $soloUsables ? 'APPROVED' : null,
            ]),
            'per_page' => 100,
        ]));
    }

    // ── Clientes ────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    public function crearCliente(array $datos): array
    {
        return $this->llamar('post', '/customers', $datos, Str::uuid()->toString());
    }

    /** @return array<string,mixed> */
    public function clientes(array $filtros = []): array
    {
        return $this->llamar('get', '/customers', $filtros);
    }

    // ── Interno ─────────────────────────────────────────────────────────────

    /** La llave privada de la empresa, ya descifrada. */
    private function llave(): string
    {
        $llave = trim((string) $this->company->pg_private_key);

        if ($llave === '') {
            throw new OnePayError('La empresa no tiene cargada la llave privada de OnePay.');
        }

        return $llave;
    }

    /**
     * ¿La llave concuerda con el entorno configurado?
     *
     * Ya pasó con Wompi: la empresa quedó en producción con llaves de prueba y
     * ningún pago en línea funcionaba, sin un solo error que lo dijera. Acá se
     * revisa antes de hacer nada.
     */
    public function problemaDeEntorno(): ?string
    {
        $llave = trim((string) $this->company->pg_private_key);

        if ($llave === '') {
            return 'Falta la llave privada de OnePay (Perfil → Desarrolladores → API Keys).';
        }

        $esDePrueba = str_starts_with($llave, 'sk_test_');
        $enSandbox  = (bool) $this->company->pg_sandbox;

        if ($esDePrueba && !$enSandbox) {
            return 'La empresa está en producción pero la llave de OnePay es de pruebas (sk_test_…): ningún cobro se haría de verdad.';
        }

        if (!$esDePrueba && $enSandbox) {
            return 'La empresa está en modo prueba pero la llave de OnePay es de producción: se le cobraría de verdad a los clientes.';
        }

        return null;
    }

    /**
     * Una llamada a la API, con los errores traducidos a algo que se entienda.
     *
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private function llamar(string $metodo, string $ruta, array $datos = [], ?string $idempotencia = null): array
    {
        $cabeceras = [
            'Authorization' => 'Bearer ' . $this->llave(),
            'Accept'        => 'application/json',
        ];

        // La idempotencia es lo que evita cobrar dos veces cuando la red se
        // corta a mitad de camino y se reintenta: OnePay devuelve el mismo
        // cobro en vez de crear otro.
        if ($idempotencia !== null) {
            $cabeceras['x-idempotency'] = $idempotencia;
        }

        try {
            $r = Http::withHeaders($cabeceras)
                ->timeout(self::ESPERA)
                ->{$metodo}(self::BASE . $ruta, $datos);
        } catch (ConnectionException $e) {
            throw new OnePayError('OnePay no contestó a tiempo. El cobro puede haberse creado igual: revisá antes de volver a mandarlo.', 0, $e);
        }

        if ($r->successful()) {
            return is_array($r->json()) ? $r->json() : [];
        }

        $cuerpo = is_array($r->json()) ? $r->json() : [];
        $texto  = self::mensajeDelError($cuerpo, $r->status());

        Log::warning('[OnePay] Llamada rechazada', [
            'empresa' => $this->company->id,
            'ruta'    => $metodo . ' ' . $ruta,
            'estado'  => $r->status(),
            'cuerpo'  => mb_substr($r->body(), 0, 500),
        ]);

        throw new OnePayError($texto, $r->status());
    }

    /**
     * El error de OnePay dicho en castellano.
     *
     * OnePay devuelve un código propio («INSUFFICIENT_FUNDS») y a veces ya
     * trae el texto para el cliente. Se prefiere el suyo; si no vino, se busca
     * en la tabla, y si tampoco está, se explica el código HTTP, que al menos
     * dice de quién es el problema.
     *
     * @param  array<string,mixed>  $cuerpo
     */
    public static function mensajeDelError(array $cuerpo, int $estado): string
    {
        if (!empty($cuerpo['message']) && is_string($cuerpo['message'])) {
            return $cuerpo['message'];
        }

        $codigo = $cuerpo['error']['code'] ?? $cuerpo['code'] ?? null;

        if (is_string($codigo) && isset(self::ERRORES[$codigo])) {
            return self::ERRORES[$codigo];
        }

        // Los de validación traen el detalle campo por campo: sirve más que
        // «datos inválidos» a secas.
        if ($estado === 422 && !empty($cuerpo['errors']) && is_array($cuerpo['errors'])) {
            $partes = [];

            foreach ($cuerpo['errors'] as $campo => $lista) {
                $partes[] = $campo . ': ' . (is_array($lista) ? implode(' ', $lista) : (string) $lista);
            }

            return 'OnePay rechazó los datos — ' . implode(' · ', $partes);
        }

        return match (true) {
            $estado === 401 => 'OnePay no aceptó la llave: revisá la llave privada en la configuración de la pasarela.',
            $estado === 403 => 'La cuenta de OnePay no tiene habilitada esta operación. Puede faltar la verificación de la empresa.',
            $estado === 404 => 'OnePay no encontró ese cobro.',
            $estado === 429 => 'OnePay está recibiendo demasiadas peticiones nuestras. Esperá un momento y reintentá.',
            $estado >= 500  => 'OnePay tuvo un problema de su lado. El cobro no se creó; se puede reintentar.',
            default         => "OnePay rechazó la llamada ({$estado}).",
        };
    }

    /**
     * Los códigos de error de OnePay, tal como los publica.
     *
     * @see https://docs.onepay.la/client/base/errors/codes
     */
    public const ERRORES = [
        'INTERNAL_ERROR'                      => 'Error interno de OnePay. Hay que escribirle a su soporte.',
        'CARD_CVV'                            => 'El código de seguridad de la tarjeta no es correcto.',
        'INSUFFICIENT_FUNDS'                  => 'Fondos insuficientes.',
        'CARD_NUMBER'                         => 'El número de la tarjeta no es válido.',
        'FRAUDULENT'                          => 'El banco marcó la transacción como fraudulenta.',
        'STOLEN'                              => 'La tarjeta está reportada. El cliente tiene que hablar con su banco.',
        'CURRENCY_NOT_SUPPORTED'              => 'Moneda no admitida.',
        'CARD_VELOCITY'                       => 'Se pasó de la cantidad de intentos permitidos.',
        'CARD_DATE'                           => 'La fecha de vencimiento de la tarjeta no es válida.',
        'TRANSACTION_NOT_FOUND'               => 'OnePay no encuentra esa transacción.',
        'MIN_AMOUNT'                          => 'El monto es menor al mínimo que acepta OnePay.',
        'ACCOUNT_NOT_CONNECTED'               => 'El cliente desconectó la cuenta: tiene que volver a conectarla.',
        'ACCOUNT_BLOCKED'                     => 'La cuenta del cliente está bloqueada.',
        'ACCOUNT_CLOSED'                      => 'La cuenta del cliente está cerrada.',
        'ACCOUNT_DOES_NOT_BELONG_TO_CUSTOMER' => 'La cuenta no corresponde al documento del cliente.',
        'TRANSACTION_EXPIRED'                 => 'El cliente no alcanzó a completar el pago en el tiempo permitido.',
        'CARD_GENERIC_ERROR'                  => 'Error al procesar la tarjeta.',
        'TRANSACTION_REJECTED'                => 'El banco no aceptó la transacción.',
        'UNKNOWN'                             => 'Error desconocido de OnePay.',
        'BANK_IS_NOT_AVAILABLE'               => 'El banco del cliente no está disponible en este momento.',
        'CARD_RESTRICTED'                     => 'La tarjeta está restringida: el cliente tiene que hablar con su banco.',
        'CARD_EXPIRED'                        => 'La tarjeta está vencida.',
        'ACCOUNT_IS_NOT_AUTHORIZED'           => 'La cuenta no está autorizada o el cliente no aceptó la suscripción.',
        'TRANSACTION_TEMPORALLY_BLOCKED'      => 'Transacción bloqueada por un rato.',
        'ACCOUNT_FAILED'                      => 'Esa cuenta no fue aceptada. Que pruebe con otra.',
        'CUSTOMER_DATA_INVALID'               => 'Los datos del cliente no son válidos.',
        'MAX_AMOUNT'                          => 'El monto supera el máximo permitido.',
        'DONT_HONOR'                          => 'El banco la rechazó sin dar motivo. El cliente tiene que llamarlo.',
        'COMPANY_TRANSACTION_LIMIT_COUNT'     => 'Se alcanzó el tope de transacciones de la cuenta de OnePay.',
        'COMPANY_TRANSACTION_LIMIT_AMOUNT'    => 'Se alcanzó el tope de monto de la cuenta de OnePay.',
        'CUSTOMER_WALLET_NOT_FOUND'           => 'No se encontró la billetera del cliente.',
        'RISK_CONTROL'                        => 'El banco la bloqueó por control de riesgo.',
        'ACCOUNT_MAX_AMOUNT'                  => 'El monto supera el límite autorizado de la cuenta.',
        'ACCOUNT_SERVICE_NOT_AVAILABLE'       => 'La cuenta del cliente no tiene el servicio de PSE activo.',
    ];
}
