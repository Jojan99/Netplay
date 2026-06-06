<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Mailjet\Client;
use Mailjet\Resources;

class InvoiceEmailService
{
    private string $apiKeyPublic;
    private string $apiKeyPrivate;
    private string $fromEmail;
    private string $fromName;
    private bool $enabled;

    public function __construct()
    {
        $this->apiKeyPublic = config('services.mailjet.api_key_public', env('MAILJET_APIKEY_PUBLIC', ''));
        $this->apiKeyPrivate = config('services.mailjet.api_key_private', env('MAILJET_APIKEY_PRIVATE', ''));
        $this->fromEmail = config('services.mailjet.from_email', env('MAILJET_FROM_EMAIL', 'atencionalcliente@netplay.com.co'));
        $this->fromName = config('services.mailjet.from_name', env('MAILJET_FROM_NAME', 'Netplay ISP'));
        $this->enabled = !empty($this->apiKeyPublic) && !empty($this->apiKeyPrivate);
    }

    /**
     * Enviar factura por correo electrónico con PDF adjunto.
     *
     * @param array $userData Datos del cliente (names, lastname, email, number_facture, price_total, date_facturation, etc.)
     * @param string $pdfContent Contenido binario del PDF
     * @param string $filename Nombre del archivo PDF
     * @return array ['status' => 'ok|error', 'message' => '...']
     */
    public function sendInvoice(array $userData, string $pdfContent, string $filename): array
    {
        if (!$this->enabled) {
            Log::warning('[EMAIL_INVOICE] Mailjet no está configurado');
            return ['status' => 'error', 'message' => 'Servicio de correo no configurado'];
        }

        $email = trim($userData['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('[EMAIL_INVOICE] Email inválido o vacío', ['user' => $userData['names'] ?? '']);
            return ['status' => 'error', 'message' => 'El cliente no tiene un correo válido'];
        }

        try {
            $mj = new Client($this->apiKeyPublic, $this->apiKeyPrivate, true, ['version' => 'v3.1']);

            $htmlBody = $this->buildInvoiceHtml($userData);

            $body = [
                'Messages' => [
                    [
                        'From' => [
                            'Email' => $this->fromEmail,
                            'Name'  => $this->fromName,
                        ],
                        'To' => [
                            [
                                'Email' => $email,
                                'Name'  => trim(($userData['names'] ?? '') . ' ' . ($userData['lastname'] ?? '')),
                            ],
                        ],
                        'Subject'     => 'Su Factura #' . ($userData['number_facture'] ?? '') . ' - Netplay ISP',
                        'TextPart'    => $this->buildInvoiceText($userData),
                        'HTMLPart'    => $htmlBody,
                        'Attachments' => [
                            [
                                'ContentType'   => 'application/pdf',
                                'Filename'      => $filename,
                                'Base64Content' => base64_encode($pdfContent),
                            ],
                        ],
                    ],
                ],
            ];

            $response = $mj->post(Resources::$Email, ['body' => $body]);
            $data = $response->getData();

            Log::info('[EMAIL_INVOICE] Respuesta de Mailjet', [
                'email' => $email,
                'facture' => $userData['number_facture'] ?? '',
                'response' => $data,
            ]);

            if (isset($data['Messages'][0]['Status']) && $data['Messages'][0]['Status'] === 'success') {
                return ['status' => 'ok', 'message' => 'Factura enviada correctamente por correo'];
            }

            $errorMsg = $data['Messages'][0]['Errors'][0]['ErrorMessage'] ?? 'Error desconocido de Mailjet';
            return ['status' => 'error', 'message' => $errorMsg];

        } catch (\Throwable $e) {
            Log::error('[EMAIL_INVOICE] Excepción enviando correo', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return ['status' => 'error', 'message' => 'Error enviando correo: ' . $e->getMessage()];
        }
    }

    /**
     * Enviar facturas masivamente por correo.
     *
     * @param array $invoices Array de facturas con 'user', 'pdf_content', 'filename'
     * @return array ['sent' => N, 'failed' => N, 'errors' => [...]]
     */
    public function sendBulkInvoices(array $invoices): array
    {
        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($invoices as $invoice) {
            $result = $this->sendInvoice($invoice['user'], $invoice['pdf_content'], $invoice['filename']);
            if ($result['status'] === 'ok') {
                $sent++;
            } else {
                $failed++;
                $errors[] = [
                    'email'   => $invoice['user']['email'] ?? '',
                    'facture' => $invoice['user']['number_facture'] ?? '',
                    'error'   => $result['message'],
                ];
            }

            // Pequeña pausa entre envíos para no saturar la API de Mailjet
            usleep(200000); // 200ms
        }

        return [
            'sent'   => $sent,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Construir HTML profesional para la factura.
     */
    private function buildInvoiceHtml(array $data): string
    {
        $names = trim(($data['names'] ?? '') . ' ' . ($data['lastname'] ?? ''));
        $numberFacture = $data['number_facture'] ?? '';
        $dateFacturation = $data['date_facturation'] ?? '';
        $total = isset($data['price_total']) ? number_format($data['price_total'] - ($data['price_discount'] ?? 0), 0, ',', '.') : '0';
        $planName = $data['plan_name'] ?? 'Servicio de Internet';
        $monthlyPrice = isset($data['monthly_price']) ? number_format($data['monthly_price'], 0, ',', '.') : '0';
        $address = $data['address'] ?? '';

        return "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Factura {$numberFacture}</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #0056b3 0%, #003d80 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
        .header p { margin: 8px 0 0; opacity: 0.9; font-size: 14px; }
        .content { padding: 30px; }
        .invoice-box { background-color: #f8fafc; border-radius: 8px; padding: 20px; margin: 20px 0; border-left: 4px solid #0056b3; }
        .invoice-box h2 { margin: 0 0 15px; color: #0056b3; font-size: 18px; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
        .detail-row:last-child { border-bottom: none; }
        .detail-row .label { color: #64748b; font-size: 14px; }
        .detail-row .value { color: #1e293b; font-weight: 600; font-size: 14px; }
        .total-box { background: linear-gradient(135deg, #0056b3 0%, #003d80 100%); color: white; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center; }
        .total-box .total-label { font-size: 14px; opacity: 0.9; margin-bottom: 5px; }
        .total-box .total-value { font-size: 28px; font-weight: 700; }
        .message { color: #475569; line-height: 1.7; font-size: 15px; margin: 20px 0; }
        .btn-container { text-align: center; margin: 25px 0; }
        .btn { display: inline-block; background-color: #0056b3; color: white; text-decoration: none; padding: 12px 30px; border-radius: 6px; font-weight: 600; font-size: 14px; }
        .footer { background-color: #f1f5f9; text-align: center; padding: 20px; color: #64748b; font-size: 13px; }
        .footer a { color: #0056b3; text-decoration: none; }
        @media only screen and (max-width: 600px) {
            .container { margin: 0; border-radius: 0; }
            .content { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Netplay ISP</h1>
            <p>Factura de Servicios</p>
        </div>
        <div class='content'>
            <p class='message'>Estimado/a <strong>{$names}</strong>,</p>
            <p class='message'>Le informamos que su factura del mes ha sido generada exitosamente. A continuación encontrará los detalles de su servicio:</p>

            <div class='invoice-box'>
                <h2>Detalle de la Factura</h2>
                <div class='detail-row'>
                    <span class='label'>No. Factura:</span>
                    <span class='value'>#{$numberFacture}</span>
                </div>
                <div class='detail-row'>
                    <span class='label'>Plan:</span>
                    <span class='value'>{$planName}</span>
                </div>
                <div class='detail-row'>
                    <span class='label'>Valor Plan:</span>
                    <span class='value'>\${$monthlyPrice} COP</span>
                </div>
                <div class='detail-row'>
                    <span class='label'>Fecha Límite:</span>
                    <span class='value'>{$dateFacturation}</span>
                </div>
                <div class='detail-row'>
                    <span class='label'>Dirección:</span>
                    <span class='value'>{$address}</span>
                </div>
            </div>

            <div class='total-box'>
                <div class='total-label'>TOTAL A PAGAR</div>
                <div class='total-value'>\${$total} COP</div>
            </div>

            <p class='message'>Adjunto a este correo encontrará su factura en formato PDF. Por favor realice el pago antes de la fecha límite indicada para evitar suspensión del servicio.</p>

            <p class='message'>Si tiene alguna pregunta o requiere asistencia, no dude en contactarnos:</p>
            <p class='message' style='text-align:center;'>
                <strong>Email:</strong> <a href='mailto:atencionalcliente@netplay.com.co'>atencionalcliente@netplay.com.co</a><br>
                <strong>WhatsApp:</strong> <a href='https://wa.me/573001234567'>+57 300 123 4567</a>
            </p>
        </div>
        <div class='footer'>
            <p>Netplay ISP - Conectando su mundo</p>
            <p>&copy; " . date('Y') . " Netplay. Todos los derechos reservados.</p>
            <p>Este es un correo automático, por favor no responda a esta dirección.</p>
        </div>
    </div>
</body>
</html>";
    }

    /**
     * Construir versión texto plano.
     */
    private function buildInvoiceText(array $data): string
    {
        $names = trim(($data['names'] ?? '') . ' ' . ($data['lastname'] ?? ''));
        $numberFacture = $data['number_facture'] ?? '';
        $total = isset($data['price_total']) ? number_format($data['price_total'] - ($data['price_discount'] ?? 0), 0, ',', '.') : '0';

        return "Estimado/a {$names},\n\n"
            . "Le informamos que su factura #{$numberFacture} ha sido generada.\n"
            . "Total a pagar: \${$total} COP\n\n"
            . "Adjunto encontrará su factura en PDF.\n\n"
            . "Gracias por preferir Netplay ISP.\n"
            . "atencionalcliente@netplay.com.co";
    }
}
