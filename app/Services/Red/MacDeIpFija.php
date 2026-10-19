<?php

namespace App\Services\Red;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mantiene al día la MAC del ARP de los clientes de IP fija.
 *
 * Las VLAN de IP fija tienen el ARP en «reply-only»: el MikroTik sólo le contesta a la
 * IP si la entrada tiene la MAC real del equipo. Cuando una ONT Huawei se reinicia, la
 * OLT le vuelve a crear la conexión por OMCI y a veces queda con otra MAC (…6C:1E pasó a
 * …6C:1D): la ONT se ve «Connected», pero el cliente se queda sin internet ni ping hasta
 * que alguien corrige el ARP a mano (CARMEN URBANEJA, 19-10).
 *
 * Cada vuelta: un ping desde el MikroTik a cada cliente de IP fija con servicio; sólo a los
 * que no contestan se les pregunta a la OLT con qué MAC tiene la conexión de su IP, y si no
 * coincide con el ARP se corrige y queda en el historial del cliente.
 */
class MacDeIpFija
{
    /** Consultas a la OLT por vuelta: cada una es una sesión de consola de unos segundos. */
    private const MAXIMO_OLT = 25;

    public function __construct(private int $companyId) {}

    /** @return array{revisados:int, sin_respuesta:int, corregidos:list<string>, errores:list<string>} */
    public function revisar(): array
    {
        $resumen = ['revisados' => 0, 'sin_respuesta' => 0, 'corregidos' => [], 'errores' => []];

        $clientes = DB::table('user_data as ud')
            ->join('olt_onts as o', 'o.user_data_id', '=', 'ud.id')
            ->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
            ->join('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('ud.company_id', $this->companyId)
            ->where('ud.active', 1)
            ->where('ud.status_internet_id', 1)
            ->where('ud.connection_type', '<>', 'pppoe')
            ->whereRaw('LOWER(a.brand) = ?', ['huawei'])
            ->get(['ud.user_id', 'ud.router_id', 'ud.names', 'ud.lastname', 't.ip', 'o.olt_id', 'o.fsp', 'o.ont_id']);

        $aprov = new AprovisionamientoDeOnt($this->companyId);
        $porRouter = $clientes->groupBy(fn ($c) => (int) $c->router_id);
        $consultasOlt = 0;

        foreach ($porRouter as $grupo) {
            $api = $aprov->routerDelCliente((int) $grupo->first()->user_id);

            if (!$api) {
                $resumen['errores'][] = 'No se pudo entrar al router ' . ($grupo->first()->router_id ?: '(principal)');
                continue;
            }

            // El ARP de una sola lectura: una consulta por IP sería un viaje por cliente.
            $arp = collect($api->query(new \RouterOS\Query('/ip/arp/print'))->read())->keyBy('address');

            foreach ($grupo as $c) {
                $entrada = $arp->get($c->ip);

                if (!$entrada || ($entrada['dynamic'] ?? 'false') === 'true' || ($entrada['disabled'] ?? 'false') === 'true') {
                    continue;   // sin entrada fija (o deshabilitada a propósito): no es este caso
                }

                $resumen['revisados']++;

                if ($this->contesta($api, $c->ip)) {
                    continue;
                }

                $resumen['sin_respuesta']++;

                if ($consultasOlt >= self::MAXIMO_OLT) {
                    continue;   // la próxima vuelta sigue con los que falten
                }

                $consultasOlt++;
                $mac = $this->macEnLaOnt((int) $c->olt_id, (string) $c->fsp, (int) $c->ont_id, (string) $c->ip);
                $actual = strtoupper((string) ($entrada['mac-address'] ?? ''));

                if (!$mac || $mac === $actual) {
                    continue;   // la ONT no tiene esa IP, o la MAC coincide: el problema es otro
                }

                try {
                    $api->query((new \RouterOS\Query('/ip/arp/set'))->equal('.id', $entrada['.id'])->equal('mac-address', $mac))->read();
                } catch (\Throwable $e) {
                    $resumen['errores'][] = "{$c->ip}: no se pudo escribir el ARP (" . mb_substr($e->getMessage(), 0, 80) . ')';
                    continue;
                }

                $nombre = trim("{$c->names} {$c->lastname}");
                $resumen['corregidos'][] = "{$nombre} ({$c->ip}): {$actual} → {$mac}";

                DB::table('user_audit_logs')->insert([
                    'user_id' => $c->user_id, 'changed_by' => 0, 'company_id' => $this->companyId,
                    'field_changed' => 'router', 'old_value' => $actual, 'new_value' => $mac,
                    'description' => 'MAC del ARP corregida sola: la ONT cambió la MAC de su conexión (no contestaba ping)',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                Log::warning('[Red] MAC del ARP corregida', ['cliente' => $c->user_id, 'ip' => $c->ip, 'antes' => $actual, 'ahora' => $mac]);
            }
        }

        return $resumen;
    }

    private function contesta($api, string $ip): bool
    {
        try {
            $r = $api->query((new \RouterOS\Query('/ping'))->equal('address', $ip)->equal('count', '2')->equal('interval', '0.3'))->read();

            return collect($r)->contains(fn ($f) => isset($f['time']) || (int) ($f['received'] ?? 0) > 0);
        } catch (\Throwable) {
            return true;   // sin poder medir no se toca nada
        }
    }

    /**
     * La MAC de la conexión de la ONT que tiene esa IP, según la OLT (OMCI). Null si la OLT
     * no contesta o ninguna conexión de la ONT tiene esa IP.
     */
    private function macEnLaOnt(int $oltId, string $fsp, int $ontId, string $ip): ?string
    {
        [$f, $s, $p] = array_pad(explode('/', $fsp), 3, '0');

        try {
            $salida = (string) app(\App\Services\OltTelnetDispatcher::class)
                ->dispatch($oltId, 'runCommand', ['command' => "display ont wan-info {$f}/{$s} {$p} {$ontId}"]);
        } catch (\Throwable) {
            return null;
        }

        // Una conexión por bloque «Index : N»; la que tiene la IP del cliente trae su MAC.
        foreach (preg_split('/^\s*Index\s*:/mi', $salida) as $bloque) {
            if (preg_match('/IPv4 address\s*:\s*' . preg_quote($ip, '/') . '\b/i', $bloque)
                && preg_match('/MAC address\s*:\s*([0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4})/', $bloque, $m)) {
                return implode(':', str_split(strtoupper(str_replace('-', '', $m[1])), 2));
            }
        }

        return null;
    }
}
