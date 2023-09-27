<?php

use App\Constants\ApiResponseConstants;
use Illuminate\Http\JsonResponse;

if (!function_exists('standardApiReponse')) {
    /**
     * Función encargada de establecer la respuesta estandar para una solicitud 
     * api
     * 
     * @param string $message
     * @param int    $error
     * @param int    $status_code
     * @return object
     */
    function standardApiReponse(
        string $message = '',
        $data = null,
        int    $error = ApiResponseConstants::SUCCESS,
        int    $status_code = JsonResponse::HTTP_OK
    ): object {
        return response()->json([
            'message' => $message,
            'data' => $data,
            'error' => $error,
        ], $status_code);
    }
}
