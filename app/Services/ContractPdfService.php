<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;
use App\Models\ContractPdfField;

class ContractPdfService
{
    /**
     * El documento se crea con `new Fpdi()`, o sea en milímetros, pero el tamaño
     * de fuente de FPDF siempre va en puntos. Las dos unidades se mezclaban sin
     * decirlo y el ajuste de la línea base era un 0.25 mágico.
     */
    private const PT_A_MM = 25.4 / 72;

    /**
     * Altura de las mayúsculas de Helvetica, en fracción del tamaño de fuente.
     * El punto que el operador marca en el panel es la ESQUINA SUPERIOR IZQUIERDA
     * del texto (el borde de arriba de las mayúsculas), así que la línea base se
     * dibuja esta distancia más abajo. Con 10 pt son 2.53 mm, prácticamente lo
     * mismo que el 0.25 anterior: las plantillas ya configuradas no se mueven.
     */
    private const ALTURA_MAYUSCULAS = 0.717;

    /** Cuánto se puede achicar la fuente para que un valor largo entre en su ancho. */
    private const FUENTE_MINIMA = 0.6;

    /** Milímetros que baja la línea base respecto del punto marcado. */
    public static function desplazamientoLineaBase(float $tamanoPt): float
    {
        return $tamanoPt * self::ALTURA_MAYUSCULAS * self::PT_A_MM;
    }

    /**
     * Combina el PDF base original con los datos del cliente, la firma y los documentos.
     *
     * @param string $pdfBasePath Ruta del PDF original (storage/public)
     * @param string $signatureBase64 Firma en base64
     * @param string $clientName Nombre del cliente
     * @param array $fieldValues Valores de variables: ['{{nombre}}' => 'Juan', ...]
     * @param array $pdfFields Configuraciones de posición
     * @param string|null $documentFrontPath Ruta de la foto frontal del documento
     * @param string|null $documentBackPath Ruta de la foto trasera del documento
     * @return string Contenido binario del PDF generado
     */
    public function combineWithSignature(
        string $pdfBasePath,
        string $signatureBase64,
        string $clientName,
        array $fieldValues = [],
        array $pdfFields = [],
        ?string $documentFrontPath = null,
        ?string $documentBackPath = null,
        array $constancia = []
    ): string {
        $pdf = $this->buildFilledPdf($pdfBasePath, $fieldValues, $pdfFields, $signatureBase64);

        // Página de constancia al final
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->Cell(0, 14, 'CONSTANCIA DE FIRMA ELECTRONICA', 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(0, 7, 'Cliente: ' . $this->toIso($clientName), 0, 1, 'C');
        $pdf->Cell(0, 7, 'Fecha de firma: ' . now()->format('d/m/Y H:i'), 0, 1, 'C');
        $pdf->Ln(4);

        // Aceptación de los términos, con el texto que aceptó y desde dónde.
        if (!empty($constancia['texto'])) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'ACEPTACION DE TERMINOS', 0, 1, 'L');
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->MultiCell(0, 5, $this->toIso('"' . $constancia['texto'] . '"'), 0, 'J');
            $pdf->Ln(2);

            $pdf->SetFont('Helvetica', '', 9);
            $momento = $constancia['momento'] ?? now();
            $pdf->MultiCell(0, 5, $this->toIso(
                'Aceptado el ' . (is_string($momento) ? $momento : $momento->format('d/m/Y H:i:s'))
                . ' desde la direccion IP ' . ($constancia['ip'] ?? '-')
                . '. Dispositivo: ' . substr((string) ($constancia['dispositivo'] ?? '-'), 0, 160)
            ), 0, 'L');
            $pdf->Ln(6);
        }

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 6, 'Firma del cliente:', 0, 1, 'C');

        if ($signatureBase64) {
            $imageData = $this->extractImageFromBase64($signatureBase64);
            if ($imageData) {
                // tempnam() YA crea el archivo: pegarle ".png" dejaba el
                // original de 0 bytes en /tmp para siempre, uno por firma.
                $tmpFile = tempnam(sys_get_temp_dir(), 'sig_');
                file_put_contents($tmpFile, $imageData);
                $pdf->Image($tmpFile, 65, $pdf->GetY() + 3, 80, 0, 'PNG');
                @unlink($tmpFile);
            }
        }

        $pdf->SetY($pdf->GetY() + 42);
        $pdf->Line(60, $pdf->GetY(), 150, $pdf->GetY());
        $pdf->SetY($pdf->GetY() + 2);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(0, 6, $this->toIso($clientName), 0, 1, 'C');

        // Página de documentos de identidad
        if ($documentFrontPath || $documentBackPath) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 16);
            $pdf->Cell(0, 16, 'DOCUMENTOS DE IDENTIDAD', 0, 1, 'C');
            $pdf->Ln(4);

            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(0, 8, 'Se anexan las fotografias de ambas caras del documento de identidad del cliente.', 0, 1, 'C');
            $pdf->Ln(4);

            // Fotos nuevas en carpeta privada, las viejas en public (ArchivosContrato resuelve ambas)
            if ($frontAbs = \App\Support\ArchivosContrato::absoluta($documentFrontPath)) {
                $pdf->SetFont('Helvetica', 'B', 11);
                $pdf->Cell(0, 8, 'CARA FRONTAL', 0, 1, 'C');
                $pdf->Image($frontAbs, 35, $pdf->GetY(), 140, 0, pathinfo($documentFrontPath, PATHINFO_EXTENSION));
                $pdf->Ln(90);
            }

            if ($backAbs = \App\Support\ArchivosContrato::absoluta($documentBackPath)) {
                $pdf->SetFont('Helvetica', 'B', 11);
                $pdf->Cell(0, 8, 'CARA TRASERA', 0, 1, 'C');
                $pdf->Image($backAbs, 35, $pdf->GetY(), 140, 0, pathinfo($documentBackPath, PATHINFO_EXTENSION));
            }
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Genera el PDF base con las variables del cliente estampadas (sin página de firma extra).
     * Útil para la vista previa antes de firmar.
     */
    public function fillPdfBase(
        string $pdfBasePath,
        array $fieldValues = [],
        array $pdfFields = []
    ): string {
        $pdf = $this->buildFilledPdf($pdfBasePath, $fieldValues, $pdfFields, '');
        return $pdf->Output('', 'S');
    }

    /**
     * Construye el PDF importando el original y estampando campos.
     */
    private function buildFilledPdf(
        string $pdfBasePath,
        array $fieldValues,
        array $pdfFields,
        string $signatureBase64 = ''
    ): Fpdi {
        // Privado primero (ahí viven desde la mudanza), public de respaldo.
        $fullPath = \App\Support\ArchivosContrato::plantilla($pdfBasePath);

        if (!$fullPath) {
            throw new \RuntimeException('PDF base no encontrado: ' . $pdfBasePath);
        }

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($fullPath);

        // Agrupar campos por página
        $fieldsByPage = [];
        foreach ($pdfFields as $field) {
            $page = is_object($field) ? $field->page : ($field['page'] ?? 1);
            $fieldsByPage[$page][] = $field;
        }

        // Importar páginas del PDF original y estampar datos
        for ($i = 1; $i <= $pageCount; $i++) {
            $templateId = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($templateId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            if (!empty($fieldsByPage[$i])) {
                foreach ($fieldsByPage[$i] as $field) {
                    $this->stampField($pdf, $field, $fieldValues, $signatureBase64);
                }
            }
        }

        return $pdf;
    }

    /**
     * Escribe texto o estampa imagen de firma sobre el PDF en coordenadas exactas.
     */
    private function stampField(Fpdi $pdf, $field, array $fieldValues, string $signatureBase64): void
    {
        $var   = is_object($field) ? $field->variable : ($field['variable'] ?? '');
        $x     = is_object($field) ? (float)$field->x : (float)($field['x'] ?? 0);
        $y     = is_object($field) ? (float)$field->y : (float)($field['y'] ?? 0);
        $size  = is_object($field) ? (int)$field->font_size : (int)($field['font_size'] ?? 10);
        $color = is_object($field) ? $field->color : ($field['color'] ?? '000000');
        $ancho = is_object($field) ? (float)($field->max_width ?? 0) : (float)($field['max_width'] ?? 0);

        // ── Campo especial: firma del cliente ───────────────────────────
        if ($var === '{{firma}}') {
            if ($signatureBase64) {
                $imageData = $this->extractImageFromBase64($signatureBase64);
                if ($imageData) {
                    $tmpFile = tempnam(sys_get_temp_dir(), 'sig_');
                    file_put_contents($tmpFile, $imageData);
                    // Ancho en MILÍMETROS (la unidad del documento), altura
                    // proporcional. El ancho configurado manda si es razonable;
                    // los 200 mm que ponía el panel por defecto ocupaban la hoja.
                    $pdf->Image($tmpFile, $x, $y, $this->anchoFirma($ancho), 0, 'PNG');
                    @unlink($tmpFile);
                }
            } else {
                // Vista previa: raya de firma en el mismo sitio donde irá la imagen
                $pdf->SetFont('Helvetica', 'I', $size);
                $pdf->SetTextColor(150, 150, 150);
                $pdf->Text($x, $y + self::desplazamientoLineaBase((float) $size), '________________________');
                $pdf->SetTextColor(0, 0, 0);
            }
            return;
        }

        // ── Campo de texto normal ───────────────────────────────────────
        $value = $fieldValues[$var] ?? '';
        if ($value === '' || $value === null) {
            return;
        }

        $value = $this->toIso($value);

        $r = hexdec(substr($color, 0, 2));
        $g = hexdec(substr($color, 2, 2));
        $b = hexdec(substr($color, 4, 2));

        $pdf->SetFont('Helvetica', '', $size);
        $pdf->SetTextColor($r, $g, $b);

        // El ancho máximo se guardaba pero no se usaba nunca: una dirección larga
        // se comía las casillas de al lado. Se achica la fuente hasta el 60% y,
        // si aun así no entra, se recorta.
        [$value, $size] = $this->ajustarAlAncho($pdf, $value, $size, $ancho);

        // Text() pone la línea base exactamente en (x, y); Cell() le suma un alto
        // interno. El punto marcado es la esquina superior izquierda del texto,
        // así que la línea base baja la altura de las mayúsculas, convertida de
        // puntos a milímetros (la unidad del documento).
        $pdf->Text($x, $y + self::desplazamientoLineaBase((float) $size), $value);

        $pdf->SetTextColor(0, 0, 0);
    }

    /** Ancho en mm de la firma estampada. */
    public static function anchoFirma(float $ancho): float
    {
        return ($ancho > 0 && $ancho <= 120) ? $ancho : 80;
    }

    /**
     * Encoge (y si hace falta recorta) un valor para que quepa en su ancho.
     * Devuelve [texto, tamaño de fuente]; GetStringWidth ya responde en mm.
     */
    private function ajustarAlAncho(Fpdi $pdf, string $value, int $size, float $ancho): array
    {
        if ($ancho <= 0 || $pdf->GetStringWidth($value) <= $ancho) {
            return [$value, $size];
        }

        $minimo = max(5, (int) round($size * self::FUENTE_MINIMA));
        for ($actual = $size - 1; $actual >= $minimo; $actual--) {
            $pdf->SetFont('Helvetica', '', $actual);
            if ($pdf->GetStringWidth($value) <= $ancho) {
                return [$value, $actual];
            }
        }

        $pdf->SetFont('Helvetica', '', $minimo);
        while (strlen($value) > 1 && $pdf->GetStringWidth($value . '.') > $ancho) {
            $value = substr($value, 0, -1);
        }

        return [$value . '.', $minimo];
    }

    /**
     * Convierte UTF-8 a ISO-8859-1 para compatibilidad con FPDF.
     */
    private function toIso(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }

    private function extractImageFromBase64(string $base64): ?string
    {
        if (str_starts_with($base64, 'data:image')) {
            $parts = explode(',', $base64, 2);
            return isset($parts[1]) ? base64_decode($parts[1]) : null;
        }
        return base64_decode($base64);
    }
}
