<?php

namespace App\Services;

/**
 * PHP stream wrapper that tunnels a TCP connection through SSH (-W ProxyJump).
 * Uses setsid + SSH_ASKPASS_REQUIRE=force to supply the password without
 * a terminal or sshpass.
 *
 * Usage:
 *   SshTunnelStream::register();
 *   $stream = fopen("sshtunnel://user:pass@jumpHost:22/oltHost:22", 'r+b');
 *   $ssh = new \phpseclib3\Net\SSH2($stream);
 */
class SshTunnelStream
{
    public const SCHEME = 'sshtunnel';

    /** @var resource */
    private $process;
    /** @var resource stdin  of the SSH process (we write here → goes to OLT) */
    private $stdin;
    /** @var resource stdout of the SSH process (we read here  ← comes from OLT) */
    private $stdout;
    /** @var resource|null (unused, needed by the interface) */
    public $context;

    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, static::class);
        }
    }

    // ── Stream wrapper protocol ────────────────────────────────────────────

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        // Parse: sshtunnel://jumpUser:jumpPass@jumpHost:jumpPort/oltHost:oltPort
        $parts = parse_url($path);
        if ($parts === false) return false;

        $jumpUser = rawurldecode($parts['user'] ?? '');
        $jumpPass = rawurldecode($parts['pass'] ?? '');
        $jumpHost = $parts['host'] ?? '';
        $jumpPort = $parts['port'] ?? 22;

        // The path after the host is "/oltHost:oltPort"
        $oltTarget = ltrim($parts['path'] ?? '', '/'); // "10.10.0.2:22"

        if (!$jumpUser || !$jumpHost || !$oltTarget) return false;

        // Temp script that echoes the password (SSH_ASKPASS mechanism)
        $askPass = tempnam(sys_get_temp_dir(), 'olt_askpass_');
        file_put_contents($askPass, "#!/bin/sh\nprintf '%s' " . escapeshellarg($jumpPass) . "\n");
        chmod($askPass, 0700);

        $cmd = sprintf(
            'setsid ssh'
            . ' -o StrictHostKeyChecking=no'
            . ' -o UserKnownHostsFile=/dev/null'
            . ' -o PasswordAuthentication=yes'
            . ' -o NumberOfPasswordPrompts=1'
            . ' -o ConnectTimeout=10'
            . ' -p %d'
            . ' %s@%s'
            . ' -W %s',
            (int) $jumpPort,
            escapeshellarg($jumpUser),
            escapeshellarg($jumpHost),
            escapeshellarg($oltTarget)
        );

        $env = array_merge($_ENV, [
            'SSH_ASKPASS'         => $askPass,
            'SSH_ASKPASS_REQUIRE' => 'force',
            'DISPLAY'             => ':0.0',
        ]);

        $this->process = proc_open($cmd, [
            0 => ['pipe', 'r'],          // stdin  → we write  → OLT
            1 => ['pipe', 'w'],          // stdout ← we read   ← OLT
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, null, $env);

        @unlink($askPass);

        if (!is_resource($this->process)) return false;

        $this->stdin  = $pipes[0];
        $this->stdout = $pipes[1];

        stream_set_blocking($this->stdin,  false);
        stream_set_blocking($this->stdout, false);

        // Give SSH time to authenticate
        usleep(800_000);

        return true;
    }

    public function stream_read(int $count): string|false
    {
        // Block until data is available (phpseclib expects blocking reads)
        $read = [$this->stdout]; $write = []; $except = [];
        if (stream_select($read, $write, $except, 10) < 1) return '';
        return fread($this->stdout, $count) ?: '';
    }

    public function stream_write(string $data): int
    {
        $written = fwrite($this->stdin, $data);
        return $written === false ? 0 : $written;
    }

    public function stream_eof(): bool
    {
        if (!is_resource($this->process)) return true;
        $status = proc_get_status($this->process);
        return !$status['running'];
    }

    /**
     * Called by PHP's stream_select() to get the native FD for OS-level select().
     * We expose stdout (readable end) so phpseclib can wait for server data.
     */
    public function stream_cast(int $cast_as)
    {
        return $this->stdout;
    }

    public function stream_close(): void
    {
        if (is_resource($this->stdin))  { fclose($this->stdin);  }
        if (is_resource($this->stdout)) { fclose($this->stdout); }
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);
            proc_close($this->process);
        }
    }

    public function stream_stat(): array { return []; }
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { return false; }
}
