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

        $cmd = sprintf(
            "ont confirm %d sn-auth %s omci ont-lineprofile-id %d ont-srvprofile-id %d desc %s\n",
            $port,
            strtoupper($serial),
            $vlan,
            $lineProfileId,
            $description
        );

        $this->ssh->write($cmd);

        // Huawei puede mostrar un prompt { <cr>|... } antes de la respuesta real
        usleep(300000);
        $buffer = $this->ssh->read('/[>#$\]]\s*$/');

        if (str_contains($buffer, '{')) {
            $this->ssh->write("\r\n");
            usleep(200000);
            $output = $this->ssh->read('/[>#$\]]\s*$/');
            $output = $buffer . $output;
        } else {
            $output = $buffer;
        }

        Log::debug('HuaweiOLT registerONT: response', [
            'fsp'    => $fsp,
            'serial' => $serial,
            'output' => $output,
        ]);

        $result = $this->parseRegistrationResponse($output, $port);

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

            $spOk = stripos($spOutput, 'success') !== false || stripos($spOutput, 'Succeeded') !== false;

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

        return stripos($output, 'success') !== false
            || stripos($output, 'Succeeded') !== false;
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

        // Salir de config
        $this->ssh->write("quit\n");
        $this->ssh->read('/[>#$]\s*$/');

        return stripos($output, 'success') !== false
            || stripos($output, 'Succeeded') !== false;
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
    } else {
        //$command = "display service-port all";

        return []; // para evitar paginación masiva en OLTs grandes, requerimos fsp+ontId. Si no se dan, devolvemos array vacío.    
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

    public function runCommand(string $command): string
    {
        $this->resetToPrompt();
        $this->ssh->write(trim($command) . "\n");
        return $this->collectPaged();
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

    private function parseRegistrationResponse(string $raw, int $portId): array
    {
        $ontId = null;
        if (preg_match('/ONTID\s*:\s*(\d+)/i', $raw, $m)
            || preg_match('/ont\s+(\d+)\s+/i', $raw, $m)) {
            $ontId = (int) $m[1];
        }

        $success = stripos($raw, 'success') !== false
                || stripos($raw, 'Succeeded') !== false;

        return [
            'success'  => $success,
            'port_id'  => $portId,
            'ont_id'   => $ontId,
            'message'  => trim($raw),
        ];
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

    $promptRe = '/(?:[>#]\s*$|----\s*More\s*----|\{\s*<cr>)/i';

    try {
        while (true) {

            $chunk = $this->ssh->read($promptRe);
            $fullOutput .= $chunk;

            // 🔥 paginado
            if (preg_match('/----\s*More\s*----/i', $chunk)) {
                $this->ssh->write(' ');
                continue;
            }

            // 🔥 Huawei <cr>
            if (preg_match('/\{\s*<cr>/i', $chunk)) {
                $this->ssh->write("\r\n");
                continue;
            }

            // 🔥 fin real
            if (preg_match('/[>#]\s*$/', $chunk)) {
                break;
            }
        }

    } catch (\Throwable $e) {}

    $fullOutput = preg_replace('/\s*----\s*More\s*----\s*/i', "\n", $fullOutput);

    return $fullOutput;
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
