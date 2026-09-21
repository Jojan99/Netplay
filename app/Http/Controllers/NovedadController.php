<?php

namespace App\Http\Controllers;

use App\Models\Novedad;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Las novedades que ve la empresa en su panel: qué se agregó, qué mejoró y qué
 * se arregló. Las escribe Netvula desde la consola; acá sólo se leen y se
 * marcan como vistas.
 */
class NovedadController extends Controller
{
    /** Cuántas hay sin ver, para el puntito del encabezado. */
    public function index(): JsonResponse
    {
        $user = JWTAuth::user();

        if (!$user) {
            return standardApiReponse('Sin sesión', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $modulos = DB::table('profile_modules')->where('profile_id', $user->profile_id)->where('active', true)
            ->pluck('module')->map(fn ($m) => (string) $m)->all();

        $novedades = Novedad::publicadas()->paraModulos($modulos)
            ->orderByDesc('publicada_en')->limit(30)
            ->get(['id', 'titulo', 'detalle', 'tipo', 'ruta', 'publicada_en']);

        $visto = DB::table('novedades_vistas')->where('user_id', $user->id)->value('visto_hasta');

        return standardApiReponse('OK', [
            'novedades' => $novedades->map(fn ($n) => $n->toArray() + ['nueva' => !$visto || $n->publicada_en->gt($visto)]),
            'sin_ver'   => $novedades->filter(fn ($n) => !$visto || $n->publicada_en->gt($visto))->count(),
        ], 0, JsonResponse::HTTP_OK);
    }

    /** Se abrió la lista: de acá en adelante ya no son nuevas. */
    public function vistas(): JsonResponse
    {
        $user = JWTAuth::user();

        if ($user) {
            DB::table('novedades_vistas')->updateOrInsert(
                ['user_id' => $user->id],
                ['visto_hasta' => now(), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return standardApiReponse('OK', null, 0, JsonResponse::HTTP_OK);
    }
}
