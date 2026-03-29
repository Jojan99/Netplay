<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

class SSHConnectionService
{
    private static ?SSH2 $sshInstance = null;
    private static ?int $connectionTime = null;
    private static int $connectionTTL = 120; // segundos

    private $host;
    private $port;
    private $username;
    private $password;

    public function __construct()
    {
        $this->host = config('ssh.default.host', '10.11.104.2');
        $this->port = config('ssh.default.port', 22);
        $this->username = config('ssh.default.username', 'root');
        $this->password = config('ssh.default.password', 'admin');
    }

    public function getConnection(): SSH2
    {
        // Validar si ya existe conexión y sigue viva y no ha expirado
        if (
            self::$sshInstance &&
            $this->isConnectionAlive(self::$sshInstance) &&
            (time() - self::$connectionTime) < self::$connectionTTL
        ) {
            Log::info('Reutilizando conexión SSH (singleton en memoria)');
            error_log("Reutilizando conexion");
            return self::$sshInstance;
        }

        Log::info('Creando nueva conexión SSH');
            error_log("nueva conexion");

        self::$sshInstance = $this->createNewConnection();
        self::$connectionTime = time();
        return self::$sshInstance;
    }

    private function isConnectionAlive(SSH2 $ssh): bool
    {
        try {
            $ssh->setTimeout(2);
            $output = $ssh->exec("echo 'test'");
            return !empty(trim($output));
        } catch (\Exception $e) {
            Log::warning('Conexión SSH no responde: ' . $e->getMessage());
            return false;
        }
    }

    private function createNewConnection(): SSH2
    {
        $ssh = new SSH2($this->host, $this->port);
        $ssh->setTimeout(5);

        if (!$ssh->login($this->username, $this->password)) {
            throw new Exception('Autenticación SSH fallida');
        }

        return $ssh;
    }

    public function executeCommands(array $commands): string
    {
        $ssh = $this->getConnection();
        $output = '';

        foreach ($commands as $command) {
            Log::debug("Ejecutando comando: $command");
            $output .= $ssh->exec($command);
            usleep(500000); // Esperar 0.5 segundos
        }

        return $output;
    }

    public function closeConnection(): void
    {
        if (self::$sshInstance instanceof SSH2) {
            try {
                self::$sshInstance->disconnect();
                Log::info('Conexión SSH cerrada manualmente');
            } catch (\Exception $e) {
                Log::warning('Error cerrando conexión SSH: ' . $e->getMessage());
            }
        }

        self::$sshInstance = null;
        self::$connectionTime = null;
    }

    public function getConnectionStatus(): array
    {
        return [
            'has_connection' => self::$sshInstance !== null,
            'connection_alive' => self::$sshInstance ? $this->isConnectionAlive(self::$sshInstance) : false,
            'ttl_remaining' => self::$connectionTime ? (self::$connectionTTL - (time() - self::$connectionTime)) : 0,
        ];
    }
}
