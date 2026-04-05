<?php

namespace App\UseCases\Search;

use App\Repositories\Interfaces\SearchRepositoryInterface;
use App\UseCases\Search\Interfaces\SearchFinancesPaidUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Http\Requests\Search\SearchRequest;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class SearchFinancesPaidUseCase implements SearchFinancesPaidUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param SearchRepositoryInterface $searchRepositoryInterface
     */
    public function __construct(
        private SearchRepositoryInterface $searchRepositoryInterface
    ) {
    }

    /**
     * @return mixed
     */
    public function SearchFinancesPaid(SearchRequest $searchRequest): mixed
    {
        try {
            if(true){
                $getDniAll = $this->searchRepositoryInterface->SearchFinancesPaid($searchRequest);
            }else{
                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrio un error al consultar los generos disponibles',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $getDniAll 
        ];
    }
}
