<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Constants\ApiResponseConstants;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\User\CreateUserDataRequest;
use App\UseCases\User\Interfaces\CreateUserDataUseCaseInterface;
use App\UseCases\User\Interfaces\UpdateUserDataUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserAllUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserByIdUseCaseInterface;
use App\UseCases\User\Interfaces\DeleteUserDatabyIdUseCaseInterface;
use App\UseCases\User\Interfaces\GetCountUserUseCaseInterface;
use App\UseCases\User\Interfaces\GetTotalPriceMonthUseCaseInterface;
use App\UseCases\User\Interfaces\GetTotalClientRegisterMonthUseCaseInterface;
use App\UseCases\User\Interfaces\GetTrazaFactureUseCaseInterface;

class UserController extends Controller
{


    /**
     * @param GetUserUseCaseInterface $getUserUseCaseInterface
     * @return object
     */
    public function getUserLoggedIn(
        GetUserUseCaseInterface $getUserUseCaseInterface
    ): object {
        try {
            $getUserLoggedIn = $getUserUseCaseInterface->getUserLoggedIn(getSessionUserName());
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
            'user data queried successfully',
            $getUserLoggedIn,
            ApiResponseConstants::SUCCESS
        );
    }

    /**
     * @param CreateUserDataRequest $createUserDataRequest
     * @param CreateUserDataUseCaseInterface $createUserDataUseCaseInterface
     * @return object
     */
    public function createUserData(
        CreateUserDataRequest $createUserDataRequest,
        CreateUserDataUseCaseInterface $createUserDataUseCaseInterface
    ): object {
        try {
            $createUserData = $createUserDataUseCaseInterface->createUserData($createUserDataRequest);
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
            $createUserData['message'],
            $createUserData['data'],
            $createUserData['status'],
            JsonResponse::HTTP_OK
        );
    }
    

    /**
     * @param CreateUserDataRequest $createUserDataRequest
     * @param int id
     * @param UpdateUserDataUseCaseInterface $updateUserDataUseCaseInterface
     * @return object
     */
    public function updateUserData(
        CreateUserDataRequest $createUserDataRequest,
        UpdateUserDataUseCaseInterface $updateUserDataUseCaseInterface
    ): object {
        try {
            $updateUserData = $updateUserDataUseCaseInterface->UpdateUserData($createUserDataRequest);
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
            $updateUserData['message'],
            $updateUserData['data'],
            $updateUserData['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param CreateUserDataRequest $createUserDataRequest
     * @param int id
     * @param UpdateUserDataUseCaseInterface $updateUserDataUseCaseInterface
     * @return object
     */
    public function DeleteUserData(
        int $id,
        DeleteUserDatabyIdUseCaseInterface $deleteUserDatabyIdUseCaseInterface

    ): object {
        try {
            $deleteUserData = $deleteUserDatabyIdUseCaseInterface->DeleteUserData($id);
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
            $deleteUserData['message'],
            $deleteUserData['data'],
            $deleteUserData['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param GetUserAllUseCaseInterface $getUserAllUseCaseInterface
     * @return object
     */
    public function getUserAll(
        GetUserAllUseCaseInterface $getUserAllUseCaseInterface
    ): object {
        try {
            $result = $getUserAllUseCaseInterface->getUserAll();
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
     * @param GetUserByIdUseCaseInterface $getUserByIdUseCaseInterface
     * @return object
     */
    public function getUserById(
        string $id,
        GetUserByIdUseCaseInterface $GetUserByIdUseCaseInterface
    ): object {
        if (!$id) {
            return standardApiReponse(
                'id parameter cannot be empty: ',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_OK
            );
        }
        try {
            $getUser = $GetUserByIdUseCaseInterface->getUserById($id);
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
            $getUser['message'],
            $getUser['data'],
            $getUser['status'],
            JsonResponse::HTTP_OK
        );
    }

     /**
     * @param GetUserByIdUseCaseInterface $getUserByIdUseCaseInterface
     * @return object
     */
    public function getUserByIdBost(
        string $id,
        GetUserByIdUseCaseInterface $GetUserByIdUseCaseInterface
    ): object {
        if (!$id) {
            return standardApiReponse(
                'id parameter cannot be empty: ',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_OK
            );
        }
        try {
            $getUser = $GetUserByIdUseCaseInterface->getUserByIdBost($id);
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
            $getUser['message'],
            $getUser['data'],
            $getUser['status'],
            JsonResponse::HTTP_OK
        );
    }


     /**
     * @param GetCountUserUseCaseInterface $getCountUserUseCaseInterface
     * @return object
     */
    public function getCountUser(
        GetCountUserUseCaseInterface $getCountUserUseCaseInterface
    ): object {
        try {
            $result = $getCountUserUseCaseInterface->getCountUser();
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
     * @param GetTotalClientRegisterMonthUseCaseInterface $getTotalClientRegisterMonthUseCaseInterface
     * @return object
     */
    public function GetTotalClientRegisterMonth(
        GetTotalClientRegisterMonthUseCaseInterface $getTotalClientRegisterMonthUseCaseInterface
    ): object {
        try {
            $result = $getTotalClientRegisterMonthUseCaseInterface->GetTotalClientRegisterMonth();
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
     * @param GetTotalPriceMonthUseCaseInterface $getTotalPriceMonthUseCaseInterface
     * @return object
     */
    public function getTotalPriceMonth(
        GetTotalPriceMonthUseCaseInterface $getTotalPriceMonthUseCaseInterface
    ): object {
        try {
            $result = $getTotalPriceMonthUseCaseInterface->getTotalPriceMonth();
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
     * @param GetTotalPriceMonthUseCaseInterface $getTotalPriceMonthUseCaseInterface
     * @return object
     */
    public function getTrazaFacture(
        GetTrazaFactureUseCaseInterface $getTrazaFactureUseCaseInterface
    ): object {
        try {
            $result = $getTrazaFactureUseCaseInterface->getTrazaFacture();
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
