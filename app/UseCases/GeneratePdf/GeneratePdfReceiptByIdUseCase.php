<?php

namespace App\UseCases\GeneratePdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Resources\Templates\TemplatesPdf;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfReceiptByIdUseCaseInterface;

/**
 *
 * @package App\UseCases\GeneratePdf
 * @author NetPlay <atencionalcliente@netplay.com.co
 * @copyright 2023/09/29
 */
class GeneratePdfReceiptByIdUseCase implements GeneratePdfReceiptByIdUseCaseInterface
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

  public function generatePdfById($dataUser, $price_total, $number_facture): mixed
  {
    try {
      $options = new Options();
      $options->set('isHtml5ParserEnabled', true);
      $options->set('isPhpEnabled', true);
      $pdf = new Dompdf($options);

      $html = $this->generateIndividualPdf($dataUser, $price_total, $number_facture);


      $pdf->loadHtml($html);

      // Renderizar el PDF
      $pdf->render();

      // Devolver el contenido del PDF generado como respuesta HTTP
      $output = $pdf->output();

      return response($output)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'attachment; filename=".pdf"');
    } catch (QueryException $err) {
      return [
        'message' => 'Ha ocurrido un error al generar el PDF',
        'status' => 1,
        'data' => ApiResponseConstants::DATA_NULL
      ];
    }

    return ['message' => 'PDF generado con éxito', 'status' => 0];
  }

  private function generateIndividualPdf($dataUser, $price_total, $number_facture)
  {
    $pdfT = new TemplatesPdf();
    $html = $pdfT->PdfReceiptPay($dataUser, $price_total, $number_facture);
    
    return $html;
  }
}
