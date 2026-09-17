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

        $companyId = (int) ($datos['company_id'] ?? getSessionCompanyId());

        [$redes, $traducciones] = self::resolverChoques($redes, $companyId);

        self::verificarRedesLibres($redes);

        $claves      = ClavesWireguard::par();
        $compartida  = ClavesWireguard::compartida();

        $tunel = VpnTunel::create(($traducciones ? ['traducciones' => $traducciones] : []) + [
            'company_id'       => $companyId,
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
            // Con empresa conocida también cuenta la red real de una traducida:
            // la OLT se sigue registrando con su IP de la LAN.
            $reales = $companyId !== null ? array_column($tunel->traducciones ?? [], 'real') : [];

            foreach (array_merge($tunel->redes_remotas ?? [], $reales) as $red) {
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

    /**
     * Ninguna de estas redes puede estar ya en otro túnel.
     *
     * WireGuard asigna cada red a un único par: si dos túneles declaran la
     * misma, el tráfico va al último que se cargó y el otro queda sin servicio
     * sin ningún aviso. Se revisa contra los túneles de todas las empresas,
     * porque la interfaz es una sola.
     *
     * @param  list<string>  $redes
     */
    public static function verificarRedesLibres(array $redes, ?int $salvoTunel = null): void
    {
        $otros = VpnTunel::where('activo', true)
            ->when($salvoTunel !== null, fn ($q) => $q->where('id', '!=', $salvoTunel))
            ->get();

        foreach ($redes as $red) {
            foreach ($otros as $existente) {
                foreach ($existente->redes_remotas ?? [] as $ocupada) {
                    if (self::seSolapan($red, $ocupada)) {
                        throw new RuntimeException(
                            "La red {$red} ya la usa otro túnel ({$ocupada}). "
                            . 'Dos túneles no pueden llegar a la misma red: el tráfico iría sólo a uno. '
                            . 'Si las dos OLT están detrás del mismo router, agregá la red a ese túnel '
                            . 'en vez de crear otro.'
                        );
                    }
                }
            }
        }
    }

    /**
     * La IP por la que la plataforma llega a un equipo de esta empresa.
     *
     * - Si su red está traducida en un túnel de la empresa: la virtual.
     * - Si su red va por un túnel de la empresa: la misma.
     * - Si su red la lleva el túnel de OTRA empresa: null. Conectarse ahí
     *   llegaría a un equipo ajeno (con credenciales de esta empresa).
     * - Si no la lleva ningún túnel (IP pública): la misma.
     */
    public static function ipParaEmpresa(?string $ip, int $companyId, bool $soloPorTunel = false): ?string
    {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        $propios = VpnTunel::where('company_id', $companyId)->where('activo', true)->get();

        foreach ($propios as $tunel) {
            if (($virtual = $tunel->ipAlcanzable($ip)) !== $ip) {
                return $virtual;
            }
        }

        $cubre = fn (VpnTunel $t) => collect($t->redes_remotas ?? [])->contains(fn ($red) => self::seSolapan($ip . '/32', $red));

        if ($propios->contains($cubre)) {
            return $ip;
        }

        // Fuera de todo túnel: sólo si se aceptan destinos por internet (a la
        // página de un equipo, con su clave, nunca: iría en claro).
        if ($soloPorTunel) {
            return null;
        }

        $ajeno = VpnTunel::where('company_id', '!=', $companyId)->where('activo', true)->get()->contains($cubre);

        return $ajeno ? null : $ip;
    }

    /** De dónde salen las redes virtuales de las redes que chocan entre empresas. */
    public const POOL_TRADUCIDO = '10.250.0.0/16';

    /**
     * Cada empresa tiene su túnel aunque use las mismas redes privadas que otra.
     *
     * El servidor es uno solo y enruta por red, así que dos empresas con
     * 192.168.5.0/24 no pueden publicarla igual. Cuando una red choca con la
     * de un túnel de OTRA empresa, se publica con una red virtual libre del
     * mismo tamaño y el router la traduce a la real (NETMAP en el script). Un
     * choque con un túnel de la MISMA empresa no se traduce: es el mismo router
     * cargado dos veces y verificarRedesLibres() lo explica.
     *
     * @param  list<string>  $redes         reales, o virtuales que ya tenía el túnel
     * @param  list<array{real:string,virtual:string}>  $previas  las del túnel que se edita
     * @return array{0:list<string>, 1:list<array{real:string,virtual:string}>}  redes a publicar y traducciones
     */
    public static function resolverChoques(array $redes, int $companyId, ?int $salvoTunel = null, array $previas = []): array
    {
        $otros = VpnTunel::where('activo', true)
            ->when($salvoTunel !== null, fn ($q) => $q->where('id', '!=', $salvoTunel))
            ->get();

        $ajenas = $otros->where('company_id', '!=', $companyId)
            ->flatMap(fn ($t) => $t->redes_remotas ?? [])->values()->all();

        // Lo que ya está publicado en la VPN no puede ser una virtual nueva.
        $ocupadas = array_merge(
            $otros->flatMap(fn ($t) => $t->redes_remotas ?? [])->values()->all(),
            [self::configuracion()->subred],
        );

        $publicar     = [];
        $traducciones = [];
        $subredVpn    = self::configuracion()->subred;

        self::validarRedesDeTunel($redes, array_column($previas, 'virtual'));

        foreach ($redes as $red) {
            // La red interna de la VPN no es una red "detrás" de ningún router:
            // se colaba al leer las direcciones del MikroTik (su wg tiene una).
            if (self::seSolapan($red, $subredVpn)) {
                continue;
            }

            // Una virtual que el túnel ya tenía se queda como está.
            $yaVirtual = collect($previas)->firstWhere('virtual', $red);

            if ($yaVirtual) {
                $publicar[]     = $red;
                $traducciones[] = $yaVirtual;
                continue;
            }

            $choca = collect($ajenas)->contains(fn ($ajena) => self::seSolapan($red, $ajena));

            if (!$choca) {
                $publicar[] = $red;
                continue;
            }

            $virtual = collect($previas)->firstWhere('real', $red)['virtual']
                ?? self::redVirtualLibre($red, array_merge($ocupadas, $publicar, $redes));

            $publicar[]     = $virtual;
            $ocupadas[]     = $virtual;
            $traducciones[] = ['real' => $red, 'virtual' => $virtual];
        }

        return [array_values(array_unique($publicar)), $traducciones];
    }

    /**
     * Qué redes puede llevar un túnel.
     *
     * El servidor pone una ruta por cada red: una red pública (el /24 donde
     * está el router de otra empresa, o el endpoint de un par) desviaría ese
     * tráfico por el túnel de quien la cargó. Y una red local del servidor lo
     * dejaría sin servicio para todos. Sólo privadas, fuera de las virtuales
     * que reparte la plataforma y sin pisar las redes del propio servidor.
     *
     * @param  list<string>  $redes
     * @param  list<string>  $virtualesPropias  las que el túnel ya tenía traducidas
     */
    public static function validarRedesDeTunel(array $redes, array $virtualesPropias = []): void
    {
        $privadas = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
        $locales  = self::redesLocalesDelServidor();

        foreach ($redes as $red) {
            if (in_array($red, $virtualesPropias, true)) {
                continue;
            }

            if (!collect($privadas)->contains(fn ($p) => self::dentroDe($red, $p))) {
                throw new RuntimeException("La red {$red} no es privada: un túnel sólo puede llevar redes 10.x, 172.16-31.x o 192.168.x.");
            }

            if (self::seSolapan($red, self::POOL_TRADUCIDO)) {
                throw new RuntimeException("La red {$red} está dentro de " . self::POOL_TRADUCIDO . ', reservada para las redes traducidas de la plataforma.');
            }

            if ($choca = collect($locales)->first(fn ($l) => self::seSolapan($red, $l))) {
                throw new RuntimeException("La red {$red} choca con una red del propio servidor ({$choca}).");
            }
        }
    }

    /** ¿$red está entera dentro de $contenedora? */
    private static function dentroDe(string $red, string $contenedora): bool
    {
        [, $bits]  = explode('/', $red);
        [, $bitsC] = explode('/', $contenedora);

        return (int) $bits >= (int) $bitsC && self::seSolapan($red, $contenedora);
    }

    /** Las redes que el servidor tiene por sus propias interfaces (no por la VPN). @return list<string> */
    private static function redesLocalesDelServidor(): array
    {
        $salida = (string) @shell_exec('ip -4 route show 2>/dev/null');
        $interfaz = self::configuracion()->interfaz;
        $redes = [];

        foreach (preg_split('/\r?\n/', $salida) as $linea) {
            if (str_contains($linea, "dev {$interfaz}") || !preg_match('#^(\d+\.\d+\.\d+\.\d+/\d+)\s#', $linea, $m)) {
                continue;
            }

            $redes[] = $m[1];
        }

        return $redes;
    }

    /** Una red del tamaño de $red dentro de POOL_TRADUCIDO que no pise ninguna ocupada. */
    private static function redVirtualLibre(string $red, array $ocupadas): string
    {
        [, $bits]           = explode('/', $red);
        [$pool, $bitsPool]  = explode('/', self::POOL_TRADUCIDO);
        $bits               = (int) $bits;

        if ($bits < (int) $bitsPool) {
            throw new RuntimeException(
                "La red {$red} ya la usa otra empresa y es demasiado grande para publicarla con otra dirección "
                . '(máximo /' . $bitsPool . '). Dividila en redes más chicas.'
            );
        }

        $inicio = ip2long($pool);
        $fin    = $inicio + 2 ** (32 - (int) $bitsPool);
        $paso   = 2 ** (32 - $bits);

        for ($base = $inicio; $base + $paso <= $fin; $base += $paso) {
            $candidata = long2ip($base) . '/' . $bits;

            if (!collect($ocupadas)->contains(fn ($o) => self::seSolapan($candidata, $o))) {
                return $candidata;
            }
        }

        throw new RuntimeException('No quedan redes libres en ' . self::POOL_TRADUCIDO . ' para publicar ' . $red . '.');
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

        $yaAsignadas = [];

        foreach (VpnTunel::where('activo', true)->orderBy('id')->get() as $tunel) {
            // AllowedIPs del lado servidor: la IP del router dentro del túnel
            // más las redes que hay detrás. De acá salen también las rutas.
            $permitidas = array_merge([$tunel->ip_tunel . '/32'], $tunel->redes_remotas ?? []);

            // Seguro: WireGuard le quita una red al par que la tenía si otro par
            // la declara. Pasó con 10.30.0.0/22: la gestión TR-069 de una empresa
            // se llevó la de otra. La red se queda con el túnel más antiguo.
            $permitidas = array_values(array_filter($permitidas, function ($red) use (&$yaAsignadas, $tunel) {
                foreach ($yaAsignadas as $otra) {
                    if (self::seSolapan($red, $otra)) {
                        Log::error('[VPN] Red repetida entre túneles: no se carga en el más nuevo', [
                            'red' => $red, 'ya_esta' => $otra, 'tunel' => $tunel->id,
                        ]);

                        return false;
                    }
                }

                $yaAsignadas[] = $red;

                return true;
            }));

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
                'traducciones'  => $tunel->traducciones ?? [],
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
