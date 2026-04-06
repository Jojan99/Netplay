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
}
