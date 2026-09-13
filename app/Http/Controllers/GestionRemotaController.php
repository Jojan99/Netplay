<?php

namespace App\Http\Controllers;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\OltOnt;
use App\Services\Red\GestionRemotaDeOnt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El acceso remoto a los equipos de los clientes, para el panel.
 *
 * Lo activa cada empresa por su cuenta: la plataforma propone qué VLAN y qué
 * red están libres y, cuando se acepta, deja la configuración puesta en el
 * MikroTik y en cada OLT.
 */
class GestionRemotaController extends Controller
{
    public function estado(): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        return standardApiReponse('Acceso remoto', $this->servicio($companyId)->estado(), 0, JsonResponse::HTTP_OK);
    }

    /**
     * Qué está libre para usar. Consulta el router y las OLT de verdad, así
     * que tarda: el front la pide sólo cuando se abre el asistente.
     */
    public function sugerencias(Request $request): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $datos = $this->servicio($companyId)->sugerencias(
                $request->filled('router_id') ? (int) $request->input('router_id') : null
            );
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo leer la red: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }

        return standardApiReponse('Lo que está libre', $datos, isset($datos['error']) ? 1 : 0, JsonResponse::HTTP_OK);
    }

    public function activar(Request $request): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $datos = $request->validate([
            'vlan'      => 'required|integer|min:2|max:4094',
            'red'       => 'required|string',
            'interfaz'  => 'required|string',
            'router_id' => 'nullable|integer',
            'uplinks'   => 'nullable|array',
        ]);

        try {
            $r = $this->servicio($companyId)->activar($datos);
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo activar: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }

        // Si algún paso falló, el mensaje lo dice: dejar "listo" a secas
        // esconde media configuración sin aplicar.
        $fallaron = collect($r['pasos'])->where('ok', false);

        return standardApiReponse(
            $fallaron->isEmpty() ? 'Acceso remoto activado' : 'Activado, pero quedó algo pendiente: ' . $fallaron->pluck('paso')->implode(', '),
            $r,
            0,
            JsonResponse::HTTP_OK
        );
    }

    public function desactivar(): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $r = $this->servicio($companyId)->desactivar();
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo desactivar: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }

        return standardApiReponse('Acceso remoto desactivado', $r, 0, JsonResponse::HTTP_OK);
    }

    /** Le da acceso a una ONT ya autorizada. */
    public function darAcceso(Request $request, int $oltId): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $datos = $request->validate([
            'fsp'    => 'required|string',
            'ont_id' => 'required|integer',
        ]);

        $r = $this->servicio($companyId)->darAcceso($oltId, $datos['fsp'], (int) $datos['ont_id']);

        return standardApiReponse($r['detalle'], $r, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    /**
     * Se lo da a los equipos que ya estaban autorizados de antes.
     *
     * De a tandas: cada ONT son dos comandos en la OLT y son cientos, así que
     * el front va pidiendo hasta que no queden.
     */
    public function alDia(Request $request): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $servicio = $this->servicio($companyId);
        // De a poco: cada equipo son ocho comandos contra la OLT y la respuesta
        // tiene que llegar antes de que el navegador se canse de esperar.
        $cuantas  = min(5, max(1, (int) $request->input('cuantas', 3)));

        // Las OLT que no saben recibir la gestión se saltean: si no, la tanda
        // se llena de equipos que siempre van a fallar.
        $marcas = \App\Models\OltAdmin::where('company_id', $companyId)->get(['id', 'brand'])
            ->filter(fn ($o) => GestionRemotaDeOnt::admiteGestion((string) $o->brand))
            ->pluck('id')->all();

        $pendientes = OltOnt::whereIn('olt_id', $marcas)
            ->whereNull('gestion_en')
            ->when($request->filled('olt_id'), fn ($q) => $q->where('olt_id', (int) $request->input('olt_id')))
            ->orderBy('id');

        $quedan = (clone $pendientes)->count();
        $hechas = [];

        foreach ($pendientes->limit($cuantas)->get() as $ont) {
            $r = $servicio->darAcceso((int) $ont->olt_id, (string) $ont->fsp, (int) $ont->ont_id);

            $hechas[] = [
                'ont'     => "{$ont->fsp}:{$ont->ont_id}",
                'nombre'  => $ont->description,
                'ok'      => $r['ok'],
                'detalle' => $r['detalle'],
            ];

            // Si el acceso remoto no está activo no tiene sentido seguir
            // castigando a la OLT con cientos de intentos iguales.
            if (!$r['ok'] && str_contains($r['detalle'], 'no está activado')) {
                break;
            }
        }

        return standardApiReponse('Equipos procesados', [
            'hechas'     => $hechas,
            'quedaban'   => $quedan,
            'pendientes' => max(0, $quedan - count(array_filter($hechas, fn ($h) => $h['ok']))),
        ], 0, JsonResponse::HTTP_OK);
    }

    private function servicio(int $companyId): GestionRemotaDeOnt
    {
        return new GestionRemotaDeOnt($companyId, app(ConectionRouterManagerInterface::class));
    }
}
