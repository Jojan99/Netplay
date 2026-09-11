<?php

namespace App\Services\Vpn;

use App\Models\VpnServidor;
use App\Models\VpnTunel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * El servidor WireGuard de la plataforma.
 *
 * PHP corre como www-data y no puede tocar interfaces de red, así que el
 * reparto es: la plataforma es la dueña del estado —qué túneles existen, con
 * qué claves y qué redes hay detrás de cada uno— lo escribe en un archivo
 * suyo, y un único ayudante con permiso de root lo aplica. Es el mismo
 * permiso mínimo que hace falta y nada más: el ayudante sólo sabe sincronizar,
 * consultar y bajar la interfaz, y valida lo que lee antes de usarlo.
 */
class ServidorVpn
{
    /** El ayudante con privilegios; se instala una vez (ver instalador()). */
    public const AYUDANTE = '/usr/local/bin/netplay-vpn';

    private const CARPETA = 'vpn';

    // ── Configuración del servidor ────────────────────────────────────────

    /**
     * La configuración, creándola la primera vez.
     *
     * La clave del servidor se genera acá y no se vuelve a mostrar: lo que los
     * routers necesitan es la pública.
     */
    public static function configuracion(): VpnServidor
    {
        $servidor = VpnServidor::query()->first();

        if ($servidor) {
            return $servidor;
        }

        $claves = ClavesWireguard::par();
        $subred = '10.200.200.0/24';

        return VpnServidor::create([
            'interfaz'      => 'wg-netplay',
            'endpoint_host' => self::ipPublica(),
            'listen_port'   => 51820,
            'subred'        => $subred,
            'ip_servidor'   => self::primeraIp($subred),
            'clave_privada' => $claves['privada'],
            'clave_publica' => $claves['publica'],
            'activo'        => true,
        ]);
    }

    /**
     * La IP con la que los routers van a marcar hacia acá.
     *
     * Se toma de la interfaz de salida; si el servidor estuviera detrás de NAT
     * habría que corregirla a mano, y para eso el campo es editable.
     */
    private static function ipPublica(): string
    {
        $salida = @shell_exec("ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1");
        $ip     = trim((string) $salida);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /** La .1 de la subred: es la del servidor. */
    private static function primeraIp(string $subred): string
    {
        [$red] = explode('/', $subred);
        $partes = explode('.', $red);
        $partes[3] = '1';

        return implode('.', $partes);
    }

    // ── Túneles ───────────────────────────────────────────────────────────

    /**
     * Da de alta un túnel y devuelve sus claves, que es la única vez que se
     * pueden ver en claro.
     *
     * @param  array<string,mixed>  $datos
     * @return array{tunel:VpnTunel, clave_privada:string, clave_compartida:string}
     */
    public static function crearTunel(array $datos): array
    {
        $servidor = self::configuracion();
        $redes    = self::normalizarRedes($datos['redes_remotas'] ?? []);

        // Una red del cliente que se solape con la del túnel rompe el
        // enrutado: el router mandaría por el túnel el tráfico que debería
        // quedarse en su LAN. Mejor rechazarlo que dejar un túnel que sube y
        // no sirve.
        foreach ($redes as $red) {
            if (self::seSolapan($red, $servidor->subred)) {
                throw new RuntimeException(
                    "La red {$red} se solapa con la del túnel ({$servidor->subred}). "
                    . 'Elegí otra red para el túnel en la configuración del servidor.'
                );
            }
        }

        $claves      = ClavesWireguard::par();
        $compartida  = ClavesWireguard::compartida();

        $tunel = VpnTunel::create([
            'company_id'       => $datos['company_id'] ?? getSessionCompanyId(),
            'nombre'           => $datos['nombre'],
            'router_id'        => $datos['router_id'] ?? null,
            'clave_privada'    => $claves['privada'],
            'clave_publica'    => $claves['publica'],
            'clave_compartida' => $compartida,
            'ip_tunel'         => self::siguienteIp($servidor),
            'redes_remotas'    => $redes,
            'puerto_router'    => (int) ($datos['puerto_router'] ?? 13231),
            'keepalive'        => (int) ($datos['keepalive'] ?? 25),
            'activo'           => true,
            'notas'            => $datos['notas'] ?? null,
        ]);

        self::aplicar();

        return [
            'tunel'            => $tunel,
            'clave_privada'    => $claves['privada'],
            'clave_compartida' => $compartida,
        ];
    }

    /**
     * El túnel que ya cubre esa IP, si existe.
     *
     * Sirve para no crear un túnel por OLT cuando varias están en el mismo
     * nodo: lo que se necesita es uno por router, no uno por equipo.
     */
    public static function tunelQueCubre(?string $ip, ?int $companyId = null): ?VpnTunel
    {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        $candidatos = VpnTunel::where('activo', true)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->get();

        foreach ($candidatos as $tunel) {
            foreach ($tunel->redes_remotas ?? [] as $red) {
                [$base, $bits] = explode('/', $red);
                $mascara = (int) $bits === 0 ? 0 : (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;

                if ((ip2long($ip) & $mascara) === (ip2long($base) & $mascara)) {
                    return $tunel;
                }
            }
        }

        return null;
    }

    /**
     * La red /24 a la que pertenece una IP.
     *
     * Es el valor por defecto cuando se crea el túnel junto con la OLT: casi
     * siempre la red de gestión es la /24 del equipo, y si no lo es se corrige
     * en la pantalla antes de generar el script.
     */
    public static function redDe(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        return long2ip(ip2long($ip) & 0xFFFFFF00) . '/24';
    }

    public static function eliminarTunel(VpnTunel $tunel): void
    {
        $tunel->delete();

        self::aplicar();
    }

    /** La primera IP libre de la subred, salteando la del servidor. */
    private static function siguienteIp(VpnServidor $servidor): string
    {
        [$red, $bits] = explode('/', $servidor->subred);

        $base      = ip2long($red);
        $cantidad  = 2 ** (32 - (int) $bits);
        $ocupadas  = VpnTunel::pluck('ip_tunel')->all();
        $ocupadas[] = $servidor->ip_servidor;

        // Se salta la de red, la del servidor y la de broadcast.
        for ($i = 2; $i < $cantidad - 1; $i++) {
            $candidata = long2ip($base + $i);

            if (!in_array($candidata, $ocupadas, true)) {
                return $candidata;
            }
        }

        throw new RuntimeException(
            "No quedan IP libres en {$servidor->subred}. Ampliá la subred del túnel."
        );
    }

    /**
     * Redes en notación CIDR, sin repetidas y sin basura.
     *
     * @param  array<int,string>|string  $redes
     * @return list<string>
     */
    public static function normalizarRedes(array|string $redes): array
    {
        if (is_string($redes)) {
            $redes = preg_split('/[\s,]+/', $redes) ?: [];
        }

        $limpias = [];

        foreach ($redes as $red) {
            $red = trim((string) $red);

            if ($red === '') {
                continue;
            }

            // Una IP suelta se toma como host: /32.
            if (!str_contains($red, '/')) {
                $red .= '/32';
            }

            [$ip, $bits] = explode('/', $red, 2);

            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new RuntimeException("«{$red}» no es una red válida.");
            }

            if (!ctype_digit($bits) || (int) $bits < 8 || (int) $bits > 32) {
                throw new RuntimeException("«{$red}» tiene una máscara inválida.");
            }

            $limpias[] = self::normalizarCidr($ip, (int) $bits);
        }

        return array_values(array_unique($limpias));
    }

    /** 10.11.104.7/24 → 10.11.104.0/24, que es lo que espera WireGuard. */
    private static function normalizarCidr(string $ip, int $bits): string
    {
        $mascara = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return long2ip(ip2long($ip) & $mascara) . '/' . $bits;
    }

    private static function seSolapan(string $a, string $b): bool
    {
        [$ipA, $bitsA] = explode('/', $a);
        [$ipB, $bitsB] = explode('/', $b);

        $bits    = min((int) $bitsA, (int) $bitsB);
        $mascara = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return (ip2long($ipA) & $mascara) === (ip2long($ipB) & $mascara);
    }

    // ── Aplicar en el sistema ─────────────────────────────────────────────

    /**
     * Escribe el estado deseado y le pide al ayudante que lo aplique.
     *
     * Si el ayudante no está instalado no es un error: la plataforma sigue
     * guardando túneles y entregando scripts, y avisa que falta el paso de
     * instalación. Así se puede preparar todo antes de tener el root.
     */
    public static function aplicar(): array
    {
        $servidor = self::configuracion();

        self::escribirEstado($servidor);

        if (!is_executable(self::AYUDANTE)) {
            return [
                'aplicado' => false,
                'motivo'   => 'El ayudante del sistema todavía no está instalado.',
            ];
        }

        $salida = self::ejecutarAyudante('sync');

        if (!$salida['ok']) {
            Log::error('[VPN] No se pudo sincronizar el servidor', $salida);

            return ['aplicado' => false, 'motivo' => $salida['salida']];
        }

        VpnTunel::whereNull('aplicado_en')->update(['aplicado_en' => now()]);

        return ['aplicado' => true, 'motivo' => null];
    }

    /**
     * El estado deseado, en dos archivos:
     *
     *   servidor.env   interfaz, dirección y puerto, que es lo que el ayudante
     *                  necesita para crear la interfaz
     *   <iface>.conf   la configuración que consume `wg syncconf`
     */
    private static function escribirEstado(VpnServidor $servidor): void
    {
        $disco = Storage::disk('local');

        $disco->put(self::CARPETA . '/servidor.env', implode("\n", [
            '# Generado por la plataforma. No editar a mano.',
            'IFACE=' . $servidor->interfaz,
            'DIRECCION=' . $servidor->ip_servidor . '/' . explode('/', $servidor->subred)[1],
            'PUERTO=' . $servidor->listen_port,
            '',
        ]));

        $disco->put(self::CARPETA . '/' . $servidor->interfaz . '.conf', self::configWireguard($servidor));

        // Las claves privadas viajan en estos archivos.
        foreach (['servidor.env', $servidor->interfaz . '.conf'] as $archivo) {
            // 0660 y no 0600: el grupo es www-data, que es quien reescribe el estado
            // desde la plataforma; con 0600 y otro dueño el guardado fallaba.
            @chmod($disco->path(self::CARPETA . '/' . $archivo), 0660);
        }
    }

    /** La configuración del lado servidor, lista para `wg syncconf`. */
    private static function configWireguard(VpnServidor $servidor): string
    {
        $lineas = [
            '[Interface]',
            'PrivateKey = ' . $servidor->clave_privada,
            'ListenPort = ' . $servidor->listen_port,
        ];

        foreach (VpnTunel::where('activo', true)->orderBy('id')->get() as $tunel) {
            // AllowedIPs del lado servidor: la IP del router dentro del túnel
            // más las redes que hay detrás. De acá salen también las rutas.
            $permitidas = array_merge([$tunel->ip_tunel . '/32'], $tunel->redes_remotas ?? []);

            $lineas[] = '';
            $lineas[] = '# ' . $tunel->nombre;
            $lineas[] = '[Peer]';
            $lineas[] = 'PublicKey = ' . $tunel->clave_publica;

            if ($tunel->clave_compartida) {
                $lineas[] = 'PresharedKey = ' . $tunel->clave_compartida;
            }

            $lineas[] = 'AllowedIPs = ' . implode(', ', $permitidas);
        }

        return implode("\n", $lineas) . "\n";
    }

    /**
     * @return array{ok:bool, salida:string}
     */
    private static function ejecutarAyudante(string $accion): array
    {
        $comando = sprintf('sudo -n %s %s 2>&1', escapeshellarg(self::AYUDANTE), escapeshellarg($accion));

        $salida = [];
        $codigo = 0;

        exec($comando, $salida, $codigo);

        return [
            'ok'     => $codigo === 0,
            'salida' => trim(implode("\n", $salida)),
        ];
    }

    // ── Estado real ───────────────────────────────────────────────────────

    /**
     * Lo que está pasando en el servidor: si la interfaz existe, y de cada
     * túnel cuándo saludó por última vez y cuánto transfirió.
     *
     * El listado se filtra por empresa. La configuración de WireGuard, en
     * cambio, se arma con los túneles de todas: es estado del sistema, y
     * filtrarla ahí dejaría sin servicio a los demás clientes.
     *
     * @return array<string,mixed>
     */
    public static function estado(?int $companyId = null): array
    {
        $servidor = self::configuracion();

        $base = [
            'servidor' => [
                'interfaz'      => $servidor->interfaz,
                'endpoint_host' => $servidor->endpoint_host,
                'listen_port'   => $servidor->listen_port,
                'subred'        => $servidor->subred,
                'ip_servidor'   => $servidor->ip_servidor,
                'clave_publica' => $servidor->clave_publica,
            ],
            'ayudante_instalado' => is_executable(self::AYUDANTE),
            'levantada'          => false,
            'error'              => null,
        ];

        if (!$base['ayudante_instalado']) {
            return $base + ['tuneles' => self::tunelesConEstado([], $companyId)];
        }

        $salida = self::ejecutarAyudante('status');

        if (!$salida['ok']) {
            return array_merge($base, [
                'error'   => $salida['salida'] ?: 'El ayudante no respondió.',
                'tuneles' => self::tunelesConEstado([], $companyId),
            ]);
        }

        $porClave = self::leerDump($salida['salida']);

        self::guardarEstado($porClave);

        return array_merge($base, [
            'levantada' => $porClave !== [],
            'tuneles'   => self::tunelesConEstado($porClave, $companyId),
        ]);
    }

    /**
     * `wg show <iface> dump` da una línea por par, separada por tabuladores:
     * clave pública, preshared, endpoint, allowed-ips, último saludo, rx, tx,
     * keepalive. La primera línea es la interfaz.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function leerDump(string $dump): array
    {
        $porClave = [];

        foreach (preg_split('/\r?\n/', trim($dump)) as $i => $linea) {
            if ($linea === '') {
                continue;
            }

            $campos = explode("\t", $linea);

            // La línea de la interfaz trae 4 campos; las de los pares, 8.
            if (count($campos) < 8) {
                continue;
            }

            $porClave[$campos[0]] = [
                'endpoint'      => $campos[2] !== '(none)' ? $campos[2] : null,
                'ultimo_saludo' => (int) $campos[4] > 0 ? (int) $campos[4] : null,
                'bytes_rx'      => (int) $campos[5],
                'bytes_tx'      => (int) $campos[6],
            ];
        }

        return $porClave;
    }

    /** @param array<string,array<string,mixed>> $porClave */
    private static function guardarEstado(array $porClave): void
    {
        foreach (VpnTunel::all() as $tunel) {
            $vivo = $porClave[$tunel->clave_publica] ?? null;

            if (!$vivo) {
                continue;
            }

            $tunel->forceFill([
                'ultimo_saludo' => $vivo['ultimo_saludo'] ? now()->setTimestamp($vivo['ultimo_saludo']) : null,
                'bytes_rx'      => $vivo['bytes_rx'],
                'bytes_tx'      => $vivo['bytes_tx'],
            ])->saveQuietly();
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $porClave
     * @return list<array<string,mixed>>
     */
    private static function tunelesConEstado(array $porClave, ?int $companyId = null): array
    {
        return VpnTunel::when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('nombre')->get()->map(function (VpnTunel $tunel) use ($porClave) {
            $vivo = $porClave[$tunel->clave_publica] ?? null;

            return [
                'id'            => $tunel->id,
                'nombre'        => $tunel->nombre,
                'router_id'     => $tunel->router_id,
                'ip_tunel'      => $tunel->ip_tunel,
                'redes_remotas' => $tunel->redes_remotas ?? [],
                'clave_publica' => $tunel->clave_publica,
                'puerto_router' => $tunel->puerto_router,
                'activo'        => $tunel->activo,
                'en_servidor'   => $vivo !== null,
                'endpoint'      => $vivo['endpoint'] ?? null,
                'ultimo_saludo' => $tunel->ultimo_saludo?->toIso8601String(),
                'conectado'     => $tunel->conectado,
                'bytes_rx'      => $tunel->bytes_rx,
                'bytes_tx'      => $tunel->bytes_tx,
                'notas'         => $tunel->notas,
            ];
        })->all();
    }
}
