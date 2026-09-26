<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Convierte un PDF en imágenes de página con poppler (pdftocairo).
 *
 * El cliente tiene que leer SU contrato, no una transcripción: la transcripción
 * a HTML sale como una lista de etiquetas sueltas ("NOMBRE / RAZÓN SOCIAL",
 * "INDENTI- Tipo: No.") y no se parece al papel que firma. Aquí se dibuja el PDF
 * de verdad y se muestran las hojas como imágenes.
 *
 * El PNG se reduce a paleta sin difuminado antes de guardarlo: un contrato es
 * texto sobre fondo plano, así que bajan de ~285 KB a ~105 KB por hoja sin que
 * se note (error medio 0,37 sobre 255) y sin los bordes sucios que deja el JPEG
 * alrededor de las letras.
 */
class RasterizadorPdf
{
    /** Ancho en píxeles de las hojas que lee el cliente en el teléfono. */
    public const ANCHO_FIRMA = 1000;

    /** Ancho de la hoja del editor de posiciones del panel (se ve con zoom). */
    public const ANCHO_PANEL = 1600;

    /** Colores de la paleta. 64 sin difuminado deja el texto limpio. */
    private const COLORES = 64;

    /** Tope de páginas que se dibujan, por si alguien sube un PDF enorme. */
    private const MAX_PAGINAS = 40;

    /** Segundos antes de matar el proceso: un PDF roto no puede colgar a nadie. */
    private const TIEMPO_LIMITE = 90;

    /** ¿Está poppler instalado? Sin él se cae al modo de respaldo en HTML. */
    public static function disponible(): bool
    {
        return is_executable('/usr/bin/pdftocairo');
    }

    /**
     * Dibuja el PDF y deja las hojas como {destino}/p1.png, p2.png…
     * Devuelve la cantidad de hojas escritas, o 0 si no se pudo.
     */
    public static function aImagenes(string $pdf, string $destino, int $ancho): int
    {
        if (!self::disponible() || !is_file($pdf)) {
            return 0;
        }

        $temporal = self::carpetaTemporal();

        try {
            // Los argumentos van en array: los arma Symfony y no pasan por la
            // shell, así que un nombre de archivo raro no puede inyectar nada.
            $proceso = new Process([
                '/usr/bin/pdftocairo',
                '-png',
                '-scale-to-x', (string) $ancho,
                '-scale-to-y', '-1',
                '-f', '1',
                '-l', (string) self::MAX_PAGINAS,
                $pdf,
                $temporal . '/h',
            ]);
            $proceso->setTimeout(self::TIEMPO_LIMITE);
            $proceso->run();

            if (!$proceso->isSuccessful()) {
                Log::warning('[RasterizadorPdf] pdftocairo falló: ' . trim($proceso->getErrorOutput()));
                return 0;
            }

            return self::guardarConPaleta($temporal, $destino);
        } catch (ProcessTimedOutException) {
            Log::warning('[RasterizadorPdf] pdftocairo tardó más de ' . self::TIEMPO_LIMITE . ' s: ' . $pdf);
            return 0;
        } catch (ProcessFailedException | \Throwable $e) {
            Log::error('[RasterizadorPdf] Error dibujando el PDF: ' . $e->getMessage());
            return 0;
        } finally {
            self::borrarCarpeta($temporal);
        }
    }

    /**
     * Rutas de las hojas ya dibujadas que estén al día con la firma dada; si la
     * firma no coincide (cambió el PDF, las posiciones o los datos del cliente),
     * devuelve un array vacío para que se vuelvan a dibujar.
     */
    public static function hojasEnCache(string $destino, string $firma): array
    {
        $meta = $destino . '/meta.json';
        if (!is_file($meta)) {
            return [];
        }

        $datos = json_decode((string) file_get_contents($meta), true);
        if (($datos['firma'] ?? null) !== $firma) {
            return [];
        }

        $hojas = [];
        for ($i = 1; $i <= (int) ($datos['hojas'] ?? 0); $i++) {
            $ruta = $destino . '/p' . $i . '.png';
            if (!is_file($ruta)) {
                return [];
            }
            $hojas[$i] = $ruta;
        }

        return $hojas;
    }

    /**
     * Dibuja si hace falta y devuelve las rutas de las hojas. El candado evita
     * que dos pestañas del mismo cliente dibujen el mismo PDF a la vez.
     */
    public static function hojas(string $pdf, string $destino, string $firma, int $ancho): array
    {
        if ($hojas = self::hojasEnCache($destino, $firma)) {
            return $hojas;
        }

        if (!is_dir($destino) && !mkdir($destino, 0750, true) && !is_dir($destino)) {
            return [];
        }

        $candado = fopen($destino . '/.candado', 'c');
        if ($candado === false) {
            return [];
        }

        try {
            flock($candado, LOCK_EX);

            // Otro proceso pudo haberlas dibujado mientras se esperaba.
            if ($hojas = self::hojasEnCache($destino, $firma)) {
                return $hojas;
            }

            self::vaciarHojas($destino);
            $cantidad = self::aImagenes($pdf, $destino, $ancho);
            if ($cantidad === 0) {
                return [];
            }

            file_put_contents($destino . '/meta.json', json_encode([
                'firma'  => $firma,
                'hojas'  => $cantidad,
                'ancho'  => $ancho,
                'fecha'  => now()->toDateTimeString(),
            ]));

            return self::hojasEnCache($destino, $firma);
        } finally {
            flock($candado, LOCK_UN);
            fclose($candado);
        }
    }

    /** Firma de un archivo: si cambia el PDF, cambia la firma y se redibuja. */
    public static function firmaDeArchivo(?string $ruta): string
    {
        return is_file((string) $ruta) ? filemtime($ruta) . '-' . filesize($ruta) : 'sin-archivo';
    }

    // ── Interno ───────────────────────────────────────────────────────────────

    /** Convierte a paleta y guarda como pN.png; devuelve cuántas quedaron. */
    private static function guardarConPaleta(string $temporal, string $destino): int
    {
        $origenes = glob($temporal . '/h-*.png') ?: [];
        natsort($origenes);

        $n = 0;
        foreach ($origenes as $origen) {
            $imagen = @imagecreatefrompng($origen);
            if (!$imagen) {
                continue;
            }

            // Sin difuminado: en un documento los fondos son planos y el
            // difuminado los llena de ruido, que además pesa.
            imagetruecolortopalette($imagen, false, self::COLORES);
            $n++;
            imagepng($imagen, $destino . '/p' . $n . '.png', 9);
            imagedestroy($imagen);
        }

        return $n;
    }

    private static function carpetaTemporal(): string
    {
        $ruta = sys_get_temp_dir() . '/contrato_' . bin2hex(random_bytes(8));
        mkdir($ruta, 0700, true);

        return $ruta;
    }

    private static function vaciarHojas(string $destino): void
    {
        foreach (glob($destino . '/p*.png') ?: [] as $viejo) {
            @unlink($viejo);
        }
        @unlink($destino . '/meta.json');
    }

    private static function borrarCarpeta(string $ruta): void
    {
        if (!is_dir($ruta)) {
            return;
        }

        foreach (glob($ruta . '/*') ?: [] as $archivo) {
            @unlink($archivo);
        }
        @rmdir($ruta);
    }
}
