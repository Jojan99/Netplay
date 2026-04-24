<?php

namespace App\Services;

use App\Models\OltAdmin;
use phpseclib3\Net\SSH2;
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
private const OID_ONT_SN         = '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3';    // + .<ONT_ID>.0 → Serial hex 8 bytes
private const OID_ONT_CTRL       = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.6';    // + .<ONT_ID>.0 → 0=active,1=deactivate
private const OID_ONT_DESC       = '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.9';   // + .<ONT_ID>.0 → String descripción
private const OID_ONT_RUNSTATE   = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15';   // + .<ONT_ID>.0 → 1=online,2=offline
private const OID_ONT_CFGSTATE   = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.16';   // + .<ONT_ID>.0 → 0=initial,1=config,2=working
private const OID_ONT_MATCHSTATE = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.17';   // + .<ONT_ID>.0 → 0=initial,1=match,2=unmatch

    // hwGponOntDiscovTable: 1.3.6.1.4.1.2011.6.128.1.1.2.48 — ONTs descubiertas, no registradas
private const OID_DISCOV_SN   = '1.3.6.1.4.1.2011.6.128.1.1.2.48.1.2'; // Serial (Hex-STRING 8 bytes)
private const OID_DISCOV_PORT = '1.3.6.1.4.1.2011.6.128.1.1.2.48.1.4'; // ifIndex del puerto GPON

    // hwGponOntOptInfoTable: 1.3.6.1.4.1.2011.6.128.1.1.2.47
    // Values are INTEGER × 0.01 dBm  (e.g. -2030 → -20.30 dBm)
private const OID_OPT_OLT_RX    = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.3'; // + .<ONT_ID>.0 → OLT Rx from ONT (×0.01 dBm)
private const OID_OPT_ONT_TX    = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4'; // + .<ONT_ID>.0 → ONT Tx (×0.01 dBm)
private const OID_OPT_ONT_RX    = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.5'; // + .<ONT_ID>.0 → ONT Rx from OLT (×0.01 dBm)
private const OID_OPT_TEMP      = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.6'; // + .<ONT_ID>.0 → Temperatura ×1 °C
private const OID_OPT_VOLTAGE   = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.7'; // + .<ONT_ID>.0 → Voltaje ×0.001 V
private const OID_OPT_BIAS      = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.8'; // + .<ONT_ID>.0 → Corriente láser ×0。01 mA

    private string  $host;
    private string  $community;
    private string  $version;
    private int     $port;
    private ?string $jumpHost;
    private ?int    $jumpPort;
    private ?string $jumpUser;
    private ?string $jumpPass;

    public function __construct(OltAdmin $olt)
    {
        $this->host      = $olt->snmp_host ?: $olt->host;
        $this->community = $olt->snmp_community ?: 'public';
        $this->version   = $olt->snmp_version   ?: '2c';
        $this->port      = (int) ($olt->snmp_port ?: 161);

        // Si snmp_host está configurado explícitamente, usamos SNMP directo (PHP extension).
        // Si snmp_jump_host está configurado, usamos ese como jump para SNMP en lugar del
        // jump_host de Telnet — útil cuando el router con acceso SNMP difiere del de Telnet.
        // Si ninguno está configurado y access_mode=jump, usamos jump_host normal.
        $hasExplicitSnmpHost = !empty(trim($olt->snmp_host ?? ''));
        $snmpJumpHost        = !empty(trim($olt->snmp_jump_host ?? '')) ? trim($olt->snmp_jump_host) : null;

        if ($hasExplicitSnmpHost) {
            // SNMP directo al snmp_host (ej. MikroTik con port forward UDP 161)
            $this->jumpHost = null;
            $this->jumpPort = 22;
            $this->jumpUser = null;
            $this->jumpPass = null;
        } elseif ($snmpJumpHost) {
            // Jump host dedicado para SNMP (distinto al jump host de Telnet)
            $this->jumpHost = $snmpJumpHost;
            $this->jumpPort = $olt->snmp_jump_port ? (int) $olt->snmp_jump_port : 22;
            $this->jumpUser = $olt->snmp_jump_user ?: ($olt->jump_user ?: null);
            $this->jumpPass = $olt->snmp_jump_pass ?: ($olt->jump_pass ?: null);
        } else {
            // Usar el mismo jump host de Telnet para SNMP
            $this->jumpHost = $olt->access_mode === 'jump' ? ($olt->jump_host ?: null) : null;
            $this->jumpPort = $olt->jump_port ? (int) $olt->jump_port : 22;
            $this->jumpUser = $olt->jump_user ?: null;
            $this->jumpPass = $olt->jump_pass ?: null;
        }
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Return all registered ONTs with run-state, serial, description.
     *
     * We walk the two parent table OIDs (.43 trace, .46 info) in a single
     * pass each rather than one request per column — this cuts Mikrotik
     * round-trips from 6 to 2.  If the first walk comes back empty (SNMP
     * not reachable on the OLT) we bail immediately so the Telnet fallback
     * in OltAdminUseCase kicks in without waiting for 5 more empty walks.
     */
    public function getAuthorizedONTs(): array
    {
        // Solo 3 walks de columnas específicas — sin rx_power (va en el detalle individual).
        $serials   = $this->walk(self::OID_ONT_SN);        // .43.1.3
        $descs     = $this->walk(self::OID_ONT_DESC);      // .43.1.9
        $runStates = $this->walk(self::OID_ONT_RUNSTATE);  // .46.1.15

        if (empty($serials)) {
            \Log::info('HuaweiSnmpReader: serial walk empty — SNMP likely unavailable');
            return [];
        }

        $onts = [];

        foreach ($serials as $oidSuffix => $rawSn) {
            $idx = $this->parseIndex($oidSuffix);
            if ($idx === null) continue;

            [$slot, $port, $ontId] = $idx;
            $run = (int) $this->extractInt($runStates[$oidSuffix] ?? 'INTEGER: 2');

            $onts[] = [
                'fsp'         => "0/{$slot}/{$port}",
                'ont_id'      => $ontId,
                'serial'      => $this->parseSerial($rawSn),
                'description' => $this->extractString($descs[$oidSuffix] ?? ''),
                'status'      => $run === 1 ? 'online' : 'offline',
            ];
        }

        usort($onts, fn($a, $b) => strcmp($a['fsp'], $b['fsp']) ?: $a['ont_id'] <=> $b['ont_id']);

        return $onts;
    }

    /**
     * Return detailed info + optical readings for a single ONT.
     */
public function getOntInfo(string $fsp, int $ontId): array
{
    // Parse FSP (frame/slot/port)
    [$frame, $slot, $port] = $this->parseFsp($fsp);

    // Primero obtenemos el ifIndex del puerto GPON usando snmpwalk cache o tabla
    // Esto depende de tu implementación, pero normalmente mapeas 0/0/2 → 4194304256
    $ifIndex = $this->getIfIndexByFsp($fsp); // debes implementar este método o tener un array de mapeo

    if (!$ifIndex) {
        return [
            'fsp' => $fsp,
            'ont_id' => $ontId,
            'serial' => '',
            'description' => '',
            'status' => 'offline',
            'error' => 'IFIndex no encontrado para este puerto'
        ];
    }

    // El sufijo SNMP ahora es ifIndex.ontId
    $suffix = "{$ifIndex}.{$ontId}";

    // Datos básicos de la ONT
    $info = [
        'fsp'    => $fsp,
        'ont_id' => $ontId,
        'serial' => $this->parseSerial($this->get(self::OID_ONT_SN . '.' . $suffix)),
        'description' => $this->extractString($this->get(self::OID_ONT_DESC . '.' . $suffix)),
        'status'      => $this->extractInt($this->get(self::OID_ONT_RUNSTATE . '.' . $suffix)) == 1 ? 'online' : 'offline',
    ];

    // Datos ópticos
    $oltRx  = $this->getInt(self::OID_OPT_OLT_RX  . '.' . $suffix);
    $ontTx  = $this->getInt(self::OID_OPT_ONT_TX  . '.' . $suffix);
    $ontRx  = $this->getInt(self::OID_OPT_ONT_RX  . '.' . $suffix);
    $temp   = $this->getInt(self::OID_OPT_TEMP    . '.' . $suffix);
    $volt   = $this->getInt(self::OID_OPT_VOLTAGE . '.' . $suffix);
    $bias   = $this->getInt(self::OID_OPT_BIAS    . '.' . $suffix);

    // Conversión de unidades
    if ($oltRx !== null && $oltRx !== -32768) $info['olt_rx_power']  = round($oltRx / 100, 2);
    if ($ontTx !== null && $ontTx !== -32768) $info['tx_power']      = round($ontTx / 100, 2);
    if ($ontRx !== null && $ontRx !== -32768) $info['rx_power']      = round($ontRx / 100, 2);
    if ($temp   !== null)                     $info['temperature']   = $temp;
    if ($volt   !== null && $volt > 0)        $info['voltage']       = round($volt / 1000, 3);
    if ($bias   !== null && $bias > 0)        $info['laser_current'] = round($bias / 100, 2);

    return $info;
}

    /**
     * Return all unregistered/unauthenticated ONTs discovered on any GPON port.
     *
     * Walks hwGponOntDiscovTable (.48.1.2) — one walk, suffix is <ifIndex>.<discovId>.
     * ifIndex encodes the port via decodeIfIndex(); discovId is a per-port counter.
     * Deduplicates by serial in case the same ONT appears under multiple discovery IDs.
     */
    public function getUnauthONTs(): array
    {
        $serialsRaw = $this->walk(self::OID_DISCOV_SN);

        if (empty($serialsRaw)) {
            \Log::info('HuaweiSnmpReader: unauth discovery walk empty');
            return [];
        }

        $onts = [];
        foreach ($serialsRaw as $suffix => $rawSn) {
            $parts = explode('.', $suffix);
            if (count($parts) < 2) continue;

            $ifIndex = (int) $parts[0];
            $fsp     = $this->decodeIfIndex($ifIndex);
            if ($fsp === null) continue;

            [$slot, $port] = $fsp;
            $serial = $this->parseSerial($rawSn);
            if (empty($serial)) continue;

            $onts[] = [
                'fsp'    => "0/{$slot}/{$port}",
                'serial' => $serial,
            ];
        }

        // Deduplicar por serial (puede aparecer en múltiples discovery IDs)
        $seen   = [];
        $unique = [];
        foreach ($onts as $ont) {
            if (!isset($seen[$ont['serial']])) {
                $seen[$ont['serial']] = true;
                $unique[] = $ont;
            }
        }

        usort($unique, fn($a, $b) => strcmp($a['fsp'], $b['fsp']));

        return $unique;
    }

protected function getIfIndexByFsp(string $fsp): ?int
{
    // Calcula ifIndex dinámicamente usando la misma fórmula inversa de decodeIfIndex.
    // base=0xFA000000, portStep=256, portsPerSlot=8
    [$frame, $slot, $port] = $this->parseFsp($fsp);
    $portIndex = $slot * 8 + $port;
    return 0xFA000000 + $portIndex * 256;
}

    // ── SNMP primitives ───────────────────────────────────────────────────

    /**
     * Public diagnostic walk — returns raw [oid_suffix => raw_value_string].
     * Useful for debugging: confirm a table has data and inspect value format.
     */
    public function walkRaw(string $oid): array
    {
        return $this->walk($oid);
    }

    /**
     * SNMP walk: returns [oid_suffix => raw_value_string, ...]
     * suffix = everything after the base OID.
     *
     * If a jump host is configured, executes snmpwalk over SSH on the jump.
     */
    private function walk(string $oid): array
    {
        if ($this->jumpHost) {
            return $this->walkViaJump($oid);
        }

        $this->requireExtension();

        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        snmp_set_quick_print(false);

        $timeout = 5_000_000;
        $retries = 1;

        $real = match ($this->version) {
            '1'  => @snmprealwalk($this->host . ':' . $this->port, $this->community, $oid, $timeout, $retries),
            default => @snmp2_real_walk($this->host . ':' . $this->port, $this->community, $oid, $timeout, $retries),
        };

        if (empty($real)) {
            \Log::warning('SNMP walk failed', ['host' => $this->host, 'oid' => $oid]);
            return [];
        }

        $result  = [];
        $baseLen = strlen($oid) + 1;
        foreach ($real as $fullOid => $value) {
            $result[substr($fullOid, $baseLen)] = $value;
        }

        return $result;
    }

    /** Single SNMP GET — returns raw value string or empty string. */
    private function get(string $oid): string
    {
        if ($this->jumpHost) {
            return $this->getViaJump($oid);
        }

        $this->requireExtension();

        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        snmp_set_quick_print(false);

        $val = match ($this->version) {
            '1'  => @snmpget($this->host . ':' . $this->port, $this->community, $oid, 3_000_000, 1),
            default => @snmp2_get($this->host . ':' . $this->port, $this->community, $oid, 3_000_000, 1),
        };

        return is_string($val) ? $val : '';
    }

    // ── SSH jump SNMP helpers ─────────────────────────────────────────────

    /** Open an SSH session to the jump host (cached for the request lifetime). */
    private ?SSH2 $jumpSsh = null;

    private bool    $jumpIsMikrotik = false;

    private function jumpSsh(): SSH2
    {
        if ($this->jumpSsh && $this->jumpSsh->isConnected()) {
            return $this->jumpSsh;
        }

        $ssh = new SSH2($this->jumpHost, $this->jumpPort, 30);

        if (!$ssh->login($this->jumpUser, $this->jumpPass)) {
            throw new RuntimeException("SNMP via SSH: autenticación fallida en jump host {$this->jumpHost}");
        }

        // Detectar Mikrotik RouterOS por el banner SSH
        // Mikrotik usa "SSH-2.0-ROSSSH" como identificación de servidor
        $banner = $ssh->getServerIdentification() ?? '';
        \Log::info('Jump SSH banner', ['host' => $this->jumpHost, 'banner' => $banner]);

        $bannerLower = strtolower($banner);
        if (str_contains($bannerLower, 'mikrotik')
            || str_contains($bannerLower, 'routeros')
            || str_contains($bannerLower, 'rosssh')) {
            $this->jumpIsMikrotik = true;
            \Log::info('Jump host detected as Mikrotik via banner', ['banner' => $banner]);

            // Terminal ancha (500 cols) para que MikroTik muestre los OIDs completos.
            // Con 80 cols (default) trunca OIDs largos con "..." en la salida del walk.
            $ssh->setWindowSize(500, 50);
            $ssh->enablePTY();
            $ssh->setTimeout(15);
            // Abrir canal PTY y esperar prompt de Mikrotik "[admin@X] > "
            $ssh->read('/\]\s*>\s*$/');
        } else {
            // Probe con comando que falla en Mikrotik pero funciona en Linux
            $probe = $ssh->exec('echo __probe__ 2>/dev/null');
            \Log::info('Jump host probe result', ['probe' => $probe]);
            if (str_contains($probe, 'bad command')
                || str_contains($probe, 'syntax error')
                || str_contains($probe, 'expected end')
                || (!str_contains($probe, '__probe__') && trim($probe) === '')) {
                $this->jumpIsMikrotik = true;
                \Log::info('Jump host detected as Mikrotik via probe');

                // También habilitar PTY para este caso
                $ssh->setWindowSize(500, 50);
                $ssh->enablePTY();
                $ssh->setTimeout(15);
                $ssh->read('/\]\s*>\s*$/');
            }
        }

        $this->jumpSsh = $ssh;
        return $ssh;
    }

    /**
     * Run snmpwalk on the jump host and parse output.
     * On Mikrotik RouterOS, uses /tool snmp-walk instead of the snmpwalk CLI.
     */
    private function walkViaJump(string $oid): array
    {
        $ssh = $this->jumpSsh();

        if ($this->jumpIsMikrotik) {
            return $this->walkViaMikrotik($ssh, $oid);
        }

        $cmd = sprintf(
            'snmpwalk -v%s -c %s -On %s:%d %s 2>&1',
            escapeshellarg($this->version),
            escapeshellarg($this->community),
            escapeshellarg($this->host),
            $this->port,
            escapeshellarg($oid)
        );

        $output = $ssh->exec($cmd);

        // Detectar errores comunes de shell/herramienta no encontrada
        if (preg_match('/bad command|command not found|syntax error|expected end/i', $output)) {
            throw new RuntimeException('SNMP_JUMP_UNAVAILABLE: snmpwalk not available on jump host');
        }

        if (empty(trim($output))) {
            \Log::warning('SNMP walk via SSH returned empty', ['host' => $this->host, 'oid' => $oid]);
            return [];
        }

        return $this->parseSnmpWalkOutput($output, $oid);
    }

    /**
     * Run snmpget on the jump host and return raw value string.
     * On Mikrotik RouterOS, uses /tool snmp-get instead of snmpget CLI.
     */
    private function getViaJump(string $oid): string
    {
        $ssh = $this->jumpSsh();

        if ($this->jumpIsMikrotik) {
            return $this->getViaMikrotik($oid);
        }

        $cmd = sprintf(
            'snmpget -v%s -c %s -On %s:%d %s 2>/dev/null',
            escapeshellarg($this->version),
            escapeshellarg($this->community),
            escapeshellarg($this->host),
            $this->port,
            escapeshellarg($oid)
        );

        $output = trim($ssh->exec($cmd));

        // Output: ".1.3.6.1... = INTEGER: 1"  →  we want "INTEGER: 1"
        if (preg_match('/=\s*(.+)$/', $output, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Single SNMP GET via MikroTik RouterOS API (/tool snmp-get).
     * Used for leaf OIDs where snmp-walk returns nothing.
     */
    private function getViaMikrotik(string $oid): string
    {
        $mikrotikVersion = ($this->version === '2c') ? '2' : $this->version;

        $client = new \RouterOS\Client([
            'host'           => $this->jumpHost,
            'user'           => $this->jumpUser,
            'pass'           => $this->jumpPass,
            'port'           => 8728,
            'timeout'        => 30,
            'socket_timeout' => 30,
        ]);

        $query = (new \RouterOS\Query('/tool/snmp-get'))
            ->add('=address='   . $this->host)
            ->add('=community=' . $this->community)
            ->add('=oid='       . $oid)
            ->add('=version='   . $mikrotikVersion);

        $rows = $client->query($query)->read();

        if (empty($rows)) {
            return '';
        }

        // RouterOS returns a record like: ['oid'=>'...', 'type'=>'integer', 'value'=>'1']
        $row = reset($rows);
        $type  = strtolower(trim($row['type']  ?? ''));
        $value = trim($row['value'] ?? '');

        return $this->normalizeTabularValue($type, $value);
    }

    /**
     * Run an SNMP walk via MikroTik using the RouterOS API (port 8728).
     *
     * The RouterOS API returns structured records with full OIDs — no SSH/PTY
     * buffering issues, no OID truncation.  Uses the evilfreelancer/routeros-api-php
     * library already present in the project.
     */
    private function walkViaMikrotik(SSH2 $ssh, string $oid): array
    {
        $mikrotikVersion = ($this->version === '2c') ? '2' : $this->version;

        \Log::info('Mikrotik SNMP walk via RouterOS API', ['oid' => $oid, 'host' => $this->jumpHost]);

        $client = new \RouterOS\Client([
            'host'           => $this->jumpHost,
            'user'           => $this->jumpUser,
            'pass'           => $this->jumpPass,
            'port'           => 8728,
            'timeout'        => 200,
            'socket_timeout' => 200,
        ]);

        $query = (new \RouterOS\Query('/tool/snmp-walk'))
            ->add('=address='   . $this->host)
            ->add('=community=' . $this->community)
            ->add('=oid='       . $oid)
            ->add('=version='   . $mikrotikVersion);

        $rows = $client->query($query)->read();

        \Log::info('Mikrotik API walk result', [
            'oid'     => $oid,
            'count'   => count($rows),
            'sample'  => array_slice($rows, 0, 3),
        ]);

        if (empty($rows)) {
            \Log::warning('Mikrotik API walk returned 0 rows', ['oid' => $oid]);
            return [];
        }

        // Each $row has keys like: 'oid' (or 'name'), 'type', 'value'
        // Normalize to the same [oid_suffix => "TYPE: value"] map that the
        // rest of the code expects.
        $baseOidClean = ltrim($oid, '.');
        $result = [];

        foreach ($rows as $row) {
            // Try common field names for the OID column
            $fullOid = ltrim($row['oid'] ?? $row['name'] ?? '', '.');
            $type    = strtolower(trim($row['type']  ?? ''));
            $value   = trim($row['value'] ?? '');

            if ($fullOid === '' || !str_starts_with($fullOid, $baseOidClean)) continue;

            $suffix = substr($fullOid, strlen($baseOidClean) + 1);
            if ($suffix === '' || $suffix === false) continue;

            $result[$suffix] = $this->normalizeTabularValue($type, $value);
        }

        return $result;
    }

    /**
     * Parse Mikrotik /tool snmp-walk output.
     *
     * Mikrotik RouterOS formats observed:
     *   Format A: ".1.3.6.1.4.1.xxx.yyy = 485754..."          (older RouterOS)
     *   Format B: name=".1.3...." type="..." value="..."       (RouterOS v7)
     *   Format C: "1.3.6.1.4.1.xxx.yyy    integer    1"        (tabular, seen on some builds)
     *             header line "OID   TYPE   VALUE" is skipped automatically
     */
    private function parseMikrotikSnmpOutput(string $output, string $baseOid): array
    {
        $result       = [];
        $baseOidClean = ltrim($baseOid, '.');

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $fullOid  = null;
            $rawValue = null;

            // Format A: ".1.3.6... = value" (most common)
            if (preg_match('/^\.?([\d.]+)\s*=\s*(.+)$/', $line, $m)) {
                $fullOid  = ltrim($m[1], '.');
                $rawValue = trim($m[2]);
            }
            // Format B: name="..." type="..." value="..."  (RouterOS v7)
            elseif (preg_match('/name="\.?([\d.]+)"\s+type="([^"]+)"\s+value="([^"]*)"/i', $line, $m)) {
                $fullOid  = ltrim($m[1], '.');
                $rawValue = $this->normalizeTabularValue(strtolower(trim($m[2])), trim($m[3]));
            }
            // Format C: "1.3.6.1.4.1.xxx   integer   1"  (tabular with space-separated columns)
            // First token must be all digits+dots (skips header line "OID  TYPE  VALUE")
            elseif (preg_match('/^([\d.]+)\s+(\S+)\s+(.*)$/', $line, $m)) {
                $fullOid  = ltrim($m[1], '.');
                $rawValue = $this->normalizeTabularValue(strtolower(trim($m[2])), trim($m[3]));
            }

            if ($fullOid === null || $rawValue === null) continue;
            // Skip rows where the OLT reports no instance (missing ONT slot, etc.)
            if (preg_match('/no such (object|instance)/i', $rawValue)) continue;
            if (!str_starts_with($fullOid, $baseOidClean)) continue;

            $suffix = substr($fullOid, strlen($baseOidClean) + 1);
            if ($suffix === '' || $suffix === false) continue;

            $result[$suffix] = $rawValue;
        }

        return $result;
    }

    /**
     * Normalize a MikroTik tabular value (Format B/C) given its explicit type.
     * Converts to the standard "TYPE: value" format used by the extract helpers.
     *
     *   integer  "1"                     → "INTEGER: 1"
     *   string   "Hello"                 → 'STRING: "Hello"'
     *   string   "485754433132333434"     → "Hex-STRING: 48 57 54 43 ..."  (if hex)
     *   hex-string "48 57 54 43 ..."     → "Hex-STRING: 48 57 54 43 ..."
     */
    private function normalizeTabularValue(string $type, string $value): string
    {
        switch ($type) {
            case 'integer':
            case 'integer32':
            case 'unsigned32':
            case 'gauge32':
            case 'counter32':
            case 'counter64':
            case 'timeticks':
                return 'INTEGER: ' . $value;

            case 'hex-string':
            case 'octet-string':
                // Value may already have spaces ("48 57 54") or be compact ("485754")
                $compact = strtoupper(str_replace(' ', '', $value));
                if ($compact !== '' && preg_match('/^[0-9A-F]+$/', $compact) && strlen($compact) % 2 === 0) {
                    return 'Hex-STRING: ' . implode(' ', str_split($compact, 2));
                }
                // Fall through: treat as plain string
                return 'STRING: "' . trim($value, '"') . '"';

            case 'string':
                // Check if value looks like compact hex (serial numbers come through as hex)
                $compact = str_replace(' ', '', $value);
                if (strlen($compact) >= 2 && strlen($compact) % 2 === 0
                    && preg_match('/^[0-9A-Fa-f]+$/', $compact)) {
                    return 'Hex-STRING: ' . implode(' ', str_split(strtoupper($compact), 2));
                }
                // Si el string tiene bytes no-UTF8 (serial binario), preservar como hex
                // para evitar que iconv descarte bytes y corrompa el serial.
                if (!mb_check_encoding($value, 'UTF-8')) {
                    return 'Hex-STRING: ' . implode(' ', str_split(strtoupper(bin2hex($value)), 2));
                }
                $safe = iconv('UTF-8', 'UTF-8//IGNORE', $value) ?: '';
                return 'STRING: "' . trim($safe, '"') . '"';

            case 'ip-address':
                return 'IpAddress: ' . $value;

            case 'oid':
                return 'OID: ' . $value;

            default:
                return $this->normalizeMikrotikValue($value);
        }
    }

    /**
     * Normalize a Mikrotik SNMP value to standard snmpwalk format so existing
     * extractInt() / extractString() / parseSerial() helpers work unchanged.
     *
     *   "485754433132333434"  →  "Hex-STRING: 48 57 54 43 31 32 33 34 34"
     *   "1"                   →  "INTEGER: 1"
     *   anything else         →  returned as-is (already normalized or string)
     */
    private function normalizeMikrotikValue(string $value): string
    {
        // Already in standard format (e.g. from non-Mikrotik fallback)
        if (preg_match('/^(INTEGER|STRING|Hex-STRING|OID|Gauge32|Counter32|Timeticks):/i', $value)) {
            return $value;
        }

        // Pure hex string (even length, only hex chars) → Hex-STRING
        if (preg_match('/^[0-9A-Fa-f]+$/', $value) && strlen($value) % 2 === 0 && strlen($value) >= 2) {
            $spaced = implode(' ', str_split(strtoupper($value), 2));
            return 'Hex-STRING: ' . $spaced;
        }

        // Pure integer (possibly negative)
        if (preg_match('/^-?\d+$/', $value)) {
            return 'INTEGER: ' . $value;
        }

        // Quoted string
        if (preg_match('/^"(.*)"$/', $value, $m)) {
            return 'STRING: "' . $m[1] . '"';
        }

        return $value;
    }

    /**
     * Parse snmpwalk CLI output into [oid_suffix => raw_value] map.
     * Each line looks like: .1.3.6.1.4.1.xxx.yyy = INTEGER: 1
     */
    private function parseSnmpWalkOutput(string $output, string $baseOid): array
    {
        $result  = [];
        $baseLen = strlen($baseOid) + 1; // +1 for the dot separator

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Split on first " = "
            $eqPos = strpos($line, ' = ');
            if ($eqPos === false) continue;

            $fullOid  = trim(substr($line, 0, $eqPos));
            $rawValue = trim(substr($line, $eqPos + 3));

            // Strip leading dot from OID if present
            $fullOid = ltrim($fullOid, '.');
            $baseOidClean = ltrim($baseOid, '.');

            if (!str_starts_with($fullOid, $baseOidClean)) continue;

            $suffix = substr($fullOid, strlen($baseOidClean) + 1);
            $result[$suffix] = $rawValue;
        }

        return $result;
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
     * Split a parent-table walk result into a per-column map.
     *
     * When we walk "parent.1" the returned suffix is "<col>.<index...>".
     * This helper keeps only rows whose first segment matches $column and
     * returns them with the column prefix stripped:
     *   ["<col>.<index>" => value]  →  ["<index>" => value]
     */
    private function extractTableColumn(array $tableWalk, int $column): array
    {
        $prefix = $column . '.';
        $out    = [];
        foreach ($tableWalk as $suffix => $value) {
            if (str_starts_with((string) $suffix, $prefix)) {
                $out[substr($suffix, strlen($prefix))] = $value;
            }
        }
        return $out;
    }

    /**
     * Parse OID suffix into [slot, port, ontId].
     *
     * Huawei formats observed in the wild:
     *   3 parts  → slot.port.ontId               (some firmware)
     *   4 parts  → frame.slot.port.ontId          (MA5600T common)
     *   2 parts  → ifIndex.ontId  (large ifIndex) — NOT supported here;
     *              use walkIfDescr() mapping if you hit this format.
     */
    private function parseIndex(string $suffix): ?array
    {
        $parts = explode('.', $suffix);

        if (count($parts) === 2) {
            // ifIndex.ontId format (Huawei MA5600T/MA5800 con firmware reciente)
            // ifIndex codifica frame/slot/port en un entero grande (base 0xFA000000)
            $fsp = $this->decodeIfIndex((int) $parts[0]);
            if ($fsp === null) return null;
            return [$fsp[0], $fsp[1], (int) $parts[1]];
        }
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
     * Decode a Huawei GPON port ifIndex into [slot, port].
     *
     * Huawei MA5600T/MA5800 GPON port ifIndex formula:
     *   base      = 0xFA000000 (4 194 304 000)
     *   step      = 128 per port
     *   per_slot  = 8 ports
     *
     *   ifIndex = base + (slot * 8 + port) * 128
     *
     * Verified against live OLT data:
     *   4 194 304 000 = 0/0/0  (offset 0,   port_idx 0, slot 0, port 0)
     *   4 194 304 256 = 0/0/2  (offset 256, port_idx 2, slot 0, port 2)
     */
    private function decodeIfIndex(int $ifIndex): ?array
    {
        $base         = 0xFA000000; // 4194304000
        $portStep     = 256;        // observed: ports are 256 apart (4194304256 - 4194304000)
        $portsPerSlot = 8;

        if ($ifIndex < $base) return null;

        $portIndex = intdiv($ifIndex - $base, $portStep);
        $slot      = intdiv($portIndex, $portsPerSlot);
        $port      = $portIndex % $portsPerSlot;

        return [$slot, $port]; // frame is always 0
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

        // Caso ideal: Hex-STRING: 48 57 54 43 09 18 0B AC → "4857544309180BAC"
        if (preg_match('/Hex-STRING:\s*([0-9A-Fa-f\s]+)/i', $raw, $m)) {
            return strtoupper(str_replace(' ', '', trim($m[1])));
        }

        if (preg_match('/STRING:\s*"(.*)"/s', $raw, $m)) {
            $content = $m[1];

            if (!mb_check_encoding($content, 'UTF-8')) {
                // Bytes crudos del PHP SNMP extension — bin2hex directo sin conversión
                return strtoupper(bin2hex($content));
            }

            // UTF-8 válido (RouterOS codificó los bytes como codepoints Latin-1).
            // mb_ord codepoint → & 0xFF recupera el byte original sin sustitución por '?'.
            $result = '';
            $len = mb_strlen($content, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                $cp = mb_ord(mb_substr($content, $i, 1, 'UTF-8'));
                $result .= sprintf('%02X', $cp & 0xFF);
            }
            return $result;
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
            $s = trim($m[1]);
            // Remove invalid UTF-8 bytes (device descriptions may contain latin-1 or binary)
            return iconv('UTF-8', 'UTF-8//IGNORE', $s) ?: '';
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
