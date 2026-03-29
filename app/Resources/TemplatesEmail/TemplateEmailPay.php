<?php

namespace App\Resources\TemplatesEmail;

use App\Constants\ApiResponseConstants;
use Mailjet\Client;
use \Mailjet\Resources;


class TemplateEmailPay
{
  public function EmailPay($data,$price,$id_facture): mixed
  {
    
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
          <h2>Pago Exitoso: ".$id_facture."</h2>
        </div>
        <div class='content'>
          <h1>¡Gracias por tu pago! Valor</h1>
          <a><strong>Sr. (es): </strong>" . $data['names'] . " " . $data['lastname'] . "</a>
          <p>Hemos recibido tu pago con éxito. Ahora puedes disfrutar de nuestros servicios sin interrupciones.</p>
          <a><strong>Valor: </strong>" . $price ."</a>
          <p>Si tienes alguna pregunta, no dudes en contactarnos.</p>
        </div>
        <div class='footer'>
          <p>&copy; 2024 Netplay. Todos los derechos reservados.</p>
        </div>
      </div>
    </body>
    </html>
";

    $mj = new Client(('0ad0ef7d5bf8c1139875a80397e6de7b'), ('b2aeeed4129241ac2811174578f6c5d3'), true, ['version' => 'v3.1']);
    $body = [
      'Messages' => [
        [
          'From' => [
            'Email' => "atencionalcliente@netplay.com.co",
            'Name' => "Netplay"
          ],
          'To' => [
            [
              'Email' => "jojanemece@gmail.com",
              'Name' => "Jojan"
            ],
            [
              'Email' => "mlxhumbertopte@gmail.com",
              'Name' => "Humberto"
            ]
          ],
          'Subject' => "PAGO EXITOSO",
          'TextPart' => "AAAA",
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
    $response = $mj->post(Resources::$Email, ['body' => $body]);

    
    $responseEmail = $response->getData();

    error_log(json_encode($responseEmail));

    if ($responseEmail['Messages'][0]['Status'] == "error") {
      return [
        'message' => 'The mail could not be sent',
        'status' => 1,
        'data' => ApiResponseConstants::DATA_NULL
      ];
    } else if ($responseEmail['Messages'][0]['Status'] == "success") {
      return [
        'message' => 'Email sent successfully',
        'status' => 0,
        'data' => ApiResponseConstants::DATA_NULL
      ];
    }
  }
}
