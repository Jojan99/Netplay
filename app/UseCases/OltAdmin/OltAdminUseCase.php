<?php

namespace App\UseCases\OltAdmin;

use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\Models\OltProfile;
use App\Repositories\Interfaces\OltAdminRepositoryInterface;
use App\Services\HuaweiSnmpReader;
use App\Services\OltTelnetDispatcher;
use Illuminate\Support\Facades\Cache;

class OltAdminUseCase
{
    public function __construct(
        private OltAdminRepositoryInterface $repo,
        private OltTelnetDispatcher         $dispatcher,
    ) {}

    // ── CRUD ──────────────────────────────────────────────────────────────

    public function listOlts(): array
    {
        return ['status' => 0, 'message' => 'OK', 'data' => $this->repo->getAllByCompany()];
    }

    // ── Ficha del equipo ──────────────────────────────────────────────────

    /**
     * Qué equipo es la OLT y en qué estado está: marca, modelo, tarjetas y
     * puertos. Se lee por SNMP con OID del estándar, así que responde igual sin
     * importar el fabricante.
     */
    public function equipo(int $oltId, bool $refrescar = false): array
    {
        $olt = OltAdmin::find($oltId);

        if (!$olt) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }

        $ficha = \App\Services\Olt\EquipoDeOlt::de($olt, $refrescar);

        $ficha['foto'] = $olt->photo_path
            ? url('/storage/' . $olt->photo_path)
            : null;

        // El modelo que declara el equipo se guarda para poder mostrarlo en el
        // listado sin volver a consultar la OLT.
        $modelo = $ficha['identidad']['modelo'] ?? null;

        if ($modelo && $olt->model !== $modelo) {
            $olt->forceFill(['model' => $modelo])->save();
        }

        return [
            'status'  => $ficha['responde'] ? 0 : 1,
            'message' => $ficha['responde']
                ? 'OK'
                : ($ficha['error'] ?: 'La OLT no respondió por SNMP'),
            'data'    => $ficha,
        ];
    }

    /**
     * Prueba cada eslabón de la conexión con la OLT y dice dónde se corta:
     * marca configurada, jump host, consola y SNMP.
     */
    public function diagnosticar(int $oltId): array
    {
        $olt = OltAdmin::find($oltId);

        if (!$olt) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }

        $r = \App\Services\Olt\DiagnosticoDeOlt::correr($olt);

        return [
            'status'  => $r['ok'] ? 0 : 1,
            'message' => $r['resumen'],
            'data'    => $r,
        ];
    }

    /**
     * Olvida el mapa de puertos guardado. Hace falta cuando le mueven una
     * placa a la OLT: si no, se sigue resolviendo el puerto viejo.
     */
    public function olvidarPuertos(int $oltId): array
    {
        $olt = OltAdmin::find($oltId);

        if (!$olt) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }

        (new HuaweiSnmpReader($olt))->olvidarMapaDePuertos();
        \App\Services\Olt\EquipoDeOlt::olvidar($olt);
        \App\Services\Olt\SenalDeLaOlt::olvidar($olt);

        return ['status' => 0, 'message' => 'Se volverá a leer el mapa de puertos y la ficha del equipo', 'data' => null];
    }

    /**
     * La salud de la señal óptica de toda la OLT: clasificación por ONT,
     * histograma, estado de cada puerto PON y los enlaces peores.
     */
    public function senal(int $oltId, bool $refrescar = false): array
    {
        $olt = OltAdmin::find($oltId);

        if (!$olt) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }

        $r = \App\Services\Olt\SenalDeLaOlt::de($olt, $refrescar);

        return [
            'status'  => $r['onts'] === [] ? 1 : 0,
            'message' => $r['onts'] === []
                ? ($r['error'] ?: 'La OLT no devolvió mediciones ópticas')
                : 'OK',
            'data'    => $r,
        ];
    }

    /**
     * Fija qué perfiles usa la OLT al autorizar una ONT.
     *
     * Se validan contra los que la OLT tiene realmente: apuntar a un perfil que
     * no existe es lo que hacía fallar el alta con "The service profile does
     * not exist", y el error sólo se veía al intentar registrar un cliente.
     */
    public function fijarPerfiles(int $oltId, ?int $lineProfileId, ?int $srvProfileId): array
    {
        $olt = OltAdmin::find($oltId);

        if (!$olt) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }

        $cambios = [];

        foreach ([
            'ont_lineprofile_id' => ['valor' => $lineProfileId, 'tipo' => 'line', 'nombre' => 'line profile'],
            'ont_srvprofile_id'  => ['valor' => $srvProfileId,  'tipo' => 'srv',  'nombre' => 'service profile'],
        ] as $campo => $dato) {
            if ($dato['valor'] === null) {
                continue;
            }

            $existe = OltProfile::where('olt_id', $oltId)
                ->where('type', $dato['tipo'])
                ->where('profile_id', $dato['valor'])
                ->exists();

            if (!$existe) {
                return [
                    'status'  => 1,
                    'message' => "El {$dato['nombre']} {$dato['valor']} no está entre los que tiene la OLT. "
                        . 'Sincronizá los perfiles y volvé a intentar.',
                    'data'    => null,
                ];
            }

            $cambios[$campo] = $dato['valor'];
        }

        if ($cambios === []) {
            return ['status' => 1, 'message' => 'No se indicó ningún perfil', 'data' => null];
        }

        $olt->forceFill($cambios)->save();

        return [
            'status'  => 0,
            'message' => 'Perfiles predeterminados actualizados',
            'data'    => [
                'ont_lineprofile_id' => $olt->ont_lineprofile_id,
                'ont_srvprofile_id'  => $olt->ont_srvprofile_id,
            ],
        ];
    }

    /**
     * Qué puertos PON autorizan solos las ONU nuevas.
     *
     * No todos los equipos lo permiten; los que no, devuelven null y la
     * pantalla no muestra el control.
     */
    public function autoAutorizacion(int $oltId): array
    {
        try {
            $puertos = $this->dispatcher->dispatch($oltId, 'autoAutorizacion');
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'No se pudo leer: ' . $e->getMessage(), 'data' => null];
        }

        if ($puertos === null) {
            return [
                'status'  => 0,
                'message' => 'Este equipo no permite controlar la autorización automática desde la plataforma.',
                'data'    => ['soportado' => false, 'puertos' => []],
            ];
        }

        return ['status' => 0, 'message' => 'OK', 'data' => ['soportado' => true, 'puertos' => $puertos]];
    }

    /** Prende o apaga la autorización automática de un puerto. */
    public function cambiarAutoAutorizacion(int $oltId, int $puerto, bool $activar): array
    {
        try {
            $puertos = $this->dispatcher->dispatch($oltId, 'cambiarAutoAutorizacion', [
                'activar' => $activar,
                'puerto'  => $puerto,
            ]);
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'No se pudo cambiar: ' . $e->getMessage(), 'data' => null];
        }

        if (!is_array($puertos) || !isset($puertos[$puerto])) {
            return ['status' => 1, 'message' => 'La OLT no aceptó el cambio.', 'data' => null];
        }

        // Se informa lo que la OLT dice que quedó, no lo que se pidió.
        $quedo = $puertos[$puerto]['auto'];

        if ($quedo !== $activar) {
            return [
                'status'  => 1,
                'message' => "El puerto {$puerto} sigue " . ($quedo ? 'autorizando solo' : 'en manual') . ': la OLT no aplicó el cambio.',
                'data'    => ['soportado' => true, 'puertos' => $puertos],
            ];
        }

        Cache::forget("olt:{$oltId}:unauth_onts");

        return [
            'status'  => 0,
            'message' => $activar
                ? "Puerto {$puerto}: las ONU nuevas se autorizan solas."
                : "Puerto {$puerto}: las ONU nuevas quedan esperando en «Sin autorizar».",
            'data'    => ['soportado' => true, 'puertos' => $puertos],
        ];
    }

    /**
     * Qué admite el equipo al autorizar: si identifica por MAC o serial, si
     * tiene service-port, si el perfil de servicio se elige en el alta. Se
     * guarda un día: no cambia salvo que cambien el equipo.
     */
    public function capacidades(int $oltId): array
    {
        // Versión en la clave: cambiarla invalida lo guardado desde un deploy,
        // sin depender de poder borrar archivos de caché que creó la web.
        $clave = "olt:{$oltId}:capacidades:v2";

        $capacidades = Cache::get($clave);

        if (!is_array($capacidades)) {
            $consultada = true;

            try {
                $capacidades = $this->dispatcher->dispatch($oltId, 'capacidades');
            } catch (\Throwable $e) {
                // Sin respuesta (OLT caída, worker con código viejo) se muestra el
                // formulario estándar en vez de dejar la pantalla sin datos, y no
                // se guarda, para volver a preguntar la próxima vez.
                \Log::warning('[OLT] No se pudieron leer las capacidades', ['olt' => $oltId, 'error' => $e->getMessage()]);
                $capacidades = null;
                $consultada  = false;
            }

            // Los drivers que no la declaran se comportan como Huawei.
            $capacidades ??= [
                'tecnologia'              => 'gpon',
                'identificador'           => 'serial',
                'service_port'            => true,
                'perfil_servicio_en_alta' => true,
                'vlan'                    => 'service-port',
                'explicacion_vlan'        => null,
            ];

            if ($consultada) {
                Cache::put($clave, $capacidades, now()->addDay());
            }
        }

        return ['status' => 0, 'message' => 'OK', 'data' => $capacidades];
    }

    /** Marcas de OLT que la plataforma sabe manejar. */
    public function marcasSoportadas(): array
    {
        return [
            'status'  => 0,
            'message' => 'OK',
            'data'    => \App\OltDrivers\FabricaDeDrivers::marcas(),
        ];
    }

    /** Guarda la foto que el operador subió de su OLT. */
    public function guardarFoto(int $oltId, \Illuminate\Http\UploadedFile $archivo): array
    {
        $olt = OltAdmin::find($oltId);

        if (!$olt) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }

        // La anterior se borra: si no, cada cambio deja un archivo suelto.
        if ($olt->photo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($olt->photo_path);
        }

        $ruta = $archivo->store('olts', 'public');

        $olt->forceFill(['photo_path' => $ruta])->save();

        return [
            'status'  => 0,
            'message' => 'Foto guardada',
            'data'    => ['foto' => url('/storage/' . $ruta)],
        ];
    }

    /** Quita la foto y vuelve al diagrama que se dibuja con los datos reales. */
    public function borrarFoto(int $oltId): array
    {
        $olt = OltAdmin::find($oltId);

        if (!$olt) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }

        if ($olt->photo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($olt->photo_path);
            $olt->forceFill(['photo_path' => null])->save();
        }

        return ['status' => 0, 'message' => 'Foto eliminada', 'data' => null];
    }

    public function createOlt(array $data): array
    {
        $tunelPedido = (bool) ($data['crear_tunel_vpn'] ?? false);

        // Los datos del túnel no son columnas de la OLT: se guardan aparte
        // antes de sacarlos del arreglo que va al repositorio.
        $tunel = [
            'id'     => isset($data['tunel_id']) ? (int) $data['tunel_id'] : null,
            'nombre' => $data['tunel_nombre'] ?? null,
            'redes'  => $data['tunel_redes'] ?? null,
        ];

        unset($data['crear_tunel_vpn'], $data['tunel_nombre'], $data['tunel_redes'], $data['tunel_id']);

        $olt = $this->repo->create($data);

        if (!$tunelPedido) {
            return ['status' => 0, 'message' => 'OLT creada correctamente', 'data' => $olt];
        }

        // El repositorio devuelve la OLT como arreglo; para el túnel hace falta
        // el modelo. Pasarle el arreglo directo reventaba con un error 500
        // después de haber creado la OLT, así que cada reintento dejaba otra.
        return $this->conTunel(OltAdmin::findOrFail($olt['id']), $tunel);
    }

    /**
     * Crea la OLT junto con su túnel de gestión.
     *
     * Tiene más sentido acá que como paso aparte: cuando se registra una OLT en
     * red privada, el camino para llegar a ella es parte del alta. Si ya hay un
     * túnel que cubre su red se reutiliza —lo que hace falta es uno por router,
     * no uno por equipo.
     *
     * @param  array{id:?int, nombre:?string, redes:?string}  $pedido
     */
    private function conTunel(OltAdmin $olt, array $pedido): array
    {
        if ($pedido['id']) {
            return $this->sumarATunel($olt, $pedido);
        }

        $existente = \App\Services\Vpn\ServidorVpn::tunelQueCubre($olt->host, (int) $olt->company_id);

        if ($existente) {
            return [
                'status'  => 0,
                'message' => "OLT creada. Ya hay un túnel que cubre su red: «{$existente->nombre}». "
                    . 'Aplicá ese script en el router si todavía no está levantado.',
                'data'    => ['olt' => $olt, 'tunel' => $existente, 'reutilizado' => true],
            ];
        }

        $redes = trim((string) ($pedido['redes'] ?? ''))
            ?: (string) \App\Services\Vpn\ServidorVpn::redDe((string) $olt->host);

        if ($redes === '') {
            return [
                'status'  => 0,
                'message' => 'OLT creada, pero no se pudo deducir la red de gestión para el túnel. '
                    . 'Crealo desde la pestaña VPN.',
                'data'    => ['olt' => $olt, 'tunel' => null],
            ];
        }

        try {
            $creado = \App\Services\Vpn\ServidorVpn::crearTunel([
                'company_id'    => $olt->company_id,
                'nombre'        => trim((string) ($pedido['nombre'] ?? '')) ?: ('Nodo ' . $olt->name),
                'redes_remotas' => $redes,
                'notas'         => "Creado con la OLT {$olt->name} ({$olt->host}).",
            ]);

            return [
                'status'  => 0,
                'message' => 'OLT y túnel creados. Pegá el script en el router para levantarlo.',
                'data'    => [
                    'olt'    => $olt,
                    'tunel'  => $creado['tunel'],
                    'script' => \App\Services\Vpn\ScriptMikrotik::para(
                        $creado['tunel'],
                        \App\Services\Vpn\ServidorVpn::configuracion(),
                        $creado['clave_privada'],
                        $creado['clave_compartida'],
                    ),
                ],
            ];
        } catch (\Throwable $e) {
            // La OLT ya quedó creada: se informa el problema del túnel sin
            // deshacerla, porque el alta en sí fue correcta.
            \Log::warning('[OLT] No se pudo crear el túnel junto con la OLT', [
                'olt' => $olt->id, 'error' => $e->getMessage(),
            ]);

            return [
                'status'  => 0,
                'message' => 'OLT creada, pero el túnel no: ' . $e->getMessage(),
                'data'    => ['olt' => $olt, 'tunel' => null],
            ];
        }
    }

    /**
     * Suma la red de la OLT a un túnel que ya existe.
     *
     * Es el caso de un router con varias OLT: el router lleva un solo túnel,
     * así que crear otro y correr su script pisaba el anterior y dejaba sin
     * acceso a las demás OLT del mismo equipo.
     *
     * @param  array{id:?int, nombre:?string, redes:?string}  $pedido
     */
    private function sumarATunel(OltAdmin $olt, array $pedido): array
    {
        $tunel = \App\Models\VpnTunel::where('id', $pedido['id'])
            ->where('company_id', $olt->company_id)
            ->first();

        if (!$tunel) {
            return [
                'status'  => 0,
                'message' => 'OLT creada, pero el túnel elegido no existe en esta empresa.',
                'data'    => ['olt' => $olt, 'tunel' => null],
            ];
        }

        $nuevas = \App\Services\Vpn\ServidorVpn::normalizarRedes(
            trim((string) ($pedido['redes'] ?? '')) ?: (string) \App\Services\Vpn\ServidorVpn::redDe((string) $olt->host)
        );

        $redes = array_values(array_unique(array_merge($tunel->redes_remotas ?? [], $nuevas)));

        try {
            \App\Services\Vpn\ServidorVpn::verificarRedesLibres($redes, $tunel->id);
        } catch (\Throwable $e) {
            return [
                'status'  => 0,
                'message' => 'OLT creada, pero su red no se pudo sumar al túnel: ' . $e->getMessage(),
                'data'    => ['olt' => $olt, 'tunel' => null],
            ];
        }

        $tunel->forceFill(['redes_remotas' => $redes])->save();
        \App\Services\Vpn\ServidorVpn::aplicar();

        return [
            'status'  => 0,
            'message' => "OLT creada y su red sumada al túnel «{$tunel->nombre}». "
                . 'Volvé a pegar el script en ese router para que agregue el acceso a la red nueva.',
            'data'    => [
                'olt'    => $olt,
                'tunel'  => $tunel,
                'script' => \App\Services\Vpn\ScriptMikrotik::para(
                    $tunel,
                    \App\Services\Vpn\ServidorVpn::configuracion(),
                    (string) $tunel->clave_privada,
                    (string) $tunel->clave_compartida,
                ),
            ],
        ];
    }

    public function updateOlt(int $id, array $data): array
    {
        if (!$this->repo->findById($id)) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }
        $this->repo->update($id, $data);
        return ['status' => 0, 'message' => 'OLT actualizada', 'data' => null];
    }

    public function deleteOlt(int $id): array
    {
        if (!$this->repo->findById($id)) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }
        $this->repo->delete($id);

        // Antes se llamaba a closeConnection(), que dejó de existir con el
        // pooling de conexiones: la OLT se borraba y la respuesta era un 500.
        // Ahora la sesión la tiene el worker, así que se le pide que termine
        // —al volver no encuentra la OLT y no se reinicia— y se limpia lo que
        // quedó guardado de ella.
        try {
            \Illuminate\Support\Facades\Redis::setex("olt:{$id}:recargar", 300, 1);
        } catch (\Throwable) {
            // Sin Redis no hay worker que detener.
        }

        foreach (["olt:{$id}:mapa_puertos", "olt:{$id}:equipo", "olt:{$id}:senal", "olt:{$id}:auth_onts", "olt:{$id}:all_service_ports"] as $clave) {
            Cache::forget($clave);
        }

        return ['status' => 0, 'message' => 'OLT eliminada', 'data' => null];
    }

    // ── ONT operations ────────────────────────────────────────────────────

    // Minutos que se cachean los resultados de consulta al OLT
    private const CACHE_TTL = 3;

    public function getUnauthONTs(int $oltId): array
    {
        $cacheKey = "olt:{$oltId}:unauth_onts";

        // $cached = Cache::get($cacheKey);
        // if ($cached !== null) {
        //     return ['status' => 0, 'message' => count($cached) . ' ONTs sin autenticar (caché)', 'data' => $cached];
        // }

        // SNMP first — fast, no Telnet session needed
        try {
            $olt  = $this->getOltModel($oltId);
            $onts = $this->snmpReader($olt)->getUnauthONTs();

            if (!empty($onts)) {
                Cache::put($cacheKey, $onts, now()->addMinutes(self::CACHE_TTL));
                return ['status' => 0, 'message' => count($onts) . ' ONTs sin autenticar (SNMP)', 'data' => $onts];
            }

            \Log::info('OLT getUnauthONTs SNMP vacío, usando Telnet', ['olt_id' => $oltId]);
        } catch (\Throwable $e) {
            \Log::warning('OLT getUnauthONTs SNMP falló, usando Telnet', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
        }

        // Telnet fallback
        try {
            $onts = $this->dispatcher->dispatch($oltId, 'getUnauthONTs');
            Cache::put($cacheKey, $onts, now()->addMinutes(self::CACHE_TTL));
            return ['status' => 0, 'message' => count($onts) . ' ONTs sin autenticar (Telnet)', 'data' => $onts];
        } catch (\Throwable $e) {
            \Log::error('OLT getUnauthONTs Telnet error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error consultando OLT: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function syncProfiles(int $oltId): array
    {
        try {
            $lineProfiles = $this->dispatcher->dispatch($oltId, 'getLineProfiles');
            $srvProfiles  = $this->dispatcher->dispatch($oltId, 'getSrvProfiles');
            $now = now();

            foreach ($lineProfiles as $p) {
                OltProfile::updateOrCreate(
                    ['olt_id' => $oltId, 'type' => 'line', 'profile_id' => $p['id']],
                    ['profile_name' => $p['name'], 'synced_at' => $now]
                );
            }
            foreach ($srvProfiles as $p) {
                OltProfile::updateOrCreate(
                    ['olt_id' => $oltId, 'type' => 'srv', 'profile_id' => $p['id']],
                    ['profile_name' => $p['name'], 'synced_at' => $now]
                );
            }

            return ['status' => 0, 'message' => 'Perfiles sincronizados', 'data' => [
                'line' => count($lineProfiles),
                'srv'  => count($srvProfiles),
                'synced_at' => $now->toIso8601String(),
            ]];
        } catch (\Throwable $e) {
            \Log::error('OLT syncProfiles error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error sincronizando perfiles: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function getProfiles(int $oltId): array
    {
        $line = OltProfile::where('olt_id', $oltId)->where('type', 'line')
            ->orderBy('profile_id')
            ->get(['profile_id', 'profile_name', 'synced_at']);

        $srv = OltProfile::where('olt_id', $oltId)->where('type', 'srv')
            ->orderBy('profile_id')
            ->get(['profile_id', 'profile_name', 'synced_at']);

        return ['status' => 0, 'message' => 'OK', 'data' => ['line' => $line, 'srv' => $srv]];
    }

    /**
     * Dónde está ya esta ONT, si es que está.
     *
     * Una ONT sólo puede estar autorizada en un puerto. Cuando se cambia de
     * fibra queda registrada en el anterior, y al intentar autorizarla en el
     * nuevo la OLT la rechaza sin decir por qué. Preguntando antes se puede
     * avisar dónde está y ofrecer moverla.
     *
     * @return array{encontrada:bool, fsp?:string, ont_id?:int, descripcion?:?string, estado?:?string}
     */
    public function buscarOntPorSerial(int $oltId, string $serial): array
    {
        $serial = strtoupper(trim($serial));

        if ($serial === '') {
            return ['encontrada' => false];
        }

        try {
            $autorizadas = $this->dispatcher->dispatch($oltId, 'getAuthorizedONTs', []);

            foreach ($autorizadas as $ont) {
                if (strtoupper(trim((string) ($ont['serial'] ?? ''))) !== $serial) {
                    continue;
                }

                return [
                    'encontrada'  => true,
                    'fsp'         => $ont['fsp'] ?? null,
                    'ont_id'      => isset($ont['ont_id']) ? (int) $ont['ont_id'] : null,
                    'descripcion' => $ont['description'] ?? null,
                    'estado'      => $ont['run_state'] ?? ($ont['status'] ?? null),
                ];
            }

            return ['encontrada' => false];
        } catch (\Throwable $e) {
            \Log::warning('OLT buscarOntPorSerial: no se pudo consultar', [
                'olt_id' => $oltId, 'serial' => $serial, 'error' => $e->getMessage(),
            ]);

            // Que no se pueda consultar no debe frenar el alta: se sigue y, si
            // ya existía, la OLT lo va a rechazar igual.
            return ['encontrada' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Autoriza una ONT que ya estaba en otro puerto.
     *
     * Se borra de donde estaba y se autoriza en el nuevo, en ese orden: al
     * revés la OLT rechaza el alta por serial duplicado.
     */
    public function moverOnt(int $oltId, array $data): array
    {
        $serial = strtoupper(trim((string) ($data['serial'] ?? '')));
        $donde  = $this->buscarOntPorSerial($oltId, $serial);

        $pasos = [];

        if ($donde['encontrada']) {
            $baja = $this->deleteONT($oltId, [
                'fsp'    => $donde['fsp'],
                'ont_id' => $donde['ont_id'],
            ]);

            $pasos[] = [
                'paso'    => "Quitar de {$donde['fsp']} (ONT ID {$donde['ont_id']})",
                'ok'      => $baja['status'] === 0,
                'detalle' => $baja['message'],
            ];

            if ($baja['status'] !== 0) {
                return [
                    'status'  => 1,
                    'message' => "No se pudo quitar la ONT de {$donde['fsp']}, así que no se movió.",
                    'data'    => ['pasos' => $pasos],
                ];
            }
        }

        $alta = $this->registerONT($oltId, $data);

        $pasos[] = [
            'paso'    => "Autorizar en {$data['fsp']}",
            'ok'      => $alta['status'] === 0,
            'detalle' => $alta['message'],
        ];

        return [
            'status'  => $alta['status'],
            'message' => $alta['message'],
            'data'    => ['pasos' => $pasos] + (array) ($alta['data'] ?? []),
        ];
    }

    public function registerONT(int $oltId, array $data): array
    {
        try {
            $desc = strtoupper(str_replace(' ', '_', trim($data['description'] ?? $data['serial'])));

            $vlan    = !empty($data['vlan']) ? (int) $data['vlan'] : null;
            $spIndex = $vlan !== null ? $this->generateServicePort($oltId, $vlan) : null;

            \Log::debug('OLT registerONT: payload recibido', [
                'olt_id'       => $oltId,
                'fsp'          => $data['fsp'],
                'vlan_raw'     => $data['vlan'] ?? 'NO VIENE',
                'vlan_parsed'  => $vlan,
                'sp_index'     => $spIndex,
            ]);

            $result = $this->dispatcher->dispatch($oltId, 'registerONT', [
                'fsp'             => $data['fsp'],
                'serial'          => $data['serial'],
                'description'     => $desc,
                'line_profile_id' => isset($data['line_profile_id']) ? (int) $data['line_profile_id'] : null,
                'srv_profile_id'  => isset($data['srv_profile_id'])  ? (int) $data['srv_profile_id']  : null,
                'vlan'            => $vlan,
                'service_port'    => $spIndex,
            ]);

            // Un alta son varios pasos contra la OLT y cualquiera puede
            // fallar por su cuenta. Se informa uno por uno: si se corta a
            // mitad, hay que poder ver qué quedó hecho para no repetirlo todo
            // ni dejar al cliente conectado sin navegar.
            $pasos = [[
                'paso'    => "Autorizar la ONT en {$data['fsp']}",
                'ok'      => (bool) $result['success'],
                'detalle' => $result['success']
                    ? 'ONT ID ' . $result['ont_id']
                    : ($result['message'] ?: 'la OLT no dio detalle'),
            ]];

            if ($result['success']) {
                Cache::forget("olt:{$oltId}:unauth_onts");

                $ontId     = (int) $result['ont_id'];
                $spCreated = $result['service_port_created'] ?? false;

                if ($vlan !== null && !empty($result['vlan_paso'])) {
                    // Equipos sin service-port (C-Data EPON): el driver dice qué
                    // verificó. El mensaje de Huawei ("sin esto el cliente
                    // conecta pero no navega") no aplica y confundía.
                    $pasos[] = [
                        'paso'    => $result['vlan_paso']['titulo'],
                        'ok'      => (bool) $result['vlan_paso']['ok'],
                        'detalle' => $result['vlan_paso']['detalle'],
                    ];
                } elseif ($vlan !== null) {
                    $pasos[] = [
                        'paso'    => "Crear el service-port (VLAN {$vlan})",
                        'ok'      => (bool) $spCreated,
                        'detalle' => $spCreated
                            ? "índice {$spIndex}"
                            : (!empty($result['service_port_error'])
                                ? 'la OLT respondió: ' . $result['service_port_error']
                                : 'sin esto el cliente conecta pero no navega'),
                    ];
                } else {
                    $pasos[] = [
                        'paso'    => 'Crear el service-port',
                        'ok'      => false,
                        'omitido' => true,
                        'detalle' => 'no se eligió VLAN, así que no se creó',
                    ];
                }

                if (!empty($data['user_data_id'])) {
                    $pasos[] = [
                        'paso'    => 'Vincular el cliente',
                        'ok'      => true,
                        'detalle' => null,
                    ];
                }

                OltOnt::updateOrCreate(
                    ['olt_id' => $oltId, 'fsp' => $data['fsp'], 'ont_id' => $ontId],
                    [
                        'serial'        => $data['serial'],
                        'description'   => $desc,
                        // El cliente queda vinculado desde el alta: así se sabe
                        // qué puerto lo atiende sin tener que asignarlo aparte.
                        'user_data_id'  => $data['user_data_id'] ?? null,
                        'status'        => 'offline',
                        // En equipos sin service-port (C-Data EPON) el paso de
                        // VLAN sólo verifica el puerto PON: no hay índice que
                        // guardar, y guardarlo inventaba un service-port que
                        // aparecía en la lista y se intentaba borrar después.
                        'service_ports' => ($spCreated && $vlan !== null && $spIndex !== null && empty($result['vlan_paso']))
                                            ? [['index' => $spIndex, 'vlan' => $vlan]]
                                            : [],
                        'synced_at'     => now(),
                    ]
                );

                Cache::forget("olt:{$oltId}:all_service_ports");
            }

            // Cuando falla se muestra lo que dijo la OLT: "Error al registrar
            // ONT" no le sirve a nadie para saber qué pasó.
            $msg = $result['success']
                ? "ONT autorizada en {$data['fsp']} · ONT ID {$result['ont_id']}"
                    . (!empty($result['vlan_paso'])
                        ? ($result['vlan_paso']['ok'] ? " · VLAN {$vlan} verificada en el puerto" : " · falta la VLAN {$vlan} en el puerto")
                        : (($result['service_port_created'] ?? false) ? " · service-port {$spIndex} creado" : ''))
                : (stripos((string) $result['message'], 'System is busy') !== false
                    ? 'La OLT está ocupada en este momento (suele ser porque está guardando la configuración). Probá de nuevo en unos segundos.'
                    : 'La OLT no autorizó la ONT: ' . ($result['message'] ?: 'sin detalle'));

            return [
                'status'  => $result['success'] ? 0 : 1,
                'message' => $msg,
                'data'    => $result + ['pasos' => $pasos, 'fsp' => $data['fsp']],
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT registerONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error registrando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function cliCommand(int $oltId, string $command): array
    {
        try {
            $output = $this->dispatcher->dispatch($oltId, 'runCommand', ['command' => $command]);
            return ['status' => 0, 'message' => 'OK', 'data' => ['output' => $output, 'command' => $command]];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    public function deleteONT(int $oltId, array $data): array
    {
        try {
            $fsp   = $data['fsp'];
            $ontId = (int) $data['ont_id'];

            // Auto-obtener todos los service-ports de esta ONT para eliminarlos primero
            $servicePortIndices = [];
            try {
                $ports = $this->dispatcher->dispatch($oltId, 'getServicePorts', [
                    'fsp'    => $fsp,
                    'ont_id' => $ontId,
                ]);
                $servicePortIndices = array_filter(array_column($ports, 'index'));
                Cache::forget("olt:{$oltId}:service_ports:{$fsp}:{$ontId}");
            } catch (\Throwable $e) {
                // Si es un error de sesión (límite, timeout) lo relanzamos — no tiene sentido continuar
                if (str_contains($e->getMessage(), 'session limit') || str_contains($e->getMessage(), 'Timeout') || str_contains($e->getMessage(), 'Reenter')) {
                    throw $e;
                }
                \Log::warning('OLT deleteONT: no se pudieron obtener service-ports, se continúa sin ellos', [
                    'olt_id' => $oltId, 'fsp' => $fsp, 'ont_id' => $ontId, 'error' => $e->getMessage(),
                ]);
            }

            $ok = $this->dispatcher->dispatch($oltId, 'deleteONT', [
                'fsp'           => $fsp,
                'ont_id'        => $ontId,
                'service_ports' => array_values($servicePortIndices),
            ]);

            if ($ok) {
                Cache::forget("olt:{$oltId}:unauth_onts");
                Cache::forget("olt:{$oltId}:all_service_ports");
                OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $fsp)
                    ->where('ont_id', $ontId)
                    ->delete();
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok
                    ? "ONT eliminada de {$fsp} (ONT ID {$ontId})"
                    : 'La OLT no pudo eliminar la ONT. Revisá que el ONT ID sea el correcto.',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT deleteONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error eliminando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * ONT que quedaron a medio provisionar.
     *
     * Cada alta son varios pasos contra la OLT y cualquiera puede fallar. Si
     * se corta después de autorizar, la ONT queda registrada pero sin
     * service-port —conectada y sin navegar— y nadie se entera hasta que el
     * cliente llama.
     *
     * @return array<int,array<string,mixed>>
     */
    public function ontsIncompletas(int $oltId): array
    {
        // Lo que la OLT tiene de verdad. Mirar sólo la tabla local daba
        // cientos de falsos positivos: las ONT sincronizadas desde el equipo
        // llegan sin los service-ports anotados, y parecían todas a medias.
        $enLaOlt = [];

        try {
            foreach ($this->getAllServicePorts($oltId) as $sp) {
                $enLaOlt[($sp['fsp'] ?? '') . ':' . ($sp['ont_id'] ?? '')] = true;
            }
        } catch (\Throwable $e) {
            \Log::warning('OLT ontsIncompletas: sin datos de la OLT, no se puede comparar', [
                'olt_id' => $oltId, 'error' => $e->getMessage(),
            ]);

            // Sin poder comparar es mejor no listar nada que listar de más.
            return [];
        }

        // Un equipo con ONT registradas no tiene cero service-ports: si vino
        // vacío es que la lectura falló, y marcarlas todas como incompletas
        // sería puro ruido.
        if (!$enLaOlt) {
            \Log::warning('OLT ontsIncompletas: la OLT no devolvió service-ports', ['olt_id' => $oltId]);

            return [];
        }

        // Sólo se opina de los puertos de los que se obtuvo respuesta. La
        // consulta por puerto no siempre devuelve lo suyo, y dar por incompleta
        // una ONT de un puerto que no se pudo leer sería inventar: son cientos
        // de clientes que están funcionando bien.
        $puertosLeidos = [];

        foreach (array_keys($enLaOlt) as $clave) {
            $puertosLeidos[explode(':', $clave)[0]] = true;
        }

        return OltOnt::where('olt_id', $oltId)
            ->get()
            ->filter(fn ($ont) => isset($puertosLeidos[$ont->fsp]))
            ->map(function ($ont) use ($enLaOlt) {
                $falta = [];

                if (!isset($enLaOlt[$ont->fsp . ':' . $ont->ont_id])) {
                    $falta[] = 'service-port';
                }

                if (empty($ont->user_data_id)) {
                    $falta[] = 'cliente asignado';
                }

                return [
                    'fsp'         => $ont->fsp,
                    'ont_id'      => $ont->ont_id,
                    'serial'      => $ont->serial,
                    'descripcion' => $ont->description,
                    'estado'      => $ont->status,
                    'falta'       => $falta,
                    'desde'       => optional($ont->synced_at)->diffForHumans(),
                ];
            })
            ->filter(fn ($o) => $o['falta'] !== [])
            ->values()
            ->all();
    }

    /**
     * Completa el service-port de una ONT que quedó a medias.
     *
     * Es el paso que más falla del alta: la ONT queda autorizada, el cliente
     * conecta y no navega. Se puede reintentar solo, sin volver a autorizar ni
     * repetir todo el alta.
     */
    public function completarServicePort(int $oltId, array $data): array
    {
        $fsp   = $data['fsp'];
        $ontId = (int) $data['ont_id'];

        $oltModel = $this->getOltModel($oltId);
        $vlan     = (int) ($data['vlan'] ?? $oltModel->default_vlan);

        if (!$vlan) {
            return ['status' => 1, 'message' => 'Hace falta la VLAN para crear el service-port.', 'data' => null];
        }

        // Equipos sin service-port: no se crea nada, se verifica que el puerto
        // PON lleve la VLAN. Mandarles la secuencia de Huawei cambiaba el
        // puerto de la ONU y fallaba después.
        $capacidades = $this->capacidades($oltId)['data'] ?? null;

        if (is_array($capacidades) && ($capacidades['service_port'] ?? true) === false) {
            $paso = $this->dispatcher->dispatch($oltId, 'pasoVlan', ['fsp' => $fsp, 'vlan' => $vlan]);

            return [
                'status'  => ($paso['ok'] ?? false) ? 0 : 1,
                'message' => $paso['detalle'] ?? 'No se pudo verificar la VLAN del puerto.',
                'data'    => $paso,
            ];
        }

        $ont = OltOnt::where('olt_id', $oltId)->where('fsp', $fsp)->where('ont_id', $ontId)->first();

        // Primero se mira si ya está: el alta pudo haberlo creado aunque la
        // OLT no lo confirmara, y crear otro con la misma VLAN lo duplica o
        // la OLT lo rechaza con un error que no explica nada.
        try {
            $existentes = $this->dispatcher->dispatch($oltId, 'getServicePorts', ['fsp' => $fsp, 'ont_id' => $ontId]) ?: [];
        } catch (\Throwable $e) {
            $existentes = [];
        }

        foreach ($existentes as $sp) {
            if ((int) ($sp['vlan'] ?? 0) === $vlan && !empty($sp['index'])) {
                $ont?->update(['service_ports' => [['index' => (int) $sp['index'], 'vlan' => $vlan]]]);
                Cache::forget("olt:{$oltId}:all_service_ports");

                return [
                    'status'  => 0,
                    'message' => "El service-port ya estaba creado en la OLT (índice {$sp['index']}, VLAN {$vlan}). Quedó registrado.",
                    'data'    => ['service_port' => (int) $sp['index']],
                ];
            }
        }

        // Índice nuevo: el del intento anterior pudo quedar a medio crear.
        $spIndex = $this->generateServicePort($oltId, $vlan);

        $r = $this->assignONTToClient($oltId, [
            'fsp'          => $fsp,
            'ont_id'       => $ontId,
            'vlan'         => $vlan,
            'service_port' => $spIndex,
            'description'  => $data['description'] ?? ($ont->description ?? ''),
        ]);

        if ($r['status'] === 0 && $ont) {
            $ont->update(['service_ports' => [['index' => $spIndex, 'vlan' => $vlan]]]);
            Cache::forget("olt:{$oltId}:all_service_ports");
        }

        return $r;
    }

    public function assignONTToClient(int $oltId, array $data): array
    {
        try {
            $oltModel = $this->getOltModel($oltId);

            $ok = $this->dispatcher->dispatch($oltId, 'assignToClient', [
                'fsp'         => $data['fsp'],
                'ont_id'      => (int) $data['ont_id'],
                'vlan'        => (int) ($data['vlan'] ?? $oltModel->default_vlan),
                'service_port'=> (int) $data['service_port'],
                'description' => $data['description'] ?? '',
            ]);

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'Service-port creado, ONT asignada al cliente' : 'Error al asignar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT assignONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error asignando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Get all authorized ONTs. Reads from DB first.
     * Only queries the OLT (SNMP → Telnet) when the DB is empty.
     * Pass ?force=1 to skip the DB and re-sync from the OLT.
     */
    public function getAuthorizedONTs(int $oltId, bool $force = false): array
    {
        if (!$force) {
            $dbOnts = OltOnt::where('olt_id', $oltId)
                            ->with('client:id,user_id,names,lastname,dni')
                            ->orderBy('fsp')->orderBy('ont_id')
                            ->get()
                            ->map(fn($o) => array_merge($o->toArray(), ['assigned_client' => $o->client]))
                            ->toArray();
            if (!empty($dbOnts)) {
                return ['status' => 0, 'message' => count($dbOnts) . ' ONTs autorizadas (BD)', 'data' => $dbOnts];
            }
        }

        // BD vacía o force → consultar OLT y guardar
        try {
            $olt    = $this->getOltModel($oltId);
            $reader = $this->snmpReader($olt);
            $onts   = $this->ontsPorSnmp($olt, $reader);

            if (empty($onts)) {
                \Log::info('OLT getAuthorizedONTs SNMP returned empty, falling back to Telnet driver', ['olt_id' => $oltId]);
                $onts = $this->dispatcher->dispatch($oltId, 'getAuthorizedONTs');
            }

            $onts = $this->mergeServicePorts($oltId, $onts);
            $this->syncONTsToDb($oltId, $onts);

            return ['status' => 0, 'message' => count($onts) . ' ONTs autorizadas', 'data' => $onts];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'SNMP_JUMP_UNAVAILABLE')) {
                try {
                    $onts = $this->dispatcher->dispatch($oltId, 'getAuthorizedONTs');
                    $onts = $this->mergeServicePorts($oltId, $onts);
                    $this->syncONTsToDb($oltId, $onts);
                    return ['status' => 0, 'message' => count($onts) . ' ONTs autorizadas (Telnet)', 'data' => $onts];
                } catch (\Throwable $e2) {
                    \Log::error('OLT getAuthorizedONTs Telnet fallback error', ['olt_id' => $oltId, 'error' => $e2->getMessage()]);
                    return ['status' => 1, 'message' => 'Error: ' . $e2->getMessage(), 'data' => null];
                }
            }

            \Log::error('OLT getAuthorizedONTs error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error SNMP: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Persiste el snapshot de ONTs en olt_onts.
     * Hace upsert por (olt_id, fsp, ont_id) y elimina las que ya no existen.
     */
    /**
     * Las ONT por SNMP, con la MIB que corresponda al equipo.
     *
     * El lector de Huawei usa la MIB propia de Huawei; en una OLT de otra marca
     * esos OID no existen y la lista volvía vacía, y la consola tampoco servía
     * si el driver no coincide con el firmware. Las OLT EPON que publican la
     * MIB NSCRTV (C-Data "EasyPath" y compatibles) se leen con ese lector.
     *
     * @return list<array<string,mixed>>
     */
    private function ontsPorSnmp(OltAdmin $olt, HuaweiSnmpReader $reader): array
    {
        if (strtolower((string) $olt->brand) !== 'huawei') {
            $epon = new \App\Services\Olt\SnmpEponNscrtv($reader);

            if ($epon->esCompatible()) {
                return $epon->onts();
            }
        }

        return $reader->getAuthorizedONTs();
    }

    private function syncONTsToDb(int $oltId, array $onts): void
    {
        $now  = now();
        $keys = [];

        foreach ($onts as $ont) {
            $campos = [
                'serial'      => $ont['serial']      ?? null,
                'description' => $ont['description'] ?? null,
                'status'      => $ont['status']      ?? 'offline',
                'synced_at'   => $now,
            ];

            // Los service-ports salen de otra lectura, guardada aparte y que
            // puede ser de antes del alta. Una lista vacía no borra los que ya
            // están registrados: eso dejaba sin service-port a una ONT recién
            // autorizada. Se quitan al eliminar la ONT o el service-port.
            if (!empty($ont['service_ports'])) {
                $campos['service_ports'] = $ont['service_ports'];
            }

            $fila = OltOnt::firstOrNew(['olt_id' => $oltId, 'fsp' => $ont['fsp'], 'ont_id' => $ont['ont_id']]);

            if (!$fila->exists && !isset($campos['service_ports'])) {
                $campos['service_ports'] = [];
            }

            $fila->fill($campos)->save();
            $keys[] = $ont['fsp'] . ':' . $ont['ont_id'];
        }

        // Una lectura que vuelve con mucho menos de lo guardado es una lectura
        // cortada, no ONT eliminadas: no se borra nada.
        $guardadas = OltOnt::where('olt_id', $oltId)->count();

        if ($guardadas > 10 && count($keys) < $guardadas * 0.5) {
            \Log::warning('[OLT] Sincronización incompleta, no se borran ONT', [
                'olt_id' => $oltId, 'leidas' => count($keys), 'guardadas' => $guardadas,
            ]);

            return;
        }

        // Eliminar de BD las ONTs que ya no existen en la OLT. Las tocadas en
        // los últimos 15 minutos se dejan: la lista de la OLT tarda en
        // mostrar una ONT recién autorizada, y borrarla perdía su cliente.
        OltOnt::where('olt_id', $oltId)
            ->where('updated_at', '<', now()->subMinutes(15))
            ->get(['id', 'fsp', 'ont_id'])
            ->each(function ($row) use ($keys) {
                if (!in_array($row->fsp . ':' . $row->ont_id, $keys)) {
                    $row->delete();
                }
            });
    }

    /**
     * Get detailed info + optical power for a single ONT via SNMP.
     * Cached for 1 minute.
     */
    public function getOntInfo(int $oltId, string $fsp, int $ontId): array
    {
        $cacheKey = "olt:{$oltId}:ont_info:{$fsp}:{$ontId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['status' => 0, 'message' => 'Info ONT (caché)', 'data' => $cached];
        }

        try {
            $olt    = $this->getOltModel($oltId);
            $reader = $this->snmpReader($olt);
            $info   = $reader->getOntInfo($fsp, $ontId);
            Cache::put($cacheKey, $info, now()->addMinutes(1));
            return ['status' => 0, 'message' => 'Info ONT obtenida', 'data' => $info];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'SNMP_JUMP_UNAVAILABLE')) {
                try {
                    $info = $this->dispatcher->dispatch($oltId, 'getOntInfo', ['fsp' => $fsp, 'ont_id' => $ontId]);
                    Cache::put($cacheKey, $info, now()->addMinutes(1));
                    return ['status' => 0, 'message' => 'Info ONT obtenida (Telnet)', 'data' => $info];
                } catch (\Throwable $e2) {
                    return ['status' => 1, 'message' => 'Error: ' . $e2->getMessage(), 'data' => null];
                }
            }
            \Log::error('OLT getOntInfo error', ['olt_id' => $oltId, 'fsp' => $fsp, 'ont_id' => $ontId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Get service ports for a specific ONT via Telnet (targeted query, fast).
     * fsp and ontId are required — "display all" is too slow over Telnet.
     */
    public function getServicePorts(int $oltId, ?string $fsp, ?int $ontId): array
    {
        // Sin ONT se devuelven los de toda la OLT. Antes esta pantalla obligaba
        // a elegir una ONT a la vez, así que para ver cómo estaban repartidas
        // las VLAN había que consultar cliente por cliente.
        if ($ontId === null) {
            $todos = $this->getAllServicePorts($oltId);

            if ($fsp !== null) {
                $todos = array_values(array_filter($todos, fn ($sp) => ($sp['fsp'] ?? null) === $fsp));
            }

            return [
                'status'  => 0,
                'message' => count($todos) . ' service-ports',
                'data'    => $todos,
            ];
        }

        if ($fsp === null) {
            return ['status' => 1, 'message' => 'Falta el puerto de la ONT.', 'data' => []];
        }

        $cacheKey = "olt:{$oltId}:service_ports:{$fsp}:{$ontId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['status' => 0, 'message' => count($cached) . ' service-ports (caché)', 'data' => $cached];
        }

        try {
            $ports = $this->dispatcher->dispatch($oltId, 'getServicePorts', ['fsp' => $fsp, 'ont_id' => $ontId]);
            Cache::put($cacheKey, $ports, now()->addMinutes(2));
            return ['status' => 0, 'message' => count($ports) . ' service-ports', 'data' => $ports];
        } catch (\Throwable $e) {
            \Log::error('OLT getServicePorts error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error obteniendo service-ports: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Auto-assign a service port to an ONT.
     * El número se genera con HHMMSS (6 dígitos, siempre único por segundo).
     * Si ya está en uso, incrementa hasta encontrar uno libre en la BD.
     */
    public function autoAssignONT(int $oltId, array $data): array
    {
        try {
            $oltModel = $this->getOltModel($oltId);
            $vlan     = (int) ($data['vlan'] ?? $oltModel->default_vlan);
            $nextSp   = $this->generateServicePort($oltId, $vlan);

            $ok = $this->dispatcher->dispatch($oltId, 'assignToClient', [
                'fsp'          => $data['fsp'],
                'ont_id'       => (int) $data['ont_id'],
                'vlan'         => $vlan,
                'service_port' => $nextSp,
                'description'  => $data['description'] ?? '',
            ]);

            if ($ok) {
                Cache::forget("olt:{$oltId}:auth_onts");
                Cache::forget("olt:{$oltId}:all_service_ports");
                Cache::forget("olt:{$oltId}:service_ports:{$data['fsp']}:{$data['ont_id']}");
                $dbOnt = OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $data['fsp'])
                    ->where('ont_id', (int) $data['ont_id'])
                    ->first();
                if ($dbOnt) {
                    $ports   = $dbOnt->service_ports ?? [];
                    $ports[] = ['index' => $nextSp, 'vlan' => (int) ($data['vlan'] ?? $oltModel->default_vlan)];
                    $dbOnt->update(['service_ports' => $ports, 'synced_at' => now()]);
                }
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? "Service-port {$nextSp} asignado correctamente" : 'Error al asignar ONT',
                'data'    => $ok ? ['service_port' => $nextSp] : null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT autoAssignONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Genera un número de service-port de 4 dígitos.
     * Composición: [último dígito año][último dígito día][último dígito minuto][último dígito segundo]
     * Ejemplo: año 2026, día 16, minuto 32, segundo 45 → 6·6·2·5 = 6625
     * Si ya está en uso, incrementa hasta encontrar uno libre (máx 9999).
     */
    private function generateServicePort(int $oltId, int $vlan = 0): int
    {
        $used = OltOnt::where('olt_id', $oltId)
            ->whereNotNull('service_ports')
            ->get('service_ports')
            ->flatMap(fn($o) => collect($o->service_ports)->pluck('index'))
            ->filter()
            ->values()
            ->all();

        $d1 = (int) date('Y') % 10; // último dígito del año
        $d2 = (int) date('j') % 10; // último dígito del día
        $d3 = (int) date('i') % 10; // último dígito del minuto
        $d4 = (int) date('s') % 10; // último dígito del segundo

        $candidate = (int) "{$d1}{$d2}{$d3}{$d4}";

        while (in_array($candidate, $used)) {
            $candidate = ($candidate % 9999) + 1;
        }

        return $candidate;
    }

    /**
     * Transfer an ONT from one port to another. Invalidates auth_onts cache.
     */
    public function transferONT(int $oltId, array $data): array
    {
        try {
            $result = $this->dispatcher->dispatch($oltId, 'transferONT', [
                'from_fsp' => $data['from_fsp'],
                'ont_id'   => (int) $data['ont_id'],
                'to_fsp'   => $data['to_fsp'],
            ]);

            if ($result['success']) {
                Cache::forget("olt:{$oltId}:auth_onts");
                Cache::forget("olt:{$oltId}:unauth_onts");
                // Mover el registro en BD al nuevo fsp sin re-sync completo
                OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $data['from_fsp'])
                    ->where('ont_id', (int) $data['ont_id'])
                    ->update(['fsp' => $data['to_fsp'], 'service_ports' => [], 'synced_at' => now()]);
            }

            return [
                'status'  => $result['success'] ? 0 : 1,
                'message' => $result['message'],
                'data'    => $result,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT transferONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error transfiriendo ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Deactivate an ONT. Invalidates auth_onts cache.
     */
    public function deactivateONT(int $oltId, array $data): array
    {
        try {
            $ok = $this->dispatcher->dispatch($oltId, 'deactivateONT', [
                'fsp'    => $data['fsp'],
                'ont_id' => (int) $data['ont_id'],
            ]);

            if ($ok) {
                Cache::forget("olt:{$oltId}:auth_onts");
                OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $data['fsp'])
                    ->where('ont_id', (int) $data['ont_id'])
                    ->update(['status' => 'offline', 'synced_at' => now()]);
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'ONT desactivada correctamente' : 'Error al desactivar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT deactivateONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error desactivando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Activate an ONT. Invalidates auth_onts cache.
     */
    public function activateONT(int $oltId, array $data): array
    {
        try {
            $ok = $this->dispatcher->dispatch($oltId, 'activateONT', [
                'fsp'    => $data['fsp'],
                'ont_id' => (int) $data['ont_id'],
            ]);

            if ($ok) {
                Cache::forget("olt:{$oltId}:auth_onts");
                OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $data['fsp'])
                    ->where('ont_id', (int) $data['ont_id'])
                    ->update(['status' => 'online', 'synced_at' => now()]);
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'ONT activada correctamente' : 'Error al activar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT activateONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error activando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function getOltModel(int $id): OltAdmin
    {
        $row = $this->repo->findById($id);
        if (!$row) throw new \RuntimeException("OLT {$id} no encontrada");
        return OltAdmin::find($id);
    }

    private function snmpReader(OltAdmin $olt): HuaweiSnmpReader
    {
        return new HuaweiSnmpReader($olt);
    }

    /**
     * Obtiene todos los service-ports del OLT con un solo comando Telnet
     * y los añade a cada ONT como 'service_ports' => [['index'=>100,'vlan'=>200], ...].
     * Se cachea separado para no invalidar el listado SNMP cada vez.
     */
    /**
     * Todos los service-ports del equipo, con la misma caché que usa el
     * listado: así preguntarlo no cuesta una consulta más a la OLT.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getAllServicePorts(int $oltId): array
    {
        $cacheKey = "olt:{$oltId}:all_service_ports";
        $todos    = Cache::get($cacheKey);

        if (is_array($todos) && $todos !== []) {
            return $todos;
        }

        // Se recorren los puertos que tienen ONT registrada, uno por uno.
        // "display service-port all" en una OLT con cientos de ONT devuelve
        // miles de líneas paginadas —por eso estaba deshabilitado y devolvía
        // vacío—; por puerto el volumen es chico y son pocas consultas.
        $puertos = OltOnt::where('olt_id', $oltId)
            ->distinct()
            ->orderBy('fsp')
            ->pluck('fsp')
            ->filter()
            ->all();

        $todos = [];

        foreach ($puertos as $fsp) {
            try {
                $delPuerto = $this->dispatcher->dispatch($oltId, 'getServicePorts', ['fsp' => $fsp]);

                foreach ((array) $delPuerto as $sp) {
                    // La respuesta trae el puerto real en "port" —"gpon0/0/1"—
                    // y no siempre es el que se consultó: la OLT devuelve
                    // service-ports de otros puertos en la misma salida. Antes
                    // se les ponía a todos el puerto preguntado y el cruce con
                    // las ONT no coincidía nunca.
                    if (preg_match('#(\d+/\d+/\d+)#', (string) ($sp['port'] ?? ''), $m)) {
                        $sp['fsp'] = $m[1];
                    } else {
                        $sp['fsp'] = $sp['fsp'] ?? $fsp;
                    }

                    $todos[] = $sp;
                }
            } catch (\Throwable $e) {
                \Log::warning('OLT getAllServicePorts: falló un puerto', [
                    'olt_id' => $oltId, 'fsp' => $fsp, 'error' => $e->getMessage(),
                ]);
            }
        }

        // Sólo se guarda si trajo algo: cachear un vacío hacía que todas las
        // ONT parecieran sin service-port durante los cinco minutos.
        if ($todos !== []) {
            Cache::put($cacheKey, $todos, now()->addMinutes(10));
        }

        return $todos;
    }

    private function mergeServicePorts(int $oltId, array $onts): array
    {
        $cacheKey = "olt:{$oltId}:all_service_ports";

        try {
            $allPorts = Cache::get($cacheKey);

            if ($allPorts === null) {
                $allPorts = $this->dispatcher->dispatch($oltId, 'getServicePorts');
                Cache::put($cacheKey, $allPorts, now()->addMinutes(5));
            }

            // Construir mapa fsp:ont_id → [service_ports]
            $map = [];
            foreach ($allPorts as $sp) {
                $key = ($sp['fsp'] ?? '') . ':' . ($sp['ont_id'] ?? '');
                $map[$key][] = ['index' => $sp['index'] ?? null, 'vlan' => $sp['vlan'] ?? null];
            }

            foreach ($onts as &$ont) {
                $key = ($ont['fsp'] ?? '') . ':' . ($ont['ont_id'] ?? '');
                $ont['service_ports'] = $map[$key] ?? [];
            }
            unset($ont);

        } catch (\Throwable $e) {
            // Si Telnet falla, devolvemos los ONTs sin service_ports (no bloqueante)
            \Log::warning('mergeServicePorts failed, skipping', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            foreach ($onts as &$ont) { $ont['service_ports'] = []; }
            unset($ont);
        }

        return $onts;
    }

    /**
     * Clientes que todavía no tienen una ONT vinculada.
     *
     * Al autorizar una ONT hay que decir de quién es, y elegir de una lista de
     * los que faltan evita el error de vincularla a alguien que ya tiene la
     * suya. El nombre sigue viajando a la OLT como descripción; lo que se
     * guarda de más es el vínculo, para saber después qué puerto atiende a
     * cada cliente.
     *
     * @return array<int,array<string,mixed>>
     */
    public function clientesSinOnt(int $oltId, ?string $busca = null): array
    {
        $companyId = getSessionCompanyId();

        // Quiénes ya tienen una ONT. No se los saca de la lista: se los marca,
        // para que se vean todos los clientes y quede claro cuál está tomado.
        $tomados = OltOnt::whereNotNull('user_data_id')
            ->pluck('user_data_id')
            ->flip()
            ->all();

        return \Illuminate\Support\Facades\DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('internet_plans as p', 'p.id', '=', 'ud.internet_plans_id')
            ->where('u.company_id', $companyId)
            ->where('ud.active', 1)
            ->when($busca, function ($q) use ($busca) {
                $t = '%' . trim($busca) . '%';

                $q->where(fn ($w) => $w->where('ud.names', 'like', $t)
                    ->orWhere('ud.lastname', 'like', $t)
                    ->orWhere('ud.dni', 'like', $t)
                    ->orWhere('ud.address', 'like', $t));
            })
            ->orderBy('ud.names')
            ->get([
                'ud.id',
                'ud.user_id',
                'ud.names',
                'ud.lastname',
                'ud.dni',
                'ud.address',
                'p.plan_name',
            ])
            // `id` es el id de USUARIO, no el de la ficha: olt_onts.user_data_id
            // guarda el id de usuario (ver OltOnt::client). Devolver el de la
            // ficha hacía que el alta vinculara la ONT a otro cliente —el que
            // tiene ese número como usuario— y que "ya tiene ONT" no marcara a
            // nadie bien.
            ->map(fn ($c) => [
                'id'        => (int) $c->user_id,
                'user_id'   => (int) $c->user_id,
                'ficha_id'  => (int) $c->id,
                'nombre'    => trim($c->names . ' ' . $c->lastname),
                'dni'       => $c->dni,
                'direccion' => $c->address,
                'plan'      => $c->plan_name,
                'tiene_ont' => isset($tomados[$c->user_id]),
            ])
            ->all();
    }

    /**
     * Asignar (o desasignar) un cliente a una ONT registrada.
     * user_data_id = null → desasignar.
     */
    public function assignClientToOnt(int $oltId, string $fsp, int $ontId, ?int $userDataId): array
    {
        $ont = OltOnt::where('olt_id', $oltId)
                     ->where('fsp', $fsp)
                     ->where('ont_id', $ontId)
                     ->first();

        if (!$ont) {
            return ['status' => 1, 'message' => 'ONT no encontrada en la base de datos.', 'data' => null];
        }

        $ont->update(['user_data_id' => $userDataId]);

        return ['status' => 0, 'message' => $userDataId ? 'Cliente asignado correctamente.' : 'Cliente desasignado.', 'data' => $ont->fresh()];
    }

    /**
     * Obtener el equipo ONT asignado a un cliente.
     */
    public function getOntByUserId(int $userDataId): array
    {
        $ont = OltOnt::where('user_data_id', $userDataId)
                     ->with('olt:id,name,host')
                     ->first();

        if (!$ont) {
            return ['status' => 0, 'message' => 'Sin equipo ONT asignado.', 'data' => null];
        }

        return ['status' => 0, 'message' => 'Equipo ONT encontrado.', 'data' => $ont];
    }

    /**
     * El equipo del cliente: fabricante, modelo, versiones, WiFi y foto.
     *
     * @param int $userId users.id
     */
    public function equipoDeCliente(int $userId, bool $refrescar = false): array
    {
        $ont = $this->ontDelCliente($userId);

        if (!$ont) {
            return ['status' => 0, 'message' => 'Sin equipo ONT asignado.', 'data' => null];
        }

        $equipo = \App\Services\Olt\EquipoDeOnt::de(
            $ont->olt, (string) $ont->fsp, (int) $ont->ont_id, (int) getSessionCompanyId(), $refrescar
        );

        return ['status' => 0, 'message' => $equipo['error'] ?? 'Equipo consultado', 'data' => $equipo];
    }

    /** Foto de un modelo de ONT, para todas las de ese modelo en la empresa. */
    public function guardarFotoDeModelo(string $fabricante, string $modelo, \Illuminate\Http\UploadedFile $archivo): array
    {
        $url = \App\Services\Olt\EquipoDeOnt::guardarFoto((int) getSessionCompanyId(), $fabricante, $modelo, $archivo);

        return ['status' => 0, 'message' => 'Foto guardada para el modelo ' . $modelo, 'data' => ['foto' => $url]];
    }

    public function borrarFotoDeModelo(string $fabricante, string $modelo): array
    {
        \App\Services\Olt\EquipoDeOnt::borrarFoto((int) getSessionCompanyId(), $fabricante, $modelo);

        return ['status' => 0, 'message' => 'Foto quitada', 'data' => null];
    }

    /** La ONT vinculada al cliente, sólo si su OLT es de la empresa en sesión. */
    private function ontDelCliente(int $userId): ?OltOnt
    {
        return OltOnt::where('user_data_id', $userId)
            ->whereHas('olt', fn ($q) => $q->where('company_id', getSessionCompanyId()))
            ->with('olt')
            ->first();
    }

    /**
     * La ONT del cliente con su estado real, preguntándole a la OLT.
     *
     * @param int $userId users.id: es lo que guarda olt_onts.user_data_id
     */
    public function ontEnVivoDeCliente(int $userId, bool $refrescar = false): array
    {
        $ont = $this->ontDelCliente($userId);

        if (!$ont) {
            return ['status' => 0, 'message' => 'Sin equipo ONT asignado.', 'data' => null];
        }

        $vivo = \App\Services\Olt\EstadoDeUnaOnt::de($ont->olt, (string) $ont->fsp, (int) $ont->ont_id, $refrescar);

        return [
            'status'  => 0,
            'message' => $vivo['error'] ?? 'ONT consultada',
            'data'    => [
                'olt' => [
                    'id'    => $ont->olt->id,
                    'name'  => $ont->olt->name,
                    'brand' => $ont->olt->brand,
                ],
                'fsp'         => $ont->fsp,
                'ont_id'      => $ont->ont_id,
                'serial'      => $ont->serial,
                'description' => $ont->description,
                'guardado'    => $ont->status,
                'vivo'        => $vivo,
            ],
        ];
    }
}
