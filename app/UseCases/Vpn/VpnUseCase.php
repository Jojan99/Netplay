<?php

namespace App\UseCases\Vpn;

use App\Models\OltAdmin;
use App\Models\VpnTunel;
use App\Services\Vpn\InstaladorVpn;
use App\Services\Vpn\ScriptMikrotik;
use App\Services\Vpn\ServidorVpn;
use Illuminate\Support\Facades\Log;

class VpnUseCase
{
    /** Estado del servidor y de los túneles de la empresa que consulta. */
    public function estado(): array
    {
        return ['status' => 0, 'message' => 'OK', 'data' => ServidorVpn::estado(self::empresa())];
    }

    /**
     * La empresa de la sesión.
     *
     * Todo lo que se lista o se modifica va contra ella: el servidor VPN es uno
     * para toda la instalación, pero los túneles son de cada cliente y no se
     * pueden ver ni tocar entre empresas.
     */
    private static function empresa(): ?int
    {
        $empresa = getSessionCompanyId();

        return $empresa ? (int) $empresa : null;
    }

    /**
     * Un túnel de la empresa de la sesión, o null.
     *
     * En consola no hay sesión: ahí se permite operar sin filtro, que es lo que
     * necesitan las tareas de mantenimiento.
     */
    private static function tunelDeLaEmpresa(int $id): ?VpnTunel
    {
        $empresa = self::empresa();

        return VpnTunel::where('id', $id)
            ->when($empresa !== null, fn ($q) => $q->where('company_id', $empresa))
            ->first();
    }

    /** El script de instalación del lado servidor, para correr con sudo. */
    public function instalador(): array
    {
        // Se deja el estado escrito antes, así el instalador ya puede levantar
        // el túnel en su último paso.
        ServidorVpn::aplicar();

        $ruta = InstaladorVpn::guardar();

        return [
            'status'  => 0,
            'message' => 'OK',
            'data'    => [
                'ruta'    => $ruta,
                'comando' => 'sudo bash ' . $ruta,
                'script'  => InstaladorVpn::script(),
            ],
        ];
    }

    /**
     * Crea el túnel y devuelve el script para el router. Las claves privadas
     * van sólo en esta respuesta: después quedan cifradas en la base.
     */
    public function crearTunel(array $datos): array
    {
        try {
            $creado   = ServidorVpn::crearTunel($datos);
            $servidor = ServidorVpn::configuracion();

            return [
                'status'  => 0,
                'message' => 'Túnel creado. Pegá el script en el router para levantarlo.',
                'data'    => [
                    'tunel'  => $creado['tunel'],
                    'script' => ScriptMikrotik::para(
                        $creado['tunel'],
                        $servidor,
                        $creado['clave_privada'],
                        $creado['clave_compartida'],
                    ),
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('[VPN] No se pudo crear el túnel', ['error' => $e->getMessage()]);

            return ['status' => 1, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Vuelve a entregar el script de un túnel existente.
     *
     * La clave privada del router se puede recuperar porque la plataforma la
     * guarda cifrada: así se regenera el script cuando el técnico lo perdió,
     * sin tener que rotar el túnel y volver a configurar el router.
     */
    public function script(int $id): array
    {
        $tunel = self::tunelDeLaEmpresa($id);

        if (!$tunel) {
            return ['status' => 1, 'message' => 'Túnel no encontrado', 'data' => null];
        }

        return [
            'status'  => 0,
            'message' => 'OK',
            'data'    => [
                'script' => ScriptMikrotik::para(
                    $tunel,
                    ServidorVpn::configuracion(),
                    (string) $tunel->clave_privada,
                    (string) $tunel->clave_compartida,
                ),
            ],
        ];
    }

    /** Cambia las redes alcanzables o los datos del túnel. */
    public function actualizarTunel(int $id, array $datos): array
    {
        $tunel = self::tunelDeLaEmpresa($id);

        if (!$tunel) {
            return ['status' => 1, 'message' => 'Túnel no encontrado', 'data' => null];
        }

        try {
            // Al reactivar un túnel se revisan sus redes otra vez: mientras
            // estuvo apagado otra empresa pudo haber publicado alguna, y
            // encenderlo tal cual le quitaba la ruta.
            $reactivar = !empty($datos['activo']) && !$tunel->activo;

            if ($reactivar && !array_key_exists('redes_remotas', $datos)) {
                $datos['redes_remotas'] = $tunel->redes_remotas ?? [];
            }

            if (array_key_exists('redes_remotas', $datos)) {
                // Las virtuales que ya tenía vuelven a su red real, así la
                // traducción se decide de nuevo contra el estado actual.
                $redes = ServidorVpn::normalizarRedes($datos['redes_remotas']);

                if ($reactivar) {
                    $redes = array_map(fn ($r) => $tunel->realDe((string) $r), $redes);
                }

                [$datos['redes_remotas'], $traducciones] = ServidorVpn::resolverChoques(
                    $redes,
                    (int) $tunel->company_id,
                    $tunel->id,
                    $tunel->traducciones ?? [],
                );
                ServidorVpn::verificarRedesLibres($datos['redes_remotas'], $tunel->id);

                if ($traducciones || $tunel->traducciones) {
                    $tunel->traducciones = $traducciones ?: null;
                }
            }

            $tunel->fill(array_intersect_key($datos, array_flip([
                'nombre', 'router_id', 'redes_remotas', 'keepalive', 'activo', 'notas',
            ])))->save();

            $aplicado = ServidorVpn::aplicar();

            return [
                'status'  => 0,
                'message' => $aplicado['aplicado']
                    ? 'Túnel actualizado y aplicado en el servidor'
                    : 'Túnel actualizado. ' . $aplicado['motivo'],
                'data'    => $tunel->fresh(),
            ];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    public function eliminarTunel(int $id): array
    {
        $tunel = self::tunelDeLaEmpresa($id);

        if (!$tunel) {
            return ['status' => 1, 'message' => 'Túnel no encontrado', 'data' => null];
        }

        // Una OLT que llega por este túnel se queda sin camino: mejor avisar que
        // dejarla muda.
        $olts = $this->oltsQueUsan($tunel);

        if ($olts->isNotEmpty()) {
            return [
                'status'  => 1,
                'message' => 'Hay OLT que llegan por este túnel: ' . $olts->pluck('name')->implode(', ')
                    . '. Cambiales el acceso antes de eliminarlo.',
                'data'    => null,
            ];
        }

        ServidorVpn::eliminarTunel($tunel);

        return ['status' => 0, 'message' => 'Túnel eliminado', 'data' => null];
    }

    /**
     * Pasa una OLT a alcanzarse por el túnel en vez de por el jump host.
     *
     * Es el objetivo de todo esto: la OLT deja de depender de que el router
     * tenga SSH abierto y de abrir una sesión por consulta. Antes de guardar se
     * comprueba que el equipo conteste, porque apuntar la OLT a un túnel que
     * todavía no llega la deja inalcanzable.
     */
    public function usarTunelEnOlt(int $tunelId, int $oltId, bool $verificar = true): array
    {
        $tunel = self::tunelDeLaEmpresa($tunelId);
        $olt   = self::oltDeLaEmpresa($oltId);

        if (!$tunel || !$olt) {
            return ['status' => 1, 'message' => 'Túnel u OLT no encontrados', 'data' => null];
        }

        // En una red traducida la OLT se alcanza por su IP virtual.
        $ipReal = $olt->host;
        $olt->host = $tunel->ipAlcanzable((string) $olt->host);

        if (!$this->estaEnAlgunaRed($olt->host, $tunel)) {
            return [
                'status'  => 1,
                'message' => "La IP de la OLT ({$ipReal}) no está en las redes de este túnel ("
                    . implode(', ', $tunel->redes_remotas ?? []) . '). Agregá la red y volvé a intentar.',
                'data'    => null,
            ];
        }

        if ($verificar) {
            $prueba = $this->alcanzable($olt);

            if (!$prueba['ok']) {
                return [
                    'status'  => 1,
                    'message' => "El túnel está configurado pero {$olt->host} todavía no responde. "
                        . 'Verificá que el script ya se aplicó en el router y que el túnel está saludando.',
                    'data'    => null,
                ];
            }
        }

        $antes = [
            'access_mode'    => $olt->access_mode,
            'jump_host'      => $olt->jump_host,
            'snmp_jump_host' => $olt->snmp_jump_host,
        ];

        // Se borran también las credenciales del jump: son la contraseña SSH de
        // un router en producción guardada en la base, y con el túnel andando
        // ya no hacen falta. Si algún día hay que volver atrás, se cargan de
        // nuevo en la configuración de la OLT.
        $olt->forceFill([
            'host'           => $olt->host,
            'access_mode'    => 'direct',
            'jump_host'      => null,
            'jump_user'      => null,
            'jump_pass'      => null,
            'snmp_jump_host' => null,
            'snmp_jump_user' => null,
            'snmp_jump_pass' => null,
            'snmp_host'      => null,   // SNMP directo a la OLT por el túnel
        ])->save();

        // El mapa de puertos y la ficha quedaron atados al camino anterior.
        \App\Services\Olt\EquipoDeOlt::olvidar($olt);
        \App\Services\Olt\SenalDeLaOlt::olvidar($olt);

        // El worker mantiene la sesión abierta por el jump: hay que pedirle que
        // se reinicie, o seguiría entrando por el camino viejo hasta que se
        // caiga por inactividad.
        try {
            \Illuminate\Support\Facades\Redis::setex("olt:{$olt->id}:recargar", 300, 1);
        } catch (\Throwable $e) {
            Log::warning('[VPN] No se pudo pedir la recarga del worker', ['olt' => $olt->id, 'error' => $e->getMessage()]);
        }

        return [
            'status'  => 0,
            'message' => "La OLT {$olt->name} ahora se alcanza por el túnel"
                . ($olt->host !== $ipReal ? " en {$olt->host} (su IP real {$ipReal} la usa otra empresa)" : '')
                . '. Se quitó el jump host'
                . ($antes['jump_host'] ? " ({$antes['jump_host']})" : '') . ' y sus credenciales.',
            'data'    => ['antes' => $antes, 'olt' => $olt->fresh()],
        ];
    }

    /** Prueba de alcance a una IP por el túnel. */
    public function probarAlcance(int $tunelId, string $ip, int $puerto): array
    {
        $tunel = self::tunelDeLaEmpresa($tunelId);

        if (!$tunel) {
            return ['status' => 1, 'message' => 'Túnel no encontrado', 'data' => null];
        }

        $ip = $tunel->ipAlcanzable($ip);

        if (!$this->estaEnAlgunaRed($ip, $tunel)) {
            return [
                'status'  => 1,
                'message' => "{$ip} no está en las redes declaradas de este túnel.",
                'data'    => null,
            ];
        }

        $inicio = microtime(true);
        $ok     = $this->contesta($ip, $puerto);
        $ms     = (int) ((microtime(true) - $inicio) * 1000);

        return [
            'status'  => $ok ? 0 : 1,
            'message' => $ok
                ? "{$ip}:{$puerto} responde por el túnel ({$ms} ms)."
                : "{$ip}:{$puerto} no responde por el túnel.",
            'data'    => ['ok' => $ok, 'ms' => $ms],
        ];
    }

    /** Una OLT de la empresa de la sesión, o null. */
    private static function oltDeLaEmpresa(int $id): ?OltAdmin
    {
        $empresa = self::empresa();

        return OltAdmin::where('id', $id)
            ->when($empresa !== null, fn ($q) => $q->where('company_id', $empresa))
            ->first();
    }

    /** ¿Alguna OLT depende de este túnel para llegar? */
    private function oltsQueUsan(VpnTunel $tunel)
    {
        return OltAdmin::where('access_mode', 'direct')
            ->when($tunel->company_id !== null, fn ($q) => $q->where('company_id', $tunel->company_id))
            ->get()
            ->filter(fn (OltAdmin $olt) => $this->estaEnAlgunaRed($olt->host, $tunel))
            ->values();
    }

    private function estaEnAlgunaRed(?string $ip, VpnTunel $tunel): bool
    {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        foreach ($tunel->redes_remotas ?? [] as $red) {
            [$base, $bits] = explode('/', $red);
            $mascara = (int) $bits === 0 ? 0 : (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;

            if ((ip2long($ip) & $mascara) === (ip2long($base) & $mascara)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Se llega a la OLT por el túnel?
     *
     * Se prueba con SNMP y, si no, con un ping. A propósito NO se abre una
     * sesión de consola: las OLT Huawei reservan el cupo de sesión durante
     * varios minutos aunque el socket se cierre enseguida, así que comprobar
     * por telnet le gastaba una sesión en cada verificación, y con unas pocas
     * la OLT empieza a contestarle "session limit reached" a la plataforma.
     *
     * @return array{ok:bool, como:?string}
     */
    private function alcanzable(OltAdmin $olt): array
    {
        // SNMP es UDP: no consume sesiones y además confirma que la comunidad
        // sigue sirviendo por el camino nuevo.
        if (extension_loaded('snmp') && $olt->snmp_community) {
            $destino = $olt->host . ':' . ((int) ($olt->snmp_port ?: 161));
            $oid     = '1.3.6.1.2.1.1.5.0';   // sysName

            $valor = ($olt->snmp_version === '1')
                ? @snmpget($destino, $olt->snmp_community, $oid, 3_000_000, 1)
                : @snmp2_get($destino, $olt->snmp_community, $oid, 3_000_000, 1);

            if (is_string($valor) && trim($valor) !== '') {
                return ['ok' => true, 'como' => 'snmp'];
            }
        }

        if ($this->respondePing($olt->host)) {
            return ['ok' => true, 'como' => 'ping'];
        }

        return ['ok' => false, 'como' => null];
    }

    /** Un ping corto. No necesita privilegios en Linux. */
    private function respondePing(string $host): bool
    {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $salida = [];
        $codigo = 1;

        exec(sprintf('ping -c 2 -W 2 %s 2>/dev/null', escapeshellarg($host)), $salida, $codigo);

        return $codigo === 0;
    }

    /** ¿Hay algo escuchando del otro lado? */
    private function contesta(string $host, int $puerto): bool
    {
        $conexion = @fsockopen($host, $puerto, $errno, $error, 5);

        if (is_resource($conexion)) {
            fclose($conexion);

            return true;
        }

        return false;
    }
}
