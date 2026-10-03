<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Medidas de una ventana tal como se ve en el teléfono de un usuario.
 *
 * TEMPORAL: sirve para encontrar por qué en algunos teléfonos los botones de abajo de las
 * ventanas quedan fuera de la pantalla, algo que no se reproduce en un navegador de escritorio.
 * Sólo guarda tamaños y el navegador; nada de lo que el usuario escribe.
 */
class DiagnosticoDePantallaController extends Controller
{
    public function guardar(Request $request): JsonResponse
    {
        $d = $request->validate(['medidas' => 'required|array|max:60']);

        Log::build(['driver' => 'single', 'path' => storage_path('logs/pantalla.log')])->info('pantalla', [
            'usuario' => getSessionUserId(),
            'empresa' => getSessionCompanyId(),
            'medidas' => array_map(fn ($v) => is_scalar($v) || $v === null ? (is_string($v) ? mb_substr($v, 0, 400) : $v) : mb_substr(json_encode($v), 0, 600), $d['medidas']),
        ]);

        return response()->json(['error' => 0]);
    }
}
