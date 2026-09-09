<?php

namespace App\Resources\Templates;

use App\Models\Company;
use App\Models\InvoiceTemplate;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;

class TemplatesPdf
{
  use InvoiceDesigns;   // diseños de la factura (clásica, moderna, minimal, tirilla)


  /**
   * Load company invoice config, falling back to sensible defaults.
   */
  private function loadCompany(?int $companyId = null): array
  {
    $id = $companyId ?? (function_exists('getSessionCompanyId') ? getSessionCompanyId() : null);
    $company = $id ? Company::with('invoiceTemplate')->find($id) : null;

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

    // Load template config if exists
    $templateType = 'classic';
    $templateConfig = [];
    if ($company?->invoiceTemplate) {
        $templateType = $company->invoiceTemplate->type ?? 'classic';
        $templateConfig = $company->invoiceTemplate->config ?? [];
    } else {
        // Fallback: try to find default template for company
        $defaultTemplate = InvoiceTemplate::where('company_id', $id)
            ->where('is_default', true)
            ->first();
        if ($defaultTemplate) {
            $templateType = $defaultTemplate->type;
            $templateConfig = $defaultTemplate->config ?? [];
        }
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
      'template_type'      => $templateType,
      'template_config'    => $templateConfig,
    ];
  }

  /**
   * Convert user data to array if it's an object (Eloquent model)
   */
  private function userToArray($user): array
  {
    if (is_array($user)) {
      return $user;
    }
    if (is_object($user) && method_exists($user, 'toArray')) {
      return $user->toArray();
    }
    if (is_object($user)) {
      return (array) $user;
    }
    return [];
  }

  public function PdfFacturas($user, $Cab, ?int $companyId = null): mixed
  {
    $userArray = $this->userToArray($user);
    $co = $this->loadCompany($companyId);
    $type = $co['template_type'];
    $config = $co['template_config'];
    return $this->renderTemplate($type, $userArray, $co, $Cab, $config);
  }

  public function PdfFacturasFacture($user): mixed
  {
    $userArray = $this->userToArray($user);
    $co = $this->loadCompany();
    $type = $co['template_type'];
    $config = $co['template_config'];
    return $this->renderTemplate($type, $userArray, $co, 0, $config);
  }

  public function PdfReceiptPay($dataUser, $Cab, $extraParam, ?int $companyId = null)
  {
    $co = $this->loadCompany($companyId);
    return $this->renderReceipt($dataUser, $co, $Cab, $extraParam);
  }

  /**
   * Main render method that dispatches to the correct template
   */
  public function renderTemplate(string $type, array $user, array $co, $cab, array $config = []): string
  {
    $method = 'template' . ucfirst($type);
    if (method_exists($this, $method)) {
      return $this->$method($user, $co, $cab, $config);
    }
    // Fallback to classic
    return $this->templateClassic($user, $co, $cab, $config);
  }

  private function buildInvoiceData(array $user, $cab): array
  {
    $fechaInit = substr($user['date_facturation'], 0, 10);
    $fechaNueva = date('Y-m-d', strtotime($fechaInit . ' -1 month'));
    $fechaActual = date('Y-m-d');
    $fechaVence = date('Y-m-d', strtotime($fechaInit . ' +3 days'));
    $Porcentage = $user['porcentage_discount'] ?? 0;
    $daysFacture = $user['days_facture'] ?? 30;
    $priceDiscount = $user['price_discount'] ?? 0;
    $monthlyPrice = ($user['create_facture_manual'] ?? 0) == 1 ? ($user['price_total'] ?? 0) : ($user['monthly_price'] ?? 0);
    $saldoTotal = ($user['price_total'] ?? 0) - $priceDiscount;
    $priceAntFactura = $cab ?? 0;

    return compact('fechaInit','fechaNueva','fechaActual','fechaVence','Porcentage','daysFacture','priceDiscount','monthlyPrice','saldoTotal','priceAntFactura');
  }

  private function getConfigValue(array $config, string $key, mixed $default = null): mixed
  {
    return $config[$key] ?? $default;
  }

  private function esc(string $text): string
  {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
  }

  // ════════════════════════════════════════════════════════════
  public function renderReceipt(array $dataUser, array $co, $Cab, $extraParam): string
  {
    error_log(json_encode($dataUser));
    $valorPrice = $dataUser['abone'] == 1 ? $extraParam : $dataUser['price_total'] - $dataUser['price_discount'];

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
</html>';

    return $html;
  }
}
