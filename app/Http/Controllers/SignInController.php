<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Constants\StatusConstants;
use App\Constants\TimeConstants;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Session\AuthenticateUserActiveRequest;
use App\UseCases\Oauth\Interfaces\ChangePasswordUseCaseInterface;
use App\UseCases\Oauth\Interfaces\SignInUseCaseInterface;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
/**
 * Clase controlador para las sesiones
 *
 * @package App\Http\Controllers
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/9
 */
class SignInController extends Controller
{
    /**
     * Método encargado de validar las credenciales de un usuario activo
     * y de retornar un token para la sesión de este
     *
     * @param AuthenticateUserActiveRequest $request
     * @param SignInUseCaseInterface        $SignInUseCaseInterface
     * @return object
     */
    public function signin(
        AuthenticateUserActiveRequest $request,
        // SignInUseCaseInterface       $SignInUseCaseInterface
    ): object {
        // Se arma el array de las credenciales con las que se va a validar
        $credentials = [
            'username' => $request->user,
            'active' => 1,
            'password' => $request->password
        ];

        try {
            // Se valida si no se pudo crear el token
            if (!$token = JWTAuth::attempt($credentials)) {
                return standardApiReponse(
                    'Credenciales no validas, revise su cuenta /o contraseña ',
                    ApiResponseConstants::DATA_NULL,
                    ApiResponseConstants::ERROR,
                    JsonResponse::HTTP_OK
                );
            }
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Failed to create token: '.$e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $authenticatedUser = JWTAuth::user();

        // Respuesta en caso de éxito
        return standardApiReponse(
            'The token has been created successfully',
            [
                'access_token' => $token,
                'userId'       => $authenticatedUser->id,
                'company_id'   => $authenticatedUser->company_id,
                'profile_id'   => $authenticatedUser->profile_id,
                'expires_in'   => config("jwt.ttl") * 60,
                'user' => [
                    'user'  => $request->user,
                    'email' => $authenticatedUser->email,
                ]
            ],
            ApiResponseConstants::SUCCESS
        );
    }
    
    /**
     * POST /api/oauth/changePassword
     * Cambia la contraseña del usuario autenticado.
     */
    public function changePassword(
        ChangePasswordRequest $request,
        ChangePasswordUseCaseInterface $changePasswordUseCase
    ): object {
        $result = $changePasswordUseCase->change($request);

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * GET /api/oauth/me
     * Retorna información del perfil del usuario autenticado.
     */
    public function getMe(): JsonResponse
    {
        $user = JWTAuth::user();

        $userData = DB::table('user_data')
            ->where('user_id', $user->id)
            ->select('names', 'lastname', 'phone', 'address', 'dni')
            ->first();

        $profileName = DB::table('profiles')
            ->where('id', $user->profile_id)
            ->value('name');

        $companyName = DB::table('companies')
            ->where('id', $user->company_id)
            ->value('name');

        return standardApiReponse('OK', [
            'id'           => $user->id,
            'username'     => $user->username,
            'email'        => $user->email,
            'names'        => $userData->names    ?? '',
            'lastname'     => $userData->lastname ?? '',
            'phone'        => $userData->phone    ?? '',
            'address'      => $userData->address  ?? '',
            'dni'          => $userData->dni      ?? '',
            'profile_id'   => $user->profile_id,
            'profile_name' => $profileName        ?? '',
            'company_name' => $companyName        ?? '',
            'created_at'   => $user->created_at,
        ], 0, JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/oauth/profile
     * Actualiza datos personales del usuario autenticado.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = JWTAuth::user();

        // Update email on users table if provided
        if ($request->filled('email')) {
            DB::table('users')->where('id', $user->id)->update(['email' => $request->input('email')]);
        }

        // Update user_data fields
        DB::table('user_data')->where('user_id', $user->id)->update(array_filter([
            'names'    => $request->input('names'),
            'lastname' => $request->input('lastname'),
            'phone'    => $request->input('phone'),
        ], fn($v) => $v !== null));

        return standardApiReponse('Perfil actualizado.', null, 0, JsonResponse::HTTP_OK);
    }

    /**
     * Método encargado de invalidar un token (logout)
     *
     * @return object
     */
    public function logout(): object
    {
        try {
            // Se obtiene y se desactiva el token
            JWTAuth::invalidate(JWTAuth::getToken());

            // Se borra la sesión del usuario
            session()->flush();

            // Respuesta en caso de éxito
            return standardApiReponse(
                'The token has been successfully inactivated',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::SUCCESS
            );
        } catch (JWTException $e) {
            // Respuesta en caso de excepción
            return standardApiReponse(
                'Failed to deactivate token: '.$e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

}
