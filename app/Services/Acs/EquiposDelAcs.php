<?php

namespace App\Services\Acs;

use App\Services\Red\AprovisionamientoDeOnt as Aprovisionamiento;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Los equipos del ACS que son de una empresa, y lo que se puede hacer con ellos.
 *
 * GenieACS es uno solo para toda la plataforma y no sabe de empresas: un
 * equipo se reconoce como de la empresa cuando su IP de WAN es la de uno de
 * sus clientes, su usuario PPPoE es el de uno de sus clientes o su serial
 * está en una de sus OLT. Lo que no se reconoce no se muestra: en el ACS hay
 * además decenas de "equipos" que registran los escáneres de internet.
 */
class EquiposDelAcs
{
    /** Cada cuánto se vuelve a leer la lista del ACS. */
    private const VIGENCIA = 60;

    /** Sin reportar en este tiempo, el equipo se muestra como sin conexión con el ACS. */
    public const REPORTA_CADA = 2 * 3600;

    private const FABRICANTES = [
        'huawei' => 'Huawei', 'cdt' => 'C-Data', 'cdtc' => 'C-Data', 'zte' => 'ZTE',
        'fiberhome' => 'FiberHome', 'tp-link' => 'TP-Link', 'nokia' => 'Nokia', 'vsol' => 'V-SOL',
        'sagemcom' => 'Sagemcom', 'sdmc' => 'SDMC',
    ];

    public function __construct(private int $companyId, private ?GenieAcs $acs = null)
    {
        $this->acs ??= GenieAcs::deEmpresa($companyId);
    }

    // ── Lista ─────────────────────────────────────────────────────────────

    /**
     * Los equipos de la empresa, con su cliente.
     *
     * @return list<array<string,mixed>>
     */
    public function lista(): array
    {
        $vinculos = $this->vinculos();
        $filas = [];

        foreach ($this->todos() as $d) {
            $fila = $this->resumen($d, $vinculos);

            if ($fila) {
                $filas[] = $fila;
            }
        }

        usort($filas, fn ($a, $b) => strcmp((string) $b['ultimo_reporte'], (string) $a['ultimo_reporte']));

        return $filas;
    }

    /** El equipo del cliente, si el ACS tiene uno que se le pueda atribuir. */
    public function deCliente(int $userId): ?array
    {
        foreach ($this->lista() as $fila) {
            if (($fila['cliente']['user_id'] ?? null) === $userId) {
                return $this->detalle($fila['id']);
            }
        }

        return null;
    }

    /**
     * Clientes con ONT que todavía no reportan al TR-069.
     *
     * La dirección del ACS hay que cargarla una vez en cada equipo: ni la OLT
     * ni el PPPoE la pueden empujar. Esta lista es la que va bajando el
     * técnico en cada visita, y se vacía sola a medida que los equipos entran.
     *
     * @return array{pendientes: list<array<string,mixed>>, con_tr069: int, total: int}
     */
    public function pendientes(): array
    {
        $enElAcs = collect($this->lista());

        // Un equipo se reconoce por el serial de su ONT o por su cliente.
        $serialesEnAcs = $enElAcs->pluck('serial')->filter()->map(fn ($s) => self::serial((string) $s))->flip();
        $clientesEnAcs = $enElAcs->pluck('cliente.user_id')->filter()->flip();

        $filas = DB::table('olt_onts as o')
            ->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
            ->join('user_data as ud', 'ud.user_id', '=', 'o.user_data_id')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('a.company_id', $this->companyId)
            ->where('ud.active', 1)
            ->orderBy('a.name')->orderBy('o.fsp')->orderBy('o.ont_id')
            ->get([
                'o.id', 'o.fsp', 'o.ont_id', 'o.serial', 'o.status', 'o.user_data_id',
                'a.name as olt', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.address', 'ud.phone',
            ]);

        $pendientes = [];

        foreach ($filas as $f) {
            if ($serialesEnAcs->has(self::serial((string) $f->serial)) || $clientesEnAcs->has((int) $f->user_data_id)) {
                continue;
            }

            $pendientes[] = [
                'ont_id_db' => (int) $f->id,
                'olt'       => $f->olt,
                'fsp'       => $f->fsp,
                'ont'       => (int) $f->ont_id,
                'serial'    => $f->serial,
                'estado'    => $f->status,
                'cliente'   => [
                    'user_id'   => (int) $f->user_data_id,
                    'nombre'    => trim($f->names . ' ' . $f->lastname),
                    'documento' => $f->dni,
                    'direccion' => $f->address,
                    'telefono'  => $f->phone,
                ],
            ];
        }

        return [
            'pendientes' => $pendientes,
            'con_tr069'  => $enElAcs->whereNotNull('cliente')->count(),
            'total'      => $filas->count(),
        ];
    }

    // ── Detalle ───────────────────────────────────────────────────────────

    /**
     * Todo lo que el ACS sabe del equipo, si es de la empresa.
     *
     * @return array<string,mixed>|null
     */
    public function detalle(string $id): ?array
    {
        $resumen = $this->propio($id);

        if (!$resumen) {
            return null;
        }

        $d = $this->acs->dispositivo($id);

        if (!$d) {
            return null;
        }

        $raiz = isset($d['InternetGatewayDevice']) ? 'InternetGatewayDevice' : 'Device';

        return array_merge($resumen, [
            'hardware'  => self::v($d, "{$raiz}.DeviceInfo.HardwareVersion"),
            'software'  => self::v($d, "{$raiz}.DeviceInfo.SoftwareVersion"),
            'encendido_hace' => self::entero(self::v($d, "{$raiz}.DeviceInfo.UpTime")),
            'ultimo_arranque' => $d['_lastBoot'] ?? null,
            'registrado' => $d['_registered'] ?? null,
            'wan'       => self::wan($d, $raiz),
            'wifi'      => self::wifi($d, $raiz),
            'equipos'   => self::hosts($d, $raiz),
            'optica'    => self::optica($d),
            'cuentas'   => $this->cuentasConPedido($id, $d, $raiz),
            'url_acs'   => self::v($d, "{$raiz}.ManagementServer.URL"),
            // Por dónde el ACS le habla al equipo para aplicar algo al momento.
            'url_conexion' => self::v($d, "{$raiz}.ManagementServer.ConnectionRequestURL"),
            'intervalo' => self::entero(self::v($d, "{$raiz}.ManagementServer.PeriodicInformInterval")),
        ]);
    }

    /**
     * Las cuentas, pidiéndoselas al equipo la primera vez.
     *
     * El ACS sólo guarda lo que alguna vez pidió, así que un equipo al que
     * nunca se le preguntó no las tiene. En vez de mostrar el hueco y esperar
     * a que alguien toque refrescar, se piden al pasar: aparecen solas en la
     * próxima carga. Se pide una vez por hora como mucho, para no encolarle
     * una tarea al equipo cada vez que se abre la ficha.
     */
    private function cuentasConPedido(string $id, array $d, string $raiz): array
    {
        $cuentas = self::cuentasDelEquipo($d, $raiz);

        if ($cuentas) {
            return $cuentas;
        }

        $marca = 'acs:cuentas-pedidas:' . md5($id);

        if (!\Illuminate\Support\Facades\Cache::has($marca)) {
            \Illuminate\Support\Facades\Cache::put($marca, true, now()->addHour());

            try {
                // Sin esperar: esto corre al abrir la ficha del cliente y el
                // dato no hace falta ahora. Llega para la próxima vuelta.
                $this->acs->encolar($id, ['name' => 'refreshObject', 'objectName' => "{$raiz}.Users"]);
            } catch (\Throwable $e) {
                // Un equipo dormido no contesta: se vuelve a intentar en una hora.
            }
        }

        return [];
    }

    /**
     * Las cuentas para entrar a la página del equipo.
     *
     * El TR-069 las publica en «Users.User.{i}» y muchos equipos las devuelven
     * en texto plano —un ZTE F680 entrega tres: la del operador que lo vendió,
     * la de administración y la del propio equipo—. Hasta ahora el técnico
     * tenía que pedírselas al cliente o buscarlas en la etiqueta.
     *
     * Las que el equipo no entrega salen sin clave: se ve el usuario y se
     * sabe que existe, que ya es más que nada.
     *
     * @return list<array{indice:int, usuario:?string, clave:?string, habilitada:?bool}>
     */
    private static function cuentasDelEquipo(array $d, string $raiz): array
    {
        $cuentas = [];

        foreach (self::hijos($d, "{$raiz}.Users.User") as $i) {
            $usuario = self::v($d, "{$raiz}.Users.User.{$i}.Username");

            if (($usuario ?? '') === '') {
                continue;
            }

            $cuentas[] = [
                'indice'     => (int) $i,
                'usuario'    => $usuario,
                'clave'      => self::v($d, "{$raiz}.Users.User.{$i}.Password") ?: null,
                'habilitada' => self::existe($d, "{$raiz}.Users.User.{$i}.Enable")
                    ? self::booleano(self::v($d, "{$raiz}.Users.User.{$i}.Enable"))
                    : null,
            ];
        }

        return $cuentas;
    }

    /**
     * El documento completo del equipo, si es de la empresa.
     *
     * Trae todo lo que el equipo publica —incluidas las claves de telnet y ssh
     * del fabricante—, así que es para uso interno del servidor: no se manda
     * al panel ni al portal.
     *
     * @return array<string,mixed>|null
     */
    public function documento(string $id): ?array
    {
        return $this->propio($id) ? $this->acs->dispositivo($id) : null;
    }

    // ── Acciones ──────────────────────────────────────────────────────────

    /**
     * Le pide al equipo los datos que muestra el panel.
     *
     * No se pide el árbol entero: hay equipos con una rama que responde error
     * —una C-Data contesta 9002 en X_CMS_PrivateNode— y esa falla corta la
     * sesión, así que las órdenes que venían detrás nunca llegaban y se
     * acumulaban. Se piden las ramas que se usan, y antes se limpia lo que
     * haya quedado trabado.
     */
    public function refrescar(string $id): array
    {
        $this->exigirPropio($id);
        $raiz = $this->raiz($id);

        $this->limpiarCola($id);

        $ramas = $raiz === 'InternetGatewayDevice'
            ? [
                'InternetGatewayDevice.DeviceInfo',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration',
                'InternetGatewayDevice.LANDevice.1.Hosts',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice',
                // Las cuentas para entrar a la página del equipo: el ACS sólo
                // guarda lo que alguna vez pidió, y esto nunca se pedía.
                'InternetGatewayDevice.Users',
            ]
            : ['Device.DeviceInfo', 'Device.WiFi', 'Device.Hosts', 'Device.IP', 'Device.Users'];

        // Las ramas se dejan todas en la cola sin esperar, y sólo la última
        // le avisa al equipo: GenieACS abre una sola sesión y en ella corren
        // las cinco. Antes cada rama esperaba su propio aviso —hasta 45 s por
        // rama, más de tres minutos para un clic— con un proceso de PHP
        // tomado todo ese rato. Con veinte técnicos refrescando a la vez se
        // acababan los procesos y se caía la plataforma entera.
        $ultima = array_pop($ramas);

        foreach ($ramas as $rama) {
            $this->acs->encolar($id, ['name' => 'refreshObject', 'objectName' => $rama]);
        }

        // La espera es corta a propósito: si el equipo no contesta en 20 s, la
        // cola ya quedó armada y se aplica en cuanto se reporte. La pantalla
        // no se queda colgada esperando a un equipo dormido.
        return $this->acs->tarea($id, ['name' => 'refreshObject', 'objectName' => $ultima], 20);
    }

    /** Borra las tareas viejas del equipo: una trabada bloquea a las demás. */
    private function limpiarCola(string $id): void
    {
        try {
            foreach ($this->acs->tareasPendientes($id) as $tarea) {
                $this->acs->borrarTarea((string) $tarea['_id']);
            }
        } catch (\Throwable $e) {
            Log::warning('[ACS] No se pudo limpiar la cola del equipo', ['equipo' => $id, 'error' => $e->getMessage()]);
        }
    }

    public function reiniciar(string $id): array
    {
        $this->exigirPropio($id);

        return $this->acs->tarea($id, ['name' => 'reboot']);
    }

    /**
     * Cambia el canal de una red WiFi. Con $canal null vuelve a automático.
     *
     * Sólo con los parámetros que el propio equipo publica: no todos dejan
     * elegir canal por TR-069 y un valor fuera de su lista lo rechaza entero.
     */
    public function cambiarCanal(string $id, int $indice, ?int $canal): array
    {
        $this->exigirPropio($id);

        $d = $this->acs->dispositivo($id);
        $red = collect(self::wifi($d, $this->raiz($id, $d)))->firstWhere('indice', $indice);

        if (!$red) {
            throw new \InvalidArgumentException('El equipo no tiene esa red WiFi.');
        }

        $valores = [];

        if ($canal === null) {
            if (!$red['ruta_canal_auto']) {
                throw new \InvalidArgumentException('Este equipo no permite elegir el canal automático desde aquí.');
            }
            $valores[] = [$red['ruta_canal_auto'], true, 'xsd:boolean'];
        } else {
            if (!$red['ruta_canal']) {
                throw new \InvalidArgumentException('Este equipo no permite cambiar el canal desde aquí.');
            }
            if ($red['canales_posibles'] && !in_array($canal, $red['canales_posibles'], true)) {
                throw new \InvalidArgumentException('Ese canal no está disponible en esta red.');
            }
            if ($red['ruta_canal_auto']) {
                $valores[] = [$red['ruta_canal_auto'], false, 'xsd:boolean'];
            }
            $valores[] = [$red['ruta_canal'], $canal, 'xsd:unsignedInt'];
        }

        return $this->acs->tarea($id, ['name' => 'setParameterValues', 'parameterValues' => $valores]);
    }

    /** "1-13" o "36,40,44" → lista de canales; null si el equipo no la publica. */
    private static function canales(mixed $crudo): ?array
    {
        if (!is_string($crudo) || trim($crudo) === '') {
            return null;
        }

        $lista = [];

        foreach (explode(',', $crudo) as $parte) {
            $parte = trim($parte);

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $parte, $m)) {
                $lista = array_merge($lista, range((int) $m[1], min((int) $m[2], 200)));
            } elseif (ctype_digit($parte)) {
                $lista[] = (int) $parte;
            }
        }

        $lista = array_values(array_unique(array_filter($lista, fn ($c) => $c > 0)));

        return $lista ?: null;
    }

    /**
     * Cambia el nombre y/o la clave de una red WiFi.
     *
     * La ruta de la clave depende del fabricante: Huawei la tiene en
     * PreSharedKey.1.KeyPassphrase y C-Data directamente en KeyPassphrase. Se
     * usa la que el equipo publica.
     */
    /**
     * Cambia el nombre o la contraseña de una red WiFi.
     *
     * Con $todas, la contraseña se aplica a todas las redes activas del equipo
     * que la dejan cambiar (2.4 y 5 GHz): el cliente usa la misma y no tiene
     * que cambiarla dos veces. Va en un solo envío, así el equipo no queda con
     * una red cambiada y la otra no. El nombre es siempre sólo de la elegida.
     */
    public function cambiarWifi(string $id, int $indice, ?string $ssid, ?string $clave, bool $todas = false, ?bool $oculta = null): array
    {
        $this->exigirPropio($id);

        $d = $this->acs->dispositivo($id);

        // Para elegir dónde va la clave hace falta la rama WiFi completa: si el
        // equipo todavía no la mandó, se le pide antes (es sólo una lectura).
        if ($clave !== null && $clave !== '' && isset($d['InternetGatewayDevice'])
            && !self::existe($d, "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$indice}.BSSID")) {
            try {
                $this->acs->tarea($id, ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration'], 20);
                $d = $this->acs->dispositivo($id) ?? $d;
            } catch (\Throwable $e) {
                Log::info('[ACS] No se pudo leer el WiFi antes de cambiar la clave', ['equipo' => $id, 'error' => $e->getMessage()]);
            }
        }
        $redes = collect(self::wifi($d, $this->raiz($id, $d)));
        $red = $redes->firstWhere('indice', $indice);

        if (!$red) {
            throw new \InvalidArgumentException('El equipo no tiene esa red WiFi.');
        }

        $valores = [];

        // Acá pasa todo: la ficha del cliente, el portal y el aprovisionamiento.
        // La revisión va en este punto y no sólo en cada formulario, para que
        // ninguna ruta nueva se salte la regla sin que nadie se dé cuenta.
        if ($ssid !== null && $ssid !== '') {
            if ($problema = Aprovisionamiento::problemaDelNombreWifi($ssid)) {
                throw new \InvalidArgumentException($problema);
            }
            $valores[] = [$red['ruta_ssid'], $ssid, 'xsd:string'];
        }

        if ($clave !== null && $clave !== '') {
            if ($problema = Aprovisionamiento::problemaDeLaClaveWifi($clave)) {
                throw new \InvalidArgumentException($problema);
            }
            if (!$red['ruta_clave']) {
                throw new \InvalidArgumentException('Este equipo no permite cambiar la contraseña por TR-069.');
            }

            // Sólo redes que el equipo confirmó encendidas. Hay equipos con redes
            // secundarias o de invitados cuyo estado todavía no se leyó: tocarlas
            // les cambiaría la contraseña a redes que el cliente ni usa.
            $destinos = $todas
                ? $redes->filter(fn ($r) => $r['ruta_clave'] && $r['activo'] === true)->pluck('rutas_clave')->flatten()->merge($red['rutas_clave'])->unique()->values()->all()
                : $red['rutas_clave'];

            foreach ($destinos as $ruta) {
                $valores[] = [$ruta, $clave, 'xsd:string'];
            }
        }

        // Ocultar la red: el equipo deja de anunciar su nombre. Se aplica a la
        // red elegida, o a todas cuando el cliente pidió usar lo mismo en todas
        // —si no, queda la de 5 GHz visible y la de 2.4 escondida—.
        if ($oculta !== null) {
            $destinos = $todas
                ? $redes->filter(fn ($r) => ($r['ruta_oculta'] ?? null) && $r['activo'] === true)->pluck('ruta_oculta')
                    ->merge([$red['ruta_oculta'] ?? null])->filter()->unique()->values()->all()
                : array_filter([$red['ruta_oculta'] ?? null]);

            if (!$destinos) {
                throw new \InvalidArgumentException('Este equipo no permite ocultar la red por TR-069.');
            }

            foreach ($destinos as $ruta) {
                // El parámetro dice si se anuncia: ocultar es apagarlo.
                $valores[] = [$ruta, $oculta ? 'false' : 'true', 'xsd:boolean'];
            }
        }

        if (!$valores) {
            throw new \InvalidArgumentException('No hay nada que cambiar.');
        }

        return $this->acs->tarea($id, ['name' => 'setParameterValues', 'parameterValues' => $valores]);
    }

    // ── Pertenencia ───────────────────────────────────────────────────────

    /** El resumen del equipo si es de la empresa; null si no. */
    private function propio(string $id): ?array
    {
        foreach ($this->lista() as $fila) {
            if ($fila['id'] === $id) {
                return $fila;
            }
        }

        return null;
    }

    private function exigirPropio(string $id): void
    {
        if (!$this->propio($id)) {
            throw new \InvalidArgumentException('Ese equipo no es de tu empresa.');
        }
    }

    private function raiz(string $id, ?array $d = null): string
    {
        $d ??= $this->acs->dispositivo($id);

        return isset($d['InternetGatewayDevice']) ? 'InternetGatewayDevice' : 'Device';
    }

    /**
     * Lo que permite reconocer un equipo como de la empresa.
     *
     * @return array{ips:array<string,object>, pppoe:array<string,object>, series:array<string,object>}
     */
    private function vinculos(): array
    {
        return Cache::remember("acs:vinculos:{$this->companyId}", self::VIGENCIA, function () {
            $clientes = DB::table('user_data as ud')
                ->join('users as u', 'u.id', '=', 'ud.user_id')
                ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
                ->where('u.company_id', $this->companyId)
                ->where('ud.active', 1)
                ->get(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.pppoe_user', 't.ip']);

            $ips = [];
            $pppoe = [];

            foreach ($clientes as $c) {
                $c->nombre = trim($c->names . ' ' . $c->lastname);

                if ($c->ip) {
                    $ips[$c->ip] = $c;
                }

                if ($c->pppoe_user) {
                    $pppoe[strtolower($c->pppoe_user)] = $c;
                }
            }

            $porUsuario = $clientes->keyBy('user_id');
            $series = [];

            foreach (DB::table('olt_onts as o')
                ->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
                ->where('a.company_id', $this->companyId)
                ->get(['o.serial', 'o.fsp', 'o.ont_id', 'o.user_data_id', 'a.name as olt']) as $o) {
                $o->cliente = $o->user_data_id ? ($porUsuario[$o->user_data_id] ?? null) : null;
                $series[self::serial((string) $o->serial)] = $o;
            }

            // Las redes que la empresa declaró en el asistente. Sirven para no
            // atribuirle un equipo de otra empresa cuando las dos usan el
            // mismo rango privado, que con 192.168.1.x pasa todo el tiempo.
            $propias = \App\Models\AcsServidor::where('company_id', $this->companyId)->value('redes');
            $propias = collect(is_array($propias) ? $propias : [])
                ->filter(fn ($r) => $r['elegida'] ?? false)->pluck('red')->values()->all();

            // Sin redes declaradas, las del túnel de la empresa. Las traducidas
            // cuentan por su red real: el equipo informa su IP real, no la
            // virtual con la que lo ve el servidor.
            if (!$propias) {
                $propias = \App\Models\VpnTunel::where('company_id', $this->companyId)->get()
                    ->flatMap(fn ($t) => array_map(fn ($r) => $t->realDe((string) $r), $t->redes_remotas ?? []))
                    ->unique()->values()->all();
            }

            // Las que también usa otra empresa: una IP ahí no dice de quién es
            // el equipo, así que no alcanza para atribuirlo por IP.
            $ajenas = \App\Models\VpnTunel::where('company_id', '!=', $this->companyId)->get()
                ->flatMap(fn ($t) => array_map(fn ($r) => $t->realDe((string) $r), $t->redes_remotas ?? []))
                ->merge(\App\Models\GestionRemota::where('company_id', '!=', $this->companyId)->whereNotNull('red')->pluck('red'))
                ->filter()->unique()->values()->all();

            return compact('ips', 'pppoe', 'series') + ['propias' => $propias, 'ajenas' => $ajenas];
        });
    }

    /**
     * Todos los equipos del ACS con lo necesario para reconocerlos. Es la
     * misma lista para todas las empresas, así que se guarda una sola vez.
     *
     * @return list<array<string,mixed>>
     */
    private function todos(): array
    {
        return Cache::remember("acs:equipos:{$this->companyId}", self::VIGENCIA, fn () => $this->acs->dispositivos([], [
            '_id', '_deviceId', '_lastInform', '_lastBoot', '_registered',
            'InternetGatewayDevice.WANDevice', 'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            // La MAC de la ONT: las C-Data EPON se registran en la OLT por MAC y
            // en el ACS con otro serial (DF1E-…); por acá se emparejan.
            'InternetGatewayDevice.ManagementServer.mac', 'InternetGatewayDevice.X_CATV_UserInfo.UserName',
            'Device.PPP.Interface', 'Device.IP.Interface', 'Device.DeviceInfo.SoftwareVersion',
        ]));
    }

    /** @return array<string,mixed>|null  null si el equipo no es de la empresa */
    private function resumen(array $d, array $vinculos): ?array
    {
        $ips = [];
        $usuarios = [];
        $macs = [];

        foreach (self::parametros($d) as $ruta => $valor) {
            if ($valor === '' || $valor === null) {
                continue;
            }
            if (preg_match('/(ManagementServer\.mac|X_CATV_UserInfo\.UserName)$/i', $ruta)
                && preg_match('/^([0-9A-Fa-f]{2}[:-]?){5}[0-9A-Fa-f]{2}$/', trim((string) $valor))) {
                $macs[] = self::serial((string) $valor);
            }
            if (preg_match('/(ExternalIPAddress|IPv4Address\.\d+\.IPAddress)$/', $ruta)) {
                $ips[] = (string) $valor;
            }
            if (preg_match('/(WANPPPConnection\.\d+|PPP\.Interface\.\d+)\.Username$/', $ruta)) {
                $usuarios[] = strtolower((string) $valor);
            }
        }

        $cliente = null;
        $ont = null;
        $via = null;

        // Por usuario PPPoE o por IP, el equipo tiene que estar en una red de
        // la empresa. Sin redes conocidas no se atribuye así: en el ACS
        // compartido "cliente1" o 192.168.1.x son de cualquiera. El serial y
        // la MAC no necesitan esto: son únicos.
        $enSuRed = $vinculos['propias'] && self::enAlgunaRed($ips, $vinculos['propias']);

        // Una IP en una red que también usa otra empresa no alcanza sola; con
        // el usuario PPPoE sí (usuario y red juntos).
        $ipsPropias = array_values(array_filter($ips, fn ($ip) => !self::enAlgunaRed([$ip], $vinculos['ajenas'] ?? [])));

        foreach ($enSuRed ? $usuarios : [] as $u) {
            if (isset($vinculos['pppoe'][$u])) {
                [$cliente, $via] = [$vinculos['pppoe'][$u], 'pppoe'];
                break;
            }
        }

        if (!$cliente) {
            foreach ($enSuRed ? $ipsPropias : [] as $ip) {
                if (isset($vinculos['ips'][$ip])) {
                    [$cliente, $via] = [$vinculos['ips'][$ip], 'ip'];
                    break;
                }
            }
        }

        $o = $vinculos['series'][self::serial((string) ($d['_deviceId']['_SerialNumber'] ?? ''))] ?? null;

        foreach ($o ? [] : array_unique($macs) as $mac) {
            if ($o = $vinculos['series'][$mac] ?? null) {
                break;
            }
        }

        if ($o) {
            $ont = ['olt' => $o->olt, 'fsp' => $o->fsp, 'ont_id' => $o->ont_id];
            $cliente ??= $o->cliente;
            $via ??= 'serial';
        }

        if (!$cliente && !$ont) {
            return null;
        }

        $ultimo = $d['_lastInform'] ?? null;

        return [
            'id'             => $d['_id'],
            'fabricante'     => self::fabricante((string) ($d['_deviceId']['_Manufacturer'] ?? '')),
            'modelo'         => $d['_deviceId']['_ProductClass'] ?? null,
            'serial'         => $d['_deviceId']['_SerialNumber'] ?? null,
            'ultimo_reporte' => $ultimo,
            'reportando'     => $ultimo && (time() - strtotime($ultimo)) < self::REPORTA_CADA,
            'ip_wan'         => $ips[0] ?? null,
            'pppoe'          => $usuarios[0] ?? null,
            'cliente'        => $cliente ? [
                'user_id'   => (int) $cliente->user_id,
                'nombre'    => $cliente->nombre,
                'documento' => $cliente->dni,
            ] : null,
            'ont'            => $ont,
            'vinculo'        => $via,
        ];
    }

    // ── Lectura del árbol de parámetros ───────────────────────────────────

    /** @return list<array<string,mixed>> */
    private static function wan(array $d, string $raiz): array
    {
        $conexiones = [];

        if ($raiz === 'InternetGatewayDevice') {
            foreach (self::hijos($d, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice') as $c) {
                foreach (['WANPPPConnection' => 'PPPoE', 'WANIPConnection' => 'IP'] as $tipo => $nombre) {
                    foreach (self::hijos($d, "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$c}.{$tipo}") as $i) {
                        $b = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$c}.{$tipo}.{$i}";
                        $conexiones[] = [
                            'tipo'    => $nombre,
                            'nombre'  => self::v($d, "{$b}.Name"),
                            'estado'  => self::v($d, "{$b}.ConnectionStatus"),
                            'ip'      => self::v($d, "{$b}.ExternalIPAddress") ?: null,
                            'mac'     => self::v($d, "{$b}.MACAddress"),
                            'usuario' => self::v($d, "{$b}.Username"),
                            'modo'    => self::v($d, "{$b}.AddressingType"),
                        ];
                    }
                }
            }
        }

        return array_values(array_filter($conexiones, fn ($c) => $c['ip'] || $c['estado'] || $c['usuario']));
    }

    /** Las redes WiFi de un documento del ACS, con sus rutas (lo usa el aprovisionamiento). @return list<array<string,mixed>> */
    public static function redesWifi(array $d): array
    {
        return self::wifi($d, isset($d['InternetGatewayDevice']) ? 'InternetGatewayDevice' : 'Device');
    }

    /** @return list<array<string,mixed>> */
    private static function wifi(array $d, string $raiz): array
    {
        $redes = [];

        // Huawei guarda la clave WPA en PreSharedKey.1.PreSharedKey (en texto,
        // no en hexadecimal): KeyPassphrase existe pero el equipo no la usa, y
        // escribir ahí respondía bien sin cambiar nada (YONATHAN_GALAO). La rama
        // PreSharedKey muchas veces no está leída, así que se va por la marca.
        $huawei = ($d['_deviceId']['_OUI'] ?? '') === '00259E'
            || str_contains(strtoupper((string) ($d['_deviceId']['_Manufacturer'] ?? '')), 'HUAWEI');

        if ($raiz === 'InternetGatewayDevice') {
            foreach (self::hijos($d, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration') as $i) {
                $b = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}";
                $conPsk = self::existe($d, "{$b}.PreSharedKey.1.KeyPassphrase");
                $estandar = self::v($d, "{$b}.Standard");

                // Dónde va la clave WPA:
                //  - Huawei: PreSharedKey.1.PreSharedKey (texto, no hexadecimal).
                //  - El resto: PreSharedKey.1.KeyPassphrase (TR-098). Si la rama
                //    todavía no está leída (sin BSSID) se va igual ahí.
                //  - Con la rama leída y sin PreSharedKey.1 (FD512XW), todas las
                //    que el equipo tenga: KeyPassphrase y la propia del fabricante
                //    X_CMS_KeyPassphrase. Existen todas, así que no se rechaza.
                $rutasClave = $huawei ? ["{$b}.PreSharedKey.1.PreSharedKey"]
                    : ($conPsk || !self::existe($d, "{$b}.BSSID") ? ["{$b}.PreSharedKey.1.KeyPassphrase"]
                    : array_values(array_filter(["{$b}.KeyPassphrase", "{$b}.X_CMS_KeyPassphrase"], fn ($r) => self::existe($d, $r))));

                // Sin ninguna a la vista (ZTE F680: la rama de la clave sin leer),
                // la estándar; si el equipo no la tiene, el ACS lo dice.
                $rutasClave = $rutasClave ?: ["{$b}.PreSharedKey.1.KeyPassphrase"];

                $redes[] = [
                    'indice'    => (int) $i,
                    'ssid'      => self::v($d, "{$b}.SSID"),
                    'activo'    => self::booleano(self::v($d, "{$b}.Enable")),
                    'clave'     => (self::v($d, "{$b}.KeyPassphrase") ?: self::v($d, "{$b}.PreSharedKey.1.KeyPassphrase")
                        ?: ($huawei ? self::v($d, "{$b}.PreSharedKey.1.PreSharedKey") : null)) ?: null,
                    'banda'     => self::banda(self::v($d, "{$b}.OperatingFrequencyBand"), $estandar, (int) $i),
                    'estandar'  => $estandar,
                    'canal'     => self::v($d, "{$b}.Channel"),
                    'canal_auto' => self::booleano(self::v($d, "{$b}.AutoChannelEnable")),
                    'canales_posibles' => self::canales(self::v($d, "{$b}.PossibleChannels")),
                    'seguridad' => self::v($d, "{$b}.BeaconType"),
                    'conectados' => self::entero(self::v($d, "{$b}.TotalAssociations")),
                    'leido_en'  => self::marca($d, "{$b}.SSID"),
                    'ruta_ssid' => "{$b}.SSID",
                    'ruta_clave'  => $rutasClave[0] ?? null,
                    'rutas_clave' => $rutasClave,
                    'ruta_canal' => self::existe($d, "{$b}.Channel") ? "{$b}.Channel" : null,
                    'ruta_canal_auto' => self::existe($d, "{$b}.AutoChannelEnable") ? "{$b}.AutoChannelEnable" : null,
                    // Red oculta: el equipo deja de anunciar su nombre. Quien
                    // la quiera usar tiene que escribirlo a mano.
                    'oculta'      => self::existe($d, "{$b}.SSIDAdvertisementEnabled")
                        ? self::booleano(self::v($d, "{$b}.SSIDAdvertisementEnabled")) === false
                        : null,
                    'ruta_oculta' => self::existe($d, "{$b}.SSIDAdvertisementEnabled") ? "{$b}.SSIDAdvertisementEnabled" : null,
                ];
            }
        } else {
            foreach (self::hijos($d, 'Device.WiFi.SSID') as $i) {
                $redes[] = [
                    'indice'    => (int) $i,
                    'ssid'      => self::v($d, "Device.WiFi.SSID.{$i}.SSID"),
                    'activo'    => self::booleano(self::v($d, "Device.WiFi.SSID.{$i}.Enable")),
                    'oculta'    => self::existe($d, "Device.WiFi.AccessPoint.{$i}.SSIDAdvertisementEnabled")
                        ? self::booleano(self::v($d, "Device.WiFi.AccessPoint.{$i}.SSIDAdvertisementEnabled")) === false
                        : null,
                    'ruta_oculta' => self::existe($d, "Device.WiFi.AccessPoint.{$i}.SSIDAdvertisementEnabled")
                        ? "Device.WiFi.AccessPoint.{$i}.SSIDAdvertisementEnabled" : null,
                    'clave'     => self::v($d, "Device.WiFi.AccessPoint.{$i}.Security.KeyPassphrase") ?: null,
                    'banda'     => null,
                    'estandar'  => null,
                    'canal'     => null,
                    'seguridad' => self::v($d, "Device.WiFi.AccessPoint.{$i}.Security.ModeEnabled"),
                    'conectados' => self::entero(self::v($d, "Device.WiFi.AccessPoint.{$i}.AssociatedDeviceNumberOfEntries")),
                    'leido_en'  => self::marca($d, "Device.WiFi.SSID.{$i}.SSID"),
                    'ruta_ssid' => "Device.WiFi.SSID.{$i}.SSID",
                    'ruta_clave' => "Device.WiFi.AccessPoint.{$i}.Security.KeyPassphrase",
                    'rutas_clave' => ["Device.WiFi.AccessPoint.{$i}.Security.KeyPassphrase"],
                ];
            }
        }

        return array_values(array_filter($redes, fn ($r) => $r['ssid'] !== null && $r['ssid'] !== ''));
    }

    /** @return list<array<string,mixed>> */
    private static function hosts(array $d, string $raiz): array
    {
        $base = $raiz === 'InternetGatewayDevice' ? 'InternetGatewayDevice.LANDevice.1.Hosts.Host' : 'Device.Hosts.Host';
        $equipos = [];

        foreach (self::hijos($d, $base) as $i) {
            $b = "{$base}.{$i}";
            $mac = self::v($d, "{$b}.MACAddress") ?: self::v($d, "{$b}.PhysAddress");

            if (!$mac) {
                continue;
            }

            $equipos[] = [
                'nombre'   => self::v($d, "{$b}.HostName") ?: null,
                'ip'       => self::v($d, "{$b}.IPAddress") ?: null,
                'mac'      => strtoupper((string) $mac),
                'activo'   => self::booleano(self::v($d, "{$b}.Active")),
                'interfaz' => self::v($d, "{$b}.InterfaceType") ?: self::v($d, "{$b}.Layer2Interface"),
            ];
        }

        usort($equipos, fn ($a, $b) => ($b['activo'] <=> $a['activo']) ?: strcmp((string) $a['nombre'], (string) $b['nombre']));

        return $equipos;
    }

    /** La potencia óptica, si el fabricante la publica (cada uno en su rama). */
    private static function optica(array $d): ?array
    {
        $o = [];

        foreach (self::parametros($d) as $ruta => $valor) {
            if (!is_numeric($valor)) {
                continue;
            }
            if (preg_match('/\.(RXPower|RxPower)$/', $ruta)) {
                $o['rx'] ??= (float) $valor;
            } elseif (preg_match('/\.(TXPower|TxPower)$/', $ruta)) {
                $o['tx'] ??= (float) $valor;
            } elseif (preg_match('/(Pon|Gpon|Epon|Optical).*\.Temperature$/i', $ruta)) {
                $o['temperatura'] ??= (float) $valor;
            }
        }

        return $o ?: null;
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    /** El valor de un parámetro por su ruta con puntos. */
    private static function v(array $d, string $ruta): mixed
    {
        $n = self::nodo($d, $ruta);

        return is_array($n) && array_key_exists('_value', $n) ? $n['_value'] : null;
    }

    private static function existe(array $d, string $ruta): bool
    {
        return is_array(self::nodo($d, $ruta));
    }

    /** Cuándo el ACS leyó ese parámetro por última vez. */
    private static function marca(array $d, string $ruta): ?string
    {
        $n = self::nodo($d, $ruta);

        return is_array($n) ? ($n['_timestamp'] ?? null) : null;
    }

    private static function nodo(array $d, string $ruta): mixed
    {
        foreach (explode('.', $ruta) as $parte) {
            if (!is_array($d) || !array_key_exists($parte, $d)) {
                return null;
            }
            $d = $d[$parte];
        }

        return $d;
    }

    /** Los índices numéricos bajo una rama: "1", "2", "5"… */
    private static function hijos(array $d, string $ruta): array
    {
        $n = self::nodo($d, $ruta);

        if (!is_array($n)) {
            return [];
        }

        $hijos = array_values(array_filter(array_keys($n), fn ($k) => ctype_digit((string) $k)));
        sort($hijos, SORT_NUMERIC);

        return $hijos;
    }

    /** @return iterable<string,mixed>  ruta => valor de todas las hojas */
    private static function parametros(array $d, string $prefijo = ''): iterable
    {
        foreach ($d as $k => $v) {
            if (!is_array($v) || (str_starts_with((string) $k, '_') && $prefijo === '')) {
                continue;
            }

            $ruta = $prefijo === '' ? (string) $k : "{$prefijo}.{$k}";

            if (array_key_exists('_value', $v)) {
                yield $ruta => $v['_value'];
            } elseif (!str_starts_with((string) $k, '_')) {
                yield from self::parametros($v, $ruta);
            }
        }
    }

    /**
     * ¿Alguna de las IP del equipo cae en las redes de la empresa?
     *
     * @param  list<string>  $ips
     * @param  list<string>  $redes  en CIDR
     */
    private static function enAlgunaRed(array $ips, array $redes): bool
    {
        foreach ($ips as $ip) {
            $n = ip2long($ip);

            if ($n === false) {
                continue;
            }

            foreach ($redes as $red) {
                [$base, $bits] = array_pad(explode('/', $red), 2, '32');
                $b = ip2long($base);

                if ($b === false) {
                    continue;
                }

                $mascara = (int) $bits === 0 ? 0 : (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;

                if (($n & $mascara) === ($b & $mascara)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Serial comparable: GPON "HWTC1234ABCD" → "485754431234ABCD", MAC sin separadores. */
    public static function serial(string $s): string
    {
        $k = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $s));

        if (preg_match('/^([A-Z]{4})([0-9A-F]{8})$/', $k, $m)) {
            return strtoupper(bin2hex($m[1])) . $m[2];
        }

        return $k;
    }

    private static function fabricante(string $crudo): string
    {
        $k = strtolower(trim($crudo));

        foreach (self::FABRICANTES as $clave => $nombre) {
            if (str_starts_with($k, $clave)) {
                return $nombre;
            }
        }

        return $crudo;
    }

    private static function banda(mixed $declarada, mixed $estandar, int $indice): string
    {
        if (is_string($declarada) && $declarada !== '') {
            return str_contains($declarada, '5') ? '5 GHz' : '2.4 GHz';
        }

        $e = strtolower((string) $estandar);

        if ($e !== '' && preg_match('/\b(a|ac|ax|a\/n|n\/ac|ac\/ax)\b/', $e) && !preg_match('/[bg]/', $e)) {
            return '5 GHz';
        }

        // Huawei numera las redes de 5 GHz desde la 5.
        return $indice >= 5 ? '5 GHz' : '2.4 GHz';
    }

    private static function booleano(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'up', 'yes'], true);
    }

    private static function entero(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
