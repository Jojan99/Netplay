<?php

namespace App\OltDrivers;

use App\OltDrivers\Interfaces\OltDriverInterface;

class HuaweiOltDriver implements OltDriverInterface
{
    private object $ssh;
    private int    $lineProfileId;
    private int    $srvProfileId;

    public function __construct(object $ssh, array $config)
    {
        $this->ssh           = $ssh;
        $this->lineProfileId = $config['ont_lineprofile_id'] ?? 10;
        $this->srvProfileId  = $config['ont_srvprofile_id']  ?? 10;

        // Enter enable + config mode once for the session
        $this->ssh->enablePTY();
        $this->ssh->setTimeout(15);
        $this->ssh->read('/[>#$]\s*$/');           // wait for prompt
        // Disable "---- More ----" pagination so long outputs stream without interruption
        $this->ssh->write("scroll\n");
        $this->ssh->read('/[>#$]\s*$/');
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

    public function getAuthorizedONTs(): array
    {
        $this->ssh->setTimeout(60);
        $this->ssh->write("display ont info all\n");
        $output = $this->collectPaged();
        $this->ssh->setTimeout(15);

        return $this->parseAuthorizedONTs($output);
    }

    public function getOntInfo(string $fsp, int $ontId): array
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $this->ssh->setTimeout(30);

        // Basic ONT info
        $this->ssh->write("display ont info {$frame}/{$slot} {$port} {$ontId}\n");
        $infoOutput = $this->collectPaged();

        // Optical info
        $this->ssh->write("display ont optical-info {$frame}/{$slot} {$port} {$ontId}\n");
        $opticalOutput = $this->collectPaged();

        $this->ssh->setTimeout(15);

        return $this->parseOntInfo($infoOutput, $opticalOutput, $fsp, $ontId);
    }

    public function getServicePorts(?string $fsp = null, ?int $ontId = null): array
    {
        $this->ssh->setTimeout(60);

        if ($fsp !== null && $ontId !== null) {
            $this->ssh->write("display service-port port {$fsp} ont {$ontId}\n");
        } else {
            $this->ssh->write("display service-port all\n");
        }

        $output = $this->collectPaged();
        $this->ssh->setTimeout(15);

        return $this->parseServicePorts($output);
    }

    public function transferONT(string $fromFsp, int $ontId, string $toFsp): array
    {
        try {
            [$fromFrame, $fromSlot, $fromPort] = $this->parseFsp($fromFsp);
            [$toFrame,   $toSlot,   $toPort]   = $this->parseFsp($toFsp);

            $this->ssh->setTimeout(30);

            // Step 1: Get ONT info (serial, description)
            $this->ssh->write("display ont info {$fromFrame}/{$fromSlot} {$fromPort} {$ontId}\n");
            $infoOutput = $this->collectPaged();

            $serial      = $this->extractValue($infoOutput, '/SN\s*:\s*(\S+)/i');
            $description = $this->extractValue($infoOutput, '/Description\s*:\s*(.*)/i');

            if (empty($serial)) {
                return ['success' => false, 'new_ont_id' => -1, 'message' => 'Could not retrieve ONT serial number'];
            }

            // Step 2: Get service ports for this ONT
            $servicePorts = $this->getServicePorts($fromFsp, $ontId);

            // Step 3: Remove all service ports
            foreach ($servicePorts as $sp) {
                $this->ssh->write("undo service-port {$sp['index']}\n");
                $this->collectOutput();
            }

            // Step 4: Delete ONT from source port
            $this->ssh->write("interface gpon {$fromFrame}/{$fromSlot}\n");
            $this->ssh->read('/[>#$]\s*$/');

            $this->ssh->write("ont delete {$fromPort} {$ontId}\n");
            $this->collectOutput();

            $this->ssh->write("quit\n");
            $this->ssh->read('/[>#$]\s*$/');

            // Step 5: Register ONT on destination port
            $this->ssh->write("interface gpon {$toFrame}/{$toSlot}\n");
            $this->ssh->read('/[>#$]\s*$/');

            $descArg = !empty($description) ? " desc " . escapeshellarg(trim($description)) : '';
            $confirmCmd = sprintf(
                "ont confirm %d sn-auth %s omci ont-lineprofile-id %d ont-srvprofile-id %d%s\n",
                $toPort,
                strtoupper(trim($serial)),
                $this->lineProfileId,
                $this->srvProfileId,
                $descArg
            );

            $this->ssh->write($confirmCmd);
            $confirmOutput = $this->collectOutput();

            $this->ssh->write("quit\n");
            $this->ssh->read('/[>#$]\s*$/');

            // Parse new ONT ID
            $newOntId = null;
            if (preg_match('/ONTID\s*:\s*(\d+)/i', $confirmOutput, $m)
                || preg_match('/ont\s+(\d+)\s+/i', $confirmOutput, $m)) {
                $newOntId = (int) $m[1];
            }

            $success = stripos($confirmOutput, 'success') !== false
                    || stripos($confirmOutput, 'Succeeded') !== false;

            if (!$success) {
                return [
                    'success'    => false,
                    'new_ont_id' => -1,
                    'message'    => 'ONT registration on new port failed: ' . trim($confirmOutput),
                ];
            }

            // Step 6: Recreate service ports on new FSP
            foreach ($servicePorts as $sp) {
                $spCmd = sprintf(
                    "service-port %d vlan %d gpon %d/%d ont %d gemport %d multi-service user-vlan %d tag-transform translate\n",
                    $sp['index'],
                    $sp['vlan'],
                    $toFrame,
                    $toSlot,
                    $newOntId ?? $ontId,
                    $sp['gemport'],
                    $sp['vlan']
                );
                $this->ssh->write($spCmd);
                $this->collectOutput();
            }

            $this->ssh->setTimeout(15);

            return [
                'success'    => true,
                'new_ont_id' => $newOntId ?? -1,
                'message'    => "ONT transferred from {$fromFsp}/{$ontId} to {$toFsp}/" . ($newOntId ?? '?'),
            ];
        } catch (\Throwable $e) {
            $this->ssh->setTimeout(15);
            return [
                'success'    => false,
                'new_ont_id' => -1,
                'message'    => 'Transfer failed: ' . $e->getMessage(),
            ];
        }
    }

    public function deactivateONT(string $fsp, int $ontId): bool
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $this->ssh->write("interface gpon {$frame}/{$slot}\n");
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("ont deactivate {$port} {$ontId}\n");
        $output = $this->collectOutput();

        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        return stripos($output, 'success') !== false
            || stripos($output, 'Succeeded') !== false;
    }

    public function activateONT(string $fsp, int $ontId): bool
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $this->ssh->write("interface gpon {$frame}/{$slot}\n");
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("ont activate {$port} {$ontId}\n");
        $output = $this->collectOutput();

        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

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

    /**
     * Parse output of "display ont info all"
     * Line format: frame/slot/port  ont_id  serial  control_flag  run_state  config_state  match_state [description...]
     */
    private function parseAuthorizedONTs(string $raw): array
    {
        $onts  = [];
        $lines = explode("\n", $raw);

        foreach ($lines as $line) {
            // Match tabular line: e.g. "  0/ 1/ 0    0    HWTC1234ABCD  active  online  normal  match"
            if (!preg_match(
                '/^\s*(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/i',
                $line,
                $m
            )) {
                continue;
            }

            $fsp    = "{$m[1]}/{$m[2]}/{$m[3]}";
            $ontId  = (int) $m[4];
            $serial = $m[5];
            // m[6]=control_flag, m[7]=run_state, m[8]=config_state, m[9]=match_state
            $runState = strtolower($m[7]);

            $onts[] = [
                'fsp'          => $fsp,
                'ont_id'       => $ontId,
                'serial'       => $serial,
                'control_flag' => $m[6],
                'status'       => $runState,
                'config_state' => $m[8],
                'match_state'  => $m[9],
                'description'  => '',
            ];
        }

        return $onts;
    }

    /**
     * Parse output of "display ont info F/S P ontId" and "display ont optical-info ..."
     */
    private function parseOntInfo(string $infoRaw, string $opticalRaw, string $fsp, int $ontId): array
    {
        $info = [
            'fsp'    => $fsp,
            'ont_id' => $ontId,
        ];

        // Parse key-value pairs from basic info
        $kvMap = [
            'serial'        => '/(?:SN|Serial\s+Number)\s*:\s*(\S+)/i',
            'vendor'        => '/Vendor\s+ID\s*:\s*(\S+)/i',
            'model'         => '/Equipment\s+type\s*:\s*(.*)/i',
            'status'        => '/Run\s+state\s*:\s*(\S+)/i',
            'config_state'  => '/Config\s+state\s*:\s*(\S+)/i',
            'match_state'   => '/Match\s+state\s*:\s*(\S+)/i',
            'description'   => '/Description\s*:\s*(.*)/i',
            'distance_m'    => '/Distance\(m\)\s*:\s*(\d+)/i',
            'last_down_cause' => '/Last\s+down\s+cause\s*:\s*(.*)/i',
            'last_down_time'  => '/Last\s+down\s+time\s*:\s*(.*)/i',
            'last_up_time'    => '/Last\s+up\s+time\s*:\s*(.*)/i',
        ];

        foreach ($kvMap as $key => $pattern) {
            if (preg_match($pattern, $infoRaw, $m)) {
                $info[$key] = trim($m[1]);
            }
        }

        // Parse optical info – try key-value format first
        $opticalKv = $this->parseOpticalKeyValue($opticalRaw);

        if (!empty($opticalKv)) {
            $info = array_merge($info, $opticalKv);
        } else {
            // Try tabular format: frame/slot/port  ont_id  rx_power  tx_power  ...
            if (preg_match(
                '/\s*\d+\s*\/\s*\d+\s*\/\s*\d+\s+\d+\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)/i',
                $opticalRaw,
                $m
            )) {
                $info['rx_optical_power_dbm'] = (float) $m[1];
                $info['tx_optical_power_dbm'] = (float) $m[2];
                $info['olt_rx_power_dbm']     = (float) $m[3];
                $info['olt_tx_power_dbm']     = (float) $m[4];
                $info['laser_bias_current_ma']= (float) $m[5];
            }
        }

        return $info;
    }

    /**
     * Parse key-value optical output lines like:
     * "  Rx optical power(dBm)              : -20.30"
     */
    private function parseOpticalKeyValue(string $raw): array
    {
        $result = [];

        $patterns = [
            'rx_optical_power_dbm'  => '/Rx\s+optical\s+power\s*\(dBm\)\s*:\s*([-\d.]+)/i',
            'tx_optical_power_dbm'  => '/Tx\s+optical\s+power\s*\(dBm\)\s*:\s*([-\d.]+)/i',
            'olt_rx_power_dbm'      => '/OLT\s+Rx\s+(?:optical\s+)?power\s*\(dBm\)\s*:\s*([-\d.]+)/i',
            'olt_tx_power_dbm'      => '/OLT\s+Tx\s+(?:optical\s+)?power\s*\(dBm\)\s*:\s*([-\d.]+)/i',
            'laser_bias_current_ma' => '/Laser\s+bias\s+current\s*\(mA\)\s*:\s*([-\d.]+)/i',
            'temperature_c'         => '/Temperature\s*\(C\)\s*:\s*([-\d.]+)/i',
            'supply_voltage_v'      => '/Supply\s+voltage\s*\(V\)\s*:\s*([-\d.]+)/i',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $raw, $m)) {
                $result[$key] = (float) $m[1];
            }
        }

        return $result;
    }

    /**
     * Parse output of "display service-port ..."
     * Tabular lines contain: index  vlan  F/S/P  ont_id  gemport  ...
     */
    private function parseServicePorts(string $raw): array
    {
        $ports = [];
        $lines = explode("\n", $raw);

        foreach ($lines as $line) {
            // Match a line that has: index(int)  ...  vlan(int)  ...  F/S/P  ...  ont_id(int)  gemport(int)
            // Typical Huawei format:
            // 1024   gpon  0/ 1/ 0   0   1   eth   vlan  100   100   ...
            // We look for lines that contain a F/S/P pattern
            if (!preg_match('/^\s*(\d+)\s+/', $line, $idxMatch)) {
                continue;
            }

            // Must contain a F/S/P pattern
            if (!preg_match('/(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)/', $line, $fspMatch)) {
                continue;
            }

            $index = (int) $idxMatch[1];
            $fsp   = "{$fspMatch[1]}/{$fspMatch[2]}/{$fspMatch[3]}";

            // Extract all integers from the line for positional parsing
            preg_match_all('/\b(\d+)\b/', $line, $allNums);
            $nums = array_map('intval', $allNums[1]);

            // First number is index, find the F/S/P position and take ont_id after it
            // We'll do a more targeted parse
            // Pattern: index  ...  F/S/P  ont_id  gemport  ...  vlan  ...
            // Try to find ont_id after F/S/P
            $ontId  = null;
            $gemport = null;
            $vlan   = null;

            // Remove the index from position consideration
            // Try to extract ont_id and gemport by position relative to F/S/P
            if (preg_match(
                '/(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s+(\d+)\s+(\d+)/i',
                $line,
                $afterFsp
            )) {
                $ontId   = (int) $afterFsp[4];
                $gemport = (int) $afterFsp[5];
            }

            // Extract vlan - look for "vlan" keyword followed by number, or user-vlan
            if (preg_match('/user-vlan\s+(\d+)/i', $line, $vlanMatch)) {
                $vlan = (int) $vlanMatch[1];
            } elseif (preg_match('/\bvlan\s+(\d+)/i', $line, $vlanMatch)) {
                $vlan = (int) $vlanMatch[1];
            } else {
                // Take a large-ish number that could be a VLAN (100-4094 range)
                foreach ($nums as $n) {
                    if ($n >= 1 && $n <= 4094 && $n !== $index) {
                        $vlan = $n;
                        break;
                    }
                }
            }

            if ($index > 0 && $ontId !== null) {
                $ports[] = [
                    'index'   => $index,
                    'vlan'    => $vlan ?? 0,
                    'fsp'     => $fsp,
                    'ont_id'  => $ontId,
                    'gemport' => $gemport ?? 1,
                ];
            }
        }

        return $ports;
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

    /**
     * Read paginated output that may contain "---- More ----" prompts.
     * Sends a space to advance each page until the full output is collected.
     */
    private function collectPaged(): string
    {
        $fullOutput = '';

        try {
            while (true) {
                $chunk = $this->ssh->read('/(?:[>#$]\s*$|---- More ----)/');
                $fullOutput .= $chunk;

                if (strpos($chunk, '---- More ----') !== false) {
                    // Send space to advance to next page
                    $this->ssh->write(' ');
                } else {
                    // Reached end prompt
                    break;
                }
            }
        } catch (\Throwable) {
            // Return whatever we collected so far
        }

        // Clean up "---- More ----" markers from the collected output
        $fullOutput = preg_replace('/\s*----\s*More\s*----\s*/i', "\n", $fullOutput);

        return $fullOutput;
    }

    /**
     * Extract a single value using a regex pattern from raw text.
     */
    private function extractValue(string $raw, string $pattern): ?string
    {
        if (preg_match($pattern, $raw, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
