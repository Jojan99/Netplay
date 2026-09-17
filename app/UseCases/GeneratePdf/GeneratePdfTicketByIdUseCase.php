<?php

namespace App\UseCases\GeneratePdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Resources\Templates\TemplatePdfOrderWork;
use App\Resources\Templates\TemplatesPdf;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdFacturesUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfTicketByIdUseCaseInterface;

use function Laravel\Prompts\error;

/**
 *
 * @package App\UseCases\GeneratePdf
 * @author NetPlay <atencionalcliente@netplay.com.co
 * @copyright 2023/09/29
 */
class GeneratePdfTicketByIdUseCase implements GeneratePdfTicketByIdUseCaseInterface
{
  /**
   * Constructor de la clase
   *
   * @param GeneratePdfRepositoryInterface $generatePdfRepository
   */
  public function __construct(
    private GeneratePdfRepositoryInterface $generatePdfRepository,
    private TemplatesPdf $templatesPdf
  ) {
  }

  public function generatePdfTicketById($id): mixed
  {
    error_log($id);
    if(sessionUserHasProfile('CONTADOR', 'ADMIN')){
      try {
        // Obtener los datos del usuario y generar el PDF
        $generatePdf = $this->generatePdfRepository->getTicketInProgressAll($id);
        if ($generatePdf->isEmpty()) {
          return ['message' => 'Ticket no encontrado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }




        // Crear una instancia de Dompdf y cargar el contenido HTML
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $pdf = new Dompdf($options);
  
        $html = $this->generateIndividualPdf($generatePdf);
  
  
        $pdf->loadHtml($html);
  
        // Renderizar el PDF
        $pdf->render();
  
        // Devolver el contenido del PDF generado como respuesta HTTP
        $output = $pdf->output();
  
        return response($output)
          ->header('Content-Type', 'application/pdf')
          ->header('Content-Disposition', 'attachment; filename="' . '.pdf"');
      } catch (QueryException $err) {
        return [
          'message' => 'Ha ocurrido un error al generar el PDF',
          'status' => 1,
          'data' => ApiResponseConstants::DATA_NULL
        ];
      }
  
      return ['message' => 'PDF generado con éxito', 'status' => 0]; 
    }else{
      return ['message' => 'No tienes permiso para realizar esta accion', 'status' => 0]; 
    }
  }


  private function generateIndividualPdf($user)
  {
    $pdfT = new TemplatePdfOrderWork();

    // Membrete de la empresa en sesión (el ticket ya se filtró por ella). Ya no
    // se escribe el storage/app/pdf.pdf compartido: nadie lo leía.
    return $pdfT->PdfOrderWork($user, (int) getSessionCompanyId());
  }
}
