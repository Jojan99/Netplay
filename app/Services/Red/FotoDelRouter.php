<?php

namespace App\Services\Red;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * La foto del equipo que corresponde al modelo del router.
 *
 * MikroTik no publica una API de imágenes: en su web cada foto tiene un
 * identificador interno que no se deduce del board-name. Lo que sí se puede es
 * leer una vez el listado de productos, quedarse con el par nombre → foto, y a
 * partir de ahí resolver cualquier referencia sin tener que cargarlas a mano.
 *
 * La imagen se guarda en el propio servidor la primera vez. Así la pantalla no
 * depende de que el sitio de MikroTik esté disponible ni le pega en cada
 * visita, y si algún día cambian el sitio se sigue viendo lo ya guardado.
 */
class FotoDelRouter
{
    private const CDN     = 'https://cdn.mikrotik.com/web-assets/rb_images/';
    private const INDICE  = 'routers/indice.json';
    private const CARPETA = 'routers';

    /** Un mes: el catálogo de MikroTik no cambia seguido. */
    private const VIGENCIA_INDICE = 30 * 24 * 3600;

    /**
     * @return array{url:?string, modelo:?string}  url servida por la plataforma
     */
    public static function resolver(?string $boardName): array
    {
        $board = trim((string) $boardName);

        if ($board === '') {
            return ['url' => null, 'modelo' => null];
        }

        $archivo = self::CARPETA . '/' . self::slug($board) . '.webp';

        if (Storage::disk('public')->exists($archivo)) {
            return ['url' => url('/storage/' . $archivo), 'modelo' => $board];
        }

        $indice = self::indice();

        if (!$indice) {
            return ['url' => null, 'modelo' => null];
        }

        $slug = self::buscar($indice, $board);

        if (!$slug) {
            return ['url' => null, 'modelo' => null];
        }

        $id = self::fotoDe($slug);

        if (!$id) {
            return ['url' => null, 'modelo' => null];
        }

        if (!self::descargar($id, $archivo)) {
            return ['url' => null, 'modelo' => null];
        }

        return ['url' => url('/storage/' . $archivo), 'modelo' => $board];
    }

    /** Sólo letras, números y guiones: es nombre de archivo. */
    private static function slug(string $texto): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $texto)) ?: 'router';
    }

    /**
     * Slugs de producto publicados en el sitemap del propio sitio.
     *
     * Se usa el sitemap y no los listados por grupo porque incluye también los
     * modelos descontinuados —que es justo lo que suele haber instalado— y
     * porque así sólo se descarga la página del modelo que hace falta.
     *
     * @return array<int,string>
     */
    private static function indice(): array
    {
        if (Storage::disk('public')->exists(self::INDICE)) {
            $guardado = json_decode((string) Storage::disk('public')->get(self::INDICE), true);

            if (is_array($guardado['productos'] ?? null)
                && (time() - (int) ($guardado['fecha'] ?? 0)) < self::VIGENCIA_INDICE) {
                return $guardado['productos'];
            }
        }

        $productos = self::leerSitemap();

        if (!$productos) {
            $guardado = Storage::disk('public')->exists(self::INDICE)
                ? json_decode((string) Storage::disk('public')->get(self::INDICE), true)
                : null;

            return is_array($guardado['productos'] ?? null) ? $guardado['productos'] : [];
        }

        Storage::disk('public')->put(self::INDICE, json_encode([
            'fecha'     => time(),
            'productos' => $productos,
        ]));

        return $productos;
    }

    /** @return array<int,string> */
    private static function leerSitemap(): array
    {
        try {
            $r = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'NetplayISP/1.0 (panel de gestion)'])
                ->get('https://mikrotik.com/sitemap.xml');

            if (!$r->successful()) {
                return [];
            }

            preg_match_all('#/product/([^<\s]+)#', $r->body(), $m);

            return array_values(array_unique($m[1]));
        } catch (\Throwable $e) {
            Log::warning('[Foto router] No se pudo leer el sitemap', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** Para comparar: sin mayúsculas ni separadores. */
    private static function normalizar(string $texto): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $texto));
    }

    /**
     * Qué slug del catálogo corresponde a este board-name.
     *
     * El board-name casi nunca es idéntico al slug: la URL suele llevar un
     * número al final (CCR1036-12G-4S-149) y las variantes usan sufijos de
     * letras (-EM, -RM, -IN). Cuando lo que sobra son sólo dígitos es el
     * identificador de la página y sigue siendo el mismo equipo; si sobran
     * letras es otro modelo, así que se prefiere el más parecido.
     *
     * @param  array<int,string>  $slugs
     */
    private static function buscar(array $slugs, string $board): ?string
    {
        $buscado = self::normalizar($board);

        if ($buscado === '') {
            return null;
        }

        $soloDigitos = null;
        $otros       = [];

        foreach ($slugs as $slug) {
            $normal = self::normalizar($slug);

            if ($normal === $buscado) {
                return $slug;
            }

            if (str_starts_with($normal, $buscado)) {
                $resto = substr($normal, strlen($buscado));

                if (ctype_digit($resto)) {
                    $soloDigitos ??= $slug;
                    continue;
                }
            }

            if (str_starts_with($normal, $buscado) || str_starts_with($buscado, $normal)) {
                $otros[$slug] = strlen($normal);
            }
        }

        if ($soloDigitos) {
            return $soloDigitos;
        }

        if (!$otros) {
            return null;
        }

        arsort($otros);

        return array_key_first($otros);
    }

    /** El identificador de la foto grande, leído de la página del producto. */
    private static function fotoDe(string $slug): ?string
    {
        try {
            $r = Http::timeout(25)
                ->withHeaders(['User-Agent' => 'NetplayISP/1.0 (panel de gestion)'])
                ->get('https://mikrotik.com/product/' . $slug);

            if (!$r->successful()) {
                return null;
            }

            return preg_match('#rb_images/(\d+)_(?:lg|tm|ts)#', $r->body(), $m) ? $m[1] : null;
        } catch (\Throwable $e) {
            Log::warning('[Foto router] No se pudo leer la página del producto', [
                'slug' => $slug, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private static function descargar(string $id, string $archivo): bool
    {
        try {
            $r = Http::timeout(25)->get(self::CDN . $id . '_lg.webp');

            if (!$r->successful() || $r->body() === '') {
                return false;
            }

            Storage::disk('public')->put($archivo, $r->body());

            return true;
        } catch (\Throwable $e) {
            Log::warning('[Foto router] No se pudo bajar la imagen', [
                'id' => $id, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
