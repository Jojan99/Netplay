<?php

namespace App\UseCases\Egresos;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use App\UseCases\Egresos\Interfaces\GetPriceEgresseUseCaseInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
