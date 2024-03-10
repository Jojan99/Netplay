<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\UseCases\Location\Interfaces\LocationUseCaseInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;

class LocationController extends Controller
{
       /**
     * @param LocationUseCaseInterface $locationUseCaseInterface
     * @return object
     */
    public function getneighborhoodAll(
        LocationUseCaseInterface $locationUseCaseInterface
    ): object {
        try {
            $result = $locationUseCaseInterface->getneighborhoodAll();
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
