<?php

namespace App\OltDrivers;

use Illuminate\Support\Facades\Log;

/**
 * OLT V-SOL de la familia V1600G / V1600D.
 *
 * V-SOL trabaja con perfiles: un perfil de ONU (onu), uno de DBA, uno de línea
 * y uno de servicio; la ONU se autoriza contra un perfil y el servicio queda
 * definido por el perfil, no por comandos sueltos. El puerto PON se entra como
 * "interface gpon 0/<puerto>" y dentro de ese modo los comandos de ONU llevan
 * sólo el ID.
 *
 * Comandos según el manual CLI de la serie GPON V-SOL:
 *   show onu unauth / show onu-autofind     ONU detectadas sin autorizar
 *   onu add <id> type <perfil> sn <SN>      autorizar
 *   no onu <id>                             quitar
 *   onu <id> activate | deactivate
 *   show onu info                           ONU del puerto
 *   show onu <id> optical_info              potencias
 *
 * Los nombres de algunos comandos cambian entre versiones de firmware, así que
 * las consultas se hacen probando las variantes documentadas hasta que el
 * equipo responde (ver DriverBase::primeraQueSirva).
 */
class VsolOltDriver extends DriverBase
{
    /** Perfil de ONU con el que se autoriza si no se configura otro. */
    private string $perfilOnu;

    public function __construct(object $ssh, array $config)
    {
        $this->perfilOnu = (string) ($config['vsol_onu_profile'] ?? '');

        parent::__construct($ssh, $config);
    }

    protected function abrirSesion(): void
    {
        $this->ssh->setTimeout(10);

        try {
            $this->ssh->read('/(?:[>#]\s*$|----\s*More\s*----|Username|Password)/i');
        } catch (\Throwable) {
            // Ya está en el prompt.
        }

        // V-SOL abrevia enable como "ena".
        $salida = $this->cmd('enable', 8);

        if ($this->fallo($salida)) {
            $salida = $this->cmd('ena', 8);
        }

        if (preg_match('/[Pp]assword/', $salida) && $this->enablePassword) {
            $this->cmd($this->enablePassword, 8);
        }

        $this->cmd('configure terminal', 8);
        $this->primeraQueSirva(['terminal length 0', 'screen-length 0'], 5);

        $this->ssh->setTimeout(15);
    }

    /**
     * Entra al puerto PON. V-SOL numera los puertos en un solo nivel dentro de
     * la tarjeta, así que "0/1/3" se traduce a "interface gpon 0/3".
     */
    private function entrarAlPuerto(string $fsp): void
    {
        ['frame' => $f, 'port' => $p] = $this->partirFsp($fsp);

        $this->volverAlPrompt();
        $this->cmd('configure terminal', 8);
        $this->cmd("interface gpon {$f}/{$p}", 10);
    }

    // ── Consultas ─────────────────────────────────────────────────────────

    public function getVersion(): string
    {
        return $this->cmd('show version', 30);
    }

    public function getUnauthONTs(): array
    {
        $this->volverAlPrompt();
        $this->cmd('configure terminal', 8);

        $intento = $this->primeraQueSirva([
            'show onu unauth',
            'show onu-autofind',
            'show onu unauthentication',
        ], 40);

        if ($intento['comando'] === null) {
            Log::warning('[OLT V-SOL] Ninguna variante de listado de ONU sin autorizar respondió');

            return [];
        }

        return $this->leerSinAutorizar($intento['salida']);
    }

    /**
     * La tabla de ONU detectadas trae puerto y serial; el formato cambia entre
     * firmware, así que se busca la pareja "puerto + serial" en cada línea.
     */
    private function leerSinAutorizar(string $salida): array
    {
        $onts = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            // "0/1  1  XPONE067B341" o "gpon 0/1   XPONE067B341"
            if (!preg_match('#(\d+/\d+)\D+([0-9A-Za-z]{8,20})#', $linea, $m)) {
                continue;
            }

            $serial = strtoupper($m[2]);

            if (!preg_match('/^[0-9A-F]{12,16}$|^[A-Z]{4}[0-9A-F]{8}$/', $serial)) {
                continue;
            }

            $onts[] = [
                'fsp'      => $this->aFsp($m[1]),
                'serial'   => $serial,
                'vendor'   => substr($serial, 0, 4),
                'ont_id'   => null,
                'status'   => 'unauth',
                'password' => null,
            ];
        }

        return $onts;
    }

    /** "0/3" del equipo → "0/0/3" de la plataforma. */
    private function aFsp(string $puertoVsol): string
    {
        $partes = explode('/', $puertoVsol);

        return count($partes) === 2
            ? "{$partes[0]}/0/{$partes[1]}"
            : $puertoVsol;
    }

    public function getAuthorizedONTs(): array
    {
        $this->volverAlPrompt();
        $this->cmd('configure terminal', 8);

        return $this->leerInfo($this->cmd('show onu info', 90));
    }

    /**
     *   ONU     SN              Status     Description
     *   0/1:1   XPONE067B341    online     Juan Perez
     */
    private function leerInfo(string $salida): array
    {
        $onts = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('#(\d+/\d+)[:\s]+(\d+)\s+([0-9A-Za-z]{8,20})\s+(\S+)(?:\s+(.*))?#', $linea, $m)) {
                continue;
            }

            $onts[] = [
                'fsp'         => $this->aFsp($m[1]),
                'ont_id'      => (int) $m[2],
                'serial'      => strtoupper($m[3]),
                'status'      => str_contains(strtolower($m[4]), 'online') || str_contains(strtolower($m[4]), 'up')
                                 ? 'online' : 'offline',
                'description' => $this->descripcionLegible(trim($m[5] ?? '')) ?: null,
            ];
        }

        return $onts;
    }

    public function getOntInfo(string $fsp, int $ontId): array
    {
        $this->entrarAlPuerto($fsp);

        $detalle = $this->primeraQueSirva([
            "show onu info {$ontId}",
            "show onu {$ontId} detail-info",
        ], 30)['salida'];

        $optico = $this->cmd("show onu {$ontId} optical_info", 30);

        if ($this->fallo($optico)) {
            $optico = $this->cmd("show onu {$ontId} optical-info", 30);
        }

        $distancia = $this->cmd("show onu {$ontId} distance", 20);
        $this->volverAlPrompt();

        return [
            'fsp'         => $fsp,
            'ont_id'      => $ontId,
            'serial'      => $this->dato($detalle, '/SN\s*:?\s*([0-9A-Za-z]{8,20})/i'),
            'status'      => str_contains(strtolower($detalle), 'online') ? 'online' : 'offline',
            'description' => $this->descripcionLegible($this->dato($detalle, '/(?:Description|Name)\s*:?\s*(.+)/i')),
            'distancia_m' => $this->numero($distancia, '/(-?[\d.]+)\s*m/i'),
            'ont_rx'      => $this->numero($optico, '/(?:ONU|Rx)\s*[Rr]x?\s*[Pp]ower\s*:?\s*(-?[\d.]+)/i')
                             ?? $this->numero($optico, '/Rx\s*:?\s*(-?[\d.]+)/i'),
            'ont_tx'      => $this->numero($optico, '/Tx\s*[Pp]ower\s*:?\s*(-?[\d.]+)/i')
                             ?? $this->numero($optico, '/Tx\s*:?\s*(-?[\d.]+)/i'),
            'olt_rx'      => $this->numero($optico, '/OLT\s*[Rr]x\s*(?:[Pp]ower)?\s*:?\s*(-?[\d.]+)/i'),
            'temperatura' => $this->numero($optico, '/Temperature\s*:?\s*(-?[\d.]+)/i'),
            'raw'         => $detalle . "\n" . $optico . "\n" . $distancia,
        ];
    }

    public function getServicePorts(?string $fsp = null, ?int $ontId = null): array
    {
        // En V-SOL el servicio lo define el perfil srv de la ONU, no una tabla
        // de service-ports como en Huawei, así que lo que se reporta es el
        // perfil asignado.
        if ($fsp === null || $ontId === null) {
            return [];
        }

        $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("show onu {$ontId} profile", 20);
        $this->volverAlPrompt();

        $perfil = $this->dato($salida, '/srv[- ]?profile\s*:?\s*(\S+)/i');

        if (!$perfil) {
            return [];
        }

        return [[
            'index'   => $ontId,
            'vlan'    => $this->numero($salida, '/vlan\s*:?\s*(\d+)/i'),
            'fsp'     => $fsp,
            'ont_id'  => $ontId,
            'perfil'  => $perfil,
            'gemport' => null,
        ]];
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
        if ($this->perfilOnu === '') {
            return [
                'success' => false,
                'ont_id'  => 0,
                'message' => 'Falta configurar el perfil de ONU de V-SOL en la OLT (campo vsol_onu_profile).',
            ];
        }

        $this->entrarAlPuerto($fsp);
        $ontId = $this->siguienteOntId($fsp);

        if ($ontId === null) {
            $this->volverAlPrompt();

            return ['success' => false, 'ont_id' => 0, 'message' => 'No quedan ONU ID libres en el puerto ' . $fsp];
        }

        $intento = $this->primeraQueSirva([
            sprintf('onu add %d type %s sn %s', $ontId, $this->perfilOnu, strtoupper($serial)),
            sprintf('onu %d type %s sn %s', $ontId, $this->perfilOnu, strtoupper($serial)),
        ], 30);

        if ($intento['comando'] === null) {
            $this->volverAlPrompt();

            Log::error('[OLT V-SOL] Alta de ONU rechazada', [
                'fsp' => $fsp, 'serial' => $serial, 'salida' => $intento['salida'],
            ]);

            return ['success' => false, 'ont_id' => 0, 'message' => $this->mensaje($intento['salida'])];
        }

        if ($description !== '') {
            $this->cmd(sprintf('onu %d description %s', $ontId, substr(preg_replace('/\s+/', '-', $description), 0, 32)), 15);
        }

        $this->volverAlPrompt();

        return [
            'success' => true,
            'ont_id'  => $ontId,
            'port_id' => $this->partirFsp($fsp)['port'],
            'message' => "ONU autorizada en {$fsp} con ONU ID {$ontId}",
        ];
    }

    private function siguienteOntId(string $fsp): ?int
    {
        $ocupados = [];

        foreach ($this->leerInfo($this->cmd('show onu info', 40)) as $ont) {
            if ($ont['fsp'] === $fsp) {
                $ocupados[] = (int) $ont['ont_id'];
            }
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
        $this->entrarAlPuerto($fsp);

        $intento = $this->primeraQueSirva([
            "no onu {$ontId}",
            "onu {$ontId} delete",
        ], 30);

        $this->volverAlPrompt();

        if ($intento['comando'] === null) {
            Log::error('[OLT V-SOL] Baja de ONU rechazada', [
                'fsp' => $fsp, 'ont_id' => $ontId, 'salida' => $intento['salida'],
            ]);

            return false;
        }

        return true;
    }

    public function assignToClient(string $fsp, int $ontId, int $vlan, int $servicePort, string $description): bool
    {
        $this->entrarAlPuerto($fsp);

        // El servicio lo lleva el perfil srv; acá sólo se ata la ONU al perfil
        // que corresponde a la VLAN del cliente, si existe uno con ese nombre.
        $intento = $this->primeraQueSirva([
            sprintf('onu %d srv-profile name srv_vlan_%d', $ontId, $vlan),
            sprintf('onu %d service 1 gemport 1 vlan %d', $ontId, $vlan),
        ], 20);

        if ($description !== '') {
            $this->cmd(sprintf('onu %d description %s', $ontId, substr(preg_replace('/\s+/', '-', $description), 0, 32)), 15);
        }

        $this->volverAlPrompt();

        return $intento['comando'] !== null;
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
            'success'    => (bool) ($alta['success'] ?? false),
            'new_ont_id' => (int) ($alta['ont_id'] ?? 0),
            'message'    => $alta['message'] ?? '',
        ];
    }

    public function deactivateONT(string $fsp, int $ontId): bool
    {
        $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("onu {$ontId} deactivate", 20);
        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    public function activateONT(string $fsp, int $ontId): bool
    {
        $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("onu {$ontId} activate", 20);
        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    public function getLineProfiles(): array
    {
        $this->volverAlPrompt();
        $this->cmd('configure terminal', 8);

        return $this->leerPerfiles($this->cmd('show profile line', 30));
    }

    public function getSrvProfiles(): array
    {
        $this->volverAlPrompt();
        $this->cmd('configure terminal', 8);

        return $this->leerPerfiles($this->cmd('show profile srv', 30));
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
