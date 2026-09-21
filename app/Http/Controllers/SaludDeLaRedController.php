<?php

namespace App\Http\Controllers;

use App\Services\Red\SaludDeLaRed;
use Illuminate\Http\JsonResponse;

/**
 * Salud de la red: cómo viene cada puerto PON, quién está al borde de quedarse
 * sin servicio y qué equipos se caen todo el día. Todo sale de lo que ya se
 * midió: esta pantalla nunca le pregunta a la OLT.
 */
class SaludDeLaRedController extends Controller
{
    public function index(): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        return standardApiReponse('OK', SaludDeLaRed::resumen($companyId), 0, JsonResponse::HTTP_OK);
    }
}
