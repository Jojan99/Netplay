<?php

namespace App\Services\Tv;

use App\Models\TvPanel;
use Illuminate\Support\Facades\Http;

/**
 * La API de administración del XUI.one (probada contra la 1.5.5 el 21-10-2026).
 *
 * Lo que se aprendió probando, y que explica la forma de las llamadas:
 *  - edit_line reemplaza la línea entera: sin username y password les pone unos al azar, y sin
 *    max_connections la deja en 1. Por eso editar() manda siempre la línea completa.
 *  - El bouquet va como texto JSON en bouquets_selected ("[10]"); como arreglo no lo toma.
 *  - Los formatos de salida van en access_output[] (1 HLS, 2 MPEG-TS, 3 RTMP).
 *  - stream_status: 0 al aire, 1 caído, 2 arrancando o reintentando.
 */
class PanelXui
{
    /** HLS, MPEG-TS y RTMP: lo mismo que tienen las líneas que ya existían. */
    private const SALIDAS = [1, 2, 3];

    public function __construct(private TvPanel $panel) {}

    /** ¿Responde con esta llave? Devuelve el usuario administrador del panel. */
    public function probar(): string
    {
        return (string) ($this->llamar('user_info')['data']['username'] ?? '');
    }

    /** @return list<array{id:int, nombre:string, canales:int}> */
    public function bouquets(): array
    {
        return collect($this->llamar('get_bouquets'))
            ->filter(fn ($b) => is_array($b) && isset($b['id']))
            ->map(fn ($b) => ['id' => (int) $b['id'], 'nombre' => (string) $b['bouquet_name'], 'canales' => count(json_decode($b['bouquet_channels'] ?: '[]', true) ?: [])])
            ->values()->all();
    }

    /** Todas las líneas del panel, sin las claves de nadie fuera de este servicio. */
    public function lineas(): array
    {
        return $this->llamar('get_lines', ['start' => 0, 'limit' => 10000])['data'] ?? [];
    }

    public function linea(int $id): array
    {
        return $this->llamar('get_line', ['id' => $id])['data'] ?? [];
    }

    /** Los canales en vivo con su estado. */
    public function canales(): array
    {
        return collect($this->llamar('get_streams', ['start' => 0, 'limit' => 5000])['data'] ?? [])
            ->filter(fn ($s) => (string) ($s['type'] ?? '1') === '1')->values()->all();
    }

    /** Crea la línea y devuelve su id en el panel. */
    public function crear(string $usuario, string $clave, int $pantallas, array $bouquets, ?string $nota = null): int
    {
        $r = $this->llamar('create_line', [], $this->cuerpo($usuario, $clave, $pantallas, $bouquets, $nota));
        $id = (int) ($r['data']['id'] ?? 0);

        if (!$id) {
            throw new \RuntimeException('El panel no devolvió la línea creada.');
        }

        // create_line no siempre toma el bouquet: se completa con una edición, que sí lo guarda.
        $this->editar($id, $usuario, $clave, $pantallas, $bouquets, $nota);

        return $id;
    }

    public function editar(int $id, string $usuario, string $clave, int $pantallas, array $bouquets, ?string $nota = null): void
    {
        $this->llamar('edit_line', ['id' => $id], $this->cuerpo($usuario, $clave, $pantallas, $bouquets, $nota));
    }

    public function activar(int $id): void { $this->llamar('enable_line', ['id' => $id]); }

    public function desactivar(int $id): void { $this->llamar('disable_line', ['id' => $id]); }

    public function borrar(int $id): void { $this->llamar('delete_line', ['id' => $id]); }

    private function cuerpo(string $usuario, string $clave, int $pantallas, array $bouquets, ?string $nota): array
    {
        $cuerpo = [
            'username' => $usuario, 'password' => $clave, 'max_connections' => max(1, $pantallas),
            'bouquets_selected' => json_encode(array_values(array_map('intval', $bouquets))),
            'access_output' => self::SALIDAS,
        ];

        return $nota !== null ? $cuerpo + ['admin_notes' => $nota] : $cuerpo;
    }

    /**
     * Una llamada a la API. Con cuerpo va por POST (crear y editar); si no, por GET.
     * Las respuestas de error del panel llegan con HTTP 200 y STATUS_FAILURE.
     */
    private function llamar(string $accion, array $query = [], ?array $cuerpo = null): array
    {
        $url = rtrim($this->panel->url, '/') . '/' . trim($this->panel->codigo_api, '/') . '/';
        $query = ['api_key' => $this->panel->api_key, 'action' => $accion] + $query;
        $http = Http::timeout(25)->connectTimeout(6)->acceptJson();

        $r = $cuerpo === null ? $http->get($url, $query) : $http->asForm()->post($url . '?' . http_build_query($query), $cuerpo);

        if (!$r->successful()) {
            throw new \RuntimeException("El panel de TV respondió {$r->status()}.");
        }

        $json = $r->json();
        if (!is_array($json)) {
            throw new \RuntimeException('El panel de TV no respondió como se esperaba.');
        }
        if (($json['status'] ?? null) === 'STATUS_FAILURE') {
            throw new \RuntimeException('El panel de TV no aceptó la operación: ' . ($json['error'] ?? 'sin detalle') . '.');
        }

        return $json;
    }
}
