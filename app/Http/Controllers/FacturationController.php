<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Constants\StatusConstants;
use App\Constants\TimeConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Http\Requests\Facturation\GetDateFacturePendingnRequest;
use App\UseCases\Facturation\Interfaces\CreateAboneFacturationUseCaseInterface;
use App\UseCases\Facturation\Interfaces\CreateDetFacturationUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDateFacturePendingUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDataInfoPenddingFactureUseCaseInterface;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\JsonResponse;
use App\UseCases\Facturation\Interfaces\CreatePaidFacturationUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDatePayFactureUseCaseInterface;

class FacturationController extends Controller
{

    /**
     * @param CreateFacturationRequest $createFacturationRequest
     * @param CreateDetFacturationUseCaseInterface $createDetFacturationUseCaseInterface
     * @return object
     */
    public function updateDetFacturation(
        CreateFacturationRequest $createFacturationRequest,
        CreateDetFacturationUseCaseInterface $createDetFacturationUseCaseInterface
    ): object {
        try {
            $createDetFacturations = $createDetFacturationUseCaseInterface->updateDetFacturation($createFacturationRequest);
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
        GetDateFacturePendingnRequest $GetDateFacturePendingnRequest,
        GetDateFacturePendingUseCaseInterface $getDateFacturePendingUseCaseInterface
   
    ): object {
        try {
            $result = $getDateFacturePendingUseCaseInterface->getDateFacturePending($GetDateFacturePendingnRequest);
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
     * @param GetDatePayFactureUseCaseInterface $getDatePayFactureUseCaseInterface
     * @return object
     */
    public function getDatePayFacture(
        GetDateFacturePendingnRequest $GetDateFacturePendingnRequest,
        GetDatePayFactureUseCaseInterface $getDatePayFactureUseCaseInterface
   
    ): object {
        try {
            $result = $getDatePayFactureUseCaseInterface->getDatePayFacture($GetDateFacturePendingnRequest);
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


    /**
     * @param CreatePaidFacturationRequest $createPaidFacturationRequest
     * @param CreatePaidFacturationUseCaseInterface $createPaidFacturationUseCaseInterface
     * @return object
     */
    public function createpaidFacturation(
        CreatePaidFacturationRequest $createPaidFacturationRequest,
        CreatePaidFacturationUseCaseInterface $createPaidFacturationUseCaseInterface
    ): object {
        try {
            $result = $createPaidFacturationUseCaseInterface->createpaidFacturation($createPaidFacturationRequest);
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
     * @param CreatePaidFacturationRequest $createPaidFacturationRequest
     * @param CreateAboneFacturationUseCaseInterface $createAboneFacturationUseCaseInterface
     * @return object
     */
    public function createDiscountFacturation(
        CreatePaidFacturationRequest $createPaidFacturationRequest,
        CreateAboneFacturationUseCaseInterface $createAboneFacturationUseCaseInterface
    ): object {
        try {
            $createDetFacturations = $createAboneFacturationUseCaseInterface->createAboneFacturation($createPaidFacturationRequest);
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
}
