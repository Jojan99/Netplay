<?php

namespace App\Http\Controllers\Client;

use App\Constants\ApiResponseConstants;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Perfil del cliente: ver y actualizar datos de contacto.
 */
class ClientProfileController extends Controller
{
    /**
     * GET /api/client/profile
     * Retorna los datos completos del cliente (user_data + plan de internet).
     */
    public function show(): JsonResponse
    {
        $user = JWTAuth::user();

        $profile = DB::table('user_data as ud')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'ud.status_internet_id')
            ->leftJoin('genders as g', 'g.id', '=', 'ud.gender_id')
            ->leftJoin('countries as c', 'c.id', '=', 'ud.country_id')
            ->leftJoin('dnis as d', 'd.id', '=', 'ud.dni_id')
            ->where('ud.user_id', $user->id)
            ->select(
                'ud.id',
                'ud.names',
                'ud.lastname',
                'ud.address',
                'ud.dni',
                'ud.email',
                'ud.phone',
                'ud.birthday',
                'ud.active',
                'ud.status',
                'g.name as gender',
                'c.name as country',
                'd.name as dni_type',
                'ip.plan_name',
                'ip.download_speed as plan_download',
                'ip.upload_speed as plan_upload',
                'ip.monthly_price as plan_price',
                'ist.name as internet_status'
            )
            ->first();

        if (!$profile) {
            return response()->json([
                'message' => 'Perfil no encontrado',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return response()->json([
            'message' => 'Perfil obtenido correctamente',
            'data'    => $profile,
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * PUT /api/client/profile
     * Actualiza los datos de contacto del cliente (solo campos permitidos).
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone'   => 'sometimes|string|max:20',
            'address' => 'sometimes|string|max:255',
            'email'   => 'sometimes|email|max:150',
        ], [
            'email.email' => 'El correo electrónico no es válido',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'data'    => $validator->errors(),
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = JWTAuth::user();

        $updatable = $request->only(['phone', 'address', 'email']);

        if (empty($updatable)) {
            return response()->json([
                'message' => 'No hay datos para actualizar',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $updatable['updated_at'] = now();

        DB::table('user_data')
            ->where('user_id', $user->id)
            ->update($updatable);

        return response()->json([
            'message' => 'Perfil actualizado correctamente',
            'data'    => null,
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }
}
