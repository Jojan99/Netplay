<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\UseCases\Egresos\Interfaces\CreateEgresosUseCaseInterface;
use App\Http\Requests\Egresos\CreateEgresosRequest;
use App\UseCases\Egresos\Interfaces\GetEgresosUseCaseInterface;
use App\UseCases\Egresos\Interfaces\GetPriceEgresseUseCaseInterface;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Exceptions\JWTException;


class EgresosController extends Controller
{
    /**
     * @param CreateEgresosUseCaseInterface $createEgresosUseCaseInterface
     * @return object
     */
    public function createEgresos(
        CreateEgresosRequest $createEgresosRequest,
        CreateEgresosUseCaseInterface $createEgresosUseCaseInterface

    ): object {
        try {
            $result = $createEgresosUseCaseInterface->createEgresos($createEgresosRequest);
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
     * @param GetEgresosUseCaseInterface $getEgresosUseCaseInterface
     * @return object
     */
    public function getEgresosAll(
        GetEgresosUseCaseInterface $getEgresosUseCaseInterface
    ): object {
        try {
            $result = $getEgresosUseCaseInterface->getEgresosAll();
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
     * @param GetPriceEgresseUseCaseInterface $getPriceEgresseUseCaseInterface
     * @return object
     */
    public function getPriceEgresseAll(
        GetPriceEgresseUseCaseInterface $getPriceEgresseUseCaseInterface
    ): object {
        try {
            $result = $getPriceEgresseUseCaseInterface->getPriceEgresseAll();
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
}