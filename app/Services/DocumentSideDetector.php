<?php

namespace App\Services;

/**
 * Detecta automáticamente si una imagen de cédula es cara frontal o trasera
 * usando análisis de color/heurística sin dependencias externas.
 */
class DocumentSideDetector
{
    /**
     * Analiza la imagen y retorna 'front', 'back' o 'unknown'.
     *
     * Heurística para cédulas colombianas:
     * - Cara frontal: zona superior tiene foto de persona (muchos pixeles de tonos piel)
     * - Cara trasera: zona superior tiene huella/espacio vacío, más texto denso
     */
    public static function detect(string $imagePath): string
    {
        if (!extension_loaded('gd')) {
            return 'unknown';
        }

        $img = @imagecreatefromjpeg($imagePath) ?: @imagecreatefrompng($imagePath);
        if (!$img) {
            return 'unknown';
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 100 || $h < 100) {
            imagedestroy($img);
            return 'unknown';
        }

        // ── Análisis 1: Zona superior (donde va la foto en la cara frontal) ──
        // En cédulas colombianas, la foto ocupa ~30-45% del alto desde arriba
        $topZoneY = (int) ($h * 0.12);
        $topZoneH = (int) ($h * 0.38);
        $topZoneW = (int) ($w * 0.55); // foto usualmente está a la izquierda

        $skinPixels = 0;
        $totalPixelsTop = $topZoneW * $topZoneH;
        $brightnessSum = 0;
        $skinBrightnessSum = 0;

        for ($y = $topZoneY; $y < min($h, $topZoneY + $topZoneH); $y += 2) {
            for ($x = 0; $x < min($w, $topZoneW); $x += 2) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $brightness = ($r + $g + $b) / 3;
                $brightnessSum += $brightness;

                // Rangos típicos de tonos de piel humana
                if (
                    $r > 60 && $r < 230 &&
                    $g > 40 && $g < 200 &&
                    $b > 30 && $b < 180 &&
                    $r > $g && $r > $b &&
                    ($r - $g) > 5 && ($r - $g) < 80 &&
                    ($g - $b) > 5 && ($g - $b) < 70
                ) {
                    $skinPixels++;
                    $skinBrightnessSum += $brightness;
                }
            }
        }

        $sampledTopPixels = max(1, (int)($totalPixelsTop / 4));
        $skinRatio = $skinPixels / $sampledTopPixels;
        $avgBrightness = $brightnessSum / $sampledTopPixels;

        // ── Análisis 2: Entropía de color global (cara trasera suele tener más blanco/negro) ──
        $darkPixels = 0;
        $lightPixels = 0;
        $edgePixels = 0;
        $sampleCount = 0;

        for ($y = 0; $y < $h; $y += 4) {
            for ($x = 0; $x < $w; $x += 4) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lum = ($r + $g + $b) / 3;

                if ($lum < 50) $darkPixels++;
                if ($lum > 200) $lightPixels++;

                // Detección básica de bordes (diferencia con vecino derecho)
                if ($x + 4 < $w) {
                    $rgb2 = imagecolorat($img, $x + 4, $y);
                    $r2 = ($rgb2 >> 16) & 0xFF;
                    $g2 = ($rgb2 >> 8) & 0xFF;
                    $b2 = $rgb2 & 0xFF;
                    $diff = abs($r - $r2) + abs($g - $g2) + abs($b - $b2);
                    if ($diff > 60) $edgePixels++;
                }
                $sampleCount++;
            }
        }

        $darkRatio = $darkPixels / max(1, $sampleCount);
        $lightRatio = $lightPixels / max(1, $sampleCount);
        $edgeRatio = $edgePixels / max(1, $sampleCount);

        imagedestroy($img);

        // ── Score combinado ──
        // Cara frontal tiene alta ratio de piel en zona superior
        // Cara trasera tiene menos piel, más bordes/texto, y más blanco

        $frontScore = $skinRatio * 100; // 0-100+
        $backScore = ($edgeRatio * 50) + ($lightRatio * 30) - ($skinRatio * 40);

        \Illuminate\Support\Facades\Log::info('[DocumentSideDetector] Análisis', [
            'image' => basename($imagePath),
            'skin_ratio' => round($skinRatio, 3),
            'avg_brightness' => round($avgBrightness, 1),
            'dark_ratio' => round($darkRatio, 3),
            'light_ratio' => round($lightRatio, 3),
            'edge_ratio' => round($edgeRatio, 3),
            'front_score' => round($frontScore, 1),
            'back_score' => round($backScore, 1),
        ]);

        // Umbrales calibrados
        if ($skinRatio > 0.12 && $frontScore > 15) {
            return 'front';
        }
        if ($skinRatio < 0.06 && ($edgeRatio > 0.08 || $lightRatio > 0.25)) {
            return 'back';
        }

        // Fallback: si hay mucha luz/piel probablemente es frontal
        if ($skinRatio > 0.08) {
            return 'front';
        }

        return 'back'; // fallback conservador
    }
}
