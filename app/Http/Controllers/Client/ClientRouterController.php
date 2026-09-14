<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\Acs\RouterDelCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * "Mi WiFi" del portal: el cliente ve y administra su propio equipo.
 *
 * Sólo funciona si su equipo reporta al servidor TR-069, así que la pantalla
 * se habilita sola a medida que se van configurando los equipos. Cada
 * operación se resuelve contra el equipo de ese cliente y nada más.
 */
class ClientRouterController extends Controller
{
    public function panel(): JsonResponse
    {
        return $this->responder(fn (RouterDelCliente $r) => $r->panel());
    }

    public function wifi(Request $request): JsonResponse
    {
        $request->validate([
            'indice' => 'required|integer|min:1',
            'nombre' => 'nullable|string|min:1|max:32',
            'clave'  => 'nullable|string|min:8|max:63',
            'todas'  => 'nullable|boolean',
        ]);

        return $this->responder(function (RouterDelCliente $r) use ($request) {
            $t = $r->cambiarWifi((int) $request->input('indice'), $request->input('nombre'), $request->input('clave'), $request->boolean('todas'));

            return [
                'mensaje' => ($t['hecha'] ?? false)
                    ? 'Listo. Vuelve a conectar tus equipos con los datos nuevos.'
                    : 'Guardado. Se aplica en cuanto tu equipo se vuelva a conectar.',
            ] + $t;
        }, 'cambio de WiFi');
    }

    public function bloquear(Request $request): JsonResponse
    {
        $request->validate(['mac' => 'required|string|max:32']);

        return $this->responder(
            fn (RouterDelCliente $r) => $r->bloquear((string) $request->input('mac')),
            'bloqueo de un equipo'
        );
    }

    public function desbloquear(Request $request): JsonResponse
    {
        $request->validate(['mac' => 'required|string|max:32']);

        return $this->responder(
            fn (RouterDelCliente $r) => $r->desbloquear((string) $request->input('mac')),
            'desbloqueo de un equipo'
        );
    }

    public function refrescar(): JsonResponse
    {
        return $this->responder(fn (RouterDelCliente $r) => $r->refrescar());
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function responder(callable $fn, ?string $accion = null): JsonResponse
    {
        $user = JWTAuth::user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.', 'data' => null, 'error' => 1], 401);
        }

        // Las acciones tocan el equipo del cliente: se limitan para que un
        // portal abierto no termine reconfigurando el equipo en bucle.
        if ($accion) {
            $clave = "router-cliente:{$user->id}";

            if (RateLimiter::tooManyAttempts($clave, 10)) {
                return response()->json([
                    'message' => 'Has hecho muchos cambios seguidos. Espera un minuto.',
                    'data' => null, 'error' => 1,
                ], 429);
            }

            RateLimiter::hit($clave, 60);
        }

        try {
            $datos = $fn(new RouterDelCliente((int) $user->id, (int) $user->company_id));

            if ($accion) {
                Log::info('[Portal] Acción sobre el equipo del cliente', [
                    'accion' => $accion, 'user_id' => $user->id, 'empresa' => $user->company_id,
                ]);
            }

            return response()->json(['message' => 'OK', 'data' => $datos, 'error' => 0]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => null, 'error' => 1]);
        } catch (\Throwable $e) {
            Log::warning('[Portal] Router del cliente falló', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'No pudimos hablar con tu equipo en este momento.',
                'data' => null, 'error' => 1,
            ]);
        }
    }
}
