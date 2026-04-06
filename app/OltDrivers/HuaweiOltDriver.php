<?php

namespace App\OltDrivers;

use App\OltDrivers\Interfaces\OltDriverInterface;
use phpseclib3\Net\SSH2;

class HuaweiOltDriver implements OltDriverInterface
{
    private SSH2 $ssh;
    private int  $lineProfileId;
    private int  $srvProfileId;

    public function __construct(SSH2 $ssh, array $config)
    {
        $this->ssh           = $ssh;
        $this->lineProfileId = $config['ont_lineprofile_id'] ?? 10;
        $this->srvProfileId  = $config['ont_srvprofile_id']  ?? 10;

        // Enter enable + config mode once for the session
        $this->ssh->enablePTY();
        $this->ssh->setTimeout(15);
        $this->ssh->read('/[>#$]\s*$/');           // wait for prompt
        $this->ssh->write("enable\n");
        $this->ssh->read('/[>#$]\s*$/');
        $this->ssh->write("config\n");
        $this->ssh->read('/[>#$]\s*$/');
    }

    // ── Public API ────────────────────────────────────────────────────────

    public function getUnauthONTs(): array
    {
        $this->ssh->write("display ont autofind all\n");
        $output = $this->collectOutput();
        return $this->parseUnauthONTs($output);
    }

    public function registerONT(string $fsp, string $serial, string $description): array
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $this->ssh->write("interface gpon {$frame}/{$slot}\n");
        $this->ssh->read('/[>#$]\s*$/');

        $cmd = sprintf(
            "ont confirm %d sn-auth %s omci ont-lineprofile-id %d ont-srvprofile-id %d desc %s\n",
            $port,
            strtoupper($serial),
            $this->lineProfileId,
            $this->srvProfileId,
            escapeshellarg($description)
        );

        $this->ssh->write($cmd);
        $output = $this->collectOutput();

        // Return to config mode
        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        return $this->parseRegistrationResponse($output, $port);
    }

    public function deleteONT(string $fsp, int $ontId, int $servicePort = 0): bool
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        if ($servicePort > 0) {
            $this->ssh->write("undo service-port {$servicePort}\n");
            $this->collectOutput();
        }

        $this->ssh->write("interface gpon {$frame}/{$slot}\n");
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("ont delete {$port} {$ontId}\n");
        $output = $this->collectOutput();

        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        return stripos($output, 'success') !== false
            || stripos($output, 'Succeeded') !== false;
    }

    public function assignToClient(string $fsp, int $ontId, int $vlan, int $servicePort, string $description): bool
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $cmd = sprintf(
            "service-port %d vlan %d gpon %d/%d ont %d gemport 1 multi-service user-vlan %d tag-transform translate\n",
            $servicePort, $vlan, $frame, $slot, $ontId, $vlan
        );

        $this->ssh->write($cmd);
        $output = $this->collectOutput();

        return stripos($output, 'success') !== false
            || stripos($output, 'Succeeded') !== false;
    }

    // ── Parsing helpers ───────────────────────────────────────────────────

    private function parseUnauthONTs(string $raw): array
    {
        $onts   = [];
        $blocks = preg_split('/Number\s*:\s*\d+/i', $raw);

        foreach ($blocks as $block) {
            if (empty(trim($block))) continue;

            $ont = [];

            if (preg_match('/F\/S\/P\s*:\s*(\S+)/i', $block, $m)) {
                $ont['fsp'] = $m[1];
            }
            if (preg_match('/Ont SN\s*:\s*(\S+)/i', $block, $m)) {
                $ont['serial'] = $m[1];
            }
            if (preg_match('/Vendor ID\s*:\s*(\S+)/i', $block, $m)) {
                $ont['vendor'] = $m[1];
            }
            if (preg_match('/Ont Model\s*:\s*(.*)/i', $block, $m)) {
                $ont['model'] = trim($m[1]);
            }
            if (preg_match('/Distance\(m\)\s*:\s*(\d+)/i', $block, $m)) {
                $ont['distance_m'] = (int) $m[1];
            }

            if (isset($ont['serial'])) {
                $onts[] = $ont;
            }
        }

        return $onts;
    }

    private function parseRegistrationResponse(string $raw, int $portId): array
    {
        $ontId = null;
        if (preg_match('/ONTID\s*:\s*(\d+)/i', $raw, $m)
            || preg_match('/ont\s+(\d+)\s+/i', $raw, $m)) {
            $ontId = (int) $m[1];
        }

        $success = stripos($raw, 'success') !== false
                || stripos($raw, 'Succeeded') !== false;

        return [
            'success'  => $success,
            'port_id'  => $portId,
            'ont_id'   => $ontId,
            'message'  => trim($raw),
        ];
    }

    // ── Utilities ─────────────────────────────────────────────────────────

    /** Parse "frame/slot/port" into [frame, slot, port] as ints */
    private function parseFsp(string $fsp): array
    {
        $parts = array_map('intval', explode('/', $fsp));
        return count($parts) === 3 ? $parts : [0, 0, (int) $fsp];
    }

    /** Read until prompt or timeout */
    private function collectOutput(): string
    {
        try {
            return $this->ssh->read('/[>#$]\s*$/');
        } catch (\Throwable) {
            return '';
        }
    }
}
