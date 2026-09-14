<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\Red\ConsumoDelCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tymon\JWTAuth\Facades\JWTAuth;

/** Portal: consumo de datos del mes y prueba de velocidad del cliente. */
class ClientConsumoController extends Controller
{
    public function historial(): JsonResponse
    {
        $user = JWTAuth::user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.', 'data' => null, 'error' => 1], 401);
        }

        $data = (new ConsumoDelCliente((int) $user->company_id))->historial((int) $user->id);

        return response()->json(['message' => 'ok', 'data' => $data, 'error' => 0]);
    }

    /**
     * Mide la velocidad unos segundos en el MikroTik. Se limita por cliente:
     * cada medición es una consulta al router de toda la red.
     */
    public function velocidad(): JsonResponse
    {
        $user = JWTAuth::user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.', 'data' => null, 'error' => 1], 401);
        }

        $clave = "velocidad-cliente:{$user->id}";

        if (RateLimiter::tooManyAttempts($clave, 4)) {
            $ultima = Cache::get("{$clave}:ultima");
            return response()->json([
                'message' => 'Ya mediste varias veces seguidas. Espera un minuto para volver a medir.',
                'data' => $ultima, 'error' => $ultima ? 0 : 1,
            ], $ultima ? 200 : 429);
        }

        RateLimiter::hit($clave, 60);

        try {
            $r = (new ConsumoDelCliente((int) $user->company_id))->velocidadAhora((int) $user->id);
        } catch (\Throwable $e) {
            Log::warning('[Portal] No se pudo medir la velocidad', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'No pudimos medir tu velocidad ahora. Intenta en un momento.', 'data' => null, 'error' => 1]);
        }

        if (!$r['ok']) {
            return response()->json(['message' => $r['detalle'], 'data' => null, 'error' => 1]);
        }

        $r['medido_en'] = now()->toIso8601String();
        Cache::put("{$clave}:ultima", $r, now()->addMinutes(2));

        return response()->json(['message' => 'ok', 'data' => $r, 'error' => 0]);
    }
}
