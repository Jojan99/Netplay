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
    /** Estado del servidor y de cada túnel. */
    public function estado(): array
    {
        return ['status' => 0, 'message' => 'OK', 'data' => ServidorVpn::estado()];
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
        $tunel = VpnTunel::find($id);

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
        $tunel = VpnTunel::find($id);

        if (!$tunel) {
            return ['status' => 1, 'message' => 'Túnel no encontrado', 'data' => null];
        }

        try {
            if (array_key_exists('redes_remotas', $datos)) {
                $datos['redes_remotas'] = ServidorVpn::normalizarRedes($datos['redes_remotas']);
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
        $tunel = VpnTunel::find($id);

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
        $tunel = VpnTunel::find($tunelId);
        $olt   = OltAdmin::find($oltId);

        if (!$tunel || !$olt) {
            return ['status' => 1, 'message' => 'Túnel u OLT no encontrados', 'data' => null];
        }

        if (!$this->estaEnAlgunaRed($olt->host, $tunel)) {
            return [
                'status'  => 1,
                'message' => "La IP de la OLT ({$olt->host}) no está en las redes de este túnel ("
                    . implode(', ', $tunel->redes_remotas ?? []) . '). Agregá la red y volvé a intentar.',
                'data'    => null,
            ];
        }

        if ($verificar && !$this->contesta($olt->host, (int) ($olt->port ?: 23))) {
            return [
                'status'  => 1,
                'message' => "El túnel está configurado pero {$olt->host}:{$olt->port} todavía no responde. "
                    . 'Verificá que el script ya se aplicó en el router y que el túnel está saludando.',
                'data'    => null,
            ];
        }

        $antes = [
            'access_mode'    => $olt->access_mode,
            'jump_host'      => $olt->jump_host,
            'snmp_jump_host' => $olt->snmp_jump_host,
        ];

        $olt->forceFill([
            'access_mode'    => 'direct',
            'snmp_jump_host' => null,
            'snmp_host'      => null,   // SNMP directo a la OLT por el túnel
        ])->save();

        // El mapa de puertos y la ficha quedaron atados al camino anterior.
        \App\Services\Olt\EquipoDeOlt::olvidar($olt);

        return [
            'status'  => 0,
            'message' => "La OLT {$olt->name} ahora se alcanza por el túnel, sin jump host.",
            'data'    => ['antes' => $antes, 'olt' => $olt->fresh()],
        ];
    }

    /** Prueba de alcance a una IP por el túnel. */
    public function probarAlcance(int $tunelId, string $ip, int $puerto): array
    {
        $tunel = VpnTunel::find($tunelId);

        if (!$tunel) {
            return ['status' => 1, 'message' => 'Túnel no encontrado', 'data' => null];
        }

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

    /** ¿Alguna OLT depende de este túnel para llegar? */
    private function oltsQueUsan(VpnTunel $tunel)
    {
        return OltAdmin::where('access_mode', 'direct')->get()
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
