<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\UseCases\Gender\Interfaces\GetGenderUseCaseInterface;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Exceptions\JWTException;


class GenderController extends Controller
{
    /**
     * @param GetGenderUseCaseInterface $getGenderUseCaseInterface
     * @return object
     */
    public function getGenderAll(
        GetGenderUseCaseInterface $getGenderUseCaseInterface
    ): object {
        try {
            $result = $getGenderUseCaseInterface->getGenderAll();
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
