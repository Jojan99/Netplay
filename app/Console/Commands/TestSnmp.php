<?php

namespace App\Console\Commands;

use App\Models\OltAdmin;
use App\Services\HuaweiSnmpReader;
use Illuminate\Console\Command;

class TestSnmp extends Command
{
    protected $signature = 'test:snmp {olt_id} {--raw} {--oid=}';
    protected $description = 'Test SNMP queries for an OLT. Use --raw [--oid=...] to dump a raw walk.';

    // Huawei base OID
    private const BASE = '1.3.6.1.4.1.2011.6.128.1.1.2';

    public function handle(): int
    {
        $oltId = $this->argument('olt_id');
        $olt   = OltAdmin::find($oltId);

        if (!$olt) {
            $this->error("OLT $oltId not found");
            return 1;
        }

        $this->info("OLT: {$olt->name} ({$olt->host})");
        $this->info("SNMP jump: {$olt->snmp_jump_host}:{$olt->snmp_jump_port} user={$olt->snmp_jump_user}");
        $this->line('');

        if ($this->option('raw')) {
            return $this->doRawWalk($olt);
        }

        return $this->doFullTest($olt);
    }

    // ── Raw walk mode ─────────────────────────────────────────────────────

    private function doRawWalk(OltAdmin $olt): int
    {
        $oid = $this->option('oid') ?: (self::BASE . '.43');

        $this->info("Raw walk: $oid");
        $this->line('');

        try {
            $reader = new HuaweiSnmpReader($olt);
            $rows   = $reader->walkRaw($oid);

            if (empty($rows)) {
                $this->warn('Walk returned 0 rows — table empty or SNMP unreachable.');
                $this->line('Check storage/logs/laravel.log for Mikrotik walk output.');
                return 1;
            }

            $this->info('Rows returned: ' . count($rows));
            $this->line('');

            // Print first 40 rows (table can be large)
            $shown = 0;
            foreach ($rows as $suffix => $value) {
                $this->line("  .$suffix  =  $value");
                if (++$shown >= 40) {
                    $this->line("  ... (showing first 40 of " . count($rows) . ")");
                    break;
                }
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    // ── Full test mode ────────────────────────────────────────────────────

    private function doFullTest(OltAdmin $olt): int
    {
        try {
            $this->info('1) Testing HuaweiSnmpReader::getAuthorizedONTs()...');
            $reader = new HuaweiSnmpReader($olt);
            $onts   = $reader->getAuthorizedONTs();

            $this->info('SNMP returned ' . count($onts) . ' ONTs');

            if (count($onts) > 0) {
                $this->table(
                    ['FSP', 'ONT ID', 'Serial', 'Status', 'Description'],
                    array_map(fn($o) => [
                        $o['fsp'], $o['ont_id'], $o['serial'], $o['status'], $o['description'],
                    ], array_slice($onts, 0, 5))
                );
                if (count($onts) > 5) {
                    $this->line('... and ' . (count($onts) - 5) . ' more');
                }

                $first = $onts[0];
                $this->line('');
                $this->info("2) Testing getOntInfo() for {$first['fsp']} ONT {$first['ont_id']}...");
                $info = $reader->getOntInfo($first['fsp'], $first['ont_id']);
                $this->line('  Serial:  ' . ($info['serial'] ?? 'n/a'));
                $this->line('  Status:  ' . ($info['status'] ?? 'n/a'));
                if (isset($info['olt_rx_power']))   $this->line('  OLT RX:  ' . $info['olt_rx_power'] . ' dBm');
                if (isset($info['tx_power']))        $this->line('  ONT TX:  ' . $info['tx_power']      . ' dBm');
                if (isset($info['rx_power']))        $this->line('  ONT RX:  ' . $info['rx_power']      . ' dBm');
                if (isset($info['temperature']))     $this->line('  Temp:    ' . $info['temperature']   . ' °C');
            } else {
                $this->warn('0 ONTs returned — check storage/logs/laravel.log for Mikrotik SNMP output');
                $this->line('Tip: run with --raw to inspect table .43 directly:');
                $this->line("  php artisan test:snmp $olt->id --raw");
                $this->line("  php artisan test:snmp $olt->id --raw --oid=" . self::BASE . '.46');
            }

            $this->line('');
            $this->info('Done.');
            return 0;
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
