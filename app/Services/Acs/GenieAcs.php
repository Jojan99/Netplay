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
    /**
     * Las tareas que encoló esta instancia (con su _id si GenieACS lo
     * devolvió, o su nombre y objeto si la espera se cortó antes). Sirve para
     * limpiar después sólo lo propio y no la cola entera del equipo.
     *
     * @var list<array{id:?string, name:string, objeto:?string, en:string}>
     */
    private array $creadas = [];

    public function __construct(private string $base) {}

    public static function make(): self
    {
        return new self(rtrim((string) config('services.genieacs.nbi', 'http://127.0.0.1:7557'), '/'));
    }

    /**
     * El servidor de esa empresa: el suyo si lo configuró, si no el de la
     * plataforma. Así una empresa con su propio ACS ve sus equipos y nadie ve
     * los de otra.
     */
    public static function deEmpresa(int $companyId): self
    {
        $propio = \App\Models\AcsServidor::where('company_id', $companyId)->where('activo', true)->first();

        return new self(rtrim($propio?->urlNbi() ?: (string) config('services.genieacs.nbi'), '/'));
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

    /**
     * Las tareas que el equipo tiene sin ejecutar.
     *
     * @return list<array<string,mixed>>
     */
    public function tareasPendientes(string $id): array
    {
        $r = Http::timeout(15)->get("{$this->base}/tasks", ['query' => json_encode(['device' => $id])]);

        return $r->successful() ? ($r->json() ?? []) : [];
    }

    /**
     * Saca una tarea de la cola. GenieACS no deja borrarla mientras el equipo
     * está en sesión (contesta 503): entonces devuelve false y la tarea sigue
     * ahí. Un 404 es que ya no estaba (se hizo o la sacó otro).
     */
    public function borrarTarea(string $tareaId): bool
    {
        try {
            $r = Http::timeout(10)->delete("{$this->base}/tasks/" . rawurlencode($tareaId));
        } catch (\Throwable) {
            return false;
        }

        return $r->successful() || $r->status() === 404;
    }

    /** @return list<array{id:?string, name:string, objeto:?string, en:string}> */
    public function creadas(): array
    {
        return $this->creadas;
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
    public function tarea(string $id, array $tarea, int $espera = 45): array
    {
        $firma = ['name' => (string) ($tarea['name'] ?? ''), 'objeto' => self::objetoDe($tarea), 'en' => now()->subSeconds(2)->toIso8601String()];

        // El _id ya trae caracteres codificados (%2D, %C2…): se vuelve a
        // codificar entero para que GenieACS lo reciba tal como lo guarda.
        $url = "{$this->base}/devices/" . rawurlencode($id) . '/tasks?timeout=8000&connection_request';

        // Avisarle al equipo puede tardar (equipos lentos, enlaces con demora).
        // Se espera bastante más que los 8 s de la tarea y, si ni así contesta,
        // la tarea igual quedó encolada en el ACS: se aplica en el próximo
        // reporte del equipo. Antes esto salía como "cURL error 28" en medio del
        // aprovisionamiento y había que reintentar a mano.
        try {
            $r = Http::timeout($espera)->post($url, $tarea);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            $this->creadas[] = ['id' => null] + $firma;

            return ['hecha' => false, 'en_cola' => true, 'estado' => 0, 'instancia' => null, 'id' => null];
        }

        if (!in_array($r->status(), [200, 202], true)) {
            throw new \RuntimeException("El ACS rechazó la tarea ({$r->status()}): " . mb_substr($r->body(), 0, 200));
        }

        $cuerpo = $r->json();
        $this->creadas[] = ['id' => is_array($cuerpo) ? ($cuerpo['_id'] ?? null) : null] + $firma;

        return [
            'hecha'   => $r->status() === 200,
            'en_cola' => $r->status() === 202,
            'estado'  => $r->status(),
            // Al crear un objeto, GenieACS devuelve el número de instancia que
            // le asignó el equipo: hace falta para escribir dentro de ella.
            'instancia' => is_array($cuerpo) ? ($cuerpo['instance'] ?? null) : null,
            'id'        => is_array($cuerpo) ? ($cuerpo['_id'] ?? null) : null,
        ];
    }

    /**
     * Una tarea que sólo vale si se hace en el momento: borrar una conexión,
     * o cambiar la que lleva el TR-069. Si GenieACS no la pudo hacer ya (el
     * equipo no atiende el aviso, la dirección que tiene guardada es vieja,
     * tardó), se saca de la cola en el acto: si quedara ahí, el equipo la
     * haría en su próximo reporte —con los Huawei, hasta 8 horas después— sin
     * nadie mirando. Así le borró la conexión a DOUGLAS_MENDEZ el 18-09.
     *
     * @param  array<string,mixed>  $tarea
     * @return array{hecha:bool, cancelada:bool, incierta:bool, falla:?string, instancia:mixed, id:?string, estado:int}
     *   hecha: el equipo la hizo; cancelada: se sacó de la cola sin hacerse;
     *   incierta: no se pudo sacar (el equipo estaba en sesión): puede haberla
     *   hecho, hay que mirar el equipo.
     */
    public function tareaInmediata(string $id, array $tarea, int $espera = 140): array
    {
        $desde = now()->subSeconds(2);
        $r = $this->tarea($id, $tarea, $espera);
        $base = ['instancia' => $r['instancia'] ?? null, 'id' => $r['id'] ?? null, 'estado' => (int) ($r['estado'] ?? 0)];

        if ($r['hecha'] ?? false) {
            return ['hecha' => true, 'cancelada' => false, 'incierta' => false, 'falla' => null] + $base;
        }

        $falla = ($r['id'] ?? null) ? $this->fallaDeTarea($id, (string) $r['id']) : null;
        $sacada = $this->sacarDeLaCola($id, $tarea, $r['id'] ?? null, $desde);

        if ($falla) {
            // Rechazada: GenieACS la reintentaría en cada reporte.
            return ['hecha' => false, 'cancelada' => $sacada === 'sacada', 'incierta' => $sacada === 'no_se_pudo', 'falla' => $falla] + $base;
        }

        return match ($sacada) {
            'sacada'     => ['hecha' => false, 'cancelada' => true, 'incierta' => false, 'falla' => null] + $base,
            // Ya no estaba y sin falla: el equipo la hizo en la sesión que
            // GenieACS no alcanzó a esperar.
            'no_estaba'  => ['hecha' => true, 'cancelada' => false, 'incierta' => false, 'falla' => null] + $base,
            default      => ['hecha' => false, 'cancelada' => false, 'incierta' => true, 'falla' => null] + $base,
        };
    }

    /**
     * Saca de la cola la tarea (por su _id o, si la espera se cortó antes de
     * tenerlo, por nombre y objeto encolados desde $desde). Mientras el equipo
     * está en sesión GenieACS no deja: se reintenta hasta que la termine.
     *
     * @return 'sacada'|'no_estaba'|'no_se_pudo'
     */
    public function sacarDeLaCola(string $id, array $tarea, ?string $tareaId, ?\DateTimeInterface $desde = null, int $vueltas = 7): string
    {
        for ($i = 0; $i < $vueltas; $i++) {
            if ($i > 0) {
                $this->esperar(10);
            }

            try {
                $pendiente = $this->buscarPendiente($id, $tarea, $tareaId, $desde);
            } catch (\Throwable) {
                continue;
            }

            if ($pendiente === null) {
                return 'no_estaba';
            }

            if ($this->borrarTarea($pendiente)) {
                return 'sacada';
            }
        }

        return 'no_se_pudo';
    }

    /**
     * El _id de una tarea encolada desde $desde que sigue esperando, o null.
     * Para cuando la espera se cortó antes de que GenieACS devolviera el _id.
     */
    public function idEnCola(string $id, array $tarea, \DateTimeInterface $desde): ?string
    {
        try {
            return $this->buscarPendiente($id, $tarea, null, $desde);
        } catch (\Throwable) {
            return null;
        }
    }

    /** ¿La tarea sigue en la cola? null si no se pudo mirar. */
    public function sigueEnCola(string $id, string $tareaId): ?bool
    {
        try {
            return $this->buscarPendiente($id, [], $tareaId, null) !== null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Etiqueta el equipo en el ACS. Es inmediato (no espera al equipo): los
     * presets la leen en el próximo reporte.
     */
    public function etiquetar(string $id, string $etiqueta): bool
    {
        $r = Http::timeout(10)->post("{$this->base}/devices/" . rawurlencode($id) . '/tags/' . rawurlencode($etiqueta));

        return $r->successful();
    }

    public function quitarEtiqueta(string $id, string $etiqueta): bool
    {
        $r = Http::timeout(10)->delete("{$this->base}/devices/" . rawurlencode($id) . '/tags/' . rawurlencode($etiqueta));

        return $r->successful() || $r->status() === 404;
    }

    /** El _id de la tarea si sigue en la cola, o null. */
    private function buscarPendiente(string $id, array $tarea, ?string $tareaId, ?\DateTimeInterface $desde): ?string
    {
        $r = Http::timeout(15)->get("{$this->base}/tasks", ['query' => json_encode(['device' => $id])]);

        if (!$r->successful()) {
            // Sin poder mirar no se puede decir que no está.
            throw new \RuntimeException("El ACS no dejó ver la cola del equipo ({$r->status()}).");
        }

        foreach ((array) $r->json() as $t) {
            if ($tareaId !== null) {
                if ((string) ($t['_id'] ?? '') === $tareaId) {
                    return $tareaId;
                }

                continue;
            }

            $cuando = isset($t['timestamp']) ? strtotime((string) $t['timestamp']) : null;

            if (($t['name'] ?? null) === ($tarea['name'] ?? null)
                && self::objetoDe($t) === self::objetoDe($tarea)
                && (!$desde || !$cuando || $cuando >= $desde->getTimestamp())) {
                return (string) $t['_id'];
            }
        }

        return null;
    }

    /** Lo que toca la tarea, para reconocerla en la cola. */
    private static function objetoDe(array $tarea): ?string
    {
        if (isset($tarea['objectName'])) {
            return (string) $tarea['objectName'];
        }

        if (isset($tarea['parameterValues'])) {
            return implode(',', array_map(fn ($p) => (string) ($p[0] ?? ''), (array) $tarea['parameterValues']));
        }

        if (isset($tarea['parameterNames'])) {
            return implode(',', (array) $tarea['parameterNames']);
        }

        return null;
    }

    /** Entre intentos de sacar una tarea: el equipo termina su sesión. */
    private function esperar(int $segundos): void
    {
        // Las pruebas lo ponen en 0 para no esperar de verdad.
        sleep((int) config('services.genieacs.pausa_cola', $segundos));
    }

    /**
     * Por qué el equipo rechazó una tarea, o null si no hay falla anotada.
     * GenieACS la guarda como "<equipo>:task_<tarea>".
     */
    public function fallaDeTarea(string $id, string $tareaId): ?string
    {
        $r = Http::timeout(10)->get("{$this->base}/faults", ['query' => json_encode(['_id' => "{$id}:task_{$tareaId}"])]);
        $falla = $r->successful() ? ($r->json()[0] ?? null) : null;

        if (!$falla) {
            return null;
        }

        $detalle = $falla['detail'] ?? [];
        $texto = $detalle['faultString'] ?? ($detalle['message'] ?? ($falla['message'] ?? ''));
        $codigo = $detalle['detail']['Fault']['FaultCode'] ?? ($falla['code'] ?? '');

        return trim("{$codigo} {$texto}") ?: 'sin detalle';
    }
}
