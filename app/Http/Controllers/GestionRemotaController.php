<?php

namespace App\Http\Controllers;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\OltOnt;
use App\Services\Red\GestionRemotaDeOnt;
use App\Services\Red\TareasDeGestion;
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

    /** Ajustes del aprovisionamiento automático y los últimos equipos programados. */
    public function aprovisionamiento(): JsonResponse
    {
        $s = new \App\Services\Red\AprovisionamientoDeOnt((int) getSessionCompanyId());

        return standardApiReponse('Aprovisionamiento', ['ajustes' => $s->ajustes(), 'ultimos' => $s->ultimos()], 0, JsonResponse::HTTP_OK);
    }

    public function guardarAprovisionamiento(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'aprovisionar'  => 'required|boolean',
            'wan'           => 'boolean',
            'wifi'          => 'boolean',
            'admin'         => 'boolean',
            'wifi_prefijo'  => 'nullable|string|max:20',
            'admin_usuario' => 'nullable|string|max:32',
            'admin_clave'   => 'nullable|string|max:64',
        ]);

        try {
            $ajustes = (new \App\Services\Red\AprovisionamientoDeOnt((int) getSessionCompanyId()))->guardarAjustes($datos);
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return standardApiReponse('Ajustes guardados', $ajustes, 0, JsonResponse::HTTP_OK);
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

    /**
     * Le da acceso a una ONT ya autorizada.
     *
     * Contesta enseguida con la tarea: el trabajo contra la OLT sigue en
     * segundo plano y la pantalla consulta cómo va.
     */
    public function darAcceso(Request $request, int $oltId): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $datos = $request->validate([
            'fsp'    => 'required|string',
            'ont_id' => 'required|integer',
        ]);

        $id = TareasDeGestion::crear($companyId, 'dar_acceso', ['olt_id' => $oltId, 'fsp' => $datos['fsp'], 'ont_id' => (int) $datos['ont_id']]);
        TareasDeGestion::lanzar($id);

        return standardApiReponse('Dando acceso remoto al equipo…', ['tarea' => $id, 'estado' => 'en_curso'], 0, JsonResponse::HTTP_OK);
    }

    /**
     * Le da el acceso a todos los equipos que ya estaban autorizados.
     *
     * Son cientos: corre en segundo plano hasta terminar o hasta que el
     * operador lo pare. Si ya hay una en curso, se devuelve esa.
     */
    public function alDia(Request $request): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        if ($enCurso = TareasDeGestion::enCurso($companyId, 'al_dia')) {
            return standardApiReponse('Ya hay una puesta al día en curso', ['tarea' => $enCurso, 'estado' => 'en_curso'], 0, JsonResponse::HTTP_OK);
        }

        $id = TareasDeGestion::crear($companyId, 'al_dia', array_filter(['olt_id' => $request->input('olt_id')]));
        TareasDeGestion::marcarActiva($companyId, 'al_dia', $id);
        TareasDeGestion::lanzar($id);

        return standardApiReponse('Puesta al día en curso', ['tarea' => $id, 'estado' => 'en_curso'], 0, JsonResponse::HTTP_OK);
    }

    /** Cómo va una tarea. Sólo las de la empresa en sesión. */
    public function tarea(string $id): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();
        $t = TareasDeGestion::ver($id);

        if (!$t || (int) $t['company_id'] !== $companyId) {
            return standardApiReponse('No existe esa tarea', null, 1, JsonResponse::HTTP_OK);
        }

        unset($t['company_id']);

        return standardApiReponse($t['detalle'] ?? '', $t, 0, JsonResponse::HTTP_OK);
    }

    /** Pide parar una tarea: termina el equipo que está haciendo y se detiene. */
    public function pararTarea(string $id): JsonResponse
    {
        $companyId = (int) getSessionCompanyId();
        $t = TareasDeGestion::ver($id);

        if (!$t || (int) $t['company_id'] !== $companyId) {
            return standardApiReponse('No existe esa tarea', null, 1, JsonResponse::HTTP_OK);
        }

        TareasDeGestion::pedirParar($id);

        return standardApiReponse('Se detiene después del equipo en curso', ['tarea' => $id], 0, JsonResponse::HTTP_OK);
    }

    /** Todo lo que tiene que estar bien, revisado y dicho en castellano. */
    public function diagnostico(): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $r = $this->servicio($companyId)->diagnostico();
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo revisar: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }

        return standardApiReponse($r['resumen'], $r, 0, JsonResponse::HTTP_OK);
    }

    /** Los perfiles de línea de una OLT y si dejan salir la gestión. */
    public function perfiles(Request $request, int $oltId): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $r = $this->servicio($companyId)->perfiles($oltId, $request->boolean('releer'));
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudieron leer los perfiles: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }

        return standardApiReponse($r['error'] ?? 'Perfiles de línea', $r, isset($r['error']) ? 1 : 0, JsonResponse::HTTP_OK);
    }

    /** Agrega la gestión a un perfil. De a uno, a pedido del operador. */
    public function prepararPerfil(int $oltId, int $perfil): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $id = TareasDeGestion::crear($companyId, 'preparar_perfil', ['olt_id' => $oltId, 'perfil' => $perfil]);
        TareasDeGestion::lanzar($id);

        return standardApiReponse('Preparando el perfil…', ['tarea' => $id, 'estado' => 'en_curso'], 0, JsonResponse::HTTP_OK);
    }

    /**
     * Reinicia un equipo desde la OLT, en segundo plano. Le corta internet un
     * minuto al cliente: la pantalla lo avisa antes de pedirlo.
     */
    public function reiniciar(Request $request, int $oltId): JsonResponse
    {
        if (!$companyId = (int) getSessionCompanyId()) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $datos = $request->validate([
            'fsp'    => 'required|string',
            'ont_id' => 'required|integer',
        ]);

        $id = TareasDeGestion::crear($companyId, 'reiniciar', ['olt_id' => $oltId, 'fsp' => $datos['fsp'], 'ont_id' => (int) $datos['ont_id']]);
        TareasDeGestion::lanzar($id);

        return standardApiReponse('Reiniciando el equipo…', ['tarea' => $id, 'estado' => 'en_curso'], 0, JsonResponse::HTTP_OK);
    }

    private function servicio(int $companyId): GestionRemotaDeOnt
    {
        return new GestionRemotaDeOnt($companyId, app(ConectionRouterManagerInterface::class));
    }
}
