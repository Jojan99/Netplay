<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\ConectionRouter;
use App\Models\GestionRemota;
use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\Services\Acs\GenieAcs;
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
        $porInterfaz = [];

        $nombresPorInterfaz = [];

        foreach ($api->query(new Query('/interface/vlan/print'))->read() as $v) {
            $padre = $v['interface'] ?? '';

            // Las VLAN de gestión y el bridge que arma la plataforma no cuentan
            // para adivinar por dónde llega cada OLT.
            if (($v['comment'] ?? '') === self::MARCA || $padre === self::BRIDGE_GESTION) {
                continue;
            }

            $vlans[] = (int) $v['vlan-id'];
            $interfaces[$padre] = ($interfaces[$padre] ?? 0) + 1;
            $porInterfaz[$padre][] = (int) $v['vlan-id'];
            $nombresPorInterfaz[$padre][] = strtolower((string) $v['name']);
        }

        $redesUsadas = array_map(
            fn ($a) => $a['address'],
            $api->query(new Query('/ip/address/print'))->read()
        );

        // Las que otras empresas ya llevan por la VPN tampoco sirven: el servidor
        // TR-069 no podría distinguir sus equipos de los de esta empresa.
        $redesUsadas = array_merge($redesUsadas, \App\Models\VpnTunel::where('company_id', '!=', $this->companyId)
            ->get()
            ->flatMap(fn ($t) => array_merge($t->redes_remotas ?? [], array_column($t->traducciones ?? [], 'real')))
            ->all());

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

            $sugerido = $this->uplinkQueCoincide($puertos, $interfaces, $api);

            $porOlt[] = [
                'olt_id'    => (int) $olt->id,
                'nombre'    => $olt->name,
                'marca'     => $olt->brand,
                'puertos'   => $puertos,
                'sugerido'  => $sugerido,
                'soporte'   => $this->soporte($olt),
                // Por qué interfaz del MikroTik llega esta OLT.
                'interfaz'  => self::interfazDeOlt($olt, $puertos, $sugerido, $porInterfaz, $nombresPorInterfaz),
            ];
        }

        $vlans = array_values(array_unique(array_filter($vlans)));
        sort($vlans);

        // La interfaz por la que salen más VLAN de clientes es la que va a la OLT.
        arsort($interfaces);

        // Si las OLT llegan por interfaces distintas, se ofrece la combinación
        // (la red de gestión se arma en un bridge) y queda sugerida.
        $deOlts = array_values(array_unique(array_filter(array_column($porOlt, 'interfaz'))));
        $combinada = count($deOlts) > 1 ? implode(' + ', $deOlts) : null;

        if ($combinada) {
            $interfaces = [$combinada => array_sum(array_intersect_key($interfaces, array_flip($deOlts)))] + $interfaces;
            $porInterfaz[$combinada] = array_values(array_unique(array_merge(...array_map(fn ($i) => $porInterfaz[$i] ?? [], $deOlts))));
        }

        return [
            'vlans_libres'   => $this->vlansLibres($vlans),
            'vlans_en_uso'   => $vlans,
            'redes_libres'   => $this->redesLibres($redesUsadas),
            'interfaz'       => $combinada ?? ($deOlts[0] ?? array_key_first($interfaces)),
            'interfaces'     => $interfaces,
            // Qué VLAN lleva cada una, para reconocerla sin entrar al router.
            'vlans_por_interfaz' => array_map(function ($l) { sort($l); return $l; }, $porInterfaz),
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

        // La red de gestión no puede ser una que otra empresa ya usa (en su
        // túnel o como su red de gestión): el servidor no sabría a cuál llegar.
        $ajenas = \App\Models\VpnTunel::where('company_id', '!=', $this->companyId)->get()
            ->flatMap(fn ($t) => array_merge($t->redes_remotas ?? [], array_column($t->traducciones ?? [], 'real')))
            ->merge(GestionRemota::where('company_id', '!=', $this->companyId)->whereNotNull('red')->pluck('red'))
            ->filter()->unique();

        if ($choca = $ajenas->first(fn ($otra) => $this->seSolapan($red, (string) $otra))) {
            throw new \InvalidArgumentException("La red {$red} ya la usa otra empresa ({$choca}). Elegí otra: las sugeridas no chocan con nadie.");
        }

        // Las interfaces se validan antes de tocar nada: limpiar la red vieja
        // y después descubrir que la nueva no existe dejaba al router sin red
        // de gestión.
        $ifaces = self::interfacesDe((string) ($datos['interfaz'] ?? ''));

        if (!$ifaces) {
            throw new \InvalidArgumentException('Elegí la interfaz del MikroTik hacia la OLT.');
        }

        $existentes = collect($this->conexion->conection($router->token)->query(new Query('/interface/print'))->read())->pluck('name')->all();

        if ($faltan = array_diff($ifaces, $existentes)) {
            throw new \InvalidArgumentException('El MikroTik no tiene la interfaz ' . implode(', ', $faltan) . '.');
        }

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
        $mias = OltAdmin::where('company_id', $this->companyId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ((array) ($datos['uplinks'] ?? []) as $oltId => $puerto) {
            if (!$puerto) {
                continue;
            }

            // Sólo OLT de la empresa: el id llega en el cuerpo del pedido y el
            // middleware de empresa no lo mira.
            if (!in_array((int) $oltId, $mias, true)) {
                $pasos[] = ['paso' => "VLAN en la OLT {$oltId}", 'ok' => false, 'detalle' => 'Esa OLT no es de tu empresa: no se tocó.'];
                continue;
            }

            try {
                $r = app(OltTelnetDispatcher::class)->dispatch((int) $oltId, 'prepararVlanDeGestion', [
                    'vlan' => $vlan, 'uplink' => $puerto,
                ]);

                $pasos[] = $r === null
                    // El driver de esa marca no sabe hacerlo: se dice qué falta
                    // en vez de mostrar un paso fallido sin explicación.
                    ? [
                        'paso'    => "VLAN {$vlan} en la OLT por {$puerto}",
                        'ok'      => false,
                        'detalle' => "Esta OLT no se configura sola: agregá la VLAN {$vlan} como tagged en el puerto {$puerto} de la OLT, a mano.",
                    ]
                    : [
                        'paso'    => "VLAN {$vlan} en la OLT por {$puerto}",
                        'ok'      => (bool) ($r['ok'] ?? false),
                        'detalle' => $r['detalle'] ?? '',
                    ];

                // Se guarda sólo si la VLAN quedó: si no, el diagnóstico la daba
                // por pasando por la OLT sin estarlo.
                if (($r['ok'] ?? false) === true) {
                    $uplinks[(int) $oltId] = $puerto;
                }
            } catch (\Throwable $e) {
                $pasos[] = ['paso' => "VLAN en la OLT {$oltId}", 'ok' => false, 'detalle' => \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage())];
            }
        }

        foreach (array_keys($uplinks) as $oltId) {
            $pasos[] = $this->asegurarServidorTr069((int) $oltId);
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

    // ── Qué soporta cada OLT ──────────────────────────────────────────────

    /**
     * Hasta dónde llega la plataforma con una OLT, dicho para el operador.
     *
     * No se le pregunta a la OLT: es algo que no cambia y consultarlo gasta
     * una sesión. Sólo Huawei GPON está probado de punta a punta; en el resto
     * se dice la verdad —qué queda listo y qué hay que hacer a mano— en vez de
     * mandar comandos que nadie probó a la OLT de un cliente.
     *
     * @return array{nivel:string, titulo:string, detalle:string}
     */
    public function soporte(OltAdmin $olt): array
    {
        $vlan  = (int) ($this->config()->vlan ?: 0) ?: 'de gestión';
        $marca = strtolower((string) $olt->brand);

        if ($marca === 'huawei') {
            return [
                'nivel'   => 'completo',
                'titulo'  => 'Se configura sola',
                'detalle' => 'La plataforma deja pasar la VLAN por la OLT, prepara los perfiles de línea y le da el acceso a cada equipo.',
            ];
        }

        if ($marca === 'cdata') {
            $tecnologia = self::tecnologiaCdata($olt);

            if ($tecnologia === 'gpon') {
                return [
                    'nivel'   => 'completo',
                    'titulo'  => 'Se configura sola',
                    'detalle' => "La plataforma deja pasar la VLAN {$vlan} por la OLT, la suma a los perfiles de línea y le da a cada equipo su IP de gestión y el servidor TR-069.",
                ];
            }

            if ($tecnologia === 'epon') {
                return [
                    'nivel'   => 'completo',
                    'titulo'  => 'Se configura sola',
                    'detalle' => "La plataforma deja pasar la VLAN {$vlan} por el puerto de subida y los PON, y a cada equipo le crea su conexión de gestión por DHCP; la dirección del TR-069 le llega en esa IP.",
                ];
            }

        }

        $nombre = ['zte' => 'ZTE', 'vsol' => 'V-SOL', 'cdata' => 'C-Data GPON'][$marca] ?? strtoupper($marca);

        return [
            'nivel'   => 'sin_probar',
            'titulo'  => "Todavía no automatizado en {$nombre}",
            'detalle' => "La red del MikroTik queda lista, pero no probamos los comandos de {$nombre} y no los mandamos a ciegas a una OLT en producción. "
                . "A mano: dejar pasar la VLAN {$vlan} por el puerto de subida, habilitarla en el perfil de cada equipo y crear su WAN TR-069 por DHCP. "
                . 'Si nos das una OLT de prueba de esta marca, lo sumamos.',
        ];
    }

    // ── Perfiles de línea ─────────────────────────────────────────────────

    /**
     * Los perfiles de línea de la OLT y si ya dejan salir la gestión.
     *
     * Sin la VLAN de gestión en el perfil, la ONT descarta ese tráfico aunque
     * todo lo demás esté bien: es el paso que faltaba y no se ve desde afuera.
     *
     * @return array<string,mixed>
     */
    public function perfiles(int $oltId, bool $releer = false): array
    {
        $g   = $this->config();
        $olt = OltAdmin::where('id', $oltId)->where('company_id', $this->companyId)->first();

        if (!$olt) {
            return ['error' => 'Esa OLT no es de tu empresa.'];
        }

        if ($this->soporte($olt)['nivel'] !== 'completo') {
            return ['error' => $this->soporte($olt)['detalle'], 'perfiles' => []];
        }

        if (!$g->vlan) {
            return ['error' => 'Primero activá el acceso remoto: hace falta saber qué VLAN agregar.', 'perfiles' => []];
        }

        // C-Data EPON no tiene perfiles de línea que preparar: la VLAN va en el
        // puerto PON. Leerlos daba "No se pudo leer el perfil" / "Sin leer".
        if (strtolower((string) $olt->brand) === 'cdata' && self::tecnologiaCdata($olt) === 'epon') {
            return [
                'perfiles' => [],
                'leido_en' => now()->toIso8601String(),
                'aviso'    => 'En EPON no hay perfiles de línea que preparar: la VLAN de gestión va en el puerto PON y se configura con «Volver a aplicar».',
                'resumen'  => ['listos' => 0, 'pendientes' => 0, 'no_soportado' => 0, 'sin_leer' => 0, 'equipos_pendientes' => 0],
            ];
        }

        $clave = "gestion:perfiles:{$oltId}:{$g->vlan}";

        if ($releer) {
            Cache::forget($clave);
        }

        if ($guardado = Cache::get($clave)) {
            return $guardado;
        }

        $dp = app(OltTelnetDispatcher::class);
        $lista = [];

        // Con el túnel cortándose de a ratos una lectura puede volver vacía: se
        // intenta otra vez antes de dar el perfil por no leído.
        $leer = function (string $metodo, array $datos = []) use ($dp, $oltId) {
            for ($intento = 0; $intento < 2; $intento++) {
                try {
                    if ($r = $dp->dispatch($oltId, $metodo, $datos)) {
                        return $r;
                    }
                } catch (\Throwable $e) {
                    Log::warning('[Gestión] Lectura de perfiles fallida', ['metodo' => $metodo, 'error' => $e->getMessage()]);
                }
            }

            return null;
        };

        $todos = $leer('perfilesDeLinea');

        if (!$todos) {
            return ['error' => 'La OLT no respondió al pedir la lista de perfiles. Suele ser el túnel: probá de nuevo en un momento.', 'perfiles' => []];
        }

        // También los que todavía no usa nadie: son los que la OLT aplica al
        // autorizar (el predeterminado) y los recién creados para una VLAN. Antes
        // se saltaban, y el aviso «preparalo en Perfiles de línea» mandaba a una
        // lista donde ese perfil no aparecía (pasó con el 10 y con el 109).
        foreach ($todos as $p) {
            $lista[] = $p + $this->estadoDePerfil($leer('perfilDeLinea', ['perfil' => (int) $p['id']]), (int) $g->vlan);
        }

        // Primero lo que hay que hacer, y dentro de eso los de más clientes.
        usort($lista, fn ($a, $b) => [$a['estado'] === 'listo', -$a['equipos']]
                                 <=> [$b['estado'] === 'listo', -$b['equipos']]);

        $cuantos = fn (string $estado) => count(array_filter($lista, fn ($p) => $p['estado'] === $estado));

        $r = [
            'perfiles' => $lista,
            'leido_en' => now()->toIso8601String(),
            'resumen'  => [
                'listos'       => $cuantos('listo'),
                'pendientes'   => $cuantos('pendiente'),
                'no_soportado' => $cuantos('no_soportado'),
                'sin_leer'     => $cuantos('sin_leer'),
                'equipos_pendientes' => array_sum(array_map(fn ($p) => $p['estado'] === 'listo' ? 0 : $p['equipos'], $lista)),
            ],
        ];

        // Una lectura incompleta no se guarda: la próxima vez se vuelve a
        // intentar en vez de mostrar media foto durante media hora.
        if ($r['resumen']['sin_leer'] === 0) {
            Cache::put($clave, $r, now()->addMinutes(30));
        }

        // Para el diagnóstico sí sirve la última, aunque esté incompleta.
        Cache::put("gestion:perfiles-ultima:{$oltId}:{$g->vlan}", $r, now()->addHours(6));

        return $r;
    }

    /**
     * Agrega la gestión a un perfil de línea.
     *
     * De a un perfil: al guardarlo la OLT les reenvía la configuración a todos
     * sus equipos, y el operador tiene que poder elegir cuándo le pasa eso a
     * cada grupo de clientes.
     *
     * @return array{ok:bool, estado:string, detalle:string}
     */
    public function prepararPerfil(int $oltId, int $perfil): array
    {
        $g   = $this->config();
        $olt = OltAdmin::where('id', $oltId)->where('company_id', $this->companyId)->first();

        if (!$olt || $this->soporte($olt)['nivel'] !== 'completo') {
            return ['ok' => false, 'estado' => 'no_soportado', 'detalle' => $olt ? $this->soporte($olt)['detalle'] : 'Esa OLT no es de tu empresa.'];
        }

        if (!$g->activa || !$g->vlan) {
            return ['ok' => false, 'estado' => 'error', 'detalle' => 'El acceso remoto no está activado.'];
        }

        try {
            $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'prepararPerfilDeLinea', [
                'perfil' => $perfil, 'vlan' => (int) $g->vlan,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'estado' => 'error', 'detalle' => \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage())];
        }

        Cache::forget("gestion:perfiles:{$oltId}:{$g->vlan}");
        Cache::forget("gestion:perfiles-ultima:{$oltId}:{$g->vlan}");

        return is_array($r) ? $r : ['ok' => false, 'estado' => 'error', 'detalle' => 'La OLT no respondió.'];
    }

    /** @return array{estado:string, detalle:string} */
    private function estadoDePerfil(?array $perfil, int $vlan): array
    {
        if (!$perfil) {
            return ['estado' => 'sin_leer', 'detalle' => 'No se pudo leer el perfil.'];
        }

        if ($perfil['modo'] !== 'VLAN') {
            return ['estado' => 'no_soportado', 'detalle' => 'Reparte el tráfico por ' . ($perfil['modo'] ?: 'otro criterio') . ', no por VLAN: hay que revisarlo a mano.'];
        }

        // Antes los dos casos daban el mismo texto y salía «por VLAN, no por
        // VLAN» en el perfil por defecto, que reparte por VLAN pero está vacío.
        if (!$perfil['gems']) {
            return ['estado' => 'no_soportado', 'detalle' => 'No tiene canales (GEM) configurados: hay que armarlo en la OLT antes de usarlo.'];
        }

        $vlans = [];

        foreach ($perfil['gems'] as $mapeos) {
            $vlans = array_merge($vlans, array_column($mapeos, 'vlan'));
        }

        $tieneVlan = in_array($vlan, $vlans, true);

        if ($tieneVlan && $perfil['tr069']) {
            return ['estado' => 'listo', 'detalle' => 'Deja salir la gestión.'];
        }

        $falta = array_filter([
            $tieneVlan ? null : "la VLAN {$vlan}",
            $perfil['tr069'] ? null : 'la gestión TR-069',
        ]);

        return ['estado' => 'pendiente', 'detalle' => 'Le falta ' . implode(' y ', $falta) . '.'];
    }

    // ── Diagnóstico ───────────────────────────────────────────────────────

    /**
     * Todo lo que tiene que estar bien para que un equipo llegue al TR-069,
     * revisado de verdad y contado en castellano.
     *
     * Del router y de la base se lee en el momento; de la OLT se usa lo último
     * leído, porque consultarla entera tarda y el túnel no siempre acompaña.
     *
     * @return array<string,mixed>
     */
    public function diagnostico(): array
    {
        $g = $this->config();
        $grupos = [];

        if (!$g->activa || !$g->vlan) {
            return [
                'nivel'   => 'apagado',
                'resumen' => 'El acceso remoto está apagado.',
                'grupos'  => [],
            ];
        }

        $grupos[] = ['titulo' => 'MikroTik', 'items' => $this->revisarRouter($g)];

        foreach (OltAdmin::where('company_id', $this->companyId)->orderBy('id')->get() as $olt) {
            // La pantalla publicada sólo ofrece preparar perfiles cuando esta
            // marca dice "huawei" (no la muestra: la usa para ese filtro). Las
            // OLT que se configuran solas se informan así para que C-Data GPON
            // también los ofrezca; la marca real va en marca_real.
            $grupos[] = [
                'titulo'     => $olt->name,
                'olt_id'     => (int) $olt->id,
                // Sólo las que tienen perfiles de línea que preparar (no C-Data EPON).
                'marca'      => $this->soporte($olt)['nivel'] === 'completo'
                    && !(strtolower((string) $olt->brand) === 'cdata' && self::tecnologiaCdata($olt) === 'epon')
                    ? 'huawei' : $olt->brand,
                'marca_real' => $olt->brand,
                'items'      => $this->revisarOlt($olt, $g),
            ];
        }

        $grupos[] = ['titulo' => 'Equipos', 'items' => $this->revisarEquipos($g)];

        $estados = [];

        foreach ($grupos as $grupo) {
            $estados = array_merge($estados, array_column($grupo['items'], 'estado'));
        }

        $nivel = in_array('error', $estados, true) ? 'error'
            : (array_intersect(['pendiente', 'aviso'], $estados) ? 'aviso' : 'ok');

        // La guía va primera y no cuenta para el semáforo: repite lo de abajo
        // en orden, para que el cliente sepa qué paso sigue.
        array_unshift($grupos, ['titulo' => 'Guía paso a paso', 'items' => $this->guia($g, $grupos)]);

        return [
            'nivel'   => $nivel,
            'resumen' => [
                'ok'    => 'Todo configurado y funcionando.',
                'aviso' => 'Funciona, pero quedan pasos por hacer.',
                'error' => 'Hay algo que impide que los equipos lleguen al TR-069.',
            ][$nivel],
            'grupos'  => $grupos,
        ];
    }

    /**
     * La guía del cliente: los pasos hechos en verde y sólo el siguiente como
     * pendiente, con su botón. Así se sabe en qué parte está y qué hacer.
     *
     * @param  list<array<string,mixed>>  $grupos  el diagnóstico ya armado
     * @return list<array<string,mixed>>
     */
    private function guia(GestionRemota $g, array $grupos): array
    {
        $total = 6;
        $items = [];
        $malos = fn (array $grupo) => array_values(array_filter($grupo['items'] ?? [], fn ($i) => $i['estado'] !== 'ok'));
        $hecho = function (int $n, string $titulo, string $detalle) use (&$items, $total) {
            $items[] = $this->item('ok', "Paso {$n} de {$total} · {$titulo}", $detalle);
        };
        $sigue = function (int $n, string $titulo, string $detalle, ?string $accion = null) use (&$items, $total) {
            $items[] = $this->item('pendiente', "👉 Paso {$n} de {$total} · {$titulo}", $detalle, $accion);

            return $items;
        };

        // 1. MikroTik
        $router = collect($grupos)->firstWhere('titulo', 'MikroTik') ?? ['items' => []];
        if ($falta = $malos($router)) {
            return $sigue(1, 'Red de gestión en el MikroTik',
                "Falta: {$falta[0]['titulo']} — {$falta[0]['detalle']} Entrá al asistente, revisá VLAN {$g->vlan}, red {$g->red} e interfaz, y pulsá «Volver a aplicar».", 'activar');
        }
        $hecho(1, 'Red de gestión en el MikroTik', "VLAN {$g->vlan}, red {$g->red}, DHCP con la dirección del TR-069 y aislamiento listos.");

        // 2. OLT
        foreach (collect($grupos)->filter(fn ($x) => isset($x['olt_id'])) as $olt) {
            // Las de marcas que no se configuran solas no traban la guía: su
            // aviso ya está en el diagnóstico y ningún botón lo resuelve.
            $modelo = OltAdmin::where('id', $olt['olt_id'])->where('company_id', $this->companyId)->first();

            if (!$modelo || $this->soporte($modelo)['nivel'] !== 'completo') {
                continue;
            }

            if ($falta = $malos($olt)) {
                return $sigue(2, "Preparar la OLT {$olt['titulo']}",
                    "Falta: {$falta[0]['titulo']} — {$falta[0]['detalle']} "
                    . (($falta[0]['accion'] ?? null) === 'perfiles'
                        ? 'Andá a la pestaña «Perfiles de línea» y pulsá «Preparar» (hacelo en un horario tranquilo: los clientes de ese perfil pueden tener un corte de segundos).'
                        : 'Elegí el puerto de subida de la OLT en el asistente y pulsá «Volver a aplicar».'),
                    $falta[0]['accion'] ?? 'activar');
            }
        }
        $hecho(2, 'OLT preparada', 'La VLAN pasa por la OLT, los perfiles de línea la llevan y los perfiles de gestión están creados. Ningún cliente se tocó.');

        // 3. VPN
        $tunel = \App\Models\VpnTunel::where('company_id', $this->companyId)->where('activo', true)->get()
            ->first(fn ($t) => in_array($g->red, $t->redes_remotas ?? [], true) || $t->virtualDe((string) $g->red));

        if (!$tunel) {
            return $sigue(3, 'Conectar la VPN',
                "La red {$g->red} no está en ningún túnel. Pulsá «Volver a aplicar» para sumarla y después, en OLT → VPN, «Ver script» y pegalo en el MikroTik.", 'activar');
        }

        if (!$tunel->conectado) {
            return $sigue(3, 'Conectar la VPN',
                "El túnel «{$tunel->nombre}» no está saludando. En OLT → VPN pulsá «Ver script» en ese túnel, copialo y pegalo completo en la terminal del MikroTik. En uno o dos minutos debe decir «conectado».");
        }
        $hecho(3, 'VPN conectada', "El túnel «{$tunel->nombre}» está conectado y lleva la red {$g->red}.");

        // 4-6. Equipos
        $olts = OltAdmin::where('company_id', $this->companyId)->get(['id', 'brand'])
            ->filter(fn ($o) => self::admiteGestion((string) $o->brand))->pluck('id');
        $totalOnts = OltOnt::whereIn('olt_id', $olts)->count();
        $conGestion = OltOnt::whereIn('olt_id', $olts)->whereNotNull('gestion_en')->count();

        try {
            $enAcs = count((new \App\Services\Acs\EquiposDelAcs($this->companyId))->lista());
        } catch (\Throwable) {
            $enAcs = 0;
        }

        $modelos = array_keys(Cache::get('cdata:modelos-sin-wan-por-olt', []));
        $url = (string) config('services.genieacs.url_equipos');

        if ($conGestion === 0 && $enAcs === 0) {
            $sigue(4, 'Probar con un solo equipo',
                'En OLT → Autorizadas elegí una ONT de prueba (mejor sin cliente) y pulsá «Dar acceso remoto». '
                . 'Si el modelo lo acepta, en uno o dos minutos pide IP de gestión y aparece en Equipos. '
                . 'Si no lo acepta, la plataforma la devuelve sola a su perfil (sin dejarla sin servicio) y te dice cómo configurarla.');
        } else {
            $hecho(4, 'Primer equipo gestionado', "{$enAcs} equipo(s) ya reportan al TR-069.");

            if ($totalOnts > 0 && $enAcs >= $totalOnts) {
                $hecho(5, 'Resto de los equipos', 'Todos los equipos de las OLT fueron sumados.');
                $hecho(6, 'Todos los equipos en el TR-069', "Los {$totalOnts} equipos reportan al TR-069.");
            } else {
            $sigue(5, 'Sumar el resto de los equipos',
                "{$enAcs} de {$totalOnts} equipos reportan al TR-069. Los modelos que aceptan la gestión por la OLT se suman con «Poner al día» "
                . '(cada equipo se corta unos 20 segundos: hacelo en un horario tranquilo). Los que no la aceptan se configuran en el equipo (ver abajo).',
                'al_dia');
            }
        }

        if ($modelos) {
            $items[] = $this->item('manual', 'Equipos que se configuran en su página web · ' . implode(', ', $modelos),
                'Estos modelos no aceptan que la OLT les cree la conexión de gestión. En cada uno, una sola vez: entrá a su página web (http://192.168.1.1), '
                . "Maintenance/Management → TR-069 Client: CWMP activado, ACS URL {$url}, Periodic Inform activado cada 300 s, sobre su conexión de internet. "
                . 'Guardá: en unos minutos aparece solo en Equipos y el contador de arriba sube. Sin corte para el cliente. '
                . 'Para los equipos nuevos, configuralo en bodega antes de instalarlos.');
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function revisarRouter(GestionRemota $g): array
    {
        $router = $this->router($g->router_id);

        if (!$router) {
            return [$this->item('error', 'Sin MikroTik', 'La empresa no tiene un MikroTik configurado.')];
        }

        try {
            $api = $this->conexion->conection($router->token);
        } catch (\Throwable $e) {
            return [$this->item('error', 'No se pudo entrar al MikroTik', $e->getMessage())];
        }

        $items = [];
        $nombre = "vlan{$g->vlan}-gestion";
        $leer = fn (string $cmd, string $campo, string $valor) => $api->query((new Query($cmd))->where($campo, $valor))->read();

        foreach (array_slice(self::interfacesDe((string) $g->interfaz), 1) as $extra) {
            $v = $leer('/interface/vlan/print', 'name', "{$nombre}-{$extra}")[0] ?? null;
            $items[] = $v && ($v['running'] ?? 'false') === 'true'
                ? $this->item('ok', "VLAN {$g->vlan} en {$extra}", 'Arriba y unida al bridge de gestión.')
                : $this->item('error', "VLAN {$g->vlan} en {$extra}", $v ? 'Creada, pero la interfaz no está en uso.' : 'No está creada en el MikroTik.', 'activar');
        }

        $vlan = $leer('/interface/vlan/print', 'name', $nombre)[0] ?? null;
        $items[] = !$vlan
            ? $this->item('error', "VLAN {$g->vlan}", 'No está creada en el MikroTik.', 'activar')
            : (($vlan['running'] ?? 'false') === 'true'
                ? $this->item('ok', "VLAN {$g->vlan}", "Arriba sobre {$vlan['interface']}.")
                : $this->item('error', "VLAN {$g->vlan}", "Creada sobre {$vlan['interface']}, pero esa interfaz no está en uso.", 'activar'));

        $dhcp = $leer('/ip/dhcp-server/print', 'name', 'dhcp-gestion-ont')[0] ?? null;
        $items[] = !$dhcp
            ? $this->item('error', 'DHCP de los equipos', 'No está creado.', 'activar')
            : (($dhcp['invalid'] ?? 'false') === 'true' || ($dhcp['disabled'] ?? 'false') === 'true'
                ? $this->item('error', 'DHCP de los equipos', 'Está creado pero no funciona.', 'activar')
                : $this->item('ok', 'DHCP de los equipos', "Reparte {$g->pool_desde} a {$g->pool_hasta}."));

        $opcion = $leer('/ip/dhcp-server/option/print', 'name', 'netplay-acs')[0] ?? null;
        $items[] = ($opcion['value'] ?? null) === $this->opcion43()
            ? $this->item('ok', 'Dirección del servidor TR-069', 'Viaja con la IP: ' . config('services.genieacs.cwmp_url'))
            : $this->item('error', 'Dirección del servidor TR-069', $opcion ? 'Apunta a otra dirección.' : 'No está cargada en el DHCP.', 'activar');

        $reglas = $api->query(new Query('/ip/firewall/filter/print'))->read();
        $posResp = $posAcs = $posNada = null;

        foreach ($reglas as $i => $r) {
            // Sólo cuentan las de la red actual y activas: las de una red vieja
            // se veían bien sin encerrar nada.
            if (($r['src-address'] ?? '') !== $g->red || ($r['disabled'] ?? 'false') === 'true') {
                continue;
            }

            $posResp ??= ($r['comment'] ?? '') === self::MARCA . ': respuestas' ? $i : null;
            $posAcs  ??= ($r['comment'] ?? '') === self::MARCA . ': al ACS' ? $i : null;
            $posNada ??= ($r['comment'] ?? '') === self::MARCA . ': nada mas' ? $i : null;
        }

        $items[] = match (true) {
            $posAcs === null || $posNada === null
                => $this->item('error', 'Aislamiento', 'Faltan las reglas que encierran la red de gestión.', 'activar'),
            $posAcs > $posNada
                => $this->item('error', 'Aislamiento', 'Las reglas están al revés: el bloqueo va antes que el paso al servidor.', 'activar'),
            $posResp === null || $posResp > $posNada
                => $this->item('error', 'Cambios al instante', 'Los equipos no pueden contestarle al servidor: los cambios esperan a que el equipo se reporte solo.', 'activar'),
            default
                => $this->item('ok', 'Aislamiento', 'La red de gestión sólo habla con el servidor TR-069 y le contesta cuando la llama.'),
        };

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function revisarOlt(OltAdmin $olt, GestionRemota $g): array
    {
        $soporte = $this->soporte($olt);

        if ($soporte['nivel'] !== 'completo') {
            return [$this->item($soporte['nivel'] === 'manual' ? 'manual' : 'aviso', $soporte['titulo'], $soporte['detalle'])];
        }

        $items = [];
        $uplink = ($g->uplinks ?? [])[(string) $olt->id] ?? ($g->uplinks ?? [])[$olt->id] ?? null;

        $items[] = $uplink
            ? $this->item('ok', 'VLAN en la OLT', "Pasa por el puerto de subida {$uplink}.")
            : $this->item('error', 'VLAN en la OLT', 'No pasa por ningún puerto de subida: los equipos piden IP y nadie les contesta.', 'activar');

        $perfilAcs = ($g->perfiles_acs ?? [])[(string) $olt->id] ?? null;

        if (strtolower((string) $olt->brand) === 'cdata' && self::tecnologiaCdata($olt) === 'epon') {
            $items[] = $perfilAcs
                ? $this->item('ok', 'Servidor TR-069', 'En EPON los equipos reciben la dirección ' . config('services.genieacs.cwmp_url') . ' por el DHCP de la red de gestión.')
                : $this->item('pendiente', 'Servidor TR-069', 'Falta revisar la OLT: pulsá «Volver a aplicar».', 'activar');
            $items[] = $this->item('ok', 'Perfiles de línea', 'En EPON no hay perfiles que preparar: la VLAN va en el puerto PON.');

            return $items;
        }

        $items[] = $perfilAcs
            ? $this->item('ok', 'Servidor TR-069 en la OLT', 'La OLT les manda a los equipos la dirección ' . config('services.genieacs.url_equipos') . ' y las credenciales.')
            : $this->item('pendiente', 'Servidor TR-069 en la OLT', 'Todavía no está creado: los equipos que no toman la dirección por DHCP no van a encender su TR-069.', 'activar');

        $perfiles = Cache::get("gestion:perfiles:{$olt->id}:{$g->vlan}") ?? Cache::get("gestion:perfiles-ultima:{$olt->id}:{$g->vlan}");

        if (!$perfiles) {
            $items[] = $this->item('pendiente', 'Perfiles de línea', 'Todavía no se revisaron.', 'perfiles');
        } else {
            $r = $perfiles['resumen'];
            $total = count($perfiles['perfiles']);

            $sinLeer = (int) ($r['sin_leer'] ?? 0);

            $items[] = $r['pendientes'] === 0 && $r['no_soportado'] === 0 && $sinLeer === 0
                ? $this->item('ok', 'Perfiles de línea', "Los {$total} perfiles en uso dejan salir la gestión.")
                : $this->item(
                    $r['listos'] === 0 ? 'error' : 'pendiente',
                    'Perfiles de línea',
                    "{$r['listos']} de {$total} listos."
                        . ($r['pendientes'] ? " Faltan {$r['pendientes']}." : '')
                        . ($r['no_soportado'] ? " {$r['no_soportado']} hay que revisarlos a mano." : '')
                        . ($sinLeer ? " {$sinLeer} no se pudieron leer." : '')
                        . " {$r['equipos_pendientes']} equipos todavía no pueden salir.",
                    'perfiles'
                );
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function revisarEquipos(GestionRemota $g): array
    {
        $estado = $this->estado()['equipos'];
        $items = [];

        $items[] = $estado['con_gestion'] >= $estado['total'] && $estado['total'] > 0
            ? $this->item('ok', 'Acceso dado', "Los {$estado['total']} equipos lo tienen.")
            : $this->item('pendiente', 'Acceso dado', "{$estado['con_gestion']} de {$estado['total']} equipos.", 'al_dia');

        // Cuántos pidieron IP de verdad: es la prueba de que el camino anda.
        try {
            $router = $this->router($g->router_id);
            $api = $this->conexion->conection($router->token);
            $conIp = 0;

            foreach ($api->query((new Query('/ip/dhcp-server/lease/print'))->where('server', 'dhcp-gestion-ont'))->read() as $l) {
                $conIp += ($l['status'] ?? '') === 'bound' ? 1 : 0;
            }

            $items[] = $conIp > 0
                ? $this->item('ok', 'Equipos con IP de gestión', $conIp === 1 ? 'Un equipo ya pidió y recibió su IP.' : "{$conIp} equipos ya pidieron y recibieron su IP.")
                : $this->item($estado['con_gestion'] > 0 ? 'error' : 'pendiente', 'Equipos con IP de gestión',
                    $estado['con_gestion'] > 0
                        ? 'Hay equipos con acceso dado pero ninguno pidió IP: revisá los perfiles de línea.'
                        : 'Todavía ninguno.');
        } catch (\Throwable $e) {
            $items[] = $this->item('aviso', 'Equipos con IP de gestión', 'No se pudo leer el DHCP del MikroTik.');
        }

        return $items;
    }

    /** @return array{estado:string, titulo:string, detalle:string, accion:?string} */
    private function item(string $estado, string $titulo, string $detalle, ?string $accion = null): array
    {
        return compact('estado', 'titulo', 'detalle', 'accion');
    }

    /** La dirección del servidor TR-069 como opción 43 del DHCP (TLV 1). */
    private function opcion43(): string
    {
        $url = (string) config('services.genieacs.cwmp_url');

        return '0x01' . str_pad(dechex(strlen($url)), 2, '0', STR_PAD_LEFT) . bin2hex($url);
    }

    // ── Por ONT ───────────────────────────────────────────────────────────

    /**
     * Le da acceso de gestión a una ONT: su service-port en la VLAN y su IP
     * por DHCP. Es lo que se corre al autorizar un equipo nuevo.
     *
     * @return array{ok:bool, detalle:string}
     */
    /**
     * @param callable(string):void|null $avance cuenta en qué paso va (ventana de tareas)
     */
    /** Las VLAN de servicio de la ONT según sus service-ports, sin la de gestión. @return list<int> */
    private static function vlansDeServicio(?OltOnt $ont, int $vlanGestion): array
    {
        $puertos = $ont?->service_ports;
        $puertos = is_string($puertos) ? (json_decode($puertos, true) ?: []) : (array) $puertos;

        return array_values(array_unique(array_filter(
            array_map(fn ($p) => (int) ($p['vlan'] ?? 0), $puertos),
            fn ($v) => $v > 0 && $v !== $vlanGestion
        )));
    }

    /** @param bool $limpiarAjenas equipo recién autorizado: se reemplazan las conexiones de otra VLAN */
    public function darAcceso(int $oltId, string $fsp, int $ontId, bool $reiniciarSiHaceFalta = false, ?callable $avance = null, bool $limpiarAjenas = false): array
    {
        $avance ??= fn (string $texto) => null;
        $g = $this->config();

        if (!$g->activa || !$g->vlan) {
            return ['ok' => false, 'detalle' => 'El acceso remoto no está activado.'];
        }

        $olt = OltAdmin::where('id', $oltId)->where('company_id', $this->companyId)->first();

        if (!$olt) {
            return ['ok' => false, 'detalle' => 'Esa OLT no es de tu empresa.'];
        }

        if (!self::admiteGestion((string) $olt->brand)) {
            return self::noAplica(
                'Esta OLT no admite dar la gestión desde acá: hay que configurarla en la OLT.',
                CompatibilidadDeOnt::queHacerAMano()
            );
        }

        // Si el equipo ya reporta al TR-069 por su propia conexión (lo trae
        // configurado o se lo cargaron a mano), la VLAN de gestión no hace
        // falta, y crearla sólo arriesga pisarle internet: los HG8145V5 la
        // ponen en el lugar 2 (JENNYFER_MARGARET, 0/0/3:16, ya estaba en el ACS
        // y se bloqueaba con "hay que configurar la gestión en el equipo").
        $avance('Buscando el equipo en el servidor TR-069…');
        $registrada = OltOnt::where('olt_id', $oltId)->where('fsp', $fsp)->where('ont_id', $ontId)->first();
        $enElAcs = $registrada ? $this->yaEnElAcs((string) $registrada->serial, $registrada) : null;

        if ($enElAcs) {
            $registrada->update(['gestion_en' => now()]);

            // Con el equipo en el TR-069, la clave de administración de la
            // empresa se pone y se confirma por ahí: desde entonces la
            // plataforma entra a su página con una clave conocida.
            if (!empty($this->equipoAcs['id']) && $g->onu_admin_clave) {
                $avance('Poniendo la clave de administración de la empresa…');
                $clave = (new \App\Services\Acs\ClaveDeOnuPorTr069($this->companyId))
                    ->asegurar((string) $this->equipoAcs['id'], (string) $g->onu_admin_clave);

                if (!($clave['omitido'] ?? false)) {
                    $enElAcs .= ' · ' . $clave['detalle'];
                }
            }

            return [
                'ok'             => true,
                'detalle'        => $enElAcs,
                'servidor_tr069' => true,
                'perfil'         => ['listo' => true, 'detalle' => 'No aplica: el equipo reporta por su propia conexión.'],
                'ya_en_acs'      => true,
            ];
        }

        if ($pisaria = $this->pisariaSuInternet($registrada?->serial)) {
            return self::noAplica($pisaria, 'Se arregla solo: al reiniciarse (un corte de luz, o reinicialo cuando no moleste) vuelve a reportar por su conexión de internet.');
        }

        // Antes de tocar la OLT: si este equipo no puede recibir la gestión
        // desde ella (un Huawei en una C-Data, por ejemplo), se dice ya y no se
        // intenta. Antes se quedaba "dando acceso" para terminar en un error
        // que no explicaba nada.
        $avance('Revisando que el equipo se pueda configurar desde la OLT…');
        $compatible = CompatibilidadDeOnt::evaluar($olt, $fsp, $ontId, $registrada?->serial);

        if ($compatible['puede'] === CompatibilidadDeOnt::NO) {
            return self::noAplica($compatible['motivo'], $compatible['que_hacer'], $compatible);
        }

        $avance('Configurando el acceso en la OLT…');

        // El número de service-port puede estar tomado por otro equipo sin que
        // la plataforma lo sepa (los pone también quien entra por consola). Se
        // prueba, se lee a quién quedó y, si no es este equipo, se va al
        // siguiente en vez de dar por bueno algo que no existe.
        $evitar = [];
        $r = null;

        // Dos intentos y no más: cada uno son ocho comandos contra la OLT y
        // del otro lado hay alguien esperando la respuesta en el navegador.
        // Una vuelta más si la consola la dio por "no en línea" pero por SNMP
        // está en línea: recién autorizada, la OLT tarda en dejarla configurar.
        for ($vuelta = 0; $vuelta < 2; $vuelta++) {
            for ($intento = 0; $intento < 2; $intento++) {
                $numero = $this->servicePortLibre($oltId, $evitar);

                try {
                    $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'darGestionAOnt', [
                        'fsp' => $fsp, 'ont_id' => $ontId, 'vlan' => (int) $g->vlan,
                        'service_port' => $numero,
                        // Recién autorizada: las conexiones de otra VLAN se reemplazan.
                        'vlans_cliente' => $limpiarAjenas ? self::vlansDeServicio($registrada, (int) $g->vlan) : [],
                        'pisar_ajenas'  => $limpiarAjenas,
                    ]);
                } catch (\Throwable $e) {
                    return ['ok' => false, 'detalle' => \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage())];
                }

                if ($r['sp_ok'] ?? false) {
                    break;
                }

                $evitar[] = $numero;
            }

            if (($r['omitido'] ?? null) !== 'apagada') {
                break;
            }

            // "La ONT no está en línea (o se está registrando)" se decía aunque
            // la ONT estuviera navegando: se mira el estado real por SNMP.
            $vivo = $this->estadoReal($olt, $fsp, $ontId);

            if (($vivo['status'] ?? null) !== 'online') {
                $r['detalle'] = ($vivo['status'] ?? null) === 'offline'
                    ? 'La ONT está apagada o sin señal: se le da el acceso cuando vuelva a conectarse.'
                    : $r['detalle'];

                break;
            }

            if ($vuelta === 0) {
                $avance('La OLT todavía la está registrando: se vuelve a intentar en unos segundos…');
                sleep(20);

                continue;
            }

            $r['detalle'] = 'La ONT está en línea' . (isset($vivo['potencia']) ? " ({$vivo['potencia']} dBm)" : '')
                . ', pero la OLT todavía no la deja configurar (recién autorizada, se está registrando). Volvé a darle acceso remoto en un par de minutos.';
        }

        // La OLT probó y el equipo no creó la conexión (el driver lo anota para
        // no insistir con los iguales): no es una falla, es que así no se puede.
        if (($r['omitido'] ?? null) === 'modelo_sin_wan') {
            return self::noAplica($r['detalle'] ?? 'Este equipo no acepta que la OLT le cree la conexión de gestión.', CompatibilidadDeOnt::queHacerAMano());
        }

        // EPON: la OLT tarda en mostrar la IP de una WAN por DHCP. Se confirma
        // en el DHCP del router (la MAC de la WAN es la de la ONT con el último
        // byte distinto); si tampoco está, se borra la conexión creada.
        // EPON: si al crear la conexión se le cayó el internet al cliente, se
        // deshace aunque haya tomado IP.
        if ($r['internet_caido'] ?? false) {
            try {
                $q = app(OltTelnetDispatcher::class)->dispatch($oltId, 'quitarGestionEpon', ['fsp' => $fsp, 'ont_id' => $ontId]);
            } catch (\Throwable $e) {
                $q = null;
            }

            return ['ok' => false, 'detalle' => $r['detalle'] . ' '
                . (($q['borrada'] ?? false) ? 'Se borró la conexión de gestión. ' : '⚠️ La conexión «gestion» no se pudo borrar: revisala. ')
                . (($q['internet_ok'] ?? false) ? 'Su internet volvió a conectarse.' : '⚠️ Revisá el internet del equipo ya.')];
        }

        if ($r['sin_confirmar'] ?? false) {
            $avance('Confirmando la IP de gestión en el router…');
            // Una conexión recién creada tiene que haber pedido IP recién; una
            // que ya existía puede tener su IP desde hace horas.
            $existente = (bool) ($r['existente'] ?? false);
            $ip = $this->ipDeGestionEnRouter((string) ($registrada?->serial ?? ''), $existente ? 10 : 45, !$existente);

            if (!$ip && $existente) {
                return ['ok' => false, 'detalle' => 'La ONT tiene la conexión de gestión pero no se encontró su IP en el DHCP del router: no se tocó. Revisá que la VLAN llegue a esta OLT o reiniciá el equipo.'];
            }

            if ($ip) {
                $r = ['ok' => true, 'por_perfil' => true, 'ip' => $ip,
                    'detalle' => "Conexión de gestión creada: IP {$ip} en la VLAN {$g->vlan}. La dirección del TR-069 le llega por DHCP."];
            } else {
                try {
                    $q = app(OltTelnetDispatcher::class)->dispatch($oltId, 'quitarGestionEpon', ['fsp' => $fsp, 'ont_id' => $ontId]);
                } catch (\Throwable $e) {
                    $q = null;
                }

                return ['ok' => false, 'detalle' => "La ONT no pidió IP de gestión en la VLAN {$g->vlan}. Revisá que la VLAN llegue del MikroTik a esta OLT. "
                    . (($q['borrada'] ?? false) ? 'Se borró la conexión creada. ' : '⚠️ La conexión «gestion» no se pudo borrar: revisala. ')
                    . (($q['internet_ok'] ?? false) ? 'Su internet sigue conectado.' : '⚠️ Revisá el internet del equipo.')];
            }
        }

        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'detalle' => $r['detalle'] ?? 'La OLT no aceptó la configuración.'];
        }

        // C-Data GPON: la gestión va en el perfil (TR-069 + WAN) y el driver ya
        // comprobó que la ONT lo aceptó. No hay service-port ni servidor por equipo.
        if ($r['por_perfil'] ?? false) {
            // EPON: con la IP de gestión la plataforma llega a la página del
            // equipo; algunos (C-Data FD5xx) ignoran la dirección del ACS que
            // llega por DHCP y hay que encender su TR-069 ahí.
            if (!empty($r['ip'])) {
                $avance('Encendiendo el TR-069 en el equipo…');

                // La OLT EPON muestra mal la IP de una WAN por DHCP (10.30.0.35
                // aparece como 10.30.0.0): la real se toma del DHCP del router.
                $ipReal = $this->ipDeGestionEnRouter((string) ($registrada?->serial ?? ''), 20, false)
                    ?? (preg_match('/\.0$/', (string) $r['ip']) ? null : (string) $r['ip']);

                // Por la IP con la que se llega a ESTE equipo por el túnel de
                // la empresa (virtual si la red está traducida). Si esa red la
                // lleva el túnel de otra empresa, no se entra.
                $ipReal = \App\Services\Vpn\ServidorVpn::ipParaEmpresa($ipReal, $this->companyId, true);

                $tr = $ipReal
                    ? (new \App\Services\Acs\Tr069EnPaginaDeOnu($ipReal))->encender(
                        [[(string) $g->onu_admin_usuario, (string) $g->onu_admin_clave]],
                        (string) config('services.genieacs.url_equipos'),
                        300,
                        $g->acs_usuario ?: null,
                        $g->acs_clave ?: null,
                    )
                    : ['ok' => false, 'detalle' => 'No se encontró la IP de gestión del equipo en el router para encender su TR-069.'];

                $r['detalle'] .= ' · ' . $tr['detalle'];

                // Sin el TR-069 encendido el equipo no se reporta: no cuenta
                // como hecho, así «Poner al día» lo vuelve a intentar.
                if (!($tr['ok'] ?? false)) {
                    $registrada?->update(['gestion_en' => null]);

                    return ['ok' => false, 'detalle' => 'Quedó a medias: ' . $r['detalle']];
                }
            }

            $registrada?->update(['gestion_en' => now()]);

            return [
                'ok'             => true,
                'detalle'        => $r['detalle'],
                'servidor_tr069' => true,
                'perfil'         => ['listo' => true, 'detalle' => 'Va en el mult-srv-profile de gestión.'],
            ];
        }

        $ont = OltOnt::where('olt_id', $oltId)->where('fsp', $fsp)->where('ont_id', $ontId)->first();

        // Cómo le llega al equipo el servidor TR-069 depende de su marca:
        //  - Huawei: la OLT le manda dirección y credenciales y lo enciende solo.
        //  - El resto (C-Data, SDMC, OEMT…): también por la OLT, por OMCI, pero
        //    lo toman recién al reiniciarse (probado con C-Data: CARMEN se
        //    registró a los 2 min del reinicio). Si el equipo no entiende esa
        //    orden de la OLT no se rompe nada: simplemente no aparece en el ACS.
        $marca = self::marcaDelEquipo((string) ($ont?->serial ?? ''));
        $avance('Asignando el servidor TR-069…');

        $servidor = $marca === 'huawei'
            ? $this->asignarServidorTr069($oltId, $fsp, $ontId)
            : $this->servidorTr069PorOmci($oltId, $fsp, $ontId, $reiniciarSiHaceFalta, self::nombreDeMarca($marca));

        // El equipo ya tiene TR-069 en su conexión de internet: no se creó la
        // VLAN de gestión (no hace falta) y basta con el servidor asignado.
        if (($r['omitido'] ?? null) === 'tr069_en_internet') {
            if ($ont && $servidor['ok']) {
                $ont->update(['gestion_en' => now()]);
            }

            return [
                'ok'      => $servidor['ok'],
                'detalle' => $r['detalle'] . ' · ' . $servidor['detalle'],
                'servidor_tr069' => $servidor['ok'],
                'perfil'  => ['listo' => true, 'detalle' => 'No aplica: el TR-069 va por su conexión de internet.'],
            ];
        }

        // Sin la VLAN de gestión en su perfil de línea la ONT descarta ese
        // tráfico aunque todo lo demás esté bien. Antes no se miraba y el
        // proceso decía "listo" con el equipo sin poder salir.
        $avance('Ajustando los últimos detalles…');
        $perfil = $this->perfilListoDe($oltId, $fsp, $ontId, (int) $g->vlan);

        $completo = $servidor['ok'] && $perfil['listo'];

        if ($ont) {
            // Queda anotado para no volver a repartir el mismo número, que es
            // justamente lo que hace que la OLT rechace el comando.
            // Uno solo por VLAN: si quedó anotado uno viejo de un intento anterior,
            // se reemplaza por el que la OLT confirmó.
            $puertos = collect($ont->service_ports ?? [])
                ->reject(fn ($p) => (int) ($p['index'] ?? 0) === (int) $r['sp'] || (int) ($p['vlan'] ?? 0) === (int) $g->vlan)
                ->values()->all();
            $puertos[] = ['index' => (int) $r['sp'], 'vlan' => (int) $g->vlan];

            // "Con acceso" sólo si quedó completo: si no, la puesta al día lo
            // saltearía para siempre creyendo que ya estaba.
            $ont->update(['service_ports' => $puertos, 'gestion_en' => $completo ? now() : null]);
        }

        $falta = array_values(array_filter([
            $perfil['listo'] ? null : $perfil['detalle'],
            $servidor['ok'] ? null : $servidor['detalle'],
        ]));

        return [
            'ok'      => $completo,
            'detalle' => $completo
                ? ($r['detalle'] ?? 'Listo') . ' · ' . $servidor['detalle']
                : 'Quedó a medias: ' . implode(' · ', $falta),
            'servidor_tr069' => $servidor['ok'],
            'perfil'  => $perfil,
        ];
    }

    /**
     * Gestión temporal para un cambio de conexión (CambioDeConexion): la ONT
     * lleva el TR-069 en su conexión de internet, que es justo la que hay que
     * reemplazar, y por esa IP el ACS no le puede avisar al momento. Se le
     * crea desde la OLT la conexión de gestión (ont ipconfig + service-port en
     * la VLAN de gestión) aunque ya tenga TR-069; nunca en el lugar 2 si ahí
     * está su internet (lo borraría). Terminado el cambio se quita con
     * quitarGestionDeOnt, para que un reinicio de la OLT no la vuelva a crear
     * encima de su internet.
     *
     * Sólo OLT Huawei.
     *
     * @return array{ok:bool, detalle:string, sp?:?int, creo_algo?:bool}
     */
    public function darGestionTemporal(int $oltId, string $fsp, int $ontId): array
    {
        $g = $this->config();

        if (!$g->activa || !$g->vlan) {
            return ['ok' => false, 'detalle' => 'El acceso remoto de la empresa está apagado.'];
        }

        $olt = OltAdmin::where('id', $oltId)->where('company_id', $this->companyId)->first();

        if (!$olt || strtolower((string) $olt->brand) !== 'huawei') {
            return ['ok' => false, 'detalle' => 'La gestión temporal sólo se da en OLT Huawei.'];
        }

        $serial = OltOnt::where('olt_id', $oltId)->where('fsp', $fsp)->where('ont_id', $ontId)->value('serial');

        if ($pisaria = $this->pisariaSuInternet($serial)) {
            return ['ok' => false, 'detalle' => $pisaria];
        }

        $r = null;
        $evitar = [];

        // Si el número de service-port estaba tomado por otro, se prueba el siguiente.
        for ($intento = 0; $intento < 2; $intento++) {
            $numero = $this->servicePortLibre($oltId, $evitar);

            try {
                $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'darGestionAOnt', [
                    'fsp' => $fsp, 'ont_id' => $ontId, 'vlan' => (int) $g->vlan, 'service_port' => $numero,
                    'vlans_cliente' => [], 'pisar_ajenas' => false, 'aunque_tenga_tr069' => true,
                ]);
            } catch (\Throwable $e) {
                return ['ok' => false, 'detalle' => \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage()), 'creo_algo' => true];
            }

            if (($r['sp_ok'] ?? false) || !empty($r['omitido'])) {
                break;
            }

            $evitar[] = $numero;
        }

        // El proceso de la OLT con el código anterior no conoce la opción y
        // contesta que no hace falta: no se creó nada.
        if (($r['omitido'] ?? null) === 'tr069_en_internet') {
            return ['ok' => false, 'detalle' => 'La OLT no creó la gestión temporal (su proceso de conexión es de antes de esta versión: hay que reiniciarlo).'];
        }

        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'detalle' => $r['detalle'] ?? 'La OLT no aceptó la conexión de gestión.',
                // "lugar_ocupado" y compañía: la OLT no tocó nada.
                'creo_algo' => empty($r['omitido']) && ($r['sp_ok'] ?? false)];
        }

        $ont = OltOnt::where('olt_id', $oltId)->where('fsp', $fsp)->where('ont_id', $ontId)->first();

        if ($ont && !empty($r['sp'])) {
            $puertos = collect($ont->service_ports ?? [])
                ->reject(fn ($p) => (int) ($p['index'] ?? 0) === (int) $r['sp'] || (int) ($p['vlan'] ?? 0) === (int) $g->vlan)
                ->values()->all();
            $puertos[] = ['index' => (int) $r['sp'], 'vlan' => (int) $g->vlan];
            $ont->update(['service_ports' => $puertos]);
        }

        return ['ok' => true, 'sp' => $r['sp'] ?? null, 'detalle' => 'Gestión temporal creada en la OLT: ' . ($r['detalle'] ?? "VLAN {$g->vlan}")];
    }

    /**
     * El equipo no se puede configurar desde la OLT: estado final, con el
     * motivo y qué hacer, para que la pantalla no quede "dando acceso".
     *
     * @return array{ok:false, no_aplica:true, detalle:string, motivo:string, que_hacer:?string}
     */
    private static function noAplica(string $motivo, ?string $queHacer, array $compatibilidad = []): array
    {
        return [
            'ok'        => false,
            'no_aplica' => true,
            'detalle'   => trim($motivo . ($queHacer ? ' ' . $queHacer : '')),
            'motivo'    => $motivo,
            'que_hacer' => $queHacer,
        ] + ($compatibilidad ? ['compatibilidad' => $compatibilidad] : []);
    }

    /** Cómo está la ONT ahora por SNMP; vacío si no se pudo leer. */
    private function estadoReal(OltAdmin $olt, string $fsp, int $ontId): array
    {
        try {
            $vivo = \App\Services\Olt\EstadoDeUnaOnt::de($olt, $fsp, $ontId, true);

            return empty($vivo['error']) ? $vivo : [];
        } catch (\Throwable) {
            return [];
        }
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

        if (in_array($red, $redes, true) || $tunel->virtualDe($red)) {
            $comoSeVe = $tunel->virtualDe($red);

            return ['paso' => 'Ruta en la VPN', 'ok' => true, 'detalle' => "Ya estaba: {$red}" . ($comoSeVe ? " (como {$comoSeVe})" : '') . " por «{$tunel->nombre}»"];
        }

        // Si otra empresa ya usa esa red en la VPN, se publica traducida: agregarla
        // tal cual le quitaba la ruta a la otra empresa.
        try {
            [$redes, $traducciones] = \App\Services\Vpn\ServidorVpn::resolverChoques(
                array_values(array_merge($redes, [$red])), (int) $tunel->company_id, $tunel->id, $tunel->traducciones ?? []
            );
            \App\Services\Vpn\ServidorVpn::verificarRedesLibres($redes, $tunel->id);
        } catch (\Throwable $e) {
            return ['paso' => 'Ruta en la VPN', 'ok' => false, 'detalle' => 'No se agregó: ' . $e->getMessage()];
        }

        $tunel->update(['redes_remotas' => $redes] + ($traducciones || $tunel->traducciones ? ['traducciones' => $traducciones ?: null] : []));

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

    /**
     * El servidor TR-069 para un equipo que no es Huawei (C-Data, SDMC, OEMT…).
     *
     * Se le asigna por la OLT igual que a un Huawei, pero el equipo no lo
     * aplica hasta reiniciarse. Un equipo recién autorizado todavía no le da
     * servicio a nadie, así que se reinicia solo; a un cliente que ya navega no
     * se le corta internet sin avisar: queda dicho que falta el reinicio.
     *
     * Con C-Data está probado; con las demás marcas depende de que el equipo
     * acepte la orden de la OLT, y se dice cómo comprobarlo.
     *
     * @return array{ok:bool, detalle:string, requiere_reinicio?:bool}
     */
    private function servidorTr069PorOmci(int $oltId, string $fsp, int $ontId, bool $reiniciar, string $nombre): array
    {
        $asignado = $this->asignarServidorTr069($oltId, $fsp, $ontId);

        if (!$asignado['ok']) {
            return $asignado;
        }

        $probado = $nombre === 'C-Data';
        $comprobar = $probado ? '' : ' Si a los 5 minutos no aparece en Router TR-069, ese modelo no toma la configuración por la OLT y hay que cargarla en el equipo.';

        if (!$reiniciar) {
            return [
                'ok'      => true,
                'detalle' => $asignado['detalle'] . " Equipo {$nombre}: lo aplica al reiniciarse; reinicialo cuando no moleste al cliente." . $comprobar,
                'requiere_reinicio' => true,
            ];
        }

        try {
            $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'reiniciarOnt', ['fsp' => $fsp, 'ont_id' => $ontId]);
        } catch (\Throwable $e) {
            return ['ok' => true, 'detalle' => $asignado['detalle'] . ' No se pudo reiniciar: ' . \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage()), 'requiere_reinicio' => true];
        }

        return ($r['ok'] ?? false)
            ? ['ok' => true, 'detalle' => $asignado['detalle'] . " Equipo {$nombre} reiniciado para que lo aplique." . $comprobar]
            : ['ok' => true, 'detalle' => $asignado['detalle'] . ' ' . ($r['detalle'] ?? 'No se pudo reiniciar.'), 'requiere_reinicio' => true];
    }

    /** Sin reportar en este tiempo, un equipo del ACS ya no cuenta como gestionado. */
    private const REPORTE_VIGENTE_DIAS = 7;

    /**
     * Si la ONT ya está en el servidor TR-069 y reportó hace poco, lo dice en
     * palabras; si no, null y se sigue por la OLT.
     *
     * El reporte tiene que ser posterior al alta: al reautorizar una ONT queda
     * de cero y pierde su WAN de gestión, pero su último reporte sigue en el
     * ACS. Con PRUEBA_TR (0/0/9:0) se dio por gestionada con un reporte de seis
     * minutos antes y quedó sin ninguna conexión.
     */
    /** El equipo del ACS que encontró yaEnElAcs(), para seguir trabajando con él. */
    private ?array $equipoAcs = null;

    private function yaEnElAcs(string $serial, ?OltOnt $ont = null): ?string
    {
        if (trim($serial) === '') {
            return null;
        }

        try {
            $buscado = \App\Services\Acs\EquiposDelAcs::serial($serial);
            // Por serial, o por la ONT con la que la plataforma ya lo emparejó
            // (las C-Data EPON se registran en el ACS con otro serial).
            $equipo = collect((new \App\Services\Acs\EquiposDelAcs($this->companyId))->lista())
                ->first(fn ($e) => \App\Services\Acs\EquiposDelAcs::serial((string) ($e['serial'] ?? '')) === $buscado
                    || ($ont && ($e['ont']['fsp'] ?? null) === $ont->fsp && (int) ($e['ont']['ont_id'] ?? -1) === (int) $ont->ont_id
                        // 0/0/1:36 existe en cada OLT C-Data: sin mirar la OLT se confundían.
                        && ($e['ont']['olt'] ?? null) === OltAdmin::where('id', $ont->olt_id)->value('name')));
            $this->equipoAcs = $equipo;
        } catch (\Throwable $e) {
            // Sin ACS para consultar se sigue como siempre.
            return null;
        }

        $ultimo = $equipo['ultimo_reporte'] ?? null;

        if (!$ultimo || \Carbon\Carbon::parse($ultimo)->lt(now()->subDays(self::REPORTE_VIGENTE_DIAS))) {
            return null;
        }

        // Dos minutos de margen: el alta y el reporte pueden cruzarse.
        $alta = collect([$ont?->created_at, $ont?->synced_at, $ont?->updated_at])
            ->filter()->max();

        if ($alta && \Carbon\Carbon::parse($ultimo)->lt(\Carbon\Carbon::parse($alta)->subMinutes(2))) {
            return null;
        }

        $hace = \Carbon\Carbon::parse($ultimo)->locale('es')->diffForHumans();

        return "El equipo ya está en el servidor TR-069 (último reporte {$hace}) por su propia conexión: no se tocó la OLT. "
            . 'Si un cambio tarda en aplicarse es porque el servidor no llega a su IP y espera a que el equipo vuelva a reportar.';
    }

    /** Cómo se le dice a la marca en pantalla. */
    public static function nombreDeMarca(string $marca): string
    {
        return CompatibilidadDeOnt::nombreDeMarca($marca);
    }

    // ── Marca del equipo ──────────────────────────────────────────────────

    /**
     * La marca del equipo por el prefijo de su serial GPON (HWTC, CDTC…). La
     * tabla de prefijos está en CompatibilidadDeOnt; lo que no está ahí vuelve
     * en minúsculas, como antes.
     */
    public static function marcaDelEquipo(string $serial): string
    {
        $vendor = CompatibilidadDeOnt::prefijo($serial);

        return CompatibilidadDeOnt::marcaPorPrefijo($vendor) ?? strtolower($vendor);
    }

    /**
     * Reinicia un equipo desde la OLT. Le corta internet un minuto al cliente:
     * se hace a pedido del operador, nunca solo sobre alguien que ya navega.
     *
     * @return array{ok:bool, detalle:string}
     */
    /**
     * Si darle la gestión desde la OLT le borraría la conexión de internet,
     * el motivo; si no, null.
     *
     * Un Huawei al que la OLT le vuelve a poner la gestión ("ont ipconfig")
     * borra las conexiones que se le crearon por TR-069, esté donde esté la
     * de internet: el 18-09 PRUEBA_TR (lugar 1, PPPoE) y LILIANA_GIL (lugar
     * 1, IP fija) quedaron sin internet hasta que se les volvió a crear. Se
     * mira lo último que el equipo informó al ACS, aunque haga días que no
     * reporta: justamente a los que no reportan es a los que se les quiere dar.
     */
    private function pisariaSuInternet(?string $serial): ?string
    {
        if (!$serial) {
            return null;
        }

        try {
            $acs = GenieAcs::deEmpresa($this->companyId);
            $id = \App\Services\Red\CambioDeConexion::buscarEnElAcs($acs, $serial);
            $d = $id ? $acs->dispositivo($id) : null;
        } catch (\Throwable) {
            return null;
        }

        if (!$d || ($d['_deviceId']['_OUI'] ?? '') !== '00259E') {
            return null;
        }

        $vlanGestion = (int) ($this->config()->vlan ?: 0);
        $internet = collect((new AprovisionamientoDeOnt($this->companyId))->conexiones($d))
            ->first(fn ($c) => $c['vlan'] !== $vlanGestion && str_contains($c['servicios'], 'INTERNET'));

        if (!$internet) {
            return null;
        }

        // Lo del ACS puede ser viejo (ELVIRA_JIMENEZ, 19-09: se reinició, perdió
        // su conexión y lo guardado de las 11:42 la seguía mostrando). Si esa IP
        // no contesta desde el router, el cliente ya no tiene servicio y darle
        // la gestión no le quita nada: al contrario, es lo que permite
        // devolvérselo.
        $ip = (string) AprovisionamientoDeOnt::v($d, "{$internet['ruta']}.ExternalIPAddress");

        if ($this->contestaDesdeElRouter($serial, $ip) === false) {
            Log::info('[GestionRemota] Gestión permitida: la conexión que muestra el ACS no contesta', ['serial' => $serial, 'ip' => $ip]);

            return null;
        }

        return "No se le da la gestión desde la OLT: este Huawei tiene su conexión de internet configurada en el propio equipo ({$internet['nombre']}) y al recibir la gestión de la OLT la borra: el cliente quedaría sin servicio.";
    }

    /**
     * ¿La IP de la conexión del cliente contesta un ping desde su router?
     * null si no se pudo saber (sin IP, sin router o sin conexión con él): en
     * la duda se sigue cuidando la conexión.
     */
    private function contestaDesdeElRouter(string $serial, string $ip): ?bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP) || $ip === '0.0.0.0') {
            return null;
        }

        $userId = OltOnt::whereHas('olt', fn ($q) => $q->where('company_id', $this->companyId))
            ->where('serial', $serial)->whereNotNull('user_data_id')->latest('updated_at')->value('user_data_id');

        try {
            $api = $userId ? (new AprovisionamientoDeOnt($this->companyId))->routerDelCliente((int) $userId) : null;

            if (!$api) {
                return null;
            }

            $respuestas = collect($api->query((new Query('/ping'))->equal('address', $ip)->equal('count', '3'))->read());
        } catch (\Throwable) {
            return null;
        }

        return $respuestas->contains(fn ($r) => isset($r['time']) || (int) ($r['received'] ?? 0) > 0);
    }

    public function reiniciarEquipo(int $oltId, string $fsp, int $ontId): array
    {
        $olt = OltAdmin::where('id', $oltId)->where('company_id', $this->companyId)->first();

        if (!$olt || !self::admiteReinicio((string) $olt->brand)) {
            return ['ok' => false, 'detalle' => $olt ? 'Esta OLT no permite reiniciar equipos desde acá.' : 'Esa OLT no es de tu empresa.'];
        }

        try {
            $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'reiniciarOnt', ['fsp' => $fsp, 'ont_id' => $ontId]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detalle' => \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage())];
        }

        return ['ok' => (bool) ($r['ok'] ?? false), 'detalle' => $r['detalle'] ?? 'La OLT no respondió.'];
    }

    // ── Servidor TR-069 en la OLT ─────────────────────────────────────────

    /**
     * Deja creado en la OLT el perfil de servidor TR-069: dirección y
     * credenciales del ACS. Uno por OLT, compartido por todos sus equipos.
     *
     * La OLT no deja modificar un perfil con equipos asignados, así que nace
     * completo. Si el número ya está ocupado se prueba el siguiente; si ya es
     * nuestro (misma dirección y usuario) se reutiliza.
     *
     * @return array{paso:string, ok:bool, detalle:string}
     */
    public function asegurarServidorTr069(int $oltId): array
    {
        $g   = $this->config();
        $olt = OltAdmin::where('id', $oltId)->where('company_id', $this->companyId)->first();

        if (!$olt || $this->soporte($olt)['nivel'] !== 'completo') {
            return ['paso' => 'Servidor TR-069 en la OLT', 'ok' => true, 'detalle' => 'Esta OLT no lo permite: se configura en cada equipo.'];
        }

        // C-Data GPON: el servidor TR-069 va en perfiles (TR-069 + WAN + copia de
        // cada mult-srv-profile). Se revisa siempre: es idempotente y no toca
        // a ningún equipo.
        if (strtolower((string) $olt->brand) === 'cdata') {
            if (!$g->acs_usuario || !$g->acs_clave) {
                $g->fill(['acs_usuario' => 'netplay-acs', 'acs_clave' => \Illuminate\Support\Str::random(24)])->save();
            }

            try {
                $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'prepararGestionPorPerfil', [
                    'vlan' => (int) $g->vlan, 'url' => (string) config('services.genieacs.url_equipos'),
                    'usuario' => $g->acs_usuario, 'clave' => $g->acs_clave,
                ]);
            } catch (\Throwable $e) {
                return ['paso' => 'Perfiles de gestión en la OLT', 'ok' => false, 'detalle' => \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage())];
            }

            if ($r['ok'] ?? false) {
                $perfiles = $g->perfiles_acs ?? [];
                // EPON no tiene perfil TR-069: la dirección va por DHCP.
                $perfiles[(string) $oltId] = $r['tr069'] !== null ? (int) $r['tr069'] : 'dhcp';
                $g->perfiles_acs = $perfiles;
                $g->save();
            }

            return ['paso' => 'Perfiles de gestión en la OLT', 'ok' => (bool) ($r['ok'] ?? false), 'detalle' => $r['detalle'] ?? 'sin detalle'];
        }

        if ($guardado = ($g->perfiles_acs ?? [])[(string) $oltId] ?? null) {
            // Se comprueba en la OLT: si cambió la dirección del servidor o se
            // reseteó la OLT, se crea uno nuevo y los equipos vuelven a recibirla.
            try {
                $vigente = app(OltTelnetDispatcher::class)->dispatch($oltId, 'servidorTr069Vigente', [
                    'perfil' => (int) $guardado, 'url' => (string) config('services.genieacs.url_equipos'),
                ]);
            } catch (\Throwable) {
                $vigente = true; // sin poder mirar, no se rehace nada
            }

            if ($vigente !== false) {
                return ['paso' => 'Servidor TR-069 en la OLT', 'ok' => true, 'detalle' => "Ya estaba creado (perfil {$guardado})."];
            }

            $perfiles = $g->perfiles_acs;
            unset($perfiles[(string) $oltId]);
            $g->perfiles_acs = $perfiles;
            $g->save();

            // Los equipos de esa OLT tenían la dirección vieja: se les vuelve a dar.
            OltOnt::where('olt_id', $oltId)->whereNotNull('gestion_en')->update(['gestion_en' => null]);
        }

        if (!$g->acs_usuario || !$g->acs_clave) {
            $g->fill(['acs_usuario' => 'netplay-acs', 'acs_clave' => \Illuminate\Support\Str::random(24)])->save();
        }

        $url = (string) config('services.genieacs.url_equipos');
        $ultimo = '';

        for ($perfil = 2; $perfil <= 16; $perfil++) {
            try {
                $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'crearServidorTr069', [
                    'perfil' => $perfil, 'nombre' => "netplay-acs-{$perfil}", 'url' => $url,
                    'usuario' => $g->acs_usuario, 'clave' => $g->acs_clave,
                ]);
            } catch (\Throwable $e) {
                return ['paso' => 'Servidor TR-069 en la OLT', 'ok' => false, 'detalle' => \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage())];
            }

            if ($r['ok'] ?? false) {
                // Por clave y no con array_merge: con claves numéricas ("5")
                // renumera y el perfil quedaba guardado sin su OLT.
                $perfiles = $g->perfiles_acs ?? [];
                $perfiles[(string) $oltId] = $perfil;
                $g->perfiles_acs = $perfiles;
                $g->save();

                return ['paso' => 'Servidor TR-069 en la OLT', 'ok' => true, 'detalle' => "Perfil {$perfil}: {$url}, usuario {$g->acs_usuario}."];
            }

            $ultimo = $r['detalle'] ?? '';
        }

        return ['paso' => 'Servidor TR-069 en la OLT', 'ok' => false, 'detalle' => $ultimo ?: 'No se pudo crear el perfil de servidor.'];
    }

    /**
     * Si el perfil de línea de la ONT deja salir la VLAN de gestión.
     *
     * Se usa la última lectura de perfiles si la hay; si no, se le pregunta a
     * la OLT por ese perfil solo.
     *
     * @return array{listo:bool, id:?int, nombre:?string, detalle:string}
     */
    private function perfilListoDe(int $oltId, string $fsp, int $ontId, int $vlan): array
    {
        $dp = app(OltTelnetDispatcher::class);

        try {
            $deOnt = $dp->dispatch($oltId, 'perfilDeOnt', ['fsp' => $fsp, 'ont_id' => $ontId]);
        } catch (\Throwable $e) {
            return ['listo' => false, 'id' => null, 'nombre' => null, 'detalle' => 'No se pudo leer su perfil de línea: ' . \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage())];
        }

        if (!$deOnt) {
            return ['listo' => false, 'id' => null, 'nombre' => null, 'detalle' => 'No se pudo leer su perfil de línea.'];
        }

        $guardado = Cache::get("gestion:perfiles:{$oltId}:{$vlan}") ?? Cache::get("gestion:perfiles-ultima:{$oltId}:{$vlan}");
        $fila = collect($guardado['perfiles'] ?? [])->firstWhere('id', $deOnt['id']);

        if (!$fila || ($fila['estado'] ?? '') !== 'listo') {
            // Lo guardado puede estar viejo (o no incluirlo): se confirma en la OLT.
            try {
                $fila = $this->estadoDePerfil($dp->dispatch($oltId, 'perfilDeLinea', ['perfil' => $deOnt['id']]), $vlan);
            } catch (\Throwable $e) {
                $fila = ['estado' => 'sin_leer'];
            }
        }

        $listo = ($fila['estado'] ?? '') === 'listo';

        return [
            'listo'   => $listo,
            'id'      => $deOnt['id'],
            'nombre'  => $deOnt['nombre'],
            'detalle' => $listo
                ? "Su perfil {$deOnt['nombre']} deja salir la gestión."
                : (($fila['estado'] ?? '') === 'sin_leer'
                    // No es lo mismo que le falte a que no se haya podido leer:
                    // con YINETH decía "falta preparar" y el perfil estaba bien.
                    ? "no se pudo leer en la OLT su perfil de línea «{$deOnt['nombre']}» para confirmarlo; si el equipo aparece en el TR-069 está bien"
                    : "falta preparar su perfil de línea «{$deOnt['nombre']}» en Acceso remoto → Perfiles de línea: sin eso el equipo no puede salir por la VLAN {$vlan}"),
        ];
    }

    /** @return array{ok:bool, detalle:string} */
    private function asignarServidorTr069(int $oltId, string $fsp, int $ontId): array
    {
        $asegurado = $this->asegurarServidorTr069($oltId);
        $perfil = ($this->config()->perfiles_acs ?? [])[(string) $oltId] ?? null;

        if (!$asegurado['ok'] || !$perfil) {
            return ['ok' => false, 'detalle' => 'Sin servidor TR-069 en la OLT: ' . $asegurado['detalle']];
        }

        try {
            // Huawei usa el perfil; C-Data no tiene perfiles y se lo da al equipo directo.
            $g = $this->config();
            $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'asignarServidorTr069', [
                'fsp' => $fsp, 'ont_id' => $ontId, 'perfil' => (int) $perfil,
                'url' => (string) config('services.genieacs.url_equipos'),
                'usuario' => $g->acs_usuario, 'clave' => $g->acs_clave,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detalle' => 'No se asignó el servidor TR-069: ' . \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage())];
        }

        return ['ok' => (bool) ($r['ok'] ?? false), 'detalle' => $r['detalle'] ?? 'No se asignó el servidor TR-069.'];
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

        // 1. La VLAN sobre cada interfaz que va a una OLT. Con más de una (OLT
        //    colgadas de puertos distintos del router) se unen en un bridge y
        //    la red vive en el bridge.
        [$donde, $detalleVlan] = $this->vlanesDeGestion($api, $vlan, $interfaz);

        $pasos[] = ['paso' => "VLAN {$vlan} en {$interfaz}", 'ok' => $donde !== null, 'detalle' => $detalleVlan];

        if ($donde === null) {
            return $pasos;
        }

        // 2. La dirección del router en esa red (movida si estaba en otra interfaz).
        $direccion = $gateway . '/' . explode('/', $red)[1];
        $existente = $hay('/ip/address/print', 'address', $direccion)[0] ?? null;

        if (!$existente) {
            $api->query((new Query('/ip/address/add'))
                ->equal('address', $direccion)
                ->equal('interface', $donde)->equal('comment', self::MARCA))->read();
        } elseif (($existente['interface'] ?? '') !== $donde) {
            $api->query((new Query('/ip/address/set'))->equal('.id', $existente['.id'])->equal('interface', $donde))->read();
        }

        // Direcciones de gestión de una red anterior (se cambió la red sin
        // cambiar la VLAN): fuera, si no quedan dos redes en la misma interfaz.
        foreach ($api->query((new Query('/ip/address/print'))->where('comment', self::MARCA))->read() as $d) {
            if (($d['address'] ?? '') !== $direccion) {
                $api->query((new Query('/ip/address/remove'))->equal('.id', $d['.id']))->read();
            }
        }

        // 3. El rango de IP para las ONT (al día si cambió la red).
        if (!$existePool = $hay('/ip/pool/print', 'name', $pool)[0] ?? null) {
            $api->query((new Query('/ip/pool/add'))->equal('name', $pool)->equal('ranges', "{$desde}-{$hasta}"))->read();
        } elseif (($existePool['ranges'] ?? '') !== "{$desde}-{$hasta}") {
            $api->query((new Query('/ip/pool/set'))->equal('.id', $existePool['.id'])->equal('ranges', "{$desde}-{$hasta}"))->read();
        }

        // 4. La opción 43: la dirección del ACS viaja con la IP.
        $url = (string) config('services.genieacs.cwmp_url');
        $tlv = $this->opcion43();
        $existeOpcion = $hay('/ip/dhcp-server/option/print', 'name', $opcion);

        $q = new Query($existeOpcion ? '/ip/dhcp-server/option/set' : '/ip/dhcp-server/option/add');

        if ($existeOpcion) {
            $q->equal('.id', $existeOpcion[0]['.id']);
        }

        $api->query($q->equal('name', $opcion)->equal('code', '43')->equal('value', $tlv))->read();

        $pasos[] = ['paso' => 'Dirección del ACS por DHCP', 'ok' => true, 'detalle' => $url];

        // 5. El servidor DHCP y su red.
        if (!$servidor = $hay('/ip/dhcp-server/print', 'name', 'dhcp-gestion-ont')[0] ?? null) {
            $api->query((new Query('/ip/dhcp-server/add'))
                ->equal('name', 'dhcp-gestion-ont')->equal('interface', $donde)
                ->equal('address-pool', $pool)->equal('lease-time', '1d')->equal('disabled', 'no'))->read();
        } elseif (($servidor['interface'] ?? '') !== $donde) {
            $api->query((new Query('/ip/dhcp-server/set'))->equal('.id', $servidor['.id'])->equal('interface', $donde))->read();
        }

        // La red del DHCP de una red anterior, fuera.
        foreach ($api->query((new Query('/ip/dhcp-server/network/print'))->where('comment', self::MARCA))->read() as $n) {
            if (($n['address'] ?? '') !== $red) {
                $api->query((new Query('/ip/dhcp-server/network/remove'))->equal('.id', $n['.id']))->read();
            }
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
        $aislada = $this->aislar($api, $red, parse_url($url, PHP_URL_HOST) ?: '');

        $pasos[] = [
            'paso'    => 'Aislada del resto de la red',
            'ok'      => $aislada['ok'],
            'detalle' => $aislada['detalle'],
        ];

        return $pasos;
    }

    /**
     * La IP que el DHCP de gestión le dio a una ONT, buscándola por MAC: la
     * conexión de gestión usa la MAC de la ONT con otro último byte
     * (80:F7:A6:2B:F7:80 → 80:F7:A6:2B:F7:8C). Espera hasta $segundos.
     */
    private function ipDeGestionEnRouter(string $serial, int $segundos, bool $reciente = true): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $serial));

        if (strlen($hex) !== 12) {
            return null;
        }

        $prefijo = substr($hex, 0, 10);
        $ultimo  = hexdec(substr($hex, 10, 2));

        // Las ONU numeran las MAC de sus conexiones a partir de la suya
        // (…F7:80 → …F7:8C): se acepta hasta 32 más. Con sólo el prefijo, dos
        // ONU del mismo lote se confundían.
        $esDeEstaOnt = function (string $mac) use ($prefijo, $ultimo): bool {
            if (!str_starts_with($mac, $prefijo)) {
                return false;
            }

            $n = hexdec(substr($mac, 10, 2));

            return $n >= $ultimo && $n <= $ultimo + 32;
        };

        // "last-seen" de RouterOS: 45s, 2m16s, 8h30m50s, 1d2h…
        $segundosDesde = function (string $t): int {
            preg_match_all('/(\d+)([wdhms])/', $t, $m, PREG_SET_ORDER);

            return array_sum(array_map(fn ($x) => (int) $x[1] * ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1][$x[2]], $m));
        };

        try {
            $router = $this->router($this->config()->router_id);
            $api = $this->conexion->conection($router->token);
        } catch (\Throwable) {
            return null;
        }

        $limite = microtime(true) + $segundos;

        do {
            foreach ($api->query((new Query('/ip/dhcp-server/lease/print'))->where('server', 'dhcp-gestion-ont'))->read() as $l) {
                $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) ($l['mac-address'] ?? '')));

                if ($esDeEstaOnt($mac) && ($l['status'] ?? '') === 'bound'
                    && (!$reciente || $segundosDesde((string) ($l['last-seen'] ?? '')) <= 600)) {
                    return (string) $l['address'];
                }
            }

            if (microtime(true) < $limite) {
                sleep(5);
            }
        } while (microtime(true) < $limite);

        return null;
    }

    private const BRIDGE_GESTION = 'bridge-gestion-ont';

    /** "sfp1 + ether8" → ["sfp1", "ether8"]. @return list<string> */
    public static function interfacesDe(string $interfaz): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\s*[+,]\s*/', $interfaz)))));
    }

    /**
     * Deja la VLAN de gestión sobre cada interfaz pedida y devuelve dónde tiene
     * que vivir la red: la VLAN misma si es una sola, o el bridge que las une.
     *
     * La VLAN que ya existía (vlan{N}-gestion) se reutiliza para la primera
     * interfaz: así la red que ya funciona no se corta más que al pasar su IP
     * al bridge. Si cambió de interfaz, se mueve.
     *
     * @return array{0:?string, 1:string}
     */
    private function vlanesDeGestion($api, int $vlan, string $interfaz): array
    {
        $ifaces = self::interfacesDe($interfaz);

        if (!$ifaces) {
            return [null, 'Elegí la interfaz del MikroTik hacia la OLT.'];
        }

        $existentes = collect($api->query(new Query('/interface/print'))->read())->pluck('name')->all();
        $noEsta = array_values(array_diff($ifaces, $existentes));

        if ($noEsta) {
            return [null, 'El MikroTik no tiene la interfaz ' . implode(', ', $noEsta) . '.'];
        }

        $vlans = collect($api->query(new Query('/interface/vlan/print'))->read());
        $deseadas = [];

        foreach ($ifaces as $i => $iface) {
            $deseadas[$i === 0 ? "vlan{$vlan}-gestion" : "vlan{$vlan}-gestion-{$iface}"] = $iface;
        }

        // Antes de crear o mover: fuera las VLAN de gestión extra que ya no
        // corresponden (o que están en otra interfaz). Al reordenar
        // ("sfp1 + ether8" → "ether8 + sfp1") chocaban con la que se movía.
        foreach ($vlans as $v) {
            $nombre = (string) $v['name'];

            if (str_starts_with($nombre, "vlan{$vlan}-gestion-") && ($deseadas[$nombre] ?? null) !== ($v['interface'] ?? null)) {
                $this->quitarDelBridge($api, $nombre);
                $api->query((new Query('/interface/vlan/remove'))->equal('.id', $v['.id']))->read();
            }
        }

        $vlans = collect($api->query(new Query('/interface/vlan/print'))->read());
        $nombres = [];

        foreach ($ifaces as $i => $iface) {
            $nombre = $i === 0 ? "vlan{$vlan}-gestion" : "vlan{$vlan}-gestion-{$iface}";
            $actual = $vlans->firstWhere('name', $nombre);

            if (!$actual) {
                $api->query((new Query('/interface/vlan/add'))
                    ->equal('name', $nombre)->equal('vlan-id', (string) $vlan)
                    ->equal('interface', $iface)->equal('comment', self::MARCA))->read();
            } elseif (($actual['interface'] ?? '') !== $iface) {
                $api->query((new Query('/interface/vlan/set'))->equal('.id', $actual['.id'])->equal('interface', $iface))->read();
            }

            $nombres[] = $nombre;
        }

        // Las VLAN de gestión de interfaces que ya no se usan, fuera.
        foreach ($vlans as $v) {
            if (str_starts_with((string) $v['name'], "vlan{$vlan}-gestion-") && !in_array($v['name'], $nombres, true)) {
                $this->quitarDelBridge($api, (string) $v['name']);
                $api->query((new Query('/interface/vlan/remove'))->equal('.id', $v['.id']))->read();
            }
        }

        $bridge = collect($api->query((new Query('/interface/bridge/print'))->where('name', self::BRIDGE_GESTION))->read())->first();

        if (count($ifaces) === 1) {
            // Volver a una sola: la red regresa a la VLAN y el bridge se quita.
            if ($bridge) {
                $this->quitarDelBridge($api, $nombres[0]);
                $this->moverRed($api, self::BRIDGE_GESTION, $nombres[0]);
                $api->query((new Query('/interface/bridge/remove'))->equal('.id', $bridge['.id']))->read();
            }

            return [$nombres[0], $nombres[0]];
        }

        if (!$bridge) {
            $api->query((new Query('/interface/bridge/add'))
                ->equal('name', self::BRIDGE_GESTION)->equal('protocol-mode', 'none')->equal('comment', self::MARCA))->read();
        }

        $puertos = collect($api->query((new Query('/interface/bridge/port/print'))->where('bridge', self::BRIDGE_GESTION))->read())->pluck('interface')->all();

        foreach ($nombres as $nombre) {
            if (!in_array($nombre, $puertos, true)) {
                $api->query((new Query('/interface/bridge/port/add'))
                    ->equal('bridge', self::BRIDGE_GESTION)->equal('interface', $nombre)->equal('comment', self::MARCA))->read();
            }
        }

        // La red que vivía en la VLAN pasa al bridge enseguida: una IP sobre un
        // puerto de bridge deja de responder.
        $this->moverRed($api, $nombres[0], self::BRIDGE_GESTION);

        return [self::BRIDGE_GESTION, implode(' + ', $nombres) . ' unidas en ' . self::BRIDGE_GESTION];
    }

    private function quitarDelBridge($api, string $interfaz): void
    {
        foreach ($api->query((new Query('/interface/bridge/port/print'))->where('interface', $interfaz))->read() as $p) {
            $api->query((new Query('/interface/bridge/port/remove'))->equal('.id', $p['.id']))->read();
        }
    }

    /** Pasa la IP y el DHCP de gestión de una interfaz a otra. */
    private function moverRed($api, string $de, string $a): void
    {
        foreach ($api->query((new Query('/ip/address/print'))->where('interface', $de))->read() as $d) {
            if (($d['comment'] ?? '') === self::MARCA) {
                $api->query((new Query('/ip/address/set'))->equal('.id', $d['.id'])->equal('interface', $a))->read();
            }
        }

        foreach ($api->query((new Query('/ip/dhcp-server/print'))->where('name', 'dhcp-gestion-ont'))->read() as $d) {
            if (($d['interface'] ?? '') === $de) {
                $api->query((new Query('/ip/dhcp-server/set'))->equal('.id', $d['.id'])->equal('interface', $a))->read();
            }
        }
    }

    /**
     * Las reglas que encierran la red de gestión, arriba de todo y en orden:
     *
     *   1. las respuestas a lo que abrió el servidor (el ACS llamando al equipo
     *      para aplicar un cambio al momento, o la plataforma entrando a su
     *      página): sin esta, el equipo contestaba y la respuesta se descartaba;
     *   2. el equipo hablando con el servidor TR-069;
     *   3. todo lo demás, descartado.
     *
     * El equipo sigue sin poder iniciar nada hacia otro lado que no sea el ACS.
     */
    private function aislar($api, string $red, string $acs): array
    {
        // El firewall del MikroTik sólo acepta IP en dst-address: con el nombre
        // (acs.netvula.com) rechazaba la regla en silencio y el aislamiento
        // quedaba sin el paso al servidor.
        if ($acs !== '' && !filter_var($acs, FILTER_VALIDATE_IP)) {
            $ip  = gethostbyname($acs);
            $acs = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
        }

        if ($acs === '') {
            return ['ok' => false, 'detalle' => 'No se pudo averiguar la IP del servidor TR-069: no se crearon las reglas.'];
        }

        $reglas = [
            self::MARCA . ': respuestas' => ['action' => 'accept', 'connection-state' => 'established,related'],
            self::MARCA . ': al ACS'     => ['action' => 'accept'] + ($acs ? ['dst-address' => $acs] : []),
            self::MARCA . ': nada mas'   => ['action' => 'drop'],
        ];

        foreach ($reglas as $marca => $campos) {
            // Si ya existe se pone al día: al cambiar la red (10.30 → 10.40) las
            // reglas quedaban encerrando la red vieja.
            if ($existente = $api->query((new Query('/ip/firewall/filter/print'))->where('comment', $marca))->read()[0] ?? null) {
                $q = (new Query('/ip/firewall/filter/set'))
                    ->equal('.id', $existente['.id'])->equal('src-address', $red)->equal('disabled', 'no');

                foreach ($campos as $campo => $valor) {
                    $q->equal($campo, $valor);
                }

                $api->query($q)->read();
                continue;
            }

            $q = (new Query('/ip/firewall/filter/add'))
                ->equal('chain', 'forward')->equal('src-address', $red)->equal('comment', $marca);

            foreach ($campos as $campo => $valor) {
                $q->equal($campo, $valor);
            }

            $api->query($q)->read();
        }

        // Arriba de todo y en este orden. Cada una se mueve al primer lugar,
        // así que se recorren al revés: la que tiene que quedar primera va última.
        foreach (array_reverse(array_keys($reglas)) as $marca) {
            $todas = $api->query(new Query('/ip/firewall/filter/print'))->read();
            $primera = $todas[0]['.id'] ?? null;

            foreach ($todas as $f) {
                if (($f['comment'] ?? '') === $marca && $primera && $f['.id'] !== $primera) {
                    $api->query((new Query('/ip/firewall/filter/move'))
                        ->equal('numbers', $f['.id'])->equal('destination', $primera))->read();
                }
            }
        }

        // Se lee de vuelta: las tres, con la red actual, en su orden y arriba.
        $todas = $api->query(new Query('/ip/firewall/filter/print'))->read();
        $pos = [];

        foreach ($todas as $i => $f) {
            if (isset($reglas[$f['comment'] ?? '']) && ($f['src-address'] ?? '') === $red) {
                $pos[$f['comment']] ??= $i;
            }
        }

        $orden = array_keys($reglas);
        $ok = count($pos) === 3 && $pos[$orden[0]] < $pos[$orden[1]] && $pos[$orden[1]] < $pos[$orden[2]];

        return [
            'ok'      => $ok,
            'detalle' => $ok
                ? "{$red} sólo habla con el servidor TR-069 ({$acs})"
                : 'El MikroTik no dejó las tres reglas de aislamiento: faltan ' . implode(', ', array_diff($orden, array_keys($pos))),
        ];
    }

    /** Borra del router lo que puso la plataforma para esa VLAN. */
    private function limpiarDelRouter($api, int $vlan): void
    {
        $borrar = [
            ['/ip/dhcp-server/network/print', '/ip/dhcp-server/network/remove', 'comment', self::MARCA],
            ['/ip/dhcp-server/print', '/ip/dhcp-server/remove', 'name', 'dhcp-gestion-ont'],
            ['/ip/pool/print', '/ip/pool/remove', 'name', 'pool-gestion-ont'],
            ['/ip/address/print', '/ip/address/remove', 'comment', self::MARCA],
            ['/interface/bridge/port/print', '/interface/bridge/port/remove', 'bridge', self::BRIDGE_GESTION],
            ['/interface/bridge/print', '/interface/bridge/remove', 'name', self::BRIDGE_GESTION],
            ['/interface/vlan/print', '/interface/vlan/remove', 'name', "vlan{$vlan}-gestion"],
            ['/ip/firewall/filter/print', '/ip/firewall/filter/remove', 'comment', self::MARCA . ': respuestas'],
            ['/ip/firewall/filter/print', '/ip/firewall/filter/remove', 'comment', self::MARCA . ': al ACS'],
            ['/ip/firewall/filter/print', '/ip/firewall/filter/remove', 'comment', self::MARCA . ': nada mas'],
        ];

        foreach ($api->query(new Query('/interface/vlan/print'))->read() as $v) {
            if (str_starts_with((string) $v['name'], "vlan{$vlan}-gestion-")) {
                $borrar[] = ['/interface/vlan/print', '/interface/vlan/remove', 'name', $v['name']];
            }
        }

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
    /**
     * Reiniciar el equipo del cliente desde la OLT. Es aparte de la gestión
     * remota: en ZTE la plataforma sabe reiniciar, pero todavía no le da
     * gestión TR-069 a las ONU.
     */
    public static function admiteReinicio(string $marca): bool
    {
        return self::admiteGestion($marca) || strtolower($marca) === 'zte';
    }

    public static function admiteGestion(string $marca): bool
    {
        // C-Data: sólo GPON (el driver lo contesta; en EPON dice que no se puede).
        return in_array(strtolower($marca), ['huawei', 'cdata'], true);
    }

    /** "gpon" o "epon" de una C-Data, preguntándole a la OLT si no está guardado. */
    private static function tecnologiaCdata(OltAdmin $olt): string
    {
        $clave = "olt:{$olt->id}:capacidades:v2";
        $capacidades = Cache::get($clave);

        if (!is_array($capacidades)) {
            try {
                $capacidades = app(OltTelnetDispatcher::class)->dispatch((int) $olt->id, 'capacidades');

                if (is_array($capacidades)) {
                    Cache::put($clave, $capacidades, now()->addDay());
                }
            } catch (\Throwable) {
                $capacidades = null;
            }
        }

        // Sin respuesta de la OLT no se adivina: "desconocida" no habilita la
        // configuración automática de ninguna de las dos familias.
        return $capacidades['tecnologia'] ?? 'desconocida';
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
    /**
     * La interfaz del MikroTik por la que llega una OLT: la que lleva más VLAN
     * de clientes del puerto de subida de esa OLT. Si empatan (la VLAN 100 está
     * en dos interfaces), gana la que en el nombre de su VLAN menciona la OLT o
     * su marca ("VLAN 100 CDATA").
     */
    private static function interfazDeOlt(OltAdmin $olt, array $puertos, ?string $uplink, array $porInterfaz, array $nombres): ?string
    {
        $vlansOlt = collect($puertos)->firstWhere('puerto', $uplink)['vlans']
            ?? collect($puertos)->flatMap(fn ($p) => $p['vlans'] ?? [])->all();
        $vlansOlt = array_values(array_diff(array_map('intval', (array) $vlansOlt), [1]));

        if (!$vlansOlt) {
            return null;
        }

        $pistas = array_filter([strtolower((string) $olt->brand), strtolower((string) $olt->name)]);
        $mejor = null;
        $puntaje = [0, 0];

        foreach ($porInterfaz as $iface => $vlans) {
            $comunes = count(array_intersect($vlansOlt, $vlans));
            $nombre = collect($nombres[$iface] ?? [])->contains(fn ($n) => collect($pistas)->contains(fn ($p) => $p !== '' && str_contains($n, $p))) ? 1 : 0;

            if ($comunes > 0 && [$comunes, $nombre] > $puntaje) {
                $puntaje = [$comunes, $nombre];
                $mejor = $iface;
            }
        }

        return $mejor;
    }

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
