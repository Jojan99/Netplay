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
        $documento = trim((string) $cliente->dni);
        $usuario   = trim((string) ($cliente->pppoe_user ?? ''));

        // Las credenciales de las VPN del ISP viven en la misma tabla: sólo
        // cuentan las de PPPoE, y sólo las del usuario o el documento del cliente.
        $secrets = array_values(array_filter(
            $this->leer($api, '/ppp/secret/print'),
            fn ($s) => in_array($s['service'] ?? '', ['pppoe', 'any', ''], true)
                && (($usuario !== '' && ($s['name'] ?? '') === $usuario)
                    || ($documento !== '' && trim((string) ($s['comment'] ?? '')) === $documento))
        ));

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

        $arp = $documento === '' ? [] : $this->donde($api, '/ip/arp/print', 'comment', $documento);
        $listas = $documento === '' ? [] : $this->donde($api, '/ip/firewall/address-list/print', 'comment', $documento);

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
