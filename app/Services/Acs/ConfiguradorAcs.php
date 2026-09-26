<?php

namespace App\Services\Acs;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\AcsServidor;
use App\Models\ConectionRouter;
use App\Models\VpnTunel;
use App\Services\Vpn\ClavesWireguard;
use App\Services\Vpn\ScriptMikrotik;
use App\Services\Vpn\ServidorVpn;
use Illuminate\Support\Facades\Log;

/**
 * Deja el TR-069 andando sin que el operador escriba redes ni arme túneles.
 *
 * Tres decisiones y nada más: si usa el servidor de la plataforma o el suyo,
 * dónde está ese servidor, y qué redes suyas hay que alcanzar —que además
 * vienen ya detectadas de su propio router—. Con eso la plataforma arma el
 * túnel, aplica la configuración de su lado y entrega el script del router
 * listo para pegar.
 */
class ConfiguradorAcs
{
    public function __construct(
        private int $companyId,
        private ConectionRouterManagerInterface $conexion,
    ) {}

    /** @return array<string,mixed> */
    public function estado(): array
    {
        // El saludo del túnel se lee de WireGuard, no de la base: sin esto la
        // pantalla decía "todavía no saluda" con el túnel andando.
        try {
            ServidorVpn::estado($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[ACS] No se pudo refrescar el estado del túnel', ['error' => $e->getMessage()]);
        }

        $s = $this->servidor();
        $tunel = $s->vpn_tunel_id ? VpnTunel::find($s->vpn_tunel_id) : $this->tunelDeLaEmpresa();

        return [
            'configurado'   => (bool) $s->aplicado_en,
            'modo'          => $s->modo,
            'host'          => $s->host,
            'puerto_cwmp'   => $s->puerto_cwmp,
            'url_nbi'       => $s->url_nbi,
            // La que se usa de verdad (si url_nbi está vacía, http://host:7557).
            'url_nbi_efectiva' => $s->esPropio() ? $s->urlNbi() : null,
            // Para el firewall del servidor propio: sólo esta IP debe poder usar su API.
            'ip_plataforma' => self::ipDeLaPlataforma(),
            'alcance'       => $s->alcance,
            'url_para_onts' => $s->urlCwmp(),
            'redes'         => $s->redes ?? [],
            'detectado_en'  => $s->detectado_en,
            'aplicado_en'   => $s->aplicado_en,
            'tunel'         => $tunel ? [
                'id'        => $tunel->id,
                'nombre'    => $tunel->nombre,
                'redes'     => $tunel->redes_remotas ?? [],
                'conectado' => $tunel->ultimo_saludo && $tunel->ultimo_saludo->gt(now()->subMinutes(5)),
                'ultimo_saludo' => $tunel->ultimo_saludo,
            ] : null,
            'pendientes'    => $this->pendientes($s, $tunel),
            // Una empresa puede tener varios MikroTik: cada uno lleva su
            // propio túnel con sus redes, y se configuran de a uno.
            'routers'       => $this->routers(),
        ];
    }

    /**
     * Los MikroTik de la empresa con el estado de su túnel.
     *
     * @return list<array<string,mixed>>
     */
    private function routers(): array
    {
        $tuneles = VpnTunel::where('company_id', $this->companyId)->get();
        $redes   = collect($this->servidor()->redes ?? []);

        return ConectionRouter::where('company_id', $this->companyId)->orderBy('id')->get()->map(function ($r) use ($tuneles, $redes) {
            $tunel = $tuneles->firstWhere('router_id', $r->id) ?? ($tuneles->count() === 1 ? $tuneles->first() : null);

            return [
                'id'        => (int) $r->id,
                'nombre'    => $r->name ?: $r->host,
                'host'      => $r->host,
                'detectado' => $redes->where('router_id', (int) $r->id)->isNotEmpty(),
                'tunel'     => $tunel ? [
                    'id'            => $tunel->id,
                    'nombre'        => $tunel->nombre,
                    'redes'         => $tunel->redes_remotas ?? [],
                    'conectado'     => $tunel->ultimo_saludo && $tunel->ultimo_saludo->gt(now()->subMinutes(5)),
                    'ultimo_saludo' => $tunel->ultimo_saludo,
                ] : null,
            ];
        })->all();
    }

    /** Guarda dónde está el servidor TR-069 de la empresa. */
    public function guardar(array $datos): array
    {
        $s = $this->servidor();

        $modo = in_array($datos['modo'] ?? '', ['plataforma', 'propio'], true) ? $datos['modo'] : 'plataforma';

        if ($modo === 'propio' && empty($datos['host'])) {
            throw new \InvalidArgumentException('Falta la dirección del servidor TR-069.');
        }

        // Lo que más se equivoca al cargarlo a mano: la dirección con http:// o
        // con puerto (quedaba "http://http://…:7547") y la API apuntando al
        // puerto de los equipos, que responde 405 porque no es la API.
        [$host, $puertoDelHost] = $modo === 'propio' ? self::limpiarHost((string) $datos['host']) : [null, null];
        $puerto = (int) ($datos['puerto_cwmp'] ?? 0) ?: ($puertoDelHost ?: 7547);
        $nbi = $modo === 'propio' ? self::limpiarUrlApi((string) ($datos['url_nbi'] ?? '')) : null;

        if ($nbi && (int) (parse_url($nbi, PHP_URL_PORT) ?: 0) === $puerto) {
            throw new \InvalidArgumentException(
                "La dirección de la API usa el puerto de los equipos ({$puerto}). En GenieACS la API (NBI) va en el 7557: "
                . 'http://' . parse_url($nbi, PHP_URL_HOST) . ':7557. Si la deja vacía se usa esa.'
            );
        }

        $s->fill([
            'modo'        => $modo,
            'host'        => $host,
            'puerto_cwmp' => $puerto,
            'url_nbi'     => $nbi,
            'alcance'     => in_array($datos['alcance'] ?? '', ['publica', 'tunel'], true) ? $datos['alcance'] : 'tunel',
            'router_id'   => $datos['router_id'] ?? $s->router_id,
        ])->save();

        return $this->estado();
    }

    /**
     * "http://181.48.150.43:7547/" → ["181.48.150.43", 7547]. Sólo queda la IP
     * o el dominio; el puerto, si venía, se usa como puerto de los equipos.
     *
     * @return array{0:string, 1:?int}
     */
    public static function limpiarHost(string $valor): array
    {
        $v = trim($valor);
        $v = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $v);
        $v = preg_replace('#[/?\#].*$#', '', $v);
        $puerto = null;

        if (preg_match('/^(.+):(\d{1,5})$/', $v, $m)) {
            [$v, $puerto] = [$m[1], (int) $m[2]];
        }

        return [mb_strtolower($v), $puerto];
    }

    /** "181.48.150.43:7557/" → "http://181.48.150.43:7557"; vacío queda null. */
    public static function limpiarUrlApi(string $valor): ?string
    {
        $v = rtrim(trim($valor), '/');

        if ($v === '') {
            return null;
        }

        return preg_match('#^https?://#i', $v) ? $v : 'http://' . $v;
    }

    /** La IP con la que la plataforma llega a los servidores de las empresas (para su firewall). */
    public static function ipDeLaPlataforma(): ?string
    {
        $ips = array_filter(array_map('trim', explode(',', (string) config('services.servidor.ips', ''))));

        return $ips ? (string) reset($ips) : null;
    }

    /** Lo que falló al hablar con la API, en palabras de operador y con qué revisar. */
    private function explicarErrorDelServidor(\Throwable $e, AcsServidor $s): string
    {
        $mensaje = $e->getMessage();
        $api = $s->urlNbi();
        $ip = self::ipDeLaPlataforma();

        if (preg_match('/respondió (404|405)/', $mensaje)) {
            return "{$api} contesta, pero no es la API de GenieACS. La API (NBI) va en el puerto 7557, no en el de los equipos: "
                . 'http://' . $s->host . ':7557.';
        }

        if (preg_match('/cURL error (7|28)|Connection refused|timed out|Could not connect/i', $mensaje)) {
            return "No se llega a {$api}. En su servidor la API de GenieACS tiene que escuchar hacia afuera "
                . '(GENIEACS_NBI_INTERFACE=0.0.0.0 y reiniciar genieacs-nbi) y el firewall dejar entrar al 7557'
                . ($ip ? " sólo desde {$ip}" : '') . '.';
        }

        return $mensaje;
    }

    /** Lee del router qué redes hay, sin pedirle nada al operador. */
    public function detectar(?int $routerId = null): array
    {
        $r = (new RedesDelOperador($this->conexion, $this->companyId))->detectar($routerId);

        $s = $this->servidor();
        $guardadas = collect($s->redes ?? []);
        $deEsteRouter = $guardadas->where('router_id', $r['router_id'])->pluck('red')->all();

        // Lo ya elegido se respeta; lo nuevo entra marcado.
        $redes = array_map(fn ($red) => $red + [
            'elegida' => $deEsteRouter === [] ? (bool) $red['sugerida'] : in_array($red['red'], $deEsteRouter, true),
        ], $r['redes']);

        // Lo de los otros MikroTik no se toca: cada uno se configura aparte.
        $otros = $guardadas->where('router_id', '!=', $r['router_id'])->values()->all();

        $s->fill([
            'redes'        => array_merge($otros, $redes),
            'detectado_en' => now(),
            'router_id'    => $r['router_id'] ?: $s->router_id,
        ])->save();

        return ['redes' => $redes, 'router' => $r['router'], 'router_id' => $r['router_id'], 'error' => $r['error']];
    }

    /**
     * Aplica lo elegido para un MikroTik: arma o actualiza su túnel con esas
     * redes y deja el servidor configurado. Lo único que queda es pegar el
     * script en ese router.
     *
     * Cada router lleva su propio túnel. Dos routers no pueden traer la misma
     * red: el servidor no sabría por cuál de los dos mandar el tráfico, y por
     * eso se avisa en vez de dejar un túnel que sube y no sirve.
     *
     * @param  list<string>  $redes  las redes elegidas, en CIDR
     */
    public function aplicar(array $redes, ?int $routerId = null): array
    {
        if (!$redes) {
            throw new \InvalidArgumentException('Seleccione al menos una red para alcanzar.');
        }

        $s = $this->servidor();

        // Un servidor propio con IP pública no necesita túnel: los equipos
        // llegan solos por internet.
        if ($s->modo === 'propio' && $s->alcance === 'publica') {
            $s->fill(['aplicado_en' => now()])->save();

            return $this->estado();
        }

        $router = ConectionRouter::where('company_id', $this->companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->orderBy('id')->first();

        if (!$router) {
            throw new \InvalidArgumentException('No encontramos ese MikroTik.');
        }

        $tunel = $this->tunelDelRouter($router->id);

        if ($tunel) {
            [$publicar, $traducciones] = ServidorVpn::resolverChoques(
                ServidorVpn::normalizarRedes($redes), (int) $tunel->company_id, $tunel->id, $tunel->traducciones ?? []
            );
            ServidorVpn::verificarRedesLibres($publicar, $tunel->id);
            $tunel->fill(['redes_remotas' => $publicar] + ($traducciones || $tunel->traducciones ? ['traducciones' => $traducciones ?: null] : []))->save();
            ServidorVpn::aplicar();
        } else {
            $creado = ServidorVpn::crearTunel([
                'nombre'        => 'Gestión ' . ($router->name ?: $router->host),
                'redes_remotas' => $redes,
                'router_id'     => $router->id,
                'notas'         => 'Creado por el asistente de TR-069.',
            ]);

            $tunel = $creado['tunel'];
        }

        $s->fill([
            'vpn_tunel_id' => $s->vpn_tunel_id ?: $tunel->id,
            'redes'        => $this->marcarElegidas($s->redes ?? [], $redes, (int) $router->id),
            'aplicado_en'  => now(),
        ])->save();

        return $this->estado() + ['tunel_aplicado' => $tunel->id];
    }

    /**
     * El script para un router y, si el servidor es de la empresa, también la
     * configuración de su lado.
     *
     * @return array{router: ?string, servidor: ?string, notas: list<string>}
     */
    public function script(?int $routerId = null): array
    {
        $s = $this->servidor();
        $notas = [];

        if ($s->modo === 'propio' && $s->alcance === 'publica') {
            return [
                'router'   => null,
                'servidor' => null,
                'notas'    => [
                    'Su servidor TR-069 tiene IP pública, así que los equipos llegan solos: no hay que tocar el router.',
                    'Configurá en los equipos la URL ' . $s->urlCwmp() . '.',
                    'Para que los cambios se apliquen al momento, su servidor tiene que poder llamar a los equipos. '
                        . 'Si están detrás del router con IP privada, seleccione "por túnel" en vez de "IP pública".',
                ],
            ];
        }

        $tunel = $routerId ? $this->tunelDelRouter($routerId) : $this->tunelDeLaEmpresa();

        if (!$tunel) {
            return [
                'router'   => null,
                'servidor' => null,
                'notas'    => ['Todavía no hay túnel para este MikroTik: aplique la configuración primero.'],
            ];
        }

        $script = ScriptMikrotik::para(
            $tunel,
            ServidorVpn::configuracion(),
            (string) $tunel->clave_privada,
            (string) $tunel->clave_compartida,
        );

        if ($s->modo === 'propio') {
            $notas[] = 'Su servidor TR-069 está en otra red, así que además del router hay que levantar '
                . 'el otro extremo del túnel en ese servidor con la configuración de abajo.';
        }

        $notas[] = 'Este script es de este MikroTik. Si tiene otro, configuralo aparte: cada uno lleva su propio túnel.';

        return [
            'router'   => $script,
            'servidor' => $s->modo === 'propio' ? $this->configDelServidorPropio($tunel) : null,
            'notas'    => $notas,
        ];
    }

    /**
     * ¿Está funcionando el TR-069? Una revisión de punta a punta, en cuatro
     * preguntas que se responden solas.
     *
     * @return array{nivel:string, resumen:string, checks:list<array<string,mixed>>}
     */
    public function diagnostico(): array
    {
        $s = $this->servidor();
        $checks = [];

        // 1. El servidor contesta.
        $servidorOk = false;

        try {
            GenieAcs::deEmpresa($this->companyId)->dispositivos([], ['_id']);
            $servidorOk = true;
        } catch (\Throwable $e) {
            $detalleServidor = $this->explicarErrorDelServidor($e, $s);
        }

        $checks[] = [
            'clave'   => 'servidor',
            'titulo'  => 'El servidor TR-069 responde',
            'ok'      => $servidorOk,
            'detalle' => $servidorOk
                ? ($s->esPropio() ? 'Su servidor en ' . $s->host : 'El servidor de la plataforma')
                : ('No contesta: ' . ($detalleServidor ?? 'sin detalle')),
        ];

        // 2. El camino hasta los equipos.
        $tunel = $s->vpn_tunel_id ? VpnTunel::find($s->vpn_tunel_id) : $this->tunelDeLaEmpresa();
        $porTunel = !($s->esPropio() && $s->alcance === 'publica');
        $tunelOk = !$porTunel || ($tunel && $tunel->ultimo_saludo && $tunel->ultimo_saludo->gt(now()->subMinutes(5)));

        $checks[] = [
            'clave'   => 'camino',
            'titulo'  => $porTunel ? 'El túnel con su router está arriba' : 'Su servidor tiene IP pública',
            'ok'      => (bool) $tunelOk,
            'detalle' => !$porTunel
                ? 'Los equipos llegan por internet.'
                : ($tunelOk
                    ? 'Último saludo ' . ($tunel->ultimo_saludo?->diffForHumans() ?? '')
                    : 'El router no saluda. Pegue el script del paso 3 en el router.'),
        ];

        // 3. Los equipos reportan.
        $equipos = [];

        try {
            $equipos = (new EquiposDelAcs($this->companyId))->lista();
        } catch (\Throwable) {
        }

        $reportando = collect($equipos)->where('reportando', true)->count();
        $ultimo = collect($equipos)->max('ultimo_reporte');

        $checks[] = [
            'clave'   => 'equipos',
            'titulo'  => 'Sus equipos reportan',
            'ok'      => $reportando > 0,
            'detalle' => $equipos === []
                ? 'Ningún equipo configurado todavía: cargales la dirección ' . $s->urlCwmp() . '.'
                : ($reportando > 0
                    ? "{$reportando} de " . count($equipos) . ' reportando'
                    : 'Ninguno reporta. El último lo hizo ' . ($ultimo ? \Carbon\Carbon::parse($ultimo)->diffForHumans() : 'nunca') . '.'),
        ];

        // 4. Los cambios se aplican al momento: hace falta llegar al equipo.
        $alInstante = $this->alcanzaUnEquipo($equipos);

        $checks[] = [
            'clave'   => 'inmediato',
            'titulo'  => 'Los cambios se aplican al momento',
            'ok'      => $alInstante['ok'],
            'detalle' => $alInstante['detalle'],
        ];

        $fallan = collect($checks)->where('ok', false);

        return [
            'nivel'   => $fallan->isEmpty() ? 'ok' : ($fallan->contains(fn ($c) => in_array($c['clave'], ['servidor', 'camino'], true)) ? 'error' : 'warn'),
            'resumen' => $fallan->isEmpty()
                ? 'TR-069 funcionando'
                : ($fallan->first()['titulo'] . ': revise abajo'),
            'checks'  => $checks,
            'equipos' => count($equipos),
            'reportando' => $reportando,
        ];
    }

    /**
     * ¿El servidor alcanza a algún equipo? Es lo que hace que un cambio se
     * aplique en segundos en vez de esperar el próximo reporte.
     *
     * @param  list<array<string,mixed>>  $equipos
     * @return array{ok:bool, detalle:string}
     */
    private function alcanzaUnEquipo(array $equipos): array
    {
        $enLinea = collect($equipos)->where('reportando', true)->first();

        if (!$enLinea) {
            return ['ok' => false, 'detalle' => 'Hace falta al menos un equipo reportando para poder probarlo.'];
        }

        $detalle = (new EquiposDelAcs($this->companyId))->detalle($enLinea['id']);
        $url = $detalle['url_conexion'] ?? null;

        if (!$url || !preg_match('#^https?://([^:/]+):?(\d+)?#', (string) $url, $m)) {
            return ['ok' => false, 'detalle' => 'El equipo todavía no informó por dónde se le puede llamar.'];
        }

        $puerto = (int) ($m[2] ?: 80);
        $socket = @fsockopen($m[1], $puerto, $errno, $error, 3);

        if ($socket) {
            fclose($socket);

            return ['ok' => true, 'detalle' => 'Probado contra ' . ($enLinea['modelo'] ?? 'un equipo') . ' en ' . $m[1] . '.'];
        }

        return [
            'ok'      => false,
            'detalle' => 'No se llega a ' . $m[1] . ': los cambios van a quedar en cola hasta el próximo reporte del equipo. '
                . 'Revise que la red de ese equipo esté marcada en el paso 2.',
        ];
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function servidor(): AcsServidor
    {
        return AcsServidor::firstOrCreate(['company_id' => $this->companyId], ['modo' => 'plataforma']);
    }

    private function tunelDeLaEmpresa(): ?VpnTunel
    {
        return VpnTunel::where('company_id', $this->companyId)->orderBy('id')->first();
    }

    /**
     * El túnel de ese router. Si hay uno solo en la empresa y todavía no dice
     * a qué router pertenece, se le asigna: viene de antes del asistente.
     */
    private function tunelDelRouter(int $routerId): ?VpnTunel
    {
        $propio = VpnTunel::where('company_id', $this->companyId)->where('router_id', $routerId)->first();

        if ($propio) {
            return $propio;
        }

        $tuneles = VpnTunel::where('company_id', $this->companyId)->get();

        if ($tuneles->count() === 1 && !$tuneles->first()->router_id) {
            $tuneles->first()->fill(['router_id' => $routerId])->save();

            return $tuneles->first();
        }

        return null;
    }

    /**
     * Qué falta para que funcione, dicho en una línea cada cosa.
     *
     * @return list<string>
     */
    private function pendientes(AcsServidor $s, ?VpnTunel $tunel): array
    {
        $faltan = [];

        if ($s->modo === 'propio' && !$s->host) {
            $faltan[] = 'Decinos dónde está su servidor TR-069.';
        }

        if (!$s->redes) {
            $faltan[] = 'Detecte las redes de su router: es un botón.';
        }

        if (!$s->aplicado_en) {
            $faltan[] = 'Aplique la configuración para armar el camino hasta los equipos.';
        }

        if ($s->alcance === 'tunel' && $tunel && !($tunel->ultimo_saludo && $tunel->ultimo_saludo->gt(now()->subMinutes(5)))) {
            $faltan[] = 'El router todavía no saluda por el túnel: pegue el script en el router.';
        }

        return $faltan;
    }

    /**
     * Marca lo elegido sólo entre las redes de ese router: las de los otros
     * MikroTik quedan como estaban.
     *
     * @param list<array<string,mixed>> $redes
     */
    private function marcarElegidas(array $redes, array $elegidas, int $routerId): array
    {
        return array_map(
            fn ($r) => (int) ($r['router_id'] ?? 0) === $routerId
                ? ['elegida' => in_array($r['red'], $elegidas, true)] + $r
                : $r,
            $redes
        );
    }

    /**
     * El otro extremo del túnel, para el servidor TR-069 de la empresa.
     *
     * Se le entrega hecho: pega el archivo y levanta la interfaz. Las redes
     * que quedan del otro lado son las mismas que eligió.
     */
    private function configDelServidorPropio(VpnTunel $tunel): string
    {
        $servidor = ServidorVpn::configuracion();
        $par      = ClavesWireguard::par();
        $redes    = implode(', ', $tunel->redes_remotas ?? []);

        return implode("\n", [
            '# /etc/wireguard/wg-netplay.conf — servidor TR-069 de la empresa',
            '# Levantar con:  wg-quick up wg-netplay   ·   habilitar:  systemctl enable wg-quick@wg-netplay',
            '',
            '[Interface]',
            'Address = 10.200.201.1/24',
            'ListenPort = ' . ($servidor->listen_port ?: 51820),
            'PrivateKey = ' . $par['privada'],
            '',
            '# El router del nodo',
            '[Peer]',
            'PublicKey = ' . $tunel->clave_publica,
            'PresharedKey = ' . ($tunel->clave_compartida ?: ''),
            'AllowedIPs = ' . ($redes ?: '10.0.0.0/8'),
            'PersistentKeepalive = 25',
            '',
            '# La clave pública de este servidor, para cargarla en el router:',
            '#   ' . $par['publica'],
        ]) . "\n";
    }
}
