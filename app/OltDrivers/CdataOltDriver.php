<?php

namespace App\OltDrivers;

use Illuminate\Support\Facades\Log;

/**
 * OLT C-Data de la familia FD16xx / FD15xx (también se vende como ECOM).
 *
 * La consola de C-Data imita la de Huawei, con dos diferencias que importan:
 * el puerto PON se entra como "interface gpon <frame>/<slot>" y el número de
 * puerto va como primer argumento de cada comando de ONT. Es decir, donde
 * Huawei dice `interface gpon 0/1` + `ont add 3 ...`, C-Data dice lo mismo pero
 * el 3 es el puerto y el siguiente número es el ONT ID.
 *
 * Comandos según el manual de línea de comandos de la serie FD16xx:
 *   show ont autofind all
 *   ont add <puerto> <ontid> sn-auth "SN" omci ont-lineprofile-id N ont-srvprofile-id M desc "x"
 *   ont delete <puerto> <ontid>
 *   ont activate|deactivate <puerto> <ontid>
 *   show ont info <puerto> <ontid>
 *   show ont optical-info <puerto> <ontid>
 */
class CdataOltDriver extends DriverBase
{
    protected function abrirSesion(): void
    {
        $this->ssh->setTimeout(10);

        try {
            $this->ssh->read('/(?:[>#]\s*$|----\s*More\s*----|Username|Password)/i');
        } catch (\Throwable) {
            // Ya está en el prompt.
        }

        $salida = $this->cmd('enable', 8);

        if (preg_match('/[Pp]assword/', $salida) && $this->enablePassword) {
            $this->cmd($this->enablePassword, 8);
        }

        $this->cmd('config', 8);
        $this->primeraQueSirva(['screen-length 0', 'terminal length 0'], 5);

        $this->ssh->setTimeout(15);
    }

    /** Entra al puerto PON y devuelve el número de puerto dentro de la tarjeta. */
    private function entrarAlPuerto(string $fsp): int
    {
        ['frame' => $f, 'slot' => $s, 'port' => $p] = $this->partirFsp($fsp);

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $this->cmd("interface gpon {$f}/{$s}", 10);

        return $p;
    }

    // ── Consultas ─────────────────────────────────────────────────────────

    public function getVersion(): string
    {
        return $this->cmd('show version', 30) . "\n" . $this->cmd('show device', 30);
    }

    public function getUnauthONTs(): array
    {
        $this->volverAlPrompt();
        $intento = $this->primeraQueSirva([
            'show ont autofind all',
            'show ont autofind-info all',
        ], 40);

        return $this->leerAutofind($intento['salida']);
    }

    /**
     * C-Data lista cada ONT descubierta como un bloque de clave/valor:
     *
     *   F/S/P               : 0/0/1
     *   Ont SN              : CDTCAF53E6E3
     *   Password            :
     *   Ont Version         : ...
     */
    private function leerAutofind(string $salida): array
    {
        $onts   = [];
        $actual = [];

        $cerrar = function () use (&$actual, &$onts) {
            if (!empty($actual['serial']) && !empty($actual['fsp'])) {
                $onts[] = $actual + ['ont_id' => null, 'status' => 'autofind'];
            }

            $actual = [];
        };

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('#F/S/P\s*:\s*(\d+/\d+/\d+)#i', $linea, $m)) {
                $cerrar();
                $actual['fsp'] = $m[1];
                continue;
            }

            if (preg_match('/Ont\s*SN\s*:\s*([0-9A-Fa-f]{8,20})/i', $linea, $m)) {
                $actual['serial'] = strtoupper($m[1]);
                $actual['vendor'] = substr(strtoupper($m[1]), 0, 4);
                continue;
            }

            if (preg_match('/Password\s*:\s*(\S+)/i', $linea, $m)) {
                $actual['password'] = $m[1];
            }
        }

        $cerrar();

        // Algunos firmware devuelven una tabla en una sola línea por ONT.
        if (!$onts) {
            foreach (preg_split('/\r?\n/', $salida) as $linea) {
                if (preg_match('#^\s*(\d+/\d+/\d+)\s+([0-9A-Fa-f]{8,20})#', $linea, $m)) {
                    $onts[] = [
                        'fsp'      => $m[1],
                        'serial'   => strtoupper($m[2]),
                        'vendor'   => substr(strtoupper($m[2]), 0, 4),
                        'ont_id'   => null,
                        'status'   => 'autofind',
                        'password' => null,
                    ];
                }
            }
        }

        return $onts;
    }

    public function getAuthorizedONTs(): array
    {
        $this->volverAlPrompt();
        $intento = $this->primeraQueSirva([
            'show ont info all',
            'show ont-info all',
        ], 90);

        return $this->leerOntInfo($intento['salida']);
    }

    /**
     *   0/0/1   1   CDTCAF53E6E3   online    active   Juan Perez
     */
    private function leerOntInfo(string $salida): array
    {
        $onts = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('#^\s*(\d+/\d+/\d+)\s+(\d+)\s+([0-9A-Za-z]{8,20})\s+(\S+)(?:\s+(\S+))?(?:\s+(.*))?$#', $linea, $m)) {
                continue;
            }

            $onts[] = [
                'fsp'         => $m[1],
                'ont_id'      => (int) $m[2],
                'serial'      => strtoupper($m[3]),
                'status'      => str_contains(strtolower($m[4]), 'online') ? 'online' : 'offline',
                'admin'       => strtolower(trim($m[5] ?? '')),
                'description' => trim($m[6] ?? '') ?: null,
            ];
        }

        return $onts;
    }

    public function getOntInfo(string $fsp, int $ontId): array
    {
        $puerto  = $this->entrarAlPuerto($fsp);
        $detalle = $this->cmd("show ont info {$puerto} {$ontId}", 30);
        $optico  = $this->cmd("show ont optical-info {$puerto} {$ontId}", 30);
        $this->volverAlPrompt();

        return [
            'fsp'         => $fsp,
            'ont_id'      => $ontId,
            'serial'      => $this->dato($detalle, '/(?:Ont\s*)?SN\s*:\s*(\S+)/i'),
            'status'      => str_contains(strtolower($detalle), 'online') ? 'online' : 'offline',
            'description' => $this->dato($detalle, '/Descri\w*\s*:\s*(.+)/i'),
            'distancia_m' => $this->numero($detalle, '/Distance\s*\(?m?\)?\s*:\s*(-?[\d.]+)/i'),
            'ont_rx'      => $this->numero($optico, '/(?:ONT|Rx)\s*[Oo]ptical\s*[Pp]ower\s*:\s*(-?[\d.]+)/i')
                             ?? $this->numero($optico, '/Rx\s*power\s*:\s*(-?[\d.]+)/i'),
            'ont_tx'      => $this->numero($optico, '/Tx\s*power\s*:\s*(-?[\d.]+)/i'),
            'olt_rx'      => $this->numero($optico, '/OLT\s*Rx\s*(?:power)?\s*:\s*(-?[\d.]+)/i'),
            'temperatura' => $this->numero($optico, '/Temperature\s*:\s*(-?[\d.]+)/i'),
            'raw'         => $detalle . "\n" . $optico,
        ];
    }

    public function getServicePorts(?string $fsp = null, ?int $ontId = null): array
    {
        $this->volverAlPrompt();
        $salida = $this->cmd('show service-port all', 60);

        $puertos = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('#^\s*(\d+)\s+(\d+)\s+gpon\s+(\d+/\d+)\s*/?\s*(\d+)?\s+(\d+)#i', $linea, $m)) {
                continue;
            }

            $linFsp  = $m[3] . '/' . ($m[4] ?? '0');
            $linOnt  = (int) $m[5];

            if ($fsp !== null && $linFsp !== $fsp) {
                continue;
            }

            if ($ontId !== null && $linOnt !== $ontId) {
                continue;
            }

            $puertos[] = [
                'index'   => (int) $m[1],
                'vlan'    => (int) $m[2],
                'fsp'     => $linFsp,
                'ont_id'  => $linOnt,
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
        $puerto = $this->entrarAlPuerto($fsp);
        $ontId  = $this->siguienteOntId($fsp, $puerto);

        if ($ontId === null) {
            $this->volverAlPrompt();

            return ['success' => false, 'ont_id' => 0, 'message' => 'No quedan ONT ID libres en el puerto ' . $fsp];
        }

        $comando = sprintf(
            'ont add %d %d sn-auth "%s" omci ont-lineprofile-id %d ont-srvprofile-id %d desc "%s"',
            $puerto,
            $ontId,
            strtoupper($serial),
            $lineProfileId ?? $this->lineProfileId,
            $srvProfileId  ?? $this->srvProfileId,
            substr($description, 0, 32)
        );

        $salida = $this->cmd($comando, 30);

        // Sin perfiles el firmware viejo acepta la forma corta.
        if ($this->fallo($salida)) {
            $salida = $this->cmd(
                sprintf('ont add %d %d sn-auth "%s"', $puerto, $ontId, strtoupper($serial)),
                30
            );
        }

        if ($this->fallo($salida)) {
            $this->volverAlPrompt();

            Log::error('[OLT C-Data] Alta de ONT rechazada', [
                'fsp' => $fsp, 'serial' => $serial, 'salida' => $salida,
            ]);

            return ['success' => false, 'ont_id' => 0, 'message' => $this->mensaje($salida)];
        }

        $servicioOk = true;

        if ($vlan) {
            $servicioOk = $this->servicio($puerto, $ontId, $vlan, $servicePort ?: $ontId, $fsp, $description);
        }

        $this->volverAlPrompt();

        return [
            'success'     => true,
            'ont_id'      => $ontId,
            'port_id'     => $puerto,
            'message'     => "ONT registrada en {$fsp} con ONT ID {$ontId}",
            'servicio_ok' => $servicioOk,
        ];
    }

    /** El primer ONT ID libre del puerto. */
    private function siguienteOntId(string $fsp, int $puerto): ?int
    {
        $ocupados = [];

        foreach ($this->leerOntInfo($this->cmd("show ont info {$puerto} all", 40)) as $ont) {
            $ocupados[] = (int) $ont['ont_id'];
        }

        for ($id = 1; $id <= 128; $id++) {
            if (!in_array($id, $ocupados, true)) {
                return $id;
            }
        }

        return null;
    }

    /** El service-port y la VLAN del puerto de usuario. */
    private function servicio(int $puerto, int $ontId, int $vlan, int $servicePort, string $fsp, string $description): bool
    {
        ['frame' => $f, 'slot' => $s] = $this->partirFsp($fsp);

        $salida = $this->cmd(sprintf('ont port native-vlan %d %d eth 1 vlan %d', $puerto, $ontId, $vlan), 20);

        $this->volverAlPrompt();
        $this->cmd('config', 8);

        $salida .= $this->cmd(sprintf(
            'service-port %d vlan %d gpon %d/%d %d ont %d gemport 1 multi-service user-vlan %d',
            $servicePort, $vlan, $f, $s, $puerto, $ontId, $vlan
        ), 20);

        return !$this->fallo($salida);
    }

    public function deleteONT(string $fsp, int $ontId, array $servicePorts = []): bool
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);

        foreach ($servicePorts as $indice) {
            $this->cmd('no service-port ' . (int) $indice, 20);
        }

        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("ont delete {$puerto} {$ontId}", 30);
        $this->volverAlPrompt();

        if ($this->fallo($salida)) {
            Log::error('[OLT C-Data] Baja de ONT rechazada', [
                'fsp' => $fsp, 'ont_id' => $ontId, 'salida' => $salida,
            ]);

            return false;
        }

        return true;
    }

    public function assignToClient(string $fsp, int $ontId, int $vlan, int $servicePort, string $description): bool
    {
        $puerto = $this->entrarAlPuerto($fsp);
        $ok     = $this->servicio($puerto, $ontId, $vlan, $servicePort, $fsp, $description);
        $this->volverAlPrompt();

        return $ok;
    }

    public function transferONT(string $fromFsp, int $ontId, string $toFsp): array
    {
        $info = $this->getOntInfo($fromFsp, $ontId);

        if (empty($info['serial'])) {
            return ['success' => false, 'new_ont_id' => 0, 'message' => 'No se pudo leer el serial de la ONT en ' . $fromFsp];
        }

        if (!$this->deleteONT($fromFsp, $ontId)) {
            return ['success' => false, 'new_ont_id' => 0, 'message' => 'No se pudo borrar la ONT de ' . $fromFsp];
        }

        $alta = $this->registerONT($toFsp, $info['serial'], (string) ($info['description'] ?? ''));

        return [
            'success'    => (bool) ($alta['success'] ?? false),
            'new_ont_id' => (int) ($alta['ont_id'] ?? 0),
            'message'    => $alta['message'] ?? '',
        ];
    }

    public function deactivateONT(string $fsp, int $ontId): bool
    {
        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("ont deactivate {$puerto} {$ontId}", 20);
        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    public function activateONT(string $fsp, int $ontId): bool
    {
        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("ont activate {$puerto} {$ontId}", 20);
        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    public function getLineProfiles(): array
    {
        $this->volverAlPrompt();

        return $this->leerPerfiles($this->primeraQueSirva([
            'show ont-lineprofile gpon all',
            'show ont-lineprofile all',
        ], 30)['salida']);
    }

    public function getSrvProfiles(): array
    {
        $this->volverAlPrompt();

        return $this->leerPerfiles($this->primeraQueSirva([
            'show ont-srvprofile gpon all',
            'show ont-srvprofile all',
        ], 30)['salida']);
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
