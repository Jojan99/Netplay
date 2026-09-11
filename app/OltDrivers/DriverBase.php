<?php

namespace App\OltDrivers;

use App\OltDrivers\Interfaces\OltDriverInterface;
use Illuminate\Support\Facades\Log;

/**
 * Lo común a cualquier OLT por línea de comandos.
 *
 * Cada marca cambia los comandos, pero la mecánica de la consola es la misma:
 * entrar, salir del paginador, mandar un comando y leer hasta el prompt. Eso
 * vive acá para que un driver nuevo sólo tenga que escribir sus comandos.
 *
 * El driver de Huawei es anterior a esta clase y mantiene su propia mecánica;
 * no se toca para no arriesgar el equipo que ya está en producción.
 */
abstract class DriverBase implements OltDriverInterface
{
    protected object $ssh;

    /** @var array<string,mixed> */
    protected array $config;

    protected int $lineProfileId;
    protected int $srvProfileId;
    protected ?string $enablePassword;

    /** Prompt de la consola: termina en > o # */
    protected string $prompt = '/[>#]\s*$/';

    /** Lo que el equipo muestra cuando pagina la salida. */
    protected string $paginador = '/(?:----\s*More\s*----|--More--|\(q to quit\))/i';

    /** Palabras con las que el equipo reporta un rechazo. */
    protected array $errores = [
        'Failure', 'Error', 'error:', 'Invalid', 'Unknown command',
        '% Unknown', 'Incomplete', 'does not exist', 'already exist',
        'Command is in use', 'not support',
    ];

    public function __construct(object $ssh, array $config)
    {
        $this->ssh            = $ssh;
        $this->config         = $config;
        $this->lineProfileId  = (int) ($config['ont_lineprofile_id'] ?? 10);
        $this->srvProfileId   = (int) ($config['ont_srvprofile_id']  ?? 10);
        $this->enablePassword = $config['enable_password'] ?? null;

        $this->abrirSesion();
    }

    /** Cada marca decide cómo pasa del banner al prompt con privilegios. */
    abstract protected function abrirSesion(): void;

    // ── Consola ───────────────────────────────────────────────────────────

    /** Manda un comando y devuelve la salida completa, paginación incluida. */
    protected function cmd(string $comando, int $espera = 15): string
    {
        $this->ssh->setTimeout($espera);
        $this->ssh->write(rtrim($comando, "\r\n") . "\n");

        return $this->leer();
    }

    /** Manda varios comandos seguidos y devuelve todas las salidas pegadas. */
    protected function cmds(array $comandos, int $espera = 15): string
    {
        $salida = '';

        foreach ($comandos as $comando) {
            $salida .= $this->cmd($comando, $espera);
        }

        return $salida;
    }

    /** Lee hasta el prompt, avanzando el paginador cuantas veces haga falta. */
    protected function leer(): string
    {
        $salida = '';
        $vueltas = 0;

        try {
            while ($vueltas++ < 200) {
                $trozo = $this->ssh->read('/(?:[>#]\s*$|----\s*More\s*----|--More--|\(q to quit\))/i');
                $salida .= $trozo;

                if (preg_match($this->paginador, $trozo)) {
                    $this->ssh->write(' ');
                    continue;
                }

                break;
            }
        } catch (\Throwable) {
            // Tiempo agotado: devolvemos lo que alcanzó a llegar.
        }

        return preg_replace('/\s*(?:----\s*More\s*----|--More--)\s*/i', "\n", $salida);
    }

    /**
     * Vuelve al modo privilegiado (#), sin importar en qué submodo quedó.
     *
     * `end` no significa lo mismo en todos los equipos: en Cisco y ZTE baja al
     * modo privilegiado, pero en el firmware vtysh de las C-Data EPON baja al
     * modo vista ("End current mode and change to view mode"). El driver
     * entraba bien con enable y en la primera consulta se tiraba a sí mismo a
     * "OLT>", donde ningún comando de configuración existe. Por eso, si después
     * de `end` el prompt termina en ">", se vuelve a pedir enable.
     */
    protected function volverAlPrompt(): void
    {
        try {
            $this->ssh->setTimeout(3);

            $this->ssh->write("end\n");
            $prompt = (string) $this->ssh->read($this->prompt);

            if (preg_match('/>\s*$/', $prompt)) {
                $this->ssh->write("enable\n");
                $respuesta = (string) $this->ssh->read('/(?:[>#]\s*$|[Pp]assword)/');

                if (preg_match('/[Pp]assword/', $respuesta) && $this->enablePassword) {
                    $this->ssh->write($this->enablePassword . "\n");
                    $this->ssh->read($this->prompt);
                }
            }
        } catch (\Throwable) {
            // El buffer ya estaba limpio.
        } finally {
            $this->ssh->setTimeout(15);
        }
    }

    /** ¿El equipo rechazó el comando? */
    protected function fallo(string $salida): bool
    {
        foreach ($this->errores as $marca) {
            if (stripos($salida, $marca) !== false) {
                return true;
            }
        }

        return false;
    }

    /** La línea con la que el equipo explicó el rechazo. */
    protected function mensaje(string $salida): string
    {
        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            $linea = trim($linea);

            if ($linea !== '' && $this->fallo($linea)) {
                return $linea;
            }
        }

        return trim(preg_replace('/\s+/', ' ', substr($salida, -200)));
    }

    /**
     * Prueba variantes de un mismo comando y devuelve la primera que el equipo
     * acepta. Los fabricantes cambian la sintaxis entre versiones de firmware
     * —y varias sólo están documentadas para algunos modelos—, así que en vez
     * de fijar una sola forma se prueba en orden y se registra la que sirvió.
     *
     * @param  list<string>  $variantes
     * @return array{comando:?string, salida:string}
     */
    protected function primeraQueSirva(array $variantes, int $espera = 15): array
    {
        $ultima = '';

        foreach ($variantes as $comando) {
            $salida = $this->cmd($comando, $espera);
            $ultima = $salida;

            if (!$this->fallo($salida) && trim($salida) !== '') {
                Log::debug('[OLT] Variante aceptada', ['comando' => $comando]);

                return ['comando' => $comando, 'salida' => $salida];
            }
        }

        return ['comando' => null, 'salida' => $ultima];
    }

    /** Separa "0/1/3" en frame, slot y puerto. */
    protected function partirFsp(string $fsp): array
    {
        $partes = array_map('intval', explode('/', trim($fsp)));

        return [
            'frame' => $partes[0] ?? 0,
            'slot'  => $partes[1] ?? 0,
            'port'  => $partes[2] ?? 0,
        ];
    }

    // ── Por defecto ───────────────────────────────────────────────────────

    public function runCommand(string $command): string
    {
        return $this->cmd($command, 30);
    }

    public function saveConfig(): void
    {
        $this->volverAlPrompt();
        $this->primeraQueSirva(['write', 'save', 'copy running-config startup-config'], 180);
    }

    /** @return array<int,string> */
    public function getLineProfiles(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function getSrvProfiles(): array
    {
        return [];
    }
}
