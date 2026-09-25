<?php

namespace App\OltDrivers;

use Illuminate\Support\Facades\Log;

/**
 * OLT ZTE de la familia C300 / C320 / C600 (ZXA10).
 *
 * ZTE nombra los puertos como gpon-olt_<frame>/<slot>/<port> y las ONU como
 * gpon-onu_<frame>/<slot>/<port>:<onu>. En la plataforma seguimos hablando de
 * "0/1/3" y de un ONT ID, igual que en Huawei, y acá se traduce.
 *
 * Comandos según el manual de configuración CLI del ZXA10 C320:
 *   show gpon onu uncfg                        ONU detectadas sin registrar
 *   show gpon onu state gpon-olt_1/2/1         estado de las ONU del puerto
 *   show gpon onu detail-info gpon-onu_1/2/1:4 detalle de una ONU
 *   show pon power attenuation gpon-onu_...    potencias ópticas
 *   interface gpon-olt_1/2/1 → onu N type T sn S     registrar
 *   interface gpon-olt_1/2/1 → no onu N              borrar
 */
class ZteOltDriver extends DriverBase
{
    /** Tipo de ONU con el que se registra si no se configura otro. */
    private string $tipoOnu;

    /** Perfil de tráfico (tcont) que se aplica al activar el servicio. */
    private string $perfilDba;

    private int $oltId;

    /** Tipo de ONU elegido para la próxima alta (se usa una vez). */
    private ?string $tipoPedido = null;

    public function __construct(object $ssh, array $config)
    {
        $this->tipoOnu   = ($config['zte_onu_type'] ?? '') ?: 'ALL';
        $this->perfilDba = (string) ($config['zte_dba_profile'] ?? '');
        $this->oltId     = (int) ($config['id'] ?? 0);

        parent::__construct($ssh, $config);
    }

    protected function abrirSesion(): void
    {
        // ZTE entrega el prompt tras el login; sólo hay que vaciar el banner.
        $this->ssh->setTimeout(10);

        try {
            $this->ssh->read('/(?:[>#]\s*$|----\s*More\s*----|Username|Password)/i');
        } catch (\Throwable) {
            // Sin banner, ya está en el prompt.
        }

        // En ZTE "enable" no pide contraseña salvo que esté configurada.
        $salida = $this->cmd('enable', 8);

        if (preg_match('/[Pp]assword/', $salida) && $this->enablePassword) {
            $this->cmd($this->enablePassword, 8);
        }

        // Sin paginador la salida llega completa de una vez.
        $this->cmd('terminal length 0', 5);

        $this->ssh->setTimeout(15);
    }

    // ── Nombres de interfaz ───────────────────────────────────────────────

    private function puerto(string $fsp): string
    {
        ['frame' => $f, 'slot' => $s, 'port' => $p] = $this->partirFsp($fsp);

        return "gpon-olt_{$f}/{$s}/{$p}";
    }

    private function onu(string $fsp, int $ontId): string
    {
        ['frame' => $f, 'slot' => $s, 'port' => $p] = $this->partirFsp($fsp);

        return "gpon-onu_{$f}/{$s}/{$p}:{$ontId}";
    }

    /** De "gpon-onu_1/2/1:4" saca ["1/2/1", 4]. */
    private function leerNombre(string $nombre): array
    {
        if (preg_match('#(?:_|^)(\d+/\d+/\d+)(?::(\d+))?#', trim($nombre), $m)) {
            return [$m[1], isset($m[2]) ? (int) $m[2] : 0];
        }

        return ['', 0];
    }

    // ── Consultas ─────────────────────────────────────────────────────────

    public function getVersion(): string
    {
        $salida = $this->cmd('show version-running', 30);

        return $this->fallo($salida) ? $this->cmd('show version', 30) : $salida;
    }

    public function getUnauthONTs(): array
    {
        $salida = $this->cmd('show gpon onu uncfg', 30);

        return $this->leerUncfg($salida);
    }

    /**
     * La tabla de ONU sin registrar:
     *
     *   OnuIndex            Sn                    State
     *   gpon-onu_1/2/1:1    ZTEGC8A3E0B1          unknown
     *
     * Algunos firmware listan el puerto (gpon-olt_) en vez de la ONU; en ese
     * caso no hay ONT ID asignado todavía, que es lo esperable.
     */
    private function leerUncfg(string $salida): array
    {
        $onts = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('/^\s*(gpon-(?:olt|onu)_\S+)\s+(\S+)(?:\s+(\S+))?/i', $linea, $m)) {
                continue;
            }

            [$fsp] = $this->leerNombre($m[1]);
            $serial = strtoupper(trim($m[2]));

            if ($fsp === '' || !preg_match('/^[A-Z0-9]{8,20}$/', $serial)) {
                continue;
            }

            $onts[] = [
                'fsp'      => $fsp,
                'serial'   => $serial,
                'vendor'   => substr($serial, 0, 4),
                'ont_id'   => null,
                'status'   => trim($m[3] ?? 'uncfg'),
                'password' => null,
            ];
        }

        return $onts;
    }

    public function getAuthorizedONTs(): array
    {
        $salida = $this->cmd('show gpon onu state', 60);
        $onts   = $this->leerEstado($salida);

        // El estado no trae el serial; se completa con la tabla de baseinfo.
        // Unos firmware la dan entera; otros (C320 de skartelecon) responden
        // «Incomplete command» y hay que pedirla puerto por puerto.
        $base = $this->cmd('show gpon onu baseinfo', 60);
        $seriales = $this->fallo($base) ? [] : $this->leerSeriales($base);

        if (!$seriales) {
            foreach (array_unique(array_column($onts, 'fsp')) as $puerto) {
                $dePuerto = $this->cmd("show gpon onu baseinfo gpon-olt_{$puerto}", 60);

                if (!$this->fallo($dePuerto)) {
                    $seriales += $this->leerSeriales($dePuerto);
                }
            }
        }

        foreach ($onts as &$ont) {
            $clave = $ont['fsp'] . ':' . $ont['ont_id'];
            $ont['serial'] = $seriales[$clave] ?? $ont['serial'];
        }
        unset($ont);

        return $onts;
    }

    /**
     *   OnuIndex            Admin State    OMCC State   Phase State
     *   gpon-onu_1/1/1:1    enable         enable       working
     *
     * La C320 de skartelecon lo da sin prefijo:
     *   1/1/1:1     enable       enable      working      1(GPON)
     */
    private function leerEstado(string $salida): array
    {
        $onts = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('#^\s*((?:gpon-onu_)?\d+/\d+/\d+:\d+)\s+(\S+)\s+(\S+)\s+(\S+)#i', $linea, $m)) {
                continue;
            }

            [$fsp, $ontId] = $this->leerNombre($m[1]);

            if ($fsp === '') {
                continue;
            }

            $fase = strtolower(trim($m[4]));

            $onts[] = [
                'fsp'         => $fsp,
                'ont_id'      => $ontId,
                'serial'      => null,
                'status'      => $fase === 'working' ? 'online' : 'offline',
                'fase'        => $fase,
                'admin'       => strtolower(trim($m[2])),
                'description' => null,
            ];
        }

        return $onts;
    }

    /** @return array<string,string> "1/1/1:2" => serial */
    private function leerSeriales(string $salida): array
    {
        $seriales = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('#^\s*((?:gpon-onu_)?\d+/\d+/\d+:\d+)\s+(\S+)#i', $linea, $m)) {
                continue;
            }

            [$fsp, $ontId] = $this->leerNombre($m[1]);
            // Con columna AuthInfo el serial es el que va después de «SN:»; el
            // segundo campo ahí es el tipo de ONU («ALL», «F601»…).
            $serial = strtoupper(trim(preg_match('/\bSN:(\S+)/i', $linea, $sn) ? $sn[1] : $m[2]));

            if ($fsp !== '' && preg_match('/^[A-Z0-9]{8,20}$/', $serial)) {
                $seriales["{$fsp}:{$ontId}"] = $serial;
            }
        }

        return $seriales;
    }

    /**
     * Potencia que recibe cada ONU de un puerto, en un solo comando (1,7 s por
     * puerto en la C320 de skartelecon, contra ~0,5 s por ONU una por una):
     *   Onu                 Rx power
     *   gpon-onu_1/1/1:1    -21.192(dbm)
     * Una ONU apagada sale sin número ("N/A"): queda sin potencia.
     *
     * @return list<array{fsp:string, ont_id:int, potencia:?float}>
     */
    public function potenciasDelPuerto(string $fsp): array
    {
        $salida = $this->cmd('show pon power onu-rx ' . $this->puerto($fsp), 60);
        $filas = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('#^\s*((?:gpon-onu_)?\d+/\d+/\d+:\d+)\s+(\S+)#i', $linea, $m)) {
                continue;
            }

            [$puerto, $ontId] = $this->leerNombre($m[1]);
            $valor = preg_match('/^(-?\d+(?:\.\d+)?)/', $m[2], $n) ? (float) $n[1] : null;

            $filas[] = ['fsp' => $puerto, 'ont_id' => $ontId, 'potencia' => $valor];
        }

        return $filas;
    }

    /**
     * El módulo óptico de una ONU (C320: «show gpon remote-onu interface pon»):
     *   Rx optical level:            -21.136(dBm)
     *   Tx optical level:            2.778(dBm)
     *   Power feed voltage:          3.28(V)
     *   Laser bias current:          16.116(mA)
     *   Temperature:                 62.895(C)
     * Uno por ONU (~1,3 s): para la ficha, no para medir la OLT entera.
     *
     * @return array{potencia:?float, tx:?float, voltaje:?float, corriente:?float, temperatura:?float}
     */
    public function opticaDeOnt(string $fsp, int $ontId): array
    {
        $salida = $this->cmd('show gpon remote-onu interface pon ' . $this->onu($fsp, $ontId), 30);
        $leer = fn (string $etiqueta) => preg_match('/' . $etiqueta . '\s*:\s*(-?\d+(?:\.\d+)?)/i', $salida, $m) ? round((float) $m[1], 2) : null;

        return [
            'potencia'    => $leer('Rx optical level'),
            'tx'          => $leer('Tx optical level'),
            'voltaje'     => $leer('Power feed voltage'),
            'corriente'   => $leer('Laser bias current'),
            'temperatura' => $leer('Temperature'),
        ];
    }

    /**
     * Marca, modelo y versión de la ONU, para la tarjeta «Equipo ONT» de la
     * ficha del cliente («show gpon remote-onu equip»):
     *   Vendor ID:     SKYW
     *   Version:       V1.0
     *   Equipment ID:  GN630V
     *   Model:         GN630V
     * El WiFi no se lee desde aquí en ZTE.
     */
    public function equipoDeOnt(string $fsp, int $ontId): array
    {
        $salida = $this->cmd('show gpon remote-onu equip ' . $this->onu($fsp, $ontId), 30);

        if ($this->fallo($salida)) {
            return [];
        }

        $dato = fn (string $etiqueta) => preg_match('/^\s*' . $etiqueta . '\s*:\s*(.+?)\s*$/im', $salida, $m) && !in_array(strtoupper($m[1]), ['N/A', ''], true) ? $m[1] : null;
        $modelo = $dato('Model') ?? $dato('Equipment ID');

        if (!$modelo && !$dato('Vendor ID')) {
            return [];
        }

        return [
            'version' => [
                'fabricante_id' => $dato('Vendor ID'),
                'modelo'        => $modelo,
                'modelo_ext'    => $dato('Equipment ID'),
                'hardware'      => $dato('Version'),
                'software'      => null,
                'firmware'      => null,
                'chipset'       => null,
                'oui'           => null,
            ],
            'wifi_soportado' => false,
            'wifi'           => null,
        ];
    }

    public function getOntInfo(string $fsp, int $ontId): array
    {
        $onu      = $this->onu($fsp, $ontId);
        $detalle  = $this->cmd("show gpon onu detail-info {$onu}", 30);
        $potencia = $this->cmd("show pon power attenuation {$onu}", 30);

        return [
            'fsp'          => $fsp,
            'ont_id'       => $ontId,
            'serial'       => $this->dato($detalle, '/Serial\s*number\s*:?\s*(\S+)/i'),
            'status'       => str_contains(strtolower($detalle), 'working') ? 'online' : 'offline',
            'description'  => $this->descripcionLegible($this->dato($detalle, '/Name\s*:?\s*(.+)/i')),
            'distancia_m'  => $this->numero($detalle, '/Distance\s*:?\s*(-?[\d.]+)/i'),
            // C320:  up    Rx :-24.437(dbm)   Tx:2.777(dbm)    (OLT recibe / ONU transmite)
            //        down  Tx :6.450(dbm)     Rx:-21.192(dbm)  (OLT transmite / ONU recibe)
            // En la línea "down" el Tx viene antes que el Rx: por eso se busca el
            // Rx en cualquier parte de esa línea y no pegado a "down".
            'ont_rx'       => $this->numero($potencia, '/^\s*down\b[^\n]*?\bRx\s*:?\s*(-?[\d.]+)/im')
                              ?? $this->numero($potencia, '/ONU\s*Rx\s*:?\s*(-?[\d.]+)/i'),
            'olt_rx'       => $this->numero($potencia, '/^\s*up\b[^\n]*?\bRx\s*:?\s*(-?[\d.]+)/im')
                              ?? $this->numero($potencia, '/OLT\s*Rx\s*:?\s*(-?[\d.]+)/i'),
            'ont_tx'       => $this->numero($potencia, '/^\s*up\b[^\n]*?\bTx\s*:?\s*(-?[\d.]+)/im'),
            'raw'          => $detalle . "\n" . $potencia,
        ];
    }

    public function getServicePorts(?string $fsp = null, ?int $ontId = null): array
    {
        // Todos, o los de un puerto: en ZTE viven dentro de la configuración de
        // cada ONU y no hay comando por puerto (la C320 de skartelecon rechaza
        // «show service-port interface gpon-olt_…»). Se lee la configuración
        // entera una vez: 620 ONT en ~20 s.
        if ($ontId === null) {
            $todos = $this->todosLosServicePorts();

            return $fsp === null ? $todos : array_values(array_filter($todos, fn ($sp) => $sp['fsp'] === $fsp));
        }

        // En ZTE el service-port vive dentro de la interfaz de la ONU, así que
        // la configuración corrida de esa interfaz es la fuente fiable.
        $onu    = $ontId !== null ? $this->onu($fsp, $ontId) : $this->puerto($fsp);
        $salida = $this->cmd("show running-config interface {$onu}", 30);

        $puertos = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('/service-port\s+(\d+)\s+vport\s+(\d+).*?(?:user-)?vlan\s+(\d+)/i', $linea, $m)) {
                continue;
            }

            $puertos[] = [
                'index'   => (int) $m[1],
                'vport'   => (int) $m[2],
                'vlan'    => (int) $m[3],
                'fsp'     => $fsp,
                'ont_id'  => $ontId,
                'gemport' => null,
            ];
        }

        return $puertos;
    }

    /**
     * Los service-port de todas las ONU, de «show running-config»:
     *   interface gpon-onu_1/1/1:1
     *     service-port 1 vport 1 user-vlan 100 vlan 100
     *   !
     *
     * @return list<array<string,mixed>>
     */
    private function todosLosServicePorts(): array
    {
        $salida = $this->cmd('show running-config', 150);
        $puertos = [];
        $onu = null;

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('#^\s*interface\s+gpon-onu_(\d+/\d+/\d+):(\d+)#i', $linea, $m)) {
                $onu = [$m[1], (int) $m[2]];
                continue;
            }

            if (preg_match('/^\s*(!|interface\s)/i', $linea)) {
                $onu = null;
                continue;
            }

            if ($onu && preg_match('/service-port\s+(\d+)\s+vport\s+(\d+).*?(?:user-)?vlan\s+(\d+)(?:.*?\bvlan\s+(\d+))?/i', $linea, $m)) {
                $puertos[] = [
                    'index'   => (int) $m[1],
                    'vport'   => (int) $m[2],
                    // "user-vlan 100 vlan 100": la que cuenta en la red es la
                    // segunda (la de la OLT); si sólo hay una, esa.
                    'vlan'    => (int) ($m[4] ?? $m[3]),
                    'fsp'     => $onu[0],
                    'port'    => $onu[0],
                    'ont_id'  => $onu[1],
                    'gemport' => null,
                ];
            }
        }

        return $puertos;
    }

    // ── Altas y bajas ─────────────────────────────────────────────────────

    public function registerONT(
        string $fsp,
        string $serial,
        string $description,
        ?int $lineProfileId = null,
        ?int $srvProfileId = null,
        ?int $vlan = null,
        ?int $servicePort = null
    ): array {
        $ontId = $this->siguienteOntId($fsp);

        if ($ontId === null) {
            return ['success' => false, 'ont_id' => 0, 'message' => 'No quedan ONT ID libres en el puerto ' . $fsp];
        }

        $salida = $this->cmds([
            'configure terminal',
            'interface ' . $this->puerto($fsp),
            sprintf('onu %d type %s sn %s', $ontId, $this->tipoDeEstaAlta(), strtoupper($serial)),
        ], 20);

        if ($this->fallo($salida)) {
            $this->volverAlPrompt();

            Log::error('[OLT ZTE] Alta de ONU rechazada', [
                'fsp' => $fsp, 'serial' => $serial, 'salida' => $salida,
            ]);

            return ['success' => false, 'ont_id' => 0, 'message' => $this->mensaje($salida)];
        }

        $this->volverAlPrompt();

        $servicio = $this->configurarServicio($fsp, $ontId, $description, $vlan, $lineProfileId, $srvProfileId);

        return [
            'success' => true,
            'ont_id'  => $ontId,
            'port_id' => $this->partirFsp($fsp)['port'],
            'message' => "ONU registrada en {$fsp} con ONT ID {$ontId}",
            // Los nombres que lee la plataforma (antes era "servicio_ok" y el
            // paso salía siempre como fallido).
            'service_port_created' => $servicio['ok'],
            'service_port_error'   => $servicio['error'],
            // En ZTE el service-port es de la ONU: siempre el 2 (internet).
            'service_port_index'   => $servicio['ok'] ? 2 : null,
        ];
    }

    /**
     * El primer ONT ID libre del puerto. ZTE no lo asigna solo: hay que darlo
     * en el comando, y si se repite el equipo rechaza el alta.
     */
    private function siguienteOntId(string $fsp): ?int
    {
        $ocupados = [];

        foreach ($this->leerEstado($this->cmd('show gpon onu state ' . $this->puerto($fsp), 30)) as $ont) {
            $ocupados[] = (int) $ont['ont_id'];
        }

        for ($id = 1; $id <= 128; $id++) {
            if (!in_array($id, $ocupados, true)) {
                return $id;
            }
        }

        return null;
    }

    public function deleteONT(string $fsp, int $ontId, array $servicePorts = []): bool
    {
        $salida = $this->cmds([
            'configure terminal',
            'interface ' . $this->puerto($fsp),
            "no onu {$ontId}",
        ], 30);

        $this->volverAlPrompt();

        if ($this->fallo($salida)) {
            Log::error('[OLT ZTE] Baja de ONU rechazada', [
                'fsp' => $fsp, 'ont_id' => $ontId, 'salida' => $salida,
            ]);

            return false;
        }

        return true;
    }

    public function assignToClient(string $fsp, int $ontId, int $vlan, int $servicePort, string $description): bool
    {
        return $this->configurarServicio($fsp, $ontId, $description, $vlan)['ok'];
    }

    /**
     * El servicio de internet de una ONU, igual que las que ya andan en la
     * C320 de skartelecon:
     *   interface gpon-onu_1/1/7:100
     *     tcont 2 profile <subida>
     *     gemport 2 tcont 2
     *     gemport 2 traffic-limit downstream <bajada>      (si se eligió)
     *     service-port 2 vport 2 user-vlan 20 vlan 20
     *   pon-onu-mng gpon-onu_1/1/7:100
     *     service internet gemport 2 vlan 20
     * Antes se mandaba «gemport 1 name internet unicast tcont 1 dir both» (de
     * otro firmware), el índice global de service-port de Huawei y nada dentro
     * de la ONU; sin perfil de subida ni siquiera se creaba el tcont.
     *
     * @return array{ok:bool, error:?string}
     */
    private function configurarServicio(string $fsp, int $ontId, string $description, ?int $vlan, ?int $perfilSubida = null, ?int $perfilBajada = null): array
    {
        $onu = $this->onu($fsp, $ontId);
        $pasos = ['configure terminal', 'interface ' . $onu];

        if ($description !== '') {
            $pasos[] = 'name ' . substr(preg_replace('/\s+/', '_', $description), 0, 32);
        }

        if (!$vlan) {
            $salida = $this->cmds($pasos, 20);
            $this->volverAlPrompt();

            return ['ok' => false, 'error' => null];
        }

        $subida = $this->nombreDePerfil('line', $perfilSubida) ?: $this->perfilDba;
        $bajada = $this->nombreDePerfil('srv', $perfilBajada);

        if ($subida === '') {
            $this->cmds($pasos, 20);
            $this->volverAlPrompt();

            return ['ok' => false, 'error' => 'falta el perfil de subida (tcont). Elegí uno por defecto en los perfiles de la OLT, o en el formulario, y volvé a vincular.'];
        }

        // Internet va en el 2, como en las ONT que ya tiene la OLT (SmartOLT
        // dejaba el 1 para gestión y el 3 para IPTV).
        array_push($pasos, "tcont 2 profile {$subida}", 'gemport 2 tcont 2');

        if ($bajada) {
            $pasos[] = "gemport 2 traffic-limit downstream {$bajada}";
        }

        array_push($pasos,
            sprintf('service-port 2 vport 2 user-vlan %d vlan %d', $vlan, $vlan),
            'exit',
            'pon-onu-mng ' . $onu,
            sprintf('service internet gemport 2 vlan %d', $vlan),
            'exit'
        );

        $salida = $this->cmds($pasos, 30);
        $this->volverAlPrompt();

        if ($this->fallo($salida)) {
            Log::error('[OLT ZTE] No se pudo configurar el servicio', ['onu' => $onu, 'vlan' => $vlan, 'salida' => $salida]);

            return ['ok' => false, 'error' => $this->mensaje($salida)];
        }

        return ['ok' => true, 'error' => null];
    }

    /** El nombre del perfil (en ZTE la OLT los pide por nombre). */
    private function nombreDePerfil(string $tipo, ?int $id): string
    {
        if (!$id || !$this->oltId) {
            return '';
        }

        return (string) \App\Models\OltProfile::where('olt_id', $this->oltId)->where('type', $tipo)->where('profile_id', $id)->value('profile_name');
    }

    public function transferONT(string $fromFsp, int $ontId, string $toFsp): array
    {
        $info = $this->getOntInfo($fromFsp, $ontId);

        if (empty($info['serial'])) {
            return ['success' => false, 'new_ont_id' => 0, 'message' => 'No se pudo leer el serial de la ONU en ' . $fromFsp];
        }

        if (!$this->deleteONT($fromFsp, $ontId)) {
            return ['success' => false, 'new_ont_id' => 0, 'message' => 'No se pudo borrar la ONU de ' . $fromFsp];
        }

        $alta = $this->registerONT($toFsp, $info['serial'], (string) ($info['description'] ?? ''));

        return [
            'success'     => (bool) ($alta['success'] ?? false),
            'new_ont_id'  => (int) ($alta['ont_id'] ?? 0),
            'message'     => $alta['message'] ?? '',
        ];
    }

    /**
     * Reinicia la ONU desde la OLT. En ZTE se hace dentro de su gestión
     * ("pon-onu-mng gpon-onu_1/1/13:1" y ahí "reboot"); según el firmware el
     * comando puede ser otro y algunos piden confirmación.
     *
     * @return array{ok:bool, detalle:string}
     */
    public function reiniciarOnt(string $fsp, int $ontId): array
    {
        $onu = $this->onu($fsp, $ontId);
        $entrada = $this->cmds(['configure terminal', 'pon-onu-mng ' . $onu], 20);

        if ($this->fallo($entrada)) {
            $this->volverAlPrompt();

            Log::error('[OLT ZTE] No se pudo entrar a la gestión de la ONU para reiniciarla', ['onu' => $onu, 'salida' => $entrada]);

            return ['ok' => false, 'detalle' => 'La OLT no dejó entrar a la gestión del equipo: ' . $this->mensaje($entrada)];
        }

        $r = $this->primeraQueSirva(['reboot', 'onu reboot', 'reset'], 25);
        $salida = $r['salida'];

        // Algunos firmwares preguntan antes de reiniciar.
        if (preg_match('/\(y\/n\)|\[yes\/no\]|confirm/i', $salida)) {
            $salida .= $this->cmd('y', 20);
        }

        $this->volverAlPrompt();

        if (!$r['comando'] || $this->fallo($salida)) {
            Log::error('[OLT ZTE] La OLT no reinició la ONU', ['onu' => $onu, 'salida' => $salida]);

            return ['ok' => false, 'detalle' => 'La OLT no reinició el equipo: ' . $this->mensaje($salida)];
        }

        Log::info('[OLT ZTE] ONU reiniciada', ['onu' => $onu, 'comando' => $r['comando']]);

        return ['ok' => true, 'detalle' => 'El equipo se está reiniciando.'];
    }

    public function deactivateONT(string $fsp, int $ontId): bool
    {
        $salida = $this->cmds([
            'configure terminal',
            'interface ' . $this->onu($fsp, $ontId),
            'shutdown',
        ], 20);

        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    public function activateONT(string $fsp, int $ontId): bool
    {
        $salida = $this->cmds([
            'configure terminal',
            'interface ' . $this->onu($fsp, $ontId),
            'no shutdown',
        ], 20);

        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    /**
     * En ZTE el nombre se pone dentro de la interfaz de la ONU, con «name».
     * No admite espacios, así que van guiones bajos.
     */
    public function cambiarDescripcion(string $fsp, int $ontId, string $descripcion): bool
    {
        $salida = $this->cmds([
            'configure terminal',
            'interface ' . $this->onu($fsp, $ontId),
            'name ' . substr(preg_replace('/\s+/', '_', $descripcion), 0, 32),
        ], 20);

        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    /** El tipo de ONU para la próxima alta (el modelo real, p. ej. F680V6.0.06). */
    public function usarTipoOnu(?string $tipo): void
    {
        $tipo = trim((string) $tipo);
        $this->tipoPedido = preg_match('/^[\w.\-]{1,40}$/', $tipo) ? $tipo : null;
    }

    private function tipoDeEstaAlta(): string
    {
        $tipo = $this->tipoPedido ?: $this->tipoOnu;
        $this->tipoPedido = null;

        return $tipo;
    }

    /**
     * Los tipos de ONU cargados en la OLT («show onu-type gpon»):
     *   ONU type name:          F680V6.0.06
     *
     * @return list<string>
     */
    public function tiposDeOnu(): array
    {
        preg_match_all('/ONU\s+type\s+name\s*:\s*(\S+)/i', $this->cmd('show onu-type gpon', 60), $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /** Para la pantalla de alta: en ZTE los perfiles son de subida y de bajada, y hay tipo de ONU. */
    public function capacidades(): array
    {
        return [
            'tecnologia'              => 'gpon',
            'identificador'           => 'serial',
            'service_port'            => true,
            'perfil_servicio_en_alta' => true,
            'vlan'                    => 'service-port',
            'explicacion_vlan'        => null,
            'etiqueta_perfil_linea'   => 'Perfil de subida (tcont)',
            'etiqueta_perfil_servicio' => 'Perfil de bajada (traffic)',
            'tipos_onu'               => $this->tiposDeOnu(),
            'tipo_onu_defecto'        => $this->tipoOnu,
        ];
    }

    /** En ZTE los perfiles de tráfico son los tcont profile. */
    public function getLineProfiles(): array
    {
        return $this->leerPerfiles($this->cmd('show gpon profile tcont', 30));
    }

    public function getSrvProfiles(): array
    {
        return $this->leerPerfiles($this->cmd('show gpon profile traffic', 30));
    }

    /**
     * En ZTE los perfiles tienen nombre, no número:
     *   Profile name :100-up
     *    Type   FBW(kbps)   ABW(kbps)   MBW(kbps) …
     *    4      0           0           102400 …
     * Antes se tomaban las filas de valores como si fueran perfiles. El número
     * que se guarda sale del nombre (siempre el mismo para el mismo nombre),
     * así no cambia si se agregan o borran perfiles; lo que usa la OLT al
     * autorizar es el nombre.
     *
     * @return list<array{id:int, name:string}>
     */
    private function leerPerfiles(string $salida): array
    {
        $perfiles = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('/^\s*Profile\s+name\s*:\s*(\S+)/i', $linea, $m)) {
                $perfiles[] = ['id' => self::idDePerfil($m[1]), 'name' => $m[1]];
            }
        }

        return $perfiles;
    }

    public static function idDePerfil(string $nombre): int
    {
        return crc32($nombre) & 0x7FFFFFFF;
    }

    private function dato(string $raw, string $patron): ?string
    {
        return preg_match($patron, $raw, $m) ? trim($m[1]) : null;
    }

    private function numero(string $raw, string $patron): ?float
    {
        return preg_match($patron, $raw, $m) ? (float) $m[1] : null;
    }
}
