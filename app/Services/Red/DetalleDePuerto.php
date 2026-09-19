<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Todo lo que el router sabe de un puerto.
 *
 * Junta en una sola consulta lo que en Winbox está repartido en varias
 * pantallas: cómo negoció el enlace, qué módulo SFP tiene puesto, cuánto
 * tráfico está pasando en este momento, qué VLAN salen por ahí, qué IP tiene
 * configuradas y cuántos clientes cuelgan del puerto.
 *
 * Cada bloque va en su propio try: que el router no soporte una consulta
 * —monitor no existe en interfaces que no son ethernet, por ejemplo— no puede
 * dejar sin datos a los demás.
 */
class DetalleDePuerto
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private string $token,
        // Para poder decir de qué cliente es cada entrada del ARP. Es opcional:
        // sin empresa se muestra el comentario tal cual, como antes.
        private ?int $companyId = null,
    ) {}

    /** @return array<string,mixed> */
    public function de(string $puerto): array
    {
        $api = $this->conexion->conection($this->token);

        $ethernet  = $this->fila($api, '/interface/ethernet/print', $puerto);
        $interfaz  = $this->fila($api, '/interface/print', $puerto);
        $enlace    = $this->enlace($api, $puerto);
        $vlans     = $this->vlans($api, $puerto);

        // Los clientes no cuelgan del ether sino de la VLAN que sale por él,
        // así que se cuentan las dos cosas.
        $interfaces = array_merge([$puerto], array_column($vlans, 'name'));

        return [
            'puerto' => [
                'nombre'      => $puerto,
                'comentario'  => $ethernet['comment'] ?? ($interfaz['comment'] ?? null),
                'tipo'        => $interfaz['type'] ?? null,
                'nombre_fisico' => $ethernet['default-name'] ?? null,
                'mac'         => $ethernet['mac-address'] ?? ($interfaz['mac-address'] ?? null),
                'mtu'         => $interfaz['mtu'] ?? ($ethernet['mtu'] ?? null),
                'l2mtu'       => $ethernet['l2mtu'] ?? null,
                'habilitado'  => ($ethernet['disabled'] ?? $interfaz['disabled'] ?? 'false') !== 'true',
                'con_enlace'  => ($ethernet['running'] ?? $interfaz['running'] ?? 'false') === 'true',
            ],
            'enlace'     => $enlace,
            'vivo'       => $this->enVivo($api, $puerto),
            'acumulado'  => [
                'rx_bytes'   => $ethernet['rx-bytes']  ?? ($interfaz['rx-byte']  ?? null),
                'tx_bytes'   => $ethernet['tx-bytes']  ?? ($interfaz['tx-byte']  ?? null),
                'rx_paquetes'=> $ethernet['rx-packet'] ?? ($interfaz['rx-packet'] ?? null),
                'tx_paquetes'=> $ethernet['tx-packet'] ?? ($interfaz['tx-packet'] ?? null),
            ],
            'errores'    => $this->errores($ethernet),
            'vlans'      => $vlans,
            'ips'        => $this->ips($api, $interfaces),
            'clientes'   => $this->clientes($api, $interfaces),
        ];
    }

    /* ── Consultas ────────────────────────────────────────────────────────── */

    /** @return array<string,mixed> */
    private function fila($api, string $comando, string $puerto): array
    {
        try {
            $q = new Query($comando);
            $q->where('name', $puerto);

            return $api->query($q)->read()[0] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Cómo quedó negociado el enlace y, si es óptico, el estado del módulo.
     *
     * @return array<string,mixed>|null
     */
    private function enlace($api, string $puerto): ?array
    {
        try {
            $q = new Query('/interface/ethernet/monitor');
            $q->equal('numbers', $puerto);
            $q->equal('once', '');

            $r = $api->query($q)->read()[0] ?? null;

            if (!$r) {
                return null;
            }

            $salida = [
                'estado'            => $r['status'] ?? null,
                'velocidad'         => $r['rate'] ?? null,
                'full_duplex'       => ($r['full-duplex'] ?? null) === 'true',
                'autonegociacion'   => $r['auto-negotiation'] ?? null,
                'control_flujo_tx'  => ($r['tx-flow-control'] ?? null) === 'true',
                'control_flujo_rx'  => ($r['rx-flow-control'] ?? null) === 'true',
                'soportado'         => $this->lista($r['supported'] ?? null),
                'anunciado'         => $this->lista($r['advertising'] ?? null),
                'anuncia_el_otro'   => $this->lista($r['link-partner-advertising'] ?? null),
            ];

            if (isset($r['sfp-module-present'])) {
                $salida['sfp'] = [
                    'presente'    => $r['sfp-module-present'] === 'true',
                    'tipo'        => $r['sfp-type'] ?? null,
                    'proveedor'   => $r['sfp-vendor-name'] ?? null,
                    'modelo'      => $r['sfp-vendor-part-number'] ?? null,
                    'serie'       => $r['sfp-vendor-serial'] ?? null,
                    'longitud_onda' => $r['sfp-wavelength'] ?? null,
                    'temperatura' => $r['sfp-temperature'] ?? null,
                    'potencia_tx' => $r['sfp-tx-power'] ?? null,
                    'potencia_rx' => $r['sfp-rx-power'] ?? null,
                    'sin_senal'   => ($r['sfp-rx-loss'] ?? null) === 'true',
                    'falla_tx'    => ($r['sfp-tx-fault'] ?? null) === 'true',
                ];
            }

            return $salida;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<int,string> */
    private function lista(?string $csv): array
    {
        return $csv ? array_values(array_filter(array_map('trim', explode(',', $csv)))) : [];
    }

    /** Lo que está pasando por el puerto ahora mismo. */
    private function enVivo($api, string $puerto): ?array
    {
        try {
            $q = new Query('/interface/monitor-traffic');
            $q->equal('interface', $puerto);
            $q->equal('once', '');

            $r = $api->query($q)->read()[0] ?? null;

            if (!$r) {
                return null;
            }

            return [
                'rx_bps'      => (int) ($r['rx-bits-per-second'] ?? 0),
                'tx_bps'      => (int) ($r['tx-bits-per-second'] ?? 0),
                'rx_pps'      => (int) ($r['rx-packets-per-second'] ?? 0),
                'tx_pps'      => (int) ($r['tx-packets-per-second'] ?? 0),
                'rx_descartes'=> (int) ($r['rx-drops-per-second'] ?? 0),
                'tx_descartes'=> (int) ($r['tx-drops-per-second'] ?? 0),
                'rx_errores'  => (int) ($r['rx-errors-per-second'] ?? 0),
                'tx_errores'  => (int) ($r['tx-errors-per-second'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Sólo los contadores de error que valen algo para diagnosticar, y sólo
     * si no están en cero: una lista de treinta ceros no le sirve a nadie.
     *
     * @param  array<string,mixed>  $ethernet
     * @return array<int,array{campo:string, etiqueta:string, valor:int}>
     */
    private function errores(array $ethernet): array
    {
        $mirar = [
            'rx-fcs-error'            => 'Tramas corruptas (FCS)',
            'rx-align-error'          => 'Errores de alineación',
            'rx-overflow'             => 'Desbordes de recepción',
            'rx-length-error'         => 'Errores de longitud',
            'rx-too-short'            => 'Tramas muy cortas',
            'rx-too-long'             => 'Tramas muy largas',
            'rx-jabber'               => 'Jabber',
            'tx-excessive-collision'  => 'Colisiones excesivas',
            'tx-late-collision'       => 'Colisiones tardías',
            'tx-underrun'             => 'Subdesbordes de envío',
            'tx-carrier-sense-error'  => 'Errores de portadora',
        ];

        $salida = [];

        foreach ($mirar as $campo => $etiqueta) {
            $valor = (int) ($ethernet[$campo] ?? 0);

            if ($valor > 0) {
                $salida[] = ['campo' => $campo, 'etiqueta' => $etiqueta, 'valor' => $valor];
            }
        }

        return $salida;
    }

    /** @return array<int,array<string,mixed>> */
    private function vlans($api, string $puerto): array
    {
        try {
            $q = new Query('/interface/vlan/print');
            $q->where('interface', $puerto);

            return array_map(fn ($v) => [
                'name'       => $v['name'] ?? '',
                'vlan_id'    => $v['vlan-id'] ?? null,
                'comentario' => $v['comment'] ?? null,
                'con_enlace' => ($v['running'] ?? 'false') === 'true',
            ], $api->query($q)->read());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param  array<int,string>  $interfaces
     * @return array<int,array<string,mixed>>
     */
    private function ips($api, array $interfaces): array
    {
        try {
            $q = new Query('/ip/address/print');
            $q->add('=.proplist=address,interface,network,disabled');

            $todas = $api->query($q)->read();

            return array_values(array_map(
                fn ($a) => [
                    'direccion'  => $a['address'] ?? '',
                    'red'        => $a['network'] ?? '',
                    'interfaz'   => $a['interface'] ?? '',
                    'habilitada' => ($a['disabled'] ?? 'false') !== 'true',
                ],
                array_filter($todas, fn ($a) => in_array($a['interface'] ?? '', $interfaces, true))
            ));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Cuántos equipos hay colgando del puerto, contando lo que entra por sus
     * VLAN.
     *
     * @param  array<int,string>  $interfaces
     */
    private function clientes($api, array $interfaces): array
    {
        try {
            $q = new Query('/ip/arp/print');
            $q->add('=.proplist=address,mac-address,interface,comment,disabled');

            $arp = array_filter(
                $api->query($q)->read(),
                fn ($a) => in_array($a['interface'] ?? '', $interfaces, true)
            );

            return [
                'total'  => count($arp),
                'activos'=> count(array_filter($arp, fn ($a) => ($a['disabled'] ?? 'false') !== 'true')),
                // Una muestra alcanza: la lista completa vive en Clientes ARP.
                'muestra'=> array_values(array_map(function ($a) {
                    $cliente = $this->companyId
                        ? IdentidadEnElRouter::clienteDeEntrada($this->companyId, $a)
                        : null;

                    return [
                        'ip'        => $a['address'] ?? '',
                        'mac'       => $a['mac-address'] ?? '',
                        'documento' => $cliente['dni'] ?? ($a['comment'] ?? ''),
                        'cliente'   => $cliente['nombre'] ?? null,
                        'interfaz'  => $a['interface'] ?? '',
                    ];
                }, array_slice($arp, 0, 8))),
            ];
        } catch (\Throwable $e) {
            return ['total' => 0, 'activos' => 0, 'muestra' => []];
        }
    }
}
