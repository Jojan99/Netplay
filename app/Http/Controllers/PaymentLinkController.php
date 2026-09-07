<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentLinkException;
use App\Models\Company;
use App\Models\OnlinePaymentTransaction;
use App\Models\PaymentLink;
use App\Services\MetaWhatsAppService;
use App\Services\PaymentGateways\PaymentLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentLinkController extends Controller
{
    public function __construct(private PaymentLinkService $links) {}

    /**
     * GET /api/pay/{token}
     *
     * Punto de entrada público de los links de pago. Genera el cobro con el
     * saldo vigente y redirige al checkout de la pasarela. Si algo falla, el
     * cliente ve una página con el motivo en lugar de un error crudo.
     */
    public function open(string $token, Request $request)
    {
        try {
            $result = $this->links->resolveToCheckout($token);
        } catch (PaymentLinkException $e) {
            // Única vía por la que un mensaje llega literal al cliente final.
            return response()->view('payment.link_message', [
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Link de pago: fallo al resolver', [
                'token_prefix' => substr($token, 0, 8),
                'ip'           => $request->ip(),
                'error'        => $e->getMessage(),
            ]);

            return response()->view('payment.link_message', [
                'message' => 'No pudimos abrir el pago en este momento. Intenta de nuevo en unos minutos.',
            ], 200);
        }

        return redirect()->away($result['url']);
    }

    /**
     * Página a la que la pasarela devuelve al cliente cuando termina de pagar.
     *
     * Antes caía en el portal de Netplay, que para alguien que salió de un chat
     * de WhatsApp no significa nada. Ahora se le devuelve por donde entró: si el
     * cobro nació de un link del bot, el botón principal lo regresa al chat.
     */
    public function result(string $reference, Request $request)
    {
        $tx = OnlinePaymentTransaction::where('reference', $reference)->first();

        // `r` es lo que dice la pasarela al redirigir; el estado real está en la
        // base y manda sobre él, porque la URL la puede tocar cualquiera.
        // La pasarela dice "rejected"; adentro ese estado se llama "declined".
        $hint = match ((string) $request->query('r', '')) {
            'approved' => 'approved',
            'rejected' => 'declined',
            'pending'  => 'pending',
            default    => null,
        };

        $status = $tx->status ?? $hint ?? 'pending';

        // El origen lo marca quien genera el cobro, en la propia URL de retorno.
        // Como respaldo se mira el link, aunque solo recuerda su último intento.
        $link      = PaymentLink::where('last_reference', $reference)->first();
        $fromWhats = $request->query('via') === 'wa'
            || ($request->query('via') === null && $link && $link->created_via === 'bot');

        // Sin transacción todavía -- la pasarela valida la URL antes de que
        // exista -- la empresa se deduce del prefijo de la referencia.
        $company = $tx
            ? Company::find($tx->company_id)
            : Company::all()->first(fn ($c) => $c->slug && str_starts_with($reference, $c->slug . '-'));
        $waNumber = $fromWhats && $company
            ? (new MetaWhatsAppService($company->id))->businessPhoneNumber()
            : null;

        $tone = match ($status) {
            'approved'  => ['icon' => '✓', 'soft' => '#dcfce7', 'strong' => '#15803d'],
            'declined',
            'failed'    => ['icon' => '✕', 'soft' => '#fee2e2', 'strong' => '#b91c1c'],
            'cancelled' => ['icon' => '!', 'soft' => '#fef3c7', 'strong' => '#b45309'],
            default     => ['icon' => '⏱', 'soft' => '#e0f2fe', 'strong' => '#0369a1'],
        };

        [$title, $message] = match ($status) {
            'approved'  => ['¡Pago confirmado!', 'Ya registramos tu pago y tu factura queda al día. Te enviamos el comprobante por WhatsApp.'],
            'declined',
            'failed'    => ['El pago no se completó', 'No se te hizo ningún cobro y tu factura sigue pendiente. Puedes intentarlo de nuevo con otro medio de pago.'],
            'cancelled' => ['Pago anulado', 'La transacción fue anulada. Si el dinero salió de tu cuenta, se te devuelve automáticamente.'],
            default     => ['Estamos confirmando tu pago', 'Puede tardar unos minutos. Te avisamos por WhatsApp apenas se acredite; no necesitas volver a pagar.'],
        };

        $volverAlChat = $waNumber
            ? ['label' => 'Volver a WhatsApp', 'url' => 'https://wa.me/' . $waNumber, 'color' => '#16a34a']
            : null;

        $verFacturas = ['label' => 'Ver mis facturas', 'url' => url('/portal/facturas'), 'color' => '#0f766e'];

        return response()->view('payment.result', [
            'title'      => $title,
            'message'    => $message,
            'tone'       => $tone,
            'amount'     => $tx ? '$' . number_format((float) $tx->amount, 0, ',', '.') : null,
            'details'    => array_filter([
                'Referencia' => $tx?->reference,
                'Fecha'      => now()->format('d/m/Y h:i a'),
            ]),
            'primary'    => $volverAlChat ?? $verFacturas,
            // Quien vino del chat también puede querer ver el detalle en el portal.
            'secondary'  => $volverAlChat ? $verFacturas : null,
            // Solo se devuelve solo al chat: mandar al portal sin avisar molesta.
            'autoReturn' => (bool) $volverAlChat,
        ]);
    }
}
