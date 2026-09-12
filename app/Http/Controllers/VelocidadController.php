<?php

namespace App\Http\Controllers;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Services\Red\ControlDeVelocidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** La velocidad de cada plan y cómo se aplica en el MikroTik. */
class VelocidadController extends Controller
{
    public function index(ConectionRouterManagerInterface $conexion): JsonResponse
    {
        return $this->responder($conexion, fn (ControlDeVelocidad $c) => $c->planes());
    }

    public function guardar(Request $request, int $planId, ConectionRouterManagerInterface $conexion): JsonResponse
    {
        $request->validate([
            'bajada'          => 'required|integer|min:1|max:10000',
            'subida'          => 'required|integer|min:1|max:10000',
            'rafaga'          => 'boolean',
            'rafaga_bajada'   => 'nullable|integer|min:1|max:20000',
            'rafaga_subida'   => 'nullable|integer|min:1|max:20000',
            'rafaga_segundos' => 'nullable|integer|min:1|max:60',
            'prioridad'       => 'nullable|integer|min:1|max:8',
        ]);

        return $this->responder($conexion, fn (ControlDeVelocidad $c) => $c->guardar($planId, $request->all()));
    }

    public function aplicar(Request $request, int $planId, ConectionRouterManagerInterface $conexion): JsonResponse
    {
        return $this->responder($conexion, function (ControlDeVelocidad $c) use ($request, $planId) {
            $r = $c->aplicar($planId, $request->input('router_id') ? (int) $request->input('router_id') : null);

            Log::info('[Velocidad] Aplicado desde el panel', ['plan' => $planId, 'resultado' => $r]);

            return $r + [
                'mensaje' => "Perfil {$r['perfil']} listo · {$r['pppoe']} clientes PPPoE y {$r['colas']} de IP fija"
                    . ($r['saltados'] ? " · {$r['saltados']} sin límite, sin tocar" : ''),
            ];
        });
    }

    /** Un cliente puede quedar fuera del control de velocidad de su plan. */
    public function cliente(Request $request, int $userId): JsonResponse
    {
        $request->validate(['control' => 'required|in:plan,sin_limite']);

        $companyId = (int) getSessionCompanyId();

        $afectados = DB::table('user_data')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->update(['control_velocidad' => $request->input('control')]);

        if (!$afectados) {
            return standardApiReponse('Ese cliente no es de tu empresa', null, 1, JsonResponse::HTTP_OK);
        }

        return standardApiReponse(
            $request->input('control') === 'plan'
                ? 'El cliente vuelve a la velocidad de su plan. Aplicá el plan para que tome efecto.'
                : 'El cliente queda fuera del control de velocidad. Quitale la cola o el perfil en el router si ya los tenía.',
            null,
            0,
            JsonResponse::HTTP_OK
        );
    }

    private function responder(ConectionRouterManagerInterface $conexion, callable $fn): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            return standardApiReponse('OK', $fn(new ControlDeVelocidad($conexion, $companyId)), 0, JsonResponse::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            Log::warning('[Velocidad] Falló', ['error' => $e->getMessage()]);

            return standardApiReponse('No se pudo: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }
}
