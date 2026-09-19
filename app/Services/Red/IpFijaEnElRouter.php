<?php

namespace App\Services\Red;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * La IP fija de un cliente dentro del MikroTik: en qué red de la VLAN cae y
 * cómo se deja su entrada en el ARP.
 *
 * Una VLAN puede tener más de una red (vlan10 con 192.168.10.1/24 y
 * 192.168.11.1/24). Antes se tomaba sólo la primera: las IP de la segunda no
 * se ofrecían nunca.
 *
 * Al asignar una IP que ya está en el ARP sin cliente de la plataforma, se
 * reutiliza esa entrada —su MAC es la del equipo que ya navega con ella— en
 * vez de crear otra: dos entradas con la misma IP se pelean el ARP. El comment
 * pasa a ser el documento, como escribe la plataforma (hay rutinas que buscan
 * al cliente sólo por eso); el nombre que tenía queda anotado como nombre de
 * origen del cliente, así IdentidadEnElRouter lo sigue reconociendo si otra
 * herramienta lo vuelve a escribir.
 */
class IpFijaEnElRouter
{
    private const MAC_VACIA = '00:00:00:00:00:00';

    public function __construct(private $api, private int $companyId) {}

    // ── Redes de la VLAN ────────────────────────────────────────────────

    /**
     * Las redes configuradas en la interfaz, en el orden del router.
     *
     * @return list<array{gateway:string, bits:int, red:int, difusion:int, network:string, mask:string, netmask:string}>
     */
    public static function redesDe($api, string $interfaz): array
    {
        $q = new Query('/ip/address/print');
        $q->add('=.proplist=address,disabled');
        $q->where('interface', $interfaz);

        $redes = [];

        foreach ($api->query($q)->read() as $fila) {
            if (($fila['disabled'] ?? 'false') === 'true'
                || !preg_match('#^(\d+\.\d+\.\d+\.\d+)(?:/(\d+))?$#', (string) ($fila['address'] ?? ''), $m)) {
                continue;
            }

            // Se recorre con su máscara real, pero no más grande que una /20
            // ni más chica que una /30 (como antes).
            $bits = (int) ($m[2] ?? 24);
            if ($bits < 20 || $bits > 30) {
                $bits = 24;
            }

            $mascara  = (-1 << (32 - $bits)) & 0xFFFFFFFF;
            $red      = ip2long($m[1]) & $mascara;
            $network  = long2ip($red) . '/' . $bits;

            $redes[$network] ??= [
                'gateway'  => $m[1],
                'bits'     => $bits,
                'red'      => $red,
                'difusion' => $red | (~$mascara & 0xFFFFFFFF),
                'network'  => $network,
                'mask'     => '/' . $bits,
                'netmask'  => long2ip($mascara),
            ];
        }

        return array_values($redes);
    }

    /** La red que contiene la IP (o la dirección de red "192.168.11.0/24"). */
    public static function redDe(array $redes, ?string $ip): ?array
    {
        $n = ip2long(explode('/', trim((string) $ip))[0]);

        if ($n === false) {
            return null;
        }

        foreach ($redes as $r) {
            if ($n >= $r['red'] && $n <= $r['difusion']) {
                return $r;
            }
        }

        return null;
    }

    // ── Quién la tiene ──────────────────────────────────────────────────

    /** Otro cliente de la empresa que ya tiene esa IP en su ficha. @return array{user_id:int, nombre:string}|null */
    public static function clienteConIp(int $companyId, string $ip, ?int $salvo = null): ?array
    {
        $f = DB::table('tabla_ips as t')
            ->join('user_data as ud', 'ud.ip_assignment_id', '=', 't.id')
            ->where('t.company_id', $companyId)
            ->whereRaw('TRIM(t.ip) = ?', [$ip])
            ->when($salvo, fn ($q) => $q->where('ud.user_id', '<>', $salvo))
            ->orderByDesc('ud.active')
            ->first(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.active']);

        if (!$f) {
            return null;
        }

        $nombre = trim("{$f->names} {$f->lastname}") ?: 'Cliente #' . $f->user_id;

        return ['user_id' => (int) $f->user_id, 'nombre' => $nombre . ((int) $f->active === 1 ? '' : ' (retirado)')];
    }

    /**
     * ¿Se le puede dar esa IP al cliente? No escribe nada.
     *
     * @return array{ok:bool, mensaje:string, red:?array, propia:?array, huerfana:?array}
     */
    public function revisar(string $ip, string $interfaz, ?int $userId = null): array
    {
        $no = fn (string $m) => ['ok' => false, 'mensaje' => $m, 'red' => null, 'propia' => null, 'huerfana' => null];

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $no("«{$ip}» no es una IP válida.");
        }

        // Entre que se listaron las IP y se guardó, otro pudo quedársela.
        if ($otro = self::clienteConIp($this->companyId, $ip, $userId)) {
            return $no("La IP {$ip} ya la tiene {$otro['nombre']} en la plataforma. Elegí otra.");
        }

        $redes = self::redesDe($this->api, $interfaz);
        $red   = self::redDe($redes, $ip);

        if ($redes && !$red) {
            return $no("La IP {$ip} no es de ninguna red de {$interfaz} (" . implode(', ', array_column($redes, 'network')) . ').');
        }

        $q = new Query('/ip/arp/print');
        $q->where('address', $ip);

        $propia = null;
        $huerfana = null;

        foreach ($this->api->query($q)->read() as $e) {
            $r = IdentidadEnElRouter::resolver($this->companyId, $e, false);

            if ($r['estado'] === 'cliente' && $r['identidad']) {
                if ($userId && (int) $r['identidad']['user_id'] === $userId) {
                    $propia ??= $e;
                    continue;
                }

                return $no("La IP {$ip} está en el router a nombre de {$r['identidad']['nombre']}. Elegí otra.");
            }

            if ($r['estado'] === 'ambiguo') {
                return $no("La IP {$ip} está en el router con «" . ($e['comment'] ?? '') . '», que coincide con más de un cliente. Revisala en el router antes de asignarla.');
            }

            $huerfana ??= $e;
        }

        return ['ok' => true, 'mensaje' => '', 'red' => $red, 'propia' => $propia, 'huerfana' => $huerfana];
    }

    /**
     * Deja la entrada del ARP del cliente para esa IP: la reutiliza si ya
     * estaba (suya o sin cliente) o la crea.
     *
     * @param  array  $revision  lo que devolvió revisar()
     * @return array{accion:string, mensaje:string, comment_anterior:?string, mac:?string}
     */
    public function aplicar(array $revision, string $ip, string $interfaz, string $documento, string $mac = ''): array
    {
        $mac = trim($mac) !== '' ? $mac : self::MAC_VACIA;

        if ($revision['propia']) {
            return ['accion' => 'ya_estaba', 'mensaje' => "La IP {$ip} ya estaba en el router a nombre del cliente.", 'comment_anterior' => null, 'mac' => $revision['propia']['mac-address'] ?? null];
        }

        if ($e = $revision['huerfana']) {
            $anterior = trim((string) ($e['comment'] ?? ''));
            $suMac    = trim((string) ($e['mac-address'] ?? ''));
            $macFinal = ($suMac !== '' && $suMac !== self::MAC_VACIA) ? $suMac : $mac;

            if (($e['dynamic'] ?? 'false') === 'true') {
                // Una entrada aprendida no se puede editar: se fija una estática
                // con la misma MAC y el router deja de lado la dinámica.
                $alta = new Query('/ip/arp/add');
                $alta->equal('address', $ip);
                $alta->equal('mac-address', $macFinal);
                $alta->equal('interface', $e['interface'] ?? $interfaz);
                $alta->equal('comment', $documento);
                $this->api->query($alta)->read();
            } else {
                $set = new Query('/ip/arp/set');
                $set->equal('.id', $e['.id']);
                $set->equal('comment', $documento);
                if ($macFinal !== $suMac) {
                    $set->equal('mac-address', $macFinal);
                }
                // Un cliente que se da de alta tiene servicio.
                if (($e['disabled'] ?? 'false') === 'true') {
                    $set->equal('disabled', 'no');
                }
                $this->api->query($set)->read();
            }

            Log::info('[IP fija] Se reutilizó una entrada del ARP sin cliente', [
                'company_id' => $this->companyId, 'ip' => $ip, 'id' => $e['.id'] ?? null,
                'comment_anterior' => $anterior, 'documento' => $documento, 'mac' => $macFinal,
            ]);

            return [
                'accion'           => 'reutilizada',
                'mensaje'          => "Se tomó la entrada que ya tenía el router para {$ip}" . ($anterior !== '' ? " («{$anterior}»)" : '') . ', con su MAC.',
                'comment_anterior' => $anterior !== '' ? $anterior : null,
                'mac'              => $macFinal !== self::MAC_VACIA ? $macFinal : null,
            ];
        }

        $alta = new Query('/ip/arp/add');
        $alta->equal('address', $ip);
        $alta->equal('mac-address', $mac);
        $alta->equal('interface', $interfaz);
        $alta->equal('comment', $documento);
        $this->api->query($alta)->read();

        return ['accion' => 'creada', 'mensaje' => '', 'comment_anterior' => null, 'mac' => null];
    }

    /**
     * El nombre con el que la entrada estaba en el router queda como nombre
     * de origen del cliente: si otra herramienta (WispHub) lo vuelve a
     * escribir en el comment, la plataforma lo sigue reconociendo.
     */
    public static function recordarNombreAnterior(int $companyId, int $userId, ?string $comment, string $documento = ''): void
    {
        $comment = trim((string) $comment);

        if ($comment === '' || $comment === trim($documento) || mb_strlen($comment) > 100) {
            return;
        }

        try {
            DB::table('clientes_externos')->insertOrIgnore([
                'company_id'  => $companyId,
                'origen'      => 'router',
                'external_id' => $comment,
                'user_id'     => $userId,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            IdentidadEnElRouter::olvidar($companyId);
        } catch (\Throwable $e) {
            Log::warning('[IP fija] No se pudo anotar el nombre anterior del router', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
