<?php

namespace App\Services\Tv;

use App\Models\TvCanal;
use App\Models\TvPanel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Previas de los canales (21-10-2026): una foto de cada canal al aire cada pocos minutos, y la
 * señal en vivo de uno a la vez para mirarlo desde la plataforma.
 *
 * Se mira por una línea propia del panel (la de monitoreo, con todos los paquetes), no por la de
 * un cliente: así no le ocupa pantallas a nadie. Se toma la salida del XUI (ya comprimida, 1 a 3
 * Mb/s) y no la de Astra (unos 10 Mb/s por canal). Cada foto toma un segundo y pesa unos 8 KB.
 */
class PreviasDeCanales
{
    private const USUARIO = 'netplay_monitor';

    /** Fotos a la vez: más satura el enlace con el panel; menos tarda demasiado. */
    private const EN_PARALELO = 4;

    /** Las fotos en paralelo más un par de canales mirándose en vivo. */
    private const CONEXIONES = 6;

    public function __construct(private TvPanel $panel) {}

    /** Mientras alguien tenga abierta la pantalla de canales, las fotos se renuevan cada pocos minutos. */
    public static function alguienMira(int $companyId): void
    {
        rescue(fn () => \Illuminate\Support\Facades\Cache::put("tv:mirando:{$companyId}", now()->timestamp, 600), null, false);
    }

    /**
     * ¿Toca tomar fotos? Si alguien está mirando, sí; si no, una vez por hora, para que al abrir
     * la pantalla no salgan fotos de hace días.
     */
    public static function tocaTomar(int $companyId): bool
    {
        $c = \Illuminate\Support\Facades\Cache::class;
        $mira = rescue(fn () => $c::get("tv:mirando:{$companyId}"), null, false);
        $ultima = (int) rescue(fn () => $c::get("tv:previas:ultima:{$companyId}"), 0, false);

        return $mira || $ultima < now()->subHour()->timestamp;
    }

    public static function carpeta(int $companyId): string
    {
        return storage_path("app/tv-previas/{$companyId}");
    }

    public static function archivo(int $companyId, int $streamId): string
    {
        return self::carpeta($companyId) . "/{$streamId}.jpg";
    }

    /** La línea de monitoreo: si no existe en el panel, se crea con todos los paquetes. */
    public function monitor(): array
    {
        $xui = new PanelXui($this->panel);
        $todos = array_column($xui->bouquets(), 'id');
        $p = $this->panel;

        if ($p->monitor_xui_id) {
            $l = rescue(fn () => $xui->linea((int) $p->monitor_xui_id), [], false);
            if (($l['username'] ?? null) === $p->monitor_usuario) {
                $faltaConexion = (int) ($l['max_connections'] ?? 0) < self::CONEXIONES;
                // Un paquete nuevo en el panel: la línea de monitoreo también tiene que verlo.
                if ($faltaConexion || array_diff($todos, json_decode((string) ($l['bouquet'] ?? '[]'), true) ?: [])) {
                    $xui->editar((int) $p->monitor_xui_id, $p->monitor_usuario, $p->monitor_clave, self::CONEXIONES, $todos, $this->nota());
                }

                return ['usuario' => $p->monitor_usuario, 'clave' => $p->monitor_clave];
            }
        }

        $usuario = self::USUARIO . ($p->monitor_xui_id ? '_' . Str::lower(Str::random(4)) : '');
        $clave = Str::random(16);
        $id = $xui->crear($usuario, $clave, self::CONEXIONES, $todos, $this->nota());
        $p->update(['monitor_xui_id' => $id, 'monitor_usuario' => $usuario, 'monitor_clave' => $clave]);

        return ['usuario' => $usuario, 'clave' => $clave];
    }

    private function nota(): string
    {
        return 'Plataforma Netplay · monitoreo de canales (previas). No es de un cliente: no la borre.';
    }

    /** La señal del canal por la línea de monitoreo, del lado del panel (red interna). */
    public function urlDelCanal(int $streamId, string $formato = 'ts'): string
    {
        $m = $this->monitor();

        return rtrim($this->panel->url, '/') . "/live/{$m['usuario']}/{$m['clave']}/{$streamId}.{$formato}";
    }

    /**
     * Una foto de cada canal al aire. Los caídos conservan la última (se ven en gris en la pantalla).
     *
     * @return array{tomadas:int, fallidas:int}
     */
    public function tomarFotos(): array
    {
        $empresa = (int) $this->panel->company_id;
        @mkdir(self::carpeta($empresa), 0775, true);
        rescue(fn () => \Illuminate\Support\Facades\Cache::put("tv:previas:ultima:{$empresa}", now()->timestamp, 7200), null, false);
        $m = $this->monitor();
        $base = rtrim($this->panel->url, '/') . "/live/{$m['usuario']}/{$m['clave']}";

        $pendientes = TvCanal::where('company_id', $empresa)->where('estado', 'al_aire')->orderBy('previa_en')->get()->all();
        $corriendo = [];
        $tomadas = 0;
        $fallidas = 0;

        while ($pendientes || $corriendo) {
            while ($pendientes && count($corriendo) < self::EN_PARALELO) {
                $c = array_shift($pendientes);
                $destino = self::archivo($empresa, $c->stream_id);
                $temporal = $destino . '.tmp.jpg';
                // Poco análisis (sin esto ffmpeg lee hasta 5 MB por foto) y solo un cuadro completo
                // (los otros salen pixelados): unos 0,6 MB por foto en vez de 1,7.
                $proc = new Process(['ffmpeg', '-y', '-loglevel', 'error', '-rw_timeout', '8000000', '-probesize', '300000', '-analyzeduration', '500000',
                    '-skip_frame', 'nokey', '-i', "{$base}/{$c->stream_id}.ts", '-frames:v', '1', '-vsync', '0', '-vf', 'scale=480:-2', '-q:v', '6', $temporal]);
                $proc->setTimeout(20);
                $proc->start();
                $corriendo[] = [$proc, $c, $temporal, $destino];
            }

            foreach ($corriendo as $i => [$proc, $c, $temporal, $destino]) {
                if ($proc->isRunning()) {
                    try {
                        $proc->checkTimeout();
                    } catch (\Throwable) {
                        // Se cuenta como fallida abajo.
                    }
                    continue;
                }
                unset($corriendo[$i]);
                if ($proc->isSuccessful() && is_file($temporal) && filesize($temporal) > 1000) {
                    rename($temporal, $destino);
                    $c->update(['previa_en' => now()]);
                    $tomadas++;
                } else {
                    @unlink($temporal);
                    $fallidas++;
                }
            }
            usleep(100000);
        }

        return ['tomadas' => $tomadas, 'fallidas' => $fallidas];
    }

    /**
     * La lista HLS del canal en vivo, con cada trozo apuntando a la plataforma (firmado), porque
     * el navegador no llega al panel: está en la red interna y sin https.
     */
    public function listaEnVivo(int $streamId, callable $urlDelTrozo): string
    {
        // El XUI abre una sesión (/auth/…) por cada pedido a /live/: se reusa mientras sirva, para
        // no gastar las conexiones de la línea de monitoreo cada vez que el reproductor refresca.
        $clave = "tv:vivo:{$this->panel->company_id}:{$streamId}";
        $sesion = rescue(fn () => \Illuminate\Support\Facades\Cache::get($clave), null, false);
        $r = $sesion ? rescue(fn () => Http::timeout(8)->get($sesion), null, false) : null;

        if (!$r || !$r->successful() || !str_contains($r->body(), '#EXTM3U')) {
            $r = Http::timeout(12)->get($this->urlDelCanal($streamId, 'm3u8'));
            if (!$r->successful()) {
                throw new \RuntimeException("El panel no entregó el canal ({$r->status()}).");
            }
            $efectiva = (string) $r->effectiveUri();
            rescue(fn () => \Illuminate\Support\Facades\Cache::put($clave, $efectiva, 120), null, false);
        }

        $origen = $r->effectiveUri();
        $raiz = $origen ? $origen->getScheme() . '://' . $origen->getHost() . ($origen->getPort() ? ':' . $origen->getPort() : '') : rtrim($this->panel->url, '/');

        return collect(preg_split('/\r?\n/', $r->body()))
            ->map(function ($linea) use ($raiz, $urlDelTrozo) {
                $linea = trim($linea);
                if ($linea === '' || str_starts_with($linea, '#')) {
                    return $linea;
                }
                $absoluta = preg_match('#^https?://#', $linea) ? $linea : $raiz . '/' . ltrim($linea, '/');

                return $urlDelTrozo($absoluta);
            })->implode("\n");
    }

    /** ¿Ese trozo es del panel de la empresa? (para que el proxy no sirva cualquier dirección). */
    public function esDelPanel(string $url): bool
    {
        $panel = parse_url($this->panel->url, PHP_URL_HOST);

        return $panel && parse_url($url, PHP_URL_HOST) === $panel && str_starts_with((string) parse_url($url, PHP_URL_PATH), '/hls/');
    }

    public static function limpiarViejas(int $companyId, array $streamIds): void
    {
        foreach (glob(self::carpeta($companyId) . '/*.jpg') ?: [] as $f) {
            if (!in_array((int) basename($f, '.jpg'), $streamIds, true)) {
                @unlink($f);
            }
        }
    }
}
