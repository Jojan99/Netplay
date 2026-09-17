<?php

namespace App\UseCases\GeneratePdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Resources\Templates\TemplatesPdf;

/**
 *
 * @package App\UseCases\GeneratePdf
 * @author NetPlay <atencionalcliente@netplay.com.co
 * @copyright 2023/09/29
 */
class GeneratePdfByIdUseCase implements GeneratePdfByIdUseCaseInterface
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

  public function generatePdfById($userFacture): mixed
  {
    try {
      // Solo facturas de la empresa en sesión: los números se repiten entre empresas.
      $empresa = (int) (getSessionCompanyId() ?: (\Tymon\JWTAuth\Facades\JWTAuth::user()->company_id ?? 0));
      $generatePdf = $empresa ? $this->generatePdfRepository->generatePdfById($userFacture, $empresa) : null;
      if (!$generatePdf) {
        return response()->json(['message' => 'Factura no encontrada', 'status' => 1], 404);
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
        ->header('Content-Disposition', 'attachment; filename="' . $generatePdf['names'] . ' ' . $generatePdf['lastname'] . ' ' . $generatePdf['number_facture'] . '.pdf"');
    } catch (QueryException $err) {
      return [
        'message' => 'Ha ocurrido un error al generar el PDF',
        'status' => 1,
        'data' => ApiResponseConstants::DATA_NULL
      ];
    }

    return ['message' => 'PDF generado con éxito', 'status' => 0];
  }


  private function generateIndividualPdf($user)
  {
    $pdfT = new TemplatesPdf();

    // Ya no se escribe el storage/app/pdf.pdf compartido: nadie lo leía.
    return $pdfT->PdfFacturas($user, null, (int) $user['company_id']);
  }
}
