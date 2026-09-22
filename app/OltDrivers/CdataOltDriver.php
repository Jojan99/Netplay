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
    /**
     * La grilla de comandos: qué se manda en cada operación según la familia.
     *
     * EPON: verificada contra una C-Data "EasyPath Ethernet-PON".
     * GPON: verificada contra una FD1608S-B1, firmware V3.2.14 (sep-2026). Los
     * comandos que venían del manual y ese firmware no acepta quedan en
     * GPON_VIEJOS, que se prueban sólo si la variante verificada es rechazada.
     *
     * {fs} frame/slot · {p} puerto PON · {id} ONT ID · {sn} serial · {mac} MAC
     */
    private const GRILLA = [
        'epon' => [
            'modo'             => 'epon',
            'max_ont'          => 64,
            'entrar_puerto'    => 'interface epon {fs}',
            'listar_puerto'    => 'show ont info 0/0 {p} all',        // desde config
            'autofind_puerto'  => 'show ont autofind {p} all',        // dentro de la interfaz
            'info'             => 'show ont info 0/0 {p} {id}',       // desde config
            'descripcion'      => 'ont description {p} {id} {texto}',
            'baja'             => 'ont delete {p} {id}',
            'activar'          => 'ont activate {p} {id}',
            'desactivar'       => 'ont deactivate {p} {id}',
            'perfiles_linea'   => ['show ont-lineprofile epon all'],
            'perfiles_srv'     => ['show ont-srvprofile epon all'],
        ],
        'gpon' => [
            'modo'             => 'gpon',
            'max_ont'          => 128,
            'entrar_puerto'    => 'interface gpon {fs}',
            'listar_todas'     => 'show ont info all',                // desde config: todos los puertos
            'listar_puerto'    => 'show ont info {p} all',            // dentro de la interfaz
            'autofind'         => ['show ont autofind all', 'show ont autofind-info all'],
            'info'             => 'show ont info {p} {id}',           // dentro de la interfaz
            'optico'           => 'show ont optical-info {p} {id}',   // dentro de la interfaz
            'alta_msp'         => 'ont add {p} {id} sn-auth {sn} mult-srv-profile profile-id {msp}',
            'alta'             => 'ont add {p} {id} sn-auth {sn} ont-lineprofile-id {lp} ont-srvprofile-id {sp}',
            'mult_srv'         => 'show ont mult-srv-profile gpon all',
            // Sin comillas: con comillas la OLT las guarda como parte del texto.
            'descripcion'      => 'ont description {p} {id} {texto}',
            'native_vlan'      => 'ont port native-vlan {p} {id} eth 1 vlan {vlan}',
            'service_port'     => 'service-port {idx} vlan {vlan} gpon {fs} port {p} ont {id} gemport 1 multi-service user-vlan {vlan}',
            'service_ports'    => 'show service-port all',
            'baja_sp'          => 'no service-port {idx}',
            'baja'             => 'ont delete {p} {id}',
            'activar'          => 'ont activate {p} {id}',
            'desactivar'       => 'ont deactivate {p} {id}',
            'perfiles_linea'   => ['show ont-line-profile gpon all', 'show ont-lineprofile gpon all', 'show ont-lineprofile all'],
            'perfiles_srv'     => ['show ont-srv-profile gpon all', 'show ont-srvprofile gpon all', 'show ont-srvprofile all'],
        ],
    ];

    /** Formas del manual de la serie FD16xx que firmwares anteriores podrían aceptar. */
    private const GPON_VIEJOS = [
        // Sin la forma corta "ont add P ID sn-auth SN": la aceptaba y dejaba la
        // ONT sin perfil de línea ni de servicio, es decir sin internet.
        'alta'         => ['ont add {p} {id} sn-auth "{sn}" omci ont-lineprofile-id {lp} ont-srvprofile-id {sp} desc "{texto}"'],
        'service_port' => ['service-port {idx} vlan {vlan} gpon {fs} {p} ont {id} gemport 1 multi-service user-vlan {vlan}'],
    ];

    /** El firmware GPON responde "There is no matched command" a lo que no reconoce. */
    protected array $errores = [
        'Failure', 'Error', 'error:', 'Invalid', 'Unknown command',
        '% Unknown', 'Incomplete', 'does not exist', 'already exist',
        'Command is in use', 'not support', 'no matched command',
    ];

    /** Un comando de la grilla con sus valores puestos. */
    private function comando(string $accion, array $valores = []): string
    {
        $plantilla = self::GRILLA[$this->tecnologia()][$accion];

        return self::llenar(is_array($plantilla) ? $plantilla[0] : $plantilla, $valores);
    }

    /** @return list<string> todas las variantes de una acción, la verificada primero */
    private function variantes(string $accion, array $valores = []): array
    {
        $plantillas = (array) self::GRILLA[$this->tecnologia()][$accion];

        if ($this->tecnologia() === 'gpon') {
            $plantillas = array_merge($plantillas, self::GPON_VIEJOS[$accion] ?? []);
        }

        return array_map(fn ($t) => self::llenar($t, $valores), $plantillas);
    }

    private static function llenar(string $plantilla, array $valores): string
    {
        return strtr($plantilla, array_combine(
            array_map(fn ($k) => '{' . $k . '}', array_keys($valores)),
            array_map('strval', array_values($valores))
        ) ?: []);
    }

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

        // Se averigua de entrada: hacerlo en medio de una operación pasaba por
        // volverAlPrompt() y sacaba a la sesión del modo en que estaba.
        $this->tecnologia();

        $this->ssh->setTimeout(15);
    }

    /**
     * Vuelve al modo privilegiado (#) saliendo de a un submodo por vez.
     *
     * `end` no sirve en todos lados: dentro de un perfil de línea el firmware
     * GPON V3.2 contesta "Unknown command" y la sesión quedaba atrapada ahí, con
     * lo que cada comando siguiente fallaba (la relectura del perfil, el perfil
     * que seguía, el puerto de subida). Con `exit` se sale de cualquier submodo.
     */
    protected function volverAlPrompt(): void
    {
        try {
            $this->ssh->setTimeout(4);

            for ($i = 0; $i < 6; $i++) {
                $this->ssh->write("\n");
                $prompt = (string) $this->ssh->read('/(?:[>#]\s*$|\(y\/n\)[^\n]*$)/i');

                if (preg_match('/\(y\/n\)[^\n]*$/i', $prompt)) {
                    $this->ssh->write("n\n");
                    $this->ssh->read($this->prompt);
                    continue;
                }

                if (preg_match('/\((?:config)[^)]*\)#\s*$/i', $prompt)) {
                    $this->ssh->write("exit\n");
                    $this->ssh->read($this->prompt);
                    continue;
                }

                if (preg_match('/>\s*$/', $prompt)) {
                    $this->ssh->write("enable\n");
                    $respuesta = (string) $this->ssh->read('/(?:[>#]\s*$|[Pp]assword)/');

                    if (preg_match('/[Pp]assword/', $respuesta) && $this->enablePassword) {
                        $this->ssh->write($this->enablePassword . "\n");
                        $this->ssh->read($this->prompt);
                    }

                    continue;
                }

                return;
            }
        } catch (\Throwable) {
            // El buffer ya estaba limpio.
        } finally {
            $this->ssh->setTimeout(15);
        }
    }

    /**
     * Guarda la configuración en la flash.
     *
     * En el firmware GPON V3.2 `save` sólo existe dentro de `config`; desde el
     * modo privilegiado no hay write/save/copy. Antes se probaban sólo esos y
     * se daba por guardado aunque la OLT contestara que no existían.
     */
    public function saveConfig(): void
    {
        $this->volverAlPrompt();

        if ($this->primeraQueSirva(['write', 'save', 'copy running-config startup-config'], 180)['comando'] !== null) {
            return;
        }

        $this->cmd('config', 8);
        $salida = $this->cmd('save', 180);

        if (preg_match('/\(y\/n\)/i', $salida)) {
            $salida .= $this->cmd('y', 180);
        }

        $this->volverAlPrompt();

        if ($this->fallo($salida)) {
            throw new \RuntimeException('La OLT no guardó la configuración: ' . $this->mensaje($salida));
        }
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

        // Cuenta el prompt, no la palabra: el eco del comando ya dice "epon".
        return $this->tecnologia = (!$this->fallo($salida) && preg_match('/\(config-[a-z-]*epon[^)]*\)#/i', $salida))
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

        // El comando se arma antes de moverse: en una sesión recién abierta
        // averiguar si es EPON o GPON pasa por volverAlPrompt(), y hacerlo ya
        // dentro de config sacaba a la sesión de ahí. "ont delete" llegaba al
        // modo privilegiado y la OLT contestaba "Unknown command".
        $entrar = $this->comando('entrar_puerto', ['fs' => "{$f}/{$s}"]);

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $this->cmd($entrar, 10);

        return $p;
    }

    /** Una MAC en el formato que pide la OLT: AA:BB:CC:DD:EE:FF. */
    private static function mac(string $valor): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $valor));

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    /**
     * La tabla de ONTs autorizadas. Es la misma en EPON y GPON salvo el
     * identificador (MAC o serial) y la columna de última causa de caída, que
     * el firmware GPON agrega antes de la descripción:
     *
     *   EPON  F/S  P  ONT MAC               Control Run    Config  Match  Desc
     *         0/0  2  1   80:F7:A6:BD:C3:2A active  online success match
     *   GPON  F/S P  ONT SN            Control Run    Config  Match Last down-cause Desc
     *         0/0 1  112 MSTC8CCE4565  Active  Online success match dying-gasp      Sindi ortega
     *
     * @return list<array{puerto:int, ont_id:int, mac:string, control:string, estado:string, descripcion:?string}>
     */
    private static function tablaDeOnts(string $salida): array
    {
        $filas    = [];
        $conCausa = (bool) preg_match('/down-?cause/i', $salida);

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match(
                '#^\s*\d+/\d+\s+(\d+)\s+(\d+)\s+([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5}|[A-Za-z0-9]{8,20})\s+(\S+)\s+(\S+)\s+\S+\s+\S+[ \t]*(.*)$#',
                $linea,
                $m
            )) {
                continue;
            }

            $resto = trim($m[6]);

            if ($conCausa && $resto !== '') {
                $resto = trim((string) (preg_split('/\s+/', $resto, 2)[1] ?? ''));
            }

            $filas[] = [
                'puerto'      => (int) $m[1],
                'ont_id'      => (int) $m[2],
                'mac'         => strtoupper($m[3]),
                'control'     => strtolower($m[4]),
                'estado'      => strtolower($m[5]),
                'descripcion' => $resto !== '' ? $resto : null,
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

    /**
     * El equipo del cliente: fabricante, modelo, versiones y su WiFi.
     *
     * Las ONU EPON se gestionan desde la OLT por OAM (CTC), así que la OLT
     * sabe qué equipo es y cómo tiene configurado el WiFi, clave incluida:
     *
     *   interface epon 0/0
     *   show ont version <puerto> <ont>
     *   show ont wifi info <puerto> <ont>
     *   show ont wifi <puerto> <ont> ssid all
     *
     * Sólo lee. En GPON no hay equivalente y se devuelve vacío.
     *
     * @return array<string,mixed>
     */
    public function equipoDeOnt(string $fsp, int $ontId): array
    {
        if (!$this->esEpon()) {
            return [];
        }

        $puerto = $this->entrarAlPuerto($fsp);

        $version = $this->cmd("show ont version {$puerto} {$ontId}", 30);
        $wifi    = $this->cmd("show ont wifi info {$puerto} {$ontId}", 30);
        $ssids   = $this->cmd("show ont wifi {$puerto} {$ontId} ssid all", 30);

        $this->volverAlPrompt();

        $v = self::bloques($version)[0] ?? [];
        $w = self::bloques($wifi)[0] ?? [];

        // "25AR(0x32354152)" → "25AR": lo de paréntesis es el mismo código en hex.
        $modelo = trim(preg_replace('/\(0x[0-9a-f]+\)/i', '', $v['ont model'] ?? '')) ?: null;

        return [
            'version' => $v ? [
                'fabricante_id' => ($v['vendor-id'] ?? '') ?: null,
                'modelo'        => $modelo,
                'modelo_ext'    => ($v['extended model'] ?? '') ?: null,
                'hardware'      => ($v['ont hardware version'] ?? '') ?: null,
                'software'      => ($v['ont software version'] ?? '') ?: null,
                'firmware'      => ($v['ont firmware version'] ?? '') ?: null,
                'chipset'       => trim(($v['ont chipset vendor id'] ?? '') . ' ' . ($v['ont chipset model'] ?? '')) ?: null,
                'oui'           => ($v['oui version'] ?? '') ?: null,
            ] : null,
            // Las ONU de otra marca (una Huawei colgada de esta OLT, por
            // ejemplo) no le entregan el WiFi por OAM: la OLT responde "ERROR".
            'wifi_soportado' => !$this->fallo($wifi) && stripos($wifi, 'ERROR') === false,
            'wifi' => $w || str_contains($ssids, 'Ssid') ? [
                'activo'   => isset($w['wifi state']) ? strtolower($w['wifi state']) === 'enable' : null,
                'estandar' => ($w['wlan standard'] ?? '') ?: null,
                'canal'    => isset($w['channel id']) ? ((int) $w['channel id'] ?: 'auto') : null,
                'ancho'    => ($w['channel bandwidth'] ?? '') ?: null,
                'ssids'    => self::ssids($ssids),
            ] : null,
        ];
    }

    /**
     * La tabla de "show ont wifi … ssid all":
     *
     *   Ssid Name          Admin   BcastAdmin  EncryptMode  EncryptKey   MaxUsers
     *   1    HGW-BDC32A    enable    enable     wpa_wpa2    12345678       0
     *   2    HGW-BDC32A-1  disable   enable     wpa_wpa2                   0
     *
     * El nombre puede llevar espacios y la clave puede venir vacía, así que se
     * ancla en las columnas fijas (enable/disable) y en el número del final.
     *
     * @return list<array<string,mixed>>
     */
    private static function ssids(string $salida): array
    {
        $ssids = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('/^\s*(\d+)\s+(.+?)\s+(enable|disable)\s+(enable|disable)\s+(\S+)\s+(?:(.*?)\s+)?(\d+)\s*$/i', $linea, $m)) {
                continue;
            }

            $ssids[] = [
                'id'       => (int) $m[1],
                'nombre'   => trim($m[2]),
                'activo'   => strtolower($m[3]) === 'enable',
                'visible'  => strtolower($m[4]) === 'enable',
                'cifrado'  => $m[5],
                'clave'    => ($m[6] ?? '') !== '' ? $m[6] : null,
                'max_usuarios' => (int) $m[7],
            ];
        }

        return $ssids;
    }


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
        $this->cmd('config', 8);
        $intento = $this->primeraQueSirva($this->variantes('autofind'), 40);
        $this->volverAlPrompt();

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
                unset($actual['fs']);
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

            // Firmware GPON V3.x: "Frame/Slot : 0/0" y el puerto en otra línea.
            if (preg_match('#^\s*Frame/Slot\s*:\s*(\d+/\d+)#i', $linea, $m)) {
                $cerrar();
                $actual['fs'] = $m[1];
                continue;
            }

            if (isset($actual['fs']) && preg_match('/^\s*Port\s*:\s*(\d+)/i', $linea, $m)) {
                $actual['fsp'] = $actual['fs'] . '/' . $m[1];
                continue;
            }

            // "SN : 5A544547C4DABD0D (ZTEG-C4DABD0D)": el legible es el de paréntesis.
            if (preg_match('/^\s*(?:Ont\s*)?SN\s*:\s*([0-9A-Fa-f]{8,20})(?:\s*\(([A-Za-z0-9]{4})-?([0-9A-Fa-f]{8})\))?/i', $linea, $m)) {
                $actual['serial'] = isset($m[3]) ? strtoupper($m[2] . $m[3]) : strtoupper($m[1]);
                $actual['vendor'] = isset($m[2]) ? strtoupper($m[2]) : substr(strtoupper($m[1]), 0, 4);
                continue;
            }

            if (preg_match('/^\s*Equipment\s*ID\s*:\s*(\S+)/i', $linea, $m)) {
                $actual['modelo'] = $m[1];
                continue;
            }

            // Anclado: "Loid Password" es otra cosa.
            if (preg_match('/^\s*(?:Ont\s*)?Password\s*:\s*(\S+)/i', $linea, $m)) {
                $actual['password'] = $m[1];
            }
        }

        $cerrar();

        // Algunos firmware devuelven una tabla en una sola línea por ONT.
        if (!$onts) {
            foreach (preg_split('/\r?\n/', $salida) as $linea) {
                if (preg_match('#^\s*(\d+/\d+/\d+)\s+([0-9A-Za-z]{8,20})\b#', $linea, $m)
                    || (preg_match('#^\s*(\d+/\d+)\s+(\d+)\s+(?:\d+\s+)?([0-9A-Za-z]{8,20})\b#', $linea, $x)
                        && ($m = [0, $x[1] . '/' . $x[2], $x[3]]))) {
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
        $this->cmd('config', 8);
        $salida = $this->cmd($this->comando('listar_todas'), 120);
        $this->volverAlPrompt();

        $onts = array_map(fn ($f) => [
            'fsp'         => '0/0/' . $f['puerto'],
            'ont_id'      => $f['ont_id'],
            'serial'      => $f['mac'],
            'status'      => $f['estado'] === 'online' ? 'online' : 'offline',
            'admin'       => $f['control'],
            'description' => $f['descripcion'],
        ], self::tablaDeOnts($salida));

        // Firmware con la tabla vieja ("0/0/1  1  SN  online  active  desc").
        return $onts ?: $this->leerOntInfo($salida);
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
                'description' => $this->descripcionLegible(trim($m[6] ?? '')) ?: null,
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
        $detalle = $this->cmd($this->comando('info', ['p' => $puerto, 'id' => $ontId]), 30);
        $optico  = $this->cmd($this->comando('optico', ['p' => $puerto, 'id' => $ontId]), 30);
        $this->volverAlPrompt();

        // "SN : 4D5354438CCE4565 (MSTC-8CCE4565)": el legible es el de paréntesis.
        $serial = $this->dato($detalle, '/^\s*(?:Ont\s*)?SN\s*:\s*\S+\s*\(([^)]+)\)/mi')
            ?? $this->dato($detalle, '/(?:Ont\s*)?SN\s*:\s*(\S+)/i');
        $estado = $this->dato($detalle, '/^\s*Run\s*state\s*:\s*(\S+)/mi');

        return [
            'fsp'         => $fsp,
            'ont_id'      => $ontId,
            'serial'      => $serial !== null ? strtoupper(str_replace('-', '', $serial)) : null,
            'status'      => $estado !== null
                ? (strtolower($estado) === 'online' ? 'online' : 'offline')
                : (str_contains(strtolower($detalle), 'online') ? 'online' : 'offline'),
            'description' => $this->descripcionLegible($this->dato($detalle, '/Descri\w*\s*:\s*(.+)/i')),
            'distancia_m' => $this->numero($detalle, '/Distance\s*\(?m?\)?\s*:\s*(-?[\d.]+)/i'),
            'ont_rx'      => $this->numero($optico, '/^\s*Rx\s*optical\s*power(?:\(dBm\))?\s*:\s*(-?[\d.]+)/mi')
                             ?? $this->numero($optico, '/(?:ONT|Rx)\s*[Oo]ptical\s*[Pp]ower\s*:\s*(-?[\d.]+)/i')
                             ?? $this->numero($optico, '/Rx\s*power\s*:\s*(-?[\d.]+)/i'),
            'ont_tx'      => $this->numero($optico, '/^\s*Tx\s*optical\s*power(?:\(dBm\))?\s*:\s*(-?[\d.]+)/mi')
                             ?? $this->numero($optico, '/Tx\s*power\s*:\s*(-?[\d.]+)/i'),
            'olt_rx'      => $this->numero($optico, '/OLT\s*Rx\s*(?:ONT\s*optical\s*)?(?:power)?(?:\(dBm\))?\s*:\s*(-?[\d.]+)/i'),
            'temperatura' => $this->numero($optico, '/Temperature(?:\(C\))?\s*:\s*(-?[\d.]+)/i'),
            'raw'         => $detalle . "\n" . $optico,
        ];
    }

    public function getServicePorts(?string $fsp = null, ?int $ontId = null): array
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd(self::GRILLA['gpon']['service_ports'], 60);
        $this->volverAlPrompt();

        $puertos = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            //  INDEX VLAN PORT   ONT GEM ...   (VLAN "-" = transparente)
            if (preg_match('#^\s*(\d+)\s+(\d+|-)\s+(\d+/\d+/\d+)\s+(\d+)\s+(\d+)\b#', $linea, $x)) {
                $m = [0, $x[1], $x[2] === '-' ? '0' : $x[2], null, null, $x[4]];
                $linFsp = $x[3];
            } elseif (preg_match('#^\s*(\d+)\s+(\d+)\s+gpon\s+(\d+/\d+)\s*/?\s*(\d+)?\s+(\d+)#i', $linea, $m)) {
                $linFsp = $m[3] . '/' . ($m[4] ?? '0');
            } else {
                continue;
            }

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
            'description'   => ($b['description'] ?? '') !== '' ? $this->descripcionLegible($b['description']) : null,
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
     *   ont add <puerto> <id> mac-auth <MAC> [ont-lineprofile-id <n> ont-srvprofile-id <m>]
     *   ont description <puerto> <id> <texto>
     *
     * El perfil de línea sólo se manda si el alta lo pide explícitamente: sin
     * él la OLT usa el suyo por defecto, que es el que tienen las ONU que ya
     * están andando. Mandar el valor por defecto de la plataforma (10) fallaba
     * en un equipo que no tiene ese perfil.
     */
    private function altaEpon(string $fsp, string $serial, string $description, ?int $lineProfileId, ?int $vlan = null, ?int $srvProfileId = null): array
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

        // Los perfiles van de a dos: con el de línea la OLT exige también el de
        // servicio ("Command incomplete" si falta). Sin ninguno, asigna los
        // suyos. Si se pide sólo uno, el otro sale de la configuración de la OLT.
        $perfiles = '';

        if ($lineProfileId !== null || $srvProfileId !== null) {
            $perfiles = ' ont-lineprofile-id ' . ($lineProfileId ?? $this->lineProfileId)
                . ' ont-srvprofile-id ' . ($srvProfileId ?? $this->srvProfileId);
        }

        $comando = "ont add {$puerto} {$ontId} mac-auth {$mac}{$perfiles}";

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

        $pasoVlan = $vlan ? $this->pasoVlan($fsp, $vlan) : null;

        return [
            'success'              => true,
            'ont_id'               => $ontId,
            'port_id'              => $puerto,
            'message'              => "ONU {$mac} autorizada en {$fsp} con ID {$ontId}",
            // En EPON no hay service-port: lo que cuenta es que el puerto PON
            // lleve la VLAN (ver pasoVlan).
            'service_port_created' => $pasoVlan['ok'] ?? false,
            'vlan_paso'            => $pasoVlan,
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

    // ── Puertos de subida ─────────────────────────────────────────────────

    /**
     * Los puertos de subida (ge/xge) con las VLAN que llevan, leídos de
     * "show vlan all" (verificado en GPON FD1608S-B1 V3.2.14):
     *
     *   VLAN ID: 10
     *   Tagged Ports:
     *     gpon 0/0/1   ...   ge 0/0/1   ge 0/0/2   xge 0/0/1
     *   Untagged Ports:  none
     *
     * Con esto la pantalla de gestión TR-069 puede proponer el puerto que va
     * al router, igual que con Huawei.
     *
     * @return list<array{puerto:string, vlans:list<int>, nativa:?int}>
     */
    public function puertosDeSubida(): array
    {
        return array_values(array_filter($this->tablaDeVlans(), fn ($p) => !str_starts_with($p['puerto'], 'pon')));
    }

    /**
     * "show vlan all" de las dos familias, puerto por puerto:
     *
     *   GPON  VLAN ID: 10 · Tagged Ports: gpon 0/0/1 … ge 0/0/1 xge 0/0/1
     *   EPON  Vlan-ID: 100 · Tagged-Ports: ge0/0/1 pon0/0/1 …
     *
     * @return array<string, array{puerto:string, vlans:list<int>, nativa:?int}>
     */
    private function tablaDeVlans(): array
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd('show vlan all', 60);
        $this->volverAlPrompt();

        if ($this->fallo($salida)) {
            return [];
        }

        $puertos = [];
        $vlan    = null;
        $seccion = null;

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('/^\s*VLAN[\s-]*ID\s*:\s*(\d+)/i', $linea, $m)) {
                $vlan    = (int) $m[1];
                $seccion = null;
                continue;
            }

            if (preg_match('/^\s*(Tagged|Untagged)[\s-]*Ports\s*:/i', $linea, $m)) {
                $seccion = strtolower($m[1]);
            }

            if ($vlan === null || $seccion === null) {
                continue;
            }

            preg_match_all('#\b(x?ge|pon)\s*(\d+/\d+/\d+)#i', $linea, $x, PREG_SET_ORDER);

            foreach ($x as $p) {
                $nombre = strtolower($p[1]) . ' ' . $p[2];
                $puertos[$nombre] ??= ['puerto' => $nombre, 'vlans' => [], 'nativa' => null];

                if ($seccion === 'tagged') {
                    $puertos[$nombre]['vlans'][] = $vlan;
                } else {
                    $puertos[$nombre]['nativa'] = $vlan;
                }
            }
        }

        foreach ($puertos as &$p) {
            $p['vlans'] = array_values(array_unique($p['vlans']));
            sort($p['vlans']);
        }

        return $puertos;
    }

    // ── Gestión remota (TR-069) en GPON ───────────────────────────────────
    //
    // Sintaxis verificada con la ayuda de la consola en una FD1608S-B1 V3.2.14.
    // Todo es aditivo y se lee de vuelta: la OLT no avisa cuando algo quedó a
    // medias, así que lo que cuenta es lo que muestra después.

    private function soloGpon(): ?array
    {
        return $this->esEpon()
            ? ['ok' => false, 'estado' => 'no_soportado', 'detalle' => 'En C-Data EPON la gestión se configura en cada equipo: la OLT no puede crearla.']
            : null;
    }

    /**
     * Deja pasar la VLAN de gestión por el puerto de subida ("xge 0/0/1").
     *
     * Antes de tocar se anotan las VLAN del puerto; si después falta alguna
     * —porque el firmware reemplazara la lista en vez de sumar— se reponen en
     * el acto. Quitar una VLAN de un puerto de subida deja sin internet a todos
     * los clientes que salen por él.
     */
    public function prepararVlanDeGestion(int $vlan, string $puertoDeSubida): array
    {
        if ($this->esEpon()) {
            return $this->vlanDeGestionEpon($vlan, $puertoDeSubida);
        }

        if (!preg_match('#^\s*(x?ge)\s*(\d+/\d+)/(\d+)\s*$#i', $puertoDeSubida, $m)) {
            return ['ok' => false, 'detalle' => "«{$puertoDeSubida}» no es un puerto de subida (ej. xge 0/0/1)."];
        }

        [$tipo, $fs, $puerto] = [strtolower($m[1]), $m[2], (int) $m[3]];
        $nombre = "{$tipo} {$fs}/{$puerto}";

        $vlansDe = function () use ($nombre): ?array {
            foreach ($this->puertosDeSubida() as $p) {
                if ($p['puerto'] === $nombre) {
                    return $p['vlans'];
                }
            }

            return null;
        };

        $antes = $vlansDe() ?? [];

        if (in_array($vlan, $antes, true)) {
            return ['ok' => true, 'detalle' => "La VLAN {$vlan} ya pasaba por {$nombre}."];
        }

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $creada = $this->cmd("vlan {$vlan}", 15);
        $this->cmd("interface {$tipo} {$fs}", 10);
        $trunk = $this->cmd("vlan trunk {$puerto} {$vlan}", 15);
        $this->volverAlPrompt();

        $despues = $vlansDe() ?? [];
        $faltan  = array_values(array_diff($antes, $despues));
        $repuso  = '';

        if ($faltan) {
            Log::error('[OLT C-Data] vlan trunk quitó VLAN del puerto de subida: se reponen', [
                'puerto' => $nombre, 'antes' => $antes, 'despues' => $despues,
            ]);

            $this->cmd('config', 8);
            $this->cmd("interface {$tipo} {$fs}", 10);
            $this->cmd("vlan trunk {$puerto} " . implode(',', array_merge($faltan, [$vlan])), 15);
            $this->volverAlPrompt();

            $despues = $vlansDe() ?? [];
            $repuso  = ' Se repusieron ' . implode(', ', $faltan) . '.';
        }

        $ok = in_array($vlan, $despues, true) && !array_diff($antes, $despues);

        return [
            'ok'      => $ok,
            'detalle' => $ok
                ? "VLAN {$vlan} creada y pasando por {$nombre} (lleva " . implode(', ', $despues) . ").{$repuso}"
                : "La OLT no dejó la VLAN {$vlan} en {$nombre}: " . ($this->mensaje($creada . "\n" . $trunk) ?: 'sin detalle') . $repuso,
        ];
    }

    /** @return array{id:int, nombre:string}|null */
    public function perfilDeOnt(string $fsp, int $ontId): ?array
    {
        if ($this->esEpon()) {
            return null;
        }

        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd($this->comando('info', ['p' => $puerto, 'id' => $ontId]), 30);
        $this->volverAlPrompt();

        if (!preg_match('/Line profile ID\s*:\s*(\d+)/i', $salida, $id)) {
            return null;
        }

        return [
            'id'     => (int) $id[1],
            'nombre' => preg_match('/Line profile name\s*:\s*(\S+)/i', $salida, $n) ? $n[1] : "perfil {$id[1]}",
        ];
    }

    /** @return list<array{id:int, nombre:string, equipos:int}> */
    public function perfilesDeLinea(): array
    {
        return array_map(
            fn ($p) => ['id' => $p['id'], 'nombre' => $p['name'], 'equipos' => (int) ($p['uso'] ?? 0)],
            $this->getLineProfiles()
        );
    }

    /**
     *   Mapping mode        : VLAN
     *   <Gem ID  1>
     *     Mapping-ID  VLAN      Priority      Port-ID
     *       1         10           -           -
     *
     * "tr069" va en true: en C-Data la gestión TR-069 se enciende en cada
     * equipo, no en el perfil.
     *
     * @return array{id:int, modo:string, tr069:bool, gems:array<int,list<array{indice:int, vlan:?int}>>}|null
     */
    public function perfilDeLinea(int $id): ?array
    {
        if ($this->esEpon()) {
            return null;
        }

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd("show ont-line-profile gpon profile-id {$id}", 30);
        $this->volverAlPrompt();

        if (!preg_match('/Line profile ID\s*:\s*' . $id . '\b/i', $salida)) {
            return null;
        }

        // La tabla de service-port del perfil viene después y no son mapeos.
        $salida = preg_split('/^\s*Gem-id\s+Server-Vlan/mi', $salida)[0];

        $gems   = [];
        $trozos = preg_split('/<Gem ID\s+(\d+)>/i', $salida, -1, PREG_SPLIT_DELIM_CAPTURE);

        for ($i = 1; $i + 1 < count($trozos); $i += 2) {
            $gem = (int) $trozos[$i];
            $gems[$gem] = [];

            preg_match_all('/^\s+(\d+)\s+(\d+|-)\s+\S+\s+\S+\s*$/m', $trozos[$i + 1], $filas, PREG_SET_ORDER);

            foreach ($filas as $f) {
                $gems[$gem][] = ['indice' => (int) $f[1], 'vlan' => $f[2] === '-' ? null : (int) $f[2]];
            }
        }

        return [
            'id'    => $id,
            'modo'  => preg_match('/Mapping mode\s*:\s*(\S+)/i', $salida, $m) ? strtoupper($m[1]) : '',
            'tr069' => true,
            'gems'  => $gems,
        ];
    }

    /**
     * Suma la VLAN de gestión al canal (GEM) del perfil de línea. Sólo agrega:
     * los mapeos que ya tiene no se tocan.
     *
     * @return array{ok:bool, estado:string, detalle:string}
     */
    public function prepararPerfilDeLinea(int $id, int $vlan): array
    {
        if ($no = $this->soloGpon()) {
            return $no;
        }

        $antes = $this->perfilDeLinea($id);

        if (!$antes) {
            return ['ok' => false, 'estado' => 'error', 'detalle' => "No se pudo leer el perfil {$id}."];
        }

        if ($antes['modo'] !== 'VLAN') {
            return ['ok' => false, 'estado' => 'no_soportado', 'detalle' => "El perfil reparte el tráfico por «{$antes['modo']}», no por VLAN: hay que revisarlo a mano."];
        }

        if (!$antes['gems']) {
            return ['ok' => false, 'estado' => 'no_soportado', 'detalle' => 'El perfil no tiene canales (GEM) configurados.'];
        }

        $gem    = array_key_first(array_filter($antes['gems'])) ?? array_key_first($antes['gems']);
        $mapeos = $antes['gems'][$gem];

        if (in_array($vlan, array_column($mapeos, 'vlan'), true)) {
            return ['ok' => true, 'estado' => 'ya_estaba', 'detalle' => 'Ya estaba listo.'];
        }

        $indice = $mapeos ? max(array_column($mapeos, 'indice')) + 1 : 1;

        if ($indice > 8) {
            return ['ok' => false, 'estado' => 'no_soportado', 'detalle' => 'El canal del perfil ya tiene los 8 mapeos que admite la OLT.'];
        }

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $this->cmd("ont-line-profile gpon profile-id {$id}", 10);
        $respuesta = $this->cmd("gem mapping {$gem} {$indice} vlan {$vlan}", 15);
        $commit    = $this->cmd('commit', 30);

        if (preg_match('/\(y\/n\)/i', $commit)) {
            $commit .= $this->cmd('y', 30);
        }

        $this->volverAlPrompt();

        $despues = $this->perfilDeLinea($id);
        $listo   = $despues && in_array($vlan, array_column($despues['gems'][$gem] ?? [], 'vlan'), true);

        return [
            'ok'      => $listo,
            'estado'  => $listo ? 'listo' : 'error',
            'detalle' => $listo
                ? "VLAN {$vlan} en el canal {$gem} del perfil {$id}."
                : 'La OLT no dejó el perfil como se pidió: ' . ($this->mensaje($respuesta . "\n" . $commit) ?: 'sin detalle'),
        ];
    }

    /** En C-Data el servidor TR-069 va en el perfil (prepararGestionPorPerfil). */
    public function crearServidorTr069(int $perfil, string $nombre, string $url, string $usuario, string $clave): array
    {
        return ($no = $this->soloGpon())
            ? $no
            : ['ok' => true, 'detalle' => 'En C-Data el servidor TR-069 va en el perfil de gestión.'];
    }

    /**
     * No se manda nada al equipo: "ont tr069" sobre una ONT con mult-srv-profile
     * la desvincula del perfil. El servidor le llega con el perfil de gestión.
     */
    public function asignarServidorTr069(string $fsp, int $ontId, int $perfil, ?string $url = null, ?string $usuario = null, ?string $clave = null): array
    {
        return ($no = $this->soloGpon())
            ? $no
            : ['ok' => true, 'detalle' => 'El servidor TR-069 le llega con el perfil de gestión.'];
    }

    public function reiniciarOnt(string $fsp, int $ontId): array
    {
        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd("ont reboot {$puerto} {$ontId}", 20);

        if (preg_match('/\(y\/n\)/i', $salida)) {
            $salida .= $this->cmd('y', 20);
        }

        $this->volverAlPrompt();

        return $this->fallo($salida)
            ? ['ok' => false, 'detalle' => 'La OLT no reinició el equipo: ' . $this->mensaje($salida)]
            : ['ok' => true, 'detalle' => 'El equipo se está reiniciando.'];
    }

    // ── Gestión TR-069 por perfil (verificado en FD1608S-B1 V3.2.14) ─────
    //
    // En C-Data GPON las ONT van atadas a un mult-srv-profile. Cualquier
    // comando individual (ont tr069, ont ipconfig, ont port native-vlan) las
    // desvincula del perfil. La gestión se da con perfiles: uno TR-069, uno WAN
    // en la VLAN de gestión y, por cada mult-srv-profile de clientes, una copia
    // con esos dos. Pasar una ONT a su copia es quitarla y volverla a agregar.
    //
    // No todos los equipos aceptan que la OLT les cree la WAN: las Mitrastar
    // GPT-2742GX4X5V (HGU) quedan en "Config state: failed". Por eso cada paso
    // se comprueba, se revierte si falla y el modelo queda anotado para no
    // volver a intentarlo con los demás equipos iguales.

    private const PERFIL_TR069 = 'netvula-acs';

    /** @return list<array{id:int, nombre:string, lp:int, sp:int, tr069:?int, wan:?int, equipos:int}> */
    private function multiSrvProfiles(): array
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd($this->comando('mult_srv'), 30);
        $this->volverAlPrompt();

        $lista = [];
        $num = fn (string $campo, string $bloque) => preg_match('/' . $campo . '\s*:\s*(\d+)/i', $bloque, $m) ? (int) $m[1] : null;

        foreach (preg_split('/^\s*-{5,}\s*$/m', $salida) as $bloque) {
            $id = $num('Profile-ID', $bloque);
            $lp = $num('Ont-line-profile', $bloque);
            $sp = $num('Ont-srv-profile', $bloque);

            if ($id === null || $lp === null || $sp === null) {
                continue;
            }

            $lista[] = [
                'id'      => $id,
                'nombre'  => preg_match('/Profile-name\s*:\s*(\S+)/i', $bloque, $n) ? $n[1] : "perfil-{$id}",
                'lp'      => $lp,
                'sp'      => $sp,
                'tr069'   => $num('Ont-tr069-profile', $bloque),
                'wan'     => $num('Ont-wan-profile', $bloque),
                'equipos' => (int) ($num('Binding-times', $bloque) ?? 0),
            ];
        }

        return $lista;
    }

    /** Filas "ID  nombre  vínculos" de show ont-tr069-profile / ont-wan-profile. @return array<int,string> */
    private function tablaDePerfiles(string $comando): array
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd($comando, 20);
        $this->volverAlPrompt();

        $perfiles = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('/^\s*(\d+)\s+(\S+)\s+\d+\s*$/', $linea, $m)) {
                $perfiles[(int) $m[1]] = $m[2];
            }
        }

        return $perfiles;
    }

    private function mostrarEnConfig(string $comando): string
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd($comando, 20);
        $this->volverAlPrompt();

        return $salida;
    }

    /** Entra a un perfil, manda sus líneas y hace commit. Devuelve lo que contestó la OLT. */
    private function editarPerfil(string $entrar, array $lineas): string
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd($entrar, 10);

        foreach ($lineas as $linea) {
            $salida .= $this->cmd($linea, 15);
        }

        $commit  = $this->cmd('commit', 30);
        $salida .= preg_match('/\(y\/n\)/i', $commit) ? $commit . $this->cmd('y', 30) : $commit;
        $this->volverAlPrompt();

        return $salida;
    }

    /**
     * Deja listos los perfiles de gestión: TR-069, WAN en la VLAN y una copia
     * con gestión de cada mult-srv-profile de clientes. No asigna nada a
     * ningún equipo: no afecta a nadie.
     *
     * @return array{ok:bool, detalle:string, tr069:?int, wan:?int, copias:array<int,int>}
     */
    public function prepararGestionPorPerfil(int $vlan, string $url, ?string $usuario = null, ?string $clave = null): array
    {
        if ($this->esEpon()) {
            return [
                'ok' => true, 'tr069' => null, 'wan' => null, 'copias' => [],
                'detalle' => 'En EPON no hay perfiles: la ONT recibe la dirección del TR-069 por el DHCP de la red de gestión (opción 43).',
            ];
        }

        $fallo = fn (string $detalle) => ['ok' => false, 'detalle' => $detalle, 'tr069' => null, 'wan' => null, 'copias' => []];

        // ── Perfil TR-069 ────────────────────────────────────────────────────
        $tr069s = $this->tablaDePerfiles('show ont-tr069-profile all');
        $tr069  = array_search(self::PERFIL_TR069, $tr069s, true);
        $tr069  = $tr069 === false ? null : (int) $tr069;

        $leido = $tr069 !== null ? $this->mostrarEnConfig("show ont-tr069-profile profile-id {$tr069}") : '';
        $listo = $tr069 !== null && str_contains($leido, $url) && preg_match('/TR069 management\s*:\s*Enable/i', $leido);

        if (!$listo) {
            if ($tr069 === null) {
                $tr069 = collect(range(1, 32))->first(fn ($id) => !isset($tr069s[$id]));
            }

            if ($tr069 === null) {
                return $fallo('La OLT ya tiene los 32 perfiles TR-069 ocupados.');
            }

            $acs = "acs-url {$url}" . ($usuario && $clave ? " acs-username {$usuario} acs-password " . substr($clave, 0, 25) : '');
            $resp = $this->editarPerfil(
                "ont-tr069-profile gpon profile-id {$tr069} profile-name " . self::PERFIL_TR069,
                [$acs, 'tr069-management enable', 'inform enable']
            );

            $leido = $this->mostrarEnConfig("show ont-tr069-profile profile-id {$tr069}");

            if (!str_contains($leido, $url)) {
                return $fallo('La OLT no creó el perfil TR-069: ' . ($this->mensaje(str_replace((string) $clave, '***', $resp)) ?: 'sin detalle'));
            }
        }

        // ── Perfil WAN en la VLAN de gestión ─────────────────────────────────
        $wans = $this->tablaDePerfiles('show ont-wan-profile gpon all');
        $wan  = null;

        foreach (array_keys($wans) as $id) {
            $w = $this->mostrarEnConfig("show ont-wan-profile gpon profile-id {$id}");

            if (preg_match('/Connect type\s*:\s*TR069/i', $w) && preg_match('/VLAN id\s*:\s*' . $vlan . '\b/i', $w)) {
                $wan = $id;
                break;
            }
        }

        if ($wan === null) {
            $wan = collect(range(1, 256))->first(fn ($id) => !isset($wans[$id]));
            $resp = $this->editarPerfil(
                "ont-wan-profile gpon profile-id {$wan} profile-name netvula-v{$vlan}",
                ["wan 1 vlan {$vlan} priority 0 ipv4 dhcp", 'wan 1 option service-type tr069']
            );

            $w = $this->mostrarEnConfig("show ont-wan-profile gpon profile-id {$wan}");

            if (!preg_match('/VLAN id\s*:\s*' . $vlan . '\b/i', $w)) {
                return $fallo('La OLT no creó el perfil WAN de gestión: ' . ($this->mensaje($resp) ?: 'sin detalle'));
            }
        }

        // ── Copia con gestión de cada mult-srv-profile de clientes ──────────
        $multis = $this->multiSrvProfiles();
        $copias = [];
        $nuevas = [];

        foreach ($multis as $m) {
            if ($m['tr069'] !== null || $m['wan'] !== null) {
                continue; // ya es un perfil con gestión
            }

            $copia = collect($multis)->first(fn ($x) => $x['lp'] === $m['lp'] && $x['sp'] === $m['sp'] && $x['tr069'] === $tr069 && $x['wan'] === $wan);

            if (!$copia) {
                $usados = array_merge(array_column($multis, 'id'), $nuevas);
                $id = collect(range(0, 127))->first(fn ($x) => !in_array($x, $usados, true));

                if ($id === null) {
                    return $fallo('La OLT no tiene lugar para más mult-srv-profile.');
                }

                $this->editarPerfil(
                    "ont mult-srv-profile gpon profile-id {$id} profile-name " . substr($m['nombre'], 0, 18) . '-tr069',
                    ["ont-line-profile profile-id {$m['lp']}", "ont-srv-profile profile-id {$m['sp']}", "ont-tr069-profile profile-id {$tr069}", "ont-wan-profile profile-id {$wan}"]
                );

                $nuevas[] = $id;
                $copia = ['id' => $id];
            }

            $copias[$m['id']] = (int) $copia['id'];
        }

        // Se relee: sólo cuenta lo que la OLT muestra.
        $despues = collect($this->multiSrvProfiles());
        $faltan  = collect($copias)->filter(fn ($c) => !$despues->first(fn ($x) => $x['id'] === $c && $x['tr069'] === $tr069 && $x['wan'] === $wan))->keys();

        if ($faltan->isNotEmpty()) {
            return ['ok' => false, 'detalle' => 'No se pudo crear la copia con gestión de los perfiles ' . $faltan->implode(', ') . '.', 'tr069' => $tr069, 'wan' => $wan, 'copias' => $copias];
        }

        return [
            'ok'      => true,
            'detalle' => "Perfil TR-069 {$tr069}, WAN de gestión {$wan} (VLAN {$vlan}) y copias con gestión: "
                . collect($copias)->map(fn ($c, $o) => "{$o}→{$c}")->implode(', ') . '. Ningún equipo cambió todavía.',
            'tr069'   => $tr069,
            'wan'     => $wan,
            'copias'  => $copias,
        ];
    }

    /** Estado, perfil, serial, descripción y modelo de una ONT. */
    private function fichaDeOnt(int $puerto, int $ontId, string $fs = '0/0'): array
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $this->cmd($this->comando('entrar_puerto', ['fs' => $fs]), 10);
        $info    = $this->cmd("show ont info {$puerto} {$ontId}", 30);
        $version = $this->cmd("show ont version {$puerto} {$ontId}", 20);
        $this->volverAlPrompt();

        $dato = fn (string $patron, string $texto) => preg_match($patron, $texto, $m) ? trim($m[1]) : null;

        return [
            'existe'      => (bool) preg_match('/ONT-ID\s*:\s*' . $ontId . '\b/', $info),
            'online'      => strtolower((string) $dato('/Run state\s*:\s*(\S+)/i', $info)) === 'online',
            'config'      => strtolower((string) $dato('/Config state\s*:\s*(\S+)/i', $info)),
            'msp'         => ($v = $dato('/Mult-srv-profile ID\s*:\s*(\d+)/i', $info)) !== null ? (int) $v : null,
            'serial'      => ($sn = $dato('/^\s*SN\s*:\s*\S+\s*\(([^)]+)\)/mi', $info)) ? strtoupper(str_replace('-', '', $sn)) : null,
            'descripcion' => ($d = $dato('/Description\s*:\s*(.*?)\s*$/mi', $info)) !== '' ? $d : null,
            'equipo'      => $dato('/Equipment-ID\s*:\s*(\S+)/i', $version),
        ];
    }

    /** Registro de la ONT que se está moviendo de perfil, para poder reponerla si algo se corta. */
    private function claveTransito(int $puerto, int $ontId, string $fs): string
    {
        return 'cdata:transito:' . ($this->config['id'] ?? 'x') . ":{$fs}/{$puerto}:{$ontId}";
    }

    /**
     * Quita la ONT y la vuelve a agregar con otro mult-srv-profile.
     *
     * Antes del "ont delete" se anota a dónde tiene que volver. Si algo falla
     * entre el delete y el add (el túnel se corta, la OLT no contesta), se la
     * repone ahí mismo con su perfil original; si ni eso se puede, el registro
     * queda y la próxima vez que se toque esa ONT se repone (reponerSiQuedoEnTransito).
     */
    private function volverAAgregar(int $puerto, int $ontId, string $serial, int $msp, ?string $descripcion, string $fs = '0/0', ?int $mspOriginal = null): string
    {
        $clave = $this->claveTransito($puerto, $ontId, $fs);

        if ($mspOriginal !== null) {
            \Illuminate\Support\Facades\Cache::put($clave, ['serial' => $serial, 'msp' => $mspOriginal, 'descripcion' => $descripcion], now()->addDays(7));
        }

        $salida = '';

        try {
            $this->volverAlPrompt();
            $this->cmd('config', 8);
            $this->cmd($this->comando('entrar_puerto', ['fs' => $fs]), 10);
            $salida  = $this->cmd("ont delete {$puerto} {$ontId}", 20);
            $salida .= $this->cmd("ont add {$puerto} {$ontId} sn-auth {$serial} mult-srv-profile profile-id {$msp}", 20);

            if ($descripcion) {
                $this->cmd("ont description {$puerto} {$ontId} " . substr(str_replace('"', '', $descripcion), 0, 64), 10);
            }

            $this->volverAlPrompt();
        } catch (\Throwable $e) {
            if ($mspOriginal !== null) {
                try {
                    $this->volverAlPrompt();
                    $this->cmd('config', 8);
                    $this->cmd($this->comando('entrar_puerto', ['fs' => $fs]), 10);
                    $this->cmd("ont add {$puerto} {$ontId} sn-auth {$serial} mult-srv-profile profile-id {$mspOriginal}", 20);
                    $this->volverAlPrompt();
                } catch (\Throwable) {
                    // Queda el registro: se repone la próxima vez.
                }
            }

            Log::error('[OLT C-Data] Se cortó el cambio de perfil de una ONT', ['fs' => $fs, 'puerto' => $puerto, 'ont_id' => $ontId, 'error' => $e->getMessage()]);

            throw $e;
        }

        return $salida;
    }

    /** Si una ONT quedó borrada a mitad de un cambio de perfil, se la repone con su perfil original. */
    private function reponerSiQuedoEnTransito(int $puerto, int $ontId, string $fs, array $ficha): ?string
    {
        $clave = $this->claveTransito($puerto, $ontId, $fs);
        $pendiente = \Illuminate\Support\Facades\Cache::get($clave);

        if (!$pendiente) {
            return null;
        }

        if ($ficha['existe']) {
            return null; // la ONT está: el registro se limpia cuando termine el cambio en curso
        }

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $this->cmd($this->comando('entrar_puerto', ['fs' => $fs]), 10);
        $this->cmd("ont add {$puerto} {$ontId} sn-auth {$pendiente['serial']} mult-srv-profile profile-id {$pendiente['msp']}", 20);

        if (!empty($pendiente['descripcion'])) {
            $this->cmd("ont description {$puerto} {$ontId} " . substr(str_replace('"', '', (string) $pendiente['descripcion']), 0, 64), 10);
        }

        $this->volverAlPrompt();
        \Illuminate\Support\Facades\Cache::forget($clave);

        return "La ONT había quedado sin agregar tras un cambio de perfil cortado: se repuso con su perfil original ({$pendiente['msp']}).";
    }

    /** Espera a que la ONT vuelva y termine de configurarse: "success", "failed" o null si no volvió. */
    private function esperarConfig(int $puerto, int $ontId, int $segundos = 75, ?int $mspEsperado = null, string $fs = '0/0'): ?string
    {
        $limite = microtime(true) + $segundos;

        while (microtime(true) < $limite) {
            sleep(6);
            $f = $this->fichaDeOnt($puerto, $ontId, $fs);

            // Sólo vale si quedó en el perfil pedido: si el delete o el add no
            // entraron, la ONT sigue en el suyo y daba "success" enseguida.
            if ($mspEsperado !== null && $f['existe'] && $f['msp'] !== $mspEsperado) {
                continue;
            }

            if ($f['online'] && in_array($f['config'], ['success', 'failed'], true)) {
                return $f['config'];
            }
        }

        return null;
    }

    /** Anota un modelo que no acepta la WAN por la OLT (y lo suma a la lista que muestra la guía). */
    public static function marcarModeloSinWan(?string $equipo): void
    {
        if (!$equipo) {
            return;
        }

        // 30 días y no para siempre: un fallo puntual no puede bloquear el
        // modelo indefinidamente; si de verdad no lo soporta, se vuelve a anotar.
        \Illuminate\Support\Facades\Cache::put(self::claveModeloSinWan($equipo), now()->toDateTimeString(), now()->addDays(30));

        $lista = \Illuminate\Support\Facades\Cache::get('cdata:modelos-sin-wan-por-olt', []);
        $lista[strtoupper($equipo)] = now()->toDateTimeString();
        \Illuminate\Support\Facades\Cache::put('cdata:modelos-sin-wan-por-olt', $lista, now()->addDays(30));
    }

    public static function claveModeloSinWan(?string $equipo): string
    {
        return 'cdata:sin-wan-por-olt:' . strtoupper((string) $equipo);
    }

    /**
     * Pasa la ONT a la copia con gestión de su mult-srv-profile. Se comprueba
     * y, si el equipo no acepta la WAN, vuelve a su perfil original.
     */
    public function darGestionAOnt(string $fsp, int $ontId, int $vlan, int $servicePort, array $vlansCliente = [], bool $pisarAjenas = false): array
    {
        $base = ['sp_ok' => true, 'sp' => null, 'ip' => null, 'por_perfil' => true];

        if ($this->esEpon()) {
            return $this->gestionEpon($fsp, $ontId, $vlan) + $base;
        }

        ['frame' => $fr, 'slot' => $sl, 'port' => $puerto] = $this->partirFsp($fsp);
        $fs = "{$fr}/{$sl}";
        $f = $this->fichaDeOnt($puerto, $ontId, $fs);

        // Un cambio de perfil anterior que se cortó con la ONT borrada: primero
        // se la repone, y después se sigue.
        if ($repuesta = $this->reponerSiQuedoEnTransito($puerto, $ontId, $fs, $f)) {
            return ['ok' => false, 'detalle' => $repuesta . ' Volvé a darle acceso en un minuto.'] + $base;
        }

        if (!$f['existe'] || !$f['serial']) {
            return ['ok' => false, 'detalle' => 'La ONT no está en la OLT.'] + $base;
        }

        if ($f['msp'] === null) {
            return ['ok' => false, 'omitido' => 'sin_perfil', 'detalle' => 'La ONT no usa mult-srv-profile (tiene configuración propia): no se toca. Volvé a autorizarla con su perfil y después dale acceso.'] + $base;
        }

        $multis = $this->multiSrvProfiles();
        $actual = collect($multis)->firstWhere('id', $f['msp']);

        // Los perfiles WAN de gestión de ESTA VLAN: una copia hecha para otra
        // VLAN (antes de cambiarla) no sirve.
        $wansDeLaVlan = $this->wansDeGestionDeVlan($vlan);
        $esCopiaVigente = fn ($x) => $x['tr069'] !== null && $x['wan'] !== null && in_array($x['wan'], $wansDeLaVlan, true);

        if ($actual && $actual['tr069'] !== null && $actual['wan'] !== null) {
            $original = collect($multis)->first(fn ($x) => $x['lp'] === $actual['lp'] && $x['sp'] === $actual['sp'] && $x['tr069'] === null && $x['wan'] === null);

            if ($f['config'] !== 'failed' && $esCopiaVigente($actual)) {
                \Illuminate\Support\Facades\Cache::forget($this->claveTransito($puerto, $ontId, $fs));

                return ['ok' => true, 'detalle' => "Ya tiene el perfil de gestión ({$actual['nombre']})."] + $base;
            }

            if ($f['config'] !== 'failed') {
                // Copia de una VLAN vieja: se pasa a la de la VLAN actual, abajo.
                $actual = $original;
            } else {
                // Quedó en el perfil de gestión pero el equipo no lo aceptó: vuelve
                // al perfil de clientes equivalente (mismo de línea y de servicio).
                if (!$original || !$f['online']) {
                    return ['ok' => false, 'detalle' => 'La ONT no aceptó el perfil de gestión y no se encontró su perfil de clientes para devolverla: revisala en la OLT.'] + $base;
                }

                $this->volverAAgregar($puerto, $ontId, $f['serial'], $original['id'], $f['descripcion'], $fs, $original['id']);
                $vuelta = $this->esperarConfig($puerto, $ontId, 55, $original['id'], $fs);

                if ($vuelta === 'success') {
                    \Illuminate\Support\Facades\Cache::forget($this->claveTransito($puerto, $ontId, $fs));
                }

                if ($f['equipo']) {
                    self::marcarModeloSinWan($f['equipo']);
                }

                return [
                    'ok'      => false,
                    'omitido' => 'modelo_sin_wan',
                    'detalle' => self::instruccionesHgu($f['equipo'])
                        . ($vuelta === 'success'
                            ? " La ONT volvió a su perfil {$original['nombre']} y sigue con su servicio."
                            : ' ⚠️ Revisá la ONT: no se confirmó que volviera a su perfil.'),
                ] + $base;
            }
        }

        if (\Illuminate\Support\Facades\Cache::has(self::claveModeloSinWan($f['equipo']))) {
            return ['ok' => false, 'omitido' => 'modelo_sin_wan', 'detalle' => self::instruccionesHgu($f['equipo'])] + $base;
        }

        $copia = $actual ? collect($multis)->first(fn ($x) => $x['lp'] === $actual['lp'] && $x['sp'] === $actual['sp'] && $esCopiaVigente($x)) : null;

        if (!$copia) {
            return ['ok' => false, 'detalle' => 'Faltan los perfiles de gestión de la VLAN ' . $vlan . ' en la OLT: usá «Volver a aplicar» en la configuración del acceso remoto.'] + $base;
        }

        // Sin la VLAN en el perfil de línea la WAN de gestión no sale: mover la
        // ONT sería cortarle el servicio para nada.
        $linea = $this->perfilDeLinea((int) $actual['lp']);
        $llevaVlan = $linea && collect($linea['gems'])->flatten(1)->contains(fn ($m) => ($m['vlan'] ?? null) === $vlan);

        if (!$llevaVlan) {
            return ['ok' => false, 'detalle' => "El perfil de línea {$actual['lp']} de esta ONT no lleva la VLAN {$vlan}: preparalo en «Perfiles de línea» antes de darle acceso."] + $base;
        }

        if (!$f['online']) {
            return ['ok' => false, 'omitido' => 'apagada', 'detalle' => 'La ONT está apagada: se le da el acceso cuando vuelva.'] + $base;
        }

        // ── El cambio: la ONT se reinicia unos segundos ─────────────────────
        $resp = $this->volverAAgregar($puerto, $ontId, $f['serial'], $copia['id'], $f['descripcion'], $fs, $actual['id']);
        $estado = $this->esperarConfig($puerto, $ontId, 60, $copia['id'], $fs);

        if ($estado === 'success') {
            \Illuminate\Support\Facades\Cache::forget($this->claveTransito($puerto, $ontId, $fs));

            return ['ok' => true, 'detalle' => "Perfil de gestión {$copia['nombre']}: pide IP en la VLAN {$vlan} y se reporta al TR-069."] + $base;
        }

        // No lo aceptó (o no volvió): a su perfil de siempre, y el modelo anotado.
        $this->volverAAgregar($puerto, $ontId, $f['serial'], $actual['id'], $f['descripcion'], $fs, $actual['id']);
        $vuelta = $this->esperarConfig($puerto, $ontId, 60, $actual['id'], $fs);

        if ($vuelta === 'success') {
            \Illuminate\Support\Facades\Cache::forget($this->claveTransito($puerto, $ontId, $fs));
        }

        if ($estado === 'failed' && $f['equipo']) {
            self::marcarModeloSinWan($f['equipo']);
        }

        Log::error('[OLT C-Data] La ONT no aceptó el perfil de gestión: se revirtió', [
            'fsp' => $fsp, 'ont_id' => $ontId, 'equipo' => $f['equipo'], 'estado' => $estado, 'vuelta' => $vuelta, 'respuesta' => $resp,
        ]);

        return [
            'ok'      => false,
            'omitido' => $estado === 'failed' ? 'modelo_sin_wan' : 'no_volvio',
            'detalle' => ($estado === 'failed' ? self::instruccionesHgu($f['equipo']) : 'La ONT no volvió a tiempo con el perfil de gestión.')
                . ($vuelta === 'success' ? ' La ONT volvió a su perfil original y sigue con su servicio.' : ' ⚠️ Revisá la ONT: no se confirmó que volviera a su perfil.'),
        ] + $base;
    }

    /** Los perfiles WAN de TR-069 que están en esa VLAN. @return list<int> */
    private function wansDeGestionDeVlan(int $vlan): array
    {
        $ids = [];

        foreach (array_keys($this->tablaDePerfiles('show ont-wan-profile gpon all')) as $id) {
            $w = $this->mostrarEnConfig("show ont-wan-profile gpon profile-id {$id}");

            if (preg_match('/Connect type\s*:\s*TR069/i', $w) && preg_match('/VLAN id\s*:\s*' . $vlan . '\b/i', $w)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    public static function instruccionesHgu(?string $equipo): string
    {
        return 'El modelo ' . ($equipo ?: 'de esta ONT') . ' no acepta que la OLT le cree la conexión de gestión. '
            . 'Se configura una vez en la página web del equipo (Maintenance/Management → TR-069): ACS URL '
            . config('services.genieacs.url_equipos') . ', Periodic Inform activado cada 300 s, sobre su conexión de internet. '
            . 'Con eso aparece solo en Equipos.';
    }

    // ── Gestión remota (TR-069) en EPON ───────────────────────────────────
    //
    // Verificado con la ayuda de la consola en una C-Data EPON V1.6.0. EPON no
    // tiene perfiles de gestión: la VLAN se deja pasar por el puerto de subida y
    // los PON, y a cada ONT se le crea una WAN TR-069 por DHCP en esa VLAN. La
    // dirección del servidor le llega por la opción 43 del DHCP del MikroTik.

    private const WAN_GESTION = 'gestion';

    /** La OLT renombra la WAN creada ("gestion" → "2_TR069_R_VID_69"). */
    private static function esWanDeGestion(string $nombre): bool
    {
        return $nombre === self::WAN_GESTION || (bool) preg_match('/^\d+_TR069_R_VID_\d+$/i', $nombre);
    }

    /** Suma la VLAN al puerto de subida y a los PON, reponiendo lo que faltara. */
    private function vlanDeGestionEpon(int $vlan, string $puertoDeSubida): array
    {
        if (!preg_match('#^\s*(x?ge)\s*(\d+/\d+)/(\d+)\s*$#i', $puertoDeSubida, $m)) {
            return ['ok' => false, 'detalle' => "«{$puertoDeSubida}» no es un puerto de subida (ej. ge 0/0/1)."];
        }

        [$tipo, $fs, $numero] = [strtolower($m[1]), $m[2], (int) $m[3]];

        $destinos = ["{$tipo} {$fs}/{$numero}" => ["interface {$tipo} {$fs}", $numero]];
        foreach (self::PUERTOS_EPON as $pon) {
            $destinos["pon 0/0/{$pon}"] = ['interface epon 0/0', $pon];
        }

        $antes = $this->tablaDeVlans();

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $creada = $this->cmd("vlan {$vlan}", 15);
        $this->volverAlPrompt();

        $respuestas = '';

        foreach ($destinos as $nombre => [$interfaz, $n]) {
            if (in_array($vlan, $antes[$nombre]['vlans'] ?? [], true)) {
                continue;
            }

            $this->volverAlPrompt();
            $this->cmd('config', 8);
            $this->cmd($interfaz, 10);
            $respuestas .= $this->cmd("vlan trunk {$n} {$vlan}", 15);
            $this->volverAlPrompt();
        }

        // Lo que ya llevaba cada puerto tiene que seguir ahí.
        $despues = $this->tablaDeVlans();
        $repuestas = [];

        foreach ($destinos as $nombre => [$interfaz, $n]) {
            $faltan = array_values(array_diff($antes[$nombre]['vlans'] ?? [], $despues[$nombre]['vlans'] ?? []));

            if ($faltan) {
                Log::error('[OLT C-Data EPON] vlan trunk quitó VLAN: se reponen', ['puerto' => $nombre, 'faltan' => $faltan]);
                $this->volverAlPrompt();
                $this->cmd('config', 8);
                $this->cmd($interfaz, 10);
                $this->cmd("vlan trunk {$n} " . implode(',', array_merge($faltan, [$vlan])), 15);
                $this->volverAlPrompt();
                $repuestas[] = "{$nombre}: " . implode(', ', $faltan);
            }
        }

        if ($repuestas) {
            $despues = $this->tablaDeVlans();
        }

        $sin = array_keys(array_filter($destinos, fn ($d, $nombre) => !in_array($vlan, $despues[$nombre]['vlans'] ?? [], true), ARRAY_FILTER_USE_BOTH));
        $ok  = !$sin;

        return [
            'ok'      => $ok,
            'detalle' => ($ok
                    ? "VLAN {$vlan} en el puerto de subida {$tipo} {$fs}/{$numero} y en los PON " . implode(', ', self::PUERTOS_EPON) . '.'
                    : "La VLAN {$vlan} no quedó en " . implode(', ', $sin) . ': ' . ($this->mensaje($creada . "\n" . $respuestas) ?: 'sin detalle'))
                . ($repuestas ? ' Se repusieron VLAN que se habían quitado (' . implode('; ', $repuestas) . ').' : ''),
        ];
    }

    /**
     *   Index wanName                     IpMode   IPAddr        Mask
     *   1     1_TR069_INTERNET_R_VID_100  static   192.168.4.13  255.255.255.0
     *   Index DefGw  PrimaryDNS  SecondaryDNS  Status
     *   1     ...                              Connect
     *
     * La OLT escribe las IP con espacios ("192.168.  4. 13").
     *
     * @return array<int, array{nombre:string, modo:string, ip:?string, estado:?string}>
     */
    private function wansEpon(int $puerto, int $ontId): array
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $this->cmd('interface epon 0/0', 10);
        $salida = $this->cmd("show ont wan status {$puerto} {$ontId} all", 20);
        $this->volverAlPrompt();

        $ip = fn (string $t) => ($x = preg_replace('/\s+/', '', $t)) && filter_var($x, FILTER_VALIDATE_IP) ? $x : null;
        $wans = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (preg_match('/^\s*(\d+)\s+(\S+)\s+(static|dhcp|pppoe)\s+(\d{1,3}\.\s*\d{1,3}\.\s*\d{1,3}\.\s*\d{1,3})?/i', $linea, $m)) {
                $wans[(int) $m[1]] = ['nombre' => $m[2], 'modo' => strtolower($m[3]), 'ip' => $ip($m[4] ?? ''), 'estado' => null];
            } elseif (preg_match('/^\s*(\d+)\s+.*\s(Connect\w*|Disconnect\w*|Connecting)\s*$/i', $linea, $m) && isset($wans[(int) $m[1]])) {
                $wans[(int) $m[1]]['estado'] = $m[2];
            }
        }

        return $wans;
    }

    /** Crea la WAN de gestión y comprueba que tome IP; si no, la borra. */
    private function gestionEpon(string $fsp, int $ontId, int $vlan): array
    {
        ['port' => $puerto] = $this->partirFsp($fsp);

        // Recién autorizada o apagada: todavía no puede crear conexiones, y
        // probar igual marcaba a su fabricante como "no soporta".
        if (!$this->onlineEpon($puerto, $ontId)) {
            return ['ok' => false, 'omitido' => 'apagada', 'detalle' => 'La ONT no está en línea (o se está registrando): se le da el acceso cuando esté conectada.'];
        }

        $antes = $this->wansEpon($puerto, $ontId);
        $gestion = collect($antes)->first(fn ($w) => self::esWanDeGestion($w['nombre']));
        $oui = self::ouiDeOnt($this->macDeOnt($puerto, $ontId));
        $claveOui = self::claveSinWanRemota($this->config['company_id'] ?? null, $oui);

        if (!$gestion && $oui && \Illuminate\Support\Facades\Cache::has($claveOui)) {
            return ['ok' => false, 'omitido' => 'modelo_sin_wan', 'detalle' => self::sinWanRemota($oui)];
        }

        if ($gestion && self::ipUsable($gestion['ip'])) {
            return ['ok' => true, 'ip' => $gestion['ip'], 'detalle' => "Ya tiene la conexión de gestión ({$gestion['ip']})."];
        }

        if ($gestion) {
            // Existe pero la OLT no muestra una IP usable (la de una WAN por DHCP
            // la muestra mal): se confirma en el DHCP del router.
            return ['ok' => false, 'sin_confirmar' => true, 'existente' => true, 'detalle' => 'Ya tiene la conexión de gestión: se confirma su IP en el DHCP del router.'];
        }

        $internet = collect($antes)->filter(fn ($w) => !self::esWanDeGestion($w['nombre']) && stripos((string) $w['estado'], 'connect') === 0)->keys()->all();

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $this->cmd('interface epon 0/0', 10);
        $resp = $this->cmd(sprintf(
            'ont wan config %d %d %s vlan enable %d 0 connection-mode route service-type tr069 ip-mode dhcp mtu 1500 bind-if not-bind',
            $puerto, $ontId, self::WAN_GESTION, $vlan
        ), 20);
        $this->volverAlPrompt();

        if ($this->fallo($resp)) {
            return ['ok' => false, 'detalle' => 'La OLT no aceptó crear la conexión de gestión: ' . $this->mensaje($resp)];
        }

        // Hasta 60 s a que la conexión aparezca y tome IP.
        $limite = microtime(true) + 60;
        $ahora  = [];
        $aparecio = false;

        while (microtime(true) < $limite) {
            sleep(8);
            $ahora = $this->wansEpon($puerto, $ontId);
            $g = collect($ahora)->first(fn ($w) => self::esWanDeGestion($w['nombre']));
            $aparecio = $aparecio || (bool) $g;

            // La conexión de internet que tenía tiene que seguir conectada.
            if ($g && array_filter($internet, fn ($i) => stripos((string) ($ahora[$i]['estado'] ?? ''), 'connect') !== 0)) {
                return ['ok' => false, 'internet_caido' => true, 'detalle' => 'Al crear la conexión de gestión se cayó la conexión de internet del equipo: se deshace.'];
            }

            if ($g && self::ipUsable($g['ip'])) {
                return ['ok' => true, 'ip' => $g['ip'], 'detalle' => "Conexión de gestión creada: IP {$g['ip']} en la VLAN {$vlan}. La dirección del TR-069 le llega por DHCP."];
            }
        }

        // La OLT aceptó el comando pero el equipo no creó ninguna conexión: no
        // acepta configuración remota de WAN (pasó con 0/0/1:38, 94:B2:71…:
        // "ONT wan is not exist!"). Se anota el fabricante, por empresa y por
        // una semana, para no insistir con los equipos iguales.
        if (!$aparecio && !collect($antes)->count()) {
            if ($oui) {
                \Illuminate\Support\Facades\Cache::put($claveOui, now()->toDateTimeString(), now()->addDays(7));
            }

            return ['ok' => false, 'omitido' => 'modelo_sin_wan', 'detalle' => self::sinWanRemota($oui)];
        }

        // La conexión está pero la OLT no mostró una IP usable (con DHCP la
        // muestra mal: 10.30.0.35 aparece como 10.30.0.0). Quien llamó la
        // confirma en el DHCP del router y, si no está, llama a quitarGestionEpon().
        Log::warning('[OLT C-Data EPON] La OLT no mostró la IP de gestión', ['fsp' => $fsp, 'ont_id' => $ontId, 'wans' => $ahora]);

        return [
            'ok'            => false,
            'sin_confirmar' => true,
            'detalle'       => 'La OLT todavía no muestra la IP de gestión: se confirma en el DHCP del router.',
        ];
    }

    /** Una IP que sirve para llegar al equipo: ni 0.x ni la dirección de red (…0). */
    private static function ipUsable(?string $ip): bool
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) && !str_starts_with($ip, '0.') && !preg_match('/\.0$/', $ip);
    }

    public static function claveSinWanRemota($companyId, ?string $oui): string
    {
        return 'epon:sin-wan-remota:' . ($companyId ?? 'x') . ':' . $oui;
    }

    private function onlineEpon(int $puerto, int $ontId): bool
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd("show ont info 0/0 {$puerto} {$ontId}", 20);
        $this->volverAlPrompt();

        return (bool) preg_match('/Run state\s*:\s*online/i', $salida);
    }

    /** La MAC con la que está autorizada la ONT (de la tabla de ONT del puerto). */
    private function macDeOnt(int $puerto, int $ontId): ?string
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $tabla = self::tablaDeOnts($this->cmd($this->comando('listar_puerto', ['p' => $puerto]), 60));
        $this->volverAlPrompt();

        return collect($tabla)->firstWhere('ont_id', $ontId)['mac'] ?? null;
    }

    /** Los primeros 3 bytes de la MAC: identifican al fabricante. */
    private static function ouiDeOnt(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

        return strlen($hex) === 12 ? substr($hex, 0, 6) : null;
    }

    private static function sinWanRemota(?string $oui): string
    {
        return 'Este equipo no acepta que la OLT le cree conexiones (la OLT informa que no tiene WAN configurable)'
            . ($oui ? ' — fabricante ' . implode(':', str_split($oui, 2)) : '') . '. '
            . 'No se tocó su servicio. Suele ser una ONU en modo bridge o de una marca sin la configuración remota de C-Data: '
            . 'la gestión se configura en el propio equipo (o en el router del cliente) con ACS URL ' . config('services.genieacs.url_equipos') . '.';
    }

    /** Borra la WAN de gestión de una ONT EPON y dice si su internet sigue conectado. */
    public function quitarGestionEpon(string $fsp, int $ontId): array
    {
        ['port' => $puerto] = $this->partirFsp($fsp);
        $antes = $this->wansEpon($puerto, $ontId);
        $internet = collect($antes)->filter(fn ($w) => !self::esWanDeGestion($w['nombre']) && stripos((string) $w['estado'], 'connect') === 0)->keys()->all();

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $this->cmd('interface epon 0/0', 10);
        foreach (collect($antes)->filter(fn ($w) => self::esWanDeGestion($w['nombre']))->pluck('nombre')->push(self::WAN_GESTION)->unique() as $nombre) {
            $this->cmd("ont wan clear {$puerto} {$ontId} {$nombre}", 20);
        }
        $this->volverAlPrompt();

        $final = $this->wansEpon($puerto, $ontId);

        return [
            'borrada'     => !collect($final)->contains(fn ($w) => self::esWanDeGestion($w['nombre'])),
            'internet_ok' => !array_filter($internet, fn ($i) => stripos((string) ($final[$i]['estado'] ?? ''), 'connect') !== 0),
        ];
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
            return $this->altaEpon($fsp, $serial, $description, $lineProfileId, $vlan, $srvProfileId);
        }

        // Los perfiles se eligen antes de tocar nada: sin perfiles válidos la OLT
        // igual acepta la ONT, pero la deja sin servicio.
        $perfiles = $this->perfilesParaAlta($lineProfileId ?? $this->lineProfileId, $srvProfileId);

        if (isset($perfiles['error'])) {
            return ['success' => false, 'ont_id' => 0, 'message' => $perfiles['error']];
        }

        $puerto = $this->entrarAlPuerto($fsp);
        $ontId  = $this->siguienteOntId($fsp, $puerto);

        if ($ontId === null) {
            $this->volverAlPrompt();

            return ['success' => false, 'ont_id' => 0, 'message' => 'No quedan ONT ID libres en el puerto ' . $fsp];
        }

        $texto = substr(trim(str_replace('"', '', $description)), 0, 64);

        $valores = [
            'p'     => $puerto,
            'id'    => $ontId,
            'sn'    => strtoupper(str_replace('-', '', $serial)),
            'lp'    => $perfiles['lp'],
            'sp'    => $perfiles['sp'],
            'msp'   => $perfiles['msp'],
            'texto' => $texto,
        ];

        // Con mult-srv-profile, como el resto de las ONT de la OLT; si no hay
        // uno que junte esos dos perfiles, línea + servicio. Las del manual sólo
        // si el firmware rechaza la verificada.
        $comandos = $perfiles['msp'] !== null
            ? [$this->comando('alta_msp', $valores)]
            : $this->variantes('alta', $valores);

        $salida = '';
        foreach ($comandos as $comando) {
            $salida = $this->cmd($comando, 30);

            if (!$this->fallo($salida)) {
                break;
            }
        }

        if ($this->fallo($salida)) {
            $this->volverAlPrompt();

            Log::error('[OLT C-Data] Alta de ONT rechazada', [
                'fsp' => $fsp, 'serial' => $serial, 'salida' => $salida,
            ]);

            return ['success' => false, 'ont_id' => 0, 'message' => $this->mensaje($salida)];
        }

        if ($texto !== '') {
            $this->ponerDescripcion($puerto, $ontId, $texto);
        }

        $servicioOk = true;

        // Con mult-srv-profile la OLT crea sola el service-port del perfil (el
        // "auto" transparente que tienen las demás ONT). Ponerle native-vlan o
        // un service-port a mano la desvincula del perfil.
        if ($vlan && $perfiles['msp'] === null) {
            $servicioOk = $this->servicio($puerto, $ontId, $vlan, $servicePort ?: $this->indiceServicePortLibre(), $fsp, $description);
        }

        $this->volverAlPrompt();

        return [
            'success'              => true,
            'ont_id'               => $ontId,
            'port_id'              => $puerto,
            'message'              => "ONT registrada en {$fsp} con ONT ID {$ontId}",
            'servicio_ok'          => $servicioOk,
            // La pantalla lee estas claves: sin ellas marcaba el service-port
            // como fallido aunque estuviera.
            'service_port_created' => $servicioOk,
        ] + ($vlan && $perfiles['msp'] !== null ? [
            'vlan_paso' => [
                'ok'      => true,
                'titulo'  => "Service-port del perfil (mult-srv-profile {$perfiles['msp']})",
                'detalle' => 'La OLT lo crea sola con el perfil, igual que en las demás ONT. No se toca a mano: hacerlo desvincula la ONT del perfil.',
            ],
        ] : []);
    }

    /**
     * Qué perfiles usar en un alta GPON, validados contra lo que tiene la OLT.
     *
     * El de servicio configurado en la plataforma puede no existir (en una OLT
     * estaba el 10 y la OLT sólo tiene el 0): se toma el que acompaña al de
     * línea en su mult-srv-profile, o el único que haya.
     *
     * @return array{lp:int, sp:int, msp:?int}|array{error:string}
     */
    private function perfilesParaAlta(int $lineProfileId, ?int $srvProfileId): array
    {
        $lineas    = array_column($this->getLineProfiles(), 'id');
        $servicios = array_column($this->getSrvProfiles(), 'id');

        if ($lineas && !in_array($lineProfileId, $lineas, true)) {
            return ['error' => "El perfil de línea {$lineProfileId} no existe en la OLT (tiene " . implode(', ', $lineas) . '). Elegí uno de esos.'];
        }

        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $salida = $this->cmd($this->comando('mult_srv'), 30);
        $this->volverAlPrompt();

        $multis = [];
        foreach (preg_split('/^\s*-{5,}\s*$/m', $salida) as $bloque) {
            if (preg_match('/Profile-ID\s*:\s*(\d+)/i', $bloque, $id)
                && preg_match('/Ont-line-profile\s*:\s*(\d+)/i', $bloque, $lp)
                && preg_match('/Ont-srv-profile\s*:\s*(\d+)/i', $bloque, $sp)) {
                $multis[] = ['id' => (int) $id[1], 'lp' => (int) $lp[1], 'sp' => (int) $sp[1]];
            }
        }

        $srv = $srvProfileId ?? $this->srvProfileId;

        if ($servicios && !in_array($srv, $servicios, true)) {
            $delMulti = collect($multis)->firstWhere('lp', $lineProfileId)['sp'] ?? null;
            $srv = $delMulti ?? (count($servicios) === 1 ? $servicios[0] : null);

            if ($srv === null) {
                return ['error' => 'El perfil de servicio ' . ($srvProfileId ?? $this->srvProfileId) . ' no existe en la OLT (tiene ' . implode(', ', $servicios) . '). Elegí uno de esos.'];
            }
        }

        $multi = collect($multis)->first(fn ($m) => $m['lp'] === $lineProfileId && $m['sp'] === $srv);

        return ['lp' => $lineProfileId, 'sp' => (int) $srv, 'msp' => $multi['id'] ?? null];
    }

    /** El primer ONT ID libre del puerto. */
    private function siguienteOntId(string $fsp, int $puerto): ?int
    {
        $salida   = $this->cmd($this->comando('listar_puerto', ['p' => $puerto]), 60);
        $ocupados = array_column(self::tablaDeOnts($salida), 'ont_id')
            ?: array_map(fn ($o) => (int) $o['ont_id'], $this->leerOntInfo($salida));

        // Se consultó desde dentro de la interfaz: el alta sigue ahí.
        for ($id = 1; $id <= self::GRILLA['gpon']['max_ont']; $id++) {
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

        // native-vlan va dentro de la interfaz, y buscar el índice libre salió de ella.
        $this->entrarAlPuerto($fsp);
        $salida = $this->cmd($this->comando('native_vlan', ['p' => $puerto, 'id' => $ontId, 'vlan' => $vlan]), 20);

        $this->volverAlPrompt();
        $this->cmd('config', 8);

        if ($servicePort < 1 || $servicePort > 8192) {
            $servicePort = $this->indiceServicePortLibre();
            $this->volverAlPrompt();
            $this->cmd('config', 8);
        }

        $valores = ['idx' => $servicePort, 'vlan' => $vlan, 'fs' => "{$f}/{$s}", 'p' => $puerto, 'id' => $ontId];
        $sp = '';

        foreach ($this->variantes('service_port', $valores) as $comando) {
            $sp = $this->cmd($comando, 20);

            if (!$this->fallo($sp)) {
                break;
            }
        }

        if ($this->fallo($salida . $sp)) {
            Log::error('[OLT C-Data] VLAN o service-port rechazado', ['fsp' => $fsp, 'ont_id' => $ontId, 'salida' => $salida . $sp]);
        }

        return !$this->fallo($salida . $sp);
    }

    /** "ont description" admite espacios entre comillas; si no, con guiones bajos. */
    private function ponerDescripcion(int $puerto, int $ontId, string $texto): void
    {
        $salida = $this->cmd($this->comando('descripcion', ['p' => $puerto, 'id' => $ontId, 'texto' => $texto]), 15);

        if ($this->fallo($salida)) {
            $plano = substr(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $texto), 0, 64);
            $this->cmd(self::llenar('ont description {p} {id} {texto}', ['p' => $puerto, 'id' => $ontId, 'texto' => $plano]), 15);
        }
    }

    /** El primer índice de service-port que no usa nadie en la OLT. */
    private function indiceServicePortLibre(): int
    {
        $usados = array_column($this->getServicePorts(), 'index');

        for ($i = 1; $i <= 8192; $i++) {
            if (!in_array($i, $usados, true)) {
                return $i;
            }
        }

        return 8192;
    }

    public function deleteONT(string $fsp, int $ontId, array $servicePorts = []): bool
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);

        foreach ($servicePorts as $indice) {
            $this->cmd(self::llenar(self::GRILLA['gpon']['baja_sp'], ['idx' => (int) $indice]), 20);
        }

        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd($this->comando('baja', ['p' => $puerto, 'id' => $ontId]), 30);
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
        // En EPON la sintaxis de GPON ("ont port native-vlan" + service-port)
        // cambiaba el puerto de la ONU y después fallaba en el service-port,
        // que no existe: un cambio a medias. Acá sólo se verifica la VLAN.
        if ($this->esEpon()) {
            return $this->pasoVlan($fsp, $vlan)['ok'];
        }

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
        $salida = $this->cmd($this->comando('desactivar', ['p' => $puerto, 'id' => $ontId]), 20);
        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    public function activateONT(string $fsp, int $ontId): bool
    {
        $puerto = $this->entrarAlPuerto($fsp);
        $salida = $this->cmd($this->comando('activar', ['p' => $puerto, 'id' => $ontId]), 20);
        $this->volverAlPrompt();

        return !$this->fallo($salida);
    }

    public function getLineProfiles(): array
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);

        $salida = $this->primeraQueSirva($this->variantes('perfiles_linea'), 30)['salida'];

        $this->volverAlPrompt();

        return $this->leerPerfiles($salida);
    }

    public function getSrvProfiles(): array
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);

        $salida = $this->primeraQueSirva($this->variantes('perfiles_srv'), 30)['salida'];

        $this->volverAlPrompt();

        return $this->leerPerfiles($salida);
    }

    /**
     *   Profile-ID  Profile-name        Binding times
     *   0           lineprofile_0       65
     *
     * Con la forma que espera la sincronización ({id, name}): antes se
     * devolvía [id => nombre] y la pantalla mostraba "Sin perfil" aunque la
     * OLT tuviera perfiles.
     *
     * @return list<array{id:int, name:string, uso:?int}>
     */
    private function leerPerfiles(string $salida): array
    {
        $perfiles = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (!preg_match('/^\s*(\d+)\s+(\S+)(?:\s+(\S+))?\s*$/', $linea, $m)) {
                continue;
            }

            // GPON lista "ID  Binding  Nombre"; EPON "ID  Nombre  Binding".
            $usoPrimero = ctype_digit($m[2]) && isset($m[3]) && !ctype_digit($m[3]);

            $perfiles[] = [
                'id'   => (int) $m[1],
                'name' => $usoPrimero ? $m[3] : $m[2],
                'uso'  => $usoPrimero ? (int) $m[2] : (isset($m[3]) && ctype_digit($m[3]) ? (int) $m[3] : null),
            ];
        }

        return $perfiles;
    }

    // ── VLAN en EPON ──────────────────────────────────────────────────────

    /**
     * Qué puede y qué no puede este equipo, para que la pantalla de alta
     * muestre sólo lo que aplica.
     *
     * @return array<string,mixed>
     */
    public function capacidades(): array
    {
        if ($this->esEpon()) {
            return [
                'tecnologia'              => 'epon',
                'identificador'           => 'mac',
                'service_port'            => false,
                // Se elige junto con el de línea: la OLT no acepta uno sin el otro.
                'perfil_servicio_en_alta' => true,
                'vlan'                    => 'puerto-pon',
                'explicacion_vlan'        => 'En EPON no hay service-port: el perfil de servicio deja pasar la VLAN tal cual la manda la ONU, y lo que hace falta es que el puerto PON lleve esa VLAN.',
            ];
        }

        return [
            'tecnologia'              => 'gpon',
            'identificador'           => 'serial',
            'service_port'            => true,
            'perfil_servicio_en_alta' => true,
            'vlan'                    => 'service-port',
            'explicacion_vlan'        => null,
        ];
    }

    /**
     * Las VLAN que lleva cada puerto PON, leídas de la configuración:
     *
     *   vlan mode 2 trunk
     *   vlan trunk 2 100
     *
     * @return array<int, list<int>>
     */
    private function vlansPorPuerto(): array
    {
        $this->volverAlPrompt();
        $this->cmd('config', 8);
        $config = $this->cmd('show current-config section epon all', 60);
        $this->volverAlPrompt();

        $vlans = [];

        if (preg_match_all('/^\s*vlan\s+(?:trunk|hybrid(?:\s+tagged)?)\s+(\d)\s+([\d,\s-]+)$/mi', $config, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                foreach (preg_split('/[\s,]+/', trim($x[2])) as $tramo) {
                    if (preg_match('/^(\d+)-(\d+)$/', $tramo, $r)) {
                        $vlans[(int) $x[1]] = array_merge($vlans[(int) $x[1]] ?? [], range((int) $r[1], (int) $r[2]));
                    } elseif (ctype_digit($tramo)) {
                        $vlans[(int) $x[1]][] = (int) $tramo;
                    }
                }
            }
        }

        return array_map(fn ($l) => array_values(array_unique($l)), $vlans);
    }

    /**
     * El paso de VLAN de un alta en EPON: el equivalente al service-port de
     * Huawei es que el puerto PON lleve la VLAN. Sólo se verifica; no se
     * agrega sola, porque no está confirmado si `vlan trunk` suma a la lista
     * o la reemplaza, y reemplazarla dejaría sin servicio a todo el puerto.
     *
     * @return array{ok:bool, titulo:string, detalle:string}
     */
    public function pasoVlan(string $fsp, int $vlan): array
    {
        ['port' => $puerto] = $this->partirFsp($fsp);

        $lleva = $this->vlansPorPuerto()[$puerto] ?? [];

        if (in_array($vlan, $lleva, true)) {
            return [
                'ok'      => true,
                'titulo'  => "VLAN {$vlan} en el puerto PON {$fsp}",
                'detalle' => "El puerto ya la lleva. Con el perfil de servicio transparente la ONU la entrega tal cual: no hace falta service-port.",
            ];
        }

        return [
            'ok'      => false,
            'titulo'  => "VLAN {$vlan} en el puerto PON {$fsp}",
            'detalle' => "El puerto no lleva la VLAN {$vlan}"
                . ($lleva ? ' (lleva ' . implode(', ', $lleva) . ')' : '')
                . ". Hay que agregarla en la OLT: interface epon 0/0 → vlan trunk {$puerto} …",
        ];
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
