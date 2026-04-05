<?php

namespace App\Resources\TemplatesEmail;

use Mailjet\Client;
use Mailjet\Resources;

class TemplateEmailCompanyConfirmation
{
    public function sendConfirmation(string $toEmail, string $companyName, string $confirmUrl): void
    {
        $html = "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
          <meta charset='UTF-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
          <style>
            body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 50px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background-color: #1a73e8; color: white; text-align: center; padding: 30px 20px; }
            .header h2 { margin: 0; font-size: 22px; }
            .content { padding: 30px 20px; }
            .content p { color: #555555; line-height: 1.7; font-size: 15px; }
            .btn { display: inline-block; margin: 20px 0; padding: 14px 32px; background-color: #1a73e8; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold; }
            .footer { background-color: #f1f1f1; text-align: center; padding: 12px; color: #888888; font-size: 12px; }
          </style>
        </head>
        <body>
          <div class='container'>
            <div class='header'>
              <h2>Confirma tu empresa en Netplay</h2>
            </div>
            <div class='content'>
              <p>Hola, <strong>" . htmlspecialchars($companyName) . "</strong>.</p>
              <p>Tu empresa ha sido registrada exitosamente en la plataforma <strong>Netplay ISP</strong>.</p>
              <p>Para activar tu cuenta y comenzar a operar, por favor confirma tu correo electrónico haciendo clic en el siguiente botón:</p>
              <p style='text-align:center;'>
                <a href='" . htmlspecialchars($confirmUrl) . "' class='btn'>Confirmar correo</a>
              </p>
              <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
              <p style='word-break:break-all; color:#1a73e8;'>" . htmlspecialchars($confirmUrl) . "</p>
              <p>Este enlace expira en <strong>24 horas</strong>.</p>
              <p>Si no solicitaste este registro, ignora este correo.</p>
            </div>
            <div class='footer'>
              &copy; " . date('Y') . " Netplay ISP. Todos los derechos reservados.
            </div>
          </div>
        </body>
        </html>";

        $mj = new Client(
            env('MAILJET_APIKEY_PUBLIC'),
            env('MAILJET_APIKEY_PRIVATE'),
            true,
            ['version' => 'v3.1']
        );

        $body = [
            'Messages' => [
                [
                    'From' => [
                        'Email' => env('MAILJET_FROM_EMAIL', 'atencionalcliente@netplay.com.co'),
                        'Name'  => env('MAILJET_FROM_NAME', 'Netplay ISP'),
                    ],
                    'To' => [
                        ['Email' => $toEmail, 'Name' => $companyName],
                    ],
                    'Subject'  => 'Confirma tu empresa en Netplay ISP',
                    'TextPart' => "Confirma tu empresa: {$confirmUrl}",
                    'HTMLPart' => $html,
                ],
            ],
        ];

        $response = $mj->post(Resources::$Email, ['body' => $body]);
        error_log('MailJet company confirmation: ' . json_encode($response->getData()));
    }
}
