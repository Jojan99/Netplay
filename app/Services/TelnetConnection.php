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
        //
        // Pero cuando la OLT se alcanza directo —por la VPN, sin jump— el
        // stream es un socket TCP común y fread() bloquea hasta que llegue algo.
        // Si la OLT se queda esperando una tecla en el paginador, fread no
        // vuelve nunca, el límite de read() no se llega a evaluar y el worker
        // queda colgado indefinidamente. Con un segundo de espera por lectura,
        // fread vuelve aunque no haya datos y el límite funciona.
        $meta = @stream_get_meta_data($stream);

        if (is_array($meta) && str_contains((string) ($meta['stream_type'] ?? ''), 'socket')) {
            @stream_set_timeout($stream, 1);
        }
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

    /**
     * Tira lo que haya quedado sin leer.
     *
     * Si una respuesta anterior dejó restos, la lectura siguiente los toma
     * como si fueran la respuesta del comando nuevo y a partir de ahí todo
     * llega corrido una posición. Vaciar antes de escribir lo evita.
     */
    public function drenar(): string
    {
        $sobrante = $this->buffer;
        $this->buffer = '';

        // Una pasada corta por el socket: lo que ya llegó se descarta, sin
        // quedarse esperando a que aparezca algo nuevo.
        $limite = microtime(true) + 0.3;

        while (microtime(true) < $limite) {
            $chunk = @fread($this->stream, 4096);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $sobrante .= $this->stripIac($chunk);
        }

        return $sobrante;
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
            // La OLT aceptó la conexión pero no mandó nada. Es lo que hace
            // cuando ya no le quedan sesiones libres: no rechaza, se queda
            // callada. Decirlo así ahorra buscar el problema en la red.
            throw new RuntimeException(
                'La OLT aceptó la conexión pero no respondió. Casi siempre es porque tiene todas '
                . 'sus sesiones ocupadas por conexiones anteriores que quedaron abiertas. Se liberan '
                . 'solas cuando la OLT las da por vencidas; si hay apuro, entrá por consola y cerralas '
                . 'a mano con "display users".'
            );
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

    public function close(): void
    {
        if (is_resource($this->stream)) {
            // Send quit so the OLT releases the session slot immediately.
            // Without this, the OLT keeps the slot alive until its own timeout
            // and rejects new connections with "Reenter times reached upper limit".
            // quit twice: first exits config/interface sub-mode, second exits
            // enable mode. The OLT may ask "Are you sure? (y/n)" — answer y.
            @fwrite($this->stream, "quit\n");
            usleep(200000);
            @fwrite($this->stream, "quit\n");
            usleep(200000);
            @fwrite($this->stream, "y\n");
            usleep(300000); // give OLT time to process logout and free the session slot
            fclose($this->stream);
        }
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
