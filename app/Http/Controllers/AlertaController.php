<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Services\Alertas\RevisorDeRed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Los avisos de la red de la empresa en sesión. */
class AlertaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $alertas = Alerta::where('company_id', $companyId)
            ->when(!$request->boolean('historial'), fn ($q) => $q->abiertas())
            ->orderByRaw("FIELD(nivel, 'critico', 'aviso')")
            ->orderByDesc('abierta_en')
            ->limit(300)
            ->get();

        return standardApiReponse('Avisos de la red', [
            'alertas' => $alertas,
            'resumen' => [
                'criticos' => $alertas->where('nivel', 'critico')->whereNull('cerrada_en')->count(),
                'avisos'   => $alertas->where('nivel', 'aviso')->whereNull('cerrada_en')->count(),
                'sin_ver'  => $alertas->whereNull('vista_en')->whereNull('cerrada_en')->count(),
            ],
        ], 0, JsonResponse::HTTP_OK);
    }

    /** Marca como vistos: lo que ya se revisó deja de figurar como nuevo. */
    public function marcarVistas(Request $request): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();

        Alerta::where('company_id', $companyId)
            ->abiertas()
            ->when($request->input('ids'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->update(['vista_en' => now()]);

        return standardApiReponse('Listo', null, 0, JsonResponse::HTTP_OK);
    }

    /** Vuelve a revisar en el momento, sin esperar al repaso automático. */
    public function revisar(): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $r = (new RevisorDeRed($companyId))->revisar();

        return standardApiReponse("{$r['abiertas']} avisos abiertos", $r, 0, JsonResponse::HTTP_OK);
    }
}
