<?php

namespace App\UseCases\Notifications;

use App\Repositories\Interfaces\SearchRepositoryInterface;
use App\UseCases\Search\Interfaces\SearchUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Http\Requests\Search\SearchRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\Resources\Templates\TemplatesPdf;
use App\Services\WhatsAppService;
use App\UseCases\Notifications\Interfaces\SendNotificationRememberUseCaseInterface;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class SendNotificationRememberUseCase implements SendNotificationRememberUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param FacturationRepositoryInterface $facturationRepositoryInterface
     */
    public function __construct(
        private GeneratePdfRepositoryInterface $generatePdfRepository

    ) {
    }

    /**
     * Método encargado de generar pdf masivo .zip
     * @return mixed
     */
    public function sendNotificationRemeReminder(): mixed
{
    try {
        if (true) {
            // Datos de pago y nombre de la empresa en sesión, nunca los de otra.
            $empresa = \App\Models\Company::find(getSessionCompanyId());
            if (!$empresa) {
                return ['message' => 'Empresa no encontrada', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
            }
            $nombreEmpresa = trim((string) $empresa->invoice_business_name) ?: trim((string) $empresa->name);
            $mediosPago = trim((string) $empresa->invoice_payment_info);
            $bloquePago = $mediosPago !== ''
                ? "Recuerda que puedes realizar tus pagos por estos medios:\n\n{$mediosPago}\n\n"
                : '';

            $getUserPeriode1 = $this->generatePdfRepository->getperiodeNotificationRemenber((int) $empresa->id);

            error_log(json_encode($getUserPeriode1));

            $generatePdf = $this->generatePdfRepository->generatePdfRemember($getUserPeriode1);
         
            foreach ($generatePdf as $user) {

                $Cab = $this->generatePdfRepository->getSaldoAnt($user['id'],$user['number_facture']);
                
                $Cab = $Cab === null ? 0 : $Cab;

                // Genera el PDF individual y almacénalo en el array junto con su nombre de archivo
                $nombreArchivo = 'Sr_o_Sra ' . $user['dni'] . ' ' . $user['names'] . ' ' . $user['lastname'] .'.pdf';
                $nombrMensaje = 'Sr_o_Sra ' . $user['dni'] . ' ' . $user['names'] . ' ' . $user['lastname'];
                $pdfFiles[] = [
                    'nombre_archivo' => $nombreArchivo,
                    'contenido' => $this->generateIndividualPdf($user, $Cab, (int) $empresa->id),
                ];

                $whatsapp = new WhatsAppService('u99eyqpz5jwn5h4w', 'instance106490');

                $storagePath = storage_path('app/pdf'); // Ruta de almacenamiento en la carpeta "pdf"
                
                // Asegurarse de que la carpeta "pdf" exista
                if (!file_exists($storagePath)) {
                    mkdir($storagePath, 0777, true);
                }
                
                $pdfFilePath = $storagePath . DIRECTORY_SEPARATOR . $nombreArchivo;

                // Guardar el contenido en un archivo en la carpeta "pdf"
                file_put_contents($pdfFilePath, $this->generateIndividualPdf($user, $Cab, (int) $empresa->id));

                Log::info('RUTAAA'.$pdfFilePath);
                $phoneNumbers = explode(' - ', $user['phone']); // Divide el string en números
                $nombrMensaje = mb_convert_encoding($user['names'] . ' ' . $user['lastname'], 'UTF-8', 'auto');

                foreach ($phoneNumbers as $phone) {
                    $phone = trim($phone); // Elimina espacios en blanco extra

                    error_log($phone);
                    error_log($nombreArchivo);
                    
                    if (!empty($phone)) {
                        $response = $whatsapp->mensajeInformativoFactura(
                            $phone,
                            $nombreArchivo,
                            $pdfFilePath,
                            '¡Hola! Sr_o_Sra '.$user['dni'].' '.$nombrMensaje.', te informamos que tu factura de servicio de internet ya está lista, tu fecha limite de pago es 31/08/2024, si ya realizaste tu pago envíanos el comprobante.

' . $bloquePago . 'Recuerda que estar al día con tu factura evita suspensiones de servicio.

*Si ya pago y envio el comprobante de pago por favor omitir este mensaje*.

Gracias por preferirnos, somos ' . $nombreEmpresa
                        );
                    }
                }
                
                // Eliminar el archivo después del envío
                if (file_exists($pdfFilePath)) {
                    unlink($pdfFilePath);
                }
            }

            /*
            // Código comentado: Generación del archivo ZIP
            $zipFileName = storage_path('app/archivos.zip');
            // Eliminar el archivo ZIP existente si existe
            if (file_exists($zipFileName)) {
              unlink($zipFileName);
            }

            $zip = new \ZipArchive();
            $zipFileName = storage_path('app/archivos.zip');

            if ($zip->open($zipFileName, \ZipArchive::CREATE) === true) {
                foreach ($pdfFiles as $pdfFile) {
                    $zip->addFromString($pdfFile['nombre_archivo'], $pdfFile['contenido']);
                }
                $zip->close();
            }

            // Prepara una respuesta HTTP con el archivo ZIP
            $response = new \Illuminate\Http\Response();
            $response->header('Content-Type', 'application/zip');
            $response->header('Content-Disposition', 'attachment; filename="archivos.zip"');
            $response->setContent(file_get_contents($zipFileName));

            $zipContent = file_get_contents($zipFileName);
            $response->setContent($zipContent);
            
            $fechaActual = Carbon::now()->format('Y-m-d');
            $nombreArchivo = 'Facturas '. $fechaActual .'.zip';
            $filePath = storage_path('archiveZip/' . $nombreArchivo);
           
            file_put_contents($filePath, $zipContent);

            return $response;
            */

            return ['message' => 'Pdf generado con exito', 'status' => 0];
        } else {
            return ['message' => 'No puedes realizar esta accion', 'status' => 0];
        }
    } catch (QueryException $err) {
        return [
            'message' => 'Ha ocurrido un error al generar el pdf',
            'status' => 1,
            'data' => ApiResponseConstants::DATA_NULL
        ];
    }
}


    private function generateIndividualPdf($user, $Cab, int $companyId)
    {

        // $fechaInit = substr($user['date_init_facturation'], 0, 10);
        // $fechaNueva = date('Y-m-d', strtotime($fechaInit . ' -1 month'));
        // $fechaActual = date('Y-m-d');
        // $fechaVence = date('Y-m-d',strtotime($fechaActual . ' +3 days'));


        // $Porcentage = 0;

        // $valorDescuento = $user['price_discount'];

        // $saldoTotal = $user['monthly_price'] - $user['price_discount'];

        // Crea un PDF individual y devuelve su contenido
        // Aquí puedes usar Dompdf, TCPDF, o cualquier otra biblioteca de tu elección
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);

        // $imagenBase64 = "data:image/png;base64," . base64_encode(file_get_contents($logoPath));
        // Crea una instancia de Dompdf, TCPDF u otra biblioteca
         $pdfT = new TemplatesPdf();
        
        $pdf = new Dompdf($options);
       

        $html = $pdfT->PdfFacturas($user, $Cab, $companyId);
          // Agrega contenido al PDF personalizado (por ejemplo, el nombre del usuario)
          $pdf->loadHtml($html);

          // Renderiza el PDF
          $pdf->render();


          // Devuelve el contenido del PDF generado
          return $pdf->output();
      }
}
