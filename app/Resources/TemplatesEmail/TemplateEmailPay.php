<?php

namespace App\Resources\TemplatesEmail;

use App\Constants\ApiResponseConstants;
use Mailjet\Client;
use \Mailjet\Resources;


class TemplateEmailPay
{
  public function EmailPay($data,$price,$id_facture): mixed
  {
    // Aviso interno de abono para la empresa del operador. Antes iba, desde
    // cualquier empresa, a dos correos personales de Netplay con claves de
    // Mailjet escritas en el código.
    $empresa = \App\Models\Company::find(getSessionCompanyId());
    $destino = trim((string) ($empresa?->email ?? ''));
    $desde   = trim((string) config('services.mailjet.from_email', ''));
    $public  = (string) config('services.mailjet.api_key_public', '');
    $private = (string) config('services.mailjet.api_key_private', '');
    if (!$empresa || !filter_var($destino, FILTER_VALIDATE_EMAIL) || $desde === '' || $public === '' || $private === '') {
      return ['message' => 'Aviso de pago no enviado: la empresa no tiene correo o el correo no está configurado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
    }
    $nombreEmpresa = trim((string) $empresa->invoice_business_name) ?: trim((string) $empresa->name);
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $html = "
    <!DOCTYPE html>
    <html lang='es'>
    <head>
      <meta charset='UTF-8'>
      <meta name='viewport' content='width=device-width, initial-scale=1.0'>
      <style>
        body {
          font-family: Arial, sans-serif;
          background-color: #f4f4f4;
          margin: 0;
          padding: 0;
        }
        .container {
          max-width: 600px;
          margin: 50px auto;
          background-color: #ffffff;
          border-radius: 8px;
          box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
          overflow: hidden;
        }
        .header {
          background-color: #4CAF50;
          color: white;
          text-align: center;
          padding: 20px;
        }
        .content {
          padding: 20px;
        }
        .content h1 {
          color: #333333;
        }
        .content p {
          color: #666666;
          line-height: 1.6;
        }
        .footer {
          background-color: #f1f1f1;
          text-align: center;
          padding: 10px;
          color: #777777;
        }
      </style>
    </head>
    <body>
      <div class='container'>
        <div class='header'>
          <h2>Pago Exitoso: ".$e($id_facture)."</h2>
        </div>
        <div class='content'>
          <h1>¡Gracias por tu pago! Valor</h1>
          <a><strong>Sr. (es): </strong>" . $e($data['names'] ?? '') . " " . $e($data['lastname'] ?? '') . "</a>
          <p>Hemos recibido tu pago con éxito. Ahora puedes disfrutar de nuestros servicios sin interrupciones.</p>
          <a><strong>Valor: </strong>" . $e($price) ."</a>
          <p>Si tienes alguna pregunta, no dudes en contactarnos.</p>
        </div>
        <div class='footer'>
          <p>&copy; " . date('Y') . " " . $e($nombreEmpresa) . ". Todos los derechos reservados.</p>
        </div>
      </div>
    </body>
    </html>
";

    $mj = new Client($public, $private, true, ['version' => 'v3.1']);
    $body = [
      'Messages' => [
        [
          'From' => [
            'Email' => $desde,
            'Name' => $nombreEmpresa
          ],
          'To' => [
            [
              'Email' => $destino,
              'Name' => $nombreEmpresa
            ]
          ],
          'Subject' => "PAGO EXITOSO",
          'TextPart' => "Pago recibido " . $id_facture . ": " . $price,
          'HTMLPart' => $html,
      //     'Attachments' => [
      //   [
      //     'ContentType' => "application/pdf", // Tipo de contenido del archivo
      //     'Filename' => "factura.pdf", // Nombre del archivo
      //     'Base64Content' => base64_encode(file_get_contents("/ruta/al/archivo/factura.pdf")) // Contenido codificado en Base64
      //   ]
      // ]
        ]
      ]
    ];
    try {
      $response = $mj->post(Resources::$Email, ['body' => $body]);
    } catch (\Throwable $err) {
      // El aviso no debe tumbar el registro del abono
      return ['message' => 'The mail could not be sent', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
    }

    
    $responseEmail = $response->getData();

    error_log(json_encode($responseEmail));

    if (($responseEmail['Messages'][0]['Status'] ?? 'error') == "error") {
      return [
        'message' => 'The mail could not be sent',
        'status' => 1,
        'data' => ApiResponseConstants::DATA_NULL
      ];
    } else if (($responseEmail['Messages'][0]['Status'] ?? '') == "success") {
      return [
        'message' => 'Email sent successfully',
        'status' => 0,
        'data' => ApiResponseConstants::DATA_NULL
      ];
    }
  }
}
