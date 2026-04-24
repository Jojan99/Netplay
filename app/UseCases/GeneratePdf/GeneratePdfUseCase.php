<?php

namespace App\UseCases\GeneratePdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use Carbon\Carbon;
use App\Resources\Templates\TemplatesPdf;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
/**
 *
 * @package App\UseCases\GeneratePdf
 * @author NetPlay <atencionalcliente@netplay.com.co
 * @copyright 2023/09/29
 */
class GeneratePdfUseCase implements GeneratePdfUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param GeneratePdfRepositoryInterface $generatePdfRepository
     */
    public function __construct(
        private GeneratePdfRepositoryInterface $generatePdfRepository,
        private ?FacturationRepositoryInterface $facturationRepository = null,
    ) {
    }

    /**
     * Método encargado de generar pdf masivo .zip
     * @return mixed
     */
   public function generatePdf($Periodo, int $companyId = 0, int $billingDay = 0): mixed
{
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    try {
        if (true) {
            $getUserPeriode1 = $this->generatePdfRepository->getUserPeriode1($Periodo, $companyId);
            $generatePdf     = $this->generatePdfRepository->generatePdf($getUserPeriode1);

             $fecha = date('Y-m-d', strtotime('+1 days'));

            foreach ($generatePdf as $user) {

                $Cab = $this->generatePdfRepository->getSaldoAnt($user['id'], $user['number_facture']);
                $Cab = $Cab === null ? 0 : $Cab;

                // 📄 Generar PDF
                $pdfContent = $this->generateIndividualPdf($user, $Cab);

                $nombreArchivo = 'Sr_o_Sra_' . $user['dni'] . '_' . $user['names'] . '_' . $user['lastname'] . '.pdf';
                $nombreArchivo = str_replace(' ', '_', $nombreArchivo);
                $nombreArchivo = preg_replace('/[^A-Za-z0-9_\-.]/', '', $nombreArchivo);

                $storagePath = storage_path('app/pdf');
                if (!file_exists($storagePath)) {
                    mkdir($storagePath, 0777, true);
                }

                $pdfFilePath = $storagePath . DIRECTORY_SEPARATOR . $nombreArchivo;
                file_put_contents($pdfFilePath, $pdfContent);

                $ruta = "https://netplay.com.co/netplay/storage/app/pdf/$nombreArchivo";

                // 📲 ENVÍO WHATSAPP
                $phoneNumbers = explode(' - ', $user['phone']);

                foreach ($phoneNumbers as $phone) {
                    $phone = trim($phone);

                    if (!empty($phone)) {

                        $payload = [
                            "apiToken" => "18736|MVxPCIhgDsWNsXw2F8IuNGKvZep7t6TQPOtJJIG248b3f82f",
                            "phone_number_id" => "1069182359602584",
                            "template_id" => "339815",
                            "phone_number" => $phone,
                            "templateVariable-Names-1" => $user['names'],
                            "templateVariable-LastName-2" => $user['lastname'],
                            "templateVariable-NumberBill-3" => $user['number_facture'],
                            "templateVariable-MonthlyPrice-4" => '$' . number_format($user['monthly_price'], 0, ',', '.'),
                            "templateVariable-DateFinishBill-5" => $fecha,
                            "template_quick_reply_button_values" => ["77R3LXw6gnAKTO8"]
                        ];

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, "https://app.whatchimp.com/api/v1/whatsapp/send/template"); // 👈 CAMBIA ESTO
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json'
                        ]);

                        $responseApi = curl_exec($ch);
                        $error = curl_error($ch);
                        curl_close($ch);

                        // 📁 LOG DE TRAZABILIDAD
                        $logPath = storage_path('logs/whatsapp_logs');
                        if (!file_exists($logPath)) {
                            mkdir($logPath, 0777, true);
                        }

                        $logData = [
                            "fecha_envio" => Carbon::now()->toDateTimeString(),
                            "usuario" => $user['names'] . ' ' . $user['lastname'],
                            "dni" => $user['dni'],
                            "telefono" => $phone,
                            "factura" => $user['number_facture'],
                            "ruta_pdf" => $ruta,
                            "payload" => $payload,
                            "response" => $responseApi,
                            "error" => $error
                        ];

                        $logFile = $logPath . '/log_' . date('Y-m-d') . '.json';

                        file_put_contents(
                            $logFile,
                            json_encode($logData, JSON_PRETTY_PRINT) . PHP_EOL,
                            FILE_APPEND
                        );
                    }
                }

                // ❌ Ya no borramos el PDF (opcional)
                // unlink($pdfFilePath);
            }

            return [
                'message' => 'PDFs generados y enviados correctamente',
                'status' => 0
            ];
        }

        } catch (QueryException $err) {
            return [
                'message' => 'Error generando PDF',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }
    }

public function generatePdfMeta($Periodo, int $companyId = 0, int $billingDay = 0): mixed
{
    try {

        $getUserPeriode1 = $this->generatePdfRepository->getUserPeriode1($Periodo, $companyId);
        $users           = $this->generatePdfRepository->generatePdf($getUserPeriode1);

        if ($billingDay > 0) {
            $fecha = Carbon::now()->setDay(min($billingDay, Carbon::now()->daysInMonth))->format('Y-m-d');
        } else {
            $fecha = Carbon::now()->format('Y-m-d');
        }

        $totalEnviados = 0;
        $totalFallidos = 0;
        $fallidos      = [];

        $watchchimp = new \App\Services\WatchChimpService();

        foreach ($users as $user) {

            $phone = trim($user['phone'] ?? '');

            if (empty($phone)) {
                continue;
            }

            try {

                // 🔥 Obtener saldo anterior
                $Cab = $this->generatePdfRepository->getSaldoAnt(
                    $user['id'], 
                    $user['number_facture']
                );

                $Cab = $Cab ?? 0;

                // 🔥 Total (sirve para deuda o no)
                $total = $Cab > 0 
                    ? $Cab + ($user['total'] ?? 0)
                    : ($user['total'] ?? 0);

                // 🔥 Variables (ORDEN IMPORTANTE)
                $variables = [
                    'names' => $user['names'] ?? '',
                    'last_names' => $user['lastname'] ?? '',
                    'number_bill' => $user['number_facture'] ?? '',
                    'monthly_price' => number_format($total, 0, ',', '.'),
                    'date_finish_bill' => $fecha,
                ];

                // 🚀 Enviar template
                $response = $watchchimp->sendTemplate(
                    $phone,
                    "333320", // TU TEMPLATE ID
                    $variables
                );

                if (($response['status'] ?? 0) != 1) {
                    throw new \Exception(json_encode($response));
                }

                $totalEnviados++;

                Log::info('[TEMPLATE ENVIADO]', [
                    'dni'   => $user['dni'],
                    'phone' => $phone,
                ]);

            } catch (\Exception $e) {

                $totalFallidos++;

                $fallidos[] = [
                    'dni'   => $user['dni'],
                    'phone' => $phone,
                    'error' => $e->getMessage()
                ];

                Log::warning('[TEMPLATE FALLIDO]', [
                    'dni'   => $user['dni'],
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }

        Log::info('[PROCESO COMPLETADO]', [
            'enviados' => $totalEnviados,
            'fallidos' => $totalFallidos,
            'detalle_fallidos' => $fallidos,
        ]);

        return [
            'message'  => "Proceso completado. Enviados: {$totalEnviados}, Fallidos: {$totalFallidos}",
            'status'   => 0,
            'enviados' => $totalEnviados,
            'fallidos' => $totalFallidos,
            'detalle_fallidos' => $fallidos,
        ];

    } catch (\Exception $err) {

        return [
            'message' => 'Error en el proceso',
            'status'  => 1,
            'error'   => $err->getMessage()
        ];
    }
}


    private function generateIndividualPdf($user,$Cab)
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
        $logoPath = "https://i.ibb.co/wQyTjTy/NET-PLAY-LOGO-Mesa-de-trabajo-1.jpg";

        // $imagenBase64 = "data:image/png;base64," . base64_encode(file_get_contents($logoPath));
        // Crea una instancia de Dompdf, TCPDF u otra biblioteca
         $pdfT = new TemplatesPdf();
        
        $pdf = new Dompdf($options);
       

        $html = $pdfT->PdfFacturas($user,$Cab);
          // Agrega contenido al PDF personalizado (por ejemplo, el nombre del usuario)
          $pdf->loadHtml($html);

          // Renderiza el PDF
          $pdf->render();


          // Devuelve el contenido del PDF generado
          return $pdf->output();
      }
}
