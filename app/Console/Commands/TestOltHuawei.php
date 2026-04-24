<?php

namespace App\Console\Commands;

use App\Models\OltAdmin;
use App\Services\OltConnectionFactory;
use Illuminate\Console\Command;

class TestOltHuawei extends Command
{
    protected $signature = 'olt:test-huawei {olt_id=5}';
    protected $description = 'Test Huawei OLT (estable, sin romper sesión)';

    public function handle()
    {
        $oltId = (int) $this->argument('olt_id');
        $olt = OltAdmin::find($oltId);

        if (!$olt) {
            $this->error("OLT {$oltId} not found");
            return 1;
        }

        $this->info("🔌 Connecting to {$olt->name} ({$olt->host}:{$olt->port})...");

        $factory = app(OltConnectionFactory::class);
        $ssh = $factory->connect($olt);

        // 🔥 limpiar banner inicial
        $ssh->setTimeout(10);
        try {
            $ssh->read('/[>#]/');
        } catch (\Throwable $e) {}

        $this->info("✅ Session ready\n");

        // =====================================
        // 🔥 FUNCIÓN PRO EXEC HUAWEI
        // =====================================
        $exec = function(string $cmd) use ($ssh) {

            echo "\n============================\n";
            echo "CMD: {$cmd}\n";
            echo "============================\n";

            $ssh->setTimeout(20);

            // 🔥 limpiar buffer antes
            try {
                $ssh->read('/[>#]/');
            } catch (\Throwable $e) {}

            // enviar comando
            $ssh->write($cmd . "\r\n");

            $output = '';

            try {
                while (true) {

                    $chunk = $ssh->read('/(?:[>#]\s*$|---- More ----|\{\s*<cr>|\}:)/');

                    $output .= $chunk;

                    // 🔥 paginado
                    if (str_contains($chunk, '---- More ----')) {
                        $ssh->write(" ");
                        continue;
                    }

                    // 🔥 confirmaciones Huawei
                    if (
                        preg_match('/\{\s*<cr>/', $chunk) ||
                        str_contains($chunk, '}:')
                    ) {
                        $ssh->write("\r\n");
                        continue;
                    }

                    // 🔥 fin real del comando
                    if (preg_match('/[>#]\s*$/', $chunk)) {
                        break;
                    }
                }

            } catch (\Throwable $e) {}

            // 🔥 limpiar basura
            $output = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $output);
            $output = preg_replace('/\x07/', '', $output);
            $output = preg_replace('/\s*----\s*More\s*----\s*/i', "\n", $output);

            echo "OUTPUT:\n";
            echo trim($output) . "\n";

            return $output;
        };

        // =====================================
        // 🔥 PRUEBAS
        // =====================================

        //$exec("display version");

        //$exec("display service-port all");

        $exec("display service-port port 0/0/0 ont 1");

        // =====================================
        // 🔒 cerrar conexión
        // =====================================
        if (method_exists($ssh, 'close')) {
            $ssh->close();
        }

        $this->info("\n🔒 Connection closed");

        return 0;
    }
}