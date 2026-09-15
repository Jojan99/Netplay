<?php

namespace App\Services\Red;

use Illuminate\Support\Facades\DB;
use RouterOS\Query;

/**
 * Las redes del router que dan internet a los clientes.
 *
 * El selector de VLAN listaba cualquier dirección /22–/24 del router: la WAN,
 * el servidor, el enlace de la OLT y la gestión de las ONT salían mezclados
 * con las VLAN de clientes. Cada red se clasifica por lo que hay en ella:
 *
 *  1. Clientes de la plataforma con IP adentro → de clientes.
 *  2. Túnel, VPN o red pública → otra.
 *  3. Nombre o comentario de infraestructura (WAN, gestión, OLT…) → otra.
 *  4. Equipos con documento en el ARP → de clientes.
 *  5. VLAN sin clientes y sin nombre de infraestructura → de clientes (la que
 *     se prepara para crecer).
 *
 * Los clientes van primero: "VLAN 100 CDATA" tiene comentario gestion_cdata y
 * setenta clientes; por el nombre sola quedaba afuera.
 */
class RedesParaClientes
{
    private const INFRAESTRUCTURA = '/\b(wan\d*|gesti[oó]n|admin|mgmt|management|olt|servidor|server|enlace|uplink|vpn|c[aá]maras?|voip|monitoreo)\b/iu';

    public function __construct(private int $companyId, private ?int $routerId = null) {}

    /**
     * @return list<array{names:string, network:string, gateway:string, mask:string, vlan_id:?int, comentario:string, tipo:string, clientes:int, motivo:string}>
     */
    public function listar($api, bool $todas = false): array
    {
        $comentarios = [];
        foreach ($this->leer($api, '/interface/print', 'name,comment') as $i) {
            $comentarios[$i['name'] ?? ''] = (string) ($i['comment'] ?? '');
        }

        $vlanIds = [];
        foreach ($this->leer($api, '/interface/vlan/print', 'name,vlan-id') as $v) {
            $vlanIds[$v['name'] ?? ''] = (int) ($v['vlan-id'] ?? 0);
        }

        $conDocumento = [];
        foreach ($this->leer($api, '/ip/arp/print', 'interface,comment') as $a) {
            if (trim((string) ($a['comment'] ?? '')) !== '') {
                $conDocumento[$a['interface'] ?? ''] = ($conDocumento[$a['interface'] ?? ''] ?? 0) + 1;
            }
        }

        $ipsDeClientes = $this->ipsDeClientes();
        $redes = [];

        foreach ($this->leer($api, '/ip/address/print', 'address,interface,disabled') as $fila) {
            $interfaz = (string) ($fila['interface'] ?? '');

            // Sólo /24 /23 /22, como antes: las /29–/30 son enlaces.
            if ($interfaz === '' || ($fila['disabled'] ?? 'false') === 'true'
                || !preg_match('#^(\d+\.\d+\.\d+\.\d+)/(22|23|24)$#', (string) ($fila['address'] ?? ''), $m)) {
                continue;
            }

            $gateway = $m[1];
            $bits    = (int) $m[2];

            $comentario = $comentarios[$interfaz] ?? '';
            $vlanId     = ($vlanIds[$interfaz] ?? 0) ?: null;
            $clientes   = $this->contarEn($ipsDeClientes, $gateway, $bits);

            [$tipo, $motivo] = $this->clasificar($interfaz, $comentario, $gateway, $vlanId, $clientes, $conDocumento[$interfaz] ?? 0);

            $redes[] = [
                'names'      => $interfaz,
                // La misma forma que antes: getIpAvalibles la recibe tal cual.
                'network'    => substr($gateway, 0, strrpos($gateway, '.')) . '.0/' . $bits,
                'gateway'    => $gateway,
                'mask'       => '/' . $bits,
                'vlan_id'    => $vlanId,
                'comentario' => $comentario,
                'tipo'       => $tipo,
                'clientes'   => $clientes,
                'motivo'     => $motivo,
            ];
        }

        usort($redes, fn ($a, $b) => [$a['tipo'] !== 'clientes', -$a['clientes'], $a['names']]
                                <=> [$b['tipo'] !== 'clientes', -$b['clientes'], $b['names']]);

        return $todas ? $redes : array_values(array_filter($redes, fn ($r) => $r['tipo'] === 'clientes'));
    }

    /** @return array{0:string, 1:string} tipo y por qué */
    private function clasificar(string $interfaz, string $comentario, string $ip, ?int $vlanId, int $clientes, int $conDocumento): array
    {
        if ($clientes > 0) {
            return ['clientes', $clientes === 1 ? '1 cliente con IP en esta red' : "{$clientes} clientes con IP en esta red"];
        }

        // Por los túneles y las sesiones se llega; no se reparte internet.
        if (str_starts_with($interfaz, '<') || preg_match('/^(wg|l2tp|pptp|sstp|ovpn|eoip|gre)/i', $interfaz)) {
            return ['otra', 'Túnel o VPN'];
        }

        if (!$this->esPrivada($ip)) {
            return ['otra', 'Red pública'];
        }

        if (preg_match(self::INFRAESTRUCTURA, str_replace(['_', '-'], ' ', $interfaz . ' ' . $comentario))) {
            return ['otra', 'Infraestructura: ' . ($comentario !== '' ? $comentario : $interfaz)];
        }

        if ($conDocumento >= 3) {
            return ['clientes', "{$conDocumento} equipos con documento en el ARP"];
        }

        if ($vlanId) {
            return ['clientes', 'VLAN sin clientes todavía'];
        }

        return ['otra', 'Puerto sin clientes registrados'];
    }

    /** IP (como entero) de los clientes activos de la empresa en este router. */
    private function ipsDeClientes(): array
    {
        return DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->join('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('u.company_id', $this->companyId)
            ->where('ud.active', 1)
            // Los que no tienen router anotado son de la época de un solo router.
            ->when($this->routerId, fn ($q) => $q->where(fn ($w) => $w->where('ud.router_id', $this->routerId)->orWhereNull('ud.router_id')))
            ->whereNotNull('t.ip')
            ->where('t.ip', '<>', '')
            ->pluck('t.ip')
            ->map(fn ($ip) => ip2long(trim((string) $ip)))
            ->filter()
            ->values()
            ->all();
    }

    private function contarEn(array $ips, string $gateway, int $bits): int
    {
        $mascara = -1 << (32 - $bits);
        $red     = ip2long($gateway) & $mascara;

        return count(array_filter($ips, fn ($ip) => ($ip & $mascara) === $red));
    }

    private function esPrivada(string $ip): bool
    {
        $n = ip2long($ip);

        // 100.64.0.0/10 (CGNAT) también es interna, aunque PHP no la marque privada.
        if ($n !== false && ($n & (-1 << 22)) === ip2long('100.64.0.0')) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function leer($api, string $comando, string $campos): array
    {
        try {
            return $api->query((new Query($comando))->add('=.proplist=' . $campos))->read();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
