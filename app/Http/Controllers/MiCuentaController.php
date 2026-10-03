<?php

namespace App\Http\Controllers;

use App\Services\Plataforma\EstadoDeCuenta;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * La cuenta de la empresa con Netvula, para el aviso del panel: si la prueba
 * está por vencer o hay un pago pendiente. Con la empresa suspendida esta ruta
 * no llega a ejecutarse: el middleware responde 403 con los mismos datos.
 */
class MiCuentaController extends Controller
{
    public function ver(): JsonResponse
    {
        $user = JWTAuth::user();

        if (!$user || !$user->company_id) {
            return standardApiReponse('Sin sesión', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        return standardApiReponse('OK', EstadoDeCuenta::de((int) $user->company_id), 0, JsonResponse::HTTP_OK);
    }
}
