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

    public function __construct(object $ssh, array $config)
    {
        $this->tipoOnu   = ($config['zte_onu_type'] ?? '') ?: 'ALL';
        $this->perfilDba = (string) ($config['zte_dba_profile'] ?? '');

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
        if (preg_match('#_(\d+/\d+/\d+)(?::(\d+))?#', $nombre, $m)) {
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

        // El estado no trae el serial; se completa con la tabla de baseinfo,
        // que sí lo lista, cuando el firmware la soporta.
        $base = $this->cmd('show gpon onu baseinfo', 60);

        if (!$this->fallo($base)) {
            $seriales = $this->leerSeriales($base);

            foreach ($onts as &$ont) {
                $clave = $ont['fsp'] . ':' . $ont['ont_id'];
                $ont['serial'] = $seriales[$clave] ?? $ont['serial'];
            }
        }

        return $onts;
    }

    /**
     *   OnuIndex            Admin State    OMCC State   Phase State
     *   gpon-onu_1/1/1:1    enable         enable       working
     */
    private function leerEstado(string $salida): array
    {
        $onts = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('/^\s*(gpon-onu_\S+)\s+(\S+)\s+(\S+)\s+(\S+)/i', $linea, $m)) {
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
            if (!preg_match('/^\s*(gpon-onu_\S+)\s+(\S+)/i', $linea, $m)) {
                continue;
            }

            [$fsp, $ontId] = $this->leerNombre($m[1]);
            $serial = strtoupper(trim($m[2]));

            if ($fsp !== '' && preg_match('/^[A-Z0-9]{8,20}$/', $serial)) {
                $seriales["{$fsp}:{$ontId}"] = $serial;
            }
        }

        return $seriales;
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
            'description'  => $this->dato($detalle, '/Name\s*:?\s*(.+)/i'),
            'distancia_m'  => $this->numero($detalle, '/Distance\s*:?\s*(-?[\d.]+)/i'),
            'ont_rx'       => $this->numero($potencia, '/down\s*Rx\s*:?\s*(-?[\d.]+)/i')
                              ?? $this->numero($potencia, '/ONU\s*Rx\s*:?\s*(-?[\d.]+)/i'),
            'olt_rx'       => $this->numero($potencia, '/up\s*Rx\s*:?\s*(-?[\d.]+)/i')
                              ?? $this->numero($potencia, '/OLT\s*Rx\s*:?\s*(-?[\d.]+)/i'),
            'ont_tx'       => $this->numero($potencia, '/up\s*Tx\s*:?\s*(-?[\d.]+)/i'),
            'raw'          => $detalle . "\n" . $potencia,
        ];
    }

    public function getServicePorts(?string $fsp = null, ?int $ontId = null): array
    {
        if ($fsp === null) {
            return [];
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
            sprintf('onu %d type %s sn %s', $ontId, $this->tipoOnu, strtoupper($serial)),
        ], 20);

        if ($this->fallo($salida)) {
            $this->volverAlPrompt();

            Log::error('[OLT ZTE] Alta de ONU rechazada', [
                'fsp' => $fsp, 'serial' => $serial, 'salida' => $salida,
            ]);

            return ['success' => false, 'ont_id' => 0, 'message' => $this->mensaje($salida)];
        }

        // Nombre y servicio se configuran en la interfaz de la ONU.
        $pasos = ['exit', 'interface ' . $this->onu($fsp, $ontId)];

        if ($description !== '') {
            $pasos[] = 'name ' . substr(preg_replace('/\s+/', '-', $description), 0, 32);
        }

        if ($vlan) {
            if ($this->perfilDba !== '') {
                $pasos[] = "tcont 1 profile {$this->perfilDba}";
                $pasos[] = 'gemport 1 name internet unicast tcont 1 dir both';
            }

            $pasos[] = sprintf(
                'service-port %d vport 1 user-vlan %d vlan %d',
                $servicePort ?: 1, $vlan, $vlan
            );
        }

        $servicio = $this->cmds($pasos, 20);
        $this->volverAlPrompt();

        return [
            'success' => true,
            'ont_id'  => $ontId,
            'port_id' => $this->partirFsp($fsp)['port'],
            'message' => "ONU registrada en {$fsp} con ONT ID {$ontId}",
            'servicio_ok' => !$this->fallo($servicio),
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
        $pasos = ['configure terminal', 'interface ' . $this->onu($fsp, $ontId)];

        if ($description !== '') {
            $pasos[] = 'name ' . substr(preg_replace('/\s+/', '-', $description), 0, 32);
        }

        if ($this->perfilDba !== '') {
            $pasos[] = "tcont 1 profile {$this->perfilDba}";
            $pasos[] = 'gemport 1 name internet unicast tcont 1 dir both';
        }

        $pasos[] = sprintf('service-port %d vport 1 user-vlan %d vlan %d', $servicePort, $vlan, $vlan);

        $salida = $this->cmds($pasos, 20);
        $this->volverAlPrompt();

        return !$this->fallo($salida);
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

    /** En ZTE los perfiles de tráfico son los tcont profile. */
    public function getLineProfiles(): array
    {
        return $this->leerPerfiles($this->cmd('show gpon profile tcont', 30));
    }

    public function getSrvProfiles(): array
    {
        return $this->leerPerfiles($this->cmd('show gpon profile traffic', 30));
    }

    /** @return array<int,string> */
    private function leerPerfiles(string $salida): array
    {
        $perfiles = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('/^\s*(\d+)\s+(\S+)/', $linea, $m)) {
                $perfiles[(int) $m[1]] = $m[2];
            }
        }

        return $perfiles;
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
