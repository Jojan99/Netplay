<?php

namespace App\Services;

use App\Models\OltAdmin;
use RuntimeException;

/**
 * Reads ONT and optical data from a Huawei OLT via SNMP.
 *
 * All queries are read-only. Write operations (register, delete, assign,
 * activate/deactivate) continue to go through HuaweiOltDriver over Telnet.
 *
 * Requires the php-snmp extension:
 *   sudo apt install php8.2-snmp && sudo systemctl restart php8.2-fpm
 */
class HuaweiSnmpReader
{
    // ── Huawei MA5600T / MA5800 GPON MIB OIDs ────────────────────────────
    // hwGponOntInfoTable: 1.3.6.1.4.1.2011.6.128.1.1.2.46
    private const OID_ONT_SN           = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.4';   // Hex-STRING 8 bytes
    private const OID_ONT_CTRL         = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.6';   // 0=active 1=deactivate
    private const OID_ONT_DESC         = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.10';  // STRING
    private const OID_ONT_RUNSTATE     = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15';  // 1=online 2=offline
    private const OID_ONT_CFGSTATE     = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.16';  // 0=initial 1=config 2=working
    private const OID_ONT_MATCHSTATE   = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.17';  // 0=initial 1=match 2=unmatch

    // hwGponOntOptInfoTable: 1.3.6.1.4.1.2011.6.128.1.1.2.47
    // Values are INTEGER × 0.01 dBm  (e.g. -2030 → -20.30 dBm)
    private const OID_OPT_OLT_RX      = '1.3.6.1.4.1.2011.6.128.1.1.2.47.1.3';   // OLT Rx from ONT
    private const OID_OPT_ONT_TX      = '1.3.6.1.4.1.2011.6.128.1.1.2.47.1.4';   // ONT Tx
    private const OID_OPT_ONT_RX      = '1.3.6.1.4.1.2011.6.128.1.1.2.47.1.5';   // ONT Rx from OLT
    private const OID_OPT_TEMP        = '1.3.6.1.4.1.2011.6.128.1.1.2.47.1.6';   // Temperature ×1 °C
    private const OID_OPT_VOLTAGE     = '1.3.6.1.4.1.2011.6.128.1.1.2.47.1.7';   // Voltage × 0.001 V
    private const OID_OPT_BIAS        = '1.3.6.1.4.1.2011.6.128.1.1.2.47.1.8';   // Laser bias × 0.01 mA

    private string $host;
    private string $community;
    private string $version;
    private int    $port;

    public function __construct(OltAdmin $olt)
    {
        $this->host      = $olt->snmp_host ?: $olt->host;
        $this->community = $olt->snmp_community ?: 'public';
        $this->version   = $olt->snmp_version   ?: '2c';
        $this->port      = (int) ($olt->snmp_port ?: 161);
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Return all registered ONTs with run-state, serial, description.
     */
    public function getAuthorizedONTs(): array
    {
        $serials    = $this->walk(self::OID_ONT_SN);
        $runStates  = $this->walk(self::OID_ONT_RUNSTATE);
        $descs      = $this->walk(self::OID_ONT_DESC);
        $ctrlFlags  = $this->walk(self::OID_ONT_CTRL);
        $cfgStates  = $this->walk(self::OID_ONT_CFGSTATE);
        $matchStates= $this->walk(self::OID_ONT_MATCHSTATE);

        $onts = [];

        foreach ($serials as $oidSuffix => $rawSn) {
            $idx = $this->parseIndex($oidSuffix);
            if ($idx === null) continue;

            [$slot, $port, $ontId] = $idx;
            $fsp    = "0/{$slot}/{$port}";
            $serial = $this->parseSerial($rawSn);

            $runRaw = $runStates[$oidSuffix]  ?? 'INTEGER: 2';
            $run    = (int) $this->extractInt($runRaw);
            $ctrl   = (int) $this->extractInt($ctrlFlags[$oidSuffix]  ?? 'INTEGER: 0');
            $cfg    = (int) $this->extractInt($cfgStates[$oidSuffix]  ?? 'INTEGER: 0');
            $match  = (int) $this->extractInt($matchStates[$oidSuffix]?? 'INTEGER: 0');

            $onts[] = [
                'fsp'          => $fsp,
                'ont_id'       => $ontId,
                'serial'       => $serial,
                'control_flag' => $ctrl === 0 ? 'active' : 'deactivate',
                'status'       => $run === 1 ? 'online' : 'offline',
                'config_state' => match ($cfg) { 1 => 'config', 2 => 'working', default => 'initial' },
                'match_state'  => match ($match) { 1 => 'match', 2 => 'unmatch', default => 'initial' },
                'description'  => $this->extractString($descs[$oidSuffix] ?? ''),
            ];
        }

        // Sort by fsp + ont_id for consistent display
        usort($onts, fn($a, $b) => strcmp($a['fsp'], $b['fsp']) ?: $a['ont_id'] <=> $b['ont_id']);

        return $onts;
    }

    /**
     * Return detailed info + optical readings for a single ONT.
     */
    public function getOntInfo(string $fsp, int $ontId): array
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        // Index suffix: slot.port.ontId (frame is structural, not in SNMP index)
        $suffix = "{$slot}.{$port}.{$ontId}";

        $info = [
            'fsp'    => $fsp,
            'ont_id' => $ontId,
            'serial' => $this->parseSerial($this->get(self::OID_ONT_SN . '.' . $suffix)),
            'description' => $this->extractString($this->get(self::OID_ONT_DESC . '.' . $suffix)),
            'status'      => $this->extractInt($this->get(self::OID_ONT_RUNSTATE . '.' . $suffix)) == 1 ? 'online' : 'offline',
        ];

        // Optical data
        $oltRx  = $this->getInt(self::OID_OPT_OLT_RX  . '.' . $suffix);
        $ontTx  = $this->getInt(self::OID_OPT_ONT_TX  . '.' . $suffix);
        $ontRx  = $this->getInt(self::OID_OPT_ONT_RX  . '.' . $suffix);
        $temp   = $this->getInt(self::OID_OPT_TEMP    . '.' . $suffix);
        $volt   = $this->getInt(self::OID_OPT_VOLTAGE . '.' . $suffix);
        $bias   = $this->getInt(self::OID_OPT_BIAS    . '.' . $suffix);

        // Convert units — field names match what the frontend template expects
        // SNMP dBm values are × 100 (e.g. -2130 → -21.30 dBm)
        if ($oltRx !== null && $oltRx !== -32768) $info['olt_rx_power']  = round($oltRx / 100, 2);
        if ($ontTx !== null && $ontTx !== -32768) $info['tx_power']      = round($ontTx / 100, 2);
        if ($ontRx !== null && $ontRx !== -32768) $info['rx_power']      = round($ontRx / 100, 2);
        if ($temp   !== null)                     $info['temperature']   = $temp;
        if ($volt   !== null && $volt > 0)        $info['voltage']       = round($volt / 1000, 3);
        if ($bias   !== null && $bias > 0)        $info['laser_current'] = round($bias / 100, 2);

        return $info;
    }

    // ── SNMP primitives ───────────────────────────────────────────────────

    /**
     * SNMP walk: returns [oid_suffix => raw_value_string, ...]
     * suffix = everything after the base OID.
     */
    private function walk(string $oid): array
    {
        $this->requireExtension();

        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        snmp_set_quick_print(false);

        $timeout = 5_000_000; // 5 s in microseconds
        $retries = 1;

        $raw = match ($this->version) {
            '1'  => @snmpwalk($this->host . ':' . $this->port, $this->community, $oid, $timeout, $retries),
            default => @snmp2_walk($this->host . ':' . $this->port, $this->community, $oid, $timeout, $retries),
        };

        if ($raw === false) {
            \Log::warning('SNMP walk failed', ['host' => $this->host, 'oid' => $oid]);
            return [];
        }

        // snmpwalk returns a flat array; rebuild with OID as key.
        // To get OID-keyed results, use snmprealwalk / snmp2_real_walk
        $real = match ($this->version) {
            '1'  => @snmprealwalk($this->host . ':' . $this->port, $this->community, $oid, $timeout, $retries),
            default => @snmp2_real_walk($this->host . ':' . $this->port, $this->community, $oid, $timeout, $retries),
        };

        if (empty($real)) return [];

        // Strip base OID prefix, keep only the suffix (the index)
        $result = [];
        $baseLen = strlen($oid) + 1; // +1 for the trailing dot
        foreach ($real as $fullOid => $value) {
            $suffix = substr($fullOid, $baseLen);
            $result[$suffix] = $value;
        }

        return $result;
    }

    /** Single SNMP GET — returns raw value string or empty string. */
    private function get(string $oid): string
    {
        $this->requireExtension();

        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        snmp_set_quick_print(false);

        $timeout = 3_000_000;
        $retries = 1;

        $val = match ($this->version) {
            '1'  => @snmpget($this->host . ':' . $this->port, $this->community, $oid, $timeout, $retries),
            default => @snmp2_get($this->host . ':' . $this->port, $this->community, $oid, $timeout, $retries),
        };

        return is_string($val) ? $val : '';
    }

    private function getInt(string $oid): ?int
    {
        $raw = $this->get($oid);
        if (empty($raw)) return null;
        $v = $this->extractInt($raw);
        return $v !== null ? (int) $v : null;
    }

    // ── Parsing helpers ───────────────────────────────────────────────────

    /**
     * Parse OID suffix into [slot, port, ontId].
     * Handles both 3-part (slot.port.ontId) and 4-part (frame.slot.port.ontId) indices.
     */
    private function parseIndex(string $suffix): ?array
    {
        $parts = explode('.', $suffix);

        if (count($parts) === 3) {
            return [(int)$parts[0], (int)$parts[1], (int)$parts[2]];
        }
        if (count($parts) === 4) {
            // frame.slot.port.ontId — drop frame (usually 0)
            return [(int)$parts[1], (int)$parts[2], (int)$parts[3]];
        }

        return null;
    }

    /**
     * Parse FSP string "0/1/2" → [frame, slot, port]
     */
    private function parseFsp(string $fsp): array
    {
        $parts = array_map('intval', explode('/', $fsp));
        return count($parts) === 3 ? $parts : [0, 0, (int) $fsp];
    }

    /**
     * Convert SNMP hex-string SN to human-readable serial like "HWTC1234ABCD".
     * Raw SNMP value: "Hex-STRING: 48 57 54 43 12 34 AB CD"
     */
    private function parseSerial(string $raw): string
    {
        if (empty($raw)) return '';

        // Extract hex bytes
        if (preg_match('/Hex-STRING:\s*([0-9A-Fa-f\s]+)/i', $raw, $m)) {
            $bytes = array_filter(explode(' ', trim($m[1])));
            if (count($bytes) >= 8) {
                // First 4 bytes: ASCII vendor ID
                $vendor = '';
                for ($i = 0; $i < 4; $i++) {
                    $c = chr(hexdec($bytes[$i]));
                    $vendor .= ctype_print($c) ? $c : '?';
                }
                // Last 4 bytes: device ID as uppercase hex
                $device = strtoupper(implode('', array_slice($bytes, 4, 4)));
                return $vendor . $device;
            }
            // Shorter hex: just return uppercase
            return strtoupper(str_replace(' ', '', implode('', $bytes)));
        }

        // Already a plain string
        if (preg_match('/STRING:\s*"?([^"]+)"?/i', $raw, $m)) {
            return trim($m[1]);
        }

        return trim($raw);
    }

    /** Extract integer from SNMP value string like "INTEGER: 1". */
    private function extractInt(string $raw): ?string
    {
        if (preg_match('/:\s*(-?\d+)/', $raw, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Extract display string from SNMP value string like 'STRING: "Hello"'. */
    private function extractString(string $raw): string
    {
        if (preg_match('/STRING:\s*"?([^"]*)"?/i', $raw, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function requireExtension(): void
    {
        if (!extension_loaded('snmp')) {
            throw new RuntimeException(
                'PHP SNMP extension not loaded. Install with: sudo apt install php8.2-snmp && sudo systemctl restart php8.2-fpm'
            );
        }
    }
}
