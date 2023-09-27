<?php

namespace App\UseCases\User;

use App\Http\Resources\GetUserData;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\UseCases\User\Interfaces\GeneratePdfUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GeneratePdfUseCase implements GeneratePdfUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param GeneratePdfRepositoryInterface $generatePdfRepository
     */
    public function __construct(
        private GeneratePdfRepositoryInterface $generatePdfRepository
    ) {
    }

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     * @param string $userName
     * @return mixed
     */
    public function generatePdf(): mixed
    {
        try {
           $generatePdf = $this->generatePdfRepository->generatePdf();

           error_log("generatePdf".json_encode($generatePdf));

        //    $pdfContents = []; // Array para almacenar el contenido de los PDF

        //    foreach ($generatePdf as $user) {
        //        // Genera el PDF y agrega el contenido al array
        //        $pdfContents[] = $this->generateIndividualPdf($user);
        //        error_log("count " . json_encode(count($pdfContents))); // Verificar la cantidad de PDF generados
        //    }
    
        //    error_log("pdfContents".json_encode($pdfContents));
        } catch (QueryException $err) {
            return [
                'message' => 'Ha ocurrido un error al geerar el pdf',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }
        return ['message' => 'Pdf generado con exito', 'status' => 0, 'data' => $generatePdf];
    }


}
