<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Los errores que ocurren en el navegador de quien usa el panel.
 *
 * Sin esto, una falla que sólo pasa con los datos o el teléfono de alguien
 * era invisible: el navegador la mostraba en una consola que nadie abre. Se
 * guardan en su propio archivo, con límite por dirección para que un error en
 * bucle no llene el disco.
 */
class ErrorNavegadorController extends Controller
{
    private const MAXIMO_POR_VENTANA = 60;

    public function guardar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'mensaje'   => 'required|string|max:2000',
            'pila'      => 'nullable|string|max:4000',
            'ruta'      => 'nullable|string|max:300',
            'ancho'     => 'nullable|integer|min:0|max:10000',
            'navegador' => 'nullable|string|max:300',
        ]);

        $clave = 'errores-navegador:' . $request->ip();
        $cuantos = (int) Cache::get($clave, 0);

        if ($cuantos >= self::MAXIMO_POR_VENTANA) {
            return standardApiReponse('Límite alcanzado', null, 0, JsonResponse::HTTP_OK);
        }

        Cache::put($clave, $cuantos + 1, now()->addMinutes(10));

        $linea = json_encode([
            'en'        => now()->toIso8601String(),
            'empresa'   => getSessionCompanyId(),
            'ip'        => $request->ip(),
        ] + $datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents(storage_path('logs/errores-navegador.log'), $linea . "\n", FILE_APPEND | LOCK_EX);

        return standardApiReponse('Registrado', null, 0, JsonResponse::HTTP_OK);
    }
}
