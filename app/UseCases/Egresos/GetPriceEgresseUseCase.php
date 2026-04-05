<?php

namespace App\UseCases\Egresos;

use App\Constants\ApiResponseConstants;
use App\Constants\ProfileConstants;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use App\UseCases\Egresos\Interfaces\GetPriceEgresseUseCaseInterface;
use Illuminate\Database\QueryException;

class GetPriceEgresseUseCase implements GetPriceEgresseUseCaseInterface
{
    public function __construct(
        private EgresosRepositoryInterface $egresosRepository
    ) {}

    public function getPriceEgresseAll(): mixed
    {
        return $this->getPriceEgresseByRange(null, null);
    }

    public function getPriceEgresseByRange(?string $from, ?string $to): mixed
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

            $data = $this->egresosRepository->getPriceEgresseByRange($from, $to);

        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrió un error al consultar los ingresos y egresos',
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
