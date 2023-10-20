<?php

namespace App\UseCases\GeneratePdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;

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
        private GeneratePdfRepositoryInterface $generatePdfRepository
    ) {
    }

    /**
     * Método encargado de generar pdf masivo .zip
     * @return mixed
     */
    public function generatePdf($data): mixed
    {
        try {

            if (true) {

                $generatePdf = $this->generatePdfRepository->generatePdf($data);

                error_log(json_encode($generatePdf));

                foreach ($generatePdf as $user) {

                    // Genera el PDF individual y almacénalo en el array junto con su nombre de archivo
                    $nombreArchivo = 'Sr o Sra ' . $user['dni'] . ' ' . $user['names'] . ' ' . $user['lastname'] . '.pdf';
                    $pdfFiles[] = [
                        'nombre_archivo' => $nombreArchivo,
                        'contenido' => $this->generateIndividualPdf($user),
                    ];
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
                unlink($zipFileName);

                return $response;
            } else {
                return ['message' => 'No puedes realizar esta accion', 'status' => 0];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ha ocurrido un error al geerar el pdf',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }
        return ['message' => 'Pdf generado con exito', 'status' => 0];
    }

    private function generateIndividualPdf($user)
    {



        // Crea un PDF individual y devuelve su contenido
        // Aquí puedes usar Dompdf, TCPDF, o cualquier otra biblioteca de tu elección
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);

        // Crea una instancia de Dompdf, TCPDF u otra biblioteca
        $pdf = new Dompdf($options);

        $html = '
    <!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Factura</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
    }

    /* Estilos para el encabezado */
    #header {
        background-color: #5ebad3;
        text-align: center;
    }

    #logo {
        width: 100px;
        height: auto;
    }

    /* Contenedor principal */
    .container {
        padding: 20px;
    }

    /* Estilos para las columnas de la izquierda */
    .left-column {
        float: left;
        width: 45%;
    }

    .left-column p {
        margin: 5px 0;
    }

    /* Estilos para la tabla */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        border: 1px solid #ddd; /* Añadido para los bordes de la tabla */
    }

    th, td {
        padding: 10px;
        text-align: left;
    }

    /* Estilos para las columnas de la derecha */
    .right-column {
        float: right;
        width: 45%;
    }

    .right-column p {
        margin: 5px 0;
    }

    /* Línea divisoria */
    .linea-divisoria {
        border: 1px solid #ccc;
        margin: 20px 0;
        clear: both;
    }

    /* Estilos para el campo adicional */
    .additional-field {
        text-align: right;
        margin-top: 10%;
    }
    .tr{
        background-color: #5ebad3;
    }

    .additional-field p {
        margin: 5px 0;
    }

    /* Estilos para la parte inferior */
    .bottom-section {
        text-align: right;
        margin-top: 15px;
        padding: 10px;
        border-top: 1px solid #ccc;
        background-color: #f0f0f0;
    }

    /* Estilos para los colores de texto */
    .iva {
        color: #e74c3c; /* Rojo para IVA */
    }

    .descuento {
        color: #3498db; /* Azul para Descuento */
    }

    .total {
        color: #27ae60; /* Verde para Total */
    }
    </style>
</head>
<body>
    <div id="header">
        <h1>NetPlay</h1>
    </div>
    <h2>Factura de Venta</h2>

    <div class="container">
        <div class="left-column">
            <p><strong>Razon Social</strong>: NJG TELECOMUNICACIONES</p>
            <p><strong>Identificación</strong>: 
                1193033331-7</p>
            <p><strong>Teléfono</strong>:
            3022042294</p>
            <p><strong>Dirección</strong>:
            Soledad, Atlantico,
           COLOMBIA.</p>
            <p><strong>Condición IVA: No Aplica</strong></p>
        </div>
        <div class="right-column">
            <p><strong>Fecha:</strong></p>
            <p><strong>Fecha Vto.:</strong></p>
            <p><strong>Forma de pago:</strong></p>
        </div>
    </div>

    <hr class="linea-divisoria">
    <div class="container">
        <div class="left-column">
        
            <p>Sr. (es):' . $user['names'] . '</p>
            <p>Dirección: ' . $user['address'] . '</p>
            <p>Municipio: Colombia</p>
        </div>
        <div class="right-column">
            <p>CC: ' . $user['dni'] . '</p>
            <p>Teléfono: ' . $user['phone'] . '</p>
        </div>
    </div>
    <hr class="linea-divisoria">
    <table>
        <thead>
            <tr class="tr">
            <th>Nombre</th>
            <th>Fecha Facturada</th>
            <th class="iva">IVA%</th>
            <th>Precio</th>
            <th class="descuento">% Dto.</th>
            <th class="total">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>' . $user['plan_name'] . '</td>
                <td>2023/08/27' . "/" . '2023/09/27</td>
                <td class="iva">0%</td>
                <td>' . $user['monthly_price'] . '</td>
                <td class="descuento">0%</td>
                <td>' . $user['monthly_price'] . '</td>
            </tr>
        </tbody>
    </table>
    <div class="additional-field">
    <p><strong>Subtotal:</strong> 149,000.00</p>
    <p><strong>Descuento:</strong> 0.00</p>
    <p><strong>Total Bruto:</strong> 149,000.00</p>
</div>
<div class="bottom-section">
    <p><strong>Valor a Pagar: $149,000.00</strong></p>
</div>
</body>
</html>
';
        // Agrega contenido al PDF personalizado (por ejemplo, el nombre del usuario)
        $pdf->loadHtml($html);

        // Renderiza el PDF
        $pdf->render();

        // Devuelve el contenido del PDF generado
        return $pdf->output();
    }
}
