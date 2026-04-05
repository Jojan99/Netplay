<?php

namespace App\UseCases\Egresos;

use App\Constants\ApiResponseConstants;
use App\Constants\ProfileConstants;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use App\UseCases\Egresos\Interfaces\GetIngresosDetailedUseCaseInterface;
use Illuminate\Database\QueryException;

class GetIngresosDetailedUseCase implements GetIngresosDetailedUseCaseInterface
{
    public function __construct(
        private EgresosRepositoryInterface $egresosRepository
    ) {}

    public function getAll(?string $from, ?string $to): mixed
    {
        try {
            $profile = getSessionUserProfileId();

            if (!in_array($profile, [ProfileConstants::ADMIN, ProfileConstants::CONTADOR])) {
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
