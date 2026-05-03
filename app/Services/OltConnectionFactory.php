<?php

namespace App\Services;

use App\Models\OltAdmin;
use phpseclib3\Net\SSH2;
use RuntimeException;

class OltConnectionFactory
{
    /**
     * Returns an authenticated session to the OLT.
     *
     * - Port 23 → Telnet (TelnetConnection)
     * - Port 22 / other → SSH (phpseclib SSH2)
     *
     * Both classes expose the same read/write/enablePTY/setTimeout interface
     * used by HuaweiOltDriver.
     */
    public function connect(OltAdmin $olt): object
    {
        \Illuminate\Support\Facades\Log::info('OLT CONNECTION ATTEMPT', [
            'olt_id' => $olt->id,
            'olt_name' => $olt->name,
            'host' => $olt->host,
            'port' => $olt->port,
            'username' => $olt->username,
            'access_mode' => $olt->access_mode,
        ]);

        // Telnet (port 23) — also supports jump host
        if ((int) $olt->port === 23) {
            \Illuminate\Support\Facades\Log::info('OLT TELNET CONNECTION', [
                'host'        => $olt->host,
                'port'        => 23,
                'access_mode' => $olt->access_mode,
                'jump_host'   => $olt->jump_host ?? null,
            ]);
            return $this->connectTelnet($olt);
        }

        // SSH (any other port)
        \Illuminate\Support\Facades\Log::info('OLT SSH CONNECTION', [
            'host'        => $olt->host,
            'port'        => $olt->port,
            'access_mode' => $olt->access_mode,
        ]);

        $ssh = match ($olt->access_mode) {
            'jump'  => $this->connectViaJump($olt),
            default => $this->connectDirect($olt),
        };

        if (!$ssh->login($olt->username, $olt->password)) {
            \Illuminate\Support\Facades\Log::error('OLT SSH AUTHENTICATION FAILED', [
                'host' => $olt->host,
                'username' => $olt->username,
            ]);
            throw new RuntimeException("OLT SSH authentication failed for {$olt->host}");
        }

        \Illuminate\Support\Facades\Log::info('OLT SSH AUTHENTICATION SUCCESSFUL', [
            'host' => $olt->host,
        ]);

        return $ssh;
    }

    // ── Telnet ────────────────────────────────────────────────────────────

    private function connectTelnet(OltAdmin $olt): TelnetConnection
    {
        \Illuminate\Support\Facades\Log::info('TELNET CONNECTION START', [
            'host' => $olt->host,
            'port' => $olt->port,
            'username' => $olt->username,
        ]);

        $stream = match ($olt->access_mode) {
            'jump'  => $this->openJumpStream($olt),
            default => $this->openDirectTcpStream($olt),
        };

        \Illuminate\Support\Facades\Log::info('TELNET STREAM OPENED', [
            'host' => $olt->host,
            'port' => $olt->port,
        ]);

        $telnet = new TelnetConnection($stream);
        $telnet->setTimeout(30);
        
        try {
            $telnet->login($olt->username, $olt->password);
            \Illuminate\Support\Facades\Log::info('TELNET LOGIN SUCCESSFUL', [
                'host' => $olt->host,
            ]);
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Log::error('TELNET LOGIN FAILED', [
                'host' => $olt->host,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $telnet;
    }

    private function openDirectTcpStream(OltAdmin $olt)
    {
        \Illuminate\Support\Facades\Log::info('OPENING TCP STREAM', [
            'host' => $olt->host,
            'port' => $olt->port,
        ]);

        $stream = stream_socket_client(
            "tcp://{$olt->host}:{$olt->port}",
            $errno,
            $errstr,
            30
        );

        if (!is_resource($stream)) {
            \Illuminate\Support\Facades\Log::error('TCP STREAM FAILED', [
                'host' => $olt->host,
                'port' => $olt->port,
                'error' => $errstr,
                'errno' => $errno,
            ]);
            throw new RuntimeException(
                "Cannot connect to {$olt->host}:{$olt->port}: {$errstr} ({$errno})"
            );
        }

        \Illuminate\Support\Facades\Log::info('TCP STREAM OPENED', [
            'host' => $olt->host,
            'port' => $olt->port,
        ]);

        return $stream;
    }

    // ── SSH ───────────────────────────────────────────────────────────────

    private function connectDirect(OltAdmin $olt): SSH2
    {
        return new SSH2($olt->host, $olt->port, 30);
    }

    private function connectViaJump(OltAdmin $olt): SSH2
    {
        return new SSH2($this->openJumpStream($olt));
    }

    // ── Shared: open raw TCP stream through SSH jump ──────────────────────

    private function openJumpStream(OltAdmin $olt)
    {
        SshTunnelStream::register();

        $url = sprintf(
            '%s://%s:%s@%s:%d/%s:%d',
            SshTunnelStream::SCHEME,
            rawurlencode($olt->jump_user),
            rawurlencode($olt->jump_pass),
            $olt->jump_host,
            $olt->jump_port ?? 22,
            $olt->host,
            $olt->port
        );

        $stream = fopen($url, 'r+b');

        if (!is_resource($stream)) {
            throw new RuntimeException(
                "Could not open SSH tunnel through {$olt->jump_host} to {$olt->host}"
            );
        }

        return $stream;
    }
}
