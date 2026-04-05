<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Internet\InternetIpRequest;
use App\UseCases\InfoInternet\Interfaces\GetDataCorteUseCaseInterface;
use App\UseCases\InfoInternet\Interfaces\GetInternetPlanUseCaseInterface;
use App\UseCases\InfoInternet\Interfaces\GetIpAllByIdZoneUseCaseInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;

class InternetInfoController extends Controller
{
    /**
     * @param GetInternetPlanUseCaseInterface $getInternetPlanUseCaseInterface
     * @return object
     */
    public function getInternetPlanAll(
        GetInternetPlanUseCaseInterface $getInternetPlanUseCaseInterface
    ): object {
        try {
            $result = $getInternetPlanUseCaseInterface->getInternetPlanAll();
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
     * @param GetDataCorteUseCaseInterface $getDataCorteUseCaseInterface
     * @return object
     */
    public function getDataCorteAll(
        GetDataCorteUseCaseInterface $getDataCorteUseCaseInterface
    ): object {
        try {
            $result = $getDataCorteUseCaseInterface->getDataCorteAll();
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
     * @param GetIpAllByIdZoneUseCaseInterface $GetIpAllByIdZoneUseCaseInterface
     * @param InternetIpRequest internetIpRequest
     * @return object
     */
    public function getIpAllByIdZone(
        InternetIpRequest $internetIpRequest,
        GetIpAllByIdZoneUseCaseInterface $getIpAllByIdZoneUseCaseInterface
    ): object {
        try {
            $result = $getIpAllByIdZoneUseCaseInterface->getIpAllByIdZone($internetIpRequest);
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
