<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;
use App\Models\ContractPdfField;

class ContractPdfService
{
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
        ?string $documentBackPath = null
    ): string {
        $pdf = $this->buildFilledPdf($pdfBasePath, $fieldValues, $pdfFields, $signatureBase64);

        // Página de firma al final
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 20, 'CONSTANCIA DE FIRMA', 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, 'El suscrito declara haber leido y aceptado el contrato.', 0, 1, 'C');
        $pdf->Cell(0, 10, 'Cliente: ' . $this->toIso($clientName), 0, 1, 'C');
        $pdf->Cell(0, 10, 'Fecha: ' . now()->format('d/m/Y H:i'), 0, 1, 'C');
        $pdf->Ln(15);

        if ($signatureBase64) {
            $imageData = $this->extractImageFromBase64($signatureBase64);
            if ($imageData) {
                $tmpFile = tempnam(sys_get_temp_dir(), 'sig_') . '.png';
                file_put_contents($tmpFile, $imageData);
                $pdf->Image($tmpFile, 65, 120, 80, 0, 'PNG');
                unlink($tmpFile);
            }
        }

        $pdf->Ln(60);
        $pdf->Line(60, 180, 150, 180);
        $pdf->SetY(182);
        $pdf->Cell(0, 8, 'Firma del cliente', 0, 1, 'C');

        // Página de documentos de identidad
        if ($documentFrontPath || $documentBackPath) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 16);
            $pdf->Cell(0, 16, 'DOCUMENTOS DE IDENTIDAD', 0, 1, 'C');
            $pdf->Ln(4);

            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(0, 8, 'Se anexan las fotografias de ambas caras del documento de identidad del cliente.', 0, 1, 'C');
            $pdf->Ln(4);

            if ($documentFrontPath && file_exists(storage_path('app/public/' . $documentFrontPath))) {
                $pdf->SetFont('Helvetica', 'B', 11);
                $pdf->Cell(0, 8, 'CARA FRONTAL', 0, 1, 'C');
                $pdf->Image(storage_path('app/public/' . $documentFrontPath), 35, $pdf->GetY(), 140, 0, pathinfo($documentFrontPath, PATHINFO_EXTENSION));
                $pdf->Ln(90);
            }

            if ($documentBackPath && file_exists(storage_path('app/public/' . $documentBackPath))) {
                $pdf->SetFont('Helvetica', 'B', 11);
                $pdf->Cell(0, 8, 'CARA TRASERA', 0, 1, 'C');
                $pdf->Image(storage_path('app/public/' . $documentBackPath), 35, $pdf->GetY(), 140, 0, pathinfo($documentBackPath, PATHINFO_EXTENSION));
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
        $fullPath = storage_path('app/public/' . $pdfBasePath);

        if (!file_exists($fullPath)) {
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

        // ── Campo especial: firma del cliente ───────────────────────────
        if ($var === '{{firma}}') {
            if ($signatureBase64) {
                $imageData = $this->extractImageFromBase64($signatureBase64);
                if ($imageData) {
                    $tmpFile = tempnam(sys_get_temp_dir(), 'sig_') . '.png';
                    file_put_contents($tmpFile, $imageData);
                    // Ancho fijo 80 pts, altura proporcional
                    $pdf->Image($tmpFile, $x, $y, 80, 0, 'PNG');
                    unlink($tmpFile);
                }
            } else {
                // Preview: placeholder de línea de firma
                $pdf->SetFont('Helvetica', 'I', $size);
                $pdf->SetTextColor(150, 150, 150);
                $pdf->SetXY($x, $y);
                $pdf->Cell(80, 0, '________________________', 0, 0, 'L');
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

        // Usamos Text() en vez de Cell() para evitar el offset interno de FPDF.
        // Cell(0,0) suma ~0.8*fontSize internamente, desplazando el texto muy abajo.
        // Text(x,y) coloca la baseline EXACTAMENTE en (x,y), sin offsets ocultos.
        // Sumamos 0.25*fontSize para centrar visualmente el texto alrededor del click.
        $baselineOffset = $size * 0.25;
        $pdf->Text($x, $y + $baselineOffset, $value);

        $pdf->SetTextColor(0, 0, 0);
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
