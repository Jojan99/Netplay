<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Grupos de WhatsApp del CRM.
 *
 * Solo WhatsApp Web: la API de Meta no soporta grupos.
 *
 * Los grupos no entran todos a la bandeja. La empresa elige cuáles seguir,
 * porque una línea suele estar en grupos ajenos a la atención y traerlos todos
 * llenaría la bandeja de ruido.
 */
class GruposController extends Controller
{
    /**
     * GET api/management/crm/grupos
     * Los grupos de la instancia, marcando cuáles se están siguiendo.
     */
    public function index(): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $company   = Company::find($companyId);

        if (!$company || !$company->wa_instance_id) {
            return response()->json(['ok' => true, 'data' => [], 'motivo' => 'sin_instancia']);
        }

        $seguidos = DB::table('crm_grupos_seguidos')
            ->where('company_id', $companyId)
            ->pluck('activo', 'jid');

        $base = rtrim(preg_replace('#/crm$#', '', (string) config('services.netplay_whatsapp.base_url')), '/');

        try {
            $res = Http::timeout(20)
                ->withHeaders(['x-api-key' => $company->wa_api_key])
                ->get("{$base}/crm/instances/{$company->wa_instance_id}/groups");

            $grupos = $res->successful() ? ($res->json('groups') ?? []) : [];
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'data' => [], 'motivo' => 'servicio_no_disponible']);
        }

        $data = array_map(fn ($g) => [
            'jid'           => $g['jid'] ?? null,
            'nombre'        => $g['name'] ?? ($g['jid'] ?? 'Grupo'),
            'participantes' => $g['participants'] ?? 0,
            'seguido'       => (bool) ($seguidos[$g['jid'] ?? ''] ?? false),
        ], $grupos);

        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * POST api/management/crm/grupos  { jid, nombre, participantes, seguir }
     * Empieza o deja de seguir un grupo.
     */
    public function toggle(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'jid'           => 'required|string|max:64',
            'nombre'        => 'nullable|string|max:160',
            'participantes' => 'nullable|integer',
            'seguir'        => 'required|boolean',
        ]);

        $companyId = getSessionCompanyId();

        DB::table('crm_grupos_seguidos')->updateOrInsert(
            ['company_id' => $companyId, 'jid' => $datos['jid']],
            [
                'nombre'        => $datos['nombre'] ?? null,
                'participantes' => $datos['participantes'] ?? null,
                'activo'        => $datos['seguir'],
                'updated_at'    => now(),
                'created_at'    => now(),
            ]
        );

        return response()->json([
            'ok'      => true,
            'seguido' => (bool) $datos['seguir'],
        ]);
    }
}
