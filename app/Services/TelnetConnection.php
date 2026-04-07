<?php

namespace App\Services;

use RuntimeException;

/**
 * Minimal Telnet client that exposes the same read/write interface
 * as phpseclib SSH2 PTY mode, so HuaweiOltDriver works unchanged.
 *
 * Usage (via OltConnectionFactory):
 *   $telnet = new TelnetConnection($stream);
 *   $telnet->setTimeout(30);
 *   $telnet->login('admin', 'pass');
 *   // Now the driver can call enablePTY/setTimeout/read/write normally.
 */
class TelnetConnection
{
    private int    $timeout = 30;
    private string $buffer  = '';

    public function __construct(private $stream)
    {
        // stream_set_blocking is intentionally NOT called here.
        // SshTunnelStream::stream_read has its own 10-second select timeout,
        // and stream_set_blocking on a user-space wrapper is a no-op anyway.
    }

    // ── phpseclib SSH2 PTY-compatible interface ───────────────────────────

    public function enablePTY(): void {}   // no-op for Telnet

    public function setTimeout(int $seconds): void
    {
        $this->timeout = $seconds;
    }

    /**
     * Accumulate stream data until $pattern (regex) matches, then return
     * everything accumulated. On timeout returns whatever was buffered.
     *
     * Reads via fread() which delegates to SshTunnelStream::stream_read()
     * (already has an internal 10-second select). We poll in a loop until
     * the pattern matches or the overall timeout expires.
     */
    public function read(string $pattern): string
    {
        $deadline = microtime(true) + $this->timeout;

        while (true) {
            if (preg_match($pattern, $this->buffer)) {
                $out          = $this->buffer;
                $this->buffer = '';
                return $out;
            }

            if (microtime(true) >= $deadline) {
                $out          = $this->buffer;
                $this->buffer = '';
                return $out;
            }

            // fread calls SshTunnelStream::stream_read which internally
            // waits up to 10 s for data via stream_select on the SSH pipe.
            $chunk = @fread($this->stream, 4096);
            if ($chunk !== false && $chunk !== '') {
                $this->buffer .= $this->stripIac($chunk);
            } else {
                usleep(50_000); // 50 ms back-off when no data available
            }
        }
    }

    /** Write raw bytes to the Telnet stream. */
    public function write(string $data): void
    {
        @fwrite($this->stream, $data);
    }

    // ── Login sequence ────────────────────────────────────────────────────

    /**
     * Handle Telnet login: wait for Username/Password prompts, send credentials.
     * Stops after sending the password — the driver's first read() will capture
     * the CLI prompt.
     */
    public function login(string $username, string $password): void
    {
        // Huawei OLT variants: "User name:", "Username:", "Login:"
        $out = $this->read('/(?:User\s*name|Login|Username)\s*[:\>]\s*$/i');
        if (empty(trim($out))) {
            throw new RuntimeException('Telnet: timed out waiting for username prompt. Received: ' . json_encode($out));
        }
        $this->write($username . "\r\n");

        // Wait for password prompt
        $out = $this->read('/Password\s*[:\>]\s*$/i');
        if (empty(trim($out))) {
            throw new RuntimeException('Telnet: timed out waiting for password prompt. Received: ' . json_encode($out));
        }

        $this->write($password . "\r\n");
        // CLI prompt will arrive shortly; driver constructor will read() it.
    }

    // ── Telnet IAC negotiation ────────────────────────────────────────────

    /**
     * Strip Telnet IAC (0xFF) sequences from received data and respond to
     * WILL/DO negotiation with DONT/WONT (we don't support any options).
     */
    private function stripIac(string $data): string
    {
        $out = '';
        $len = strlen($data);
        $i   = 0;

        while ($i < $len) {
            $byte = ord($data[$i]);

            if ($byte === 0xff && $i + 2 < $len) {   // IAC + cmd + opt
                $cmd = ord($data[$i + 1]);
                $opt = $data[$i + 2];

                if ($cmd === 0xfb) {                  // WILL x → DONT x
                    @fwrite($this->stream, "\xff\xfe" . $opt);
                } elseif ($cmd === 0xfd) {            // DO x   → WONT x
                    @fwrite($this->stream, "\xff\xfc" . $opt);
                }
                // WONT / DONT: no reply needed
                $i += 3;
            } elseif ($byte === 0xff && $i + 1 < $len) { // IAC + one byte
                $i += 2;
            } else {
                $out .= $data[$i];
                $i++;
            }
        }

        return $out;
    }
}
