<?php

namespace App\Resources\Templates;

use App\Models\Company;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;

class TemplatesPdf
{

  /**
   * Load company invoice config, falling back to sensible defaults.
   */
  private function loadCompany(?int $companyId = null): array
  {
    $id = $companyId ?? (function_exists('getSessionCompanyId') ? getSessionCompanyId() : null);
    $company = $id ? Company::find($id) : null;

    $defaultLogoPath = realpath(__DIR__ . "/../../../resources/img/NET-PLAY-LOGO-Mesa-de-trabajo-1.jpg");

    // Resolve logo to base64
    $logoBase64 = null;
    
    // First try: use invoice_logo_base64 directly (already in base64 format)
    if ($company?->invoice_logo_base64) {
      $logoBase64 = $company->invoice_logo_base64;
    }
    // Fallback: if invoice_logo_url is already base64, use it directly
    elseif ($company?->invoice_logo_url && str_starts_with($company->invoice_logo_url, 'data:')) {
      $logoBase64 = $company->invoice_logo_url;
    }
    // Legacy: if invoice_logo_url is a URL, try to convert it
    elseif ($company?->invoice_logo_url && filter_var($company->invoice_logo_url, FILTER_VALIDATE_URL)) {
      try {
        $data = @file_get_contents($company->invoice_logo_url);
        if ($data) {
          $mime = 'image/jpeg';
          if (str_ends_with(strtolower(parse_url($company->invoice_logo_url, PHP_URL_PATH) ?? ''), '.png')) {
            $mime = 'image/png';
          }
          $logoBase64 = "data:{$mime};base64," . base64_encode($data);
        }
      } catch (\Throwable) {}
    }
    // Fallback to company logo
    elseif ($company?->logo && filter_var($company->logo, FILTER_VALIDATE_URL)) {
      try {
        $data = @file_get_contents($company->logo);
        if ($data) {
          $logoBase64 = "data:image/jpeg;base64," . base64_encode($data);
        }
      } catch (\Throwable) {}
    }
    
    // Last resort: use default logo
    if (!$logoBase64 && $defaultLogoPath && file_exists($defaultLogoPath)) {
      $logoBase64 = "data:image/jpeg;base64," . base64_encode(file_get_contents($defaultLogoPath));
    }

    return [
      'business_name'      => $company?->invoice_business_name ?? $company?->name ?? 'SOLUCIONES NETPLAY S.A.S',
      'nit'                => $company?->invoice_nit            ?? $company?->nit  ?? '901911441-2',
      'phone'              => $company?->invoice_phone          ?? $company?->phone ?? '3022042294',
      'address'            => $company?->invoice_address        ?? $company?->address ?? 'Soledad, Atlantico',
      'city'               => $company?->invoice_city           ?? 'Soledad',
      'country'            => $company?->invoice_country        ?? 'COLOMBIA',
      'iva_condition'      => $company?->invoice_iva_condition  ?? 'No Aplica',
      'economic_activity'  => $company?->invoice_economic_activity ?? '6110 - Actividades de telecomunicaciones alámbricas',
      'payment_info'       => $company?->invoice_payment_info   ?? "- BANCOLOMBIA CTA AHO 47800013328\n- DAVIPLATA 3022042294\n- NEQUI 3022042294",
      'footer'             => $company?->invoice_footer         ?? '¡Gracias por preferirnos!',
      'logo_base64'        => $logoBase64,
    ];
  }

  public function PdfFacturas($user, $Cab, ?int $companyId = null): mixed
  {

    json_encode($Cab);
    $priceAntFactura = $Cab;

    $fechaInit = substr($user['date_facturation'], 0, 10);
    $fechaNueva = date('Y-m-d', strtotime($fechaInit . ' -1 month'));
    $fechaActual = date('Y-m-d');
    $fechaVence = date('Y-m-d', strtotime($fechaInit . ' +3 days'));
    $Porcentage = $user['porcentage_discount'];
    $daysFacture = $user['days_facture'];
    $saldoTotal = $user['price_total'] - $user['price_discount'];
    $valorDescuento = $user['price_discount'];

    if($user['create_facture_manual'] == 1){
      $user['monthly_price'] = $user['price_total'];
    }

    $co = $this->loadCompany($companyId);
    $imagenBase64 = $co['logo_base64'] ?? '';

    $html = '
        <!DOCTYPE html>
<html>

<head>
  <meta charset="UTF-8">
  <title>Factura</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      font-size: 12px; 
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
      font-size: 12px; 
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
    <div class="logo"><img src="' . $imagenBase64 . '" alt="Logo"></div>
    </div>

  <div class="container">
    <div class="left-column"">
      <a><strong>Actividad Económica:</strong></a>
      <a>' . htmlspecialchars($co['economic_activity']) . '</a>
    </div>
    <div class="center-column">
      <a><strong>Razon Social</strong>: ' . htmlspecialchars($co['business_name']) . '</a>
      <a><strong>Identificación</strong>: ' . htmlspecialchars($co['nit']) . '</a>
      <a><strong>Teléfono</strong>: ' . htmlspecialchars($co['phone']) . '</a>
      <p><strong>Dirección</strong>: ' . htmlspecialchars($co['address']) . ', ' . htmlspecialchars($co['country']) . '</p>
      <a><strong>Condición IVA: ' . htmlspecialchars($co['iva_condition']) . '</strong></a>
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
      <a><strong>Sr. (es): </strong>' . $user['names'] . ' ' . $user['lastname'] . '</a>
      <p><strong>Dirección: </strong>' . $user['address'] . '</p>
      <a><strong>Municipio: </strong>' . htmlspecialchars($co['city']) . '</a>
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
        <th class="descuento">Dias facturados.</th>
        <th class="total">Total</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>' . $user['plan_name'] . '</td>
        <td>' . $fechaNueva . ' - ' . $fechaInit . '</td>
        <td class="iva">0%</td>
        <td>' . $user['monthly_price'] . '</td>
        <td class="descuento">' . $Porcentage . '</td>
        <td class="descuento">' . $daysFacture . '</td>
        <td>' . $saldoTotal . '</td>
      </tr>
      <tr>
        <td>' . $user['plan_name'] . '</td>
        <td>SALDO ANTERIOR</td>
        <td class="iva">-</td>
        <td>' . $priceAntFactura . '</td>
        <td class="descuento">-</td>
        <td class="descuento">-</td>
        <td>' . $priceAntFactura . '</td>
      </tr>
    </tbody>
  </table>
  <div class="additional-field">
    <p><strong>Subtotal:</strong> ' . ($user['monthly_price'] + $priceAntFactura) . '</p>
    <p><strong>Descuento:</strong> ' . $valorDescuento . '</p>
    <p><strong>Total Bruto:</strong> ' . (($saldoTotal) + $priceAntFactura) . '</p>
  </div>
  <div class="bottom-section">
    <p><strong>Valor a Pagar: ' . (($saldoTotal) + $priceAntFactura) . '</strong></p>
    <p style="font-size:10px;white-space:pre-line;">' . htmlspecialchars($co['payment_info']) . '</p>
    <p style="font-size:10px;">' . htmlspecialchars($co['footer']) . '</p>
  </div>
</body>

</html>
';

    return $html;
  }



  public function PdfReceiptPay($dataUser, $Cab, $extraParam, ?int $companyId = null)
  {
    $co = $this->loadCompany($companyId);

    error_log(json_encode($dataUser));
    $valorPrice = $dataUser['abone'] == 1 ? $extraParam : $dataUser['price_total'] - $dataUser['price_discount'];
    // Crear una instancia de Dompdf
    $dompdf = new Dompdf();
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('defaultFont', 'Arial');
    $options->set('isRemoteEnabled', true);
    $options->set('isFontSubsettingEnabled', true);
    $dompdf->setOptions($options);
    $dompdf->setPaper(array(0, 0, 450, 300), 'landscape');

    // HTML para el recibo
    $html = '
    <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Pago</title>
    <style>
        body {
            font-family: "Courier New", monospace;
            margin: 0;
            padding: 0;
            background-color: #fff;
            color: #000;
        }
        .container {
            max-width: 400px;
            margin: 0 auto;
            padding: 20px;
            background-color: #fff;
            border: 1px solid #000;
        }
        .header, .footer {
            text-align: center;
        }
        .header h1 {
            font-size: 18px;
            margin: 0;
        }
        .details, .item-list {
            margin-bottom: 20px;
            font-size: 14px;
        }
        .details p, .item-list p {
            margin: 5px 0;
        }
        .item-list {
            border-top: 1px dashed #000;
            padding-top: 10px;
        }
        .item-list p {
            display: flex;
            justify-content: space-between;
        }
        .item-list .description {
            flex: 1;
        }
        .item-list .value {
            flex: auto;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>' . htmlspecialchars($co['business_name']) . '</h1>
            <p>Régimen fiscal: ' . htmlspecialchars($co['iva_condition']) . '</p>
            <p>' . htmlspecialchars($co['city']) . '</p>
            <p>NIT: ' . htmlspecialchars($co['nit']) . '</p>
            <p>Tel: ' . htmlspecialchars($co['phone']) . '</p>
            <p>' . htmlspecialchars($co['address']) . '</p>
            <p>SISTEMA P.O.S</p>
            <h2>RECIBO DE PAGO</h2>
        </div>
        <div class="details">
            <p><strong>Fecha:</strong> '.htmlspecialchars($dataUser['updated_at'], ENT_QUOTES, 'UTF-8').'</p>
            <p><strong># Factura:</strong> '.$dataUser['number_facture'].'</p>
            <p><strong>Cliente:</strong> '. htmlspecialchars($dataUser['names'], ENT_QUOTES, 'UTF-8'). ' ' . htmlspecialchars($dataUser['lastname'], ENT_QUOTES, 'UTF-8') .'</p>
        </div>
        <div class="item-list">
            <p><span class="description"><strong>DESCRIPCION</strong></p>
            <p><span class="description">'. htmlspecialchars($dataUser['plan_name'], ENT_QUOTES, 'UTF-8') .'</span></p>
            <p><span class="description">SUBTOTAL</span> <span class="value">'.number_format($valorPrice, 2, ',', '.').'</span></p>
            <p><span class="description">IVA</span> <span class="value">$0.00</span></p>
            <p><span class="description">TOTAL A PAGAR</span> <span class="value">'.number_format($valorPrice, 2, ',', '.').'</span></p>
            <p><span class="description">PAGO DE FACTURA</span></p>
            <p><span class="description">SALDO</span> <span class="value">'.number_format($Cab, 2, ',', '.').'</span></p>
        </div>
        <div class="footer">
            <p>¡SOLICITE SIEMPRE SU RECIBO DE PAGO!</p>
        </div>
    </div>
</body>
</html>

';
    return $html;
  }
  public function PdfFacturasFacture($user): mixed
  {
    $fechaInit = substr($user['date_facturation'], 0, 10);
    $fechaNueva = date('Y-m-d', strtotime($fechaInit . ' -1 month'));
    $fechaActual = date('Y-m-d');
    $fechaVence = date('Y-m-d', strtotime($fechaInit . ' +3 days'));

    $Porcentage = $user['porcentage_discount'];
    $daysFacture = $user['days_facture'];
    $saldoTotal = $user['price_total'];
    $valorDescuento = 0;
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
      font-size: 12px; 
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
      font-size: 12px; 
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
    <div class="logo"><img src="' . $imagenBase64 . '"  alt="Logo"></div>
    </div>

  <div class="container">
    <div class="left-column"">
      <a><strong>Actividad Económica:</strong></a>
      <a>6110 - Actividades de
        telecomunicaciones alámbricas</a>

    </div>
    <div class=" center-column">
      <a><strong>Razon Social</strong>: Soluciones Netplay S.A.S</a>
      <a><strong>Identificación</strong>:
        901911441-2</a>
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
      <a><strong>Sr. (es): </strong>' . $user['names'] . ' ' . $user['lastname'] . '</a>
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
        <th class="descuento">Dias Dto.</th>
        <th class="total">Total</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>' . $user['plan_name'] . '</td>
        <td>' . $fechaNueva . ' - ' . $fechaInit . ' </td>
        <td class="iva">0%</td>
        <td>' . $saldoTotal . '</td>
        <td class="descuento">' . $Porcentage . '</td>
        <td class="descuento">' . $daysFacture . '</td>
        <td>' . $saldoTotal . '</td>
      </tr>
    </tbody>
  </table>
  <div class="additional-field">
    <p><strong>Subtotal:</strong> ' . $saldoTotal . '</p>
    <p><strong>Descuento:</strong> ' . $valorDescuento . '</p>
    <p><strong>Total Bruto:</strong> ' . $saldoTotal . '</p>
  </div>
  <div class="bottom-section">
    <p><strong>Valor a Pagar: ' . $saldoTotal . '</strong></p>
  </div>
</body>

</html>
';

    return $html;
  }

}

