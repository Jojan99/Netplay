<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Search\SearchRequest;
use App\UseCases\Search\Interfaces\SearchFinancesPaidUseCaseInterface;
use App\UseCases\Search\Interfaces\SearchUseCaseInterface;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Exceptions\JWTException;

class SearchController extends Controller
{
    /**
     * @param SearchUseCaseInterface $searchUseCaseInterface
     * @return object
     */
    public function getSearchUseCase(
        SearchRequest $searchRequest,
        SearchUseCaseInterface $searchUseCaseInterface
    ): object {
        try {
            $result = $searchUseCaseInterface->getSearchUseCase($searchRequest);
        } catch (JWTException $e) {
            
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param SearchFinancesPaidUseCaseInterface $SearchFinancesPaidUseCaseInterface
     * @return object
     */
    public function SearchFinancesPaid(
        SearchRequest $searchRequest,
        SearchFinancesPaidUseCaseInterface $SearchFinancesPaidUseCaseInterface
    ): object {
        try {
            $result = $SearchFinancesPaidUseCaseInterface->SearchFinancesPaid($searchRequest);
        } catch (JWTException $e) {
            
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Currency rates could not be queried: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }


        // Enviar la consulta y recibir la respuesta

}
