<?php

namespace App\UseCases\Egresos;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use App\UseCases\Egresos\Interfaces\GetIngresosDetailedUseCaseInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class GetIngresosDetailedUseCase implements GetIngresosDetailedUseCaseInterface
{
    public function __construct(
        private EgresosRepositoryInterface $egresosRepository
    ) {}

    public function getAll(?string $from, ?string $to): mixed
    {
        try {
            $profileName = strtoupper(
                DB::table('profiles')->where('id', getSessionUserProfileId())->value('name') ?? ''
            );

            if (!in_array($profileName, ['ADMIN', 'CONTADOR'])) {
                return [
                    'message' => 'Acción no permitida',
                    'status'  => ApiResponseConstants::ERROR,
                    'data'    => null,
                ];
            }

            $data = $this->egresosRepository->getIngresosDetailed($from, $to);

        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrió un error al consultar los ingresos',
                'status'  => ApiResponseConstants::ERROR,
                'data'    => null,
            ];
        }

        return [
            'message' => 'Consulta realizada con éxito',
            'status'  => ApiResponseConstants::SUCCESS,
            'data'    => $data,
        ];
    }
}
