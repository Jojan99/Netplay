<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\ConectionRouter;
use App\Models\UserData;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Lo que un cliente tiene configurado en su MikroTik, para verlo y quitarlo.
 *
 * Al eliminar un cliente quedaban en el router su credencial PPPoE, su entrada
 * de ARP y la de "morosos": el cliente seguía navegando o la credencial se
 * podía reutilizar. Todo lo del cliente se reconoce por su documento, que va
 * en el comment de cada entrada, y por su usuario PPPoE.
 *
 * Las listas de velocidad por plan (50MB, 100MB…) no se tocan: son rangos de
 * IP de la red, no entradas del cliente, y no llevan su documento.
 */
class ClienteEnElRouter
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    /**
     * Qué hay del cliente en el router, sin cambiar nada.
     *
     * @return array<string,mixed>
     */
    public function queHay(int $userId): array
    {
        $cliente = $this->cliente($userId);

        if (!$cliente) {
            return ['ok' => false, 'error' => 'Cliente no encontrado.'];
        }

        $router = $this->router($cliente);

        $base = [
            'ok'       => true,
            'error'    => null,
            'tipo'     => ($cliente->connection_type ?? 'static') === 'pppoe' ? 'pppoe' : 'static',
            'router'   => $router?->name ?: $router?->host,
            'pppoe'    => [],
            'arp'      => [],
            'listas'   => [],
            'hay_algo' => false,
        ];

        if (!$router) {
            return array_merge($base, ['ok' => false, 'error' => 'El cliente no tiene un MikroTik asignado.']);
        }

        try {
            $api = $this->conexion->conection($router->token);

            $encontrado = $this->buscar($api, $cliente);
        } catch (\Throwable $e) {
            return array_merge($base, ['ok' => false, 'error' => 'No se pudo leer el MikroTik: ' . $e->getMessage()]);
        }

        $pppoe = array_map(fn ($s) => [
            'usuario'    => $s['name'] ?? '',
            'perfil'     => $s['profile'] ?? null,
            'habilitado' => ($s['disabled'] ?? 'false') !== 'true',
            'sesion'     => $encontrado['sesiones'][$s['name'] ?? ''] ?? null,
        ], $encontrado['secrets']);

        $arp = array_map(fn ($a) => [
            'ip'         => $a['address'] ?? null,
            'interfaz'   => $a['interface'] ?? null,
            'mac'        => $a['mac-address'] ?? null,
            'habilitado' => ($a['disabled'] ?? 'false') !== 'true',
        ], $encontrado['arp']);

        $listas = array_map(fn ($l) => [
            'lista' => $l['list'] ?? null,
            'ip'    => $l['address'] ?? null,
        ], $encontrado['listas']);

        return array_merge($base, [
            'pppoe'    => $pppoe,
            'arp'      => $arp,
            'listas'   => $listas,
            'hay_algo' => $pppoe || $arp || $listas,
        ]);
    }

    /**
     * Ping desde el MikroTik a la IP del cliente: la de su sesión PPPoE o la de su ARP.
     *
     * Antes el ping vivía dentro de un controlador y sólo buscaba en el ARP: a un cliente
     * PPPoE sano le daba «sin conexión». Aquí sirve para los dos y no depende de la sesión
     * del panel, así que lo puede usar un proceso en segundo plano.
     *
     * @return array{ok:bool, error:?string, ip:?string, enviados:int, recibidos:int, perdida:int, promedio_ms:?float, maximo_ms:?float}
     */
    public function ping(int $userId, int $cuantos = 5): array
    {
        $r = ['ok' => false, 'error' => null, 'ip' => null, 'enviados' => 0, 'recibidos' => 0, 'perdida' => 100, 'promedio_ms' => null, 'maximo_ms' => null];
        $cliente = $this->cliente($userId);
        $router  = $cliente ? $this->router($cliente) : null;

        if (!$cliente || !$router) {
            return ['error' => 'El cliente no tiene un MikroTik asignado.'] + $r;
        }

        try {
            $api = $this->conexion->conection($router->token);
            $encontrado = $this->buscar($api, $cliente);

            // «sesiones» trae TODAS las sesiones PPPoE del router, por nombre de usuario. Las de
            // este cliente son las que llevan el nombre de una de SUS credenciales. Tomar «la
            // primera» era hacerle ping a otro cliente y decir que éste respondía.
            $ip = null;
            foreach ($encontrado['secrets'] as $secret) {
                $suya = $encontrado['sesiones'][$secret['name'] ?? ''] ?? null;
                $ip = $ip ?: ($suya['ip'] ?? null);
            }
            // Un cliente PPPoE sin sesión no tiene a dónde hacerle ping: una entrada ARP
            // vieja con su IP anterior contestaría por él (o por quien la tenga ahora)
            // y el ping diría «responde» de un equipo que no está conectado.
            $esPppoe = ($cliente->connection_type ?? 'static') === 'pppoe';

            if (!$esPppoe) {
                foreach ($encontrado['arp'] as $a) {
                    if (($a['disabled'] ?? 'false') !== 'true') {
                        $ip = $ip ?: ($a['address'] ?? null);
                    }
                }
            }

            if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return ['error' => $esPppoe ? 'El equipo no tiene sesión PPPoE abierta: no hay a quién hacerle ping.' : 'El cliente no tiene una IP habilitada en el router.'] + $r;
            }

            $cuantos = max(1, min(10, $cuantos));
            $q = (new \RouterOS\Query('/ping'))->equal('address', $ip)->equal('count', (string) $cuantos)->equal('interval', '0.3');
            $tiempos = [];

            foreach ($api->query($q)->read() as $fila) {
                if (!isset($fila['seq']) && !isset($fila['time']) && !isset($fila['status'])) {
                    continue;
                }
                $r['enviados']++;

                if (isset($fila['time']) && !isset($fila['status'])) {
                    $tiempos[] = self::aMilisegundos((string) $fila['time']);
                }
            }

            $r['enviados']  = max($r['enviados'], $cuantos);
            $r['recibidos'] = count($tiempos);
            $r['perdida']   = (int) round(($r['enviados'] - $r['recibidos']) / $r['enviados'] * 100);
            $r['promedio_ms'] = $tiempos ? round(array_sum($tiempos) / count($tiempos), 1) : null;
            $r['maximo_ms']   = $tiempos ? round(max($tiempos), 1) : null;

            return ['ok' => true, 'ip' => $ip] + $r;
        } catch (\Throwable $e) {
            return ['error' => 'No se pudo hacer ping desde el MikroTik: ' . $e->getMessage()] + $r;
        }
    }

    /** «5ms», «350us», «1ms500us», «1s20ms» → milisegundos. */
    private static function aMilisegundos(string $tiempo): float
    {
        $total = 0.0;

        if (preg_match_all('/([\d.]+)\s*(us|ms|s|m)/', $tiempo, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $valor, $unidad]) {
                $total += (float) $valor * ['us' => 0.001, 'ms' => 1, 's' => 1000, 'm' => 60000][$unidad];
            }

            return $total;
        }

        return (float) $tiempo;
    }

    /**
     * Borra del router todo lo del cliente: credencial PPPoE (cortando la
     * sesión abierta), ARP y entradas de address-list con su documento.
     *
     * @return array{ok:bool, quitado:list<string>, errores:list<string>}
     */
    public function quitar(int $userId): array
    {
        return $this->aplicar($userId, function ($api, array $encontrado) {
            $hecho = [];

            foreach ($encontrado['secrets'] as $s) {
                $this->cortarSesion($api, (string) ($s['name'] ?? ''));
                $this->ejecutar($api, '/ppp/secret/remove', $s['.id']);
                $hecho[] = 'credencial PPPoE ' . ($s['name'] ?? '');
            }

            foreach ($encontrado['arp'] as $a) {
                $this->ejecutar($api, '/ip/arp/remove', $a['.id']);
                $hecho[] = 'ARP ' . ($a['address'] ?? '');
            }

            foreach ($encontrado['listas'] as $l) {
                $this->ejecutar($api, '/ip/firewall/address-list/remove', $l['.id']);
                $hecho[] = ($l['list'] ?? 'lista') . ' ' . ($l['address'] ?? '');
            }

            return $hecho;
        });
    }

    /**
     * Le corta el servicio sin borrar nada: se puede reactivar después.
     *
     * Es lo que hacía el panel al eliminar un cliente, pero a través de la
     * suspensión por falta de pago, que además le mandaba el WhatsApp de
     * "servicio suspendido" a alguien que se acababa de dar de baja.
     *
     * @return array{ok:bool, quitado:list<string>, errores:list<string>}
     */
    public function suspender(int $userId): array
    {
        return $this->aplicar($userId, function ($api, array $encontrado) {
            $hecho = [];

            foreach ($encontrado['secrets'] as $s) {
                if (($s['disabled'] ?? 'false') !== 'true') {
                    $this->ejecutar($api, '/ppp/secret/disable', $s['.id']);
                }
                $this->cortarSesion($api, (string) ($s['name'] ?? ''));
                $hecho[] = 'credencial PPPoE ' . ($s['name'] ?? '') . ' deshabilitada';
            }

            foreach ($encontrado['arp'] as $a) {
                if (($a['disabled'] ?? 'false') !== 'true') {
                    $this->ejecutar($api, '/ip/arp/disable', $a['.id']);
                }
                $hecho[] = 'ARP ' . ($a['address'] ?? '') . ' deshabilitado';
            }

            return $hecho;
        });
    }

    /* ── Interno ──────────────────────────────────────────────────────────── */

    /**
     * @param  callable(mixed, array): list<string>  $accion
     * @return array{ok:bool, quitado:list<string>, errores:list<string>}
     */
    private function aplicar(int $userId, callable $accion): array
    {
        $cliente = $this->cliente($userId);
        $router  = $cliente ? $this->router($cliente) : null;

        if (!$cliente || !$router) {
            return ['ok' => false, 'quitado' => [], 'errores' => ['El cliente no tiene un MikroTik asignado.']];
        }

        try {
            $api   = $this->conexion->conection($router->token);
            $hecho = $accion($api, $this->buscar($api, $cliente));

            Log::info('[Router] Cliente limpiado', ['user_id' => $userId, 'router' => $router->id, 'hecho' => $hecho]);

            return ['ok' => true, 'quitado' => $hecho, 'errores' => []];
        } catch (\Throwable $e) {
            Log::warning('[Router] No se pudo limpiar el cliente', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'quitado' => [], 'errores' => [$e->getMessage()]];
        }
    }

    /**
     * Todo lo del cliente en el router, con su .id para poder tocarlo.
     *
     * @return array{secrets:list<array>, sesiones:array<string,array>, arp:list<array>, listas:list<array>}
     */
    private function buscar($api, UserData $cliente): array
    {
        $identidad = IdentidadEnElRouter::deUsuario((int) $cliente->user_id, $this->companyId);

        if (!$identidad) {
            return ['secrets' => [], 'sesiones' => [], 'arp' => [], 'listas' => []];
        }

        // Las credenciales de las VPN del ISP viven en la misma tabla: sólo
        // cuentan las de PPPoE, y sólo las de este cliente (por su usuario, su
        // documento o el nombre que tenía en la plataforma de la que vino).
        $secrets = IdentidadEnElRouter::suyas(
            array_values(array_filter(
                $this->leer($api, '/ppp/secret/print'),
                fn ($s) => in_array($s['service'] ?? '', ['pppoe', 'any', ''], true)
            )),
            $identidad,
            $this->companyId
        );

        $sesiones = [];

        foreach ($this->leer($api, '/ppp/active/print') as $a) {
            if (($a['service'] ?? '') === 'pppoe') {
                $sesiones[$a['name'] ?? ''] = [
                    'ip'    => $a['address'] ?? null,
                    'desde' => $a['uptime'] ?? null,
                    'mac'   => $a['caller-id'] ?? null,
                ];
            }
        }

        // El ARP y las listas se leen enteros y se filtran aquí: el cliente
        // puede estar con su documento, con el nombre que traía de la otra
        // plataforma o, en última instancia, por su IP fija.
        $arp = IdentidadEnElRouter::suyas($this->leer($api, '/ip/arp/print'), $identidad, $this->companyId);
        $listas = IdentidadEnElRouter::suyas($this->leer($api, '/ip/firewall/address-list/print'), $identidad, $this->companyId);

        return compact('secrets', 'sesiones', 'arp', 'listas');
    }

    private function cliente(int $userId): ?UserData
    {
        return UserData::where('user_id', $userId)
            ->where('company_id', $this->companyId)
            ->first();
    }

    /** El router del cliente o, si no tiene uno, el de la empresa. */
    private function router(UserData $cliente): ?ConectionRouter
    {
        $consulta = ConectionRouter::where('company_id', $this->companyId);

        if ($cliente->router_id) {
            $propio = (clone $consulta)->where('id', $cliente->router_id)->first();

            if ($propio) {
                return $propio;
            }
        }

        return $consulta->orderBy('id')->first();
    }

    /** @return list<array<string,mixed>> */
    private function leer($api, string $comando): array
    {
        return $api->query(new Query($comando))->read();
    }

    /** @return list<array<string,mixed>> */
    private function donde($api, string $comando, string $campo, string $valor): array
    {
        return $api->query((new Query($comando))->where($campo, $valor))->read();
    }

    private function ejecutar($api, string $comando, string $id): void
    {
        $api->query((new Query($comando))->equal('.id', $id))->read();
    }

    private function cortarSesion($api, string $usuario): void
    {
        if ($usuario === '') {
            return;
        }

        foreach ($this->donde($api, '/ppp/active/print', 'name', $usuario) as $sesion) {
            $this->ejecutar($api, '/ppp/active/remove', $sesion['.id']);
        }
    }
}
