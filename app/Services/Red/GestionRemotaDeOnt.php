<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\ConectionRouter;
use App\Models\GestionRemota;
use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\Services\OltTelnetDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * El acceso remoto a los equipos de los clientes, de punta a punta.
 *
 * Es una red aparte con su VLAN: la ONT pide IP ahí y en la misma respuesta
 * recibe la dirección del servidor TR-069 (opción 43 del DHCP). Con eso un
 * equipo nuevo entra solo al sistema, sin que el técnico le escriba nada.
 *
 * Montarlo a mano son once pasos repartidos entre el router y cada OLT, y
 * cualquiera de ellos mal puesto deja clientes sin servicio —sacar una VLAN
 * de un puerto de subida, por ejemplo—. Acá se propone lo que está libre, se
 * aplica de forma aditiva y queda anotado para poder deshacerlo.
 */
class GestionRemotaDeOnt
{
    /** Marca con la que se reconoce lo que puso la plataforma. */
    private const MARCA = 'Netplay gestion ONT';

    /** Redes candidatas, en orden de preferencia. */
    private const REDES = ['10.30.0.0/22', '10.40.0.0/22', '10.50.0.0/22', '172.31.0.0/22', '172.30.0.0/22'];

    public function __construct(
        private int $companyId,
        private ConectionRouterManagerInterface $conexion,
    ) {}

    // ── Estado ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function estado(): array
    {
        $g = $this->config();

        // Sólo cuentan los equipos de OLT que saben recibir la gestión: sumar
        // los otros haría ver siempre una cuenta pendiente imposible de cerrar.
        $suyas = OltAdmin::where('company_id', $this->companyId)->get(['id', 'brand'])
            ->filter(fn ($o) => self::admiteGestion((string) $o->brand))
            ->pluck('id')->all();

        $onts = OltOnt::whereIn('olt_id', $suyas);

        return [
            'activa'      => (bool) $g->activa,
            'vlan'        => $g->vlan,
            'red'         => $g->red,
            'gateway'     => $g->gateway,
            'interfaz'    => $g->interfaz,
            'router_id'   => $g->router_id,
            'uplinks'     => $g->uplinks ?? [],
            'aplicada_en' => $g->aplicada_en,
            'equipos'     => [
                'con_gestion' => (clone $onts)->whereNotNull('gestion_en')->count(),
                'total'       => (clone $onts)->count(),
            ],
        ];
    }

    /**
     * Qué VLAN y qué red están libres, y por dónde sale cada OLT.
     *
     * Se mira lo que hay de verdad en el router y en las OLT: proponer una
     * VLAN ocupada rompería el servicio de esos clientes.
     *
     * @return array<string,mixed>
     */
    public function sugerencias(?int $routerId = null): array
    {
        $router = $this->router($routerId);

        if (!$router) {
            return ['error' => 'La empresa no tiene un MikroTik configurado.'];
        }

        $api = $this->conexion->conection($router->token);

        // VLAN y redes que ya usa el router.
        $vlans = [];
        $interfaces = [];

        foreach ($api->query(new Query('/interface/vlan/print'))->read() as $v) {
            $vlans[] = (int) $v['vlan-id'];
            $padre = $v['interface'] ?? '';
            $interfaces[$padre] = ($interfaces[$padre] ?? 0) + 1;
        }

        $redesUsadas = array_map(
            fn ($a) => $a['address'],
            $api->query(new Query('/ip/address/print'))->read()
        );

        // Lo que usan las OLT en sus puertos de subida, y por dónde salen.
        $porOlt = [];

        foreach (OltAdmin::where('company_id', $this->companyId)->get() as $olt) {
            try {
                $puertos = app(OltTelnetDispatcher::class)->dispatch($olt->id, 'puertosDeSubida') ?: [];
            } catch (\Throwable $e) {
                Log::warning('[Gestión] No se pudieron leer los puertos de subida', ['olt' => $olt->id, 'error' => $e->getMessage()]);
                $puertos = [];
            }

            foreach ($puertos as $p) {
                $vlans = array_merge($vlans, $p['vlans']);
            }

            $porOlt[] = [
                'olt_id'   => (int) $olt->id,
                'nombre'   => $olt->name,
                'marca'    => $olt->brand,
                'puertos'  => $puertos,
                'sugerido' => $this->uplinkQueCoincide($puertos, $interfaces, $api),
            ];
        }

        $vlans = array_values(array_unique(array_filter($vlans)));
        sort($vlans);

        // La interfaz por la que salen más VLAN de clientes es la que va a la OLT.
        arsort($interfaces);

        return [
            'vlans_libres'   => $this->vlansLibres($vlans),
            'vlans_en_uso'   => $vlans,
            'redes_libres'   => $this->redesLibres($redesUsadas),
            'interfaz'       => array_key_first($interfaces),
            'interfaces'     => $interfaces,
            'router_id'      => (int) $router->id,
            'olts'           => $porOlt,
            'url_acs'        => (string) config('services.genieacs.cwmp_url'),
        ];
    }

    // ── Activar ───────────────────────────────────────────────────────────

    /**
     * Deja todo listo: la red en el router y la VLAN pasando por cada OLT.
     *
     * @return array<string,mixed>
     */
    public function activar(array $datos): array
    {
        $vlan = (int) ($datos['vlan'] ?? 0);
        $red  = (string) ($datos['red'] ?? '');

        if ($vlan < 2 || $vlan > 4094) {
            throw new \InvalidArgumentException('Elegí un número de VLAN entre 2 y 4094.');
        }

        if (!preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $red)) {
            throw new \InvalidArgumentException('La red tiene que ir en formato 10.30.0.0/22.');
        }

        $router = $this->router($datos['router_id'] ?? null);

        if (!$router) {
            throw new \InvalidArgumentException('La empresa no tiene un MikroTik configurado.');
        }

        $g = $this->config();

        // Si ya había una VLAN puesta y ahora es otra, se limpia la anterior:
        // dos redes de gestión conviviendo confunden a cualquiera.
        if ($g->vlan && (int) $g->vlan !== $vlan) {
            $this->limpiarDelRouter($this->conexion->conection($router->token), (int) $g->vlan);

            // Los equipos quedaron configurados con la VLAN vieja: hay que
            // volver a pasarles la nueva, así que dejan de contar como hechos.
            OltOnt::whereHas('olt', fn ($q) => $q->where('company_id', $this->companyId))
                ->whereNotNull('gestion_en')
                ->update(['gestion_en' => null]);
        }

        [$gateway, $desde, $hasta] = $this->rangos($red);

        $api = $this->conexion->conection($router->token);
        $interfaz = (string) ($datos['interfaz'] ?? '');

        $pasos = $this->enElRouter($api, $vlan, $red, $gateway, $desde, $hasta, $interfaz);

        $uplinks = [];

        foreach ((array) ($datos['uplinks'] ?? []) as $oltId => $puerto) {
            if (!$puerto) {
                continue;
            }

            try {
                $r = app(OltTelnetDispatcher::class)->dispatch((int) $oltId, 'prepararVlanDeGestion', [
                    'vlan' => $vlan, 'uplink' => $puerto,
                ]);

                $pasos[] = [
                    'paso'    => "VLAN {$vlan} en la OLT por {$puerto}",
                    'ok'      => (bool) ($r['ok'] ?? false),
                    'detalle' => $r['detalle'] ?? '',
                ];

                $uplinks[(int) $oltId] = $puerto;
            } catch (\Throwable $e) {
                $pasos[] = ['paso' => "VLAN en la OLT {$oltId}", 'ok' => false, 'detalle' => $e->getMessage()];
            }
        }

        $pasos[] = $this->enElTunel($router, $red);

        $g->fill([
            'activa'      => true,
            'vlan'        => $vlan,
            'red'         => $red,
            'gateway'     => $gateway,
            'pool_desde'  => $desde,
            'pool_hasta'  => $hasta,
            'router_id'   => $router->id,
            'interfaz'    => $interfaz,
            'uplinks'     => $uplinks,
            'aplicada_en' => now(),
        ])->save();

        return ['pasos' => $pasos, 'estado' => $this->estado()];
    }

    /** Quita del router lo que puso la plataforma. En la OLT no se toca nada. */
    public function desactivar(): array
    {
        $g = $this->config();
        $router = $this->router($g->router_id);

        if ($router && $g->vlan) {
            $this->limpiarDelRouter($this->conexion->conection($router->token), (int) $g->vlan);
        }

        $g->fill(['activa' => false, 'aplicada_en' => null])->save();

        return [
            'estado' => $this->estado(),
            'aviso'  => 'La VLAN sigue permitida en la OLT: sacarla de un puerto de subida es riesgoso y allí no molesta.',
        ];
    }

    // ── Por ONT ───────────────────────────────────────────────────────────

    /**
     * Le da acceso de gestión a una ONT: su service-port en la VLAN y su IP
     * por DHCP. Es lo que se corre al autorizar un equipo nuevo.
     *
     * @return array{ok:bool, detalle:string}
     */
    public function darAcceso(int $oltId, string $fsp, int $ontId): array
    {
        $g = $this->config();

        if (!$g->activa || !$g->vlan) {
            return ['ok' => false, 'detalle' => 'El acceso remoto no está activado.'];
        }

        $olt = OltAdmin::where('id', $oltId)->where('company_id', $this->companyId)->first();

        if (!$olt) {
            return ['ok' => false, 'detalle' => 'Esa OLT no es de tu empresa.'];
        }

        if (!self::admiteGestion((string) $olt->brand)) {
            return ['ok' => false, 'detalle' => 'Esta OLT no admite dar la gestión desde acá: hay que configurarla en la OLT.'];
        }

        // El número de service-port puede estar tomado por otro equipo sin que
        // la plataforma lo sepa (los pone también quien entra por consola). Se
        // prueba, se lee a quién quedó y, si no es este equipo, se va al
        // siguiente en vez de dar por bueno algo que no existe.
        $evitar = [];
        $r = null;

        // Dos intentos y no más: cada uno son ocho comandos contra la OLT y
        // del otro lado hay alguien esperando la respuesta en el navegador.
        for ($intento = 0; $intento < 2; $intento++) {
            $numero = $this->servicePortLibre($oltId, $evitar);

            try {
                $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'darGestionAOnt', [
                    'fsp' => $fsp, 'ont_id' => $ontId, 'vlan' => (int) $g->vlan,
                    'service_port' => $numero,
                ]);
            } catch (\Throwable $e) {
                return ['ok' => false, 'detalle' => $e->getMessage()];
            }

            if ($r['sp_ok'] ?? false) {
                break;
            }

            $evitar[] = $numero;
        }

        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'detalle' => $r['detalle'] ?? 'La OLT no aceptó la configuración.'];
        }

        $ont = OltOnt::where('olt_id', $oltId)->where('fsp', $fsp)->where('ont_id', $ontId)->first();

        if ($ont) {
            // Queda anotado para no volver a repartir el mismo número, que es
            // justamente lo que hace que la OLT rechace el comando.
            // Uno solo por VLAN: si quedó anotado uno viejo de un intento anterior,
            // se reemplaza por el que la OLT confirmó.
            $puertos = collect($ont->service_ports ?? [])
                ->reject(fn ($p) => (int) ($p['index'] ?? 0) === (int) $r['sp'] || (int) ($p['vlan'] ?? 0) === (int) $g->vlan)
                ->values()->all();
            $puertos[] = ['index' => (int) $r['sp'], 'vlan' => (int) $g->vlan];

            $ont->update(['gestion_en' => now(), 'service_ports' => $puertos]);
        }

        return ['ok' => true, 'detalle' => $r['detalle'] ?? 'Listo'];
    }

    /**
     * La red de gestión, agregada a las rutas de la VPN.
     *
     * Sin esto el equipo se presenta al servidor, pero el servidor no sabe
     * volver a él: los cambios quedarían esperando a que el equipo pregunte
     * otra vez, en vez de aplicarse al momento.
     *
     * @return array{paso:string, ok:bool, detalle:string}
     */
    private function enElTunel(ConectionRouter $router, string $red): array
    {
        $tunel = \App\Services\Vpn\ServidorVpn::tunelQueCubre((string) $router->host, $this->companyId)
            ?? \App\Models\VpnTunel::where('company_id', $this->companyId)->where('activo', true)->orderBy('id')->first();

        if (!$tunel) {
            return [
                'paso'    => 'Ruta en la VPN',
                'ok'      => true,
                'detalle' => 'No hay túnel: el servidor llega por internet.',
            ];
        }

        $redes = (array) ($tunel->redes_remotas ?? []);

        if (in_array($red, $redes, true)) {
            return ['paso' => 'Ruta en la VPN', 'ok' => true, 'detalle' => "Ya estaba: {$red} por «{$tunel->nombre}»"];
        }

        $redes[] = $red;
        $tunel->update(['redes_remotas' => array_values($redes)]);

        try {
            $r = \App\Services\Vpn\ServidorVpn::aplicar();
        } catch (\Throwable $e) {
            $r = ['aplicado' => false, 'motivo' => $e->getMessage()];
        }

        return [
            'paso'    => 'Ruta en la VPN',
            'ok'      => (bool) ($r['aplicado'] ?? false),
            'detalle' => ($r['aplicado'] ?? false)
                ? "{$red} por «{$tunel->nombre}»"
                : 'Quedó anotada, pero falta aplicarla: ' . ($r['motivo'] ?? 'sin detalle'),
        ];
    }

    // ── El router ─────────────────────────────────────────────────────────

    /**
     * Crea en el MikroTik la VLAN, su red, el DHCP con la opción 43 y el
     * aislamiento. Todo idempotente: se puede repetir sin duplicar nada.
     *
     * @return list<array<string,mixed>>
     */
    private function enElRouter($api, int $vlan, string $red, string $gateway, string $desde, string $hasta, string $interfaz): array
    {
        $nombre = "vlan{$vlan}-gestion";
        $pool   = "pool-gestion-ont";
        $opcion = "netplay-acs";
        $pasos  = [];

        $hay = fn (string $cmd, string $campo, string $valor) => $api->query((new Query($cmd))->where($campo, $valor))->read();

        // 1. La VLAN sobre la interfaz que va a la OLT.
        if (!$hay('/interface/vlan/print', 'name', $nombre)) {
            $api->query((new Query('/interface/vlan/add'))
                ->equal('name', $nombre)->equal('vlan-id', (string) $vlan)
                ->equal('interface', $interfaz)->equal('comment', self::MARCA))->read();
        }

        $pasos[] = ['paso' => "VLAN {$vlan} en {$interfaz}", 'ok' => true, 'detalle' => $nombre];

        // 2. La dirección del router en esa red.
        if (!$hay('/ip/address/print', 'address', $gateway . '/' . explode('/', $red)[1])) {
            $api->query((new Query('/ip/address/add'))
                ->equal('address', $gateway . '/' . explode('/', $red)[1])
                ->equal('interface', $nombre)->equal('comment', self::MARCA))->read();
        }

        // 3. El rango de IP para las ONT.
        if (!$hay('/ip/pool/print', 'name', $pool)) {
            $api->query((new Query('/ip/pool/add'))->equal('name', $pool)->equal('ranges', "{$desde}-{$hasta}"))->read();
        }

        // 4. La opción 43: la dirección del ACS viaja con la IP.
        $url = (string) config('services.genieacs.cwmp_url');
        $tlv = '0x01' . str_pad(dechex(strlen($url)), 2, '0', STR_PAD_LEFT) . bin2hex($url);
        $existeOpcion = $hay('/ip/dhcp-server/option/print', 'name', $opcion);

        $q = new Query($existeOpcion ? '/ip/dhcp-server/option/set' : '/ip/dhcp-server/option/add');

        if ($existeOpcion) {
            $q->equal('.id', $existeOpcion[0]['.id']);
        }

        $api->query($q->equal('name', $opcion)->equal('code', '43')->equal('value', $tlv))->read();

        $pasos[] = ['paso' => 'Dirección del ACS por DHCP', 'ok' => true, 'detalle' => $url];

        // 5. El servidor DHCP y su red.
        if (!$hay('/ip/dhcp-server/print', 'name', 'dhcp-gestion-ont')) {
            $api->query((new Query('/ip/dhcp-server/add'))
                ->equal('name', 'dhcp-gestion-ont')->equal('interface', $nombre)
                ->equal('address-pool', $pool)->equal('lease-time', '1d')->equal('disabled', 'no'))->read();
        }

        if (!$hay('/ip/dhcp-server/network/print', 'address', $red)) {
            $api->query((new Query('/ip/dhcp-server/network/add'))
                ->equal('address', $red)->equal('gateway', $gateway)
                ->equal('dns-server', $gateway)->equal('dhcp-option', $opcion)
                ->equal('comment', self::MARCA))->read();
        }

        $dhcp = $hay('/ip/dhcp-server/print', 'name', 'dhcp-gestion-ont')[0] ?? [];

        $pasos[] = [
            'paso'    => 'DHCP para las ONT',
            'ok'      => ($dhcp['invalid'] ?? 'true') !== 'true',
            'detalle' => ($dhcp['invalid'] ?? 'true') !== 'true' ? "{$desde} a {$hasta}" : ($dhcp['.about'] ?? 'no quedó activo'),
        ];

        // 6. Aislamiento: de esa red sólo se sale hacia el ACS.
        $this->aislar($api, $red, parse_url($url, PHP_URL_HOST) ?: '');

        $pasos[] = ['paso' => 'Aislada del resto de la red', 'ok' => true, 'detalle' => 'sólo habla con el servidor TR-069'];

        return $pasos;
    }

    /** Las dos reglas que encierran la red de gestión, arriba de todo. */
    private function aislar($api, string $red, string $acs): void
    {
        $marcas = [self::MARCA . ': al ACS', self::MARCA . ': nada mas'];

        foreach ($marcas as $i => $marca) {
            if ($api->query((new Query('/ip/firewall/filter/print'))->where('comment', $marca))->read()) {
                continue;
            }

            $q = (new Query('/ip/firewall/filter/add'))
                ->equal('chain', 'forward')->equal('src-address', $red)
                ->equal('action', $i === 0 ? 'accept' : 'drop')
                ->equal('comment', $marca);

            if ($i === 0 && $acs) {
                $q->equal('dst-address', $acs);
            }

            $api->query($q)->read();
        }

        // Tienen que ir primero: una regla que acepte todo antes las anularía.
        // Y al revés entre ellas: cada una se mueve arriba de la anterior, así
        // que va última la que tiene que quedar primera (si el "descartar"
        // quedara arriba del "al ACS", no pasaría nada hacia el servidor).
        $todas = $api->query(new Query('/ip/firewall/filter/print'))->read();
        $primera = $todas[0]['.id'] ?? null;

        foreach (array_reverse($marcas) as $marca) {
            foreach ($todas as $f) {
                if (($f['comment'] ?? '') === $marca && $primera) {
                    $api->query((new Query('/ip/firewall/filter/move'))
                        ->equal('numbers', $f['.id'])->equal('destination', $primera))->read();
                }
            }
        }
    }

    /** Borra del router lo que puso la plataforma para esa VLAN. */
    private function limpiarDelRouter($api, int $vlan): void
    {
        $borrar = [
            ['/ip/dhcp-server/network/print', '/ip/dhcp-server/network/remove', 'comment', self::MARCA],
            ['/ip/dhcp-server/print', '/ip/dhcp-server/remove', 'name', 'dhcp-gestion-ont'],
            ['/ip/pool/print', '/ip/pool/remove', 'name', 'pool-gestion-ont'],
            ['/ip/address/print', '/ip/address/remove', 'comment', self::MARCA],
            ['/interface/vlan/print', '/interface/vlan/remove', 'name', "vlan{$vlan}-gestion"],
            ['/ip/firewall/filter/print', '/ip/firewall/filter/remove', 'comment', self::MARCA . ': al ACS'],
            ['/ip/firewall/filter/print', '/ip/firewall/filter/remove', 'comment', self::MARCA . ': nada mas'],
        ];

        foreach ($borrar as [$listar, $quitar, $campo, $valor]) {
            foreach ($api->query((new Query($listar))->where($campo, $valor))->read() as $fila) {
                try {
                    $api->query((new Query($quitar))->equal('.id', $fila['.id']))->read();
                } catch (\Throwable $e) {
                    Log::warning('[Gestión] No se pudo quitar', ['cmd' => $quitar, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    // ── Ayudas ────────────────────────────────────────────────────────────

    /**
     * Qué equipos saben recibir la configuración de gestión.
     *
     * Las EPON de C-Data no tienen cómo: su CLI sólo habilita o deshabilita la
     * VLAN del servicio, no le puede decir a la ONT que pida IP de gestión.
     */
    public static function admiteGestion(string $marca): bool
    {
        return in_array(strtolower($marca), ['huawei'], true);
    }

    private function config(): GestionRemota
    {
        return GestionRemota::firstOrCreate(['company_id' => $this->companyId]);
    }

    private function router(?int $routerId): ?ConectionRouter
    {
        return ConectionRouter::where('company_id', $this->companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->orderBy('id')->first();
    }

    /**
     * Tres números de VLAN libres, redondos y fáciles de recordar.
     *
     * @param  list<int>  $usadas
     * @return list<int>
     */
    private function vlansLibres(array $usadas): array
    {
        $libres = [];

        foreach ([300, 400, 500, 600, 900, 1000] as $candidata) {
            if (!in_array($candidata, $usadas, true)) {
                $libres[] = $candidata;
            }

            if (count($libres) === 3) {
                break;
            }
        }

        return $libres;
    }

    /**
     * Redes que no se solapan con ninguna del router.
     *
     * @param  list<string>  $usadas
     * @return list<string>
     */
    private function redesLibres(array $usadas): array
    {
        $libres = [];

        foreach (self::REDES as $red) {
            $choca = false;

            foreach ($usadas as $usada) {
                if ($this->seSolapan($red, $usada)) {
                    $choca = true;
                    break;
                }
            }

            if (!$choca) {
                $libres[] = $red;
            }
        }

        return $libres;
    }

    private function seSolapan(string $a, string $b): bool
    {
        [$ipA, $bitsA] = array_pad(explode('/', $a), 2, '32');
        [$ipB, $bitsB] = array_pad(explode('/', $b), 2, '32');

        $bits = min((int) $bitsA, (int) $bitsB);
        $mascara = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return (ip2long($ipA) & $mascara) === (ip2long($ipB) & $mascara);
    }

    /** @return array{0:string,1:string,2:string} gateway, primera y última del rango */
    private function rangos(string $red): array
    {
        [$base, $bits] = explode('/', $red);
        $inicio = ip2long($base);
        $total  = 2 ** (32 - (int) $bits);

        return [long2ip($inicio + 1), long2ip($inicio + 10), long2ip($inicio + $total - 2)];
    }

    /**
     * Qué puerto de subida de la OLT corresponde a la interfaz del router:
     * el que comparte más VLAN con las que salen por ahí.
     *
     * @param  list<array{puerto:string, vlans:list<int>}>  $puertos
     * @param  array<string,int>  $interfaces
     */
    private function uplinkQueCoincide(array $puertos, array $interfaces, $api): ?string
    {
        if (!$puertos) {
            return null;
        }

        $principal = array_key_first($interfaces);
        $deLaInterfaz = [];

        foreach ($api->query(new Query('/interface/vlan/print'))->read() as $v) {
            if (($v['interface'] ?? '') === $principal) {
                $deLaInterfaz[] = (int) $v['vlan-id'];
            }
        }

        $mejor = null;
        // Empieza abajo de todo: si arrancara en cero, un puerto que comparte
        // pocas VLAN y trae otras propias daba negativo y no se proponía nada.
        $puntaje = PHP_INT_MIN;

        foreach ($puertos as $p) {
            $comunes = count(array_intersect($deLaInterfaz, $p['vlans']));
            $sobran  = count(array_diff($p['vlans'], $deLaInterfaz, [1]));
            $valor   = $comunes - $sobran;

            if ($valor > $puntaje) {
                $puntaje = $valor;
                $mejor   = $p['puerto'];
            }
        }

        return $mejor;
    }

    /**
     * Un índice de service-port que no esté usado en esa OLT.
     *
     * Se miran los dos lados: lo que la plataforma tiene anotado y lo que la
     * OLT devolvió la última vez que se leyeron sus service-port. Repetir un
     * índice hace que la OLT rechace el comando, así que conviene mirar de
     * más. Se arranca alto (9000 para arriba) para no pisar la numeración que
     * usan los service-port de internet, que salen del reloj.
     */
    private function servicePortLibre(int $oltId, array $evitar = []): int
    {
        $usados = OltOnt::where('olt_id', $oltId)
            ->whereNotNull('service_ports')
            ->get('service_ports')
            ->flatMap(fn ($o) => collect($o->service_ports)->pluck('index'))
            ->filter()->map(fn ($i) => (int) $i)->all();

        foreach ((array) Cache::get("olt:{$oltId}:all_service_ports", []) as $puertos) {
            foreach ((array) $puertos as $sp) {
                if (isset($sp['index'])) {
                    $usados[] = (int) $sp['index'];
                }
            }
        }

        $usados = array_flip(array_merge($usados, $evitar));

        // Un contador aparte para no volver a empezar desde 9000 en cada
        // equipo: probar de nuevo el mismo número gasta una vuelta entera
        // contra la OLT para que conteste que ya estaba tomado.
        $desde = max(9000, (int) Cache::get("olt:{$oltId}:gestion_sp", 9000));

        foreach ([range($desde, 9999), range(9000, $desde - 1)] as $tramo) {
            foreach ($tramo as $i) {
                if (isset($usados[$i])) {
                    continue;
                }

                Cache::put("olt:{$oltId}:gestion_sp", $i + 1, now()->addYear());

                return $i;
            }
        }

        // Improbable, pero si los 9000 están tomados se busca hacia abajo.
        for ($i = 8999; $i >= 1; $i--) {
            if (!isset($usados[$i])) {
                return $i;
            }
        }

        throw new \RuntimeException('La OLT no tiene números de service-port libres.');
    }
}
