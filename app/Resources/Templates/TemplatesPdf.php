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
   * Datos de facturación de la empresa. Cada empresa sale con lo suyo: si un
   * campo está vacío se toma el de la empresa o se deja en blanco, nunca los
   * datos, cuentas ni logo de otra empresa. Sin empresa no se genera nada.
   */
  private function loadCompany(?int $companyId = null): array
  {
    $id = $companyId ?: (function_exists('getSessionCompanyId') ? getSessionCompanyId() : null);
    $company = $id ? Company::with('invoiceTemplate')->find($id) : null;

    if (!$company) {
      throw new \RuntimeException('No se pudo determinar la empresa de la factura.');
    }

    // Plantilla de la empresa
    $templateType = 'classic';
    $templateConfig = [];
    if ($company->invoiceTemplate) {
        $templateType = $company->invoiceTemplate->type ?? 'classic';
        $templateConfig = $company->invoiceTemplate->config ?? [];
    } else {
        $defaultTemplate = InvoiceTemplate::where('company_id', $company->id)
            ->where('is_default', true)
            ->first();
        if ($defaultTemplate) {
            $templateType = $defaultTemplate->type;
            $templateConfig = $defaultTemplate->config ?? [];
        }
    }

    return self::datosEmpresa($company) + [
      'template_type'      => $templateType,
      'template_config'    => $templateConfig,
    ];
  }

  /**
   * Membrete de una empresa para facturas, recibos y vista previa.
   * Los vacíos se completan con los datos generales de la misma empresa.
   */
  public static function datosEmpresa(Company $company): array
  {
    $txt = fn ($v) => trim((string) $v);

    return [
      'business_name'      => $txt($company->invoice_business_name) ?: $txt($company->name),
      'nit'                => $txt($company->invoice_nit)           ?: $txt($company->nit),
      'phone'              => $txt($company->invoice_phone)         ?: $txt($company->phone),
      'address'            => $txt($company->invoice_address)       ?: $txt($company->address),
      'city'               => $txt($company->invoice_city),
      'country'            => $txt($company->invoice_country)       ?: 'COLOMBIA',
      'iva_condition'      => $txt($company->invoice_iva_condition) ?: 'No Aplica',
      'economic_activity'  => $txt($company->invoice_economic_activity),
      'payment_info'       => $txt($company->invoice_payment_info),
      'footer'             => $txt($company->invoice_footer)        ?: '¡Gracias por preferirnos!',
      'logo_base64'        => self::logoEmpresa($company),
    ];
  }

  /** Logo en base64: el de factura, si no el de la empresa, si no ninguno. */
  public static function logoEmpresa(Company $company): ?string
  {
    foreach ([$company->invoice_logo_base64, $company->invoice_logo_url, $company->logo] as $logo) {
      $logo = trim((string) $logo);
      if ($logo === '') {
        continue;
      }
      if (str_starts_with($logo, 'data:image')) {
        return $logo;
      }
      if (preg_match('#^https?://#i', $logo)) {
        try {
          $data = @file_get_contents($logo, false, stream_context_create(['http' => ['timeout' => 5]]));
          if ($data) {
            $mime = str_ends_with(strtolower(parse_url($logo, PHP_URL_PATH) ?? ''), '.png') ? 'image/png' : 'image/jpeg';
            return "data:{$mime};base64," . base64_encode($data);
          }
        } catch (\Throwable) {}
      }
    }

    return null;
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
    return $this->renderReceipt($this->userToArray($dataUser), $co, $Cab, $extraParam);
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
            ' . ($co['city'] !== '' ? '<p>' . htmlspecialchars($co['city']) . '</p>' : '') . '
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
