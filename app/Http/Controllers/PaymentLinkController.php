<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentLinkException;
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
}
