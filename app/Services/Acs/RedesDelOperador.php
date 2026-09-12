<?php

namespace App\Services\Acs;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\ConectionRouter;
use App\Models\OltAdmin;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Qué redes hay detrás del router de una empresa, leídas de su propio router.
 *
 * Para que el servidor TR-069 pueda hablarle a los equipos de los clientes
 * hacen falta las redes donde viven esos equipos. Pedirle al operador que las
 * escriba en notación CIDR es pedirle que sepa algo que no tiene por qué
 * saber: acá se leen del router y se muestran con nombre ("Clientes de la
 * vlan 101", "Rango PPPoE principal").
 */
class RedesDelOperador
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    /**
     * @return array{redes: list<array<string,mixed>>, router: ?string, error: ?string}
     */
    public function detectar(?int $routerId = null): array
    {
        $router = ConectionRouter::where('company_id', $this->companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->orderBy('id')
            ->first();

        if (!$router) {
            return ['redes' => [], 'router' => null, 'error' => 'La empresa no tiene un MikroTik configurado.'];
        }

        $redes = $this->deLasOlt();

        try {
            $api = $this->conexion->conection($router->token);

            $redes = array_merge($redes, $this->deLasInterfaces($api), $this->deLosRangosPppoe($api));
        } catch (\Throwable $e) {
            Log::warning('[ACS] No se pudieron leer las redes del router', ['error' => $e->getMessage()]);

            return [
                'redes'  => $redes,
                'router' => $router->name,
                'error'  => 'No se pudo leer el MikroTik: ' . $e->getMessage(),
            ];
        }

        return ['redes' => self::ordenar($redes), 'router' => $router->name ?: $router->host, 'error' => null];
    }

    // ── De dónde sale cada red ────────────────────────────────────────────

    /**
     * Las redes de los clientes y de gestión, por las direcciones del router.
     *
     * Se descarta lo público: ahí está internet, no los equipos del cliente.
     *
     * @return list<array<string,mixed>>
     */
    private function deLasInterfaces($api): array
    {
        $redes = [];

        foreach ($api->query(new Query('/ip/address/print'))->read() as $fila) {
            [$ip, $bits] = array_pad(explode('/', (string) ($fila['address'] ?? '')), 2, '32');
            $interfaz = (string) ($fila['interface'] ?? '');

            if (!filter_var($ip, FILTER_VALIDATE_IP) || !self::esPrivada($ip) || (int) $bits >= 31) {
                continue;
            }

            // Las direcciones de las sesiones PPPoE son de una en una: el rango
            // sale del pool, no de acá. La red del propio túnel tampoco entra:
            // es por donde se llega, no algo a lo que haya que llegar.
            if (str_contains($interfaz, 'pppoe') || str_contains($interfaz, '<') || str_starts_with($interfaz, 'wg')) {
                continue;
            }

            $red = ($fila['network'] ?? self::red($ip, (int) $bits)) . '/' . $bits;

            $redes[$red] = [
                'red'      => $red,
                'nombre'   => self::nombreDeInterfaz($interfaz),
                'tipo'     => str_contains(strtolower($interfaz), 'vlan') ? 'clientes' : 'gestion',
                'origen'   => 'Dirección del router en ' . $interfaz,
                'sugerida' => true,
            ];
        }

        return array_values($redes);
    }

    /**
     * Los rangos que reparte el router a los clientes PPPoE.
     *
     * @return list<array<string,mixed>>
     */
    private function deLosRangosPppoe($api): array
    {
        $redes = [];

        foreach ($api->query(new Query('/ip/pool/print'))->read() as $pool) {
            foreach (explode(',', (string) ($pool['ranges'] ?? '')) as $rango) {
                $red = self::rangoACidr(trim($rango));

                if (!$red) {
                    continue;
                }

                $redes[$red] = [
                    'red'      => $red,
                    'nombre'   => 'Rango de IP ' . ($pool['name'] ?? ''),
                    'tipo'     => 'pppoe',
                    'origen'   => 'Pool ' . ($pool['name'] ?? '') . ' (' . trim($rango) . ')',
                    'sugerida' => true,
                ];
            }
        }

        return array_values($redes);
    }

    /**
     * La red de cada OLT de la empresa: la plataforma ya la usa para gestionarlas
     * y tiene que seguir alcanzándolas.
     *
     * @return list<array<string,mixed>>
     */
    private function deLasOlt(): array
    {
        $redes = [];

        foreach (OltAdmin::where('company_id', $this->companyId)->get(['name', 'host']) as $olt) {
            if (!filter_var($olt->host, FILTER_VALIDATE_IP) || !self::esPrivada($olt->host)) {
                continue;
            }

            $red = self::red($olt->host, 24) . '/24';

            $redes[$red] = [
                'red'      => $red,
                'nombre'   => 'Gestión de la OLT ' . $olt->name,
                'tipo'     => 'olt',
                'origen'   => 'La OLT responde en ' . $olt->host,
                'sugerida' => true,
            ];
        }

        return array_values($redes);
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    /** "vlan101" → "Clientes de la vlan 101"; "ether4" → "Red en ether4". */
    private static function nombreDeInterfaz(string $interfaz): string
    {
        if (preg_match('/vlan\s*(\d+)/i', $interfaz, $m)) {
            return "Clientes de la vlan {$m[1]}";
        }

        if (preg_match('/^bridge|lan/i', $interfaz)) {
            return 'Red local (' . $interfaz . ')';
        }

        return 'Red en ' . $interfaz;
    }

    /**
     * "10.20.0.2-10.20.3.254" → "10.20.0.0/22": el bloque más chico que
     * contiene todo el rango.
     */
    public static function rangoACidr(string $rango): ?string
    {
        $partes = array_map('trim', explode('-', $rango));
        $desde  = $partes[0] ?? '';
        $hasta  = $partes[1] ?? $desde;

        if (!filter_var($desde, FILTER_VALIDATE_IP) || !filter_var($hasta, FILTER_VALIDATE_IP)) {
            return null;
        }

        $a = ip2long($desde);
        $b = ip2long($hasta);

        for ($bits = 32; $bits >= 8; $bits--) {
            $mascara = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

            if (($a & $mascara) === ($b & $mascara)) {
                return long2ip($a & $mascara) . '/' . $bits;
            }
        }

        return null;
    }

    private static function red(string $ip, int $bits): string
    {
        $mascara = (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return long2ip(ip2long($ip) & $mascara);
    }

    /** Sólo las redes privadas: lo público es internet. */
    private static function esPrivada(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Primero lo de los clientes, que es lo que casi siempre hace falta.
     *
     * @param  list<array<string,mixed>>  $redes
     * @return list<array<string,mixed>>
     */
    private static function ordenar(array $redes): array
    {
        $peso = ['clientes' => 0, 'pppoe' => 1, 'olt' => 2, 'gestion' => 3];

        usort($redes, fn ($a, $b) => [$peso[$a['tipo']] ?? 9, $a['red']] <=> [$peso[$b['tipo']] ?? 9, $b['red']]);

        // La misma red puede venir de dos lados —la IP del router y la OLT que
        // vive ahí—: se queda la primera, que es la de nombre más útil.
        $unicas = [];

        foreach ($redes as $red) {
            $unicas[$red['red']] ??= $red;
        }

        return array_values($unicas);
    }
}
