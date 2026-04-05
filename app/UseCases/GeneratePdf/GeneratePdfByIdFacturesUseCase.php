<?php

namespace App\UseCases\GeneratePdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Resources\Templates\TemplatesPdf;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdFacturesUseCaseInterface;

use function Laravel\Prompts\error;

/**
 *
 * @package App\UseCases\GeneratePdf
 * @author NetPlay <atencionalcliente@netplay.com.co
 * @copyright 2023/09/29
 */
class GeneratePdfByIdFacturesUseCase implements GeneratePdfByIdFacturesUseCaseInterface
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

  public function generatePdfByIdFacture($userFacture): mixed
  {
    
    if(true){
      try {
        // Obtener los datos del usuario y generar el PDF
        $generatePdf = $this->generatePdfRepository->generatePdfById($userFacture);

        if (!$generatePdf) {
            return response()->json(['message' => 'Factura no encontrada', 'status' => 1], 404);
        }

        $Cab = $this->generatePdfRepository->getSaldoAnt($generatePdf['id'],$generatePdf['number_facture']);
        

        // Crear una instancia de Dompdf y cargar el contenido HTML
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $pdf = new Dompdf($options);
  
        $html = $this->generateIndividualPdf($generatePdf,$Cab);
  
  
        $pdf->loadHtml($html);
  
        // Renderizar el PDF
        $pdf->render();
  
        // Devolver el contenido del PDF generado como respuesta HTTP
        $output = $pdf->output();
  
        return response($output)
          ->header('Content-Type', 'application/pdf')
          ->header('Content-Disposition', 'attachment; filename="' . $generatePdf['names'] . ' ' . $generatePdf['lastname'] . ' ' . $generatePdf['number_facture'] . '.pdf"');
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


  private function generateIndividualPdf($user,$Cab)
  {
    // Crea un PDF individual y devuelve su contenido
    // Aquí puedes usar Dompdf, TCPDF, o cualquier otra biblioteca de tu elección
    $options = new Options();
    // Crea una instancia de Dompdf, TCPDF u otra biblioteca
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('paperSize', array(0, 0, 58, 100)); // Ancho x alto en milímetros
    $pdfT = new TemplatesPdf();
    $pdf = new Dompdf($options);

    $html = $pdfT->PdfFacturas($user,$Cab);

    // Agrega contenido al PDF personalizado (por ejemplo, el nombre del usuario)
    $pdf->loadHtml($html);

    // Renderiza el PDF
    $pdf->render();

    // Devuelve el contenido del PDF generado

    $pdfContent = $pdf->output();

    $filePath = storage_path('app/pdf.pdf');
    file_put_contents($filePath, $pdfContent);

    return $html;
  }
}
