<?php

namespace App\Services\Vpn;

use App\Models\VpnServidor;
use App\Models\VpnTunel;

/**
 * El script que se pega en el MikroTik del cliente para levantar el túnel.
 *
 * Cinco cosas tienen que quedar hechas en el router, y las cinco importan:
 *
 *   1. la interfaz WireGuard con su clave privada;
 *   2. su dirección dentro del túnel;
 *   3. el servidor dado de alta como par, con keepalive para que el router sea
 *      el que marca —así funciona aunque el router esté detrás de NAT y no
 *      necesite ningún puerto abierto;
 *   4. una regla de entrada que deje pasar el tráfico que llega por el túnel,
 *      porque el firewall que trae el router por defecto descarta todo lo que
 *      no reconoce;
 *   5. un masquerade del tráfico del túnel hacia la red de la OLT.
 *
 * El punto 5 es el que se olvida y deja el túnel "arriba pero sin servicio":
 * la OLT no tiene ruta de vuelta hacia la red del túnel, así que responde por
 * su gateway y la respuesta se pierde. Enmascarando en el router, la OLT ve el
 * pedido como si viniera de su propia LAN y contesta sin que haya que tocarla.
 */
class ScriptMikrotik
{
    /**
     * @param  string  $clavePrivada     en claro: sólo se entrega al generar
     * @param  string  $claveCompartida  en claro
     */
    public static function para(VpnTunel $tunel, VpnServidor $servidor, string $clavePrivada, string $claveCompartida): string
    {
        $iface    = 'wg-netplay';
        $etiqueta = 'Netplay gestion remota';
        $mascara  = explode('/', $servidor->subred)[1];

        $l = [];

        $l[] = '# ─────────────────────────────────────────────────────────────';
        $l[] = '#  Netplay · túnel de gestión: ' . $tunel->nombre;
        $l[] = '#  Generado ' . now()->format('d/m/Y H:i');
        $l[] = '#';
        $l[] = '#  Pegá todo este bloque en la terminal del router.';
        $l[] = '#  Requiere RouterOS 7.1 o superior (WireGuard).';
        $l[] = '#  Se puede volver a ejecutar: primero limpia lo que dejó antes.';
        $l[] = '# ─────────────────────────────────────────────────────────────';
        $l[] = '';
        $l[] = ':put "Netplay: configurando tunel de gestion...";';
        $l[] = '';
        $l[] = '# 0. Limpieza de una corrida anterior.';
        $l[] = '#    Las reglas se buscan por comentario, que es texto y siempre';
        $l[] = '#    se puede comparar. Lo que cuelga de la interfaz se toca sólo';
        $l[] = '#    si la interfaz existe: si no, RouterOS rechaza el filtro';
        $l[] = '#    porque el nombre no corresponde a ninguna interfaz.';
        $l[] = '/ip/firewall/nat remove [find comment="' . $etiqueta . '"];';
        $l[] = '/ip/firewall/filter remove [find comment="' . $etiqueta . '"];';
        $l[] = ':if ([:len [/interface/wireguard find name="' . $iface . '"]] > 0) do={';
        $l[] = '    /interface/wireguard/peers remove [find interface="' . $iface . '"];';
        $l[] = '    /ip/address remove [find interface="' . $iface . '"];';
        $l[] = '    /interface/wireguard remove [find name="' . $iface . '"];';
        $l[] = '}'; 
        $l[] = '';
        $l[] = '# 1. La interfaz del túnel, con la clave privada de este router.';
        $l[] = '/interface/wireguard add name="' . $iface . '" \\';
        $l[] = '    listen-port=' . $tunel->puerto_router . ' \\';
        $l[] = '    private-key="' . $clavePrivada . '" \\';
        $l[] = '    comment="' . $etiqueta . '";';
        $l[] = '';
        $l[] = '# 2. La dirección de este router dentro del túnel.';
        $l[] = '/ip/address add interface="' . $iface . '" \\';
        $l[] = '    address=' . $tunel->ip_tunel . '/' . $mascara . ' \\';
        $l[] = '    comment="' . $etiqueta . '";';
        $l[] = '';
        $l[] = '# 3. El servidor de Netplay como par. El keepalive hace que el';
        $l[] = '#    router sea quien marca: no hay que abrir ningún puerto acá.';
        $l[] = '/interface/wireguard/peers add interface="' . $iface . '" \\';
        $l[] = '    public-key="' . $servidor->clave_publica . '" \\';

        if ($claveCompartida !== '') {
            $l[] = '    preshared-key="' . $claveCompartida . '" \\';
        }

        $l[] = '    endpoint-address=' . $servidor->endpoint_host . ' \\';
        $l[] = '    endpoint-port=' . $servidor->listen_port . ' \\';
        $l[] = '    allowed-address=' . $servidor->subred . ' \\';
        $l[] = '    persistent-keepalive=' . $tunel->keepalive . 's \\';
        $l[] = '    comment="' . $etiqueta . '";';
        $l[] = '';
        $l[] = '# 4. Dejar entrar lo que llega por el túnel. Va arriba de todo,';
        $l[] = '#    porque el firewall por defecto descarta lo que no reconoce.';
        $l[] = '/ip/firewall/filter add chain=input \\';
        $l[] = '    in-interface="' . $iface . '" \\';
        $l[] = '    action=accept \\';
        $l[] = '    comment="' . $etiqueta . '";';
        $l[] = '/ip/firewall/filter move [find comment="' . $etiqueta . '"] destination=0;';
        $l[] = '';

        $redes = $tunel->redes_remotas ?? [];

        if ($redes) {
            $l[] = '# 5. Enmascarar el tráfico del túnel hacia las redes de gestión.';
            $l[] = '#    Sin esto la OLT recibe el pedido pero no sabe por dónde';
            $l[] = '#    contestar, y el túnel queda arriba sin dar servicio.';

            foreach ($redes as $red) {
                $l[] = '/ip/firewall/nat add chain=srcnat \\';
                $l[] = '    src-address=' . $servidor->subred . ' \\';
                $l[] = '    dst-address=' . $red . ' \\';
                $l[] = '    action=masquerade \\';
                $l[] = '    comment="' . $etiqueta . '";';
            }

            $l[] = '';
        } else {
            $l[] = '# 5. Sin redes de gestión declaradas: no se agrega NAT. Cargá la';
            $l[] = '#    red de la OLT en la plataforma y volvé a generar el script.';
            $l[] = '';
        }

        $l[] = ':put "Netplay: listo. Probando alcance al servidor...";';
        $l[] = ':delay 3s;';
        $l[] = '/ping ' . $servidor->ip_servidor . ' count=3 interface="' . $iface . '";';
        $l[] = '';
        $l[] = '# Para revisar después:';
        $l[] = '#   /interface/wireguard/peers print detail';
        $l[] = '#   /ping ' . $servidor->ip_servidor . ' interface=' . $iface;
        $l[] = '';
        $l[] = '# Para deshacer todo:';
        $l[] = '#   /ip/firewall/nat remove [find comment="' . $etiqueta . '"]';
        $l[] = '#   /ip/firewall/filter remove [find comment="' . $etiqueta . '"]';
        $l[] = '#   /interface/wireguard remove [find name="' . $iface . '"]';

        return implode("\n", $l) . "\n";
    }

    /**
     * Los mismos pasos como lista de comandos sueltos, para aplicarlos por la
     * API de RouterOS en los routers que la plataforma ya administra.
     *
     * @return list<array{ruta:string, accion:string, datos:array<string,string>}>
     */
    public static function comandos(VpnTunel $tunel, VpnServidor $servidor, string $clavePrivada, string $claveCompartida): array
    {
        $iface    = 'wg-netplay';
        $etiqueta = 'Netplay gestion remota';
        $mascara  = explode('/', $servidor->subred)[1];

        $pasos = [
            ['ruta' => '/interface/wireguard', 'accion' => 'add', 'datos' => [
                'name'        => $iface,
                'listen-port' => (string) $tunel->puerto_router,
                'private-key' => $clavePrivada,
                'comment'     => $etiqueta,
            ]],
            ['ruta' => '/ip/address', 'accion' => 'add', 'datos' => [
                'interface' => $iface,
                'address'   => $tunel->ip_tunel . '/' . $mascara,
                'comment'   => $etiqueta,
            ]],
            ['ruta' => '/interface/wireguard/peers', 'accion' => 'add', 'datos' => array_filter([
                'interface'            => $iface,
                'public-key'           => $servidor->clave_publica,
                'preshared-key'        => $claveCompartida ?: null,
                'endpoint-address'     => $servidor->endpoint_host,
                'endpoint-port'        => (string) $servidor->listen_port,
                'allowed-address'      => $servidor->subred,
                'persistent-keepalive' => $tunel->keepalive . 's',
                'comment'              => $etiqueta,
            ])],
            ['ruta' => '/ip/firewall/filter', 'accion' => 'add', 'datos' => [
                'chain'        => 'input',
                'in-interface' => $iface,
                'action'       => 'accept',
                'comment'      => $etiqueta,
                'place-before' => '0',
            ]],
        ];

        foreach ($tunel->redes_remotas ?? [] as $red) {
            $pasos[] = ['ruta' => '/ip/firewall/nat', 'accion' => 'add', 'datos' => [
                'chain'       => 'srcnat',
                'src-address' => $servidor->subred,
                'dst-address' => $red,
                'action'      => 'masquerade',
                'comment'     => $etiqueta,
            ]];
        }

        return $pasos;
    }
}
