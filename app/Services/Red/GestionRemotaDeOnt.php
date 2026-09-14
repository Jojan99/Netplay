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
                'soporte'  => $this->soporte($olt),
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
            $tecnologia = (Cache::get("olt:{$olt->id}:capacidades:v2") ?? [])['tecnologia'] ?? 'epon';

            if ($tecnologia === 'epon') {
                return [
                    'nivel'   => 'manual',
                    'titulo'  => 'La red queda lista; cada equipo se configura a mano',
                    'detalle' => "En EPON la OLT no puede indicarle a la ONU que pida su IP de gestión. En cada equipo hay que crear una WAN "
                        . "de tipo TR-069, IPoE por DHCP, en la VLAN {$vlan}, y que esa VLAN pase por el puerto PON. Con eso el equipo entra solo al sistema.",
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

        foreach ($todos as $p) {
            // Los que no usa nadie no se leen ni se tocan: no suman nada.
            if ((int) $p['equipos'] === 0) {
                continue;
            }

            $lista[] = $p + $this->estadoDePerfil($leer('perfilDeLinea', ['perfil' => (int) $p['id']]), (int) $g->vlan);
        }

        usort($lista, fn ($a, $b) => $b['equipos'] <=> $a['equipos']);

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

        if ($perfil['modo'] !== 'VLAN' || !$perfil['gems']) {
            return ['estado' => 'no_soportado', 'detalle' => 'Reparte el tráfico por ' . ($perfil['modo'] ?: 'otro criterio') . ', no por VLAN: hay que revisarlo a mano.'];
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
            $grupos[] = ['titulo' => $olt->name, 'olt_id' => (int) $olt->id, 'marca' => $olt->brand, 'items' => $this->revisarOlt($olt, $g)];
        }

        $grupos[] = ['titulo' => 'Equipos', 'items' => $this->revisarEquipos($g)];

        $estados = [];

        foreach ($grupos as $grupo) {
            $estados = array_merge($estados, array_column($grupo['items'], 'estado'));
        }

        $nivel = in_array('error', $estados, true) ? 'error'
            : (array_intersect(['pendiente', 'aviso'], $estados) ? 'aviso' : 'ok');

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
    public function darAcceso(int $oltId, string $fsp, int $ontId, bool $reiniciarSiHaceFalta = false): array
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
                return ['ok' => false, 'detalle' => \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage())];
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

        // Cómo le llega al equipo el servidor TR-069 depende de su marca:
        //  - Huawei: la OLT le manda dirección y credenciales y lo enciende solo.
        //  - C-Data: también por la OLT, pero lo toma recién al reiniciarse
        //    (probado con CARMEN: se registró a los 2 min del reinicio).
        //  - Otras: se configura en el propio equipo, y se dice.
        $marca = self::marcaDelEquipo((string) ($ont?->serial ?? ''));

        $servidor = match ($marca) {
            'huawei' => $this->asignarServidorTr069($oltId, $fsp, $ontId),
            'cdata'  => $this->servidorTr069ParaCdata($oltId, $fsp, $ontId, $reiniciarSiHaceFalta),
            default  => ['ok' => false, 'detalle' => 'Equipo ' . strtoupper($marca ?: 'de otra marca') . ': el TR-069 se configura en el propio equipo.'],
        };

        // Sin la VLAN de gestión en su perfil de línea la ONT descarta ese
        // tráfico aunque todo lo demás esté bien. Antes no se miraba y el
        // proceso decía "listo" con el equipo sin poder salir.
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

    /**
     * El servidor TR-069 para un C-Data.
     *
     * Se le asigna por la OLT igual que a un Huawei, pero el equipo no lo
     * aplica hasta reiniciarse. Un equipo recién autorizado todavía no le da
     * servicio a nadie, así que se reinicia solo; a un cliente que ya navega no
     * se le corta internet sin avisar: queda dicho que falta el reinicio.
     *
     * @return array{ok:bool, detalle:string, requiere_reinicio?:bool}
     */
    private function servidorTr069ParaCdata(int $oltId, string $fsp, int $ontId, bool $reiniciar): array
    {
        $asignado = $this->asignarServidorTr069($oltId, $fsp, $ontId);

        if (!$asignado['ok']) {
            return $asignado;
        }

        if (!$reiniciar) {
            return [
                'ok'      => true,
                'detalle' => $asignado['detalle'] . ' Equipo C-Data: lo aplica al reiniciarse; reinicialo cuando no moleste al cliente.',
                'requiere_reinicio' => true,
            ];
        }

        try {
            $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'reiniciarOnt', ['fsp' => $fsp, 'ont_id' => $ontId]);
        } catch (\Throwable $e) {
            return ['ok' => true, 'detalle' => $asignado['detalle'] . ' No se pudo reiniciar: ' . \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage()), 'requiere_reinicio' => true];
        }

        return ($r['ok'] ?? false)
            ? ['ok' => true, 'detalle' => $asignado['detalle'] . ' Equipo C-Data reiniciado para que lo aplique.']
            : ['ok' => true, 'detalle' => $asignado['detalle'] . ' ' . ($r['detalle'] ?? 'No se pudo reiniciar.'), 'requiere_reinicio' => true];
    }

    // ── Marca del equipo ──────────────────────────────────────────────────

    /** La marca del equipo por el prefijo de su serial GPON (HWTC, CDTC…). */
    public static function marcaDelEquipo(string $serial): string
    {
        $s = strtoupper(trim($serial));
        $vendor = ctype_xdigit(substr($s, 0, 8)) && strlen($s) >= 16 ? (string) @hex2bin(substr($s, 0, 8)) : substr($s, 0, 4);

        return match ($vendor) {
            'HWTC', 'HUAW' => 'huawei',
            'CDTC', 'CDT'  => 'cdata',
            'ZTEG', 'ZXIC' => 'zte',
            'VSOL'         => 'vsol',
            default        => strtolower($vendor),
        };
    }

    /**
     * Reinicia un equipo desde la OLT. Le corta internet un minuto al cliente:
     * se hace a pedido del operador, nunca solo sobre alguien que ya navega.
     *
     * @return array{ok:bool, detalle:string}
     */
    public function reiniciarEquipo(int $oltId, string $fsp, int $ontId): array
    {
        $olt = OltAdmin::where('id', $oltId)->where('company_id', $this->companyId)->first();

        if (!$olt || !self::admiteGestion((string) $olt->brand)) {
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

        if (!empty(($g->perfiles_acs ?? [])[(string) $oltId])) {
            return ['paso' => 'Servidor TR-069 en la OLT', 'ok' => true, 'detalle' => 'Ya estaba creado (perfil ' . $g->perfiles_acs[(string) $oltId] . ').'];
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
                $g->perfiles_acs = array_merge($g->perfiles_acs ?? [], [(string) $oltId => $perfil]);
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
                : "falta preparar su perfil de línea «{$deOnt['nombre']}» en Acceso remoto → Perfiles de línea: sin eso el equipo no puede salir por la VLAN {$vlan}",
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
            $r = app(OltTelnetDispatcher::class)->dispatch($oltId, 'asignarServidorTr069', [
                'fsp' => $fsp, 'ont_id' => $ontId, 'perfil' => (int) $perfil,
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
        $tlv = $this->opcion43();
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
    private function aislar($api, string $red, string $acs): void
    {
        $reglas = [
            self::MARCA . ': respuestas' => ['action' => 'accept', 'connection-state' => 'established,related'],
            self::MARCA . ': al ACS'     => ['action' => 'accept'] + ($acs ? ['dst-address' => $acs] : []),
            self::MARCA . ': nada mas'   => ['action' => 'drop'],
        ];

        foreach ($reglas as $marca => $campos) {
            if ($api->query((new Query('/ip/firewall/filter/print'))->where('comment', $marca))->read()) {
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
            ['/ip/firewall/filter/print', '/ip/firewall/filter/remove', 'comment', self::MARCA . ': respuestas'],
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
