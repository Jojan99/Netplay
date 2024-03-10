<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Constants\StatusConstants;
use App\Constants\TimeConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\UseCases\Facturation\Interfaces\CreateDetFacturationUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDateFacturePendingUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDataInfoPenddingFactureUseCaseInterface;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\JsonResponse;

class FacturationController extends Controller
{

    /**
     * @param CreateFacturationRequest $createFacturationRequest
     * @param CreateDetFacturationUseCaseInterface $createDetFacturationUseCaseInterface
     * @return object
     */
    public function createDetFacturation(
        CreateFacturationRequest $createFacturationRequest,
        CreateDetFacturationUseCaseInterface $createDetFacturationUseCaseInterface
    ): object {
        try {
            $createDetFacturations = $createDetFacturationUseCaseInterface->createDetFacturation($createFacturationRequest);
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
            $createDetFacturations['message'],
            $createDetFacturations['data'],
            $createDetFacturations['status'],
            JsonResponse::HTTP_OK
        );
    }

       /**
     * @param GetDateFacturePendingUseCaseInterface $getDateFacturePendingUseCaseInterface
     * @return object
     */
    public function getDateFacturePending(
        GetDateFacturePendingUseCaseInterface $getDateFacturePendingUseCaseInterface
    ): object {
        try {
            $result = $getDateFacturePendingUseCaseInterface->getDateFacturePending();
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
     * @param GetDataInfoPenddingFactureUseCaseInterface $getDataInfoPenddingFactureUseCaseInterface
     * @return object
     */
    public function getDataInfoPenddingFacture(
        GetDataInfoPenddingFactureUseCaseInterface $getDataInfoPenddingFactureUseCaseInterface
    ): object {
        try {
            $result = $getDataInfoPenddingFactureUseCaseInterface->getDataInfoPenddingFacture();
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
