<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\ConectionRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * La plataforma contra el MikroTik: quién figura de una forma y está de otra.
 *
 * La sincronización diaria de las 6 (arp:sync) corrige sola los ARP, pero mira
 * solo el router principal, no revisa los PPPoE y lo que no encuentra queda en
 * un log que nadie lee. Así un cliente podía estar ACTIVE en la plataforma y
 * cortado en el router: paga y no tiene internet, y nadie se entera hasta que
 * escribe. O al revés: suspendido en la plataforma y navegando.
 *
 * Esto solo lee: muestra los descuadres para que alguien los mire y los corrija.
 *
 *  - activo_cortado:        activo en la plataforma, cortado en el router (ARP o PPPoE deshabilitado).
 *  - suspendido_navegando:  suspendido en la plataforma, habilitado en el router.
 *  - no_esta:               cliente vigente que no aparece en su router.
 */
class PlataformaContraRouter
{
    public function __construct(private ConectionRouterManagerInterface $conexion) {}

    /**
     * @return array{revisados:int, ok:int, routers:list<array>, problemas:list<array>}
     */
    public function revisar(int $companyId): array
    {
        $routers = ConectionRouter::where('company_id', $companyId)->orderBy('id')->get();
        $resultado = ['revisados' => 0, 'ok' => 0, 'routers' => [], 'problemas' => []];

        if ($routers->isEmpty()) {
            return $resultado;
        }

        $principal = $routers->first()->id;
        $porRouter = $this->clientes($companyId)->groupBy(fn ($c) => $routers->contains('id', $c->router_id) ? (int) $c->router_id : (int) $principal);

        foreach ($routers as $router) {
            $clientes = $porRouter->get((int) $router->id, collect());
            $nombre = $router->name ?: $router->host;

            if ($clientes->isEmpty()) {
                continue;
            }

            try {
                $api = $this->conexion->conection($router->token);
                $arp = $api->query(new Query('/ip/arp/print'))->read();
                $secrets = array_values(array_filter(
                    $api->query(new Query('/ppp/secret/print'))->read(),
                    fn ($s) => in_array($s['service'] ?? '', ['pppoe', 'any', ''], true)
                ));
                $sesiones = [];
                foreach ($api->query(new Query('/ppp/active/print'))->read() as $a) {
                    $sesiones[$a['name'] ?? ''] = true;
                }
            } catch (\Throwable $e) {
                Log::warning('[Red] No se pudo leer el router para comparar', ['router' => $router->id, 'error' => $e->getMessage()]);
                $resultado['routers'][] = ['nombre' => $nombre, 'clientes' => $clientes->count(), 'error' => $e->getMessage()];
                continue;
            }

            $resultado['routers'][] = ['nombre' => $nombre, 'clientes' => $clientes->count(), 'error' => null];

            foreach ($clientes as $c) {
                $resultado['revisados']++;
                $identidad = IdentidadEnElRouter::deUsuario((int) $c->user_id, $companyId);
                $esPppoe = $c->connection_type === 'pppoe';
                $entradas = $identidad ? IdentidadEnElRouter::suyas($esPppoe ? $secrets : $arp, $identidad, $companyId) : [];

                $suspendido = (int) $c->status_internet_id === 2;
                $base = [
                    'user_id'    => (int) $c->user_id,
                    'nombre'     => trim("{$c->names} {$c->lastname}"),
                    'dni'        => $c->dni,
                    'tipo'       => $esPppoe ? 'pppoe' : 'static',
                    'router'     => $nombre,
                    'plataforma' => $suspendido ? 'suspendido' : 'activo',
                    'no_reactivar' => (bool) $c->no_reactivar_auto,
                ];

                if (!$entradas) {
                    // Un suspendido que no está no navega: no es un problema que haya que mirar.
                    if (!$suspendido) {
                        $resultado['problemas'][] = $base + [
                            'problema' => 'no_esta',
                            'router_estado' => 'no está',
                            'donde' => $esPppoe ? ($c->pppoe_user ?: 'sin usuario PPPoE') : ($c->ip ?: 'sin IP'),
                        ];
                    } else {
                        $resultado['ok']++;
                    }
                    continue;
                }

                // Habilitado si alguna de sus entradas lo está: con una sola encendida, navega.
                $habilitado = collect($entradas)->contains(fn ($e) => ($e['disabled'] ?? 'false') !== 'true');
                $donde = $esPppoe ? ($entradas[0]['name'] ?? '') : implode(', ', array_unique(array_column($entradas, 'address')));
                $conectado = $esPppoe ? isset($sesiones[$entradas[0]['name'] ?? '']) : null;

                if ($habilitado === !$suspendido) {
                    $resultado['ok']++;
                    continue;
                }

                $resultado['problemas'][] = $base + [
                    'problema'      => $suspendido ? 'suspendido_navegando' : 'activo_cortado',
                    'router_estado' => $habilitado ? 'habilitado' : 'cortado',
                    'donde'         => $donde,
                    'conectado'     => $conectado,
                ];
            }
        }

        // Lo más grave arriba: el que paga y no tiene internet.
        $orden = ['activo_cortado' => 0, 'suspendido_navegando' => 1, 'no_esta' => 2];
        usort($resultado['problemas'], fn ($a, $b) => $orden[$a['problema']] <=> $orden[$b['problema']] ?: strcmp($a['nombre'], $b['nombre']));

        return $resultado;
    }

    /** Clientes vigentes de la empresa (sin el personal). */
    private function clientes(int $companyId)
    {
        return DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('u.company_id', $companyId)
            ->where('ud.company_id', $companyId)
            ->where('ud.active', 1)
            ->whereNotIn('u.profile_id', fn ($q) => $q->select('id')->from('profiles')
                ->where('company_id', $companyId)->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']))
            ->get(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.router_id', 'ud.connection_type', 'ud.pppoe_user',
                'ud.status_internet_id', 'ud.no_reactivar_auto', 't.ip']);
    }
}
