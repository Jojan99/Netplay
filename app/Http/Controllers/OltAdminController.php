<?php

namespace App\Http\Controllers;

use App\Http\Requests\OltAdmin\OltAdminRequest;
use App\Http\Requests\OltAdmin\OltOntRequest;
use App\UseCases\OltAdmin\OltAdminUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OltAdminController extends Controller
{
    public function __construct(private OltAdminUseCase $uc) {}

    // ── CRUD OLTs ─────────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $r = $this->uc->listOlts();
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function store(OltAdminRequest $request): JsonResponse
    {
        $r = $this->uc->createOlt($request->validated());
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function update(OltAdminRequest $request, int $id): JsonResponse
    {
        $r = $this->uc->updateOlt($id, $request->validated());
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function destroy(int $id): JsonResponse
    {
        $r = $this->uc->deleteOlt($id);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    // ── Ficha del equipo ──────────────────────────────────────────────────

    /** Marca, modelo, tarjetas y puertos de la OLT, leídos por SNMP. */
    public function equipo(Request $request, int $oltId): JsonResponse
    {
        $r = $this->uc->equipo($oltId, $request->boolean('refrescar'));

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Prueba la conexión con la OLT paso por paso. */
    public function diagnostico(int $oltId): JsonResponse
    {
        $r = $this->uc->diagnosticar($oltId);

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Olvida el mapa de puertos y la ficha guardadas de la OLT. */
    public function olvidarPuertos(int $oltId): JsonResponse
    {
        $r = $this->uc->olvidarPuertos($oltId);

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Señal óptica de todas las ONT de la OLT, en un solo barrido. */
    public function senal(Request $request, int $oltId): JsonResponse
    {
        $r = $this->uc->senal($oltId, $request->boolean('refrescar'));

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Fija los perfiles que se usan al autorizar una ONT en esta OLT. */
    public function fijarPerfiles(Request $request, int $oltId): JsonResponse
    {
        $datos = $request->validate([
            'ont_lineprofile_id' => 'nullable|integer|min:0',
            'ont_srvprofile_id'  => 'nullable|integer|min:0',
        ]);

        $r = $this->uc->fijarPerfiles(
            $oltId,
            $datos['ont_lineprofile_id'] ?? null,
            $datos['ont_srvprofile_id'] ?? null,
        );

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Qué puertos autorizan solos las ONU nuevas. */
    public function autoAutorizacion(int $oltId): JsonResponse
    {
        $r = $this->uc->autoAutorizacion($oltId);

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function cambiarAutoAutorizacion(Request $request, int $oltId): JsonResponse
    {
        $datos = $request->validate([
            'puerto'  => 'required|integer|min:1|max:64',
            'activar' => 'required|boolean',
        ]);

        $r = $this->uc->cambiarAutoAutorizacion($oltId, (int) $datos['puerto'], (bool) $datos['activar']);

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Marcas de OLT que la plataforma sabe manejar. */
    public function marcas(): JsonResponse
    {
        $r = $this->uc->marcasSoportadas();

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Foto del equipo que el operador sube desde la pantalla. */
    public function guardarFoto(Request $request, int $oltId): JsonResponse
    {
        $request->validate([
            'foto' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $r = $this->uc->guardarFoto($oltId, $request->file('foto'));

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function borrarFoto(int $oltId): JsonResponse
    {
        $r = $this->uc->borrarFoto($oltId);

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    // ── ONT operations ────────────────────────────────────────────────────

    public function unauthONTs(int $oltId): JsonResponse
    {
        $r = $this->uc->getUnauthONTs($oltId);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function registerONT(OltOntRequest $request, int $oltId): JsonResponse
    {
        $r = $this->uc->registerONT($oltId, $request->validated());
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Dónde está ya esta ONT, para avisar antes de intentar autorizarla. */
    public function buscarOnt(Request $request, int $oltId): JsonResponse
    {
        $serial = trim((string) $request->query('serial'));

        if ($serial === '') {
            return standardApiReponse('Falta el serial', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $r = $this->uc->buscarOntPorSerial($oltId, $serial);

        return standardApiReponse('Búsqueda lista', $r, 0, JsonResponse::HTTP_OK);
    }

    /** Quita la ONT de donde esté y la autoriza en el puerto pedido. */
    public function moverOnt(OltOntRequest $request, int $oltId): JsonResponse
    {
        $r = $this->uc->moverOnt($oltId, $request->validated());

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Reintenta sólo el service-port de una ONT que quedó a medias. */
    public function completarServicePort(OltOntRequest $request, int $oltId): JsonResponse
    {
        $r = $this->uc->completarServicePort($oltId, $request->validated());

        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /** Clientes que todavía no tienen una ONT vinculada. */
    public function clientesSinOnt(Request $request, int $oltId): JsonResponse
    {
        $r = $this->uc->clientesSinOnt($oltId, $request->query('q'));

        return standardApiReponse('Clientes sin ONT', $r, 0, JsonResponse::HTTP_OK);
    }

    /** ONT que quedaron a medio provisionar. */
    public function ontsIncompletas(int $oltId): JsonResponse
    {
        return standardApiReponse('ONT incompletas', $this->uc->ontsIncompletas($oltId), 0, JsonResponse::HTTP_OK);
    }

    public function deleteONT(OltOntRequest $request, int $oltId): JsonResponse
    {
        $r = $this->uc->deleteONT($oltId, $request->validated());
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function assignONT(OltOntRequest $request, int $oltId): JsonResponse
    {
        $r = $this->uc->assignONTToClient($oltId, $request->validated());
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function autoAssignONT(Request $request, int $oltId): JsonResponse
    {
        $r = $this->uc->autoAssignONT($oltId, $request->only(['fsp', 'ont_id', 'vlan', 'description']));
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    // ── New read operations ───────────────────────────────────────────────

    /**
     * GET /management/olt/{oltId}/onts
     * Returns all authorized/registered ONTs.
     */
    public function authorizedONTs(int $oltId, Request $request): JsonResponse
    {
        $force = $request->boolean('force', false);
        $r = $this->uc->getAuthorizedONTs($oltId, $force);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /**
     * GET /management/olt/{oltId}/ont/info?fsp=0/1/0&ont_id=0
     * Returns detailed info for a single ONT including optical power.
     */
    public function ontInfo(int $oltId, Request $request): JsonResponse
    {
        $fsp   = $request->query('fsp');
        $ontId = (int) $request->query('ont_id', 0);

        if (empty($fsp)) {
            return standardApiReponse('El parámetro fsp es requerido', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $r = $this->uc->getOntInfo($oltId, $fsp, $ontId);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /**
     * GET /management/olt/{oltId}/service-ports?fsp=0/1/0&ont_id=0
     * Returns service ports, optionally filtered by fsp+ont_id.
     */
    public function servicePorts(int $oltId, Request $request): JsonResponse
    {
        $fsp   = $request->query('fsp') ?: null;
        $ontId = $request->has('ont_id') ? (int) $request->query('ont_id') : null;

        $r = $this->uc->getServicePorts($oltId, $fsp, $ontId);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    // ── New write operations ──────────────────────────────────────────────

    /**
     * GET /management/olt/{oltId}/profiles
     * Returns line and srv profiles stored in DB for this OLT.
     */
    public function getProfiles(int $oltId): JsonResponse
    {
        $r = $this->uc->getProfiles($oltId);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /**
     * POST /management/olt/{oltId}/profiles/sync
     * Fetches profiles from OLT via Telnet and saves to DB.
     */
    public function syncProfiles(int $oltId): JsonResponse
    {
        $r = $this->uc->syncProfiles($oltId);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /**
     * POST /management/olt/{oltId}/ont/transfer
     * Body: { from_fsp: "0/1/0", ont_id: 0, to_fsp: "0/1/2" }
     */
    public function transferONT(Request $request, int $oltId): JsonResponse
    {
        $request->validate([
            'from_fsp' => 'required|string',
            'ont_id'   => 'required|integer',
            'to_fsp'   => 'required|string',
        ]);

        $r = $this->uc->transferONT($oltId, $request->only(['from_fsp', 'ont_id', 'to_fsp']));
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /**
     * POST /management/olt/{oltId}/ont/deactivate
     * Body: { fsp: "0/1/0", ont_id: 0 }
     */
    public function deactivateONT(Request $request, int $oltId): JsonResponse
    {
        $request->validate([
            'fsp'    => 'required|string',
            'ont_id' => 'required|integer',
        ]);

        $r = $this->uc->deactivateONT($oltId, $request->only(['fsp', 'ont_id']));
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /**
     * POST /management/olt/{oltId}/ont/activate
     * Body: { fsp: "0/1/0", ont_id: 0 }
     */
    public function activateONT(Request $request, int $oltId): JsonResponse
    {
        $request->validate([
            'fsp'    => 'required|string',
            'ont_id' => 'required|integer',
        ]);

        $r = $this->uc->activateONT($oltId, $request->only(['fsp', 'ont_id']));
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    public function cliCommand(Request $request, int $oltId): JsonResponse
    {
        $command = trim($request->input('command', ''));
        if (empty($command)) {
            return standardApiReponse('Comando requerido', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        $r = $this->uc->cliCommand($oltId, $command);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /**
     * POST /management/olt/{oltId}/ont/assign-client
     * Body: { fsp, ont_id, user_data_id (nullable) }
     */
    public function assignClientToOnt(Request $request, int $oltId): JsonResponse
    {
        $request->validate([
            'fsp'          => 'required|string',
            'ont_id'       => 'required|integer',
            'user_data_id' => 'nullable|integer',
        ]);

        $r = $this->uc->assignClientToOnt(
            $oltId,
            $request->input('fsp'),
            (int) $request->input('ont_id'),
            $request->input('user_data_id') !== null ? (int) $request->input('user_data_id') : null,
        );
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }

    /**
     * GET /management/olt/ont/by-user/{userId}
     * Returns the ONT assigned to the given user_data.id
     */
    public function getOntByUser(int $userId): JsonResponse
    {
        $r = $this->uc->getOntByUserId($userId);
        return standardApiReponse($r['message'], $r['data'], $r['status'], JsonResponse::HTTP_OK);
    }
}
