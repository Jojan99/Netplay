<?php

namespace App\Services\Correo;

use App\Models\Company;

/**
 * El diseño de todos los correos de la plataforma, en un solo lugar.
 *
 * Los correos se armaban cada uno con su HTML suelto: uno verde, otro azul, con
 * tipografías distintas y sin logo. Aquí hay una sola plantilla —encabezado con la marca,
 * título, cuerpo, cuadro de datos, botón y pie— y cada correo sólo dice QUÉ lleva.
 *
 * Está hecha con tablas y estilos en línea porque es lo único que respetan todos los
 * programas de correo (Gmail quita los <style>, Outlook no entiende flex ni grid).
 *
 * Dos caras: la de Netvula (correos de la plataforma a las empresas) y la de una empresa
 * (lo que un ISP le manda a sus clientes: lleva su nombre, y Netvula sólo firma al pie).
 */
class PlantillaDeCorreo
{
    private const AZUL   = '#1463ff';
    private const MARINO = '#0b1b33';
    private const TEXTO  = '#17293a';
    private const TENUE  = '#5b7083';
    private const LINEA  = '#e3e9ee';
    private const FONDO  = '#f1f4f8';

    private const TONOS = [
        'info'    => ['#eaf1ff', '#1463ff', '#0b3aa8'],
        'ok'      => ['#e6f6ec', '#1f8a4c', '#12592f'],
        'aviso'   => ['#fff4dc', '#d9911a', '#7a4f08'],
        'peligro' => ['#fde8e6', '#d0392b', '#8a1f15'],
    ];

    /** @var list<string> */
    private array $bloques = [];
    /** @var list<string> */
    private array $plano = [];
    private string $antetitulo = '';
    private string $titulo = '';
    private string $tono = 'info';
    private string $pie = '';

    private function __construct(
        private string $marca,
        private ?string $logo,
        private bool $deNetvula,
        private string $contacto = '',
    ) {}

    /** Correos de la plataforma: a nombre de Netvula, con su logo. */
    public static function netvula(): self
    {
        $soporte = array_filter([
            config('plataforma.soporte.correo') ?: null,
            ($wa = preg_replace('/\D/', '', (string) config('plataforma.soporte.whatsapp'))) ? 'WhatsApp +' . $wa : null,
        ]);

        return new self(
            (string) config('plataforma.nombre', 'Netvula'),
            'https://' . config('plataforma.dominio', 'netvula.com') . '/assets/brand/netvula-logo-dark.png',
            true,
            implode(' · ', $soporte),
        );
    }

    /** Correos de un ISP a sus clientes: a nombre de la empresa. */
    public static function deEmpresa(Company $empresa, ?string $logoCid = null): self
    {
        // El membrete es el que la empresa parametrizó para sus facturas: el mismo del PDF.
        // Nada se inventa: lo que no esté cargado, no sale.
        $m = \App\Resources\Templates\TemplatesPdf::datosEmpresa($empresa);
        $nombre = $m['business_name'];
        $contacto = array_filter([
            $m['nit'] !== '' ? 'NIT ' . $m['nit'] : null,
            $m['phone'] !== '' ? 'Tel. ' . $m['phone'] : null,
            trim(implode(', ', array_filter([$m['address'], $m['city']]))) ?: null,
            filter_var(trim((string) $empresa->email), FILTER_VALIDATE_EMAIL) ? trim((string) $empresa->email) : null,
        ]);

        return new self($nombre ?: 'Su proveedor de internet', $logoCid ? 'cid:' . $logoCid : null, false, implode(' · ', $contacto));
    }

    // ── Contenido ────────────────────────────────────────────────────────────

    /** El renglón chico encima del título («Recordatorio de pago», «Factura»). */
    public function antetitulo(string $texto): self
    {
        $this->antetitulo = $texto;

        return $this;
    }

    /** @param string $tono info | ok | aviso | peligro: pinta la franja y el antetítulo. */
    public function titulo(string $texto, string $tono = 'info'): self
    {
        $this->titulo = $texto;
        $this->tono = isset(self::TONOS[$tono]) ? $tono : 'info';

        return $this;
    }

    /** Un párrafo. Admite <strong> y <a>; el resto del texto va escapado por quien lo llama. */
    public function parrafo(string $html): self
    {
        $this->bloques[] = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:' . self::TEXTO . ';">' . $html . '</p>';
        $this->plano[] = self::aTexto($html);

        return $this;
    }

    /** El número grande del correo: el valor a pagar, el saldo. */
    public function cifra(string $etiqueta, string $valor, ?string $nota = null): self
    {
        [$suave, $fuerte, $tinta] = self::TONOS[$this->tono];
        $this->bloques[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px;"><tr><td align="center" style="padding:20px 16px;background:' . $suave . ';border-radius:12px;">'
            . '<div style="font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:' . $tinta . ';">' . self::e($etiqueta) . '</div>'
            . '<div style="margin-top:6px;font-size:32px;line-height:1.1;font-weight:800;color:' . self::MARINO . ';">' . self::e($valor) . '</div>'
            . ($nota ? '<div style="margin-top:6px;font-size:13px;color:' . $tinta . ';">' . self::e($nota) . '</div>' : '')
            . '</td></tr></table>';
        $this->plano[] = "{$etiqueta}: {$valor}" . ($nota ? " ({$nota})" : '');

        return $this;
    }

    /**
     * Un cuadro de datos: Plan, Periodo, Vence…
     *
     * @param array<string, string|null> $datos  lo que venga vacío no se muestra
     */
    public function datos(array $datos): self
    {
        $filas = '';
        $ultimo = array_key_last(array_filter($datos, fn ($v) => $v !== null && $v !== ''));

        foreach ($datos as $nombre => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            $borde = $nombre === $ultimo ? '' : 'border-bottom:1px solid ' . self::LINEA . ';';
            $filas .= '<tr><td style="padding:11px 14px;font-size:13px;color:' . self::TENUE . ';' . $borde . '">' . self::e((string) $nombre) . '</td>'
                . '<td align="right" style="padding:11px 14px;font-size:14px;font-weight:600;color:' . self::TEXTO . ';' . $borde . '">' . self::e((string) $valor) . '</td></tr>';
            $this->plano[] = "{$nombre}: {$valor}";
        }

        if ($filas !== '') {
            $this->bloques[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 18px;border:1px solid ' . self::LINEA . ';border-radius:12px;border-collapse:separate;">' . $filas . '</table>';
        }

        return $this;
    }

    /** Un recuadro de color para lo que no se puede pasar por alto. */
    public function aviso(string $html, ?string $tono = null): self
    {
        [$suave, $fuerte, $tinta] = self::TONOS[$tono && isset(self::TONOS[$tono]) ? $tono : $this->tono];
        $this->bloques[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 18px;"><tr>'
            . '<td style="padding:13px 16px;background:' . $suave . ';border-left:4px solid ' . $fuerte . ';border-radius:8px;font-size:14px;line-height:1.55;color:' . $tinta . ';">' . $html . '</td></tr></table>';
        $this->plano[] = self::aTexto($html);

        return $this;
    }

    /** Un texto que la empresa escribió tal cual (sus medios de pago): se respeta renglón por renglón. */
    public function textoDeLaEmpresa(string $titulo, string $texto): self
    {
        $this->bloques[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 18px;border:1px solid ' . self::LINEA . ';border-radius:12px;border-collapse:separate;"><tr>'
            . '<td style="padding:14px 16px;">'
            . '<div style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:' . self::TENUE . ';">' . self::e($titulo) . '</div>'
            . '<div style="font-size:14.5px;line-height:1.6;color:' . self::TEXTO . ';">' . nl2br(self::e(trim($texto))) . '</div>'
            . '</td></tr></table>';
        $this->plano[] = mb_strtoupper($titulo) . "\n" . trim($texto);

        return $this;
    }

    public function boton(string $texto, string $url): self
    {
        $u = self::e($url);
        $this->bloques[] = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px auto 20px;"><tr>'
            . '<td align="center" bgcolor="' . self::AZUL . '" style="border-radius:10px;">'
            . '<a href="' . $u . '" target="_blank" style="display:inline-block;padding:14px 30px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">' . self::e($texto) . '</a>'
            . '</td></tr></table>';
        $this->plano[] = "{$texto}: {$url}";

        return $this;
    }

    /** El enlace escrito, para quien no puede tocar el botón. */
    public function enlace(string $url, string $antes = 'Si el botón no funciona, copie y pegue este enlace en su navegador:'): self
    {
        $u = self::e($url);
        $this->bloques[] = '<p style="margin:0 0 16px;font-size:12.5px;line-height:1.55;color:' . self::TENUE . ';">' . self::e($antes) . '<br>'
            . '<a href="' . $u . '" style="color:' . self::AZUL . ';word-break:break-all;">' . $u . '</a></p>';

        return $this;
    }

    /** Una línea al final del cuerpo, en chico. */
    public function nota(string $html): self
    {
        $this->pie = $html;

        return $this;
    }

    // ── Salida ───────────────────────────────────────────────────────────────

    public function html(): string
    {
        [$suave, $fuerte, $tinta] = self::TONOS[$this->tono];
        $marca = self::e($this->marca);

        $cabecera = $this->logo
            ? '<img src="' . self::e($this->logo) . '" alt="' . $marca . '" height="' . ($this->deNetvula ? '30' : '48') . '" style="display:block;border:0;outline:none;height:' . ($this->deNetvula ? '30' : '48') . 'px;max-width:240px;">'
            : '';

        // El nombre de la empresa va siempre escrito en sus correos: muchos logos no lo traen.
        if (!$this->deNetvula) {
            $cabecera = ($cabecera !== '' ? '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding-right:14px;">' . $cabecera . '</td><td>' : '')
                . '<div style="font-size:19px;font-weight:800;letter-spacing:.2px;color:' . self::MARINO . ';">' . $marca . '</div>'
                . ($cabecera !== '' ? '</td></tr></table>' : '');
        }

        $firma = $this->deNetvula
            ? '<strong style="color:' . self::TEXTO . ';">' . $marca . '</strong> · La plataforma para administrar su ISP'
            : '<strong style="color:' . self::TEXTO . ';">' . $marca . '</strong>';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="color-scheme" content="light only">'
            . '<title>' . self::e($this->titulo) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . self::FONDO . ';font-family:\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">'
            // Lo que muestra la bandeja al lado del asunto.
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . self::e(mb_substr(implode(' ', $this->plano), 0, 140)) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::FONDO . ';"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid ' . self::LINEA . ';">'
            . '<tr><td style="padding:22px 32px;background:' . ($this->deNetvula ? self::MARINO : '#ffffff') . ';">' . $cabecera . '</td></tr>'
            . '<tr><td style="height:4px;line-height:4px;font-size:0;background:' . $fuerte . ';">&nbsp;</td></tr>'
            . '<tr><td style="padding:30px 32px 8px;">'
            . ($this->antetitulo !== '' ? '<div style="margin:0 0 8px;font-size:11.5px;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;color:' . $fuerte . ';">' . self::e($this->antetitulo) . '</div>' : '')
            . '<h1 style="margin:0 0 18px;font-size:24px;line-height:1.25;font-weight:800;color:' . self::MARINO . ';">' . self::e($this->titulo) . '</h1>'
            . implode('', $this->bloques)
            . ($this->pie !== '' ? '<p style="margin:6px 0 16px;font-size:12.5px;line-height:1.55;color:' . self::TENUE . ';">' . $this->pie . '</p>' : '')
            . '</td></tr>'
            . '<tr><td style="padding:18px 32px 24px;border-top:1px solid ' . self::LINEA . ';background:#fafbfc;font-size:12.5px;line-height:1.6;color:' . self::TENUE . ';">'
            . $firma
            . ($this->contacto !== '' ? '<br>' . self::e($this->contacto) : '')
            . '</td></tr></table>'
            . '<div style="max-width:600px;padding:14px 12px 0;font-size:11.5px;line-height:1.5;color:#8a9aab;">'
            . ($this->deNetvula
                ? '© ' . date('Y') . ' ' . $marca . '. Recibe este correo porque su empresa usa la plataforma.'
                : '© ' . date('Y') . ' ' . $marca . '. Enviado con Netvula.')
            . '</div>'
            . '</td></tr></table></body></html>';
    }

    /** La versión en texto plano: es lo que lee un filtro de spam y un lector de pantalla. */
    public function texto(): string
    {
        return trim(($this->antetitulo !== '' ? mb_strtoupper($this->antetitulo) . "\n" : '') . $this->titulo . "\n\n" . implode("\n\n", array_filter($this->plano))
            . ($this->pie !== '' ? "\n\n" . self::aTexto($this->pie) : '') . "\n\n— " . $this->marca . ($this->contacto !== '' ? "\n" . $this->contacto : ''));
    }

    public static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    private static function aTexto(string $html): string
    {
        $html = preg_replace('/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $html);

        return trim(html_entity_decode(strip_tags((string) preg_replace('/<br\s*\/?>/i', "\n", (string) $html)), ENT_QUOTES, 'UTF-8'));
    }
}
