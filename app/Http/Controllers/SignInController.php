<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Constants\StatusConstants;
use App\Constants\TimeConstants;
use App\Http\Requests\Session\AuthenticateUserActiveRequest;
use App\UseCases\Oauth\Interfaces\SignInUseCaseInterface;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\JsonResponse;
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

        //Se consulta los datos del usuario
        // $user = $SignInUseCaseInterface->getDataUserByUserName($request->user);

        // Respuesta en caso de éxito
        return standardApiReponse(
            'The token has been created successfully',
            [
                'access_token' => $token,
                'userId' => 1,
                'expires_in' => config("jwt.ttl") * 60,
                'user' => [
                    'user' => $request->user,
                    'email' => "johndoe@example.com",
                ]
            ],
            ApiResponseConstants::SUCCESS
        );
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
