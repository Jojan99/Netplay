<?php

namespace App\Services\Importador;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\Importacion;
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

                $como = null;
                $ipEnRouter = null;

                if ($c->connection_type === 'pppoe' && $c->pppoe_user) {
                    if (isset($lectura['pppoe'][mb_strtolower($c->pppoe_user)])) {
                        $como = 'pppoe';
                    }
                } else {
                    $doc = preg_replace('/\D/', '', (string) $c->dni);

                    if ($c->ip && isset($lectura['arp'][$c->ip])) {
                        $como = 'ip';
                    } elseif ($doc !== '' && isset($lectura['porDocumento'][$doc])) {
                        $como = 'documento';
                        $ipEnRouter = $lectura['porDocumento'][$doc];
                    }
                }

                if (!$como) {
                    continue;
                }

                $encontrado[$c->user_id] = [
                    'router_id'   => (int) $router->id,
                    'router'      => $router->name,
                    'como'        => $como,
                    'ip_router'   => $ipEnRouter,
                ];
            }
        }

        foreach ($clientes as $c) {
            $e = $encontrado[$c->user_id] ?? null;

            if (!$e) {
                $resultado['sin_encontrar']++;
                continue;
            }

            $resultado['por_' . ($e['como'] === 'documento' ? 'documento' : ($e['como'] === 'ip' ? 'ip' : 'pppoe'))]++;

            $otraIp = $e['como'] === 'documento' && $e['ip_router'] && $c->ip && $e['ip_router'] !== $c->ip;
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

        $resultado['encontrados'] = $resultado['por_ip'] + $resultado['por_documento'] + $resultado['por_pppoe'];

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
     * @return array{arp: array<string,string>, porDocumento: array<string,string>, pppoe: array<string,bool>}|null
     */
    private function leer(object $router): ?array
    {
        try {
            $api = $this->conexion->conection($router->token);

            $arp = [];
            $porDocumento = [];

            foreach ($api->query(new Query('/ip/arp/print'))->read() as $fila) {
                $ip = trim((string) ($fila['address'] ?? ''));
                $comment = preg_replace('/\D/', '', trim((string) ($fila['comment'] ?? '')));

                if ($ip !== '') {
                    $arp[$ip] = $comment;
                }
                if ($comment !== '' && !isset($porDocumento[$comment])) {
                    $porDocumento[$comment] = $ip;
                }
            }

            $pppoe = [];

            foreach ($api->query(new Query('/ppp/secret/print'))->read() as $fila) {
                $nombre = trim((string) ($fila['name'] ?? ''));

                if ($nombre !== '') {
                    $pppoe[mb_strtolower($nombre)] = true;
                }
            }

            return ['arp' => $arp, 'porDocumento' => $porDocumento, 'pppoe' => $pppoe];
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
            'routers' => [], 'total' => 0, 'encontrados' => 0, 'por_ip' => 0, 'por_documento' => 0, 'por_pppoe' => 0,
            'sin_encontrar' => 0, 'otra_ip' => 0, 'sin_router' => 0, 'para_amarrar' => 0,
            'hallazgos' => [], 'errores' => [$motivo],
        ];
    }
}
