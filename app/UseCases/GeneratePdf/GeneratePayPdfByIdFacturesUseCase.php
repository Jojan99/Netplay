<?php

namespace App\UseCases\GeneratePdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Resources\Templates\TemplatesPdf;
use App\UseCases\GeneratePdf\Interfaces\GeneratePayPdfByIdFacturesUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdFacturesUseCaseInterface;


/**
 *
 * @package App\UseCases\GeneratePdf
 * @author NetPlay <atencionalcliente@netplay.com.co
 * @copyright 2023/09/29
 */
class GeneratePayPdfByIdFacturesUseCase implements GeneratePayPdfByIdFacturesUseCaseInterface
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

  public function generatePayPdfByIdFacture($userFacture,$extraParam): mixed
  {

    if(sessionUserHasProfile('CONTADOR', 'ADMIN')){
      try {
        
        // Obtener los datos del usuario y generar el PDF
        // Con la empresa del operador: los números de factura se repiten entre empresas.
        $empresa = (int) (getSessionCompanyId() ?: (\Tymon\JWTAuth\Facades\JWTAuth::user()->company_id ?? 0));
        $generatePdf = $empresa ? $this->generatePdfRepository->generatePdfById($userFacture, $empresa) : null;
        if (!$generatePdf) {
            return response()->json(['message' => 'Factura no encontrada', 'status' => 1], 404);
        }

        $Cab = $this->generatePdfRepository->getPaySaldoAnt($generatePdf['id'],$generatePdf['number_facture']);
       
        error_log("dddddddddddddddddddddddddd".json_encode($Cab));
       
        // Crear una instancia de Dompdf y cargar el contenido HTML
        $dompdf = new Dompdf();
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true); // Si necesitas cargar imágenes externas
        
        $dompdf = new Dompdf($options);
        
        // Configura el tamaño y la orientación del papel
        $dompdf->setPaper([0, 0, 600, 300], 'landscape');
        
        // Genera el contenido HTML
        $html = $this->generateIndividualPdf($generatePdf, $Cab, $extraParam, $empresa);
        
        // Carga el HTML en Dompdf
        $dompdf->loadHtml($html);
        
        // Renderiza el PDF
        $dompdf->render();
  
        // Renderizar el PDF

        // Devolver el contenido del PDF generado como respuesta HTTP
        $output = $dompdf->output();
  
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


  private function generateIndividualPdf($user, $Cab, $extraParam, int $companyId)
  {
    $pdfT = new TemplatesPdf();

    // Recibo con los datos de la empresa de la factura. Ya no se escribe el
    // storage/app/pdf.pdf compartido: nadie lo leía.
    return $pdfT->PdfReceiptPay($user, $Cab, $extraParam, (int) ($user['company_id'] ?? $companyId));
  }
}
