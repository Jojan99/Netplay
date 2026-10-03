<?php

namespace App\Services;

use App\Models\Company;
use App\Services\Correo\Correo;
use Illuminate\Support\Facades\Log;

/**
 * Correo de factura con la marca de la empresa dueña de la factura.
 * Sale por la cuenta de Mailjet propia de la empresa si la conectó; si no, por
 * la de la plataforma (no-reply@netvula.com) con el nombre de la empresa y las
 * respuestas (Reply-To) al correo de la empresa. Ver App\Services\Correo\Correo.
 */
class InvoiceEmailService
{
    private Correo $correo;

    private string $empresa;
    private string $empresaEmail;
    private string $empresaTelefono;
    private string $empresaPie;
    private ?string $logo;

    public function __construct(private Company $company)
    {
        $this->correo = Correo::deEmpresa($company);

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
        if (!$this->correo->configurado()) {
            Log::warning('[EMAIL_INVOICE] Mailjet no está configurado', ['company_id' => $this->company->id]);
            return ['status' => 'error', 'message' => 'Servicio de correo no configurado'];
        }

        $email = trim($userData['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('[EMAIL_INVOICE] Email inválido o vacío', ['user' => $userData['names'] ?? '']);
            return ['status' => 'error', 'message' => 'El cliente no tiene un correo válido'];
        }

        $adjuntos = [[
            'ContentType'   => 'application/pdf',
            'Filename'      => $filename,
            'Base64Content' => base64_encode($pdfContent),
        ]];

        // Logo como imagen incrustada: los clientes de correo bloquean data: URIs.
        if ($this->logo && preg_match('#^data:(image/[a-z+.-]+);base64,(.+)$#is', $this->logo, $m)) {
            $adjuntos[] = [
                'ContentType'   => strtolower($m[1]),
                'Filename'      => 'logo.' . (str_contains(strtolower($m[1]), 'png') ? 'png' : 'jpg'),
                'ContentID'     => 'logo',
                'Base64Content' => preg_replace('/\s+/', '', $m[2]),
            ];
        }

        $resultado = $this->correo->enviar(
            ['email' => $email, 'nombre' => trim(($userData['names'] ?? '') . ' ' . ($userData['lastname'] ?? ''))],
            'Su Factura #' . ($userData['number_facture'] ?? '') . ($this->empresa !== '' ? ' - ' . $this->empresa : ''),
            $this->buildInvoiceHtml($userData),
            $this->buildInvoiceText($userData),
            $adjuntos,
        );

        return $resultado['ok']
            ? ['status' => 'ok', 'message' => 'Factura enviada correctamente por correo']
            : ['status' => 'error', 'message' => $resultado['detalle']];
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
     * La plantilla del correo de la factura, con el diseño común de la plataforma.
     *
     * Todo lo que muestra sale de dos lugares y de ninguno más: la factura real del cliente
     * ($data, la misma fila con la que se arma el PDF) y el membrete que la empresa parametrizó
     * para sus facturas (teléfono, NIT, dirección, medios de pago, pie). Lo que no esté cargado
     * no se muestra: aquí no se rellena nada con valores supuestos.
     */
    private function plantilla(array $data): \App\Services\Correo\PlantillaDeCorreo
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $pesos = fn ($v) => '$ ' . number_format((float) $v, 0, ',', '.');
        $m = \App\Resources\Templates\TemplatesPdf::datosEmpresa($this->company);

        $nombre = trim(($data['names'] ?? '') . ' ' . ($data['lastname'] ?? ''));
        $valor = (float) ($data['price_total'] ?? 0);
        $descuento = (float) ($data['price_discount'] ?? 0);
        $abonado = (float) ($data['price_abone'] ?? 0);
        $saldo = max(0, $valor - $descuento - $abonado);
        $limite = !empty($data['date_facturation']) ? self::fechaLarga((string) $data['date_facturation']) : null;
        $concepto = trim((string) ($data['concepto'] ?? ''));
        $conLogo = $this->logo && str_starts_with($this->logo, 'data:');

        $p = \App\Services\Correo\PlantillaDeCorreo::deEmpresa($this->company, $conLogo ? 'logo' : null)
            ->antetitulo('Factura de servicios')
            ->titulo('Su factura #' . ($data['number_facture'] ?? '') . ' ya está lista')
            ->parrafo('Hola' . ($nombre !== '' ? ', <strong>' . $e($nombre) . '</strong>' : '') . '.')
            ->parrafo('Le compartimos la factura de su servicio. La encuentra adjunta en PDF y este es el resumen:')
            ->cifra('Total a pagar', $pesos($saldo), $limite ? 'Fecha límite de pago: ' . $limite : null)
            ->datos([
                'Factura' => '#' . ($data['number_facture'] ?? ''),
                'Cliente' => $nombre ?: null,
                'Documento' => !empty($data['dni']) ? (string) $data['dni'] : null,
                'Concepto' => $concepto !== '' ? $concepto : null,
                'Plan' => !empty($data['plan_name']) ? (string) $data['plan_name'] : null,
                'Valor' => $pesos($valor),
                'Descuento' => $descuento > 0 ? '- ' . $pesos($descuento) : null,
                'Abonado' => $abonado > 0 ? '- ' . $pesos($abonado) : null,
                'Fecha límite de pago' => $limite,
                'Dirección del servicio' => !empty($data['address']) ? (string) $data['address'] : null,
            ]);

        // Los medios de pago que la empresa puso en su factura, tal cual los escribió.
        $pago = trim((string) $m['payment_info']);

        if ($pago !== '' && !preg_match('/^[\-–—.\s]*$/u', $pago)) {
            $p->textoDeLaEmpresa('Medios de pago', $pago);
        }

        $p->parrafo('Si ya realizó el pago, puede ignorar este mensaje.');

        // El WhatsApp es el teléfono de la factura, si es un celular; si no, no hay botón.
        $digitos = preg_replace('/\D+/', '', (string) $m['phone']);
        $digitos = strlen($digitos) === 10 && $digitos[0] === '3' ? '57' . $digitos : $digitos;

        if (preg_match('/^573\d{9}$/', $digitos)) {
            $p->boton('Escribirnos por WhatsApp', 'https://wa.me/' . $digitos);
        }

        $pie = trim((string) $this->company->invoice_footer);

        return $p->nota(trim(($pie !== '' ? $e($pie) . '<br>' : '')
            . ($this->empresaEmail !== '' ? 'Puede responder a este correo para comunicarse con nosotros.' : 'Este es un correo automático: por favor no responda a esta dirección.')));
    }

    private static function fechaLarga(string $fecha): string
    {
        try {
            return \Carbon\Carbon::parse($fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
        } catch (\Throwable) {
            return $fecha;
        }
    }

    private function buildInvoiceHtml(array $data): string
    {
        return $this->plantilla($data)->html();
    }

    private function buildInvoiceText(array $data): string
    {
        return $this->plantilla($data)->texto();
    }
}
