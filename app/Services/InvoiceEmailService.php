<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Mailjet\Client;
use Mailjet\Resources;

/**
 * Correo de factura con la marca de la empresa dueña de la factura.
 * La dirección remitente es la verificada en Mailjet (MAILJET_FROM_EMAIL); el
 * nombre, las respuestas (Reply-To) y todo el contenido son de la empresa.
 */
class InvoiceEmailService
{
    private string $apiKeyPublic;
    private string $apiKeyPrivate;
    private string $fromEmail;
    private bool $enabled;

    private string $empresa;
    private string $empresaEmail;
    private string $empresaTelefono;
    private string $empresaPie;
    private ?string $logo;

    public function __construct(private Company $company)
    {
        $this->apiKeyPublic = (string) config('services.mailjet.api_key_public', '');
        $this->apiKeyPrivate = (string) config('services.mailjet.api_key_private', '');
        $this->fromEmail = trim((string) config('services.mailjet.from_email', ''));
        $this->enabled = $this->apiKeyPublic !== '' && $this->apiKeyPrivate !== '' && $this->fromEmail !== '';

        $this->empresa         = trim((string) $company->invoice_business_name) ?: trim((string) $company->name);
        $this->empresaEmail    = filter_var(trim((string) $company->email), FILTER_VALIDATE_EMAIL) ? trim((string) $company->email) : '';
        $this->empresaTelefono = trim((string) $company->invoice_phone) ?: trim((string) $company->phone);
        $this->empresaPie      = trim((string) $company->invoice_footer);
        $this->logo            = \App\Resources\Templates\TemplatesPdf::logoEmpresa($company);
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

            $mensaje = [
                'From' => [
                    'Email' => $this->fromEmail,
                    'Name'  => $this->empresa,
                ],
                'To' => [
                    [
                        'Email' => $email,
                        'Name'  => trim(($userData['names'] ?? '') . ' ' . ($userData['lastname'] ?? '')),
                    ],
                ],
                'Subject'     => 'Su Factura #' . ($userData['number_facture'] ?? '') . ($this->empresa !== '' ? ' - ' . $this->empresa : ''),
                'TextPart'    => $this->buildInvoiceText($userData),
                'HTMLPart'    => $htmlBody,
                'Attachments' => [
                    [
                        'ContentType'   => 'application/pdf',
                        'Filename'      => $filename,
                        'Base64Content' => base64_encode($pdfContent),
                    ],
                ],
            ];

            // Las respuestas del cliente van al correo de la empresa.
            if ($this->empresaEmail !== '') {
                $mensaje['ReplyTo'] = ['Email' => $this->empresaEmail, 'Name' => $this->empresa];
            }

            // Logo como imagen incrustada: los clientes de correo bloquean data: URIs.
            if ($this->logo && preg_match('#^data:(image/[a-z+.-]+);base64,(.+)$#is', $this->logo, $m)) {
                $mensaje['InlinedAttachments'] = [[
                    'ContentType'   => strtolower($m[1]),
                    'Filename'      => 'logo.' . (str_contains(strtolower($m[1]), 'png') ? 'png' : 'jpg'),
                    'ContentID'     => 'logo',
                    'Base64Content' => preg_replace('/\s+/', '', $m[2]),
                ]];
            }

            $body = ['Messages' => [$mensaje]];

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
        $successfulIds = [];

        foreach ($invoices as $invoice) {
            $result = $this->sendInvoice($invoice['user'], $invoice['pdf_content'], $invoice['filename']);
            if ($result['status'] === 'ok') {
                $sent++;
                if (!empty($invoice['det_facturation_id'])) {
                    $successfulIds[] = $invoice['det_facturation_id'];
                }
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
            'successful_ids' => $successfulIds,
        ];
    }

    /**
     * Construir HTML profesional para la factura.
     */
    private function buildInvoiceHtml(array $data): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $names = $e(trim(($data['names'] ?? '') . ' ' . ($data['lastname'] ?? '')));
        $numberFacture = $e($data['number_facture'] ?? '');
        $dateFacturation = $e($data['date_facturation'] ?? '');
        $total = isset($data['price_total']) ? number_format($data['price_total'] - ($data['price_discount'] ?? 0), 0, ',', '.') : '0';
        $planName = $e($data['plan_name'] ?? 'Servicio de Internet');
        $monthlyPrice = isset($data['monthly_price']) ? number_format($data['monthly_price'], 0, ',', '.') : '0';
        $address = $e($data['address'] ?? '');

        // Marca de la empresa de la factura
        $empresa = $e($this->empresa);
        $logoHtml = $this->logo && str_starts_with($this->logo, 'data:')
            ? "<img src='cid:logo' alt='{$empresa}' style='max-height:60px;max-width:200px;margin-bottom:10px;'><br>"
            : '';

        $contacto = [];
        if ($this->empresaEmail !== '') {
            $mail = $e($this->empresaEmail);
            $contacto[] = "<strong>Email:</strong> <a href='mailto:{$mail}'>{$mail}</a>";
        }
        if ($this->empresaTelefono !== '') {
            $digitos = preg_replace('/\D+/', '', $this->empresaTelefono);
            if (strlen($digitos) === 10) {
                $digitos = '57' . $digitos;
            }
            $tel = $e($this->empresaTelefono);
            $contacto[] = strlen($digitos) >= 11
                ? "<strong>WhatsApp:</strong> <a href='https://wa.me/{$digitos}'>{$tel}</a>"
                : "<strong>Teléfono:</strong> {$tel}";
        }
        $contactoHtml = $contacto
            ? "<p class='message'>Si tiene alguna pregunta o requiere asistencia, no dude en contactarnos:</p>
            <p class='message' style='text-align:center;'>" . implode('<br>', $contacto) . "</p>"
            : '';

        $pie = $this->empresaPie !== '' ? "<p>" . $e($this->empresaPie) . "</p>" : '';
        $derechos = $empresa !== '' ? "<p>&copy; " . date('Y') . " {$empresa}. Todos los derechos reservados.</p>" : '';
        $responder = $this->empresaEmail !== ''
            ? '<p>Puede responder a este correo para comunicarse con nosotros.</p>'
            : '<p>Este es un correo automático, por favor no responda a esta dirección.</p>';

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
            {$logoHtml}
            <h1>{$empresa}</h1>
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

            {$contactoHtml}
        </div>
        <div class='footer'>
            {$pie}
            {$derechos}
            {$responder}
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
            . ($this->empresa !== '' ? "Gracias por preferir {$this->empresa}.\n" : '')
            . $this->empresaEmail;
    }
}
