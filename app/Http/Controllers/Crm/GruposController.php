<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\WhatsApp\LineasDeWhatsApp as Lineas;

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

        // Se recorren TODAS las líneas de la empresa, no sólo la principal: con
        // dos líneas vinculadas la mitad de los grupos no aparecía en la lista.
        // La api-key es la de la empresa y el servicio Node rechaza cualquier
        // instancia que no sea suya, así que no se puede ver la de otra.
        $lineas = Lineas::deEmpresa((int) $companyId)
            ?: [['instance_id' => $company->wa_instance_id, 'nombre' => 'Principal']];

        $data      = [];
        $alcanzado = false;

        foreach ($lineas as $linea) {
            try {
                $res = Http::timeout(20)
                    ->withHeaders(['x-api-key' => $company->wa_api_key])
                    ->get("{$base}/crm/instances/{$linea['instance_id']}/groups");
            } catch (\Throwable $e) {
                continue;   // una línea caída no puede dejar sin grupos a las demás
            }

            $alcanzado = true;

            foreach ($res->successful() ? ($res->json('groups') ?? []) : [] as $g) {
                $data[] = [
                    'jid'           => $g['jid'] ?? null,
                    'nombre'        => $g['name'] ?? ($g['jid'] ?? 'Grupo'),
                    'participantes' => $g['participants'] ?? 0,
                    'seguido'       => (bool) ($seguidos[$g['jid'] ?? ''] ?? false),
                    'linea'         => $linea['nombre'],
                ];
            }
        }

        if (!$alcanzado) {
            return response()->json(['ok' => false, 'data' => [], 'motivo' => 'servicio_no_disponible']);
        }

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
