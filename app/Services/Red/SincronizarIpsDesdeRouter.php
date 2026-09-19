<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Trae al sistema la IP que cada cliente tiene de verdad en el MikroTik.
 *
 * A cada cliente se lo reconoce con el criterio común (IdentidadEnElRouter):
 * el documento en el comment, el nombre que traía de la plataforma de la que
 * se importó, o su IP. La IP del router es la que manda: es con la que el
 * cliente efectivamente navega. La plataforma se venía desincronizando —
 * migraciones que no llegaban a guardarse, IPs cambiadas a mano en el router,
 * registros de asignación compartidos entre clientes — y eso rompía todo lo
 * que se apoya en la IP: el diagnóstico, la suspensión, la lista de IPs libres.
 *
 * Es de una sola dirección, router → plataforma. Nunca escribe en el MikroTik:
 * si algo no cuadra, se reporta y se deja como está.
 */
class SincronizarIpsDesdeRouter
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    /**
     * @param  bool      $simular   No escribe nada, sólo informa qué haría.
     * @param  int|null  $routerId  Un router puntual; null = todos los de la empresa.
     * @return array<string,mixed>
     */
    public function ejecutar(bool $simular = false, ?int $routerId = null): array
    {
        $routers = $this->routersDeLaEmpresa($routerId);

        if (!$routers) {
            return $this->vacio('La empresa no tiene routers configurados.');
        }

        $clientes = $this->clientesPorDocumento();

        $resultado = [
            'simulacion'    => $simular,
            'routers'       => [],
            'cambios'       => [],
            'sin_cambio'    => 0,
            'desconocidos'  => [],
            'ambiguos'      => [],
            'errores'       => [],
        ];

        foreach ($routers as $router) {
            $arp = $this->leerArp($router);

            if ($arp === null) {
                $resultado['errores'][] = "No se pudo leer el ARP de {$router->name}.";
                $resultado['routers'][] = ['router' => $router->name, 'entradas' => 0, 'ok' => false];
                continue;
            }

            $resultado['routers'][] = ['router' => $router->name, 'entradas' => count($arp), 'ok' => true];

            $this->procesarArp($arp, $clientes, $router, $simular, $resultado);
        }

        if (!$simular && $resultado['cambios']) {
            // Todo junto y en una transacción. De a un cliente por vez eran
            // unas 3.000 consultas: tardaba minutos, la respuesta HTTP se
            // cortaba antes de terminar y la pantalla decía que había fallado
            // cuando en realidad estaba aplicándose. Y si se cortaba de
            // verdad, quedaba a medias.
            DB::transaction(fn () => $this->aplicarEnLote($resultado['cambios']));

            Log::info('[Sync IPs] Sincronización desde el router', [
                'company_id' => $this->companyId,
                'cambios'    => count($resultado['cambios']),
            ]);
        }

        return $resultado;
    }

    /* ── Router ───────────────────────────────────────────────────────────── */

    private function routersDeLaEmpresa(?int $routerId)
    {
        return DB::table('conection_routers')
            ->where('company_id', $this->companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->get(['id', 'name', 'token']);
    }

    /**
     * Entradas ARP con documento, agrupadas por documento.
     *
     * @return array<string,array<int,string>>|null  documento => IPs; null si el router no respondió
     */
    private function leerArp(object $router): ?array
    {
        try {
            $api = $this->conexion->conection($router->token);

            $query = new Query('/ip/arp/print');
            $query->add('=.proplist=address,comment,disabled');

            $entradas = [];

            foreach ($api->query($query)->read() as $fila) {
                $ip = trim((string) ($fila['address'] ?? ''));

                if ($ip === '') {
                    continue;
                }

                $entradas[] = ['address' => $ip, 'comment' => trim((string) ($fila['comment'] ?? ''))];
            }

            return $entradas;
        } catch (\Throwable $e) {
            Log::warning('[Sync IPs] No se pudo leer el ARP', [
                'company_id' => $this->companyId,
                'router'     => $router->name,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* ── Clientes ─────────────────────────────────────────────────────────── */

    /** @return array<int,object> user_id => cliente con su IP actual */
    private function clientesPorDocumento(): array
    {
        $filas = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('u.company_id', $this->companyId)
            // Un retirado no debe recibir la IP del router: ya no es cliente.
            ->where('ud.active', 1)
            ->get(['ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname', 't.ip as ip_actual']);

        $porCliente = [];

        foreach ($filas as $f) {
            $porCliente[(int) $f->user_id] = $f;
        }

        return $porCliente;
    }

    /* ── Comparación ──────────────────────────────────────────────────────── */

    private function procesarArp(array $arp, array $clientes, object $router, bool $simular, array &$resultado): void
    {
        // Cada entrada se le atribuye a un cliente con el criterio común:
        // documento en el comment, nombre que traía de la plataforma anterior
        // o —en última instancia— la IP que ya tiene en su ficha.
        $porCliente = [];

        foreach ($arp as $entrada) {
            $ip = $entrada['address'];
            $etiqueta = $entrada['comment'] !== '' ? $entrada['comment'] : $ip;
            $quien = IdentidadEnElRouter::resolver($this->companyId, $entrada);

            if ($quien['estado'] === 'ambiguo') {
                $resultado['ambiguos'][] = [
                    'documento' => $etiqueta,
                    'router'    => $router->name,
                    'ips'       => [$ip],
                    'motivo'    => 'Hay más de un cliente que responde a ese dato en la plataforma.',
                ];
                continue;
            }

            if ($quien['estado'] !== 'cliente' || !$quien['identidad']) {
                // Sin dueño: gateways, equipos de la red o entradas a mano.
                if ($entrada['comment'] !== '') {
                    $resultado['desconocidos'][] = [
                        'documento' => $entrada['comment'],
                        'router'    => $router->name,
                        'ip'        => $ip,
                    ];
                }
                continue;
            }

            $identidad = $quien['identidad'];

            // Un retirado no debe recibir la IP del router: ya no es cliente.
            if (!$identidad['activo']) {
                continue;
            }

            $porCliente[$identidad['user_id']]['identidad'] = $identidad;
            $porCliente[$identidad['user_id']]['via'] = $quien['via'];
            $porCliente[$identidad['user_id']]['entradas'][] = $entrada;
        }

        // Si en el propio router una misma IP está en dos entradas de clientes
        // distintos, no se copia a la plataforma: traería el conflicto para
        // adentro en vez de arreglarlo.
        $duenosPorIp = [];

        foreach ($porCliente as $userId => $datos) {
            foreach ($datos['entradas'] as $e) {
                $duenosPorIp[$e['address']][$userId] = true;
            }
        }

        foreach ($porCliente as $userId => $datos) {
            $identidad = $datos['identidad'];
            $ips = array_values(array_unique(array_column($datos['entradas'], 'address')));
            $etiqueta = $identidad['dni'] ?: (string) $userId;

            // El mismo cliente con dos IPs en el router: no hay forma de saber
            // cuál es la buena, se deja para revisar a mano.
            if (count($ips) > 1) {
                $resultado['ambiguos'][] = [
                    'documento' => $etiqueta,
                    'router'    => $router->name,
                    'ips'       => $ips,
                    'motivo'    => 'El router tiene a este cliente en más de una entrada ARP.',
                ];
                continue;
            }

            $ipRouter = $ips[0];

            if (count($duenosPorIp[$ipRouter] ?? []) > 1) {
                $resultado['ambiguos'][] = [
                    'documento' => $etiqueta,
                    'router'    => $router->name,
                    'ips'       => [$ipRouter],
                    'motivo'    => 'En el router esta IP está en más de un cliente.',
                ];
                continue;
            }

            $cliente = $clientes[$userId] ?? null;

            if (!$cliente) {
                continue;
            }

            if ($cliente->ip_actual === $ipRouter) {
                $resultado['sin_cambio']++;
                continue;
            }

            $resultado['cambios'][] = [
                'tipo'      => $cliente->ip_actual ? 'cambio' : 'faltaba',
                'user_id'   => (int) $cliente->user_id,
                'cliente'   => trim($cliente->names . ' ' . $cliente->lastname),
                'documento' => $cliente->dni,
                'router'    => $router->name,
                'antes'     => $cliente->ip_actual,
                'ahora'     => $ipRouter,
                'via'       => $datos['via'],
            ];
        }
    }

    /* ── Escritura ────────────────────────────────────────────────────────── */

    /**
     * Guarda todas las IPs de una vez.
     *
     * Mantiene la misma regla que la asignación de a uno: se escribe sobre el
     * registro del cliente sólo si es suyo y de nadie más; si lo comparte con
     * otros, o si apunta a un registro que ya no existe, se le arma uno propio.
     *
     * @param  array<int,array<string,mixed>>  $cambios
     */
    private function aplicarEnLote(array $cambios): void
    {
        $ipPorUsuario = [];

        foreach ($cambios as $c) {
            $ipPorUsuario[(int) $c['user_id']] = $c['ahora'];
        }

        $usuarios = array_keys($ipPorUsuario);

        $asignaciones = DB::table('user_data')
            ->whereIn('user_id', $usuarios)
            ->pluck('ip_assignment_id', 'user_id');

        $fichasIds = array_values(array_filter($asignaciones->all()));

        // Cuántos clientes referencian cada registro. Se cuenta sobre toda la
        // tabla, no sólo sobre los que se están tocando: el que lo comparte
        // puede ser un cliente que no entró en esta pasada.
        $compartidas = $fichasIds
            ? DB::table('user_data')->whereIn('ip_assignment_id', $fichasIds)
                ->groupBy('ip_assignment_id')
                ->select('ip_assignment_id', DB::raw('COUNT(*) as n'))
                ->pluck('n', 'ip_assignment_id')
            : collect();

        $fichas = $fichasIds
            ? DB::table('tabla_ips')->whereIn('id', $fichasIds)->get()->keyBy('id')
            : collect();

        $actualizar = [];   // id del registro => ip nueva
        $crear      = [];   // user_id => datos del registro nuevo

        foreach ($ipPorUsuario as $userId => $ip) {
            $fichaId = $asignaciones[$userId] ?? null;
            $ficha   = $fichaId ? ($fichas[$fichaId] ?? null) : null;

            if ($ficha && (int) ($compartidas[$fichaId] ?? 0) === 1) {
                $actualizar[$fichaId] = $ip;
                continue;
            }

            $crear[$userId] = [
                'company_id' => $this->companyId,
                'id_user'    => $userId,
                'ip'         => $ip,
                'name'       => $ficha->name ?? '',
                'mac'        => $ficha->mac ?? null,
                'active'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->actualizarIps($actualizar);
        $this->crearFichas($crear);
    }

    /** @param  array<int,string>  $porFicha  id => ip */
    private function actualizarIps(array $porFicha): void
    {
        foreach (array_chunk($porFicha, 500, true) as $lote) {
            $casos = '';
            $valores = [];

            foreach ($lote as $id => $ip) {
                $casos .= ' WHEN ? THEN ?';
                $valores[] = $id;
                $valores[] = $ip;
            }

            $ids = implode(',', array_map('intval', array_keys($lote)));

            DB::update(
                "UPDATE tabla_ips SET ip = CASE id{$casos} END, updated_at = ? WHERE id IN ({$ids})",
                [...$valores, now()]
            );
        }
    }

    /** @param  array<int,array<string,mixed>>  $porUsuario  user_id => fila */
    private function crearFichas(array $porUsuario): void
    {
        if (!$porUsuario) {
            return;
        }

        // El insert masivo no devuelve los ids, así que se anota hasta dónde
        // llegaba la tabla y después se leen los que aparecieron.
        $ultimoId = (int) DB::table('tabla_ips')->max('id');

        foreach (array_chunk(array_values($porUsuario), 200) as $lote) {
            DB::table('tabla_ips')->insert($lote);
        }

        $nuevas = DB::table('tabla_ips')
            ->where('id', '>', $ultimoId)
            ->where('company_id', $this->companyId)
            ->pluck('id', 'id_user');

        $casos = '';
        $valores = [];
        $usuarios = [];

        foreach ($porUsuario as $userId => $_) {
            if (!isset($nuevas[$userId])) {
                continue;
            }

            $casos .= ' WHEN ? THEN ?';
            $valores[] = $userId;
            $valores[] = $nuevas[$userId];
            $usuarios[] = (int) $userId;
        }

        if (!$usuarios) {
            return;
        }

        foreach (array_chunk($usuarios, 500) as $lote) {
            $enLote = array_flip($lote);
            $casosLote = '';
            $valoresLote = [];

            foreach ($porUsuario as $userId => $_) {
                if (!isset($enLote[$userId], $nuevas[$userId])) {
                    continue;
                }

                $casosLote .= ' WHEN ? THEN ?';
                $valoresLote[] = $userId;
                $valoresLote[] = $nuevas[$userId];
            }

            $ids = implode(',', $lote);

            DB::update(
                "UPDATE user_data SET ip_assignment_id = CASE user_id{$casosLote} END WHERE user_id IN ({$ids})",
                $valoresLote
            );
        }
    }

    private function vacio(string $motivo): array
    {
        return [
            'simulacion'   => true,
            'routers'      => [],
            'cambios'      => [],
            'sin_cambio'   => 0,
            'desconocidos' => [],
            'ambiguos'     => [],
            'errores'      => [$motivo],
        ];
    }
}
