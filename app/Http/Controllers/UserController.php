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
use App\UseCases\User\Interfaces\GeneratePdfUseCaseInterface;
use Dompdf\Dompdf;
use Dompdf\Options;


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
    public function UpdateUserData(
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
     * @param GeneratePdfUseCaseInterface $generatePdfUseCaseInterface
     * @return object
     */
    public function generatePdf(
        GeneratePdfUseCaseInterface $generatePdfUseCaseInterface
    ): object {
        try {
            $pdfContents2 = $generatePdfUseCaseInterface->generatePdf();



            $i = 0;
            foreach ($pdfContents2 as $user) {
                // Genera el PDF y agrega el contenido al array
                //     // Genera el PDF individual y agrega el contenido al array
                $nombreArchivo = 'archivo_' . $i . '.pdf';

                // Genera el PDF individual y almacénalo en el array junto con su nombre de archivo
                $pdfFiles[] = [
                    'nombre_archivo' => $nombreArchivo,
                    'contenido' => $this->generateIndividualPdf($i),
                ];
                $i++;
            }
            // $i = 0;
            // while ($i < 2) { // Cambia 3 al número de veces que deseas ejecutar el bucle
            //     // Genera un nombre de archivo único para cada PDF

            //     // Genera el PDF individual y agrega el contenido al array
            //     $nombreArchivo = 'archivo_' . $i . '.pdf';

            //     // Genera el PDF individual y almacénalo en el array junto con su nombre de archivo
            //     $pdfFiles[] = [
            //         'nombre_archivo' => $nombreArchivo,
            //         'contenido' => $this->generateIndividualPdf($pdfContents2),
            //     ];
            // }

            // Prepara una respuesta con los PDFs como archivos adjuntos
            $response = new \Illuminate\Http\Response();
            $response->header('Content-Type', 'application/zip');
            $response->header('Content-Disposition', 'attachment; filename="archivos.zip"');

            $zip = new \ZipArchive();
            $zipFileName = storage_path('app/archivos.zip');

            if ($zip->open($zipFileName, \ZipArchive::CREATE) === true) {
                foreach ($pdfFiles as $pdfFile) {
                    $zip->addFromString($pdfFile['nombre_archivo'], $pdfFile['contenido']);
                }
                $zip->close();
            }

            $response->setContent(file_get_contents($zipFileName));
            return $response;
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
    private function generateIndividualPdf($i)
    {
        // Crea un PDF individual y devuelve su contenido
    // Aquí puedes usar Dompdf, TCPDF, o cualquier otra biblioteca de tu elección
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);

    // Crea una instancia de Dompdf, TCPDF u otra biblioteca
    $pdf = new Dompdf($options);

    // Agrega contenido al PDF personalizado (por ejemplo, el nombre del usuario)
    $pdf->loadHtml('<h1>¡Hola, ' . $i . ', este es tu PDF generado dinámicamente!</h1>');

    // Renderiza el PDF
    $pdf->render();

    // Devuelve el contenido del PDF generado
    return $pdf->output();
    }
}
