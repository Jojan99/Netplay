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

            // Usar el día de corte configurado, no el día actual
            if ($billingDay > 0) {
                $fechaCarbon = Carbon::now()->setDay(min($billingDay, Carbon::now()->daysInMonth));
            } else {
                $fechaCarbon = Carbon::now();
            }
            $fecha = $fechaCarbon->format('Y-m-d');
            $zipFileName     = storage_path('app/archivos.zip');

            if (file_exists($zipFileName)) unlink($zipFileName);

            $zip = new \ZipArchive();
            if ($zip->open($zipFileName, \ZipArchive::CREATE) !== true) {
                throw new \Exception('No se pudo crear el archivo ZIP.');
            }

            $storagePath = storage_path('app/public/pdf');
            if (!file_exists($storagePath)) {
                mkdir($storagePath, 0775, true);
            } else {
                foreach (glob($storagePath . '/*') as $file) {
                    if (is_file($file)) unlink($file);
                }
            }

            $emojisHola  = ['👋', '😊', '🙌', '✨', '💬'];
            $emojisFactura = ['📄', '📋', '🧾', '📑', '💼'];
            $emojisGracias = ['🙏', '💚', '✅', '⭐', '😊'];
            $emojisPago  = ['💳', '🏦', '💰', '📲', '✅'];

            $saludos = [
                "¡Hola", "Buenas", "Hola", "Cordial saludo", "Estimado(a)"
            ];

            $cierres = [
                "Gracias por preferir Soluciones NetPlay",
                "Gracias por confiar en Soluciones NetPlay",
                "Quedamos atentos a cualquier consulta. Soluciones NetPlay",
                "Estamos para servirte. Soluciones NetPlay",
                "Un gusto atenderte. Soluciones NetPlay"
            ];

            $disponibilidad = [
                "tu factura del servicio de internet ya está disponible",
                "tu factura de internet ya fue generada",
                "tu factura del mes ya se encuentra lista",
                "hemos generado tu factura de internet",
                "tu factura ya está lista para consultar"
            ];

            $mensajesComprobante = [
                "Le solicitamos amablemente enviar el comprobante de pago por este mismo canal para validar su transacción.",
                "Por favor compártenos el comprobante de pago a través de este medio para realizar la verificación.",
                "Agradecemos enviar el comprobante de pago por esta misma conversación para confirmar su transacción.",
                "Para completar la validación, por favor envíe el comprobante por este mismo medio.",
                "Le agradecemos compartir el comprobante por este canal para proceder con la validación.",
                "Una vez realizado el pago, envíenos el comprobante por esta conversación.",
                "Por favor envíanos el comprobante por este medio para confirmar tu pago.",
                "Recuerda enviarnos el comprobante por esta conversación para validar tu pago."
            ];

            // ✅ Contadores para el log
            $totalEnviados = 0;
            $totalFallidos = 0;
            $fallidos      = [];

            foreach ($generatePdf as $user) {

                $Cab = $this->generatePdfRepository->getSaldoAnt($user['id'], $user['number_facture']);
                $Cab = $Cab === null ? 0 : $Cab;

                $pdfContent    = $this->generateIndividualPdf($user, $Cab);
                $nombreArchivo = 'Sr_o_Sra_' . $user['dni'] . '_' . $user['names'] . '_' . $user['lastname'] . '.pdf';
                $nombreArchivo = str_replace(' ', '_', $nombreArchivo);
                $nombreArchivo = preg_replace('/[^A-Za-z0-9_\-.]/', '', $nombreArchivo);
                $nombrMensaje  = mb_convert_encoding($user['names'] . ' ' . $user['lastname'], 'UTF-8', 'auto');

                $zip->addFromString($nombreArchivo, $pdfContent);

                $pdfFilePath = $storagePath . DIRECTORY_SEPARATOR . $nombreArchivo;
                file_put_contents($pdfFilePath, $pdfContent);

                $ruta = "https://netplay.com.co/storage/pdf/$nombreArchivo";

                // Variantes aleatorias
                $emojiHola    = $emojisHola[array_rand($emojisHola)];
                $emojiFactura = $emojisFactura[array_rand($emojisFactura)];
                $emojiGracias = $emojisGracias[array_rand($emojisGracias)];
                $emojiPago    = $emojisPago[array_rand($emojisPago)];
                $saludo       = $saludos[array_rand($saludos)];
                $cierre       = $cierres[array_rand($cierres)];
                $disponible   = $disponibilidad[array_rand($disponibilidad)];
                $mensageQr    = $mensajesComprobante[array_rand($mensajesComprobante)];

                if ($user['billing_electronic'] != 1) {
                    $mensage = "{$saludo} {$nombrMensaje} {$emojiHola}

{$emojiFactura} {$disponible}.
La fecha límite de pago es *{$fecha}*.

{$emojiPago} Puedes realizar tu pago en:
- BANCOLOMBIA CTA AHO 47800013328
- DAVIPLATA 3022042294
- NEQUI 3022042294 (Hum Gom)
- NEQUI 3245127869 (Joj Pom)

{$mensajesComprobante[array_rand($mensajesComprobante)]}

{$emojiGracias} {$cierre}.";
                } else {
                    $mensage = "{$saludo} {$nombrMensaje} {$emojiHola}

{$emojiFactura} {$disponible}.
La fecha límite de pago es *{$fecha}*.

{$emojiPago} Puedes realizar tu pago en:
- BANCOLOMBIA CTA AHO 44200009784
  Soluciones Netplay SAS

{$mensajesComprobante[array_rand($mensajesComprobante)]}

{$emojiGracias} {$cierre}.";
                }

                // ✅ Enviar WhatsApp con try/catch individual
                $phone = trim($user['phone']);

                if (!empty($phone)) {
                    $whatsapp = new WhatsAppService();

                    // Enviar documento
                    try {
                        $whatsapp->sendDocument($phone, $ruta, $nombreArchivo, $mensage);
                        $totalEnviados++;
                        Log::info('[FACTURA ENVIADA]', [
                            'dni'   => $user['dni'],
                            'phone' => $phone,
                        ]);
                    } catch (\Exception $e) {
                        $totalFallidos++;
                        $fallidos[] = ['dni' => $user['dni'], 'phone' => $phone, 'error' => $e->getMessage()];
                        Log::warning('[FACTURA FALLIDA]', [
                            'dni'   => $user['dni'],
                            'phone' => $phone,
                            'error' => $e->getMessage(),
                        ]);
                        // ✅ Continúa con el siguiente cliente
                        continue;
                    }

                    // Enviar imagen QR solo si es factura electrónica
                    if ($user['billing_electronic'] == 1) {
                        try {
                            $whatsapp->sendImage(
                                $phone,
                                "https://netplay.com.co/storage/Qr/QrNetplay.jpeg",
                                $mensageQr
                            );
                        } catch (\Exception $e) {
                            Log::warning('[QR IMAGE FALLIDA]', [
                                'dni'   => $user['dni'],
                                'phone' => $phone,
                                'error' => $e->getMessage(),
                            ]);
                            // No interrumpir — el documento ya se envió
                        }
                    }
                }
                //$delay = rand(60, 90);
                Log::info("[DELAY] Cliente {$user['dni']} procesado. Esperando {$delay}s...");
                sleep($delay);
            }

            $zip->close();

            Log::info('[PROCESO COMPLETADO]', [
                'enviados' => $totalEnviados,
                'fallidos' => $totalFallidos,
                'detalle_fallidos' => $fallidos,
            ]);

            return [
                'message'  => "PDF generado. Enviados: {$totalEnviados}, Fallidos: {$totalFallidos}",
                'status'   => 0,
                'enviados' => $totalEnviados,
                'fallidos' => $totalFallidos,
                'detalle_fallidos' => $fallidos,
            ];

        } else {
            return ['message' => 'No puedes realizar esta acción', 'status' => 0];
        }
    } catch (QueryException $err) {
        return [
            'message' => 'Ha ocurrido un error al generar el PDF',
            'status'  => 1,
            'data'    => ApiResponseConstants::DATA_NULL
        ];
    }

    return ['message' => 'PDF generado con éxito', 'status' => 0];
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
