<?php

namespace App\Console\Commands;

use App\Models\OltAdmin;
use App\Services\OltConnectionFactory;
use App\OltDrivers\HuaweiOltDriver;
use Illuminate\Console\Command;

class TestOltServicePorts extends Command
{
    protected $signature = 'olt:test-sp {olt_id=5} {--enable-pass=}';
    protected $description = 'Debug service-port on OLT — one connection only';

    public function handle()
    {
        $oltId      = (int) $this->argument('olt_id');
        $enablePass = $this->option('enable-pass');
        $olt        = OltAdmin::find($oltId);

        if (!$olt) { $this->error("OLT {$oltId} not found"); return 1; }

        $factory = app(OltConnectionFactory::class);
        $this->info("Connecting to {$olt->name} ({$olt->host}:{$olt->port})...");
        $ssh = $factory->connect($olt);

        $config = $olt->toArray();
        $config['enable_password'] = $enablePass ?? ($olt->enable_password ?? null);

        // Constructor drains banner. If enable_password configured, enters enable mode.
        new HuaweiOltDriver($ssh, $config);
        $this->info("Session ready.\n");

        $run = function(string $cmd) use ($ssh) {

    // 🔥 limpiar comando
    $cmd = preg_replace('/\s+/', ' ', trim($cmd));

    echo "\n============================\n";
    echo "CMD: {$cmd}\n";
    echo "============================\n";

    $ssh->setTimeout(20);

    // limpiar buffer
    try {
        $ssh->read('/[>#]/');
    } catch (\Throwable $e) {}

    // 🔥 enviar limpio
    $ssh->write($cmd . "\r\n");

    $output = '';

    try {
        while (true) {

            $chunk = $ssh->read('/(?:[>#]\s*$|---- More ----|\{\s*<cr>|\}:)/');

            $output .= $chunk;

            if (str_contains($chunk, '---- More ----')) {
                $ssh->write(" ");
                continue;
            }

            if (
                preg_match('/\{\s*<cr>/', $chunk) ||
                str_contains($chunk, '}:')
            ) {
                $ssh->write("\r\n");
                continue;
            }

            if (preg_match('/[>#]\s*$/', $chunk)) {
                break;
            }
        }

    } catch (\Throwable $e) {}

    // limpiar basura
    $output = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $output);
    $output = preg_replace('/\x07/', '', $output);

    echo "OUTPUT:\n";
    echo trim($output) . "\n";

    return $output;
};

        $run("display version");
        $run("display service-port all");
        $run("display service-port port 0/0/0 ont 1");

        // Close explicitly
        if (method_exists($ssh, 'close')) $ssh->close();

        return 0;
    }
}
