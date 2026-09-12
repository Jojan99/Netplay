<?php

namespace App\Http\Controllers;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Services\Acs\ConfiguradorAcs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El asistente que deja el TR-069 andando: dónde está el servidor, qué redes
 * hay que alcanzar —detectadas del router— y el script para pegar.
 */
class AcsSetupController extends Controller
{
    public function estado(ConectionRouterManagerInterface $conexion): JsonResponse
    {
        return $this->responder($conexion, fn (ConfiguradorAcs $c) => $c->estado());
    }

    public function guardar(Request $request, ConectionRouterManagerInterface $conexion): JsonResponse
    {
        $request->validate([
            'modo'        => 'required|in:plataforma,propio',
            'host'        => 'nullable|string|max:120',
            'puerto_cwmp' => 'nullable|integer|min:1|max:65535',
            'url_nbi'     => 'nullable|string|max:160',
            'alcance'     => 'nullable|in:publica,tunel',
            'router_id'   => 'nullable|integer',
        ]);

        return $this->responder($conexion, fn (ConfiguradorAcs $c) => $c->guardar($request->all()));
    }

    public function detectar(Request $request, ConectionRouterManagerInterface $conexion): JsonResponse
    {
        $routerId = $request->input('router_id') ? (int) $request->input('router_id') : null;

        return $this->responder($conexion, fn (ConfiguradorAcs $c) => $c->detectar($routerId));
    }

    public function aplicar(Request $request, ConectionRouterManagerInterface $conexion): JsonResponse
    {
        $request->validate([
            'redes' => 'required|array|min:1', 'redes.*' => 'string|max:40',
            'router_id' => 'nullable|integer',
        ]);

        return $this->responder($conexion, fn (ConfiguradorAcs $c) => $c->aplicar(
            $request->input('redes'),
            $request->input('router_id') ? (int) $request->input('router_id') : null,
        ));
    }

    public function diagnostico(ConectionRouterManagerInterface $conexion): JsonResponse
    {
        return $this->responder($conexion, fn (ConfiguradorAcs $c) => $c->diagnostico());
    }

    public function script(Request $request, ConectionRouterManagerInterface $conexion): JsonResponse
    {
        $routerId = $request->query('router_id') ? (int) $request->query('router_id') : null;

        return $this->responder($conexion, fn (ConfiguradorAcs $c) => $c->script($routerId));
    }

    private function responder(ConectionRouterManagerInterface $conexion, callable $fn): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            return standardApiReponse('OK', $fn(new ConfiguradorAcs($companyId, $conexion)), 0, JsonResponse::HTTP_OK);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            Log::warning('[ACS] Asistente falló', ['error' => $e->getMessage()]);

            return standardApiReponse('No se pudo completar: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }
}
