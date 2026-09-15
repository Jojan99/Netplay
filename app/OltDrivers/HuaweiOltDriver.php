<?php

namespace App\OltDrivers;

use App\OltDrivers\Interfaces\OltDriverInterface;
use Illuminate\Support\Facades\Log;


class HuaweiOltDriver implements OltDriverInterface
{
    private object  $ssh;
    private int     $lineProfileId;
    private int     $srvProfileId;
    private ?string $enablePassword;

    /**
     * Queda en true cuando una lectura terminó sin encontrar el prompt.
     *
     * Cuando eso pasa la sesión queda con datos sin leer, y el comando
     * siguiente los consume como si fueran su propia respuesta: se llegó a ver
     * "display ont optical-info 0/0 0 0" llegando a la OLT como
     * "display ont optical-info0/". Antes se devolvía cadena vacía y nadie se
     * enteraba, así que la consulta siguiente fallaba sin motivo aparente.
     */
    private bool $desincronizada = false;

    public function __construct(object $ssh, array $config)
    {
        $this->ssh            = $ssh;
        $this->lineProfileId  = $config['ont_lineprofile_id'] ?? 10;
        $this->srvProfileId   = $config['ont_srvprofile_id']  ?? 10;
        $this->enablePassword = $config['enable_password'] ?? null;

        // Drain the initial MOTD/banner, handling ---- More ---- pagination.
        // Huawei OLTs often show a warning banner with a ---- More ---- page break
        // before the CLI prompt. Without consuming it the OLT stays blocked at
        // the pager and every subsequent command is treated as pager input.
        // Drain the initial MOTD/banner. Huawei OLTs send the banner in two
        // bursts: first the login info table ending with "OLT>", then a second
        // burst with a "Warning: default password" message ending with another
        // "OLT>". We must consume BOTH bursts before sending any commands,
        // otherwise the warning ends up polluting the response to "enable".
        $this->ssh->setTimeout(10);
        $attempts = 0;
        while ($attempts < 15) {
            // Match OLT prompts (end with > # $) OR More pager OR login prompts.
            // Login prompts contain ">>" so we detect them explicitly below.
            $out = $this->ssh->read('/(?:[>#$]\s*$|----\s*More\s*----|User name|User password|Reenter times)/');
            Log::debug('HuaweiOLT constructor read', ['attempt' => $attempts, 'output' => $out]);

            // OLT session limit exceeded or session in auth state — unusable.
            if (
                str_contains($out, 'Reenter times') ||
                str_contains($out, 'User name')     ||
                str_contains($out, 'User password')
            ) {
                throw new \RuntimeException(
                    'OLT session limit reached (Reenter times). Wait for idle sessions to expire or reduce concurrent connections.'
                );
            }

            if (preg_match('/----\s*More\s*----/i', $out)) {
                $this->ssh->write(' '); // advance past MOTD pagination
            } elseif (preg_match('/Warning:/i', $out)) {
                // Second burst: post-login warning + another prompt — keep draining
            } else {
                break; // reached stable CLI prompt
            }
            $attempts++;
        }

        // Some Huawei OLTs send a second burst after the MOTD (e.g. "Warning:
        // default password") in a separate TCP segment that arrives a few ms
        // after the first prompt. Drain it with a short timeout so it does not
        // pollute the response to "enable".
        try {
            $this->ssh->setTimeout(2);
            $extra = $this->ssh->read('/[>#$]\s*$/');
            if (!empty(trim($extra))) {
                Log::debug('HuaweiOLT constructor extra drain', ['output' => $extra]);
            }
        } catch (\Throwable) {
            // timeout — no second burst, that's fine
        }

        // Always try to enter enable/privilege mode. The OLT may or may not
        // ask for a password; enterEnableMode() handles both cases.
        $this->enterEnableMode();

        $this->ssh->setTimeout(15);
    }

    // ── Public API ────────────────────────────────────────────────────────

    /** Retorna la versión del sistema de la OLT (útil para probar conectividad). */
    public function getVersion(): string
    {
        $this->ssh->write("display version\n");
        return $this->collectPaged(); // collectPaged maneja el prompt { <cr>|backplane... } de Huawei
    }

    public function getUnauthONTs(): array
    {
        $this->ssh->write("display ont autofind all\n");
        $output = $this->collectOutput();
        return $this->parseUnauthONTs($output);
    }

    public function registerONT(string $fsp, string $serial, string $description, ?int $lineProfileId = null, ?int $srvProfileId = null, ?int $vlan = null, ?int $servicePort = null): array
    {
        Log::debug('HuaweiOLT registerONT: inicio', [
            'fsp'          => $fsp,
            'vlan'         => $vlan,
            'service_port' => $servicePort,
        ]);

        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $lineProfileId = $lineProfileId ?? $this->lineProfileId;
        $srvProfileId  = $srvProfileId  ?? $this->srvProfileId;

        $this->resetToPrompt();

        $this->ssh->write("enable\n");
        $this->ssh->read('/[>#$]\s*$/');
        $this->ssh->write("config\n");
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("interface gpon {$frame}/{$slot}\n");
        $this->ssh->read('/[>#$]\s*$/');

        // Los argumentos estaban corridos un lugar: al perfil de línea le
        // llegaba la VLAN y al de servicio el de línea, mientras que el de
        // servicio no se usaba nunca. Cuando esos números casualmente existían
        // en la OLT el alta funcionaba, y cuando no, respondía "The service
        // profile does not exist" sin que se entendiera por qué.
        $cmd = sprintf(
            "ont confirm %d sn-auth %s omci ont-lineprofile-id %d ont-srvprofile-id %d desc %s\n",
            $port,
            strtoupper($serial),
            $lineProfileId,
            $srvProfileId,
            $description
        );

        // La OLT contesta "System is busy" cuando está ocupada con otra cosa
        // —sobre todo mientras guarda la configuración en flash— y ella misma
        // pide reintentar. Se hace acá en vez de dejarle el reintento al
        // operador, que no tiene por qué saber que es pasajero.
        $intentos = 0;
        $output   = '';

        do {
            if ($intentos > 0) {
                Log::info('[OLT] La OLT estaba ocupada, reintentando el alta', [
                    'fsp' => $fsp, 'intento' => $intentos + 1,
                ]);
                // Espera creciente: guardar en flash le lleva más que unos
                // segundos, y con 4 fijos el alta se rechazaba igual. Que falle
                // deja al operador reautorizando, y ahí la ONT pierde su WAN.
                sleep(4 * $intentos);
                $this->resetToPrompt();
            }

            $this->ssh->write($cmd);

            // Huawei puede mostrar un prompt { <cr>|... } antes de la respuesta real
            usleep(300000);
            $buffer = $this->ssh->read('/[>#$\]]\s*$/');

            if (str_contains($buffer, '{')) {
                $this->ssh->write("\r\n");
                usleep(200000);
                $output = $buffer . $this->ssh->read('/[>#$\]]\s*$/');
            } else {
                $output = $buffer;
            }

            $ocupada = stripos($output, 'System is busy') !== false;
            $intentos++;
        } while ($ocupada && $intentos < 5);

        Log::debug('HuaweiOLT registerONT: response', [
            'fsp'    => $fsp,
            'serial' => $serial,
            'output' => $output,
        ]);

        $result = $this->parseRegistrationResponse($output, $port);

        // Cuando la OLT rechaza, queda anotado con qué se le pidió. Antes sólo
        // se guardaba en debug —que en producción no se escribe— así que no
        // había forma de saber qué perfil se había enviado.
        if (!$result['success']) {
            Log::error('[OLT] Alta de ONT rechazada', [
                'fsp'          => $fsp,
                'serial'       => $serial,
                'line_profile' => $lineProfileId,
                'srv_profile'  => $srvProfileId,
                'vlan'         => $vlan,
                'comando'      => trim($cmd),
                'respuesta'    => $result['message'],
            ]);
        }

        // Salir de interface gpon — volvemos a config mode
        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        // Si se proporcionó vlan + service_port, crear el service-port en la misma sesión
        if ($result['success'] && $vlan !== null && $servicePort !== null) {
            $ontId  = $result['ont_id'] ?? $port; // fallback al port si no se parseó
            $spCmd  = sprintf(
                "service-port %d vlan %d gpon %d/%d/%d ont %d gemport 1 multi-service user-vlan %d tag-transform translate\n",
                $servicePort, $vlan, $frame, $slot, $port, $ontId, $vlan
            );

            $this->ssh->write($spCmd);
            usleep(200000);
            $spBuffer = $this->ssh->read('/[>#$\]]\s*$/');
            if (str_contains($spBuffer, '{')) {
                $this->ssh->write("\n");
                usleep(200000);
                $spOutput = $spBuffer . $this->ssh->read('/[>#$]\s*$/');
            } else {
                $spOutput = $spBuffer;
            }

            $spOk = $this->servicePortQuedo($spOutput, $fsp, (int) $ontId, $vlan);

            if (!$spOk) {
                $result['service_port_error'] = $this->respuestaDeLaOlt($spOutput);
            }

            Log::debug('HuaweiOLT registerONT: service-port creation', [
                'fsp'          => $fsp,
                'ont_id'       => $ontId,
                'service_port' => $servicePort,
                'vlan'         => $vlan,
                'output'       => $spOutput,
                'ok'           => $spOk,
            ]);

            $result['service_port_created'] = $spOk;
            $result['service_port_index']   = $servicePort;
        }

        // Salir de config
        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        return $result;
    }

    public function getLineProfiles(): array
    {
        $this->ssh->write("display ont-lineprofile gpon all\n");
        $output = $this->collectPaged();
        return $this->parseProfileList($output);
    }

    public function getSrvProfiles(): array
    {
        $this->ssh->write("display ont-srvprofile gpon all\n");
        $output = $this->collectPaged();
        return $this->parseProfileList($output);
    }

    private function parseProfileList(string $raw): array
    {
        $profiles = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim(preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $line));
            // Match lines that start with a number: "  10  line-profile_10  ..."
            if (preg_match('/^\s*(\d+)\s+(\S+)/', $line, $m)) {
                $profiles[] = [
                    'id'   => (int) $m[1],
                    'name' => $m[2],
                ];
            }
        }
        return $profiles;
    }

    public function deleteONT(string $fsp, int $ontId, array $servicePorts = []): bool
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $this->resetToPrompt();

        // Entrar a modo configuración
        $this->ssh->write("enable\n");
        $this->ssh->read('/[>#$]\s*$/');
        $this->ssh->write("config\n");
        $this->ssh->read('/[>#$]\s*$/');

        // Eliminar service-ports solo si existen (si no hay SP, el undo falla en la OLT)
        if (!empty($servicePorts)) {
            $this->ssh->write("undo service-port port {$fsp} ont {$ontId}\n");
            $this->ssh->write("\n");
            $this->ssh->write("yes\n");
            $undoOut = $this->collectOutput();

            Log::debug('HuaweiOLT deleteONT: undo service-port', [
                'fsp'    => $fsp,
                'ont_id' => $ontId,
                'output' => $undoOut,
            ]);
        } else {
            Log::debug('HuaweiOLT deleteONT: sin service-ports, saltando undo', [
                'fsp'    => $fsp,
                'ont_id' => $ontId,
            ]);
        }

        // Entrar a interfaz GPON y eliminar ONT
        $this->ssh->write("interface gpon {$frame}/{$slot}\n");
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("ont delete {$port} {$ontId}\n");
        $output = $this->collectOutput();

        Log::debug('HuaweiOLT deleteONT: ont delete response', [
            'fsp'    => $fsp,
            'ont_id' => $ontId,
            'output' => $output,
        ]);

        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');
        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        return $this->salioBien($output);
    }

    /**
     * ¿Salió bien un comando de la OLT?
     *
     * Se mira el "success: N" cuando está, y si no, que no haya queja. Buscar
     * la palabra "success" suelta daba verdadero siempre, porque la OLT la
     * escribe también cuando el resultado es 0.
     */
    private function salioBien(string $raw): bool
    {
        if (preg_match('/success\s*:\s*(\d+)/i', $raw, $m)) {
            return ((int) $m[1]) > 0;
        }

        if ($this->tieneError($raw)) {
            return false;
        }

        return (bool) preg_match('/(success|succeeded)/i', $raw);
    }

    public function assignToClient(string $fsp, int $ontId, int $vlan, int $servicePort, string $description): bool
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $this->resetToPrompt();

        // Entrar a modo configuración
        $this->ssh->write("enable\n");
        $this->ssh->read('/[>#$]\s*$/');
        $this->ssh->write("config\n");
        $this->ssh->read('/[>#$]\s*$/');

        $cmd = sprintf(
            "service-port %d vlan %d gpon %d/%d/%d ont %d gemport 1 multi-service user-vlan %d tag-transform translate\n",
            $servicePort, $vlan, $frame, $slot, $port, $ontId, $vlan
        );

        $this->ssh->write($cmd);
        usleep(200000);
        $buffer = $this->ssh->read('/[>#$\]]\s*$/');
        if (str_contains($buffer, '{')) {
            $this->ssh->write("\n");
            usleep(200000);
            $output = $buffer . $this->ssh->read('/[>#$]\s*$/');
        } else {
            $output = $buffer;
        }

        Log::debug('HuaweiOLT assignToClient: response', [
            'fsp'          => $fsp,
            'ont_id'       => $ontId,
            'service_port' => $servicePort,
            'vlan'         => $vlan,
            'output'       => $output,
        ]);

        $ok = $this->servicePortQuedo($output, $fsp, $ontId, $vlan);

        // Salir de config
        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        return $ok;
    }

    /**
     * ¿Quedó creado el service-port?
     *
     * Muchas Huawei no contestan nada cuando lo crean bien: buscar "success"
     * en la respuesta daba por fallido un service-port que estaba andando, y
     * el panel pedía crearlo de nuevo. Se decide leyéndolo de la OLT.
     */
    private function servicePortQuedo(string $respuesta, string $fsp, int $ontId, int $vlan): bool
    {
        try {
            foreach ($this->getServicePorts($fsp, $ontId) as $sp) {
                if ((int) ($sp['vlan'] ?? 0) === $vlan) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[OLT] No se pudo verificar el service-port', ['fsp' => $fsp, 'ont' => $ontId, 'error' => $e->getMessage()]);

            // Sin poder leerlo, se decide por la respuesta: callada o con
            // "success" es que salió; con "Failure" o un error, no.
            return !preg_match('/failure|error|unknown command|incomplete|parameter/i', $respuesta);
        }

        return false;
    }

    /** Lo que dijo la OLT, sin el eco del comando ni el prompt. */
    private function respuestaDeLaOlt(string $salida): string
    {
        $lineas = array_filter(array_map('trim', preg_split('/\r?\n/', preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $salida))),
            fn ($l) => $l !== '' && !preg_match('/^service-port\s|[>#]\s*$|^\{|<cr>/i', $l));

        return mb_substr(implode(' ', $lineas), 0, 200);
    }

    public function getAuthorizedONTs(): array
    {
        $this->ssh->setTimeout(60);
        $this->ssh->write("display ont info all\n");
        $output = $this->collectPaged();
        $this->ssh->setTimeout(15);

        return $this->parseAuthorizedONTs($output);
    }

    public function getOntInfo(string $fsp, int $ontId): array
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $this->ssh->setTimeout(30);

        // Basic ONT info
        $this->ssh->write("display ont info {$frame}/{$slot} {$port} {$ontId}\n");
        $infoOutput = $this->collectPaged();

        // Optical info
        $this->ssh->write("display ont optical-info {$frame}/{$slot} {$port} {$ontId}\n");
        $opticalOutput = $this->collectPaged();

        $this->ssh->setTimeout(15);

        return $this->parseOntInfo($infoOutput, $opticalOutput, $fsp, $ontId);
    }

    private function enterEnableMode(): bool
    {
        $this->ssh->setTimeout(8);
        $this->ssh->write("enable\n");
        try {
            $out = $this->ssh->read('/(?:[>#$]\s*$|[Pp]assword\s*:)/');
            Log::debug('HuaweiOLT enterEnableMode response', ['output' => $out]);
            if (preg_match('/[Pp]assword\s*:/i', $out)) {
                if ($this->enablePassword === null) {
                    Log::warning('HuaweiOLT: OLT asks for enable password but none is configured — staying in user mode');
                    return false;
                }
                $this->ssh->write($this->enablePassword . "\n");
                $after = $this->ssh->read('/(?:[>#$]\s*$|[Ii]nvalid|[Uu]ser\s*name)/');
                Log::debug('HuaweiOLT enterEnableMode after password', ['output' => $after]);
                if (preg_match('/#\s*$/', $after)) {
                    Log::debug('HuaweiOLT entered enable mode successfully');
                    $this->drainResidualEcho();
                    return true;
                }
                Log::error('HuaweiOLT enable password invalid — will run commands in user mode');
                return false;
            } elseif (preg_match('/#\s*$/', $out)) {
                Log::debug('HuaweiOLT already in enable mode');
                $this->drainResidualEcho();
                return true;
            }
        } catch (\Throwable $e) {
            Log::debug('HuaweiOLT enterEnableMode exception', ['error' => $e->getMessage()]);
        }
        return false;
    }

    /**
     * After entering enable mode the OLT echoes the 'enable' command in a
     * separate TCP segment that may arrive after our read() already returned.
     * Sending a blank newline forces the OLT to emit a fresh '#' prompt;
     * the read() will consume ALL pending bytes including the stale echo,
     * leaving the session at a known clean state.
     */
    private function drainResidualEcho(): void
    {
        $this->ssh->setTimeout(4);
        $this->ssh->write("\n");
        try {
            $this->ssh->read('/[>#$]\s*$/');
        } catch (\Throwable) {}
    }

public function getServicePorts(?string $fsp = null, ?int $ontId = null): array
{
    Log::debug('INICIO getServicePortsaaaaaaaaaaaaaaaaaa', [
        'fsp_original' => $fsp,
        'ont_id' => $ontId
    ]);

    $this->resetToPrompt();
    $this->ssh->setTimeout(4);

    // 🔧 Normalizar FSP
    if ($fsp !== null) {
        $fsp = trim($fsp);
        $fsp = preg_replace('/[^\d\/]/', '', $fsp);
        $fsp = preg_replace('#/+#', '/', $fsp);
        $fsp = trim($fsp, '/');
    }

    // 🧾 Comando
    if ($fsp !== null && $ontId !== null) {
        $command = "display service-port port {$fsp} ont {$ontId}";
    } elseif ($fsp !== null) {
        // Todos los de un puerto. No se usa "display service-port all" a
        // propósito: en una OLT con cientos de ONT devuelve miles de líneas
        // paginadas. Por puerto el volumen es manejable y quien llama puede
        // recorrer los puertos que le interesen.
        $command = "display service-port port {$fsp}";
    } else {
        return [];
    }

    Log::debug('COMANDO', ['cmd' => $command]);

    // 1. Enviar comando
    $this->ssh->write($command . "\r\n");

    // 2. Esperar que Huawei responda
    usleep(100000); // 300ms

    // 3. Leer buffer inicial
    $buffer = $this->ssh->read('/[>#$]\s*$/');
    Log::debug('BUFFER INICIAL', ['buffer' => $buffer]);

    // 4. Si hay prompt interactivo { ... }
    if (str_contains($buffer, '{')) {
        $this->ssh->write("\r\n"); // enviar ENTER

        usleep(100000);

        $output = $this->ssh->read('/[>#$]\s*$/');
    } else {
        $output = $buffer;
    }

    // 🧹 Limpiar salida
    $output = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $output);
    $output = preg_replace('/\x07/', '', $output);

    Log::debug('RAW OUTPUT', ['output' => $output]);

    // ⚠️ sesión inválida
    if (str_contains($output, 'User password') || str_contains($output, 'User name')) {
        Log::warning('HuaweiOLT: sesión en estado de autenticación');
        return [];
    }

    // 🔄 Parsear
    $parsed = $this->parseServicePorts($output);

    Log::debug('PARSED', [
        'count' => count($parsed),
        'data'  => $parsed
    ]);

    return $parsed;
}

public function parseServicePorts(string $output): array
{
    $lines = explode("\n", $output);
    $data = [];

    foreach ($lines as $line) {
        $line = trim($line);

        // Saltar basura
        if (
            empty($line) ||
            str_contains($line, 'INDEX') ||
            str_contains($line, '---') ||
            str_contains($line, 'Total') ||
            str_contains($line, 'Note')
        ) {
            continue;
        }

        // Solo líneas que empiezan con número
        if (!preg_match('/^\d+/', $line)) {
            continue;
        }

        // Separar por espacios múltiples
        $cols = preg_split('/\s+/', $line);

        // Validar columnas mínimas
        if (count($cols) < 10) {
            continue;
        }

        /*
        Ejemplo Huawei:
        10001  100 common   gpon 0/0 /0  1    1     vlan  100        -    -    up
        */

        // Reconstruir puerto
        $portType = $cols[3];
        $port = $portType;

        $offset = 0;

        // Caso especial: "0/0 /0" — el puerto F/S/P aparece partido en dos tokens
        if (isset($cols[4]) && str_contains($cols[4], '/')) {
            $port .= $cols[4];
            $offset = 1;
            // Si el siguiente token también empieza con '/' es la parte /P del puerto
            if (isset($cols[5]) && str_starts_with($cols[5], '/')) {
                $port .= $cols[5];
                $offset = 2;
            }
        }

        try {
            $data[] = [
                'index'     => (int) $cols[0],
                'vlan'      => (int) $cols[1],
                'vlan_attr' => $cols[2],
                'port_type' => $portType,
                'port'      => $port,
                'ont_id'    => (int) ($cols[4 + $offset] ?? 0),
                'gemport'   => (int) ($cols[5 + $offset] ?? 0),
                'flow_type' => $cols[6 + $offset] ?? null,
                'flow_para' => $cols[7 + $offset] ?? null,
                'rx'        => $cols[8 + $offset] ?? null,
                'tx'        => $cols[9 + $offset] ?? null,
                'state'     => $cols[10 + $offset] ?? null,
            ];
        } catch (\Throwable $e) {
            continue;
        }
    }

    return $data;
}

    public function transferONT(string $fromFsp, int $ontId, string $toFsp): array
    {
        try {
            [$fromFrame, $fromSlot, $fromPort] = $this->parseFsp($fromFsp);
            [$toFrame,   $toSlot,   $toPort]   = $this->parseFsp($toFsp);

            $this->ssh->setTimeout(30);

            // Step 1: Get ONT info (serial, description)
            $this->ssh->write("display ont info {$fromFrame}/{$fromSlot} {$fromPort} {$ontId}\n");
            $infoOutput = $this->collectPaged();

            $serial      = $this->extractValue($infoOutput, '/SN\s*:\s*(\S+)/i');
            $description = $this->extractValue($infoOutput, '/Description\s*:\s*(.*)/i');

            if (empty($serial)) {
                return ['success' => false, 'new_ont_id' => -1, 'message' => 'Could not retrieve ONT serial number'];
            }

            // Step 2: Get service ports for this ONT
            $servicePorts = $this->getServicePorts($fromFsp, $ontId);

            // Step 3: Remove all service ports
            foreach ($servicePorts as $sp) {
                $this->ssh->write("undo service-port {$sp['index']}\n");
                $this->collectOutput();
            }

            // Step 4: Delete ONT from source port
            $this->ssh->write("interface gpon {$fromFrame}/{$fromSlot}\n");
            $this->ssh->read('/[>#$]\s*$/');

            $this->ssh->write("ont delete {$fromPort} {$ontId}\n");
            $this->collectOutput();

            $this->ssh->write("quit\n");
            $this->ssh->read('/[>#$]\s*$/');

            // Step 5: Register ONT on destination port
            $this->ssh->write("interface gpon {$toFrame}/{$toSlot}\n");
            $this->ssh->read('/[>#$]\s*$/');

            $descArg = !empty($description) ? " desc " . escapeshellarg(trim($description)) : '';
            $confirmCmd = sprintf(
                "ont confirm %d sn-auth %s omci ont-lineprofile-id %d ont-srvprofile-id %d%s\n",
                $toPort,
                strtoupper(trim($serial)),
                $this->lineProfileId,
                $this->srvProfileId,
                $descArg
            );

            $this->ssh->write($confirmCmd);
            $confirmOutput = $this->collectOutput();

            $this->ssh->write("quit\n");
            $this->ssh->read('/[>#$]\s*$/');

            // Parse new ONT ID
            $newOntId = null;
            if (preg_match('/ONTID\s*:\s*(\d+)/i', $confirmOutput, $m)
                || preg_match('/ont\s+(\d+)\s+/i', $confirmOutput, $m)) {
                $newOntId = (int) $m[1];
            }

            $success = stripos($confirmOutput, 'success') !== false
                    || stripos($confirmOutput, 'Succeeded') !== false;

            if (!$success) {
                return [
                    'success'    => false,
                    'new_ont_id' => -1,
                    'message'    => 'ONT registration on new port failed: ' . trim($confirmOutput),
                ];
            }

            // Step 6: Recreate service ports on new FSP
            foreach ($servicePorts as $sp) {
                $spCmd = sprintf(
                    "service-port %d vlan %d gpon %d/%d/%d ont %d gemport %d multi-service user-vlan %d tag-transform translate\n",
                    $sp['index'],
                    $sp['vlan'],
                    $toFrame,
                    $toSlot,
                    $toPort,
                    $newOntId ?? $ontId,
                    $sp['gemport'],
                    $sp['vlan']
                );
                $this->ssh->write($spCmd);
                $this->collectOutput();
            }

            $this->ssh->setTimeout(15);

            return [
                'success'    => true,
                'new_ont_id' => $newOntId ?? -1,
                'message'    => "ONT transferred from {$fromFsp}/{$ontId} to {$toFsp}/" . ($newOntId ?? '?'),
            ];
        } catch (\Throwable $e) {
            $this->ssh->setTimeout(15);
            return [
                'success'    => false,
                'new_ont_id' => -1,
                'message'    => 'Transfer failed: ' . $e->getMessage(),
            ];
        }
    }

    public function deactivateONT(string $fsp, int $ontId): bool
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $this->ssh->write("interface gpon {$frame}/{$slot}\n");
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("ont deactivate {$port} {$ontId}\n");
        $output = $this->collectOutput();

        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        return stripos($output, 'success') !== false
            || stripos($output, 'Succeeded') !== false;
    }

    /**
     * Los puertos de subida de la OLT con las VLAN que llevan.
     *
     * Sirve para saber por dónde sale el tráfico hacia el router y, de paso,
     * qué VLAN ya están en uso: son las que no se pueden proponer para la red
     * de gestión.
     *
     * @return list<array{puerto:string, vlans:list<int>}>
     */
    public function puertosDeSubida(): array
    {
        $tablero = $this->runCommand('display board 0');
        $slots = [];

        // Las placas de control ("MCU") son las que llevan los puertos de
        // subida; las GP/EP son las de fibra hacia los clientes.
        foreach (preg_split('/\r?\n/', $tablero) as $linea) {
            if (preg_match('/^\s*(\d+)\s+(\S*MCU\S*|\S*CTRL\S*)\s+/i', $linea, $m)) {
                $slots[] = (int) $m[1];
            }
        }

        $puertos = [];

        foreach ($slots as $slot) {
            foreach (range(0, 3) as $puerto) {
                $salida = $this->runCommand("display port vlan 0/{$slot}/{$puerto}");

                if (stripos($salida, 'Total:') === false) {
                    continue;
                }

                // La lista de VLAN va entre las dos líneas de guiones; fuera de
                // ahí están el eco del comando y el "Total", cuyos números no
                // son VLAN.
                $partes = preg_split('/^\s*-{5,}\s*$/m', $salida);
                $vlans = [];

                if (isset($partes[1])) {
                    preg_match_all('/\d{1,4}/', $partes[1], $m);
                    $vlans = array_values(array_filter(array_unique(array_map('intval', $m[0] ?? []))));
                    sort($vlans);
                }

                $puertos[] = [
                    'puerto' => "0/{$slot}/{$puerto}",
                    'vlans'  => $vlans,
                    'nativa' => preg_match('/Native VLAN:\s*(\d+)/i', $salida, $n) ? (int) $n[1] : null,
                ];
            }
        }

        return $puertos;
    }

    /**
     * Deja lista la VLAN de gestión en la OLT: la crea y la deja pasar por el
     * puerto de subida que va al router.
     *
     * Es aditivo: no quita ninguna VLAN de las que ya estaban, porque sacar
     * una de un puerto de subida deja sin servicio a esos clientes.
     */
    /**
     * Qué perfil de línea usa una ONT. Es lo que decide si el equipo puede
     * sacar tráfico por la VLAN de gestión.
     *
     * @return array{id:int, nombre:string}|null
     */
    public function perfilDeOnt(string $fsp, int $ontId): ?array
    {
        [$frame, $slot, $puerto] = $this->parseFsp($fsp);

        $salida = $this->runCommand("display ont info {$frame} {$slot} {$puerto} {$ontId}");

        if (!preg_match('/Line profile ID\s*:\s*(\d+)/i', $salida, $id)) {
            return null;
        }

        return [
            'id'     => (int) $id[1],
            'nombre' => preg_match('/Line profile name\s*:\s*(\S+)/i', $salida, $n) ? $n[1] : "perfil {$id[1]}",
        ];
    }

    /**
     * Los perfiles de línea y cuántos equipos usa cada uno.
     *
     * @return list<array{id:int, nombre:string, equipos:int}>
     */
    public function perfilesDeLinea(): array
    {
        $salida = $this->runCommand('display ont-lineprofile gpon all');
        $perfiles = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('/^\s*(\d+)\s+(\S+)\s+(\d+)\s*$/', $linea, $m)) {
                $perfiles[] = ['id' => (int) $m[1], 'nombre' => $m[2], 'equipos' => (int) $m[3]];
            }
        }

        return $perfiles;
    }

    /**
     * Cómo reparte el tráfico un perfil de línea: modo, gestión TR-069 y qué
     * VLAN viaja por cada canal (GEM).
     *
     * @return array{id:int, modo:string, tr069:bool, gems:array<int,list<array{indice:int, vlan:?int}>>}|null
     */
    public function perfilDeLinea(int $id): ?array
    {
        $salida = $this->runCommand("display ont-lineprofile gpon profile-id {$id}");

        if (!preg_match('/Profile-ID\s*:\s*' . $id . '\b/', $salida)) {
            return null;
        }

        $gems = [];
        $trozos = preg_split('/<Gem Index\s+(\d+)>/', $salida, -1, PREG_SPLIT_DELIM_CAPTURE);

        for ($i = 1; $i + 1 < count($trozos); $i += 2) {
            $gem = (int) $trozos[$i];
            $gems[$gem] = [];

            // Las filas de la tabla de mapeo: índice, VLAN y el resto en guiones.
            preg_match_all('/^\s+(\d+)\s+(\d+|-)\s+\S+\s+\S+\s+\S+\s+\S+/m', $trozos[$i + 1], $filas, PREG_SET_ORDER);

            foreach ($filas as $f) {
                $gems[$gem][] = ['indice' => (int) $f[1], 'vlan' => $f[2] === '-' ? null : (int) $f[2]];
            }
        }

        return [
            'id'    => $id,
            'modo'  => preg_match('/Mapping mode\s*:\s*(\S+)/i', $salida, $m) ? strtoupper($m[1]) : '',
            'tr069' => (bool) preg_match('/TR069 management\s*:\s*Enable/i', $salida),
            'gems'  => $gems,
        ];
    }

    /**
     * Deja un perfil de línea listo para la gestión remota: la VLAN de gestión
     * con su propio lugar en el canal del servicio y la gestión TR-069
     * encendida, así la ONT arma sola su WAN de gestión.
     *
     * Sólo agrega: no quita ni cambia ningún mapeo que ya esté.
     *
     * @return array{ok:bool, estado:string, detalle:string}
     */
    public function prepararPerfilDeLinea(int $id, int $vlan): array
    {
        $antes = $this->perfilDeLinea($id);

        if (!$antes) {
            return ['ok' => false, 'estado' => 'error', 'detalle' => "No se pudo leer el perfil {$id}."];
        }

        if ($antes['modo'] !== 'VLAN') {
            return [
                'ok' => false, 'estado' => 'no_soportado',
                'detalle' => "El perfil reparte el tráfico por «{$antes['modo']}», no por VLAN: agregarle la gestión cambiaría cómo navegan sus clientes. Hay que revisarlo a mano.",
            ];
        }

        if (!$antes['gems']) {
            return ['ok' => false, 'estado' => 'no_soportado', 'detalle' => 'El perfil no tiene canales (GEM) configurados.'];
        }

        // El canal que ya lleva el servicio; si ninguno tiene mapeos, el primero.
        $gem = array_key_first(array_filter($antes['gems'])) ?? array_key_first($antes['gems']);
        $mapeos = $antes['gems'][$gem];
        $tieneVlan = in_array($vlan, array_column($mapeos, 'vlan'), true);

        if ($tieneVlan && $antes['tr069']) {
            return ['ok' => true, 'estado' => 'ya_estaba', 'detalle' => 'Ya estaba listo.'];
        }

        $indice = $mapeos ? max(array_column($mapeos, 'indice')) + 1 : 0;

        if (!$tieneVlan && $indice > 7) {
            return ['ok' => false, 'estado' => 'no_soportado', 'detalle' => 'El canal del perfil ya tiene los 8 mapeos que admite la OLT.'];
        }

        $this->runCommand('config');
        $this->runCommand("ont-lineprofile gpon profile-id {$id}");

        $respuestas = '';

        if (!$tieneVlan) {
            $respuestas .= $this->runCommand("gem mapping {$gem} {$indice} vlan {$vlan}");
        }

        if (!$antes['tr069']) {
            $respuestas .= $this->runCommand('tr069-management enable');
        }

        // Con equipos usando el perfil, la OLT pide confirmación antes de
        // mandarles la configuración nueva.
        $commit = $this->runCommand('commit');

        if (preg_match('/\(y\/n\)/i', $commit)) {
            $commit .= $this->confirmar();
        }

        $this->volverAlPrincipio();

        $despues = $this->perfilDeLinea($id);
        $listo = $despues
            && $despues['tr069']
            && in_array($vlan, array_column($despues['gems'][$gem] ?? [], 'vlan'), true);

        return [
            'ok'      => $listo,
            'estado'  => $listo ? 'listo' : 'error',
            'detalle' => $listo
                ? "VLAN {$vlan} en el canal {$gem} y gestión TR-069 encendida."
                : 'La OLT no dejó el perfil como se pidió: ' . (self::primeraLinea($respuestas . "\n" . $commit) ?: 'sin detalle'),
        ];
    }

    /**
     * Crea un perfil de servidor TR-069 completo: dirección, usuario y clave.
     *
     * Tiene que nacer con las credenciales: la OLT no deja modificar un perfil
     * que ya tiene equipos asignados ("has been bound"). Si hay que cambiarlas
     * se crea otro perfil y se pasan los equipos.
     *
     * @return array{ok:bool, detalle:string}
     */
    public function crearServidorTr069(int $perfil, string $nombre, string $url, string $usuario, string $clave): array
    {
        $this->runCommand('config');

        $respuesta = $this->comandoConClave(
            "ont tr069-server-profile add profile-id {$perfil} profile-name {$nombre} url {$url} user {$usuario}",
            $clave
        );

        $this->volverAlPrincipio();

        $leido = $this->runCommand("display ont tr069-server-profile profile-id {$perfil}");
        $ok = (bool) preg_match('/User Name\s*:\s*' . preg_quote($usuario, '/') . '\s*$/m', $leido)
            && str_contains($leido, $url);

        return [
            'ok'      => $ok,
            'detalle' => $ok
                ? "Perfil de servidor {$perfil} con {$url} y usuario {$usuario}."
                : 'La OLT no creó el perfil de servidor: ' . self::errorDeOlt($respuesta),
        ];
    }

    /**
     * Le asigna a un equipo el perfil de servidor TR-069: la OLT le manda la
     * dirección y las credenciales del ACS.
     *
     * @return array{ok:bool, detalle:string}
     */
    public function asignarServidorTr069(string $fsp, int $ontId, int $perfil): array
    {
        [$frame, $slot, $puerto] = $this->parseFsp($fsp);

        $antes = $this->vinculosDeServidorTr069($perfil);

        $this->runCommand('config');
        $this->runCommand("interface gpon {$frame}/{$slot}");
        $respuesta = $this->runCommand("ont tr069-server-config {$puerto} {$ontId} profile-id {$perfil}");
        $this->volverAlPrincipio();

        $despues = $this->vinculosDeServidorTr069($perfil);
        $error   = preg_match('/(Failure[^\n]*|Unknown command|Parameter error|Too many parameters|Incomplete command)/i', $respuesta, $m) ? trim($m[1]) : null;

        // Sin error y con el perfil ya asignado (o uno más que antes) quedó.
        $ok = $error === null && $despues !== null && ($antes === null || $despues >= $antes);

        return [
            'ok'      => $ok,
            'detalle' => $ok ? "Servidor TR-069 asignado (perfil {$perfil})." : 'La OLT no asignó el servidor TR-069: ' . ($error ?? 'sin detalle'),
        ];
    }

    /** Cuántos equipos usan un perfil de servidor TR-069, o null si no existe. */
    private function vinculosDeServidorTr069(int $perfil): ?int
    {
        $leido = $this->runCommand("display ont tr069-server-profile profile-id {$perfil}");

        return preg_match('/Binding times\s*:\s*(\d+)/i', $leido, $m) ? (int) $m[1] : null;
    }

    /**
     * Manda un comando que termina pidiendo una clave y la contesta.
     *
     * La OLT la pide aparte, sin mostrarla, y a veces la hace confirmar.
     * Escribirla en la misma línea dejaba la consola esperando esa pregunta.
     * Devuelve lo que contestó la OLT, que no incluye la clave.
     */
    private function comandoConClave(string $comando, string $clave): string
    {
        $this->resetToPrompt();
        $this->ssh->setTimeout(12);

        $espera = '/(pass(?:word)?[^\n]*:\s*$|\{\s*<cr>[^\n]*$|\(y\/n\)[^\n]*$|(?:^|\n)[^\s{}|<>]+(?:\([^)\n]*\))?[>#]\s*$)/i';

        $this->ssh->write($comando . "\n");
        $salida = $this->ssh->read($espera);
        $respuesta = $salida;

        for ($i = 0; $i < 4; $i++) {
            if (preg_match('/\{\s*<cr>[^\n]*$/i', $salida)) {
                $this->ssh->write("\r\n");
            } elseif (preg_match('/pass(?:word)?[^\n]*:\s*$/i', $salida)) {
                $this->ssh->write($clave . "\n");
            } elseif (preg_match('/\(y\/n\)[^\n]*$/i', $salida)) {
                $this->ssh->write("y\n");
            } else {
                break;
            }

            $salida = $this->ssh->read($espera);
            $respuesta .= $salida;
        }

        $this->ssh->setTimeout(30);

        return str_replace($clave, '***', $respuesta);
    }

    private static function errorDeOlt(string $respuesta): string
    {
        return preg_match('/(Failure[^\n]*|Unknown command|Parameter error|Too many parameters|Incomplete command)/i', $respuesta, $m)
            ? trim($m[1])
            : 'sin detalle';
    }

    /**
     * Reinicia una ONT desde la OLT.
     *
     * La OLT pide confirmación. Se contesta escribiendo directo: runCommand()
     * manda antes un Enter para limpiar la consola, y ese Enter respondía la
     * pregunta con la opción por defecto (n), cancelando el reinicio.
     *
     * @return array{ok:bool, detalle:string}
     */
    public function reiniciarOnt(string $fsp, int $ontId): array
    {
        [$frame, $slot, $puerto] = $this->parseFsp($fsp);

        $this->runCommand('config');
        $this->runCommand("interface gpon {$frame}/{$slot}");

        $salida = $this->runCommand("ont reset {$puerto} {$ontId}");

        if (preg_match('/\(y\/n\)/i', $salida)) {
            $salida .= $this->confirmar();
        }

        $this->volverAlPrincipio();

        $error = preg_match('/(Failure[^\n]*|Unknown command|Parameter error|Incomplete command|Too many parameters)/i', $salida, $m) ? trim($m[1]) : null;

        return [
            'ok'      => $error === null,
            'detalle' => $error === null ? 'El equipo se está reiniciando.' : "La OLT no reinició el equipo: {$error}",
        ];
    }

    /**
     * Contesta "y" a una pregunta (y/n) que ya está en pantalla, sin mandar
     * nada antes que la responda por su cuenta.
     */
    private function confirmar(): string
    {
        $this->ssh->setTimeout(15);
        $this->ssh->write("y\n");

        $respuesta = $this->ssh->read('/(?:^|\n)[^\s{}|<>]+(?:\([^)\n]*\))?[>#]\s*$/');

        $this->ssh->setTimeout(30);

        return $respuesta;
    }

    /**
     * Vuelve a la vista principal de la OLT.
     *
     * Salir con un `quit` fijo no alcanza: si un comando dejó abierto un
     * sub-prompt, el `quit` se lo come y la sesión queda dentro de `config`,
     * donde los comandos siguientes se comportan distinto.
     */
    private function volverAlPrincipio(): void
    {
        for ($i = 0; $i < 4; $i++) {
            // Primero se mira dónde está: un "quit" en la vista principal no
            // sale de ningún lado, pregunta si se quiere cerrar la sesión.
            $prompt = $this->runCommand('');

            if (preg_match('/\(y\/n\)/i', $prompt)) {
                $this->runCommand('n');
                continue;
            }

            if (!preg_match('/\((?:config|config-[^)]*)\)#\s*$/', $prompt)) {
                return;
            }

            $salida = $this->runCommand('quit');

            // Por si igual pregunta: nunca se cierra la sesión desde acá.
            if (preg_match('/\(y\/n\)/i', $salida)) {
                $this->runCommand('n');
                return;
            }
        }
    }

    public function prepararVlanDeGestion(int $vlan, string $puertoDeSubida): array
    {
        [$frame, $slot, $puerto] = array_map('intval', explode('/', $puertoDeSubida));

        $this->runCommand('config');

        $creada   = $this->runCommand("vlan {$vlan} smart");
        $enPuerto = $this->runCommand("port vlan {$vlan} {$frame}/{$slot} {$puerto}");

        // Se lee de vuelta: la OLT no dice nada cuando el comando sale bien, y
        // "sin error" no alcanza para asegurar que la VLAN quedó en el puerto.
        $comprobacion = $this->runCommand("display port vlan {$puertoDeSubida}");

        $this->volverAlPrincipio();

        $lista = preg_split('/^\s*-{5,}\s*$/m', $comprobacion)[1] ?? '';

        return [
            'ok'      => (bool) preg_match('/\b' . $vlan . '\b/', $lista),
            'detalle' => trim(self::primeraLinea($creada) . ' ' . self::primeraLinea($enPuerto)),
        ];
    }

    /**
     * @param list<int> $vlansCliente VLAN de servicio de la ONT (sus service-ports)
     * @param bool      $pisarAjenas  equipo recién autorizado: las conexiones de
     *                                otra VLAN (un equipo reutilizado) no le dan
     *                                servicio y se pueden reemplazar
     */
    public function darGestionAOnt(string $fsp, int $ontId, int $vlan, int $servicePort, array $vlansCliente = [], bool $pisarAjenas = false): array
    {
        [$frame, $slot, $puerto] = $this->parseFsp($fsp);

        $this->runCommand('config');

        // ── Antes de tocar nada: qué conexiones (WAN) tiene ya el equipo ────
        //
        // "ont ipconfig" crea la WAN de gestión en el lugar 2 de la ONT. Si ahí
        // estaba la conexión de internet del cliente, la reemplaza y el cliente
        // se queda sin servicio (pasó con DOUGLAS_MENDEZ, LILIANA_COROMOTO e
        // IRIANIS_GUERRERO). Y si su internet ya lleva TR-069 ("Tr069,
        // Internet"), no hace falta otra WAN: el servidor llega por esa.
        $wanInfo = $this->runCommand("display ont wan-info {$frame}/{$slot} {$puerto} {$ontId}");
        $wans = [];

        foreach (preg_split('/(?=^\s*Index\s*:)/m', $wanInfo) as $bloque) {
            if (!preg_match('/^\s*Index\s*:\s*(\d+)/m', $bloque, $mi)) {
                continue;
            }
            $wans[] = [
                'indice'   => (int) $mi[1],
                'nombre'   => preg_match('/^\s*Name\s*:\s*(\S+)/m', $bloque, $mn) ? $mn[1] : '',
                'servicio' => preg_match('/^\s*Service type\s*:\s*(.+)$/m', $bloque, $ms) ? trim($ms[1]) : '',
                'vlan'     => preg_match('/^\s*Manage VLAN\s*:\s*(\d+)/m', $bloque, $mv) ? (int) $mv[1] : null,
            ];
        }

        // Si la ONT no informa sus WAN (otras marcas, o apagada) se sigue como
        // siempre: con C-Data el camino por la OLT está probado.
        foreach ($wans as $w) {
            if ($w['vlan'] === $vlan) {
                continue; // la de gestión, de una vez anterior
            }

            // Una conexión de otra VLAN en un equipo recién autorizado viene de
            // su dueño anterior (YINETH_DE_LA_CRUZ traía 2_INTERNET_R_VID_200 con
            // service-port en la 106): no da servicio, así que ni protege el
            // lugar 2 ni sirve para el TR-069. La de su VLAN sí se respeta.
            if ($pisarAjenas && $vlansCliente && $w['vlan'] !== null && !in_array($w['vlan'], $vlansCliente, true)) {
                continue;
            }

            if (stripos($w['servicio'], 'tr069') !== false) {
                $this->volverAlPrincipio();

                return [
                    'ok' => true, 'sp_ok' => true, 'sp' => null, 'ip' => null,
                    'omitido' => 'tr069_en_internet',
                    'detalle' => "El equipo ya tiene TR-069 en su conexión {$w['nombre']}: no se crea la VLAN de gestión.",
                ];
            }

            if ($w['indice'] === 2) {
                $this->volverAlPrincipio();

                return [
                    'ok' => false, 'sp_ok' => true, 'sp' => null, 'ip' => null,
                    'omitido' => 'lugar_ocupado',
                    'detalle' => "No se tocó el equipo: su conexión {$w['nombre']} está en el lugar donde la OLT crea la de gestión y se borraría. "
                        . 'Hay que configurar la gestión en el propio equipo.',
                ];
            }
        }

        // Si el equipo ya tiene su carril en esta VLAN se reutiliza: la OLT no
        // deja crear un segundo para el mismo equipo y la misma VLAN, y lo
        // rechaza igual que si el número fuera de otro.
        $listar = fn () => $this->runCommand("display service-port port {$frame}/{$slot}/{$puerto} ont {$ontId}");
        $suyo   = fn (string $tabla) => preg_match('/^\s*(\d+)\s+' . $vlan . '\s+\w+\s+gpon\b/m', $tabla, $m) ? (int) $m[1] : null;

        $sp = '';
        $tiene = $suyo($listar());

        if ($tiene === null) {
            // El service-port lleva el tráfico de gestión del equipo hasta el
            // router; la ONT lo ve como un servicio más, con su propia VLAN.
            $sp = $this->runCommand(sprintf(
                'service-port %d vlan %d gpon %d/%d/%d ont %d gemport 1 multi-service user-vlan %d tag-transform translate',
                $servicePort, $vlan, $frame, $slot, $puerto, $ontId, $vlan
            ));

            // Vale sólo lo que aparece en la lista del equipo: si el número
            // estaba tomado por otro, la OLT lo rechazó y acá no figura.
            $tiene = $suyo($listar());
        }

        // Y esto le dice a la ONT que pida IP por DHCP en esa VLAN: con la IP
        // le llega la dirección del servidor TR-069 (opción 43). Vive dentro
        // de la vista de la placa, no en la general.
        $this->runCommand("interface gpon {$frame}/{$slot}");
        $ip = $this->runCommand(sprintf('ont ipconfig %d %d dhcp vlan %d', $puerto, $ontId, $vlan));

        // Se lee de vuelta: la OLT contesta lo mismo para "hecho" que para
        // "casi", así que lo único que cuenta es lo que quedó guardado.
        $quedo = $this->runCommand(sprintf('display ont ipconfig %d %d', $puerto, $ontId));

        $this->volverAlPrincipio();

        $falla = fn (string $t) => (bool) preg_match('/failure|error|incomplete|unknown command/i', $t);

        $spOk = $tiene !== null;
        $servicePort = $tiene ?? $servicePort;

        // Si la OLT sabe mostrar la configuración, manda lo que muestra; si no
        // conoce el comando, queda lo que contestó al aplicarlo.
        $confirmado = stripos($quedo, 'unknown command') === false
            ? (bool) preg_match('/manage VLAN\s*:\s*' . $vlan . '\b/i', $quedo)
            : !$falla($ip);

        // Cuando sale bien se cuenta qué quedó, no lo que contestó la OLT: sus
        // respuestas son sub-prompts que no le dicen nada a nadie.
        return [
            'ok'      => $confirmado && $spOk,
            'sp_ok'   => $spOk,
            'sp'      => $servicePort,
            // La IP que ya tomó el equipo, si la tiene: hace falta para entrar
            // a su página cuando la OLT sola no alcanza (C-Data).
            'ip'      => preg_match('/ONT IP\s*:\s*(\d{1,3}(?:\.\d{1,3}){3})/', $quedo, $mIp) ? $mIp[1] : null,
            'detalle' => $confirmado && $spOk
                ? "service-port {$servicePort} · pide IP de gestión en la VLAN {$vlan}"
                : (!$spOk
                    ? "La OLT no aceptó el service-port {$servicePort}: " . (self::primeraLinea($sp) ?: 'número ocupado')
                    : 'La ONT no tomó la VLAN de gestión: ' . self::primeraLinea($ip)),
        ];
    }

    /** Lo que contestó la OLT, sin el eco del comando. */
    private static function primeraLinea(string $salida): string
    {
        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            $linea = trim($linea);

            if ($linea !== '' && !preg_match('/^(service-port|ont ipconfig|vlan|port vlan)/i', $linea) && !str_contains($linea, '#')) {
                return mb_substr($linea, 0, 120);
            }
        }

        return 'sin detalle';
    }

    public function runCommand(string $command): string
    {
        $this->resetToPrompt();
        $this->ssh->write(trim($command) . "\n");
        return $this->collectPaged();
    }

    public function saveConfig(): void
    {
        $this->resetToPrompt();

        $this->ssh->setTimeout(8);
        $this->ssh->write("enable\n");
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("config\n");
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("save\n");

        // Huawei shows { <cr>|configuration<K>|data<K> }: before executing
        $this->ssh->setTimeout(10);
        $out = $this->ssh->read('/(?:\{\s*<cr>|[>#$]\s*$)/i');

        if (preg_match('/\{\s*<cr>/i', $out)) {
            $this->ssh->write("\n");
        }

        // Save can take several minutes on Huawei hardware
        $this->ssh->setTimeout(180);
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("quit\n");
        $this->ssh->setTimeout(10);
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->setTimeout(15);

        Log::info('HuaweiOLT saveConfig: configuración guardada en flash');
    }

    public function activateONT(string $fsp, int $ontId): bool
    {
        [$frame, $slot, $port] = $this->parseFsp($fsp);

        $this->ssh->write("interface gpon {$frame}/{$slot}\n");
        $this->ssh->read('/[>#$]\s*$/');

        $this->ssh->write("ont activate {$port} {$ontId}\n");
        $output = $this->collectOutput();

        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        return stripos($output, 'success') !== false
            || stripos($output, 'Succeeded') !== false;
    }

    // ── Parsing helpers ───────────────────────────────────────────────────

    private function parseUnauthONTs(string $raw): array
    {
        $onts   = [];
        $blocks = preg_split('/Number\s*:\s*\d+/i', $raw);

        foreach ($blocks as $block) {
            if (empty(trim($block))) continue;

            $ont = [];

            // F/S/P may have spaces: "0/ 0/ 0" — capture all three parts
            if (preg_match('/F\/S\/P\s*:\s*(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)/i', $block, $m)) {
                $ont['fsp'] = "{$m[1]}/{$m[2]}/{$m[3]}";
            }
            if (preg_match('/Ont SN\s*:\s*(\S+)/i', $block, $m)) {
                $ont['serial'] = $m[1];
            }
            if (preg_match('/Vendor ID\s*:\s*(\S+)/i', $block, $m)) {
                $ont['vendor'] = $m[1];
            }
            if (preg_match('/Ont Model\s*:\s*(.*)/i', $block, $m)) {
                $ont['model'] = trim($m[1]);
            }
            if (preg_match('/Distance\(m\)\s*:\s*(\d+)/i', $block, $m)) {
                $ont['distance_m'] = (int) $m[1];
            }

            if (isset($ont['serial'])) {
                $onts[] = $ont;
            }
        }

        return $onts;
    }

    /**
     * Lee la respuesta de "ont confirm".
     *
     * La OLT contesta así:
     *
     *   Number of ONTs that can be added: 1, success: 1
     *   PortID :3, ONTID :82
     *
     * Antes se daba por bueno cualquier texto que contuviera la palabra
     * "success", y esa línea aparece siempre — con 1 cuando funcionó y con 0
     * cuando no. El sistema informaba éxito en los dos casos. Hay que leer el
     * número.
     */
    private function parseRegistrationResponse(string $raw, int $portId): array
    {
        $ontId = null;

        if (preg_match('/ONTID\s*:\s*(\d+)/i', $raw, $m)) {
            $ontId = (int) $m[1];
        }

        if (preg_match('/PortID\s*:\s*(\d+)/i', $raw, $m)) {
            $portId = (int) $m[1];
        }

        // "success: N" — el dato que de verdad dice si entró.
        if (preg_match('/success\s*:\s*(\d+)/i', $raw, $m)) {
            $success = ((int) $m[1]) > 0;
        } else {
            // Sin esa línea, se acepta sólo si dio un ONTID y no hay error.
            $success = $ontId !== null && !$this->tieneError($raw);
        }

        return [
            'success'  => $success,
            'port_id'  => $portId,
            'ont_id'   => $ontId,
            'message'  => $this->mensajeDeLaOlt($raw),
        ];
    }

    /** ¿La OLT devolvió una queja? */
    private function tieneError(string $raw): bool
    {
        return (bool) preg_match('/(Failure|Failed|Error|invalid|does not exist|already exist)/i', $raw);
    }

    /**
     * La línea que le sirve a una persona, sin el eco del comando ni los
     * códigos del terminal.
     */
    private function mensajeDeLaOlt(string $raw): string
    {
        $limpio = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $raw);
        $utiles = [];

        foreach (preg_split('/\r?\n/', (string) $limpio) as $linea) {
            $linea = trim($linea);

            // Fuera el eco del comando y el prompt.
            if ($linea === '' || str_starts_with($linea, 'ont ') || str_contains($linea, '#')) {
                continue;
            }

            $utiles[] = $linea;
        }

        return trim(implode(' · ', $utiles)) ?: trim((string) $limpio);
    }

    /**
     * Parse output of "display ont info all"
     * Line format: frame/slot/port  ont_id  serial  control_flag  run_state  config_state  match_state [description...]
     */
    private function parseAuthorizedONTs(string $raw): array
    {
        $onts  = [];
        $lines = explode("\n", $raw);

        foreach ($lines as $line) {
            // Match tabular line: e.g. "  0/ 1/ 0    0    HWTC1234ABCD  active  online  normal  match"
            if (!preg_match(
                '/^\s*(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/i',
                $line,
                $m
            )) {
                continue;
            }

            $fsp    = "{$m[1]}/{$m[2]}/{$m[3]}";
            $ontId  = (int) $m[4];
            $serial = $m[5];
            // m[6]=control_flag, m[7]=run_state, m[8]=config_state, m[9]=match_state
            $runState = strtolower($m[7]);

            $onts[] = [
                'fsp'          => $fsp,
                'ont_id'       => $ontId,
                'serial'       => $serial,
                'control_flag' => $m[6],
                'status'       => $runState,
                'config_state' => $m[8],
                'match_state'  => $m[9],
                'description'  => '',
            ];
        }

        return $onts;
    }

    /**
     * Parse output of "display ont info F/S P ontId" and "display ont optical-info ..."
     */
    private function parseOntInfo(string $infoRaw, string $opticalRaw, string $fsp, int $ontId): array
    {
        $info = [
            'fsp'    => $fsp,
            'ont_id' => $ontId,
        ];

        // Parse key-value pairs from basic info
        $kvMap = [
            'serial'        => '/(?:SN|Serial\s+Number)\s*:\s*(\S+)/i',
            'vendor'        => '/Vendor\s+ID\s*:\s*(\S+)/i',
            'model'         => '/Equipment\s+type\s*:\s*(.*)/i',
            'status'        => '/Run\s+state\s*:\s*(\S+)/i',
            'config_state'  => '/Config\s+state\s*:\s*(\S+)/i',
            'match_state'   => '/Match\s+state\s*:\s*(\S+)/i',
            'description'   => '/Description\s*:\s*(.*)/i',
            'distance_m'    => '/Distance\(m\)\s*:\s*(\d+)/i',
            'last_down_cause' => '/Last\s+down\s+cause\s*:\s*(.*)/i',
            'last_down_time'  => '/Last\s+down\s+time\s*:\s*(.*)/i',
            'last_up_time'    => '/Last\s+up\s+time\s*:\s*(.*)/i',
        ];

        foreach ($kvMap as $key => $pattern) {
            if (preg_match($pattern, $infoRaw, $m)) {
                $info[$key] = trim($m[1]);
            }
        }

        // Parse optical info – try key-value format first
        $opticalKv = $this->parseOpticalKeyValue($opticalRaw);

        if (!empty($opticalKv)) {
            $info = array_merge($info, $opticalKv);
        } else {
            // Try tabular format: frame/slot/port  ont_id  rx_power  tx_power  ...
            if (preg_match(
                '/\s*\d+\s*\/\s*\d+\s*\/\s*\d+\s+\d+\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)/i',
                $opticalRaw,
                $m
            )) {
                $info['rx_optical_power_dbm'] = (float) $m[1];
                $info['tx_optical_power_dbm'] = (float) $m[2];
                $info['olt_rx_power_dbm']     = (float) $m[3];
                $info['olt_tx_power_dbm']     = (float) $m[4];
                $info['laser_bias_current_ma']= (float) $m[5];
            }
        }

        return $info;
    }

    /**
     * Parse key-value optical output lines like:
     * "  Rx optical power(dBm)              : -20.30"
     */
    private function parseOpticalKeyValue(string $raw): array
    {
        $result = [];

        $patterns = [
            'rx_optical_power_dbm'  => '/Rx\s+optical\s+power\s*\(dBm\)\s*:\s*([-\d.]+)/i',
            'tx_optical_power_dbm'  => '/Tx\s+optical\s+power\s*\(dBm\)\s*:\s*([-\d.]+)/i',
            'olt_rx_power_dbm'      => '/OLT\s+Rx\s+(?:optical\s+)?power\s*\(dBm\)\s*:\s*([-\d.]+)/i',
            'olt_tx_power_dbm'      => '/OLT\s+Tx\s+(?:optical\s+)?power\s*\(dBm\)\s*:\s*([-\d.]+)/i',
            'laser_bias_current_ma' => '/Laser\s+bias\s+current\s*\(mA\)\s*:\s*([-\d.]+)/i',
            'temperature_c'         => '/Temperature\s*\(C\)\s*:\s*([-\d.]+)/i',
            'supply_voltage_v'      => '/Supply\s+voltage\s*\(V\)\s*:\s*([-\d.]+)/i',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $raw, $m)) {
                $result[$key] = (float) $m[1];
            }
        }

        return $result;
    }

    /**
     * Parse output of "display service-port ..."
     * Tabular lines contain: index  vlan  F/S/P  ont_id  gemport  ...
     */
    // private function parseServicePorts(string $raw): array
    // {
    //     $ports = [];
    //     $lines = explode("\n", $raw);

    //     foreach ($lines as $line) {
    //         // Match a line that has: index(int)  ...  vlan(int)  ...  F/S/P  ...  ont_id(int)  gemport(int)
    //         // Typical Huawei format:
    //         // 1024   gpon  0/ 1/ 0   0   1   eth   vlan  100   100   ...
    //         // We look for lines that contain a F/S/P pattern
    //         if (!preg_match('/^\s*(\d+)\s+/', $line, $idxMatch)) {
    //             continue;
    //         }

    //         // Must contain a F/S/P pattern
    //         if (!preg_match('/(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)/', $line, $fspMatch)) {
    //             continue;
    //         }

    //         $index = (int) $idxMatch[1];
    //         $fsp   = "{$fspMatch[1]}/{$fspMatch[2]}/{$fspMatch[3]}";

    //         // Extract all integers from the line for positional parsing
    //         preg_match_all('/\b(\d+)\b/', $line, $allNums);
    //         $nums = array_map('intval', $allNums[1]);

    //         // First number is index, find the F/S/P position and take ont_id after it
    //         // We'll do a more targeted parse
    //         // Pattern: index  ...  F/S/P  ont_id  gemport  ...  vlan  ...
    //         // Try to find ont_id after F/S/P
    //         $ontId  = null;
    //         $gemport = null;
    //         $vlan   = null;

    //         // Remove the index from position consideration
    //         // Try to extract ont_id and gemport by position relative to F/S/P
    //         if (preg_match(
    //             '/(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s+(\d+)\s+(\d+)/i',
    //             $line,
    //             $afterFsp
    //         )) {
    //             $ontId   = (int) $afterFsp[4];
    //             $gemport = (int) $afterFsp[5];
    //         }

    //         // Extract vlan - look for "vlan" keyword followed by number, or user-vlan
    //         if (preg_match('/user-vlan\s+(\d+)/i', $line, $vlanMatch)) {
    //             $vlan = (int) $vlanMatch[1];
    //         } elseif (preg_match('/\bvlan\s+(\d+)/i', $line, $vlanMatch)) {
    //             $vlan = (int) $vlanMatch[1];
    //         } else {
    //             // Take a large-ish number that could be a VLAN (100-4094 range)
    //             foreach ($nums as $n) {
    //                 if ($n >= 1 && $n <= 4094 && $n !== $index) {
    //                     $vlan = $n;
    //                     break;
    //                 }
    //             }
    //         }

    //         if ($index > 0 && $ontId !== null) {
    //             $ports[] = [
    //                 'index'   => $index,
    //                 'vlan'    => $vlan ?? 0,
    //                 'fsp'     => $fsp,
    //                 'ont_id'  => $ontId,
    //                 'gemport' => $gemport ?? 1,
    //             ];
    //         }
    //     }

    //     return $ports;
    // }

    // ── Utilities ─────────────────────────────────────────────────────────

    /** Parse "frame/slot/port" into [frame, slot, port] as ints */
    private function parseFsp(string $fsp): array
    {
        // Strip Telnet control characters (SI, SO, etc.) that can corrupt FSP strings
        $fsp   = preg_replace('/[^\x20-\x7E]/', '', $fsp);
        $parts = array_map('intval', explode('/', $fsp));
        return count($parts) === 3 ? $parts : [0, 0, (int) $fsp];
    }

    /**
     * Drena cualquier output residual y regresa a un prompt limpio (#/>).
     * Necesario cuando se reutiliza la misma sesión Telnet entre comandos:
     * si el comando anterior dejó bytes sin leer en el buffer, el siguiente
     * comando los recibirá mezclados con su propia respuesta.
     */
    private function resetToPrompt(): void
    {
        // Lo que haya quedado de antes se descarta: si no, la respuesta de un
        // comando se lee como si fuera la del siguiente y todo llega corrido.
        if (method_exists($this->ssh, 'drenar')) {
            $sobrante = $this->ssh->drenar();

            if (trim($sobrante) !== '') {
                Log::debug('[OLT] Se descartó lo que quedó de un comando anterior', ['restos' => substr($sobrante, -200)]);
            }
        }

        try {
            $this->ssh->setTimeout(3);
            $this->ssh->write("\n");
            $chunk = $this->ssh->read('/[>#$]\s*$/');
            // Si quedó en un sub-prompt de Huawei ({ <cr>... }), salir con Enter
            if (preg_match('/\{\s*<cr>/i', $chunk)) {
                $this->ssh->write("\r\n");
                $this->ssh->read('/[>#$]\s*$/');
            }
        } catch (\Throwable) {
            // Timeout = buffer ya estaba limpio, está bien
        } finally {
            $this->ssh->setTimeout(30);
        }
    }

    /** Read until prompt or timeout */
    private function collectOutput(): string
    {
        try {
            return $this->ssh->read('/(?:(?<!<cr)>|[#$])\s*$/');
        } catch (\Throwable) {
            $this->desincronizada = true;

            return '';
        }
    }

    /**
     * Read paginated output that may contain "---- More ----" prompts.
     * Sends a space to advance each page until the full output is collected.
     */
 private function collectPaged(): string
{
    $fullOutput = '';

    // El prompt de verdad es una línea entera tipo "OLT-Huawei(config)#". Con
    // un simple "termina en > o #" alcanzaba la lista de opciones de un
    // sub-prompt ("{ <cr>|e2e<K>|gemport<K>") para cortar la lectura a la
    // mitad: se perdía la respuesta y quedaba para el comando siguiente.
    $finRe    = '/(?:^|\n)[^\s{}|<>]+(?:\([^)\n]*\))?[>#]\s*$/';
    // Esta versión muestra "---- More ( Press 'Q' to break ) ----": con un
    // patrón que esperaba "---- More ----" pegado nunca se pedía la página
    // siguiente y cada consulta larga esperaba 60 s a que la OLT cortara sola.
    $masRe    = '/----\s*More\b[^\n]*?----/i';
    $promptRe = '/(?:(?:^|\n)[^\s{}|<>]+(?:\([^)\n]*\))?[>#]\s*$|----\s*More\b[^\n]*?----|\{\s*<cr>|\(y\/n\)[^\n]*$)/i';

    // Cada página y cada sub-prompt es otra vuelta; si algo se traba, esperar
    // el tiempo completo en cada una hacía que un simple "display" tardara un
    // minuto. Después de la primera respuesta ya sabemos que la OLT contesta.
    $vueltas = 0;

    try {
        while (true) {

            $chunk = $this->ssh->read($promptRe);
            $fullOutput .= $chunk;

            if (++$vueltas === 1) {
                $this->ssh->setTimeout(8);
            }

            if ($vueltas > 40) {
                $this->desincronizada = true;
                break;
            }

            // 🔥 paginado
            // Una confirmación (y/n) no se contesta acá: decide quien mandó el
            // comando. Esperar un prompt que no llega colgaba la lectura.
            if (preg_match('/\(y\/n\)[^\n]*$/i', $chunk)) {
                break;
            }

            if (preg_match($masRe, $chunk)) {
                $this->ssh->write(' ');
                continue;
            }

            // 🔥 Huawei <cr>
            if (preg_match('/\{\s*<cr>/i', $chunk)) {
                $this->ssh->write("\r\n");
                continue;
            }

            // 🔥 fin real: se mira todo lo leído, porque el prompt puede llegar
            // partido entre dos lecturas.
            if (preg_match($finRe, $fullOutput)) {
                break;
            }
        }

    } catch (\Throwable $e) {
        // Se agotó el tiempo sin llegar al prompt: lo que quedó en el buffer
        // va a aparecer pegado a la respuesta del comando siguiente.
        $this->desincronizada = true;
    }

    $fullOutput = preg_replace('/[ \t]*----\s*More\b[^\n]*?----[ \t]*/i', "\n", $fullOutput);

    // Al pasar de página la OLT borra el aviso moviendo el cursor
    // ("\e[37D" + espacios + "\e[37D"). Esos códigos quedaban pegados al
    // principio de la primera fila de la página siguiente y la fila no se
    // reconocía: se perdía justo el renglón que venía después del corte.
    $fullOutput = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $fullOutput);

    return $fullOutput;
}
    /**
     * ¿La sesión quedó desincronizada?
     *
     * El worker la consulta después de cada comando: si quedó así, cierra la
     * sesión en vez de reutilizarla, porque el próximo comando leería la
     * respuesta a medias del anterior.
     */
    public function estaDesincronizada(): bool
    {
        return $this->desincronizada;
    }

    /**
     * Extract a single value using a regex pattern from raw text.
     */
    private function extractValue(string $raw, string $pattern): ?string
    {
        if (preg_match($pattern, $raw, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
