<?php

namespace App\Services\Importador;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\Importacion;
use App\Services\Red\IdentidadEnElRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Compara los clientes recién importados con lo que hay en el MikroTik.
 *
 * Es de una sola dirección y no escribe una sola línea en el router: lee el
 * ARP (IP fija) y las credenciales PPPoE, y cuenta cuántos clientes calzan y
 * cuántos no. Con eso el dueño decide si los "amarra" —que es sólo anotar el
 * router en la ficha, del lado de la plataforma—.
 *
 * Reconoce al cliente con el mismo criterio que el resto del sistema
 * (IdentidadEnElRouter): su documento, el nombre que tenía en la plataforma de
 * origen —que es lo que WispHub deja en el comment— o su IP.
 */
class CotejoConElRouter
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    /**
     * @param  int|null $routerId  Un router puntual; null = todos los de la empresa.
     * @return array<string,mixed>
     */
    public function revisar(Importacion $imp, ?int $routerId = null): array
    {
        $routers = DB::table('conection_routers')
            ->where('company_id', $this->companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->get(['id', 'name', 'token']);

        if ($routers->isEmpty()) {
            return $this->vacio('La empresa no tiene routers configurados.');
        }

        $clientes = $this->clientesDeLaImportacion($imp);

        if (!$clientes) {
            return $this->vacio('Todavía no hay clientes importados para comparar.');
        }

        $resultado = [
            'routers'       => [],
            'total'         => count($clientes),
            'por_ip'        => 0,
            'por_documento' => 0,
            'por_nombre'    => 0,
            'por_pppoe'     => 0,
            'sin_encontrar' => 0,
            'otra_ip'       => 0,
            'sin_router'    => 0,
            'para_amarrar'  => 0,
            'hallazgos'     => [],
            'errores'       => [],
        ];

        $encontrado = [];

        foreach ($routers as $router) {
            $lectura = $this->leer($router);

            if ($lectura === null) {
                $resultado['errores'][] = "No se pudo leer {$router->name}.";
                $resultado['routers'][] = ['id' => (int) $router->id, 'nombre' => $router->name, 'ok' => false, 'arp' => 0, 'pppoe' => 0];
                continue;
            }

            $resultado['routers'][] = [
                'id' => (int) $router->id, 'nombre' => $router->name, 'ok' => true,
                'arp' => count($lectura['arp']), 'pppoe' => count($lectura['pppoe']),
            ];

            foreach ($clientes as $c) {
                if (isset($encontrado[$c->user_id])) {
                    continue;
                }

                $identidad = IdentidadEnElRouter::deUsuario((int) $c->user_id, $this->companyId);

                if (!$identidad) {
                    continue;
                }

                $suyas = IdentidadEnElRouter::suyas(
                    $identidad['connection_type'] === 'pppoe' ? array_merge($lectura['pppoe'], $lectura['arp']) : $lectura['arp'],
                    $identidad,
                    $this->companyId
                );

                if (!$suyas) {
                    continue;
                }

                $encontrado[$c->user_id] = [
                    'router_id' => (int) $router->id,
                    'router'    => $router->name,
                    'como'      => $suyas[0]['via'],
                    'ip_router' => $suyas[0]['address'] ?? null,
                ];
            }
        }

        foreach ($clientes as $c) {
            $e = $encontrado[$c->user_id] ?? null;

            if (!$e) {
                $resultado['sin_encontrar']++;
                continue;
            }

            $clave = 'por_' . (in_array($e['como'], ['documento', 'nombre', 'ip', 'pppoe'], true) ? $e['como'] : 'pppoe');
            $resultado[$clave]++;

            // Si se lo reconoció por la IP, es la misma por definición.
            $otraIp = $e['como'] !== 'ip' && $e['ip_router'] && $c->ip && $e['ip_router'] !== $c->ip;
            $resultado['otra_ip'] += $otraIp ? 1 : 0;

            if (!$c->router_id) {
                $resultado['sin_router']++;
                $resultado['para_amarrar']++;
            }

            if (count($resultado['hallazgos']) < 200) {
                $resultado['hallazgos'][] = [
                    'user_id'    => (int) $c->user_id,
                    'nombre'     => trim("{$c->names} {$c->lastname}"),
                    'dni'        => $c->dni,
                    'como'       => $e['como'],
                    'router'     => $e['router'],
                    'router_id'  => $e['router_id'],
                    'ip_ficha'   => $c->ip,
                    'ip_router'  => $e['ip_router'],
                    'otra_ip'    => $otraIp,
                    'ya_tiene_router' => (bool) $c->router_id,
                ];
            }
        }

        $resultado['encontrados'] = $resultado['por_ip'] + $resultado['por_documento'] + $resultado['por_nombre'] + $resultado['por_pppoe'];
        // Los que sólo se reconocen por el nombre que traían de la otra
        // plataforma: es lo que hay que explicarle al dueño.
        $resultado['identificados_por_nombre'] = $resultado['por_nombre'];

        return $resultado;
    }

    /**
     * Amarra: le anota en la ficha el router donde apareció. Sólo escribe en la
     * plataforma —nunca en el MikroTik— y no le cambia el router al que ya tiene uno.
     *
     * @return array{amarrados:int, revisados:int}
     */
    public function amarrar(Importacion $imp, ?int $routerId = null): array
    {
        $revision = $this->revisar($imp, $routerId);
        $amarrados = 0;

        foreach ($revision['hallazgos'] as $h) {
            if ($h['ya_tiene_router']) {
                continue;
            }

            $amarrados += DB::table('user_data')
                ->where('user_id', $h['user_id'])
                ->where('company_id', $this->companyId)
                ->whereNull('router_id')
                ->update(['router_id' => $h['router_id']]);
        }

        return ['amarrados' => $amarrados, 'revisados' => (int) $revision['total']] + $revision;
    }

    /**
     * Los clientes que creó o actualizó esta importación.
     *
     * @return array<int,object>
     */
    private function clientesDeLaImportacion(Importacion $imp): array
    {
        return DB::table('importacion_filas as f')
            ->join('user_data as ud', 'ud.user_id', '=', 'f.user_id')
            ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('f.importacion_id', $imp->id)
            ->whereIn('f.resultado', ['creado', 'actualizado'])
            ->where('ud.company_id', $this->companyId)
            ->get(['ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname', 'ud.connection_type', 'ud.pppoe_user', 'ud.router_id', 't.ip'])
            ->keyBy('user_id')
            ->all();
    }

    /**
     * Lo que hay en el router, de sólo lectura.
     *
     * @return array{arp: array<int,array<string,mixed>>, pppoe: array<int,array<string,mixed>>}|null
     */
    private function leer(object $router): ?array
    {
        try {
            $api = $this->conexion->conection($router->token);

            $arp = [];

            foreach ($api->query(new Query('/ip/arp/print'))->read() as $fila) {
                if (trim((string) ($fila['address'] ?? '')) !== '') {
                    $arp[] = $fila;
                }
            }

            $pppoe = [];

            foreach ($api->query(new Query('/ppp/secret/print'))->read() as $fila) {
                if (trim((string) ($fila['name'] ?? '')) !== '') {
                    $pppoe[] = $fila;
                }
            }

            return ['arp' => $arp, 'pppoe' => $pppoe];
        } catch (\Throwable $e) {
            Log::warning('[Importador] No se pudo leer el router para cotejar', [
                'router' => $router->id, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @return array<string,mixed> */
    private function vacio(string $motivo): array
    {
        return [
            'routers' => [], 'total' => 0, 'encontrados' => 0, 'por_ip' => 0, 'por_documento' => 0, 'por_nombre' => 0, 'por_pppoe' => 0,
            'identificados_por_nombre' => 0,
            'sin_encontrar' => 0, 'otra_ip' => 0, 'sin_router' => 0, 'para_amarrar' => 0,
            'hallazgos' => [], 'errores' => [$motivo],
        ];
    }
}
