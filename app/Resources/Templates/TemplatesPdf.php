<?php

namespace App\Resources\Templates;

use App\Models\Company;
use App\Models\InvoiceTemplate;
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
  // CLASSIC TEMPLATE — Professional, table-based layout
  // ════════════════════════════════════════════════════════════
  public function templateClassic(array $user, array $co, $cab, array $config = []): string
  {
    $d = $this->buildInvoiceData($user, $cab);
    $imagenBase64 = $co['logo_base64'] ?? '';
    $showLogo = $this->getConfigValue($config, 'show_logo', true);
    $showActivity = $this->getConfigValue($config, 'show_activity', true);
    $showIvaCondition = $this->getConfigValue($config, 'show_iva_condition', true);
    $showPaymentInfo = $this->getConfigValue($config, 'show_payment_info', true);
    $showFooter = $this->getConfigValue($config, 'show_footer', true);
    $showBalance = $this->getConfigValue($config, 'show_balance', true);
    $primaryColor = $this->getConfigValue($config, 'primary_color', '#2563eb');

    $html = '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Factura</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; margin: 0; padding: 25px; color: #333; }
    .main-table { width: 100%; border-collapse: collapse; }
    .main-table td { vertical-align: top; padding: 0; }
    .logo-cell { width: 25%; padding-right: 15px; }
    .logo-cell img { max-width: 100%; max-height: 70px; }
    .company-cell { width: 45%; text-align: center; padding: 0 10px; }
    .meta-cell { width: 30%; text-align: right; padding-left: 15px; }
    .badge { background: ' . $primaryColor . '; color: #fff; padding: 5px 14px; font-weight: bold; font-size: 11px; letter-spacing: 1px; }
    .divider { border: none; border-top: 3px solid ' . $primaryColor . '; margin: 18px 0; }
    .client-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
    .client-table td { padding: 3px 0; font-size: 11px; }
    .items-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .items-table th { background: ' . $primaryColor . '; color: #fff; padding: 9px 8px; font-size: 11px; text-align: center; font-weight: bold; }
    .items-table td { padding: 10px 8px; border-bottom: 1px solid #e0e0e0; text-align: center; font-size: 11px; }
    .items-table td.left { text-align: left; }
    .totals-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    .totals-table td { padding: 4px 8px; font-size: 11px; }
    .totals-table .grand td { border-top: 2px solid ' . $primaryColor . '; padding-top: 8px; font-size: 13px; font-weight: bold; color: ' . $primaryColor . '; }
    .footer-table { width: 100%; border-collapse: collapse; margin-top: 30px; border-top: 3px solid ' . $primaryColor . '; padding-top: 15px; }
    .footer-table td { text-align: center; font-size: 10px; color: #666; padding: 4px 0; }
    .payment-info { white-space: pre-line; line-height: 1.5; }
  </style>
</head>
<body>
  <table class="main-table">
    <tr>
      <td class="logo-cell">
        ' . ($showLogo && $imagenBase64 ? '<img src="' . $imagenBase64 . '" alt="Logo">' : '') . '
      </td>
      <td class="company-cell">
        <div style="font-size:16px;font-weight:bold;color:#1a1a1a;margin-bottom:4px;">' . $this->esc($co['business_name']) . '</div>
        <div style="font-size:10px;color:#555;line-height:1.5;">
          NIT: ' . $this->esc($co['nit']) . '<br>
          Tel: ' . $this->esc($co['phone']) . '<br>
          ' . $this->esc($co['address']) . ', ' . $this->esc($co['city']) . ', ' . $this->esc($co['country']) . '<br>
          ' . ($showActivity ? $this->esc($co['economic_activity']) . '<br>' : '') . '
          ' . ($showIvaCondition ? 'Condición IVA: ' . $this->esc($co['iva_condition']) : '') . '
        </div>
      </td>
      <td class="meta-cell">
        <div class="badge">FACTURA DE VENTA</div>
        <div style="margin-top:10px;font-size:11px;line-height:1.6;">
          <strong>No. ' . $this->esc($user['number_facture']) . '</strong><br>
          Fecha: ' . $d['fechaActual'] . '<br>
          Vence: ' . $d['fechaVence'] . '<br>
          Forma de pago: Efectivo
        </div>
      </td>
    </tr>
  </table>

  <hr class="divider">

  <table class="client-table">
    <tr>
      <td style="width:55%;">
        <strong>Cliente:</strong> ' . $this->esc($user['names'] . ' ' . $user['lastname']) . '<br>
        <strong>Dirección:</strong> ' . $this->esc($user['address']) . '<br>
        <strong>Municipio:</strong> ' . $this->esc($co['city']) . '
      </td>
      <td style="width:45%;text-align:right;">
        <strong>CC / NIT:</strong> ' . $this->esc($user['dni']) . '<br>
        <strong>Teléfono:</strong> ' . $this->esc($user['phone']) . '
      </td>
    </tr>
  </table>

  <div style="font-size:11px;margin-bottom:8px;"><strong>Moneda:</strong> Pesos Colombianos (COP)</div>

  <table class="items-table">
    <thead>
      <tr>
        <th style="width:28%;">Descripción</th>
        <th style="width:22%;">Período</th>
        <th style="width:10%;">IVA%</th>
        <th style="width:14%;">Precio</th>
        <th style="width:10%;">%Dto.</th>
        <th style="width:8%;">Días</th>
        <th style="width:14%;">Total</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="left">' . $this->esc($user['plan_name']) . '</td>
        <td>' . $d['fechaNueva'] . ' - ' . $d['fechaInit'] . '</td>
        <td>0%</td>
        <td>$ ' . number_format($d['monthlyPrice'], 0, ',', '.') . '</td>
        <td>' . $d['Porcentage'] . '%</td>
        <td>' . $d['daysFacture'] . '</td>
        <td>$ ' . number_format($d['saldoTotal'], 0, ',', '.') . '</td>
      </tr>
      ' . ($showBalance && $d['priceAntFactura'] > 0 ? '
      <tr>
        <td class="left">' . $this->esc($user['plan_name']) . ' — Saldo anterior</td>
        <td>-</td>
        <td>-</td>
        <td>$ ' . number_format($d['priceAntFactura'], 0, ',', '.') . '</td>
        <td>-</td>
        <td>-</td>
        <td>$ ' . number_format($d['priceAntFactura'], 0, ',', '.') . '</td>
      </tr>
      ' : '') . '
    </tbody>
  </table>

  <table class="totals-table">
    <tr>
      <td style="width:60%;"></td>
      <td style="width:25%;text-align:right;">Subtotal:</td>
      <td style="width:15%;text-align:right;">$ ' . number_format($d['monthlyPrice'] + $d['priceAntFactura'], 0, ',', '.') . '</td>
    </tr>
    <tr>
      <td></td>
      <td style="text-align:right;">Descuento:</td>
      <td style="text-align:right;">$ ' . number_format($d['priceDiscount'], 0, ',', '.') . '</td>
    </tr>
    <tr class="grand">
      <td></td>
      <td style="text-align:right;">TOTAL A PAGAR:</td>
      <td style="text-align:right;">$ ' . number_format($d['saldoTotal'] + $d['priceAntFactura'], 0, ',', '.') . '</td>
    </tr>
  </table>

  <table class="footer-table">
    <tr>
      <td>
        ' . ($showPaymentInfo ? '<div class="payment-info">' . nl2br($this->esc($co['payment_info'])) . '</div>' : '') . '
        ' . ($showFooter ? '<div style="margin-top:10px;font-weight:bold;color:#444;">' . $this->esc($co['footer']) . '</div>' : '') . '
      </td>
    </tr>
  </table>
</body>
</html>';

    return $html;
  }

  // ════════════════════════════════════════════════════════════
  // MODERN TEMPLATE — Clean, card-based using tables
  // ════════════════════════════════════════════════════════════
  public function templateModern(array $user, array $co, $cab, array $config = []): string
  {
    $d = $this->buildInvoiceData($user, $cab);
    $imagenBase64 = $co['logo_base64'] ?? '';
    $showLogo = $this->getConfigValue($config, 'show_logo', true);
    $showPaymentInfo = $this->getConfigValue($config, 'show_payment_info', true);
    $showFooter = $this->getConfigValue($config, 'show_footer', true);
    $showBalance = $this->getConfigValue($config, 'show_balance', true);
    $primaryColor = $this->getConfigValue($config, 'primary_color', '#0f172a');
    $accentColor = $this->getConfigValue($config, 'accent_color', '#10b981');

    $html = '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Factura</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; margin: 0; padding: 30px; color: #334155; }
    .top-bar { background: ' . $primaryColor . '; height: 6px; margin-bottom: 25px; }
    .header-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
    .header-table td { vertical-align: middle; padding: 0; }
    .header-table .brand-cell { width: 60%; }
    .header-table .brand-cell img { max-height: 55px; vertical-align: middle; margin-right: 12px; }
    .header-table .brand-cell .name { font-size: 18px; font-weight: bold; color: ' . $primaryColor . '; }
    .header-table .brand-cell .sub { font-size: 10px; color: #64748b; line-height: 1.5; }
    .header-table .invoice-box { width: 40%; background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; text-align: right; }
    .header-table .invoice-box h3 { margin: 0 0 8px 0; color: ' . $primaryColor . '; font-size: 14px; }
    .header-table .invoice-box p { margin: 3px 0; font-size: 11px; }
    .client-card { background: ' . $primaryColor . '; color: #fff; padding: 18px 20px; margin-bottom: 20px; }
    .client-card h4 { margin: 0 0 10px 0; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
    .client-card table { width: 100%; border-collapse: collapse; color: #fff; }
    .client-card td { padding: 2px 0; font-size: 11px; vertical-align: top; }
    .items-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .items-table th { background: ' . $primaryColor . '; color: #fff; padding: 10px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; text-align: center; }
    .items-table td { padding: 10px 8px; border-bottom: 1px solid #e2e8f0; font-size: 11px; text-align: center; }
    .items-table td.left { text-align: left; }
    .summary-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .summary-table td { padding: 0; }
    .summary-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px 20px; }
    .summary-box table { width: 100%; border-collapse: collapse; }
    .summary-box td { padding: 4px 0; font-size: 11px; }
    .summary-box .grand td { border-top: 2px solid ' . $accentColor . '; padding-top: 8px; font-size: 13px; font-weight: bold; color: ' . $accentColor . '; }
    .footer-table { width: 100%; border-collapse: collapse; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 15px; }
    .footer-table td { text-align: center; font-size: 10px; color: #94a3b8; padding: 4px 0; }
  </style>
</head>
<body>
  <div class="top-bar"></div>

  <table class="header-table">
    <tr>
      <td class="brand-cell">
        ' . ($showLogo && $imagenBase64 ? '<img src="' . $imagenBase64 . '" alt="Logo">' : '') . '
        <span class="name">' . $this->esc($co['business_name']) . '</span><br>
        <span class="sub">NIT ' . $this->esc($co['nit']) . ' &nbsp;|&nbsp; ' . $this->esc($co['phone']) . '<br>' . $this->esc($co['address']) . ', ' . $this->esc($co['city']) . '</span>
      </td>
      <td class="invoice-box">
        <h3>FACTURA</h3>
        <p><strong>No.</strong> ' . $this->esc($user['number_facture']) . '</p>
        <p><strong>Fecha:</strong> ' . $d['fechaActual'] . '</p>
        <p><strong>Vence:</strong> ' . $d['fechaVence'] . '</p>
      </td>
    </tr>
  </table>

  <div class="client-card">
    <h4>Facturar a</h4>
    <table>
      <tr>
        <td style="width:50%;">
          <strong>' . $this->esc($user['names'] . ' ' . $user['lastname']) . '</strong><br>
          CC/NIT: ' . $this->esc($user['dni']) . '<br>
          Tel: ' . $this->esc($user['phone']) . '
        </td>
        <td style="width:50%;">
          ' . $this->esc($user['address']) . '<br>
          ' . $this->esc($co['city']) . '<br>
          ' . $this->esc($co['country']) . '
        </td>
      </tr>
    </table>
  </div>

  <table class="items-table">
    <thead>
      <tr>
        <th style="width:35%;text-align:left;">Servicio</th>
        <th style="width:25%;">Período</th>
        <th style="width:15%;">Precio</th>
        <th style="width:10%;">Dto.</th>
        <th style="width:15%;text-align:right;">Total</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="left"><strong>' . $this->esc($user['plan_name']) . '</strong></td>
        <td>' . $d['fechaNueva'] . ' - ' . $d['fechaInit'] . '</td>
        <td>$ ' . number_format($d['monthlyPrice'], 0, ',', '.') . '</td>
        <td>' . $d['Porcentage'] . '%</td>
        <td style="text-align:right;">$ ' . number_format($d['saldoTotal'], 0, ',', '.') . '</td>
      </tr>
      ' . ($showBalance && $d['priceAntFactura'] > 0 ? '
      <tr>
        <td class="left"><strong>Saldo anterior</strong></td>
        <td>-</td>
        <td>$ ' . number_format($d['priceAntFactura'], 0, ',', '.') . '</td>
        <td>-</td>
        <td style="text-align:right;">$ ' . number_format($d['priceAntFactura'], 0, ',', '.') . '</td>
      </tr>
      ' : '') . '
    </tbody>
  </table>

  <table class="summary-table">
    <tr>
      <td style="width:55%;"></td>
      <td style="width:45%;">
        <div class="summary-box">
          <table>
            <tr>
              <td>Subtotal</td>
              <td style="text-align:right;">$ ' . number_format($d['monthlyPrice'] + $d['priceAntFactura'], 0, ',', '.') . '</td>
            </tr>
            <tr>
              <td>Descuento</td>
              <td style="text-align:right;">$ ' . number_format($d['priceDiscount'], 0, ',', '.') . '</td>
            </tr>
            <tr class="grand">
              <td>TOTAL</td>
              <td style="text-align:right;">$ ' . number_format($d['saldoTotal'] + $d['priceAntFactura'], 0, ',', '.') . '</td>
            </tr>
          </table>
        </div>
      </td>
    </tr>
  </table>

  <table class="footer-table">
    <tr>
      <td>
        ' . ($showPaymentInfo ? '<div style="white-space:pre-line;">' . nl2br($this->esc($co['payment_info'])) . '</div>' : '') . '
        ' . ($showFooter ? '<div style="margin-top:10px;">' . $this->esc($co['footer']) . '</div>' : '') . '
      </td>
    </tr>
  </table>
</body>
</html>';

    return $html;
  }

  // ════════════════════════════════════════════════════════════
  // MINIMAL TEMPLATE — Editorial, elegant
  // ════════════════════════════════════════════════════════════
  public function templateMinimal(array $user, array $co, $cab, array $config = []): string
  {
    $d = $this->buildInvoiceData($user, $cab);
    $imagenBase64 = $co['logo_base64'] ?? '';
    $showLogo = $this->getConfigValue($config, 'show_logo', true);
    $showPaymentInfo = $this->getConfigValue($config, 'show_payment_info', true);
    $showFooter = $this->getConfigValue($config, 'show_footer', true);
    $showBalance = $this->getConfigValue($config, 'show_balance', true);
    $primaryColor = $this->getConfigValue($config, 'primary_color', '#1a1a1a');

    $html = '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Factura</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: Georgia, "Times New Roman", serif; font-size: 12px; margin: 0; padding: 45px; color: #1a1a1a; }
    .header { text-align: center; margin-bottom: 45px; }
    .header img { max-height: 45px; margin-bottom: 12px; }
    .header h2 { margin: 0; font-weight: normal; letter-spacing: 3px; text-transform: uppercase; font-size: 13px; color: #333; }
    .header p { margin: 4px 0; font-size: 10px; color: #777; }
    .divider { border: none; border-top: 1px solid #ccc; margin: 25px 0; }
    .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
    .meta-table td { padding: 3px 0; font-size: 11px; vertical-align: top; }
    .items-table { width: 100%; border-collapse: collapse; margin: 25px 0; }
    .items-table th { text-align: left; padding: 10px 0; border-bottom: 2px solid ' . $primaryColor . '; font-weight: normal; text-transform: uppercase; font-size: 9px; letter-spacing: 2px; color: #555; }
    .items-table td { padding: 12px 0; border-bottom: 1px solid #eee; font-size: 11px; }
    .items-table td.right { text-align: right; }
    .totals-table { width: 100%; border-collapse: collapse; margin-top: 35px; }
    .totals-table td { padding: 5px 0; font-size: 11px; border: none; }
    .totals-table .grand td { border-top: 2px solid ' . $primaryColor . '; padding-top: 12px; font-size: 14px; font-weight: bold; }
    .footer-table { width: 100%; border-collapse: collapse; margin-top: 60px; }
    .footer-table td { text-align: center; font-size: 10px; color: #999; padding: 4px 0; }
  </style>
</head>
<body>
  <div class="header">
    ' . ($showLogo && $imagenBase64 ? '<img src="' . $imagenBase64 . '" alt="Logo"><br>' : '') . '
    <h2>' . $this->esc($co['business_name']) . '</h2>
    <p>' . $this->esc($co['address']) . ', ' . $this->esc($co['city']) . ' &mdash; NIT ' . $this->esc($co['nit']) . '</p>
  </div>

  <hr class="divider">

  <table class="meta-table">
    <tr>
      <td style="width:50%;">
        <strong style="color:#333;">Cliente</strong><br>
        ' . $this->esc($user['names'] . ' ' . $user['lastname']) . '<br>
        ' . $this->esc($user['address']) . '<br>
        CC/NIT ' . $this->esc($user['dni']) . '
      </td>
      <td style="width:50%;text-align:right;">
        <strong style="color:#333;">Factura ' . $this->esc($user['number_facture']) . '</strong><br>
        ' . $d['fechaActual'] . '<br>
        Vence ' . $d['fechaVence'] . '
      </td>
    </tr>
  </table>

  <table class="items-table">
    <thead>
      <tr>
        <th>Concepto</th>
        <th class="right">Importe</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>' . $this->esc($user['plan_name']) . ' <span style="color:#888;">(' . $d['fechaNueva'] . ' - ' . $d['fechaInit'] . ')</span></td>
        <td class="right">$ ' . number_format($d['monthlyPrice'], 0, ',', '.') . '</td>
      </tr>
      ' . ($showBalance && $d['priceAntFactura'] > 0 ? '
      <tr>
        <td>Saldo anterior</td>
        <td class="right">$ ' . number_format($d['priceAntFactura'], 0, ',', '.') . '</td>
      </tr>
      ' : '') . '
      <tr>
        <td>Descuento (' . $d['Porcentage'] . '%)</td>
        <td class="right" style="color:#888;">- $ ' . number_format($d['priceDiscount'], 0, ',', '.') . '</td>
      </tr>
    </tbody>
  </table>

  <table class="totals-table">
    <tr>
      <td style="width:60%;"></td>
      <td style="width:25%;text-align:right;">Subtotal</td>
      <td style="width:15%;text-align:right;">$ ' . number_format($d['monthlyPrice'] + $d['priceAntFactura'], 0, ',', '.') . '</td>
    </tr>
    <tr class="grand">
      <td></td>
      <td style="text-align:right;">Total a pagar</td>
      <td style="text-align:right;">$ ' . number_format($d['saldoTotal'] + $d['priceAntFactura'], 0, ',', '.') . '</td>
    </tr>
  </table>

  <table class="footer-table">
    <tr>
      <td>
        ' . ($showPaymentInfo ? '<div style="white-space:pre-line;line-height:1.6;">' . nl2br($this->esc($co['payment_info'])) . '</div>' : '') . '
        ' . ($showFooter ? '<div style="margin-top:10px;">' . $this->esc($co['footer']) . '</div>' : '') . '
      </td>
    </tr>
  </table>
</body>
</html>';

    return $html;
  }

  // ════════════════════════════════════════════════════════════
  // RECEIPT / POS TEMPLATE — Thermal printer style
  // ════════════════════════════════════════════════════════════
  public function templateReceipt(array $user, array $co, $cab, array $config = []): string
  {
    $d = $this->buildInvoiceData($user, $cab);
    $imagenBase64 = $co['logo_base64'] ?? '';
    $showLogo = $this->getConfigValue($config, 'show_logo', true);
    $showPaymentInfo = $this->getConfigValue($config, 'show_payment_info', true);
    $showFooter = $this->getConfigValue($config, 'show_footer', true);
    $showBalance = $this->getConfigValue($config, 'show_balance', true);

    $html = '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Factura</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: "Courier New", Courier, monospace; font-size: 11px; margin: 0; padding: 15px; background: #fff; color: #000; max-width: 320px; }
    .center { text-align: center; }
    .logo img { max-height: 40px; margin-bottom: 6px; }
    .title { font-size: 14px; font-weight: bold; margin: 8px 0; }
    .dashed { border: none; border-top: 1px dashed #000; margin: 10px 0; }
    .row-table { width: 100%; border-collapse: collapse; }
    .row-table td { padding: 2px 0; font-size: 11px; }
    .row-table td:first-child { text-align: left; }
    .row-table td:last-child { text-align: right; }
    .totals { margin-top: 12px; }
    .totals td { border-top: 1px dashed #000; padding-top: 6px; }
    .footer { text-align: center; margin-top: 18px; font-size: 10px; }
  </style>
</head>
<body>
  <div class="center logo">
    ' . ($showLogo && $imagenBase64 ? '<img src="' . $imagenBase64 . '" alt="Logo"><br>' : '') . '
    <div class="title">' . $this->esc($co['business_name']) . '</div>
    <div>' . $this->esc($co['iva_condition']) . '</div>
    <div>' . $this->esc($co['city']) . '</div>
    <div>NIT: ' . $this->esc($co['nit']) . '</div>
    <div>Tel: ' . $this->esc($co['phone']) . '</div>
    <div>' . $this->esc($co['address']) . '</div>
    <div style="margin-top:8px;font-weight:bold;font-size:12px;">FACTURA DE VENTA</div>
    <div>No. ' . $this->esc($user['number_facture']) . '</div>
  </div>

  <hr class="dashed">

  <table class="row-table">
    <tr><td>Fecha:</td><td>' . $d['fechaActual'] . '</td></tr>
    <tr><td>Vence:</td><td>' . $d['fechaVence'] . '</td></tr>
    <tr><td>Cliente:</td><td>' . $this->esc($user['names'] . ' ' . $user['lastname']) . '</td></tr>
    <tr><td>CC/NIT:</td><td>' . $this->esc($user['dni']) . '</td></tr>
  </table>

  <hr class="dashed">

  <div style="margin:8px 0;font-weight:bold;">' . $this->esc($user['plan_name']) . '</div>
  <table class="row-table">
    <tr><td>Período:</td><td>' . $d['fechaNueva'] . ' - ' . $d['fechaInit'] . '</td></tr>
    <tr><td>Precio:</td><td>$ ' . number_format($d['monthlyPrice'], 0, ',', '.') . '</td></tr>
    <tr><td>Dto. (' . $d['Porcentage'] . '%):</td><td>$ ' . number_format($d['priceDiscount'], 0, ',', '.') . '</td></tr>
    ' . ($showBalance && $d['priceAntFactura'] > 0 ? '<tr><td>Saldo ant.:</td><td>$ ' . number_format($d['priceAntFactura'], 0, ',', '.') . '</td></tr>' : '') . '
  </table>

  <table class="row-table totals">
    <tr><td><strong>TOTAL A PAGAR</strong></td><td><strong>$ ' . number_format($d['saldoTotal'] + $d['priceAntFactura'], 0, ',', '.') . '</strong></td></tr>
  </table>

  <hr class="dashed">

  <div class="footer">
    ' . ($showPaymentInfo ? '<div style="white-space:pre-line;line-height:1.5;">' . nl2br($this->esc($co['payment_info'])) . '</div>' : '') . '
    ' . ($showFooter ? '<div style="margin-top:8px;">' . $this->esc($co['footer']) . '</div>' : '') . '
  </div>
</body>
</html>';

    return $html;
  }

  // ════════════════════════════════════════════════════════════
  // LEGACY METHODS (keep compatibility)
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
