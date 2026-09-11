<?php

namespace App\Http\Controllers;

use App\UseCases\Vpn\VpnUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VpnController extends Controller
{
    public function __construct(private VpnUseCase $uc) {}

    /** Estado del servidor y de cada túnel. */
    public function estado(): JsonResponse
    {
        $r = $this->uc->estado();

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** El script que instala el servidor; se corre una vez con sudo. */
    public function instalador(): JsonResponse
    {
        $r = $this->uc->instalador();

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function crearTunel(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre'        => 'required|string|max:120',
            'router_id'     => 'nullable|integer',
            'redes_remotas' => 'required',
            'puerto_router' => 'nullable|integer|min:1|max:65535',
            'keepalive'     => 'nullable|integer|min:5|max:120',
            'notas'         => 'nullable|string|max:1000',
        ]);

        $r = $this->uc->crearTunel($datos);

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function script(int $id): JsonResponse
    {
        $r = $this->uc->script($id);

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function actualizarTunel(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'nombre'        => 'sometimes|string|max:120',
            'router_id'     => 'nullable|integer',
            'redes_remotas' => 'sometimes',
            'keepalive'     => 'sometimes|integer|min:5|max:120',
            'activo'        => 'sometimes|boolean',
            'notas'         => 'nullable|string|max:1000',
        ]);

        $r = $this->uc->actualizarTunel($id, $datos);

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function eliminarTunel(int $id): JsonResponse
    {
        $r = $this->uc->eliminarTunel($id);

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Deja una OLT alcanzándose por el túnel en lugar del jump host. */
    public function usarEnOlt(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'olt_id'    => 'required|integer',
            'verificar' => 'sometimes|boolean',
        ]);

        $r = $this->uc->usarTunelEnOlt($id, (int) $datos['olt_id'], $request->boolean('verificar', true));

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function probar(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'ip'     => 'required|ip',
            'puerto' => 'nullable|integer|min:1|max:65535',
        ]);

        $r = $this->uc->probarAlcance($id, $datos['ip'], (int) ($datos['puerto'] ?? 23));

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }
}
