<?php

namespace App\Http\Controllers\Client;

use App\Constants\ApiResponseConstants;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Autenticación del portal de cliente.
 * Solo permite login a usuarios con profile_id = 1 (CLIENT/USER).
 * Devuelve el token JWT + datos del cliente (user_data).
 */
class ClientAuthController extends Controller
{
    /**
     * POST /api/client/login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ], [
            'username.required' => 'El usuario es requerido',
            'password.required' => 'La contraseña es requerida',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos incompletos',
                'data'    => $validator->errors(),
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $credentials = [
            'username' => $request->username,
            'password' => $request->password,
            'active'   => 1,
        ];

        try {
            $token = JWTAuth::attempt($credentials);

            if (!$token) {
                return response()->json([
                    'message' => 'Credenciales incorrectas',
                    'data'    => null,
                    'status'  => ApiResponseConstants::ERROR,
                ], JsonResponse::HTTP_OK);
            }
        } catch (JWTException $e) {
            return response()->json([
                'message' => 'Error al crear la sesión: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        $user = JWTAuth::user();

        // Validar por nombre del perfil (cada empresa tiene su propio profile_id para 'USER')
        $profileName = DB::table('profiles')
            ->where('id', $user->profile_id)
            ->value('name');

        if (strtoupper($profileName ?? '') !== 'USER') {
            JWTAuth::invalidate($token);
            return response()->json([
                'message' => 'Este portal es exclusivo para clientes',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        // Cargar datos del cliente y empresa
        $userData = DB::table('user_data')
            ->where('user_id', $user->id)
            ->first();

        $company = DB::table('companies')
            ->where('id', $user->company_id)
            ->select('id', 'name', 'slug', 'logo', 'phone', 'email', 'address')
            ->first();

        return response()->json([
            'message' => 'Sesión iniciada correctamente',
            'data'    => [
                'access_token' => $token,
                'expires_in'   => config('jwt.ttl') * 60,
                'user_id'      => $user->id,
                'company_id'   => $user->company_id,
                'profile_id'   => $user->profile_id,
                'client'       => $userData,
                'company'      => $company,
            ],
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * GET /api/client/me
     * Retorna los datos del cliente autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        $user = JWTAuth::user();

        $userData = DB::table('user_data')
            ->where('user_id', $user->id)
            ->first();

        $company = DB::table('companies')
            ->where('id', $user->company_id)
            ->select('id', 'name', 'slug', 'logo', 'phone', 'email', 'address')
            ->first();

        return response()->json([
            'message' => 'OK',
            'data'    => [
                'user_id'    => $user->id,
                'company_id' => $user->company_id,
                'client'     => $userData,
                'company'    => $company,
            ],
            'status' => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/client/logout
     */
    public function logout(): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (JWTException $e) {
            // Token ya inválido — no es error crítico
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
            'data'    => null,
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }
}
