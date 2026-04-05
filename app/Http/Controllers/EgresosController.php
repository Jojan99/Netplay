<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Egresos\CreateEgresosRequest;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use App\UseCases\Egresos\Interfaces\CreateEgresosUseCaseInterface;
use App\UseCases\Egresos\Interfaces\GetEgresosUseCaseInterface;
use App\UseCases\Egresos\Interfaces\GetIngresosDetailedUseCaseInterface;
use App\UseCases\Egresos\Interfaces\GetPriceEgresseUseCaseInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EgresosController extends Controller
{
    public function createEgresos(
        CreateEgresosRequest $createEgresosRequest,
        CreateEgresosUseCaseInterface $createEgresosUseCaseInterface
    ): object {
        $result = $createEgresosUseCaseInterface->createEgresos($createEgresosRequest);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getEgresosAll(
        GetEgresosUseCaseInterface $getEgresosUseCaseInterface
    ): object {
        $result = $getEgresosUseCaseInterface->getEgresosAll();
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getPriceEgresseAll(
        GetPriceEgresseUseCaseInterface $getPriceEgresseUseCaseInterface
    ): object {
        $result = $getPriceEgresseUseCaseInterface->getPriceEgresseAll();
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getPriceEgresseByRange(
        Request $request,
        GetPriceEgresseUseCaseInterface $getPriceEgresseUseCaseInterface
    ): object {
        $result = $getPriceEgresseUseCaseInterface->getPriceEgresseByRange(
            $request->query('from'),
            $request->query('to')
        );
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getIngresosDetailed(
        Request $request,
        GetIngresosDetailedUseCaseInterface $useCase
    ): object {
        $result = $useCase->getAll(
            $request->query('from'),
            $request->query('to')
        );
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    // ── Egresos v2 ──────────────────────────────────────────────────────────

    public function listPaginated(Request $request, EgresosRepositoryInterface $repo): object
    {
        $data = $repo->getEgresosPaginated(
            $request->query('search'),
            $request->query('from'),
            $request->query('to'),
            $request->query('category'),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 15)
        );
        return standardApiReponse('OK', $data, 0, JsonResponse::HTTP_OK);
    }

    public function createEgresoV2(Request $request, EgresosRepositoryInterface $repo): object
    {
        $result = $repo->createEgresoV2($request->all());
        return standardApiReponse('Egreso creado', $result, 0, JsonResponse::HTTP_OK);
    }

    public function updateEgreso(Request $request, int $id, EgresosRepositoryInterface $repo): object
    {
        $ok = $repo->updateEgreso($id, $request->all());
        return standardApiReponse($ok ? 'Egreso actualizado' : 'No encontrado', null, $ok ? 0 : 1, JsonResponse::HTTP_OK);
    }

    public function deleteEgreso(int $id, EgresosRepositoryInterface $repo): object
    {
        $ok = $repo->deleteEgreso($id);
        return standardApiReponse($ok ? 'Egreso eliminado' : 'No encontrado', null, $ok ? 0 : 1, JsonResponse::HTTP_OK);
    }

    public function exportEgresosCSV(Request $request, EgresosRepositoryInterface $repo)
    {
        $rows = $repo->exportEgresos($request->query('from'), $request->query('to'));
        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['#ID', 'Concepto', 'Categoría', 'Valor', 'Fecha']);
            foreach ($rows as $r) {
                $r = (array) $r;
                fputcsv($h, [$r['id'] ?? '', $r['concept'] ?? '', $r['category'] ?? '', $r['value'] ?? 0, $r['created_at'] ?? '']);
            }
            fclose($h);
        }, 'egresos_' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
