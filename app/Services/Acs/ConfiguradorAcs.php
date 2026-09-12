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
        $s = $this->servidor();
        $tunel = $s->vpn_tunel_id ? VpnTunel::find($s->vpn_tunel_id) : $this->tunelDeLaEmpresa();

        return [
            'configurado'   => (bool) $s->aplicado_en,
            'modo'          => $s->modo,
            'host'          => $s->host,
            'puerto_cwmp'   => $s->puerto_cwmp,
            'url_nbi'       => $s->url_nbi,
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
        ];
    }

    /** Guarda dónde está el servidor TR-069 de la empresa. */
    public function guardar(array $datos): array
    {
        $s = $this->servidor();

        $modo = in_array($datos['modo'] ?? '', ['plataforma', 'propio'], true) ? $datos['modo'] : 'plataforma';

        if ($modo === 'propio' && empty($datos['host'])) {
            throw new \InvalidArgumentException('Falta la dirección del servidor TR-069.');
        }

        $s->fill([
            'modo'        => $modo,
            'host'        => $modo === 'propio' ? trim((string) $datos['host']) : null,
            'puerto_cwmp' => (int) ($datos['puerto_cwmp'] ?? 7547) ?: 7547,
            'url_nbi'     => $modo === 'propio' ? ($datos['url_nbi'] ?? null) : null,
            'alcance'     => in_array($datos['alcance'] ?? '', ['publica', 'tunel'], true) ? $datos['alcance'] : 'tunel',
            'router_id'   => $datos['router_id'] ?? $s->router_id,
        ])->save();

        return $this->estado();
    }

    /** Lee del router qué redes hay, sin pedirle nada al operador. */
    public function detectar(?int $routerId = null): array
    {
        $r = (new RedesDelOperador($this->conexion, $this->companyId))->detectar($routerId);

        $s = $this->servidor();
        $elegidas = collect($s->redes ?? [])->pluck('red')->all();

        // Lo ya elegido se respeta; lo nuevo entra marcado.
        $redes = array_map(fn ($red) => $red + [
            'elegida' => $elegidas === [] ? (bool) $red['sugerida'] : in_array($red['red'], $elegidas, true),
        ], $r['redes']);

        $s->fill(['redes' => $redes, 'detectado_en' => now(), 'router_id' => $routerId ?: $s->router_id])->save();

        return ['redes' => $redes, 'router' => $r['router'], 'error' => $r['error']];
    }

    /**
     * Aplica todo: arma o actualiza el túnel con las redes elegidas y deja la
     * configuración del servidor puesta. Lo único que queda es pegar el script.
     *
     * @param  list<string>  $redes  las redes elegidas, en CIDR
     */
    public function aplicar(array $redes): array
    {
        if (!$redes) {
            throw new \InvalidArgumentException('Elegí al menos una red para alcanzar.');
        }

        $s = $this->servidor();

        // Con el servidor de la plataforma —o con uno propio en otra red— el
        // camino es el túnel. Un servidor propio dentro de la misma red del
        // router no necesita nada: ya se ven entre ellos.
        if ($s->modo === 'propio' && $s->alcance === 'publica') {
            $s->fill(['aplicado_en' => now()])->save();

            return $this->estado();
        }

        $tunel = $this->tunelDeLaEmpresa();

        if ($tunel) {
            $tunel->fill(['redes_remotas' => ServidorVpn::normalizarRedes($redes)])->save();
            ServidorVpn::aplicar();
        } else {
            $router = ConectionRouter::where('company_id', $this->companyId)
                ->when($s->router_id, fn ($q) => $q->where('id', $s->router_id))
                ->orderBy('id')->first();

            $creado = ServidorVpn::crearTunel([
                'nombre'         => 'Gestión ' . ($router->name ?? 'del nodo'),
                'redes_remotas'  => $redes,
                'router_id'      => $router->id ?? null,
                'notas'          => 'Creado por el asistente de TR-069.',
            ]);

            $tunel = $creado['tunel'];
        }

        $s->fill([
            'vpn_tunel_id' => $tunel->id,
            'redes'        => $this->marcarElegidas($s->redes ?? [], $redes),
            'aplicado_en'  => now(),
        ])->save();

        return $this->estado();
    }

    /**
     * El script para el router y, si el servidor es de la empresa, también la
     * configuración de su lado.
     *
     * @return array{router: ?string, servidor: ?string, notas: list<string>}
     */
    public function script(): array
    {
        $s = $this->servidor();
        $notas = [];

        if ($s->modo === 'propio' && $s->alcance === 'publica') {
            return [
                'router'   => null,
                'servidor' => null,
                'notas'    => [
                    'Tu servidor TR-069 tiene IP pública, así que los equipos llegan solos: no hay que tocar el router.',
                    'Configurá en los equipos la URL ' . $s->urlCwmp() . '.',
                    'Para que los cambios se apliquen al momento, tu servidor tiene que poder llamar a los equipos. '
                        . 'Si están detrás del router con IP privada, elegí "por túnel" en vez de "IP pública".',
                ],
            ];
        }

        $tunel = $s->vpn_tunel_id ? VpnTunel::find($s->vpn_tunel_id) : $this->tunelDeLaEmpresa();

        if (!$tunel) {
            return ['router' => null, 'servidor' => null, 'notas' => ['Todavía no hay túnel: aplicá la configuración primero.']];
        }

        $script = ScriptMikrotik::para(
            $tunel,
            ServidorVpn::configuracion(),
            (string) $tunel->clave_privada,
            (string) $tunel->clave_compartida,
        );

        if ($s->modo === 'propio') {
            $notas[] = 'Tu servidor TR-069 está en otra red, así que además del router hay que levantar '
                . 'el otro extremo del túnel en ese servidor con la configuración de abajo.';
        }

        return [
            'router'   => $script,
            'servidor' => $s->modo === 'propio' ? $this->configDelServidorPropio($tunel) : null,
            'notas'    => $notas,
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
     * Qué falta para que funcione, dicho en una línea cada cosa.
     *
     * @return list<string>
     */
    private function pendientes(AcsServidor $s, ?VpnTunel $tunel): array
    {
        $faltan = [];

        if ($s->modo === 'propio' && !$s->host) {
            $faltan[] = 'Decinos dónde está tu servidor TR-069.';
        }

        if (!$s->redes) {
            $faltan[] = 'Detectá las redes de tu router: es un botón.';
        }

        if (!$s->aplicado_en) {
            $faltan[] = 'Aplicá la configuración para armar el camino hasta los equipos.';
        }

        if ($s->alcance === 'tunel' && $tunel && !($tunel->ultimo_saludo && $tunel->ultimo_saludo->gt(now()->subMinutes(5)))) {
            $faltan[] = 'El router todavía no saluda por el túnel: pegá el script en el router.';
        }

        return $faltan;
    }

    /** @param list<array<string,mixed>> $redes */
    private function marcarElegidas(array $redes, array $elegidas): array
    {
        return array_map(fn ($r) => ['elegida' => in_array($r['red'], $elegidas, true)] + $r, $redes);
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
