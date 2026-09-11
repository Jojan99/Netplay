<?php

namespace App\OltDrivers;

use Illuminate\Support\Facades\Log;

/**
 * OLT C-Data de la familia FD16xx / FD15xx (también se vende como ECOM).
 *
 * La consola de C-Data imita la de Huawei, con dos diferencias que importan:
 * el puerto PON se entra como "interface gpon <frame>/<slot>" y el número de
 * puerto va como primer argumento de cada comando de ONT. Es decir, donde
 * Huawei dice `interface gpon 0/1` + `ont add 3 ...`, C-Data dice lo mismo pero
 * el 3 es el puerto y el siguiente número es el ONT ID.
 *
 * Comandos según el manual de línea de comandos de la serie FD16xx:
 *   show ont autofind all
 *   ont add <puerto> <ontid> sn-auth "SN" omci ont-lineprofile-id N ont-srvprofile-id M desc "x"
 *   ont delete <puerto> <ontid>
 *   ont activate|deactivate <puerto> <ontid>
 *   show ont info <puerto> <ontid>
 *   show ont optical-info <puerto> <ontid>
 */
class CdataOltDriver extends DriverBase
{
    protected function abrirSesion(): void
    {
        $this->ssh->setTimeout(10);

        try {
            $this->ssh->read('/(?:[>#]\s*$|----\s*More\s*----|Username|Password)/i');
        } catch (\Throwable) {
            // Ya está en el prompt.
        }

        $salida = $this->cmd('enable', 8);

        if (preg_match('/[Pp]assword/', $salida) && $this->enablePassword) {
            $this->cmd($this->enablePassword, 8);
        }

        $this->cmd('config', 8);
        $this->primeraQueSirva(['screen-length 0', 'terminal length 0'], 5);

        $this->ssh->setTimeout(15);
    }

    /** "epon" o "gpon", según lo que acepte el equipo. Se averigua una vez. */
    private ?string $tecnologia = null;

    /**
     * C-Data vende las dos familias con la misma consola: las EPON ("EasyPath")
     * entran con `interface epon 0/0` y autorizan por MAC; las GPON con
     * `interface gpon 0/0` y por serial. Se prueba EPON y, si el equipo la
     * rechaza, es GPON.
     */
    private function tecnologia(): string
    {
        if ($this->tecnologia !== null) {
            return $this->tecnologia;
        }

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd('interface epon 0/0', 10);
        $this->volverAlPrompt();

        return $this->tecnologia = (!$this->fallo($salida) && stripos($salida, 'epon') !== false)
            ? 'epon'
            : 'gpon';
    }

    private function esEpon(): bool
    {
        return $this->tecnologia() === 'epon';
    }

    /** Entra al puerto PON y devuelve el número de puerto dentro de la tarjeta. */
    private function entrarAlPuerto(string $fsp): int
    {
        ['frame' => $f, 'slot' => $s, 'port' => $p] = $this->partirFsp($fsp);

        $tipo = $this->tecnologia();

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $this->cmd("interface {$tipo} {$f}/{$s}", 10);

        return $p;
    }

    /** Una MAC en el formato que pide la OLT: AA:BB:CC:DD:EE:FF. */
    private static function mac(string $valor): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $valor));

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    /**
     * La tabla de "show ont info 0/0 <puerto> all":
     *
     *   F/S  P  ONT MAC               Control   Run        Config   Match     Desc
     *           ID                    flag      state      state    state
     *   0/0  2  1   80:F7:A6:BD:C3:2A active    online     success  match
     *
     * El detalle de una sola ONT viene en bloques clave-valor y el listado en
     * esta tabla; leer la tabla con el parser de bloques no devolvía nada, y
     * el alta calculaba mal el siguiente ID libre.
     *
     * @return list<array{puerto:int, ont_id:int, mac:string, control:string, estado:string, descripcion:?string}>
     */
    private static function tablaDeOnts(string $salida): array
    {
        $filas = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match(
                '#^\s*\d+/\d+\s+(\d+)\s+(\d+)\s+([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})\s+(\S+)\s+(\S+)\s+\S+\s+\S+\s*(.*)$#',
                $linea,
                $m
            )) {
                continue;
            }

            $filas[] = [
                'puerto'      => (int) $m[1],
                'ont_id'      => (int) $m[2],
                'mac'         => strtoupper($m[3]),
                'control'     => strtolower($m[4]),
                'estado'      => strtolower($m[5]),
                'descripcion' => trim($m[6]) !== '' ? trim($m[6]) : null,
            ];
        }

        return $filas;
    }

    /**
     * Bloques clave-valor de "show ont info": uno por ONT, separados por
     * líneas de guiones. La misma forma que usa Huawei.
     *
     * @return list<array<string,string>>
     */
    private static function bloques(string $salida): array
    {
        $bloques = [];
        $actual  = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('/^\s*([A-Za-z][A-Za-z \/-]*?)\s*:\s*(.*?)\s*$/', $linea, $m)) {
                continue;
            }

            $clave = strtolower(trim($m[1]));

            // Un "Frame/Slot" nuevo abre el bloque de la ONT siguiente.
            if ($clave === 'frame/slot' && isset($actual['ont-id'])) {
                $bloques[] = $actual;
                $actual    = [];
            }

            $actual[$clave] = $m[2];
        }

        if (isset($actual['ont-id'])) {
            $bloques[] = $actual;
        }

        return $bloques;
    }

    // ── Consultas ─────────────────────────────────────────────────────────

    public function getVersion(): string
    {
        return $this->cmd('show version', 30) . "\n" . $this->cmd('show device', 30);
    }

    public function getUnauthONTs(): array
    {
        if ($this->esEpon()) {
            return $this->autofindEpon();
        }

        $this->volverAlPrompt();
        $intento = $this->primeraQueSirva([
            'show ont autofind all',
            'show ont autofind-info all',
        ], 40);

        return $this->leerAutofind($intento['salida']);
    }

    /**
     * C-Data lista cada ONT descubierta como un bloque de clave/valor:
     *
     *   F/S/P               : 0/0/1
     *   Ont SN              : CDTCAF53E6E3
     *   Password            :
     *   Ont Version         : ...
     */
    private function leerAutofind(string $salida): array
    {
        $onts   = [];
        $actual = [];

        $cerrar = function () use (&$actual, &$onts) {
            if (!empty($actual['serial']) && !empty($actual['fsp'])) {
                $onts[] = $actual + ['ont_id' => null, 'status' => 'autofind'];
            }

            $actual = [];
        };

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('#F/S/P\s*:\s*(\d+/\d+/\d+)#i', $linea, $m)) {
                $cerrar();
                $actual['fsp'] = $m[1];
                continue;
            }

            if (preg_match('/Ont\s*SN\s*:\s*([0-9A-Fa-f]{8,20})/i', $linea, $m)) {
                $actual['serial'] = strtoupper($m[1]);
                $actual['vendor'] = substr(strtoupper($m[1]), 0, 4);
                continue;
            }

            if (preg_match('/Password\s*:\s*(\S+)/i', $linea, $m)) {
                $actual['password'] = $m[1];
            }
        }

        $cerrar();

        // Algunos firmware devuelven una tabla en una sola línea por ONT.
        if (!$onts) {
            foreach (preg_split('/\r?\n/', $salida) as $linea) {
                if (preg_match('#^\s*(\d+/\d+/\d+)\s+([0-9A-Fa-f]{8,20})#', $linea, $m)) {
                    $onts[] = [
                        'fsp'      => $m[1],
                        'serial'   => strtoupper($m[2]),
                        'vendor'   => substr(strtoupper($m[2]), 0, 4),
                        'ont_id'   => null,
                        'status'   => 'autofind',
                        'password' => null,
                    ];
                }
            }
        }

        return $onts;
    }

    public function getAuthorizedONTs(): array
    {
        if ($this->esEpon()) {
            return $this->ontsEpon();
        }

        $this->volverAlPrompt();
        $intento = $this->primeraQueSirva([
            'show ont info all',
            'show ont-info all',
        ], 90);

        return $this->leerOntInfo($intento['salida']);
    }

    /**
     *   0/0/1   1   CDTCAF53E6E3   online    active   Juan Perez
     */
    private function leerOntInfo(string $salida): array
    {
        $onts = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('#^\s*(\d+/\d+/\d+)\s+(\d+)\s+([0-9A-Za-z]{8,20})\s+(\S+)(?:\s+(\S+))?(?:\s+(.*))?$#', $linea, $m)) {
                continue;
            }

            $onts[] = [
                'fsp'         => $m[1],
                'ont_id'      => (int) $m[2],
                'serial'      => strtoupper($m[3]),
                'status'      => str_contains(strtolower($m[4]), 'online') ? 'online' : 'offline',
                'admin'       => strtolower(trim($m[5] ?? '')),
                'description' => trim($m[6] ?? '') ?: null,
            ];
        }

        return $onts;
    }

    public function getOntInfo(string $fsp, int $ontId): array
    {
        if ($this->esEpon()) {
            return $this->infoEpon($fsp, $ontId);
        }

        $puerto  = $this->entrarAlPuerto($fsp);
        $detalle = $this->cmd("show ont info {$puerto} {$ontId}", 30);
        $optico  = $this->cmd("show ont optical-info {$puerto} {$ontId}", 30);
        $this->volverAlPrompt();

        return [
            'fsp'         => $fsp,
            'ont_id'      => $ontId,
            'serial'      => $this->dato($detalle, '/(?:Ont\s*)?SN\s*:\s*(\S+)/i'),
            'status'      => str_contains(strtolower($detalle), 'online') ? 'online' : 'offline',
            'description' => $this->dato($detalle, '/Descri\w*\s*:\s*(.+)/i'),
            'distancia_m' => $this->numero($detalle, '/Distance\s*\(?m?\)?\s*:\s*(-?[\d.]+)/i'),
            'ont_rx'      => $this->numero($optico, '/(?:ONT|Rx)\s*[Oo]ptical\s*[Pp]ower\s*:\s*(-?[\d.]+)/i')
                             ?? $this->numero($optico, '/Rx\s*power\s*:\s*(-?[\d.]+)/i'),
            'ont_tx'      => $this->numero($optico, '/Tx\s*power\s*:\s*(-?[\d.]+)/i'),
            'olt_rx'      => $this->numero($optico, '/OLT\s*Rx\s*(?:power)?\s*:\s*(-?[\d.]+)/i'),
            'temperatura' => $this->numero($optico, '/Temperature\s*:\s*(-?[\d.]+)/i'),
            'raw'         => $detalle . "\n" . $optico,
        ];
    }

    public function getServicePorts(?string $fsp = null, ?int $ontId = null): array
    {
        $this->volverAlPrompt();
        $salida = $this->cmd('show service-port all', 60);

        $puertos = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('#^\s*(\d+)\s+(\d+)\s+gpon\s+(\d+/\d+)\s*/?\s*(\d+)?\s+(\d+)#i', $linea, $m)) {
                continue;
            }

            $linFsp  = $m[3] . '/' . ($m[4] ?? '0');
            $linOnt  = (int) $m[5];

            if ($fsp !== null && $linFsp !== $fsp) {
                continue;
            }

            if ($ontId !== null && $linOnt !== $ontId) {
                continue;
            }

            $puertos[] = [
                'index'   => (int) $m[1],
                'vlan'    => (int) $m[2],
                'fsp'     => $linFsp,
                'ont_id'  => $linOnt,
                'gemport' => null,
            ];
        }

        return $puertos;
    }

    // ── EPON (verificado contra una C-Data "EasyPath Ethernet-PON") ──────

    /** Los puertos PON que tiene el equipo. La EPON de C-Data acepta 1-4. */
    private const PUERTOS_EPON = [1, 2, 3, 4];

    /**
     * ONU que la OLT encontró pero no están autorizadas.
     *
     *   show ont autofind <puerto> all
     */
    private function autofindEpon(): array
    {
        $onts = [];

        foreach (self::PUERTOS_EPON as $puerto) {
            $this->entrarAlPuerto("0/0/{$puerto}");
            $salida = $this->cmd("show ont autofind {$puerto} all", 30);

            foreach (preg_split('/\r?\n/', $salida) as $linea) {
                if (!preg_match('/([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', $linea, $m)) {
                    continue;
                }

                $mac = strtoupper($m[1]);

                $onts[$mac] = [
                    'fsp'      => "0/0/{$puerto}",
                    'serial'   => $mac,
                    'vendor'   => null,
                    'ont_id'   => null,
                    'status'   => 'autofind',
                    'password' => null,
                ];
            }
        }

        $this->volverAlPrompt();

        return array_values($onts);
    }

    /**
     * Las ONU autorizadas, puerto por puerto.
     *
     *   show ont info 0/0 <puerto> all
     */
    private function ontsEpon(): array
    {
        $onts = [];

        $this->volverAlPrompt();
        $this->cmd('config', 8);

        foreach (self::PUERTOS_EPON as $puerto) {
            $salida = $this->cmd("show ont info 0/0 {$puerto} all", 60);

            foreach (self::tablaDeOnts($salida) as $f) {
                $onts[] = [
                    'fsp'         => '0/0/' . $f['puerto'],
                    'ont_id'      => $f['ont_id'],
                    'serial'      => $f['mac'],
                    'status'      => $f['estado'] === 'online' ? 'online' : 'offline',
                    'description' => $f['descripcion'],
                ];
            }
        }

        $this->volverAlPrompt();

        return $onts;
    }

    /** El detalle de una ONU: estado, MAC, distancia, perfil. */
    private function infoEpon(string $fsp, int $ontId): array
    {
        ['port' => $puerto] = $this->partirFsp($fsp);

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd("show ont info 0/0 {$puerto} {$ontId}", 30);
        $this->volverAlPrompt();

        $b = self::bloques($salida)[0] ?? [];

        return [
            'fsp'           => $fsp,
            'ont_id'        => $ontId,
            'serial'        => self::mac($b['mac'] ?? '') ?? null,
            'status'        => strtolower($b['run state'] ?? '') === 'online' ? 'online' : 'offline',
            'description'   => ($b['description'] ?? '') !== '' ? $b['description'] : null,
            'distancia_m'   => isset($b['ont distance']) ? (int) $b['ont distance'] : null,
            'modo_auth'     => $b['auth mode'] ?? null,
            'perfil_linea'  => $b['line profile name'] ?? null,
            'raw'           => $salida,
        ];
    }

    /**
     * Autoriza una ONU por MAC.
     *
     *   interface epon 0/0
     *   ont add <puerto> <id> mac-auth <MAC> [ont-lineprofile-id <n>]
     *   ont description <puerto> <id> <texto>
     *
     * El perfil de línea sólo se manda si el alta lo pide explícitamente: sin
     * él la OLT usa el suyo por defecto, que es el que tienen las ONU que ya
     * están andando. Mandar el valor por defecto de la plataforma (10) fallaba
     * en un equipo que no tiene ese perfil.
     */
    private function altaEpon(string $fsp, string $serial, string $description, ?int $lineProfileId): array
    {
        $mac = self::mac($serial);

        if ($mac === null) {
            return [
                'success' => false,
                'ont_id'  => 0,
                'message' => "«{$serial}» no es una MAC válida: en EPON la ONU se autoriza por MAC.",
            ];
        }

        $puerto = $this->entrarAlPuerto($fsp);
        $ontId  = $this->siguienteOntIdEpon($puerto);

        if ($ontId === null) {
            $this->volverAlPrompt();

            return ['success' => false, 'ont_id' => 0, 'message' => "El puerto {$fsp} ya tiene sus 64 ONU."];
        }

        // Buscar el siguiente ID sale de config: hay que volver a la interfaz.
        $this->entrarAlPuerto($fsp);

        $comando = "ont add {$puerto} {$ontId} mac-auth {$mac}"
            . ($lineProfileId !== null ? " ont-lineprofile-id {$lineProfileId}" : '');

        $salida = $this->cmd($comando, 30);

        if ($this->fallo($salida)) {
            $this->volverAlPrompt();

            Log::error('[OLT C-Data EPON] Alta de ONU rechazada', [
                'fsp' => $fsp, 'mac' => $mac, 'comando' => $comando, 'salida' => $salida,
            ]);

            return ['success' => false, 'ont_id' => 0, 'message' => $this->mensaje($salida)];
        }

        if ($description !== '') {
            // La descripción no admite espacios sin comillas; hasta 64 caracteres.
            $texto = substr(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $description), 0, 64);
            $this->cmd("ont description {$puerto} {$ontId} {$texto}", 15);
        }

        $this->volverAlPrompt();

        return [
            'success'              => true,
            'ont_id'               => $ontId,
            'port_id'              => $puerto,
            'message'              => "ONU {$mac} autorizada en {$fsp} con ID {$ontId}",
            // El service-port de EPON no está implementado todavía.
            'service_port_created' => false,
        ];
    }

    /** El primer ID libre del puerto, leyendo las ONU que ya tiene. */
    private function siguienteOntIdEpon(int $puerto): ?int
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);

        $ocupados = array_column(
            self::tablaDeOnts($this->cmd("show ont info 0/0 {$puerto} all", 60)),
            'ont_id'
        );

        for ($id = 1; $id <= 64; $id++) {
            if (!in_array($id, $ocupados, true)) {
                return $id;
            }
        }

        return null;
    }

    // ── Autorización automática ───────────────────────────────────────────

    /**
     * Qué puertos autorizan solos las ONU que encuentran.
     *
     * En la C-Data EPON esto es el modo de autenticación de cada puerto:
     * `ont authmode <puerto> auto` deja pasar cualquier ONU por MAC y la agrega
     * a la configuración; `mac` sólo deja pasar las que se agregaron a mano.
     * (`ont policy-auth` es otra cosa: autorizar según fabricante o modelo.)
     *
     * Se lee de la configuración. Un puerto sin línea `ont authmode` está en el
     * valor de fábrica, que en este firmware se comporta como `auto`: con
     * policy-auth apagado, una ONU borrada de un puerto así volvió a quedar
     * autorizada sola en segundos.
     *
     * @return array<int, array{auto:bool, modo:string, de_fabrica:bool}>|null
     */
    public function autoAutorizacion(): ?array
    {
        if (!$this->esEpon()) {
            return null;
        }

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $config = $this->cmd('show current-config section epon all', 60);
        $this->volverAlPrompt();

        $explicitos = [];

        if (preg_match_all('/^\s*ont authmode\s+(\d)\s+(\S+)/mi', $config, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $explicitos[(int) $x[1]] = strtolower($x[2]);
            }
        }

        $puertos = [];

        foreach (self::PUERTOS_EPON as $p) {
            $modo = $explicitos[$p] ?? 'auto';

            $puertos[$p] = [
                'auto'       => $modo === 'auto',
                'modo'       => $modo,
                'de_fabrica' => !isset($explicitos[$p]),
            ];
        }

        return $puertos;
    }

    /**
     * Prende o apaga la autorización automática de un puerto y devuelve cómo
     * quedaron todos, releídos de la OLT.
     *
     * Apagarla no toca las ONU ya autorizadas: siguen con su `ont add`. Sólo
     * cambia qué pasa con las nuevas, que quedan esperando en el autofind.
     */
    public function cambiarAutoAutorizacion(bool $activar, ?int $puerto = null): ?array
    {
        if (!$this->esEpon() || $puerto === null || !in_array($puerto, self::PUERTOS_EPON, true)) {
            return null;
        }

        $this->entrarAlPuerto("0/0/{$puerto}");
        $salida = $this->cmd("ont authmode {$puerto} " . ($activar ? 'auto' : 'mac'), 20);
        $this->volverAlPrompt();

        if ($this->fallo($salida)) {
            Log::error('[OLT C-Data] No se pudo cambiar el modo de autenticación', [
                'puerto' => $puerto, 'salida' => $salida,
            ]);
        }

        return $this->autoAutorizacion();
    }

    // ── Altas y bajas ─────────────────────────────────────────────────────

    public function registerONT(
        string $fsp,
        string $serial,
        string $description,
        ?int $lineProfileId = null,
        ?int $srvProfileId = null,
        ?int $vlan = null,
        ?int $servicePort = null
    ): array {
        if ($this->esEpon()) {
            return $this->altaEpon($fsp, $serial, $description, $lineProfileId);
        }

        $puerto = $this->entrarAlPuerto($fsp);
        $ontId  = $this->siguienteOntId($fsp, $puerto);

        if ($ontId === null) {
            $this->volverAlPrompt();

            return ['success' => false, 'ont_id' => 0, 'message' => 'No quedan ONT ID libres en el puerto ' . $fsp];
        }

        $comando = sprintf(
            'ont add %d %d sn-auth "%s" omci ont-lineprofile-id %d ont-srvprofile-id %d desc "%s"',
            $puerto,
            $ontId,
            strtoupper($serial),
            $lineProfileId ?? $this->lineProfileId,
            $srvProfileId  ?? $this->srvProfileId,
            substr($description, 0, 32)
        );

        $salida = $this->cmd($comando, 30);

        // Sin perfiles el firmware viejo acepta la forma corta.
        if ($this->fallo($salida)) {
            $salida = $this->cmd(
                sprintf('ont add %d %d sn-auth "%s"', $puerto, $ontId, strtoupper($serial)),
                30
            );
        }

        if ($this->fallo($salida)) {
            $this->volverAlPrompt();

            Log::error('[OLT C-Data] Alta de ONT rechazada', [
                'fsp' => $fsp, 'serial' => $serial, 'salida' => $salida,
            ]);

            return ['success' => false, 'ont_id' => 0, 'message' => $this->mensaje($salida)];
        }

        $servicioOk = true;

        if ($vlan) {
            $servicioOk = $this->servicio($puerto, $ontId, $vlan, $servicePort ?: $ontId, $fsp, $description);
        }

        $this->volverAlPrompt();

        return [
            'success'     => true,
            'ont_id'      => $ontId,
            'port_id'     => $puerto,
            'message'     => "ONT registrada en {$fsp} con ONT ID {$ontId}",
            'servicio_ok' => $servicioOk,
        ];
    }

    /** El primer ONT ID libre del puerto. */
    private function siguienteOntId(string $fsp, int $puerto): ?int
    {
        $ocupados = [];

        foreach ($this->leerOntInfo($this->cmd("show ont info {$puerto} all", 40)) as $ont) {
            $ocupados[] = (int) $ont['ont_id'];
        }

        for ($id = 1; $id <= 128; $id++) {
            if (!in_array($id, $ocupados, true)) {
                return $id;
            }
        }

        return null;
    }

    /** El service-port y la VLAN del puerto de usuario. */
    private function servicio(int $puerto, int $ontId, int $vlan, int $servicePort, string $fsp, string $description): bool
    {
        ['frame' => $f, 'slot' => $s] = $this->partirFsp($fsp);

        $salida = $this->cmd(sprintf('ont port native-vlan %d %d eth 1 vlan %d', $puerto, $ontId, $vlan), 20);

        $this->volverAlPrompt();
        $this->cmd('config', 8);

        $salida .= $this->cmd(sprintf(
            'service-port %d vlan %d gpon %d/%d %d ont %d gemport 1 multi-service user-vlan %d',
            $servicePort, $vlan, $f, $s, $puerto, $ontId, $vlan
        ), 20);

        return !$this->fallo($salida);
    }

    public function deleteONT(string $fsp, int $ontId, array $servicePorts = []): bool
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);

        foreach ($servicePorts as $indice) {
            $this->cmd('no service-port ' . (int) $indice, 20);
        }

        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("ont delete {$puerto} {$ontId}", 30);
        $this->volverAlPrompt();

        if ($this->fallo($salida)) {
            Log::error('[OLT C-Data] Baja de ONT rechazada', [
                'fsp' => $fsp, 'ont_id' => $ontId, 'salida' => $salida,
            ]);

            return false;
        }

        return true;
    }

    public function assignToClient(string $fsp, int $ontId, int $vlan, int $servicePort, string $description): bool
    {
        $puerto = $this->entrarAlPuerto($fsp);
        $ok     = $this->servicio($puerto, $ontId, $vlan, $servicePort, $fsp, $description);
        $this->volverAlPrompt();

        return $ok;
    }

    public function transferONT(string $fromFsp, int $ontId, string $toFsp): array
    {
        $info = $this->getOntInfo($fromFsp, $ontId);

        if (empty($info['serial'])) {
            return ['success' => false, 'new_ont_id' => 0, 'message' => 'No se pudo leer el serial de la ONT en ' . $fromFsp];
        }

        if (!$this->deleteONT($fromFsp, $ontId)) {
            return ['success' => false, 'new_ont_id' => 0, 'message' => 'No se pudo borrar la ONT de ' . $fromFsp];
        }

        $alta = $this->registerONT($toFsp, $info['serial'], (string) ($info['description'] ?? ''));

        return [
            'success'    => (bool) ($alta['success'] ?? false),
            'new_ont_id' => (int) ($alta['ont_id'] ?? 0),
            'message'    => $alta['message'] ?? '',
        ];
    }

    public function deactivateONT(string $fsp, int $ontId): bool
    {
        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("ont deactivate {$puerto} {$ontId}", 20);
        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    public function activateONT(string $fsp, int $ontId): bool
    {
        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("ont activate {$puerto} {$ontId}", 20);
        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    public function getLineProfiles(): array
    {
        $this->volverAlPrompt();

        return $this->leerPerfiles($this->primeraQueSirva([
            'show ont-lineprofile gpon all',
            'show ont-lineprofile all',
        ], 30)['salida']);
    }

    public function getSrvProfiles(): array
    {
        $this->volverAlPrompt();

        return $this->leerPerfiles($this->primeraQueSirva([
            'show ont-srvprofile gpon all',
            'show ont-srvprofile all',
        ], 30)['salida']);
    }

    /** @return array<int,string> */
    private function leerPerfiles(string $salida): array
    {
        $perfiles = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('/^\s*(\d+)\s+(\S+)/', $linea, $m)) {
                $perfiles[(int) $m[1]] = $m[2];
            }
        }

        return $perfiles;
    }

    private function dato(string $raw, string $patron): ?string
    {
        return preg_match($patron, $raw, $m) ? trim($m[1]) : null;
    }

    private function numero(string $raw, string $patron): ?float
    {
        return preg_match($patron, $raw, $m) ? (float) $m[1] : null;
    }
}
