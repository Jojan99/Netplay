<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Alta y gestión de clientes que se conectan por PPPoE.
 *
 * Con IP fija el cliente vive en el ARP del router y la plataforma decide qué
 * IP le toca. Con PPPoE es al revés: el cliente se autentica con usuario y
 * contraseña, y la IP se la da el router desde un pool. Lo que la plataforma
 * administra entonces no es una IP sino una credencial —el "secret"— y el
 * perfil que le fija la velocidad.
 *
 * Suspender tampoco es lo mismo: en vez de sacarle la IP se deshabilita el
 * secret y se le corta la sesión, porque si no sigue navegando hasta que
 * reconecte.
 */
class ServicioPppoe
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private string $token,
    ) {}

    /* ── Lectura ──────────────────────────────────────────────────────────── */

    /**
     * Qué tiene el router preparado para PPPoE.
     *
     * @return array{disponible:bool, servidores:array, perfiles:array, pools:array, secrets:int, sesiones:int}
     */
    public function estado(): array
    {
        $api = $this->api();

        $servidores = $this->leer($api, '/interface/pppoe-server/server/print');
        $perfiles   = $this->leer($api, '/ppp/profile/print');
        $pools      = $this->leer($api, '/ip/pool/print');

        return [
            // Sin un servidor PPPoE levantado los secrets no sirven de nada:
            // se pueden crear, pero nadie va a poder autenticarse.
            'disponible' => count($servidores) > 0,
            'servidores' => array_map(fn ($s) => [
                'nombre'     => $s['service-name'] ?? ($s['name'] ?? ''),
                'interfaz'   => $s['interface'] ?? '',
                'perfil'     => $s['default-profile'] ?? '',
                'habilitado' => ($s['disabled'] ?? 'false') !== 'true',
            ], $servidores),
            'perfiles' => array_map(fn ($p) => [
                'nombre'          => $p['name'] ?? '',
                'velocidad'       => $p['rate-limit'] ?? null,
                'direccion_local' => $p['local-address'] ?? null,
                'pool'            => $p['remote-address'] ?? null,
            ], $perfiles),
            'pools' => array_map(fn ($p) => [
                'nombre' => $p['name'] ?? '',
                'rangos' => $p['ranges'] ?? '',
            ], $pools),
            'secrets'  => count($this->leer($api, '/ppp/secret/print')),
            'sesiones' => count($this->leer($api, '/ppp/active/print')),
        ];
    }

    /**
     * Los usuarios PPPoE dados de alta en el router, con su sesión si la tienen.
     *
     * @return array<int,array<string,mixed>>
     */
    public function usuarios(): array
    {
        $api = $this->api();

        $activas = [];

        foreach ($this->leer($api, '/ppp/active/print') as $s) {
            $activas[$s['name'] ?? ''] = [
                'ip'       => $s['address'] ?? null,
                'desde'    => $s['uptime'] ?? null,
                'servicio' => $s['service'] ?? null,
                'mac'      => $s['caller-id'] ?? null,
            ];
        }

        return array_map(function ($s) use ($activas) {
            $usuario = $s['name'] ?? '';

            return [
                'usuario'    => $usuario,
                'perfil'     => $s['profile'] ?? null,
                'servicio'   => $s['service'] ?? null,
                'comentario' => $s['comment'] ?? null,
                'habilitado' => ($s['disabled'] ?? 'false') !== 'true',
                'ultima_ip'  => $s['last-logged-out'] ?? null,
                'sesion'     => $activas[$usuario] ?? null,
            ];
        }, $this->leer($api, '/ppp/secret/print'));
    }

    /* ── Alta y bajas ─────────────────────────────────────────────────────── */

    /**
     * Crea el usuario PPPoE. Si ya existe, lo actualiza.
     *
     * El documento va en el comment, igual que en el ARP de los clientes con
     * IP fija: es como el resto del sistema encuentra de quién es cada cosa.
     */
    public function crear(string $usuario, string $clave, string $perfil, string $documento): void
    {
        $api = $this->api();
        $id  = $this->idDe($api, $usuario);

        $q = new Query($id ? '/ppp/secret/set' : '/ppp/secret/add');

        if ($id) {
            $q->equal('.id', $id);
        }

        $q->equal('name', $usuario);
        $q->equal('password', $clave);
        $q->equal('profile', $perfil);
        $q->equal('service', 'pppoe');
        $q->equal('comment', $documento);

        $api->query($q)->read();

        Log::info('[PPPoE] Usuario dado de alta', ['usuario' => $usuario, 'perfil' => $perfil]);
    }

    /**
     * Corta el servicio: deshabilita el secret y baja la sesión.
     *
     * Sin bajar la sesión el cliente sigue navegando hasta que se desconecte
     * solo, que puede ser dentro de varios días.
     */
    public function suspender(string $usuario): void
    {
        $this->habilitar($usuario, false);
        $this->cortarSesion($usuario);
    }

    public function reactivar(string $usuario): void
    {
        $this->habilitar($usuario, true);
    }

    public function eliminar(string $usuario): void
    {
        $api = $this->api();
        $id  = $this->idDe($api, $usuario);

        if (!$id) {
            return;
        }

        $this->cortarSesion($usuario);

        $q = new Query('/ppp/secret/remove');
        $q->equal('.id', $id);
        $api->query($q)->read();
    }

    /** Le cambia el plan: en PPPoE la velocidad la fija el perfil. */
    public function cambiarPerfil(string $usuario, string $perfil): void
    {
        $api = $this->api();
        $id  = $this->idDe($api, $usuario);

        if (!$id) {
            return;
        }

        $q = new Query('/ppp/secret/set');
        $q->equal('.id', $id);
        $q->equal('profile', $perfil);
        $api->query($q)->read();

        // El perfil nuevo recién se aplica cuando vuelve a conectarse.
        $this->cortarSesion($usuario);
    }

    /* ── Interno ──────────────────────────────────────────────────────────── */

    private function api()
    {
        return $this->conexion->conection($this->token);
    }

    /** @return array<int,array<string,mixed>> */
    private function leer($api, string $comando): array
    {
        try {
            return $api->query(new Query($comando))->read();
        } catch (\Throwable $e) {
            Log::warning('[PPPoE] Consulta fallida', ['comando' => $comando, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function idDe($api, string $usuario): ?string
    {
        try {
            $q = new Query('/ppp/secret/print');
            $q->where('name', $usuario);
            $q->add('=.proplist=.id');

            return $api->query($q)->read()[0]['.id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function habilitar(string $usuario, bool $habilitado): void
    {
        $api = $this->api();
        $id  = $this->idDe($api, $usuario);

        if (!$id) {
            return;
        }

        $q = new Query($habilitado ? '/ppp/secret/enable' : '/ppp/secret/disable');
        $q->equal('.id', $id);
        $api->query($q)->read();
    }

    private function cortarSesion(string $usuario): void
    {
        try {
            $api = $this->api();

            $q = new Query('/ppp/active/print');
            $q->where('name', $usuario);
            $q->add('=.proplist=.id');

            foreach ($api->query($q)->read() as $sesion) {
                $baja = new Query('/ppp/active/remove');
                $baja->equal('.id', $sesion['.id']);
                $api->query($baja)->read();
            }
        } catch (\Throwable $e) {
            Log::warning('[PPPoE] No se pudo cortar la sesión', [
                'usuario' => $usuario, 'error' => $e->getMessage(),
            ]);
        }
    }
}
