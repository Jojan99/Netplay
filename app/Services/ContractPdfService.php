<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;

class ContractPdfService
{
    /**
     * Combina el PDF base original con la firma del cliente.
     * Importa todas las páginas del PDF original y agrega la firma
     * en la última página (o en una página nueva al final).
     *
     * @param string $pdfBasePath Ruta del PDF original (storage/public)
     * @param string $signatureBase64 Firma en base64 (data:image/png;base64,...)
     * @param string $clientName Nombre del cliente para el filename
     * @return string Contenido binario del PDF generado
     */
    public function combineWithSignature(string $pdfBasePath, string $signatureBase64, string $clientName): string
    {
        $fullPath = storage_path('app/public/' . $pdfBasePath);

        if (!file_exists($fullPath)) {
            throw new \RuntimeException('PDF base no encontrado: ' . $pdfBasePath);
        }

        $pdf = new Fpdi();

        // Obtener número de páginas del PDF original
        $pageCount = $pdf->setSourceFile($fullPath);

        // Importar todas las páginas del PDF original
        for ($i = 1; $i <= $pageCount; $i++) {
            $templateId = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($templateId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);
        }

        // Agregar página de firma al final
        $pdf->AddPage();

        // Título de la página de firma
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 20, 'CONSTANCIA DE FIRMA', 0, 1, 'C');
        $pdf->Ln(5);

        // Información
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, 'El suscrito declara haber leído y aceptado el contrato.', 0, 1, 'C');
        $pdf->Cell(0, 10, 'Cliente: ' . $clientName, 0, 1, 'C');
        $pdf->Cell(0, 10, 'Fecha: ' . now()->format('d/m/Y H:i'), 0, 1, 'C');
        $pdf->Ln(15);

        // Insertar firma
        if ($signatureBase64) {
            $imageData = $this->extractImageFromBase64($signatureBase64);
            if ($imageData) {
                $tmpFile = tempnam(sys_get_temp_dir(), 'sig_') . '.png';
                file_put_contents($tmpFile, $imageData);
                // Centrar firma
                $pdf->Image($tmpFile, 65, 120, 80, 0, 'PNG');
                unlink($tmpFile);
            }
        }

        // Línea de firma
        $pdf->Ln(60);
        $pdf->Line(60, 180, 150, 180);
        $pdf->SetY(182);
        $pdf->Cell(0, 8, 'Firma del cliente', 0, 1, 'C');

        return $pdf->Output('', 'S');
    }

    /**
     * Extrae datos binarios de una imagen base64.
     */
    private function extractImageFromBase64(string $base64): ?string
    {
        if (str_starts_with($base64, 'data:image')) {
            $parts = explode(',', $base64, 2);
            return isset($parts[1]) ? base64_decode($parts[1]) : null;
        }
        return base64_decode($base64);
    }
}
