<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Constants\ApiResponseConstants;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
        Request $request,
        GetTotalClientRegisterMonthUseCaseInterface $getTotalClientRegisterMonthUseCaseInterface
    ): object {
        try {
            $year = (int) $request->query('year', (int) now()->year);
            $result = $getTotalClientRegisterMonthUseCaseInterface->GetTotalClientRegisterMonth($year);
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
        Request $request,
        GetTotalPriceMonthUseCaseInterface $getTotalPriceMonthUseCaseInterface
    ): object {
        try {
            $year = (int) $request->query('year', (int) now()->year);
            $result = $getTotalPriceMonthUseCaseInterface->getTotalPriceMonth($year);
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

    public function search(Request $request): object
    {
        $q = $request->query('q', '');
        $results = DB::table('user_data as ud')
            ->join('users', 'users.id', '=', 'ud.user_id')
            ->where('users.company_id', getSessionCompanyId())
            ->where(function ($query) use ($q) {
                $query->where('ud.names', 'like', "%{$q}%")
                      ->orWhere('ud.lastname', 'like', "%{$q}%")
                      ->orWhere('ud.dni', 'like', "%{$q}%")
                      ->orWhere('users.username', 'like', "%{$q}%");
            })
            ->select('ud.user_id as id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.phone', 'ud.email')
            ->limit(10)
            ->get();

        return standardApiReponse('ok', $results, 0, JsonResponse::HTTP_OK);
    }

    public function getAuditLog(int $user_id): object
    {
        $logs = DB::table('user_audit_logs')
            ->where('user_id', $user_id)
            ->where('company_id', getSessionCompanyId())
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();
        return standardApiReponse('ok', $logs, 0, JsonResponse::HTTP_OK);
    }

    public function exportUsers(): object
    {
        try {
            $users = DB::table('users')
                ->select(
                    'users.id',
                    'user_data.names',
                    'user_data.lastname',
                    'user_data.dni',
                    'user_data.phone',
                    'user_data.email',
                    'user_data.address',
                    'internet_status.name as status',
                    'internet_plans.plan_name',
                    DB::raw("COALESCE(tabla_ips.ip, '') as ip"),
                    'cab_facturations.group as billing_group',
                    'users.created_at'
                )
                ->join('user_data', 'users.id', '=', 'user_data.user_id')
                ->join('internet_status', 'user_data.status_internet_id', '=', 'internet_status.id')
                ->join('internet_plans', 'user_data.internet_plans_id', '=', 'internet_plans.id')
                ->join('cab_facturations', 'cab_facturations.user_id', '=', 'users.id')
                ->leftJoin('tabla_ips', 'tabla_ips.id', '=', 'user_data.ip_assignment_id')
                ->where('user_data.active', 1)
                ->where('users.company_id', getSessionCompanyId())
                ->get();
        } catch (JWTException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        return standardApiReponse('ok', $users, 0, JsonResponse::HTTP_OK);
    }
}
