<?php

namespace App\Services\Acs;

use Illuminate\Support\Facades\Http;

/**
 * Cliente de la API de GenieACS (NBI).
 *
 * La NBI no tiene usuarios ni permisos: quien la alcanza puede leer y
 * reconfigurar todos los equipos. Por eso sólo se habla con ella desde el
 * backend, y lo que llega al panel pasa antes por EquiposDelAcs, que filtra
 * por empresa.
 */
class GenieAcs
{
    public function __construct(private string $base) {}

    public static function make(): self
    {
        return new self(rtrim((string) config('services.genieacs.nbi', 'http://127.0.0.1:7557'), '/'));
    }

    /**
     * @param  array<string,mixed>  $query       filtro de MongoDB
     * @param  list<string>|null    $proyeccion  parámetros a traer
     * @return list<array<string,mixed>>
     */
    public function dispositivos(array $query = [], ?array $proyeccion = null): array
    {
        $params = [];

        if ($query) {
            $params['query'] = json_encode($query);
        }

        if ($proyeccion) {
            $params['projection'] = implode(',', $proyeccion);
        }

        $r = Http::timeout(15)->get("{$this->base}/devices", $params);

        if (!$r->successful()) {
            throw new \RuntimeException("El ACS respondió {$r->status()}");
        }

        return $r->json() ?? [];
    }

    /** @return array<string,mixed>|null */
    public function dispositivo(string $id): ?array
    {
        return $this->dispositivos(['_id' => $id])[0] ?? null;
    }

    /**
     * Encola una tarea y pide al equipo que se conecte para hacerla ya.
     *
     * GenieACS responde 200 si el equipo la hizo en el momento y 202 si quedó
     * en cola: pasa cuando el equipo está detrás de NAT y no se le puede
     * avisar, y entonces se aplica en su próximo reporte.
     *
     * @param  array<string,mixed>  $tarea
     * @return array{hecha:bool, en_cola:bool, estado:int}
     */
    public function tarea(string $id, array $tarea): array
    {
        // El _id ya trae caracteres codificados (%2D, %C2…): se vuelve a
        // codificar entero para que GenieACS lo reciba tal como lo guarda.
        $url = "{$this->base}/devices/" . rawurlencode($id) . '/tasks?timeout=8000&connection_request';

        $r = Http::timeout(20)->post($url, $tarea);

        if (!in_array($r->status(), [200, 202], true)) {
            throw new \RuntimeException("El ACS rechazó la tarea ({$r->status()}): " . mb_substr($r->body(), 0, 200));
        }

        return ['hecha' => $r->status() === 200, 'en_cola' => $r->status() === 202, 'estado' => $r->status()];
    }
}
