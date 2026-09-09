<?php

namespace App\Resources\Templates;

/**
 * Diseños de la factura en PDF.
 *
 * DomPDF no soporta flexbox ni grid, así que todo se arma con tablas y estilos
 * en línea. Cada método devuelve el HTML completo de una hoja carta.
 *
 * Datos que recibe:
 *  $user  names, lastname, dni, address, phone, plan_name, number_facture
 *  $co    business_name, nit, phone, address, city, country, iva_condition,
 *         economic_activity, payment_info, footer, logo_base64
 *  $d     fechaInit, fechaNueva, fechaActual, fechaVence, Porcentage,
 *         daysFacture, priceDiscount, monthlyPrice, saldoTotal, priceAntFactura
 */
trait InvoiceDesigns
{
    /* ── Utilidades comunes ─────────────────────────────────────────── */

    private function money(float|int|string $v): string
    {
        return '$' . number_format((float) $v, 0, ',', '.');
    }

    private function fecha(string $iso): string
    {
        $t = strtotime($iso);
        return $t ? date('d/m/Y', $t) : $iso;
    }

    /** Aclara u oscurece un color hex para derivar tonos del acento elegido. */
    private function tint(string $hex, float $factor): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        if (strlen($hex) !== 6) return '#' . $hex;
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $c = hexdec(substr($hex, $i * 2, 2));
            $c = $factor >= 0
                ? (int) round($c + (255 - $c) * $factor)      // aclarar
                : (int) round($c * (1 + $factor));            // oscurecer
            $out .= str_pad(dechex(max(0, min(255, $c))), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /** ¿El color es oscuro? Sirve para elegir texto blanco o negro encima. */
    private function esOscuro(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return true;
        $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) < 150;
    }

    /** Líneas de la factura, iguales para todos los diseños. */
    private function lineas(array $user, array $d, bool $showBalance): array
    {
        $lineas = [[
            'desc'    => $user['plan_name'] ?? 'Servicio de internet',
            'periodo' => $this->fecha($d['fechaNueva']) . ' — ' . $this->fecha($d['fechaInit']),
            'dias'    => $d['daysFacture'],
            'precio'  => $d['monthlyPrice'],
            'dto'     => $d['Porcentage'],
            'total'   => $d['saldoTotal'],
        ]];

        if ($showBalance && $d['priceAntFactura'] > 0) {
            $lineas[] = [
                'desc'    => 'Saldo pendiente de facturas anteriores',
                'periodo' => '—',
                'dias'    => '—',
                'precio'  => $d['priceAntFactura'],
                'dto'     => 0,
                'total'   => $d['priceAntFactura'],
            ];
        }
        return $lineas;
    }

    private function totalFinal(array $d): float
    {
        return (float) $d['saldoTotal'] + (float) $d['priceAntFactura'];
    }

    /** Bloque de medios de pago, en una caja legible. */
    private function bloquePago(array $co, string $borde): string
    {
        $lineas = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $co['payment_info']))));
        if (!$lineas) return '';

        $items = '';
        foreach ($lineas as $l) {
            $items .= '<tr><td style="padding:2px 0;font-size:10px;color:#334155;">' . $this->esc(ltrim($l, "-• \t")) . '</td></tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;border:1px solid ' . $borde . ';margin-top:16px;">
            <tr><td style="padding:10px 12px;">
              <div style="font-size:9px;letter-spacing:1px;text-transform:uppercase;color:#64748b;margin-bottom:6px;">Medios de pago</div>
              <table style="width:100%;border-collapse:collapse;">' . $items . '</table>
            </td></tr>
          </table>';
    }

    /* ══════════════════════════════════════════════════════════════════
     * CLÁSICA — carta formal con membrete y tabla de detalle
     * ══════════════════════════════════════════════════════════════════ */
    public function templateClassic(array $user, array $co, $cab, array $config = []): string
    {
        $d = $this->buildInvoiceData($user, $cab);
        $primary = $this->getConfigValue($config, 'primary_color', '#1e3a5f');
        $showLogo = $this->getConfigValue($config, 'show_logo', true);
        $showActivity = $this->getConfigValue($config, 'show_activity', true);
        $showIva = $this->getConfigValue($config, 'show_iva_condition', true);
        $showPay = $this->getConfigValue($config, 'show_payment_info', true);
        $showFooter = $this->getConfigValue($config, 'show_footer', true);
        $showBalance = $this->getConfigValue($config, 'show_balance', true);
        $suave = $this->tint($primary, 0.92);
        $borde = $this->tint($primary, 0.75);
        $total = $this->totalFinal($d);

        $filas = '';
        foreach ($this->lineas($user, $d, $showBalance) as $i => $l) {
            $fondo = $i % 2 ? '#fbfcfd' : '#ffffff';
            $filas .= '<tr>
                <td style="padding:9px 10px;border-bottom:1px solid #e8edf2;background:' . $fondo . ';font-size:10.5px;">' . $this->esc($l['desc']) . '</td>
                <td style="padding:9px 10px;border-bottom:1px solid #e8edf2;background:' . $fondo . ';font-size:10px;color:#64748b;text-align:center;">' . $l['periodo'] . '</td>
                <td style="padding:9px 10px;border-bottom:1px solid #e8edf2;background:' . $fondo . ';font-size:10.5px;text-align:center;">' . $l['dias'] . '</td>
                <td style="padding:9px 10px;border-bottom:1px solid #e8edf2;background:' . $fondo . ';font-size:10.5px;text-align:right;">' . $this->money($l['precio']) . '</td>
                <td style="padding:9px 10px;border-bottom:1px solid #e8edf2;background:' . $fondo . ';font-size:10.5px;text-align:right;">' . $l['dto'] . '%</td>
                <td style="padding:9px 10px;border-bottom:1px solid #e8edf2;background:' . $fondo . ';font-size:11px;text-align:right;font-weight:bold;">' . $this->money($l['total']) . '</td>
            </tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Factura ' . $this->esc($user['number_facture']) . '</title></head>
<body style="margin:0;padding:34px 36px;font-family:Helvetica,Arial,sans-serif;color:#1f2937;font-size:11px;">

  <table style="width:100%;border-collapse:collapse;">
    <tr>
      ' . ($showLogo && $co['logo_base64'] ? '<td style="width:22%;vertical-align:top;"><img src="' . $co['logo_base64'] . '" style="max-width:130px;max-height:66px;"></td>' : '') . '
      <td style="vertical-align:top;padding-left:' . ($showLogo && $co['logo_base64'] ? '14px' : '0') . ';">
        <div style="font-size:16px;font-weight:bold;color:' . $primary . ';letter-spacing:-0.2px;">' . $this->esc($co['business_name']) . '</div>
        <div style="font-size:10px;color:#6b7280;line-height:1.65;margin-top:4px;">
          NIT ' . $this->esc($co['nit']) . '<br>
          ' . $this->esc($co['address']) . ' · ' . $this->esc($co['city']) . '<br>
          Tel. ' . $this->esc($co['phone']) . '
          ' . ($showActivity ? '<br>' . $this->esc($co['economic_activity']) : '') . '
          ' . ($showIva ? '<br>Régimen: ' . $this->esc($co['iva_condition']) : '') . '
        </div>
      </td>
      <td style="width:33%;vertical-align:top;">
        <table style="width:100%;border-collapse:collapse;border:1px solid ' . $borde . ';">
          <tr><td style="background:' . $primary . ';color:#fff;padding:7px 12px;font-size:10px;letter-spacing:1.4px;text-transform:uppercase;text-align:center;">Factura de venta</td></tr>
          <tr><td style="padding:10px 12px;text-align:center;background:' . $suave . ';">
            <div style="font-size:17px;font-weight:bold;color:' . $primary . ';">' . $this->esc($user['number_facture']) . '</div>
          </td></tr>
          <tr><td style="padding:9px 12px;">
            <table style="width:100%;border-collapse:collapse;font-size:10px;color:#4b5563;">
              <tr><td style="padding:2px 0;">Emitida</td><td style="padding:2px 0;text-align:right;font-weight:bold;color:#1f2937;">' . $this->fecha($d['fechaActual']) . '</td></tr>
              <tr><td style="padding:2px 0;">Vence</td><td style="padding:2px 0;text-align:right;font-weight:bold;color:#1f2937;">' . $this->fecha($d['fechaVence']) . '</td></tr>
              <tr><td style="padding:2px 0;">Moneda</td><td style="padding:2px 0;text-align:right;">COP</td></tr>
            </table>
          </td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="height:3px;background:' . $primary . ';margin:18px 0 16px;"></div>

  <table style="width:100%;border-collapse:collapse;border:1px solid #e5e9ee;">
    <tr>
      <td style="width:50%;padding:11px 14px;vertical-align:top;border-right:1px solid #e5e9ee;">
        <div style="font-size:9px;letter-spacing:1px;text-transform:uppercase;color:#94a3b8;margin-bottom:5px;">Facturado a</div>
        <div style="font-size:12px;font-weight:bold;">' . $this->esc(trim(($user['names'] ?? '') . ' ' . ($user['lastname'] ?? ''))) . '</div>
        <div style="font-size:10px;color:#64748b;line-height:1.6;margin-top:3px;">
          CC/NIT ' . $this->esc($user['dni'] ?? '') . '<br>
          ' . $this->esc($user['address'] ?? '') . '<br>
          Tel. ' . $this->esc($user['phone'] ?? '') . '
        </div>
      </td>
      <td style="width:50%;padding:11px 14px;vertical-align:top;">
        <div style="font-size:9px;letter-spacing:1px;text-transform:uppercase;color:#94a3b8;margin-bottom:5px;">Servicio</div>
        <div style="font-size:12px;font-weight:bold;">' . $this->esc($user['plan_name'] ?? '') . '</div>
        <div style="font-size:10px;color:#64748b;line-height:1.6;margin-top:3px;">
          Período ' . $this->fecha($d['fechaNueva']) . ' — ' . $this->fecha($d['fechaInit']) . '<br>
          ' . $d['daysFacture'] . ' días facturados
        </div>
      </td>
    </tr>
  </table>

  <table style="width:100%;border-collapse:collapse;margin-top:16px;">
    <thead><tr>
      <th style="background:' . $primary . ';color:#fff;padding:8px 10px;font-size:9.5px;letter-spacing:.8px;text-transform:uppercase;text-align:left;">Concepto</th>
      <th style="background:' . $primary . ';color:#fff;padding:8px 10px;font-size:9.5px;letter-spacing:.8px;text-transform:uppercase;text-align:center;">Período</th>
      <th style="background:' . $primary . ';color:#fff;padding:8px 10px;font-size:9.5px;letter-spacing:.8px;text-transform:uppercase;text-align:center;">Días</th>
      <th style="background:' . $primary . ';color:#fff;padding:8px 10px;font-size:9.5px;letter-spacing:.8px;text-transform:uppercase;text-align:right;">Precio</th>
      <th style="background:' . $primary . ';color:#fff;padding:8px 10px;font-size:9.5px;letter-spacing:.8px;text-transform:uppercase;text-align:right;">Dto.</th>
      <th style="background:' . $primary . ';color:#fff;padding:8px 10px;font-size:9.5px;letter-spacing:.8px;text-transform:uppercase;text-align:right;">Total</th>
    </tr></thead>
    <tbody>' . $filas . '</tbody>
  </table>

  <table style="width:100%;border-collapse:collapse;margin-top:14px;">
    <tr>
      <td style="width:52%;vertical-align:top;">' . ($showPay ? $this->bloquePago($co, '#e5e9ee') : '') . '</td>
      <td style="width:48%;vertical-align:top;padding-left:14px;">
        <table style="width:100%;border-collapse:collapse;">
          <tr><td style="padding:5px 12px;font-size:10.5px;color:#64748b;">Subtotal</td><td style="padding:5px 12px;text-align:right;font-size:10.5px;">' . $this->money($d['monthlyPrice'] + $d['priceAntFactura']) . '</td></tr>
          <tr><td style="padding:5px 12px;font-size:10.5px;color:#64748b;">Descuento</td><td style="padding:5px 12px;text-align:right;font-size:10.5px;">− ' . $this->money($d['priceDiscount']) . '</td></tr>
          <tr><td style="padding:5px 12px;font-size:10.5px;color:#64748b;border-bottom:1px solid #e5e9ee;">IVA (0%)</td><td style="padding:5px 12px;text-align:right;font-size:10.5px;border-bottom:1px solid #e5e9ee;">' . $this->money(0) . '</td></tr>
          <tr>
            <td style="padding:11px 12px;background:' . $primary . ';color:#fff;font-size:11px;letter-spacing:.6px;text-transform:uppercase;">Total a pagar</td>
            <td style="padding:11px 12px;background:' . $primary . ';color:#fff;font-size:16px;font-weight:bold;text-align:right;">' . $this->money($total) . '</td>
          </tr>
          <tr><td colspan="2" style="padding:7px 12px;background:' . $suave . ';font-size:10px;color:#475569;text-align:center;">Pagar antes del <strong>' . $this->fecha($d['fechaVence']) . '</strong></td></tr>
        </table>
      </td>
    </tr>
  </table>

  ' . ($showFooter ? '<div style="margin-top:22px;padding-top:12px;border-top:1px solid #e5e9ee;text-align:center;font-size:10px;color:#94a3b8;">' . $this->esc($co['footer']) . '</div>' : '') . '
  <div style="margin-top:6px;text-align:center;font-size:8.5px;color:#cbd5e1;">Documento generado electrónicamente · ' . $this->esc($co['business_name']) . '</div>
</body></html>';
    }

    /* ══════════════════════════════════════════════════════════════════
     * MODERNA — banda de color, total destacado
     * ══════════════════════════════════════════════════════════════════ */
    public function templateModern(array $user, array $co, $cab, array $config = []): string
    {
        $d = $this->buildInvoiceData($user, $cab);
        $primary = $this->getConfigValue($config, 'primary_color', '#0f172a');
        $accent  = $this->getConfigValue($config, 'accent_color', '#0d9488');
        $showLogo = $this->getConfigValue($config, 'show_logo', true);
        $showPay = $this->getConfigValue($config, 'show_payment_info', true);
        $showFooter = $this->getConfigValue($config, 'show_footer', true);
        $showBalance = $this->getConfigValue($config, 'show_balance', true);
        $textoHeader = $this->esOscuro($primary) ? '#ffffff' : '#0f172a';
        $suaveAccent = $this->tint($accent, 0.9);
        $total = $this->totalFinal($d);

        $filas = '';
        foreach ($this->lineas($user, $d, $showBalance) as $l) {
            $filas .= '<tr>
                <td style="padding:11px 12px;border-bottom:1px solid #eef2f6;">
                  <div style="font-size:11.5px;font-weight:bold;color:#0f172a;">' . $this->esc($l['desc']) . '</div>
                  <div style="font-size:9.5px;color:#94a3b8;margin-top:2px;">' . $l['periodo'] . ' · ' . $l['dias'] . ' días</div>
                </td>
                <td style="padding:11px 12px;border-bottom:1px solid #eef2f6;text-align:right;font-size:11px;color:#64748b;">' . $this->money($l['precio']) . '</td>
                <td style="padding:11px 12px;border-bottom:1px solid #eef2f6;text-align:right;font-size:12px;font-weight:bold;color:#0f172a;">' . $this->money($l['total']) . '</td>
            </tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Factura ' . $this->esc($user['number_facture']) . '</title></head>
<body style="margin:0;padding:0;font-family:Helvetica,Arial,sans-serif;color:#334155;font-size:11px;">

  <table style="width:100%;border-collapse:collapse;background:' . $primary . ';">
    <tr>
      <td style="padding:26px 36px 24px;vertical-align:middle;">
        ' . ($showLogo && $co['logo_base64'] ? '<img src="' . $co['logo_base64'] . '" style="max-height:44px;max-width:150px;margin-bottom:8px;"><br>' : '') . '
        <div style="font-size:17px;font-weight:bold;color:' . $textoHeader . ';">' . $this->esc($co['business_name']) . '</div>
        <div style="font-size:9.5px;color:' . $textoHeader . ';opacity:.7;margin-top:3px;line-height:1.6;">NIT ' . $this->esc($co['nit']) . ' · ' . $this->esc($co['phone']) . '<br>' . $this->esc($co['address']) . ', ' . $this->esc($co['city']) . '</div>
      </td>
      <td style="padding:26px 36px 24px;text-align:right;vertical-align:middle;">
        <div style="font-size:10px;letter-spacing:2px;text-transform:uppercase;color:' . $textoHeader . ';opacity:.65;">Factura</div>
        <div style="font-size:24px;font-weight:bold;color:' . $textoHeader . ';margin:2px 0 8px;">' . $this->esc($user['number_facture']) . '</div>
        <div style="display:inline-block;background:' . $accent . ';color:#fff;padding:5px 12px;font-size:10px;font-weight:bold;">Vence ' . $this->fecha($d['fechaVence']) . '</div>
      </td>
    </tr>
  </table>

  <div style="padding:0 36px;">

    <table style="width:100%;border-collapse:collapse;margin-top:22px;">
      <tr>
        <td style="width:58%;vertical-align:top;padding-right:16px;">
          <div style="font-size:9px;letter-spacing:1.2px;text-transform:uppercase;color:#94a3b8;">Cliente</div>
          <div style="font-size:14px;font-weight:bold;color:#0f172a;margin-top:4px;">' . $this->esc(trim(($user['names'] ?? '') . ' ' . ($user['lastname'] ?? ''))) . '</div>
          <div style="font-size:10px;color:#64748b;line-height:1.7;margin-top:4px;">
            CC/NIT ' . $this->esc($user['dni'] ?? '') . '<br>
            ' . $this->esc($user['address'] ?? '') . '<br>
            Tel. ' . $this->esc($user['phone'] ?? '') . '
          </div>
        </td>
        <td style="width:42%;vertical-align:top;">
          <table style="width:100%;border-collapse:collapse;background:#f8fafc;border-left:3px solid ' . $accent . ';">
            <tr><td style="padding:12px 14px;">
              <div style="font-size:9px;letter-spacing:1.2px;text-transform:uppercase;color:#94a3b8;">Total a pagar</div>
              <div style="font-size:26px;font-weight:bold;color:' . $accent . ';margin:3px 0;">' . $this->money($total) . '</div>
              <div style="font-size:9.5px;color:#64748b;">Emitida el ' . $this->fecha($d['fechaActual']) . '</div>
            </td></tr>
          </table>
        </td>
      </tr>
    </table>

    <table style="width:100%;border-collapse:collapse;margin-top:22px;">
      <thead><tr>
        <th style="padding:8px 12px;border-bottom:2px solid ' . $primary . ';font-size:9px;letter-spacing:1.2px;text-transform:uppercase;color:#64748b;text-align:left;">Detalle</th>
        <th style="padding:8px 12px;border-bottom:2px solid ' . $primary . ';font-size:9px;letter-spacing:1.2px;text-transform:uppercase;color:#64748b;text-align:right;">Valor</th>
        <th style="padding:8px 12px;border-bottom:2px solid ' . $primary . ';font-size:9px;letter-spacing:1.2px;text-transform:uppercase;color:#64748b;text-align:right;">Total</th>
      </tr></thead>
      <tbody>' . $filas . '</tbody>
    </table>

    <table style="width:100%;border-collapse:collapse;margin-top:16px;">
      <tr>
        <td style="width:55%;vertical-align:top;">' . ($showPay ? $this->bloquePago($co, '#e2e8f0') : '') . '</td>
        <td style="width:45%;vertical-align:top;padding-left:16px;">
          <table style="width:100%;border-collapse:collapse;">
            <tr><td style="padding:4px 0;font-size:10.5px;color:#64748b;">Subtotal</td><td style="padding:4px 0;text-align:right;font-size:10.5px;">' . $this->money($d['monthlyPrice'] + $d['priceAntFactura']) . '</td></tr>
            <tr><td style="padding:4px 0;font-size:10.5px;color:#64748b;">Descuento</td><td style="padding:4px 0;text-align:right;font-size:10.5px;color:' . $accent . ';">− ' . $this->money($d['priceDiscount']) . '</td></tr>
            <tr><td colspan="2" style="border-top:1px solid #e2e8f0;padding-top:2px;"></td></tr>
            <tr>
              <td style="padding:10px 12px;background:' . $suaveAccent . ';font-size:11px;font-weight:bold;color:#0f172a;">TOTAL</td>
              <td style="padding:10px 12px;background:' . $suaveAccent . ';font-size:15px;font-weight:bold;color:' . $accent . ';text-align:right;">' . $this->money($total) . '</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>

    ' . ($showFooter ? '<div style="margin-top:26px;padding:12px;background:#f8fafc;text-align:center;font-size:10.5px;color:#475569;">' . $this->esc($co['footer']) . '</div>' : '') . '
    <div style="margin-top:8px;padding-bottom:28px;text-align:center;font-size:8.5px;color:#cbd5e1;">' . $this->esc($co['business_name']) . ' · NIT ' . $this->esc($co['nit']) . ' · Documento generado electrónicamente</div>
  </div>
</body></html>';
    }

    /* ══════════════════════════════════════════════════════════════════
     * MINIMAL — mucho aire, sólo líneas finas
     * ══════════════════════════════════════════════════════════════════ */
    public function templateMinimal(array $user, array $co, $cab, array $config = []): string
    {
        $d = $this->buildInvoiceData($user, $cab);
        $primary = $this->getConfigValue($config, 'primary_color', '#111827');
        $showLogo = $this->getConfigValue($config, 'show_logo', true);
        $showPay = $this->getConfigValue($config, 'show_payment_info', true);
        $showFooter = $this->getConfigValue($config, 'show_footer', true);
        $showBalance = $this->getConfigValue($config, 'show_balance', true);
        $total = $this->totalFinal($d);

        $filas = '';
        foreach ($this->lineas($user, $d, $showBalance) as $l) {
            $filas .= '<tr>
                <td style="padding:13px 0;border-bottom:1px solid #ececec;">
                  <div style="font-size:12px;color:#111827;">' . $this->esc($l['desc']) . '</div>
                  <div style="font-size:9.5px;color:#9ca3af;margin-top:3px;">' . $l['periodo'] . '</div>
                </td>
                <td style="padding:13px 0;border-bottom:1px solid #ececec;text-align:right;font-size:12px;color:#111827;">' . $this->money($l['total']) . '</td>
            </tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Factura ' . $this->esc($user['number_facture']) . '</title></head>
<body style="margin:0;padding:48px 54px;font-family:Helvetica,Arial,sans-serif;color:#374151;font-size:11px;">

  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="vertical-align:top;">
        ' . ($showLogo && $co['logo_base64'] ? '<img src="' . $co['logo_base64'] . '" style="max-height:38px;max-width:130px;margin-bottom:10px;"><br>' : '') . '
        <div style="font-size:13px;font-weight:bold;color:' . $primary . ';letter-spacing:.2px;">' . $this->esc($co['business_name']) . '</div>
        <div style="font-size:9.5px;color:#9ca3af;line-height:1.7;margin-top:4px;">NIT ' . $this->esc($co['nit']) . '<br>' . $this->esc($co['address']) . '<br>' . $this->esc($co['phone']) . '</div>
      </td>
      <td style="vertical-align:top;text-align:right;">
        <div style="font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#9ca3af;">Factura</div>
        <div style="font-size:20px;color:' . $primary . ';margin-top:4px;">' . $this->esc($user['number_facture']) . '</div>
        <div style="font-size:9.5px;color:#9ca3af;line-height:1.7;margin-top:8px;">Emitida ' . $this->fecha($d['fechaActual']) . '<br>Vence ' . $this->fecha($d['fechaVence']) . '</div>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#ececec;margin:30px 0;"></div>

  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="width:50%;vertical-align:top;">
        <div style="font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;">Cliente</div>
        <div style="font-size:12.5px;color:#111827;">' . $this->esc(trim(($user['names'] ?? '') . ' ' . ($user['lastname'] ?? ''))) . '</div>
        <div style="font-size:10px;color:#9ca3af;line-height:1.7;margin-top:3px;">' . $this->esc($user['dni'] ?? '') . '<br>' . $this->esc($user['address'] ?? '') . '<br>' . $this->esc($user['phone'] ?? '') . '</div>
      </td>
      <td style="width:50%;vertical-align:top;">
        <div style="font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;">Servicio</div>
        <div style="font-size:12.5px;color:#111827;">' . $this->esc($user['plan_name'] ?? '') . '</div>
        <div style="font-size:10px;color:#9ca3af;line-height:1.7;margin-top:3px;">' . $d['daysFacture'] . ' días · ' . $this->fecha($d['fechaNueva']) . ' a ' . $this->fecha($d['fechaInit']) . '</div>
      </td>
    </tr>
  </table>

  <table style="width:100%;border-collapse:collapse;margin-top:34px;">
    <thead><tr>
      <th style="padding-bottom:8px;border-bottom:1px solid ' . $primary . ';font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#9ca3af;text-align:left;">Concepto</th>
      <th style="padding-bottom:8px;border-bottom:1px solid ' . $primary . ';font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#9ca3af;text-align:right;">Importe</th>
    </tr></thead>
    <tbody>' . $filas . '</tbody>
  </table>

  <table style="width:100%;border-collapse:collapse;margin-top:18px;">
    <tr><td style="width:58%;"></td><td style="width:42%;">
      <table style="width:100%;border-collapse:collapse;">
        <tr><td style="padding:4px 0;font-size:10.5px;color:#9ca3af;">Subtotal</td><td style="padding:4px 0;text-align:right;font-size:10.5px;">' . $this->money($d['monthlyPrice'] + $d['priceAntFactura']) . '</td></tr>
        <tr><td style="padding:4px 0;font-size:10.5px;color:#9ca3af;">Descuento</td><td style="padding:4px 0;text-align:right;font-size:10.5px;">− ' . $this->money($d['priceDiscount']) . '</td></tr>
        <tr>
          <td style="padding:12px 0 0;border-top:2px solid ' . $primary . ';font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#111827;">Total</td>
          <td style="padding:12px 0 0;border-top:2px solid ' . $primary . ';text-align:right;font-size:19px;font-weight:bold;color:' . $primary . ';">' . $this->money($total) . '</td>
        </tr>
      </table>
    </td></tr>
  </table>

  ' . ($showPay ? $this->bloquePago($co, '#ececec') : '') . '
  ' . ($showFooter ? '<div style="margin-top:34px;text-align:center;font-size:10px;color:#9ca3af;">' . $this->esc($co['footer']) . '</div>' : '') . '
</body></html>';
    }

    /* ══════════════════════════════════════════════════════════════════
     * TIRILLA — formato angosto para impresora térmica
     * ══════════════════════════════════════════════════════════════════ */
    public function templateReceipt(array $user, array $co, $cab, array $config = []): string
    {
        $d = $this->buildInvoiceData($user, $cab);
        $primary = $this->getConfigValue($config, 'primary_color', '#111827');
        $showLogo = $this->getConfigValue($config, 'show_logo', true);
        $showPay = $this->getConfigValue($config, 'show_payment_info', true);
        $showFooter = $this->getConfigValue($config, 'show_footer', true);
        $showBalance = $this->getConfigValue($config, 'show_balance', true);
        $total = $this->totalFinal($d);
        $sep = '<div style="border-top:1px dashed #cbd5e1;margin:10px 0;"></div>';

        $filas = '';
        foreach ($this->lineas($user, $d, $showBalance) as $l) {
            $filas .= '<tr>
                <td style="padding:4px 0;font-size:10px;">' . $this->esc($l['desc']) . '<div style="font-size:8.5px;color:#94a3b8;">' . $l['periodo'] . '</div></td>
                <td style="padding:4px 0;text-align:right;font-size:10px;font-weight:bold;">' . $this->money($l['total']) . '</td>
            </tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Factura ' . $this->esc($user['number_facture']) . '</title></head>
<body style="margin:0;padding:16px;font-family:Helvetica,Arial,sans-serif;color:#1f2937;font-size:10px;">
  <table style="width:300px;margin:0 auto;border-collapse:collapse;">
    <tr><td style="text-align:center;padding-bottom:8px;">
      ' . ($showLogo && $co['logo_base64'] ? '<img src="' . $co['logo_base64'] . '" style="max-height:40px;max-width:120px;"><br>' : '') . '
      <div style="font-size:12px;font-weight:bold;color:' . $primary . ';margin-top:5px;">' . $this->esc($co['business_name']) . '</div>
      <div style="font-size:8.5px;color:#64748b;line-height:1.6;">NIT ' . $this->esc($co['nit']) . '<br>' . $this->esc($co['address']) . '<br>Tel. ' . $this->esc($co['phone']) . '</div>
    </td></tr>
    <tr><td>' . $sep . '</td></tr>
    <tr><td style="text-align:center;">
      <div style="font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#94a3b8;">Factura de venta</div>
      <div style="font-size:15px;font-weight:bold;color:' . $primary . ';margin:2px 0;">' . $this->esc($user['number_facture']) . '</div>
      <div style="font-size:9px;color:#64748b;">Emitida ' . $this->fecha($d['fechaActual']) . ' · Vence ' . $this->fecha($d['fechaVence']) . '</div>
    </td></tr>
    <tr><td>' . $sep . '</td></tr>
    <tr><td style="font-size:9.5px;line-height:1.7;">
      <strong>' . $this->esc(trim(($user['names'] ?? '') . ' ' . ($user['lastname'] ?? ''))) . '</strong><br>
      CC/NIT ' . $this->esc($user['dni'] ?? '') . '<br>
      ' . $this->esc($user['address'] ?? '') . '<br>
      Tel. ' . $this->esc($user['phone'] ?? '') . '
    </td></tr>
    <tr><td>' . $sep . '</td></tr>
    <tr><td><table style="width:100%;border-collapse:collapse;">' . $filas . '</table></td></tr>
    <tr><td>' . $sep . '</td></tr>
    <tr><td>
      <table style="width:100%;border-collapse:collapse;">
        <tr><td style="padding:2px 0;font-size:9.5px;color:#64748b;">Subtotal</td><td style="padding:2px 0;text-align:right;font-size:9.5px;">' . $this->money($d['monthlyPrice'] + $d['priceAntFactura']) . '</td></tr>
        <tr><td style="padding:2px 0;font-size:9.5px;color:#64748b;">Descuento</td><td style="padding:2px 0;text-align:right;font-size:9.5px;">− ' . $this->money($d['priceDiscount']) . '</td></tr>
        <tr>
          <td style="padding:8px 6px;background:' . $primary . ';color:#fff;font-size:10px;font-weight:bold;">TOTAL</td>
          <td style="padding:8px 6px;background:' . $primary . ';color:#fff;font-size:13px;font-weight:bold;text-align:right;">' . $this->money($total) . '</td>
        </tr>
      </table>
    </td></tr>
    ' . ($showPay ? '<tr><td>' . $this->bloquePago($co, '#e2e8f0') . '</td></tr>' : '') . '
    ' . ($showFooter ? '<tr><td style="text-align:center;padding-top:12px;font-size:9.5px;color:#64748b;">' . $this->esc($co['footer']) . '</td></tr>' : '') . '
    <tr><td style="text-align:center;padding-top:6px;font-size:8px;color:#cbd5e1;">Documento generado electrónicamente</td></tr>
  </table>
</body></html>';
    }
}
