<?php

namespace App\Services\Vpn;

use App\Models\ConectionRouter;
use App\Models\VpnTunel;
use Illuminate\Support\Facades\Log;

/**
 * Las redes de clientes que tiene que llevar el túnel VPN.
 *
 * Cuando se crea un rango de IP nuevo en el MikroTik (un pool PPPoE para otra
 * VLAN, por ejemplo), sus clientes quedan fuera del túnel: sus equipos le
 * reportan al TR-069 por internet, pero el servidor no les llega de vuelta y
 * los cambios quedan en cola. Pasó con 10.23.0.0/22 (VLAN 100). Por eso la red
 * se agrega al túnel en el mismo momento en que se crea el rango.
 */
class RedesEnElTunel
{
    /**
     * Agrega al túnel del router las redes que cubren esos rangos, si faltan.
     *
     * @param  string  $rangos  como los guarda el MikroTik: "10.23.0.2-10.23.3.254", "10.24.0.0/22", varios separados por coma
     * @return array{ok:bool, detalle:string, agregadas:list<string>}
     */
    public static function asegurar(ConectionRouter $router, string $rangos): array
    {
        $redes = self::redesDe($rangos);

        if (!$redes) {
            return ['ok' => true, 'detalle' => '', 'agregadas' => []];
        }

        $tunel = self::tunelDe($router);

        if (!$tunel) {
            return ['ok' => true, 'detalle' => 'Sin túnel VPN: el servidor llega por internet.', 'agregadas' => []];
        }

        $actuales = (array) ($tunel->redes_remotas ?? []);
        $faltan = array_values(array_filter($redes, fn ($red) => !self::cubierta($red, $actuales)));

        if (!$faltan) {
            return ['ok' => true, 'detalle' => 'La red ya estaba en el túnel «' . $tunel->nombre . '».', 'agregadas' => []];
        }

        try {
            // Dos túneles no pueden llevar la misma red: el tráfico iría a uno solo.
            ServidorVpn::verificarRedesLibres($faltan, $tunel->id);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'detalle' => 'No se agregó al túnel: ' . $e->getMessage(), 'agregadas' => []];
        }

        $tunel->update(['redes_remotas' => array_values(array_merge($actuales, $faltan))]);

        try {
            $r = ServidorVpn::aplicar();
        } catch (\Throwable $e) {
            $r = ['aplicado' => false, 'motivo' => $e->getMessage()];
        }

        Log::info('[VPN] Red de clientes agregada al túnel', [
            'tunel' => $tunel->id, 'redes' => $faltan, 'aplicado' => $r['aplicado'] ?? false,
        ]);

        $lista = implode(', ', $faltan);

        return ($r['aplicado'] ?? false)
            ? ['ok' => true, 'detalle' => "{$lista} agregada al túnel «{$tunel->nombre}».", 'agregadas' => $faltan]
            : ['ok' => false, 'detalle' => "{$lista} quedó anotada en el túnel, pero falta aplicarla: " . ($r['motivo'] ?? 'sin detalle'), 'agregadas' => $faltan];
    }

    /**
     * La red más chica que cubre cada rango: 10.23.0.2-10.23.3.254 → 10.23.0.0/22.
     *
     * @return list<string>
     */
    public static function redesDe(string $rangos): array
    {
        $redes = [];

        foreach (preg_split('/\s*,\s*/', trim($rangos)) ?: [] as $rango) {
            if ($rango === '') {
                continue;
            }

            if (str_contains($rango, '/')) {
                try {
                    $redes = array_merge($redes, ServidorVpn::normalizarRedes([$rango]));
                } catch (\RuntimeException) {
                    // Un rango que no es una red válida no se lleva al túnel.
                }
                continue;
            }

            [$desde, $hasta] = array_pad(array_map('trim', explode('-', $rango, 2)), 2, null);
            $hasta ??= $desde;

            if (!filter_var($desde, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($hasta, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }

            $a = ip2long($desde);
            $b = ip2long($hasta);
            $bits = 32;

            for ($x = $a ^ $b; $x > 0; $x >>= 1) {
                $bits--;
            }

            // Menos de /16 sería meter media red privada en el túnel por un error de tipeo.
            if ($bits < 16) {
                continue;
            }

            $mascara = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
            $redes[] = long2ip($a & $mascara) . '/' . $bits;
        }

        return array_values(array_unique($redes));
    }

    /** El túnel por el que se llega a ese router. */
    private static function tunelDe(ConectionRouter $router): ?VpnTunel
    {
        return ServidorVpn::tunelQueCubre((string) $router->host, (int) $router->company_id)
            ?? VpnTunel::where('company_id', $router->company_id)->where('activo', true)->where('router_id', $router->id)->first()
            ?? VpnTunel::where('company_id', $router->company_id)->where('activo', true)->orderBy('id')->first();
    }

    /** Si alguna de las redes del túnel ya contiene a esta. */
    private static function cubierta(string $red, array $redes): bool
    {
        [$ip, $bits] = explode('/', $red);

        foreach ($redes as $existente) {
            [$base, $bitsBase] = array_pad(explode('/', (string) $existente), 2, '32');

            if ((int) $bitsBase > (int) $bits) {
                continue;
            }

            $mascara = (int) $bitsBase === 0 ? 0 : (-1 << (32 - (int) $bitsBase)) & 0xFFFFFFFF;

            if ((ip2long($ip) & $mascara) === (ip2long($base) & $mascara)) {
                return true;
            }
        }

        return false;
    }
}
