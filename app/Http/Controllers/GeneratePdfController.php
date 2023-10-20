<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdUseCaseInterface;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\GeneratePdf\GeneratePdfDataRequest;


class GeneratePdfController extends Controller
{

  /**
     * @param GeneratePdfUseCaseInterface $generatePdfUseCaseInterface
     * @return object
     */
    public function generatePdf(
        GeneratePdfUseCaseInterface $generatePdfUseCaseInterface,
        GeneratePdfDataRequest $request
    ): object {
        try {

            $data = $request->validate([
                'ids' => 'required|array',
            ]);

            $response = $generatePdfUseCaseInterface->generatePdf($data);

            // Verifica si la respuesta es un objeto Response
            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }
            // Si no es una respuesta HTTP, entonces asume que es una respuesta de error
            return response()->json($response, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
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
            $response['message'],
            $response['data'],
            $response['status'],
            JsonResponse::HTTP_OK
        );
    }

    /**
     * @param GeneratePdfUseCaseInterface $generatePdfUseCaseInterface
     * @param int id_user
     * @return object
     */
    public function generatePdfbyId(
        GeneratePdfUseCaseInterface $generatePdfUseCaseInterface,
        GeneratePdfByIdUseCaseInterface $generatePdfByIdUseCaseInterface,
        $user_id
        
    ): object {
        try {
            $response = $generatePdfByIdUseCaseInterface->generatePdfById($user_id);

            // Verifica si la respuesta es un objeto Response
            if ($response instanceof \Illuminate\Http\Response) {
                return $response;
            }
            // Si no es una respuesta HTTP, entonces asume que es una respuesta de error
            return response()->json($response, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
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
            $response['message'],
            $response['data'],
            $response['status'],
            JsonResponse::HTTP_OK
        );
    }
    
}
