<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\OltAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Cada puerto PON como un pedazo de la red: cuántos equipos cuelgan, cuánto
 * le queda, cuántos clientes deben o están cortados y qué alertas tiene.
 *
 * Complementa la señal (que viene de la OLT) con lo que sabe el sistema, para
 * ver de un vistazo dónde se concentran las fallas y qué puertos se llenan.
 * La ruta ya valida que la OLT sea de la empresa (empresa.propia).
 */
class PuertosDeOltController extends Controller
{
    /** ONT por puerto que admite cada tecnología (GPON 1:128, EPON 1:64). */
    private const CAPACIDAD = ['gpon' => 128, 'epon' => 64];

    /** GET api/management/olt/{oltId}/puertos */
    public function resumen(int $oltId): JsonResponse
    {
        $olt = OltAdmin::findOrFail($oltId);
        $capacidad = strtolower((string) $olt->brand) === 'huawei' ? self::CAPACIDAD['gpon'] : self::CAPACIDAD['epon'];
        $limiteMora = now()->subDays(CarteraController::DIAS_PARA_MORA)->toDateString();

        // olt_onts.user_data_id guarda users.id, pese al nombre.
        $puertos = DB::table('olt_onts as o')
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'o.user_data_id')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'ud.status_internet_id')
            ->where('o.olt_id', $oltId)
            ->groupBy('o.fsp')
            ->selectRaw("o.fsp,
                COUNT(*) AS onts,
                SUM(o.user_data_id IS NOT NULL) AS con_cliente,
                SUM(o.user_data_id IS NOT NULL AND ist.name IS NOT NULL AND UPPER(ist.name) NOT LIKE 'ACT%') AS suspendidos,
                SUM(o.user_data_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM det_facturations d JOIN cab_facturations c ON c.id = d.cab_id
                    WHERE c.user_id = o.user_data_id AND c.company_id = ? AND d.paid = 0 AND d.date_facturation < ?
                )) AS en_mora", [$olt->company_id, $limiteMora])
            ->orderBy('o.fsp')
            ->get();

        $alertas = Alerta::where('company_id', $olt->company_id)->abiertas()
            ->where(fn ($q) => $q->where('clave', 'like', "pon:{$oltId}:%")
                ->orWhere('clave', 'like', "senal:{$oltId}:%")
                ->orWhere('clave', 'like', "caida:{$oltId}:%"))
            ->get(['clave', 'tipo', 'nivel']);

        $porPuerto = [];

        foreach ($alertas as $a) {
            // pon:OLT:FSP · senal:OLT:FSP:ONT · caida:OLT:FSP:ONT
            $fsp = explode(':', $a->clave)[2] ?? null;
            if ($fsp === null) {
                continue;
            }
            $porPuerto[$fsp]['alertas'] = ($porPuerto[$fsp]['alertas'] ?? 0) + 1;
            $porPuerto[$fsp]['criticas'] = ($porPuerto[$fsp]['criticas'] ?? 0) + ($a->nivel === 'critico' ? 1 : 0);
            $porPuerto[$fsp]['corte'] = ($porPuerto[$fsp]['corte'] ?? false) || $a->tipo === 'corte';
        }

        return response()->json(['status' => 0, 'data' => [
            'capacidad' => $capacidad,
            'puertos'   => $puertos->map(fn ($p) => [
                'fsp'         => $p->fsp,
                'onts'        => (int) $p->onts,
                'ocupacion'   => round(((int) $p->onts) * 100 / $capacidad, 1),
                'con_cliente' => (int) $p->con_cliente,
                'en_mora'     => (int) $p->en_mora,
                'suspendidos' => (int) $p->suspendidos,
                'alertas'     => $porPuerto[$p->fsp]['alertas'] ?? 0,
                'criticas'    => $porPuerto[$p->fsp]['criticas'] ?? 0,
                'corte'       => $porPuerto[$p->fsp]['corte'] ?? false,
            ])->values(),
        ]]);
    }
}
