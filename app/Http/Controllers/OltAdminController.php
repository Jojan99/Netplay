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

    // ── New read operations ───────────────────────────────────────────────

    /**
     * GET /management/olt/{oltId}/onts
     * Returns all authorized/registered ONTs.
     */
    public function authorizedONTs(int $oltId): JsonResponse
    {
        $r = $this->uc->getAuthorizedONTs($oltId);
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
}
