<?php

namespace App\UseCases\GeneratePdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;

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
        private GeneratePdfRepositoryInterface $generatePdfRepository
    ) {
    }

    public function generatePdfById($user_id): mixed
    {
        try {
            // Obtener los datos del usuario y generar el PDF
            $generatePdf = $this->generatePdfRepository->generatePdfById($user_id);
    
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
                ->header('Content-Disposition', 'attachment; filename="mi_archivo.pdf"');
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


        $fechaInit = substr($user['date_init_facturation'], 0, 10);
        $fechaNueva = date('Y-m-d', strtotime($fechaInit . ' +1 month'));
        $fechaActual = date('Y-m-d');

        $Porcentage = 0;



        $valorDescuento = $user['monthly_price'] * ($Porcentage / 100);

        $saldoTotal = $user['monthly_price'] - $valorDescuento;


        // Crea un PDF individual y devuelve su contenido
        // Aquí puedes usar Dompdf, TCPDF, o cualquier otra biblioteca de tu elección
        $options = new Options();
        // Crea una instancia de Dompdf, TCPDF u otra biblioteca
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $pdf = new Dompdf($options);
        $logoPath = "https://scontent-bog1-1.xx.fbcdn.net/v/t39.30808-6/340150489_722020196383845_5627885988636614182_n.jpg?_nc_cat=109&ccb=1-7&_nc_sid=a2f6c7&_nc_eui2=AeG2ZurqTeee1qF_eru2KYjpe3HvlryLXUZ7ce-WvItdRqskXKVY1LADJJUYwa6QxWg&_nc_ohc=WhgY3NkHYQwAX-PdPIc&_nc_ht=scontent-bog1-1.xx&oh=00_AfCf9YV5OxggcqMLMFnfaNbAuBAKz81Me2bs_FkGZOwBiA&oe=651C6875";

        // $imagenBase64 = "data:image/png;base64," . base64_encode(file_get_contents($logoPath));
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
                <p><strong>Fecha: </strong>' . $fechaActual . '</p>
                <p><strong>Fecha Vto.: </strong>' . $fechaActual . '</p>
                <p><strong>Forma de pago:</strong>Efectivo</p>
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
                    <td>' . $fechaInit . ' / ' . $fechaNueva . ' </td>
                    <td class="iva">0%</td>
                    <td>' . $user['monthly_price'] . '</td>
                    <td class="descuento">' . $Porcentage . '</td>
                    <td>' . $saldoTotal . '</td>
                </tr>
            </tbody>
        </table>
        <div class="additional-field">
        <p><strong>Subtotal:</strong> ' . $user['monthly_price'] . '</p>
        <p><strong>Descuento:</strong> ' . $valorDescuento . '</p>
        <p><strong>Total Bruto:</strong> ' . $saldoTotal . '</p>
    </div>
    <div class="bottom-section">
        <p><strong>Valor a Pagar: ' . $saldoTotal . '</strong></p>
    </div>
    </body>
    </html>
';
        // Agrega contenido al PDF personalizado (por ejemplo, el nombre del usuario)
        $pdf->loadHtml($html);

        // Renderiza el PDF
        $pdf->render();

        // Devuelve el contenido del PDF generado
        return $html;
    }
}
