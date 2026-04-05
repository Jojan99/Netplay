<?php

namespace App\Managers;

use App\Managers\Interfaces\SSHConnectionManagerInterface;
use phpseclib3\Net\SSH2;

class SSHConnectionManager implements SSHConnectionManagerInterface
{
    private $ssh;
    private $host;
    private $port;
    private $username;
    private $password;

    public function __construct()
    {
        $this->host = '10.11.104.2';
        $this->port = 22;
        $this->username = 'root';
        $this->password = '4dm1nn3t';
    }

    private function connect(): void
    {
        // Cierra la conexión previa si está activa
        if ($this->ssh && $this->ssh->isConnected()) {
            $this->ssh->disconnect();
        }

        // Intenta crear una nueva conexión SSH
        $this->ssh = new SSH2($this->host, $this->port);

        // Verifica las credenciales de inicio de sesión
        if (!$this->ssh->login($this->username, $this->password)) {
            throw new \Exception('SSH authentication failed');
        }
    }

    public function getSSH(): SSH2
    {
        // Vuelve a conectar si es necesario
        $this->connect();
        return $this->ssh;
    }

    public function closeConnection(): void
    {
        if ($this->ssh && $this->ssh->isConnected()) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }
    }
}