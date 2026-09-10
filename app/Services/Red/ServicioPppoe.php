<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\DB;
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
                'autenticacion' => $s['authentication'] ?? null,
                'una_sesion' => ($s['one-session-per-host'] ?? '') === 'true',
                'max_sesiones' => $s['max-sessions'] ?? null,
                // RouterOS marca así el servidor que no puede levantar: pasa
                // cuando la interfaz elegida no sirve para atender clientes.
                'invalido'   => ($s['invalid'] ?? 'false') === 'true',
            ], $servidores),
            'perfiles' => array_map(fn ($p) => [
                'nombre'          => $p['name'] ?? '',
                'velocidad'       => $p['rate-limit'] ?? null,
                'direccion_local' => $p['local-address'] ?? null,
                'pool'            => $p['remote-address'] ?? null,
                'una_sesion'      => ($p['only-one'] ?? 'default') === 'yes',
                'dns'             => $p['dns-server'] ?? null,
                'cifrado'         => ($p['use-encryption'] ?? '') === 'yes',
                'del_sistema'     => ($p['default'] ?? 'false') === 'true',
                // Un perfil que reparte de otro pool no sirve para clientes:
                // los mandaría a la red equivocada.
                'en_uso'          => $this->cuantosUsan($p['name'] ?? ''),
            ], $perfiles),
            'pools' => array_map(fn ($p) => [
                'nombre' => $p['name'] ?? '',
                'rangos' => $p['ranges'] ?? '',
            ], $pools),
            'secrets'  => count($this->leer($api, '/ppp/secret/print')),
            'sesiones' => count($this->leer($api, '/ppp/active/print')),
            // Lo que hay conectado por VPN y no por PPPoE. Se informa para que
            // no parezca que faltan clientes ni que sobran.
            'otros_ppp' => $this->otrosServiciosPpp($api),
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
                'ip'         => $s['address'] ?? null,
                'desde'      => $s['uptime'] ?? null,
                'servicio'   => $s['service'] ?? null,
                'mac'        => $s['caller-id'] ?? null,
                'sesion_id'  => $s['session-id'] ?? null,
                'codificacion' => $s['encoding'] ?? null,
            ];
        }

        // El documento del cliente va en el comment, así que se puede mostrar
        // de quién es cada credencial y no sólo el usuario.
        $clientes = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $this->companyId())
            ->where('ud.active', 1)
            ->whereNotNull('ud.dni')
            ->get(['ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname', 'ud.pppoe_user'])
            ->keyBy(fn ($c) => preg_replace('/\D/', '', (string) $c->dni));

        // MikroTik guarda en el mismo lugar las credenciales de PPPoE y las de
        // las VPN —L2TP, PPTP, SSTP, OpenVPN—. Acá interesan sólo las de
        // clientes: la cuenta de la VPN del propio ISP no es un abonado.
        $secrets = array_filter(
            $this->leer($api, '/ppp/secret/print'),
            fn ($s) => in_array($s['service'] ?? '', ['pppoe', 'any', ''], true)
        );

        return array_values(array_map(function ($s) use ($activas, $clientes) {
            $usuario = $s['name'] ?? '';
            $documento = trim((string) ($s['comment'] ?? ''));
            $cliente = $clientes[preg_replace('/\D/', '', $documento)] ?? null;

            return [
                'usuario'    => $usuario,
                'perfil'     => $s['profile'] ?? null,
                'servicio'   => $s['service'] ?? null,
                'comentario' => $documento ?: null,
                'habilitado' => ($s['disabled'] ?? 'false') !== 'true',
                'cliente'    => $cliente ? trim($cliente->names . ' ' . $cliente->lastname) : null,
                'user_id'    => $cliente->user_id ?? null,
                'ultima_salida' => $s['last-logged-out'] ?? null,
                'ultimo_motivo' => $s['last-disconnect-reason'] ?? null,
                'ultima_mac'    => $s['last-caller-id'] ?? null,
                // Sólo cuenta como conectado si entró por PPPoE: la misma
                // credencial podría estar usándose para otra cosa.
                'sesion'     => ($activas[$usuario]['servicio'] ?? null) === 'pppoe'
                    ? $activas[$usuario]
                    : null,
            ];
        }, $secrets));
    }

    /**
     * Sesiones PPP que no son PPPoE, agrupadas por servicio.
     *
     * @return array<int,array{servicio:string, sesiones:int}>
     */
    private function otrosServiciosPpp($api): array
    {
        $porServicio = [];

        foreach ($this->leer($api, '/ppp/active/print') as $a) {
            $servicio = $a['service'] ?? '';

            if ($servicio === '' || $servicio === 'pppoe') {
                continue;
            }

            $porServicio[$servicio] = ($porServicio[$servicio] ?? 0) + 1;
        }

        $salida = [];

        foreach ($porServicio as $servicio => $n) {
            $salida[] = ['servicio' => $servicio, 'sesiones' => $n];
        }

        return $salida;
    }

    /** Cuántas credenciales están usando este perfil. */
    private function cuantosUsan(string $perfil): int
    {
        if ($perfil === '') {
            return 0;
        }

        $this->secretsCache ??= $this->leer($this->api(), '/ppp/secret/print');

        return count(array_filter(
            $this->secretsCache,
            fn ($s) => ($s['profile'] ?? '') === $perfil
        ));
    }

    /** @var array<int,array<string,mixed>>|null */
    private ?array $secretsCache = null;

    private function companyId(): int
    {
        return (int) getSessionCompanyId();
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

    /* ── Perfiles ─────────────────────────────────────────────────────────── */

    /**
     * Crea o edita un perfil.
     *
     * El perfil es lo que define la velocidad y de qué rango sale la IP, así
     * que es la pieza que más se toca: cada plan necesita el suyo.
     *
     * @param  array{nombre:string, nombre_anterior?:?string, velocidad?:?string,
     *               gateway?:?string, pool?:?string, dns?:?string, una_sesion?:bool}  $datos
     */
    public function guardarPerfil(array $datos): void
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));

        if ($nombre === '') {
            throw new \InvalidArgumentException('El perfil necesita un nombre.');
        }

        $api = $this->api();

        // Al renombrar hay que buscar por el nombre viejo, que es con el que
        // el perfil existe todavía en el router.
        $buscar = trim((string) ($datos['nombre_anterior'] ?? '')) ?: $nombre;
        $id     = $this->idDeAlgo($api, '/ppp/profile/print', 'name', $buscar);

        $q = new Query($id ? '/ppp/profile/set' : '/ppp/profile/add');

        if ($id) {
            $q->equal('.id', $id);
        }

        $q->equal('name', $nombre);

        foreach ([
            'local-address'  => $datos['gateway'] ?? null,
            'remote-address' => $datos['pool'] ?? null,
            'rate-limit'     => $datos['velocidad'] ?? null,
            'dns-server'     => $datos['dns'] ?? null,
        ] as $campo => $valor) {
            $valor = trim((string) $valor);

            // Vacío significa "sin definir": se manda igual para poder borrar
            // un valor que estaba puesto.
            $q->equal($campo, $valor);
        }

        $q->equal('only-one', !empty($datos['una_sesion']) ? 'yes' : 'default');

        $api->query($q)->read();

        Log::info('[PPPoE] Perfil guardado', ['perfil' => $nombre]);
    }

    /** @return array{ok:bool, motivo?:string} */
    public function eliminarPerfil(string $nombre): array
    {
        $api = $this->api();

        // Un perfil en uso no se puede borrar: los clientes que lo tienen
        // quedarían apuntando a algo que no existe.
        $enUso = $this->cuantosUsan($nombre);

        if ($enUso > 0) {
            return ['ok' => false, 'motivo' => "Lo están usando {$enUso} credencial(es). Cambialas de perfil primero."];
        }

        $id = $this->idDeAlgo($api, '/ppp/profile/print', 'name', $nombre);

        if (!$id) {
            return ['ok' => true];
        }

        $q = new Query('/ppp/profile/remove');
        $q->equal('.id', $id);
        $api->query($q)->read();

        return ['ok' => true];
    }

    /* ── Rangos de IP ─────────────────────────────────────────────────────── */

    /** @return array<int,array<string,mixed>> */
    public function pools(): array
    {
        $api = $this->api();

        // Qué perfiles reparten de cada rango: sirve para no borrar uno que
        // esté en uso sin darse cuenta.
        $perfiles = $this->leer($api, '/ppp/profile/print');

        return array_map(function ($p) use ($perfiles) {
            $nombre = $p['name'] ?? '';

            $usan = array_values(array_map(
                fn ($x) => $x['name'] ?? '',
                array_filter($perfiles, fn ($x) => ($x['remote-address'] ?? '') === $nombre)
            ));

            return [
                'nombre'    => $nombre,
                'rangos'    => $p['ranges'] ?? '',
                'siguiente' => $p['next-pool'] ?? null,
                'usado_por' => $usan,
            ];
        }, $this->leer($api, '/ip/pool/print'));
    }

    /** @param  array{nombre:string, nombre_anterior?:?string, rangos:string}  $datos */
    public function guardarPool(array $datos): void
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $rangos = trim((string) ($datos['rangos'] ?? ''));

        if ($nombre === '' || $rangos === '') {
            throw new \InvalidArgumentException('El rango necesita un nombre y las direcciones.');
        }

        $api = $this->api();
        $buscar = trim((string) ($datos['nombre_anterior'] ?? '')) ?: $nombre;
        $id = $this->idDeAlgo($api, '/ip/pool/print', 'name', $buscar);

        $q = new Query($id ? '/ip/pool/set' : '/ip/pool/add');

        if ($id) {
            $q->equal('.id', $id);
        }

        $q->equal('name', $nombre);
        $q->equal('ranges', $rangos);
        $api->query($q)->read();

        Log::info('[PPPoE] Rango guardado', ['pool' => $nombre, 'rangos' => $rangos]);
    }

    /** @return array{ok:bool, motivo?:string} */
    public function eliminarPool(string $nombre): array
    {
        $api = $this->api();

        $usan = array_filter(
            $this->leer($api, '/ppp/profile/print'),
            fn ($p) => ($p['remote-address'] ?? '') === $nombre
        );

        if ($usan) {
            $nombres = implode(', ', array_map(fn ($p) => $p['name'] ?? '', $usan));

            return ['ok' => false, 'motivo' => "Reparten de este rango: {$nombres}. Cambialos primero."];
        }

        $id = $this->idDeAlgo($api, '/ip/pool/print', 'name', $nombre);

        if (!$id) {
            return ['ok' => true];
        }

        $q = new Query('/ip/pool/remove');
        $q->equal('.id', $id);
        $api->query($q)->read();

        return ['ok' => true];
    }

    /* ── Servidores ───────────────────────────────────────────────────────── */

    /**
     * @param  array{servicio:string, interfaz:string, perfil:string,
     *               nombre_anterior?:?string, una_sesion?:bool}  $datos
     */
    public function guardarServidor(array $datos): void
    {
        $servicio = trim((string) ($datos['servicio'] ?? ''));
        $interfaz = trim((string) ($datos['interfaz'] ?? ''));

        if ($servicio === '' || $interfaz === '') {
            throw new \InvalidArgumentException('El servidor necesita un nombre de servicio y una interfaz.');
        }

        $api = $this->api();
        $buscar = trim((string) ($datos['nombre_anterior'] ?? '')) ?: $servicio;
        $id = $this->idDeAlgo($api, '/interface/pppoe-server/server/print', 'service-name', $buscar);

        $q = new Query($id ? '/interface/pppoe-server/server/set' : '/interface/pppoe-server/server/add');

        if ($id) {
            $q->equal('.id', $id);
        }

        $q->equal('service-name', $servicio);
        $q->equal('interface', $interfaz);
        $q->equal('default-profile', trim((string) ($datos['perfil'] ?? 'default')) ?: 'default');
        $q->equal('authentication', 'pap,chap');
        $q->equal('one-session-per-host', empty($datos['una_sesion']) ? 'no' : 'yes');
        $q->equal('disabled', 'no');
        $api->query($q)->read();

        Log::info('[PPPoE] Servidor guardado', ['servicio' => $servicio, 'interfaz' => $interfaz]);
    }

    public function eliminarServidor(string $servicio): void
    {
        $api = $this->api();
        $id  = $this->idDeAlgo($api, '/interface/pppoe-server/server/print', 'service-name', $servicio);

        if (!$id) {
            return;
        }

        $q = new Query('/interface/pppoe-server/server/remove');
        $q->equal('.id', $id);
        $api->query($q)->read();
    }

    /** Interfaces por donde puede escuchar un servidor. */
    public function interfaces(): array
    {
        return array_values(array_filter(array_map(
            fn ($i) => ['nombre' => $i['name'] ?? '', 'tipo' => $i['type'] ?? ''],
            $this->leer($this->api(), '/interface/print')
        ), fn ($i) => in_array($i['tipo'], ['ether', 'vlan', 'bridge'], true)));
    }

    private function idDeAlgo($api, string $comando, string $campo, string $valor): ?string
    {
        try {
            $q = new Query($comando);
            $q->where($campo, $valor);
            $q->add('=.proplist=.id');

            return $api->query($q)->read()[0]['.id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
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
