<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdFacturesUseCaseInterface;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\GeneratePdf\GeneratePdfDataRequest;
use App\UseCases\GeneratePdf\Interfaces\GeneratePayPdfByIdFacturesUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfTicketByIdUseCaseInterface;
use Illuminate\Http\Request;
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

            
            $data = [
                'ids' => [3]
            ];

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
        GeneratePdfByIdFacturesUseCaseInterface $generatePdfByIdFacturesUseCaseInterface,
        $user_id
    ): object {
        try {
            $response = $generatePdfByIdFacturesUseCaseInterface->generatePdfByIdFacture($user_id);

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
     * @param GeneratePdfTicketByIdUseCaseInterface $generatePdfTicketByIdUseCaseInterface
     * @param int id_user
     * @return object
     */
    public function generatePdfTicketbyId(
        GeneratePdfTicketByIdUseCaseInterface $generatePdfTicketByIdUseCaseInterface,
        $user_id
    ): object {
        try {
            $response = $generatePdfTicketByIdUseCaseInterface->generatePdfTicketbyId($user_id);

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

    public function generatePaidPdfbyId(
        GeneratePayPdfByIdFacturesUseCaseInterface $generatePayPdfByIdFacturesUseCaseInterface,
        $id_facture, Request $request
    ): object {
        try {


            $idFacture = $id_facture;

            // Acceder al parámetro de consulta 'extraParam'
            $extraParam = $request->query('extraParam');

            error_log(">>>>>>>>>>>>>>><<<<<".$extraParam);

            $response = $generatePayPdfByIdFacturesUseCaseInterface->generatePayPdfByIdFacture($id_facture,$extraParam);

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

        return $response;
    }
    
}
