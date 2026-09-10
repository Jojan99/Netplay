<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Trae al sistema la IP que cada cliente tiene de verdad en el MikroTik.
 *
 * En el router cada cliente está identificado por su número de documento, en
 * el comment de la entrada ARP. Ese es el dato que manda: es la IP con la que
 * el cliente efectivamente navega. La plataforma se venía desincronizando —
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

            $porDocumento = [];

            foreach ($api->query($query)->read() as $fila) {
                $documento = trim((string) ($fila['comment'] ?? ''));
                $ip        = trim((string) ($fila['address'] ?? ''));

                // Sin documento en el comment no hay a quién atribuirle la IP:
                // son gateways, equipos de la red o entradas hechas a mano.
                if ($documento === '' || $ip === '') {
                    continue;
                }

                $porDocumento[$documento][] = $ip;
            }

            return $porDocumento;
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

    /** @return array<string,object> documento => cliente con su IP actual */
    private function clientesPorDocumento(): array
    {
        $filas = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('u.company_id', $this->companyId)
            ->whereNotNull('ud.dni')
            ->where('ud.dni', '<>', '')
            ->get(['ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname', 't.ip as ip_actual']);

        $porDocumento = [];

        foreach ($filas as $f) {
            // Documentos repetidos entre clientes: no se puede saber cuál es,
            // así que ninguno se toca. Se marca con null y se reporta.
            $clave = $this->normalizar($f->dni);

            $porDocumento[$clave] = array_key_exists($clave, $porDocumento) ? null : $f;
        }

        return $porDocumento;
    }

    /** El comment del router puede venir con puntos, espacios o guiones. */
    private function normalizar(string $documento): string
    {
        return preg_replace('/\D/', '', $documento) ?: $documento;
    }

    /* ── Comparación ──────────────────────────────────────────────────────── */

    private function procesarArp(array $arp, array $clientes, object $router, bool $simular, array &$resultado): void
    {
        // Si en el propio router una misma IP aparece bajo dos documentos, no
        // se copia a la plataforma: traería el conflicto para adentro en vez
        // de arreglarlo. Se reporta para revisarlo en el MikroTik.
        $duenosPorIp = [];

        foreach ($arp as $documento => $ips) {
            foreach (array_unique($ips) as $ip) {
                $duenosPorIp[$ip][] = (string) $documento;
            }
        }

        $ipsDisputadas = array_keys(array_filter(
            $duenosPorIp,
            fn ($duenos) => count(array_unique($duenos)) > 1
        ));

        foreach ($arp as $documento => $ips) {
            $clave = $this->normalizar((string) $documento);
            $ips   = array_values(array_unique($ips));

            // El mismo documento con dos IPs en el router: no hay forma de
            // saber cuál es la buena, se deja para revisar a mano.
            if (count($ips) > 1) {
                $resultado['ambiguos'][] = [
                    'documento' => $documento,
                    'router'    => $router->name,
                    'ips'       => $ips,
                    'motivo'    => 'El router tiene este documento en más de una entrada ARP.',
                ];
                continue;
            }

            $cliente = $clientes[$clave] ?? false;

            if ($cliente === false) {
                $resultado['desconocidos'][] = [
                    'documento' => $documento,
                    'router'    => $router->name,
                    'ip'        => $ips[0],
                ];
                continue;
            }

            if ($cliente === null) {
                $resultado['ambiguos'][] = [
                    'documento' => $documento,
                    'router'    => $router->name,
                    'ips'       => $ips,
                    'motivo'    => 'Hay más de un cliente con este documento en la plataforma.',
                ];
                continue;
            }

            $ipRouter = $ips[0];

            if (in_array($ipRouter, $ipsDisputadas, true)) {
                $resultado['ambiguos'][] = [
                    'documento' => $documento,
                    'router'    => $router->name,
                    'ips'       => [$ipRouter],
                    'motivo'    => 'En el router esta IP está en más de un documento: ' .
                                   implode(', ', array_unique($duenosPorIp[$ipRouter])),
                ];
                continue;
            }

            if ($cliente->ip_actual === $ipRouter) {
                $resultado['sin_cambio']++;
                continue;
            }

            $cambio = [
                'tipo'      => $cliente->ip_actual ? 'cambio' : 'faltaba',
                'user_id'   => (int) $cliente->user_id,
                'cliente'   => trim($cliente->names . ' ' . $cliente->lastname),
                'documento' => $cliente->dni,
                'router'    => $router->name,
                'antes'     => $cliente->ip_actual,
                'ahora'     => $ipRouter,
            ];

            if (!$simular) {
                $cambio['como'] = AsignacionDeIp::asignar((int) $cliente->user_id, $ipRouter, $this->companyId);
            }

            $resultado['cambios'][] = $cambio;
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
