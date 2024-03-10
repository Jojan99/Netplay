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

    public function generatePdfById($userFacture): mixed
    {
        try {
          error_log(json_encode($userFacture));

            // Obtener los datos del usuario y generar el PDF
            $generatePdf = $this->generatePdfRepository->generatePdfById($userFacture);

    
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
        $fechaNueva = date('Y-m-d', strtotime($fechaInit . ' -1 month'));
        $fechaActual = date('Y-m-d');
        $fechaVence = date('Y-m-d',strtotime($fechaActual . ' +3 days'));
        

        error_log($fechaVence);

        error_log($user);
        $Porcentage = 0;

        $valorDescuento = $user['price_discount'];

        $saldoTotal = $user['monthly_price'] - $user['price_discount'];

        // Crea un PDF individual y devuelve su contenido
        // Aquí puedes usar Dompdf, TCPDF, o cualquier otra biblioteca de tu elección
        $options = new Options();
        // Crea una instancia de Dompdf, TCPDF u otra biblioteca
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $options->set('paperSize', array(0, 0, 58, 100)); // Ancho x alto en milímetros
        $pdf = new Dompdf($options);
        $logoPath = "https://i.ibb.co/wQyTjTy/NET-PLAY-LOGO-Mesa-de-trabajo-1.jpg";

        $imagenBase64 = "data:image/png;base64," . base64_encode(file_get_contents($logoPath));
        $html = '
        <!DOCTYPE html>
<html>

<head>
  <meta charset="UTF-8">
  <title>Factura</title>
  <style>
    body {
      font-family: Arial, sans-serif;
    }

    /* Estilos para el encabezado */
    #header {
      text-align: center;
    }


    /* Estilos para las columnas de la izquierda */
    .left-column {
      float: left;
      width: 33.33%;
    }

    .center-column {
      float: left;
      width: 33.33%;
      font-size: 15px;
    }

    /* Estilos para la tabla */
    table {
      width: 100%;
      /* Añadido para los bordes de la tabla */
      text-align: center;
      padding-bottom: 15%;
    }

    th {
      background-color: #f2f2f2;
      text-align: center;
      border-radius: 8px; 
    }


    /* Estilos para las columnas de la derecha */
    .right-column {
      float: right;
      width: 33.33%;
    }

    .right-column-center {
      position: absolute;
      right: 10%;
      /* Ajusta este valor según tus preferencias */
    }

    .left-column-center {
      position: absolute;

    }

    /* Línea divisoria */
    .linea-divisoria {
      border: 1px solid black;
      clear: both;

    }

    /* Estilos para el campo adicional */
    .additional-field {
      text-align: right;
      margin-top: 10%;
    }

    .additional-field p {
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
      color: #e74c3c;
      /* Rojo para IVA */
    }

    .descuento {
      color: #3498db;
      /* Azul para Descuento */
    }

    .total {
      color: #27ae60;
      /* Verde para Total */
    }

    .container {
      position: relative;
    }
    .containerlogo {
      position: relative;

    }

    .container1 {
      position: relative;
      padding-bottom: 100px;
    }

    .title {
      position: absolute;
      left: 70%;
      /* Ajusta este valor según tus preferencias */
      /* Otros estilos según tus preferencias */
    }


    .logo img {
      width: 20%; /* Ajusta el ancho al 100% del contenedor */
      height: 10%; /* Ajusta la altura al 100% del contenedor */
      left: 20%;

      object-fit: contain; /* Puedes probar otras opciones como "cover", "fill", "contain", etc. */
    }
  </style>


</head>

<body>


<div class="containerlogo">
    <div class="title">FACTURA DE VENTA</div>
    <div class="logo"><img src="'.$imagenBase64.'" alt="Logo"></div>
    </div>

  <div class="container">
    <div class="left-column"">
      <a><strong>Actividad Económica:</strong></a>
      <a>6110 - Actividades de
        telecomunicaciones alámbricas</a>

    </div>
    <div class=" center-column">
      <a><strong>Razon Social</strong>: NJG TELECOMUNICACIONES</a>
      <a><strong>Identificación</strong>:
        1193033331-7</a>
      <a><strong>Teléfono</strong>:
        3022042294</a>
      <p><strong>Dirección</strong>:
        Soledad, Atlantico,
        COLOMBIA.</p>
      <a><strong>Condición IVA: No Aplica</strong></a>
    </div>
    <div class="right-column">
    <a><strong>Numero: </strong>' . $user['number_facture'] . '</a>
      <p><strong>Fecha: </strong>' . $fechaActual . '</p>
      <p><strong>Fecha Vto: </strong>' . $fechaVence . '</p>
      <a><strong>Forma de pago: </strong>Efectivo</a>
    </div>
  </div>

  <hr class="linea-divisoria">
  <div class="container1">
    <div class="left-column-center">
      <a><strong>Sr. (es): </strong>' . $user['names'] . '</a>
      <p><strong>Dirección: </strong>' . $user['address'] . '</p>
      <a><strong>Municipio: </strong>Soledad</a>

    </div>
    <div class="right-column-center">
      <a><strong>CC: </strong> ' . $user['dni'] . '</a>
      <p><strong>Telefono: </strong> ' . $user['phone'] . '</p>
    </div>
  </div>
  <hr class="linea-divisoria">
  <a><strong>Moneda: </strong>Pesos Colombianos</a>
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
        <td>' . $fechaNueva . ' - ' . $fechaInit . ' </td>
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

        $pdfContent = $pdf->output();

        $filePath = storage_path('app/pdf.pdf');
        file_put_contents($filePath, $pdfContent);

        return $html;
    }
}
