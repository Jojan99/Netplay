<?php

namespace App\Services\Red;

use App\Models\Aprovisionamiento;
use App\Models\GestionRemota;
use App\Models\OltAdmin;
use App\Models\UserData;
use App\Services\Acs\EquiposDelAcs;
use App\Services\Red\AsignacionDeIp;
use App\Services\Red\GestionRemotaDeOnt;
use App\Services\Acs\GenieAcs;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Aprovisionamiento automático de una ONT recién autorizada.
 *
 * Al autorizar, si la empresa lo tiene encendido, queda programado con los
 * datos del cliente vinculado: su conexión a internet (IP fija o PPPoE en la
 * VLAN elegida), el WiFi y la cuenta de administración del equipo. Cuando la
 * ONT aparece en el TR-069 —el acceso remoto le da la dirección— la tarea que
 * corre cada minuto se lo aplica y deja anotado cada paso.
 *
 * La conexión a internet por TR-069 está hecha para Huawei (rama X_HW). En las
 * demás marcas se configura el WiFi y la WAN queda indicada para cargarla en
 * el equipo: mandar parámetros que nadie probó puede dejar al cliente sin
 * servicio.
 */
class AprovisionamientoDeOnt
{
    /** Si en este tiempo el equipo no aparece en el TR-069, se deja de esperar. */
    public const ESPERA_MINUTOS = 90;

    /**
     * Con una marca que no está probada en esa OLT (CompatibilidadDeOnt dice
     * "no se sabe"): el acceso remoto tarda uno o dos minutos y el reinicio
     * otros tantos, así que en 15 ya se sabe si va a aparecer.
     */
    public const ESPERA_SIN_CONFIRMAR_MINUTOS = 15;

    /** A los pocos minutos ya se puede mirar si el equipo tiene por dónde salir. */
    public const ESPERA_SIN_CAMINO_MINUTOS = 4;

    /** Si no apareció en este tiempo, se le abre la puerta de servicio solo. */
    public const ESPERA_PARA_ABRIR_CAMINO = 5;

    /** Y si con el camino abierto sigue mudo, se lo reinicia. */
    public const ESPERA_PARA_REINICIAR = 9;

    /** Sin saludar al ACS por más de esto, el equipo se da por mudo. */
    public const MINUTOS_PARA_DARLO_POR_MUDO = 30;

    /**
     * Los que no se pueden configurar solos (estado no_aplica) no muestran
     * espera, pero se siguen mirando estos días: si el técnico le activa el
     * TR-069 en el equipo, el aprovisionamiento sigue solo.
     */
    public const VIGILAR_A_MANO_DIAS = 3;

    private const DNS = '8.8.8.8,8.8.4.4';
    private const WAN = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';
    private const RUTA_POR_DEFECTO = 'InternetGatewayDevice.Layer3Forwarding.DefaultConnectionService';
    private const WLAN = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration';
    private const CUENTAS = 'InternetGatewayDevice.UserInterface.X_HW_WebUserInfo';

    /** Veces que se vuelve a intentar solo un paso que falló porque el equipo tardó. */
    private const REINTENTOS = 6;
    private const SIN_RESPUESTA = 'no respondió al momento';
    private const NO_APARECE = 'el equipo dijo que lo creó pero no aparece';

    public function __construct(private int $companyId) {}

    // ── Ajustes ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function ajustes(): array
    {
        $g = GestionRemota::firstOrCreate(['company_id' => $this->companyId]);

        return [
            'aprovisionar'    => (bool) $g->aprovisionar,
            'wan'             => (bool) $g->aprov_wan,
            'wifi'            => (bool) $g->aprov_wifi,
            'admin'           => (bool) $g->aprov_admin,
            'wifi_prefijo'    => $g->wifi_prefijo,
            'prefijo_sugerido' => $this->prefijoPorDefecto(),
            'admin_usuario'   => $g->onu_admin_usuario,
            // La clave no sale nunca: sólo si hay una guardada.
            'admin_con_clave' => (bool) $g->onu_admin_clave,
            'gestion_activa'  => (bool) $g->activa,
        ];
    }

    /** @param array<string,mixed> $d */
    public function guardarAjustes(array $d): array
    {
        $g = GestionRemota::firstOrCreate(['company_id' => $this->companyId]);

        $cambios = [
            'aprovisionar'      => (bool) ($d['aprovisionar'] ?? false),
            'aprov_wan'         => (bool) ($d['wan'] ?? true),
            'aprov_wifi'        => (bool) ($d['wifi'] ?? true),
            'aprov_admin'       => (bool) ($d['admin'] ?? false),
            'wifi_prefijo'      => mb_substr(self::limpiarSsid((string) ($d['wifi_prefijo'] ?? '')), 0, 20) ?: null,
            'onu_admin_usuario' => trim((string) ($d['admin_usuario'] ?? '')) ?: null,
        ];

        // Sólo se cambia si llega una: la pantalla no la recibe nunca.
        if ((string) ($d['admin_clave'] ?? '') !== '') {
            // Esta clave termina dentro del equipo por TR-069 y, en algunas
            // marcas, por una línea de consola: una eñe o una comilla la parten
            // en el camino y el equipo contesta «Invalid arguments».
            if ($problema = self::problemaDeUnaClaveDeEquipo((string) $d['admin_clave'], 'La clave de administración del equipo')) {
                throw new \InvalidArgumentException($problema);
            }

            $cambios['onu_admin_clave'] = (string) $d['admin_clave'];
        }

        if ($cambios['onu_admin_usuario']
            && $problema = self::problemaDeUnaClaveDeEquipo($cambios['onu_admin_usuario'], 'El usuario de administración del equipo')) {
            throw new \InvalidArgumentException($problema);
        }

        if ($cambios['aprov_admin'] && (!$cambios['onu_admin_usuario'] || !(($cambios['onu_admin_clave'] ?? null) ?: $g->onu_admin_clave))) {
            throw new \InvalidArgumentException('Para cambiar la cuenta de administración de los equipos hacen falta usuario y clave.');
        }

        $g->fill($cambios)->save();

        return $this->ajustes();
    }

    /** Los últimos programados, con sus pasos. @return list<array<string,mixed>> */
    public function ultimos(int $cuantos = 20): array
    {
        return Aprovisionamiento::where('company_id', $this->companyId)
            ->latest('id')->limit($cuantos)->get()
            ->map(fn (Aprovisionamiento $a) => $this->fila($a))->all();
    }

    /**
     * Lo que la ficha del cliente muestra de su conexión: si el aprovisionamiento
     * está encendido, el último que se le aplicó a su ONT y, con IP fija, qué
     * MAC tiene cargada en el ARP del MikroTik.
     *
     * @return array{habilitado:bool, tiene_ont:bool, ultimo:?array, arp:?array}
     */
    public function deCliente(int $userId): array
    {
        $g = GestionRemota::where('company_id', $this->companyId)->first();

        $tieneOnt = DB::table('olt_onts as o')->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
            ->where('a.company_id', $this->companyId)->where('o.user_data_id', $userId)->exists();

        $ultimo = Aprovisionamiento::where('company_id', $this->companyId)->where('user_id', $userId)
            ->where('estado', '<>', 'reemplazado')->latest('id')->first();

        $arp = null;
        $cliente = $this->cliente($userId);

        if ($cliente && $cliente['tipo'] === 'static' && $cliente['ip']) {
            try {
                $api = $this->routerDelCliente($userId);
                $fila = $api ? ($api->query((new \RouterOS\Query('/ip/arp/print'))->where('address', $cliente['ip']))->read()[0] ?? null) : null;
                $mac = strtoupper((string) ($fila['mac-address'] ?? ''));
                $arp = [
                    'ip'       => $cliente['ip'],
                    'mac'      => $mac ?: null,
                    'interfaz' => $fila['interface'] ?? null,
                    // Con el ARP en reply-only, sin MAC real el router no le contesta.
                    'sin_mac'  => !$fila || $mac === '' || $mac === '00:00:00:00:00:00',
                ];
            } catch (\Throwable $e) {
                $arp = ['ip' => $cliente['ip'], 'mac' => null, 'interfaz' => null, 'sin_mac' => null, 'error' => 'No se pudo leer el router'];
            }
        }

        $enCurso = CambioDeConexion::enCurso($this->companyId, $userId);

        return [
            'habilitado' => (bool) ($g?->aprovisionar && $g->aprov_wan),
            'tiene_ont'  => $tieneOnt,
            'ultimo'     => $ultimo ? $this->fila($ultimo) : null,
            'arp'        => $arp,
            // Mientras dura, la ficha sigue con el tipo de antes (es el que
            // anda); esto es lo que se muestra como "Cambiando a …".
            'cambio_en_curso' => $enCurso ? $this->fila($enCurso) : null,
        ];
    }

    /** Uno solo, para seguirlo desde la ventana de tareas. */
    public function uno(int $id): ?array
    {
        $a = Aprovisionamiento::where('company_id', $this->companyId)->find($id);

        return $a ? $this->fila($a) : null;
    }

    /** @return array<string,mixed> */
    private function fila(Aprovisionamiento $a): array
    {
        return [
                'id'         => $a->id,
                'olt_id'     => $a->olt_id,
                'ont'        => "{$a->fsp}:{$a->ont_id}",
                'serial'     => $a->serial,
                'cliente'    => $a->datos['cliente'] ?? null,
                // La clave PPPoE de un cambio en curso viaja cifrada: no sale.
                'wan'        => isset($a->datos['wan']) ? \Illuminate\Support\Arr::except((array) $a->datos['wan'], ['clave_cifrada']) : null,
                'wifi_ssid'  => $a->datos['wifi']['ssid'] ?? null,
                'wifi_clave' => $a->wifi_clave,
                'estado'     => $a->estado,
                'detalle'    => $a->detalle,
                // Por qué no se configura solo y qué hacer (estado no_aplica).
                'puede'      => $a->datos['compatibilidad']['puede'] ?? null,
                'motivo'     => $a->datos['compatibilidad']['motivo'] ?? null,
                'que_hacer'  => $a->datos['compatibilidad']['que_hacer'] ?? null,
                'pasos'      => $a->pasos ?? [],
                'creado'     => $a->created_at,
                'listo_en'   => $a->listo_en,
                // Cambio de conexión desde la ficha: de qué a qué y en qué va.
                'cambio'     => !empty($a->datos['cambio']) ? [
                    'de'   => CambioDeConexion::texto($a->datos['cambio']['de']),
                    'a'    => CambioDeConexion::texto($a->datos['cambio']['a']),
                    'tipo_nuevo' => $a->datos['cambio']['a']['tipo'],
                    'modo' => $a->datos['cambio']['modo'],
                    'fase' => $a->datos['cambio']['fase'],
                    'cancelando' => !empty($a->datos['cambio']['cancelar']),
                ] : null,
        ];
    }

    /**
     * Vuelve a aplicar uno que terminó con fallas. Si el equipo ya estaba en el
     * TR-069 se aplica directo (sin esperar otro reporte); lo toma la tarea de
     * cada minuto.
     */
    public function reintentar(int $id): array
    {
        $a = Aprovisionamiento::where('company_id', $this->companyId)->findOrFail($id);

        if (!in_array($a->estado, ['con_errores', 'error', 'vencido', 'no_aplica'], true)) {
            throw new \InvalidArgumentException('Sólo se reintenta uno que terminó con fallas.');
        }

        // Un cambio de conexión sólo se retoma si ya estaba confirmado y faltó
        // quitar la gestión temporal; si no, se pide de nuevo desde la ficha
        // (reintentarlo a ciegas podría volver a tocar el router).
        if (!empty($a->datos['cambio'])) {
            $c = $a->datos['cambio'];

            if (empty($c['ficha_actualizada']) || $c['modo'] !== 'gestion_temporal') {
                throw new \InvalidArgumentException('Este cambio de conexión no se completó y quedó como estaba: volvé a pedirlo desde la ficha del cliente.');
            }

            $c['fase'] = 'tr069';
            $c['fase_desde'] = now()->toIso8601String();
            $a->datos = array_merge($a->datos, ['cambio' => $c]);
            $a->fill(['estado' => 'aplicando', 'listo_en' => null, 'detalle' => 'Reintentando quitar la gestión temporal…'])->save();

            return $this->ultimos();
        }

        // Pedido a pesar de que la plataforma dijo que no se configura solo:
        // se lo espera el tiempo completo, sin volver a decidir.
        if ($a->estado === 'no_aplica') {
            $a->datos = array_merge($a->datos, ['forzado' => true]);
        }

        if ($a->acs_id) {
            $a->datos = array_merge($a->datos, ['reintentos' => 0]);
            $a->fill(['estado' => 'aplicando', 'intentos' => 0, 'listo_en' => null, 'detalle' => 'Reintentando…',
                'pasos' => [['paso' => 'Reintento pedido desde el panel', 'ok' => true, 'detalle' => $a->acs_id]]]);
        } else {
            $a->fill(['estado' => 'esperando', 'intentos' => 0, 'listo_en' => null, 'pasos' => [],
                'detalle' => 'Esperando que el equipo aparezca en el TR-069…']);
            // La espera y el "reportó después de autorizarse" cuentan desde ahora.
            $a->created_at = now();
        }

        $a->save();

        return $this->ultimos();
    }

    /**
     * Lo cancela el operador (desde la ventana de tareas o la lista): queda
     * "cancelado" y la tarea de cada minuto no le aplica nada más. Lo que ya
     * se le aplicó al equipo queda aplicado.
     */
    public function cancelar(int $id): array
    {
        $a = Aprovisionamiento::where('company_id', $this->companyId)->findOrFail($id);

        if (!in_array($a->estado, ['esperando', 'aplicando', 'no_aplica'], true)) {
            throw new \InvalidArgumentException('Ya había terminado: no hay nada que cancelar.');
        }

        // Un cambio de conexión no se corta en el aire: se deshace (la ONT
        // vuelve a lo de antes y lo nuevo sale del router) en la próxima vuelta.
        if (!empty($a->datos['cambio'])) {
            $fase = $a->datos['cambio']['fase'] ?? '';

            if (!in_array($fase, ['gestion', 'wan', 'confirmar'], true)) {
                throw new \InvalidArgumentException(str_starts_with($fase, 'deshacer')
                    ? 'Ya se está dejando como estaba.'
                    : 'La conexión nueva ya está confirmada y andando: no se puede cancelar.');
            }

            $a->datos = array_merge($a->datos, ['cambio' => array_merge($a->datos['cambio'], ['cancelar' => true])]);
            $a->fill(['detalle' => 'Cancelando: se deja como estaba…'])->save();

            return $this->fila($a);
        }

        $hechos = collect($a->pasos ?? [])->filter(fn ($p) => ($p['ok'] ?? false) && isset($p['clave']))->count();

        $a->fill([
            'estado'   => 'cancelado',
            'detalle'  => 'Cancelado por el usuario a las ' . now()->format('H:i') . '.'
                . ($hechos ? " Lo que ya se le aplicó al equipo ({$hechos} " . ($hechos === 1 ? 'paso' : 'pasos') . ') queda aplicado.' : ' No se le aplicó nada al equipo.'),
            'listo_en' => now(),
        ])->save();

        return $this->fila($a);
    }

    /** Los pasos hechos de uno cancelado, sin pisarle el estado. */
    private static function anotarPasosSinEstado(Aprovisionamiento $a, array $pasos): void
    {
        Aprovisionamiento::whereKey($a->id)->update(['pasos' => json_encode(array_values($pasos), JSON_UNESCAPED_UNICODE)]);
    }

    /** Si el operador lo canceló mientras la tarea de cada minuto lo trabajaba. */
    private static function fueCancelado(Aprovisionamiento $a): bool
    {
        return Aprovisionamiento::whereKey($a->id)->value('estado') === 'cancelado';
    }

    // ── Al autorizar ──────────────────────────────────────────────────────

    /**
     * Deja programado el aprovisionamiento de la ONT recién autorizada.
     *
     * Devuelve el paso para mostrar en el alta, o null si la empresa no lo
     * tiene encendido.
     *
     * @param array<string,mixed> $pedido gateway y máscara de la red elegida, y el WiFi escrito en el alta
     */
    public static function programar(int $oltId, string $fsp, int $ontId, string $serial, ?int $userId, ?int $vlan, array $pedido, bool $forzar = false): ?array
    {
        $companyId = (int) (OltAdmin::find($oltId)?->company_id ?: 0);
        $g = $companyId ? GestionRemota::where('company_id', $companyId)->first() : null;

        // $forzar: un equipo puntual, aunque la empresa lo tenga apagado.
        if (!$g || (!$g->aprovisionar && !$forzar) || trim($serial) === '') {
            return null;
        }

        $yo = new self($companyId);
        $cliente = $userId ? $yo->cliente($userId) : null;
        $datos = ['cliente' => $cliente['nombre'] ?? null];
        $avisos = [];

        if ($g->aprov_wan) {
            [$wan, $aviso] = $yo->wanDelCliente($cliente, $vlan, $pedido);

            if ($wan) {
                $datos['wan'] = $wan;
            }
            if ($aviso) {
                $avisos[] = $aviso;
            }
        }

        $clave = null;

        // El alta puede decir que no se le mande WiFi: la ONT ya está instalada
        // con su red andando y cambiarla deja a la familia sin conexión hasta
        // que alguien reconecte todos los teléfonos. Si el alta no dice nada,
        // manda lo que tenga configurado la empresa.
        $quiereWifi = array_key_exists('wifi', $pedido)
            ? (bool) $pedido['wifi']
            : (bool) $g->aprov_wifi;

        if ($quiereWifi) {
            $ssid = self::limpiarSsid((string) ($pedido['wifi_ssid'] ?? '')) ?: $yo->ssidPorDefecto($g, $cliente, $serial);
            $clave = trim((string) ($pedido['wifi_clave'] ?? '')) ?: self::claveWifi();
            $datos['wifi'] = ['ssid' => $ssid];
        }

        if ($g->aprov_admin && $g->onu_admin_usuario && $g->onu_admin_clave) {
            $datos['admin'] = true;
        }

        if (empty($datos['wan']) && empty($datos['wifi']) && empty($datos['admin'])) {
            return $avisos
                ? ['paso' => 'Aprovisionamiento del equipo', 'ok' => false, 'omitido' => true, 'detalle' => implode(' ', $avisos)]
                : null;
        }

        // Antes de esperar: ¿este equipo puede llegar solo al TR-069? Con el
        // serial / la MAC alcanza y es instantáneo (el alta no espera a la OLT);
        // la tarea de cada minuto lo confirma por SNMP si no se sabe.
        $compatible = self::compatibilidad($g, (int) $oltId, $fsp, $ontId, $serial, false);
        $noAplica = $compatible['puede'] === CompatibilidadDeOnt::NO;

        // Si la misma ONT se había programado (se volvió a autorizar), vale la última.
        // Un cambio de conexión en curso no se pisa: lleva el router y se
        // deshace solo si no se confirma.
        Aprovisionamiento::where('company_id', $companyId)->where('serial', $serial)
            ->whereIn('estado', ['esperando', 'aplicando', 'no_aplica'])
            ->whereRaw("JSON_EXTRACT(datos, '$.cambio') IS NULL")
            ->update(['estado' => 'reemplazado', 'detalle' => 'Se volvió a autorizar la ONT.']);

        // Con "no" no se crea una espera: queda el motivo, como estado final.
        $a = Aprovisionamiento::create([
            'company_id' => $companyId,
            'olt_id'     => $oltId,
            'fsp'        => $fsp,
            'ont_id'     => $ontId,
            'serial'     => $serial,
            'user_id'    => $cliente['id'] ?? null,
            'datos'      => $datos + ['avisos' => $avisos, 'compatibilidad' => $compatible],
            'wifi_clave' => $clave,
            'estado'     => $noAplica ? 'no_aplica' : 'esperando',
            'pasos'      => [],
            'detalle'    => $noAplica ? $compatible['motivo'] : self::textoDeEspera($compatible),
        ]);

        if ($noAplica) {
            return [
                'paso'      => 'Aprovisionamiento del equipo',
                'ok'        => false,
                'omitido'   => true,
                'no_aplica' => true,
                'detalle'   => 'No se puede aprovisionar solo: ' . $compatible['motivo'] . ' ' . $compatible['que_hacer'],
                'aprovisionamiento' => $a->id,
            ];
        }

        $partes = [];
        if (!empty($datos['wan'])) {
            $partes[] = 'internet ' . self::resumenWan($datos['wan']);
        }
        if (!empty($datos['wifi'])) {
            $partes[] = "WiFi «{$datos['wifi']['ssid']}» con clave {$clave}";
        }
        if (!empty($datos['admin'])) {
            $partes[] = 'cuenta de administración';
        }

        return [
            'paso'    => 'Aprovisionamiento del equipo',
            'ok'      => true,
            'detalle' => 'Programado: ' . implode(' · ', $partes) . '. Se aplica solo cuando el equipo aparezca en el TR-069; el resultado queda en Acceso remoto.'
                . ($compatible['puede'] === CompatibilidadDeOnt::NO_SE_SABE ? ' ' . $compatible['motivo'] . ' Se espera ' . self::ESPERA_SIN_CONFIRMAR_MINUTOS . ' minutos.' : '')
                . ($avisos ? ' ' . implode(' ', $avisos) : ''),
            'aprovisionamiento' => $a->id,
        ];
    }

    /**
     * Pasa el TR-069 a la conexión de internet que la ONT ya tiene y quita de la
     * OLT la de gestión, sin tocar los datos de esa conexión (IP, usuario PPPoE):
     * para equipos que ya andan y quedaron con las dos conexiones.
     *
     * @return array{texto:string, id:?int}
     */
    public static function tr069PorInternetDeOnt(int $companyId, int $oltId, string $fsp, int $ontId): array
    {
        $ont = \App\Models\OltOnt::where('olt_id', $oltId)->where('fsp', $fsp)->where('ont_id', $ontId)
            ->whereHas('olt', fn ($q) => $q->where('company_id', $companyId))->first();

        if (!$ont || !$ont->serial) {
            return ['texto' => 'No se encontró la ONT.', 'id' => null];
        }

        $vlanGestion = (int) (GestionRemota::where('company_id', $companyId)->value('vlan') ?: 0);
        $d = null;

        try {
            $acs = GenieAcs::deEmpresa($companyId);
            $crudo = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $ont->serial));
            $id = $acs->dispositivos(
                ['_deviceId._SerialNumber' => ['$in' => EquiposDelAcs::serialesPosibles((string) $ont->serial)]], ['_id']
            )[0]['_id'] ?? null;
            $d = $id ? $acs->dispositivo($id) : null;
        } catch (\Throwable) {
            $id = null;
        }

        if (!$id || !$d) {
            return ['texto' => 'El equipo no está en el servidor TR-069: no se puede pasar el TR-069 a su conexión de internet.', 'id' => null];
        }

        // La conexión de internet que tiene hoy (la VLAN que no es la de gestión).
        $yo = new self($companyId);
        $buscar = fn (array $d) => collect($yo->conexiones($d))
            ->first(fn ($c) => $c['vlan'] && $c['vlan'] !== $vlanGestion && str_contains($c['servicios'], 'INTERNET'));
        $conexion = $buscar($d);

        // El servidor muchas veces no tiene leídas las conexiones: se le piden.
        if (!$conexion) {
            try {
                $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => self::WAN]);
                $d = $acs->dispositivo($id) ?? $d;
                $conexion = $buscar($d);
            } catch (\Throwable) {
            }
        }

        if (!$conexion) {
            return ['texto' => 'El servidor TR-069 no tiene leída la conexión de internet del equipo: pedí leerla y volvé a intentar.', 'id' => null];
        }

        if (CambioDeConexion::enCurso($companyId, (int) $ont->user_data_id)) {
            return ['texto' => 'Hay un cambio de conexión en curso en este equipo: esperá a que termine.', 'id' => null];
        }

        Aprovisionamiento::where('company_id', $companyId)->where('serial', $ont->serial)
            ->whereIn('estado', ['esperando', 'aplicando'])
            ->update(['estado' => 'reemplazado', 'detalle' => 'Se pasó el TR-069 a la conexión de internet.']);

        $dejar = fn (string $clave, string $paso) => ['paso' => $paso, 'ok' => true, 'clave' => $clave, 'detalle' => 'Se dejó como estaba.'];
        $tipo = $conexion['objeto'] === 'WANPPPConnection' ? 'pppoe' : 'static';

        $nuevo = Aprovisionamiento::create([
            'company_id' => $companyId,
            'olt_id'     => $oltId,
            'fsp'        => $fsp,
            'ont_id'     => $ontId,
            'serial'     => $ont->serial,
            'user_id'    => $ont->user_data_id,
            // Los pasos de la conexión se dan por hechos: sólo corre el del TR-069.
            'datos'      => [
                'cliente' => $ont->description, 'avisos' => [], 'origen' => 'tr069_por_internet',
                'wan' => ['tipo' => $tipo, 'vlan' => $conexion['vlan']],
                'resultados' => ['wan' => $dejar('wan', 'Conexión a internet'), 'mac' => $dejar('mac', 'MAC en el MikroTik')],
            ],
            'estado'     => 'aplicando',
            'acs_id'     => $id,
            'pasos'      => [['paso' => 'TR-069 a la conexión de internet', 'ok' => true, 'detalle' => "{$conexion['nombre']} (VLAN {$conexion['vlan']})"]],
            'detalle'    => 'Pasando el TR-069 a la conexión de internet…',
        ]);

        return ['texto' => "Se pasa el TR-069 a {$conexion['nombre']} y se quita la gestión de la OLT.", 'id' => $nuevo->id];
    }

    /**
     * Reaplica la conexión de un cliente que ya tiene ONT, después de cambiarle
     * el tipo (PPPoE ↔ IP fija) o la IP desde su ficha. Sólo la conexión y, con
     * IP fija, la MAC en el MikroTik: su WiFi y su cuenta no se tocan. Si la ONT
     * ya está en el TR-069 se aplica sin esperar otro reporte.
     *
     * @return array{texto:string, id:?int}|null lo que se le dice al operador y el
     *         aprovisionamiento creado, o null si no aplica
     */
    public static function reaplicarConexion(int $companyId, int $userId, ?string $motivo = null): ?array
    {
        $g = GestionRemota::where('company_id', $companyId)->first();

        if (!$g?->aprovisionar || !$g->aprov_wan) {
            return null;
        }

        $ont = DB::table('olt_onts as o')->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
            ->where('a.company_id', $companyId)->where('o.user_data_id', $userId)
            ->orderByDesc('o.updated_at')->first(['o.olt_id', 'o.fsp', 'o.ont_id', 'o.serial', 'o.service_ports']);

        if (!$ont || !$ont->serial) {
            return null;
        }

        // Un cambio de conexión en curso lleva también el router: no se pisa.
        if ($curso = CambioDeConexion::enCurso($companyId, $userId)) {
            return ['texto' => "Hay un cambio de conexión en curso: {$curso->detalle} Esperá a que termine.", 'id' => null];
        }

        $yo = new self($companyId);
        $cliente = $yo->cliente($userId);
        $vlanGestion = (int) ($g->vlan ?: 0);
        $vlan = collect(json_decode((string) $ont->service_ports, true) ?: [])
            ->pluck('vlan')->map(fn ($v) => (int) $v)->first(fn ($v) => $v && $v !== $vlanGestion);

        // La ficha guardada a veces sólo tiene el carril de gestión (DOUGLAS_MENDEZ):
        // la VLAN del cliente se lee de la OLT.
        if (!$vlan) {
            try {
                $vlan = collect(app(\App\Services\OltTelnetDispatcher::class)->dispatch((int) $ont->olt_id, 'getServicePorts', [
                    'fsp' => $ont->fsp, 'ont_id' => (int) $ont->ont_id,
                ]) ?: [])->pluck('vlan')->map(fn ($v) => (int) $v)->first(fn ($v) => $v && $v !== $vlanGestion);
            } catch (\Throwable) {
            }
        }

        $pedido = [];

        if ($cliente && $cliente['tipo'] === 'static' && $cliente['ip']) {
            $api = $yo->routerDelCliente($userId);
            $red = $api ? $yo->redEnElRouter($api, $cliente['ip']) : null;
            $pedido = ['gateway' => $red['gateway'] ?? null, 'mascara' => isset($red['bits']) ? "/{$red['bits']}" : null];
        }

        [$wan, $aviso] = $yo->wanDelCliente($cliente, $vlan ?: null, $pedido);

        if (!$wan) {
            return $aviso ? ['texto' => "La ONT no se reconfiguró sola: {$aviso}", 'id' => null] : null;
        }

        try {
            $crudo = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $ont->serial));
            $ficha = GenieAcs::deEmpresa($companyId)->dispositivos(
                ['_deviceId._SerialNumber' => ['$in' => EquiposDelAcs::serialesPosibles((string) $ont->serial)]],
                ['_id', '_lastInform'],
            )[0] ?? null;
            $enAcs = $ficha['_id'] ?? null;
        } catch (\Throwable) {
            $ficha = null;
            $enAcs = null;
        }

        // Estar en el ACS no es estar vivo: la ficha queda guardada aunque el
        // equipo lleve días mudo. Si no saluda hace rato, las tareas se
        // encolan para nadie —eso fue exactamente lo que pasó con LILIANA_GIL—
        // así que para decidir si hay que destrabar se mira el último saludo.
        $ultimo = isset($ficha['_lastInform']) ? Carbon::parse($ficha['_lastInform']) : null;
        $responde = $ultimo && $ultimo->gt(now()->subMinutes(self::MINUTOS_PARA_DARLO_POR_MUDO));

        // Fuera del TR-069 y sin forma de llegar solo: no se deja esperando.
        $compatible = $enAcs ? null : self::compatibilidad($g, (int) $ont->olt_id, (string) $ont->fsp, (int) $ont->ont_id, (string) $ont->serial, false);

        if (($compatible['puede'] ?? null) === CompatibilidadDeOnt::NO) {
            return ['texto' => 'La ONT no se reconfigura sola: ' . $compatible['motivo'] . ' ' . $compatible['que_hacer'], 'id' => null];
        }

        // Si el equipo no está en el TR-069, antes de encolar tareas que nadie
        // va a recoger se mira si se le puede abrir un camino. Si no hace
        // falta, esto no hace nada.
        $destrabe = $responde ? ['pasos' => [], 'ip_prestada' => null] : self::destrabar($companyId, $ont, $userId);

        Aprovisionamiento::where('company_id', $companyId)->where('serial', $ont->serial)
            ->whereIn('estado', ['esperando', 'aplicando', 'no_aplica'])
            ->update(['estado' => 'reemplazado', 'detalle' => 'Se cambió la conexión del cliente.']);

        // El WiFi del alta que nunca llegó a entrar se lleva de nuevo.
        // Reaplicar rehace la conexión y nada más; si el WiFi había quedado
        // fallado —una clave con eñe, el equipo mudo cuando se intentó— se
        // quedaba esperando para siempre y desde afuera parecía que reaplicar
        // no hacía nada. Lo que ya entró no se vuelve a tocar: cambiarle la
        // red a un cliente que está navegando es peor que no hacer nada.
        $wifi = self::wifiPendiente($companyId, (string) $ont->serial);

        $nuevo = Aprovisionamiento::create([
            'company_id' => $companyId,
            'olt_id'     => $ont->olt_id,
            'fsp'        => $ont->fsp,
            'ont_id'     => $ont->ont_id,
            'serial'     => $ont->serial,
            'user_id'    => $userId,
            'wifi_clave' => $wifi['clave'] ?? null,
            'datos'      => ['cliente' => $cliente['nombre'] ?? null, 'wan' => $wan, 'avisos' => [], 'origen' => $motivo ? 'reinicio' : 'cambio_de_conexion']
                + ($wifi ? ['wifi' => $wifi['datos']] : [])
                + ($compatible ? ['compatibilidad' => $compatible] : [])
                + ($destrabe['ip_prestada'] ?? null ? ['ip_prestada' => $destrabe['ip_prestada']] : []),
            'estado'     => $enAcs ? 'aplicando' : 'esperando',
            'acs_id'     => $enAcs,
            'pasos'      => array_merge(
                $enAcs ? [['paso' => $motivo ?? 'Cambio de conexión desde la ficha del cliente', 'ok' => true, 'detalle' => $enAcs]] : [],
                $destrabe['pasos'] ?? [],
            ),
            'detalle'    => $enAcs ? 'Aplicando la conexión nueva…' : self::textoDeEspera($compatible),
        ]);

        return [
            'texto' => 'La ONT se reconfigura sola en uno o dos minutos: ' . self::resumenWan($wan)
                . ($wifi ? ', y se vuelve a intentar el WiFi «' . $wifi['datos']['ssid'] . '», que había quedado sin aplicar' : '') . '.',
            'id' => $nuevo->id,
        ];
    }

    /**
     * El WiFi que se pidió en el alta y todavía no entró al equipo.
     *
     * @return array{datos: array, clave: string}|null
     */
    private static function wifiPendiente(int $companyId, string $serial): ?array
    {
        $a = Aprovisionamiento::where('company_id', $companyId)->where('serial', $serial)
            ->whereNotNull('wifi_clave')->orderByDesc('id')->first();

        if (!$a || empty($a->datos['wifi']['ssid']) || ($a->datos['resultados']['wifi']['ok'] ?? false)) {
            return null;
        }

        return ['datos' => $a->datos['wifi'], 'clave' => (string) $a->wifi_clave];
    }

    /**
     * Le abre un camino a un equipo que no puede pedir ayuda.
     *
     * La configuración viaja por TR-069, el TR-069 viaja por la conexión del
     * cliente: si esa conexión quedó mal, el equipo no puede reportarse y no
     * hay forma de arreglarlo. Reaplicar, solo, encola tareas que nadie va a
     * recoger. Acá se mira si falta algo y se abre lo que haga falta; si el
     * equipo ya tiene por dónde salir, esto no toca nada.
     *
     * @return array{pasos: list<array{paso:string, ok:bool, detalle:string}>, ip_prestada: ?string}
     */
    private static function destrabar(int $companyId, object $ont, int $userId): array
    {
        $pasos = [];
        $ipPrestada = null;

        $g = GestionRemota::where('company_id', $companyId)->first();
        $vlanGestion = (int) ($g?->vlan ?: 0);

        // ── 1. El camino de gestión por la OLT ─────────────────────────────
        $tieneGestion = collect(json_decode((string) ($ont->service_ports ?? '[]'), true) ?: [])
            ->contains(fn ($sp) => (int) ($sp['vlan'] ?? 0) === $vlanGestion);

        if ($vlanGestion && !$tieneGestion && $g?->activa) {
            try {
                // darAcceso hace la cadena entera: conexión de gestión, perfil
                // de línea si le falta el carril, y servidor TR-069.
                $r = app(GestionRemotaDeOnt::class, ['companyId' => $companyId])
                    ->darAcceso((int) $ont->olt_id, (string) $ont->fsp, (int) $ont->ont_id, true);

                $pasos[] = [
                    'paso'    => 'Abrir camino de gestión',
                    'ok'      => (bool) ($r['ok'] ?? false),
                    'detalle' => ($r['ok'] ?? false)
                        ? 'El equipo no se reportaba: se le dio la conexión de gestión por la OLT para poder entrar. Se le quita sola cuando quede andando.'
                        : ($r['detalle'] ?? 'La OLT no aceptó la conexión de gestión.'),
                ];
            } catch (\Throwable $e) {
                $pasos[] = ['paso' => 'Abrir camino de gestión', 'ok' => false, 'detalle' => mb_substr($e->getMessage(), 0, 140)];
            }
        }

        // ── 2. La IP que el equipo sigue pidiendo ──────────────────────────
        //
        // Un equipo al que le cambiaron la conexión conserva la anterior hasta
        // que alguien se la cambie por TR-069. Si sigue pidiendo una IP fija
        // que quedó libre, prestársela es la forma de entrar sin tocar nada
        // suyo: se le devuelve, se lo configura, y se suelta.
        $suya = self::wanQuePideLaOnt((int) $ont->olt_id, (string) $ont->fsp, (int) $ont->ont_id);

        $yaTiene = DB::table('user_data')->where('user_id', $userId)->value('ip_assignment_id');

        if ($suya && !$yaTiene) {
            $libre = DB::table('tabla_ips as t')
                ->leftJoin('user_data as ud', 'ud.ip_assignment_id', '=', 't.id')
                ->where('t.company_id', $companyId)->where('t.ip', $suya['ip'])
                ->whereNull('ud.user_id')
                ->value('t.id');

            if ($libre) {
                try {
                    AsignacionDeIp::asignar($userId, $suya['ip'], $companyId);

                    $api = (new self($companyId))->routerDelCliente($userId);

                    if (!$api) {
                        throw new \RuntimeException('No se pudo entrar al router del cliente.');
                    }

                    $ipFija = new \App\Services\Red\IpFijaEnElRouter($api, $companyId);
                    $revision = $ipFija->revisar($suya['ip'], (string) ($suya['vlan'] ?: ''), $userId);

                    if (!($revision['ok'] ?? false)) {
                        throw new \RuntimeException((string) ($revision['mensaje'] ?? 'El router no aceptó la IP.'));
                    }

                    $ipFija->aplicar(
                        $revision,
                        $suya['ip'],
                        (string) ($suya['vlan'] ?: ''),
                        (string) (DB::table('user_data')->where('user_id', $userId)->value('dni') ?: ''),
                        $suya['mac'] ?? '',
                    );

                    $ipPrestada = $suya['ip'];
                    $pasos[] = [
                        'paso'    => 'Devolverle la IP que sigue pidiendo',
                        'ok'      => true,
                        'detalle' => "El equipo sigue configurado con la IP fija {$suya['ip']}, que estaba libre: se le devolvió para poder entrar por TR-069. "
                            . 'Se suelta sola cuando tome su conexión nueva.',
                    ];
                } catch (\Throwable $e) {
                    $pasos[] = ['paso' => 'Devolverle la IP que sigue pidiendo', 'ok' => false, 'detalle' => mb_substr($e->getMessage(), 0, 140)];
                }
            }
        }

        return ['pasos' => $pasos, 'ip_prestada' => $ipPrestada];
    }

    /**
     * Qué conexión tiene puesta la ONT hoy, leída de la OLT.
     *
     * @return array{ip:string, vlan:int, mac:string}|null
     */
    private static function wanQuePideLaOnt(int $oltId, string $fsp, int $ontId): ?array
    {
        [$f, $s, $p] = array_pad(explode('/', $fsp), 3, '0');

        try {
            $salida = (string) app(\App\Services\OltTelnetDispatcher::class)
                ->dispatch($oltId, 'runCommand', ['command' => "display ont wan-info {$f}/{$s} {$p} {$ontId}"]);
        } catch (\Throwable $e) {
            return null;
        }

        if (!preg_match('/IPv4 access type\s*:\s*Static/i', $salida)
            || !preg_match('/IPv4 address\s*:\s*(\d+\.\d+\.\d+\.\d+)/i', $salida, $mi)) {
            return null;
        }

        preg_match('/Manage VLAN\s*:\s*(\d+)/i', $salida, $mv);
        preg_match('/MAC address\s*:\s*([0-9A-Fa-f-]{14})/', $salida, $mm);

        return [
            'ip'   => $mi[1],
            'vlan' => (int) ($mv[1] ?? 0),
            'mac'  => isset($mm[1])
                ? implode(':', str_split(strtoupper(str_replace('-', '', $mm[1])), 2))
                : '',
        ];
    }

    /** @return array{id:int, nombre:string, nombres:string, apellidos:string, tipo:string, pppoe_usuario:?string, ip:?string}|null */
    public function cliente(int $userId): ?array
    {
        $c = DB::table('users as u')
            ->join('user_data as ud', 'ud.user_id', '=', 'u.id')
            ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('u.id', $userId)
            ->where('u.company_id', $this->companyId)
            ->first(['u.id', 'ud.names', 'ud.lastname', 'ud.connection_type', 'ud.pppoe_user', 't.ip']);

        return $c ? [
            'id'            => (int) $c->id,
            'nombre'        => trim("{$c->names} {$c->lastname}"),
            'nombres'       => (string) $c->names,
            'apellidos'     => (string) $c->lastname,
            'tipo'          => $c->connection_type === 'pppoe' ? 'pppoe' : 'static',
            'pppoe_usuario' => $c->pppoe_user ?: null,
            'ip'            => $c->ip ?: null,
        ] : null;
    }

    /** @return array{0:?array, 1:?string} la WAN a configurar, o por qué no */
    public function wanDelCliente(?array $c, ?int $vlan, array $pedido): array
    {
        if (!$c) {
            return [null, 'Sin cliente vinculado no se configura la conexión a internet.'];
        }

        if (!$vlan) {
            return [null, 'Sin VLAN elegida no se configura la conexión a internet.'];
        }

        if ($c['tipo'] === 'pppoe') {
            return $c['pppoe_usuario']
                ? [['tipo' => 'pppoe', 'usuario' => $c['pppoe_usuario'], 'vlan' => $vlan], null]
                : [null, 'El cliente es PPPoE pero no tiene usuario cargado: la conexión a internet no se configura.'];
        }

        if (!$c['ip']) {
            return [null, 'El cliente no tiene IP asignada: la conexión a internet no se configura.'];
        }

        $gateway = trim((string) ($pedido['gateway'] ?? ''));
        $bits = (int) ltrim((string) ($pedido['mascara'] ?? '24'), '/');
        $bits = $bits >= 8 && $bits <= 30 ? $bits : 24;

        if (!filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [null, 'No llegó el gateway de la red elegida: la conexión a internet no se configura.'];
        }

        $mascara = (-1 << (32 - $bits)) & 0xFFFFFFFF;

        // Una IP de otra red dejaría al cliente sin internet: mejor no tocar.
        if ((ip2long($c['ip']) & $mascara) !== (ip2long($gateway) & $mascara)) {
            return [null, "La IP del cliente ({$c['ip']}) no es de la red elegida ({$gateway}/{$bits}): la conexión a internet no se configura."];
        }

        return [[
            'tipo'    => 'static',
            'ip'      => $c['ip'],
            'mascara' => long2ip($mascara),
            'gateway' => $gateway,
            'vlan'    => $vlan,
            'dns'     => self::DNS,
        ], null];
    }

    private function ssidPorDefecto(GestionRemota $g, ?array $c, string $serial): string
    {
        $prefijo = $g->wifi_prefijo ?: $this->prefijoPorDefecto();
        $quien = $c
            ? (strtok(trim($c['apellidos']), ' ') ?: strtok(trim($c['nombres']), ' '))
            : substr(preg_replace('/[^0-9A-Za-z]/', '', $serial), -4);

        return mb_substr(self::limpiarSsid($prefijo . '_' . mb_strtoupper((string) $quien)), 0, 32);
    }

    private function prefijoPorDefecto(): string
    {
        $nombre = (string) DB::table('companies')->where('id', $this->companyId)->value('name');
        $primera = strtok(trim($nombre), ' ') ?: 'WIFI';

        return mb_substr(self::limpiarSsid(mb_strtoupper($primera)), 0, 12) ?: 'WIFI';
    }

    /**
     * El nombre de la red tal como lo escribieron, sin acentos ni caracteres
     * que los equipos manejan mal. Lo que no entra se cae en silencio: acá no
     * se rechaza nada, porque este nombre lo arma el sistema.
     */
    private static function limpiarSsid(string $s): string
    {
        $signos = preg_quote(self::SIGNOS_WIFI, '/');

        return mb_substr(trim((string) preg_replace("/[^A-Za-z0-9 {$signos}]/", '', Str::ascii(trim($s)))), 0, 32);
    }

    /** Diez letras y números sin los que se confunden (l, 1, o, 0). */
    private static function claveWifi(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyz23456789';
        $clave = '';

        for ($i = 0; $i < 10; $i++) {
            $clave .= $letras[random_int(0, strlen($letras) - 1)];
        }

        return $clave;
    }

    public static function resumenWan(array $wan): string
    {
        return $wan['tipo'] === 'pppoe'
            ? "PPPoE ({$wan['usuario']}) en la VLAN {$wan['vlan']}"
            : "IP {$wan['ip']} en la VLAN {$wan['vlan']}";
    }

    // ── Cuando el equipo aparece ──────────────────────────────────────────

    /** Lo llama la tarea de cada minuto. Devuelve cuántos revisó. */
    public static function trabajarPendientes(): int
    {
        $revisados = 0;

        // Cada uno con su propio candado: antes la pasada entera tenía uno solo
        // (withoutOverlapping) y un equipo lento —esperando a la OLT o al ACS—
        // frenaba a todos los demás. El 18-09 un cambio de conexión quedó 11
        // minutos sin moverse detrás de otro. Ahora las pasadas pueden correr a
        // la vez y cada registro lo trabaja una sola a la vez.
        Aprovisionamiento::whereIn('estado', ['esperando', 'aplicando'])->orderBy('id')->limit(20)->get()
            ->each(function (Aprovisionamiento $a) use (&$revisados) {
                $candado = \Illuminate\Support\Facades\Cache::lock("aprovisionamiento:{$a->id}", 900);

                if (!$candado->get()) {
                    return; // lo está trabajando otra pasada
                }

                $revisados++;

                try {
                    // Pudo cambiar mientras esperaba el candado (lo canceló el operador).
                    $a->refresh();

                    if (!in_array($a->estado, ['esperando', 'aplicando'], true)) {
                        return;
                    }

                    (new self((int) $a->company_id))->trabajar($a);
                } catch (\Throwable $e) {
                    Log::warning('[Aprovisionamiento] No se pudo avanzar', ['id' => $a->id, 'error' => $e->getMessage()]);
                    $a->intentos++;
                    $a->detalle = mb_substr('Reintentando: ' . $e->getMessage(), 0, 250);

                    if ($a->intentos >= 10) {
                        $a->estado = 'error';
                    }

                    $a->save();
                } finally {
                    $candado->release();
                }
            });

        // Los que no se configuran solos: si el técnico le activó el TR-069 en
        // el equipo y ya se reportó, se sigue como con cualquier otro.
        Aprovisionamiento::where('estado', 'no_aplica')
            ->where('created_at', '>=', now()->subDays(self::VIGILAR_A_MANO_DIAS))
            ->orderBy('id')->limit(20)->get()
            ->each(function (Aprovisionamiento $a) use (&$revisados) {
                $revisados++;

                try {
                    (new self((int) $a->company_id))->retomarSiAparecio($a);
                } catch (\Throwable $e) {
                    Log::info('[Aprovisionamiento] No se pudo mirar si apareció', ['id' => $a->id, 'error' => $e->getMessage()]);
                }
            });

        try {
            $revisados += self::repararTrasReinicio();
        } catch (\Throwable $e) {
            Log::warning('[Aprovisionamiento] No se pudieron revisar los reinicios', ['error' => $e->getMessage()]);
        }

        try {
            $revisados += GestionPorInternet::trabajar();
        } catch (\Throwable $e) {
            Log::warning('[Aprovisionamiento] Gestión por internet', ['error' => $e->getMessage()]);
        }

        return $revisados;
    }

    /**
     * Una ONT Huawei con la gestión de la OLT que se reinicia puede volver sin
     * la conexión de internet que se le creó por TR-069: la OLT le vuelve a
     * cargar su configuración y la borra (19-09: LILIANA_JIMENEZ a las 9:08 y
     * ELVIRA_JIMENEZ a las 9:40 quedaron sólo con la de gestión). Cada equipo
     * aprovisionado que arrancó después de su aprovisionamiento se mira una vez
     * por arranque; si no tiene conexión de internet se le vuelve a poner.
     */
    public static function repararTrasReinicio(): int
    {
        if (!\Illuminate\Support\Facades\Cache::add('aprovisionamiento:reinicios', 1, 110)) {
            return 0;
        }

        $reparados = 0;

        foreach (GestionRemota::where('aprovisionar', true)->where('aprov_wan', true)->pluck('company_id')->unique() as $companyId) {
            $companyId = (int) $companyId;
            $acs = GenieAcs::deEmpresa($companyId);

            // Arrancaron en las últimas horas y ya tuvieron unos minutos para acomodarse.
            $arranques = collect($acs->dispositivos(['_lastBoot' => [
                '$gt' => now()->subHours(12)->toIso8601String(),
                '$lt' => now()->subMinutes(3)->toIso8601String(),
            ]], ['_id', '_lastBoot']))->pluck('_lastBoot', '_id');

            if ($arranques->isEmpty()) {
                continue;
            }

            $ultimos = Aprovisionamiento::where('company_id', $companyId)->whereIn('acs_id', $arranques->keys())
                ->orderByDesc('id')->get()->unique('acs_id');

            foreach ($ultimos as $a) {
                $arranque = \Carbon\Carbon::parse($arranques[$a->acs_id]);

                if ($a->estado !== 'listo' || empty($a->datos['wan']) || !$a->user_id || $arranque->lte($a->updated_at)) {
                    continue;
                }

                $visto = 'aprovisionamiento:arranque:' . md5($a->acs_id . $arranque->toIso8601String());

                if (\Illuminate\Support\Facades\Cache::has($visto)) {
                    continue;
                }

                // Lo guardado puede ser de antes del arranque: se lee de nuevo.
                // Si no contesta, se vuelve a probar en la próxima pasada.
                if (!($acs->tarea((string) $a->acs_id, ['name' => 'refreshObject', 'objectName' => self::WAN], 60)['hecha'] ?? false)) {
                    continue;
                }

                \Illuminate\Support\Facades\Cache::put($visto, 1, now()->addDays(2));
                $d = $acs->dispositivo((string) $a->acs_id) ?? [];

                if (self::gruposSinLeer($d) || collect((new self($companyId))->conexiones($d))->contains(fn ($c) => str_contains($c['servicios'], 'INTERNET'))) {
                    continue;
                }

                $r = self::reaplicarConexion($companyId, (int) $a->user_id,
                    'El equipo se reinició (' . $arranque->timezone('America/Bogota')->format('d/m H:i') . ') y volvió sin su conexión a internet: se le vuelve a poner');
                Log::info('[Aprovisionamiento] Conexión perdida al reiniciar', ['equipo' => $a->acs_id, 'cliente' => $a->user_id, 'resultado' => $r['texto'] ?? null]);
                $reparados++;
            }
        }

        return $reparados;
    }

    /** Un no_aplica que igual apareció en el TR-069 (se lo configuraron a mano): se aplica. */
    public function retomarSiAparecio(Aprovisionamiento $a): bool
    {
        $acs = GenieAcs::deEmpresa($this->companyId);
        $id = $this->buscarEnElAcs($acs, $a);

        if (!$id) {
            return false;
        }

        $this->empezarAAplicar($acs, $a, $id);
        $this->anotarTareas($a, $acs);

        return true;
    }

    private function empezarAAplicar(GenieAcs $acs, Aprovisionamiento $a, string $id): void
    {
        $a->fill([
            'acs_id'  => $id,
            'estado'  => 'aplicando',
            'pasos'   => [['paso' => 'El equipo apareció en el TR-069', 'ok' => true, 'detalle' => $id]],
            'detalle' => 'Leyendo la configuración del equipo…',
        ])->save();

        $this->refrescar($acs, $a, $id, true);
    }

    // ── Si el equipo puede llegar solo al TR-069 ──────────────────────────

    /**
     * CompatibilidadDeOnt más lo que depende de la empresa: sin el acceso
     * remoto encendido nadie le da al equipo la dirección del TR-069.
     *
     * @return array<string,mixed>
     */
    private static function compatibilidad(?GestionRemota $g, int $oltId, string $fsp, int $ontId, string $serial, bool $leerOlt): array
    {
        $olt = OltAdmin::find($oltId);

        if (!$olt) {
            return ['puede' => CompatibilidadDeOnt::NO_SE_SABE, 'motivo' => 'No se encontró la OLT.', 'que_hacer' => null, 'con_olt' => false];
        }

        $c = CompatibilidadDeOnt::evaluar($olt, $fsp, $ontId, $serial, $leerOlt);

        if ($c['puede'] !== CompatibilidadDeOnt::NO && (!$g?->activa || !$g->vlan)) {
            $c = array_merge($c, [
                'puede'     => CompatibilidadDeOnt::NO,
                'motivo'    => 'El acceso remoto de la empresa está apagado: nadie le da al equipo la dirección del TR-069.',
                'que_hacer' => 'Encendelo en Acceso remoto → Configuración y volvé a autorizar el equipo, o ' . lcfirst(CompatibilidadDeOnt::queHacerAMano()),
            ]);
        }

        return $c + ['evaluado_en' => now()->toDateTimeString()];
    }

    /** Lo que se ve mientras se espera al equipo. */
    private static function textoDeEspera(?array $compatible): string
    {
        return ($compatible['puede'] ?? null) === CompatibilidadDeOnt::NO_SE_SABE
            ? 'Esperando que el equipo aparezca en el TR-069 (marca sin probar: hasta ' . self::ESPERA_SIN_CONFIRMAR_MINUTOS . ' min)…'
            : 'Esperando que el equipo aparezca en el TR-069…';
    }

    public function trabajar(Aprovisionamiento $a): void
    {
        // Cancelado entre que se leyó la lista y ahora: no se toca.
        if (self::fueCancelado($a)) {
            return;
        }

        // Un cambio de conexión desde la ficha del cliente (PPPoE ↔ IP fija,
        // IP nueva): lleva también el router y se deshace si no se confirma.
        if (!empty($a->datos['cambio'])) {
            (new CambioDeConexion($this->companyId))->avanzar($a);

            return;
        }

        $acs = GenieAcs::deEmpresa($this->companyId);

        try {
            $this->unaVuelta($a, $acs);
        } finally {
            $this->anotarTareas($a, $acs);
        }
    }

    /**
     * ¿La ONT quedó sin service-port? Devuelve dónde está, o null si está bien.
     *
     * La ausencia nunca es prueba por sí sola: la OLT pagina sus respuestas y
     * una lectura cortada hace parecer que faltan service-ports que sí están.
     * Por eso se pregunta por esa ONT puntual, se exige que la respuesta traiga
     * datos del puerto —si no, la lectura no sirve— y recién ahí se concluye.
     */
    /**
     * El destrabe completo, desde el trabajo de fondo.
     *
     * Es el mismo que hace Reaplicar: abrir la gestión por la OLT —preparando
     * el perfil de línea si le falta el carril—, asignar el servidor TR-069 y,
     * si el equipo sigue pidiendo una IP fija que está libre, prestársela.
     * Corre una sola vez por aprovisionamiento.
     */
    private function destrabarloSolo(Aprovisionamiento $a): void
    {
        $datos = $a->datos ?? [];
        $datos['camino_abierto'] = now()->toIso8601String();
        $a->datos = $datos;
        $a->save();

        $ont = DB::table('olt_onts')->where('olt_id', $a->olt_id)
            ->whereRaw('UPPER(serial) = ?', [strtoupper((string) $a->serial)])
            ->first(['olt_id', 'fsp', 'ont_id', 'serial', 'service_ports']);

        if (!$ont) {
            return;
        }

        $r = self::destrabar((int) $a->company_id, $ont, (int) $a->user_id);

        foreach ($r['pasos'] as $paso) {
            $this->anotarPaso($a, $paso['paso'], $paso['ok'], $paso['detalle']);
        }

        if ($r['ip_prestada'] ?? null) {
            $datos = $a->datos;
            $datos['ip_prestada'] = $r['ip_prestada'];
            $a->timestamps = false;
            $a->datos = $datos;
            $a->save();
            $a->timestamps = true;
        }
    }

    /** Un reinicio, una sola vez: el equipo toma el servidor TR-069 al arrancar. */
    private function reiniciarloSolo(Aprovisionamiento $a): void
    {
        $datos = $a->datos ?? [];
        $datos['reinicio_pedido'] = now()->toIso8601String();
        $a->datos = $datos;
        $a->save();

        $ont = DB::table('olt_onts')->where('olt_id', $a->olt_id)
            ->whereRaw('UPPER(serial) = ?', [strtoupper((string) $a->serial)])
            ->first(['olt_id', 'fsp', 'ont_id']);

        if (!$ont) {
            return;
        }

        try {
            $r = app(GestionRemotaDeOnt::class, ['companyId' => (int) $a->company_id])
                ->reiniciarEquipo((int) $ont->olt_id, (string) $ont->fsp, (int) $ont->ont_id);
        } catch (\Throwable $e) {
            $this->anotarPaso($a, 'Reiniciar el equipo', false, mb_substr($e->getMessage(), 0, 130));

            return;
        }

        $this->anotarPaso(
            $a,
            'Reiniciar el equipo',
            (bool) ($r['ok'] ?? false),
            ($r['ok'] ?? false)
                ? 'Seguía sin reportarse con el camino abierto: se lo reinició para que tome el servidor TR-069 al arrancar.'
                : ($r['detalle'] ?? 'La OLT no pudo reiniciarlo.'),
        );
    }

    /** Deja un paso anotado sin moverle la fecha al aprovisionamiento. */
    private function anotarPaso(Aprovisionamiento $a, string $paso, bool $ok, string $detalle): void
    {
        $a->timestamps = false;
        $a->pasos = array_merge($a->pasos ?? [], [['paso' => $paso, 'ok' => $ok, 'detalle' => $detalle]]);
        $a->save();
        $a->timestamps = true;
    }

    private function sinCaminoDeDatos(Aprovisionamiento $a): ?string
    {
        $fsp   = $a->fsp;
        $ontId = $a->ont_id === null ? null : (int) $a->ont_id;

        // El alta no siempre conoce el número de ONT: cuando falta, se busca
        // por serial, que es lo único que no cambia. Ojo: en Huawei la primera
        // ONT de un puerto es la 0, así que un cero es un número válido.
        if ($ontId === null && $a->serial) {
            $fila = \App\Models\OltOnt::where('olt_id', $a->olt_id)
                ->whereRaw('UPPER(serial) = ?', [strtoupper((string) $a->serial)])
                ->first(['fsp', 'ont_id']);

            $fsp   = $fila?->fsp ?: $fsp;
            $ontId = $fila?->ont_id === null ? null : (int) $fila->ont_id;
        }

        if (!$a->olt_id || !$fsp || $ontId === null) {
            return null;
        }

        try {
            $respuesta = app(\App\Services\OltTelnetDispatcher::class)
                ->dispatch((int) $a->olt_id, 'getServicePorts', ['fsp' => $fsp, 'ont_id' => $ontId]) ?: [];
        } catch (\Throwable $e) {
            return null;
        }

        // Sin datos del puerto no se opina: puede ser la sesión, no el cliente.
        $delPuerto = collect($respuesta)->filter(
            fn ($sp) => str_contains(str_replace(['gpon', 'epon'], '', (string) ($sp['port'] ?? '')), $fsp),
        );

        if ($delPuerto->isEmpty()) {
            return null;
        }

        $suyo = $delPuerto->contains(fn ($sp) => (int) ($sp['ont_id'] ?? -1) === $ontId);

        return $suyo ? null : "{$fsp}:{$ontId}";
    }

    private function unaVuelta(Aprovisionamiento $a, GenieAcs $acs): void
    {
        if ($a->estado === 'esperando') {
            $forzado = !empty($a->datos['forzado']);
            $compatible = $forzado ? null : $this->compatibilidadDe($a);

            // No puede llegar solo: no se espera. Una sola mirada al ACS por si
            // ya se reporta por su cuenta (se lo configuraron a mano).
            if (($compatible['puede'] ?? null) === CompatibilidadDeOnt::NO) {
                if ($id = $this->buscarEnElAcs($acs, $a)) {
                    $this->empezarAAplicar($acs, $a, $id);
                } else {
                    $a->fill(['estado' => 'no_aplica', 'detalle' => $compatible['motivo']])->save();

                    return;
                }
            } else {
                $sinConfirmar = ($compatible['puede'] ?? null) === CompatibilidadDeOnt::NO_SE_SABE;
                $espera = $sinConfirmar ? self::ESPERA_SIN_CONFIRMAR_MINUTOS : self::ESPERA_MINUTOS;

                // Antes de seguir esperando: si la ONT no tiene service-port,
                // no hay camino de datos y no va a aparecer nunca. Esperar 90
                // minutos por algo que no puede pasar deja al cliente sin
                // internet y al técnico mirando un reloj.
                // Si no aparece por su cuenta, se le abre la puerta de servicio:
                // la gestión por la OLT. Un equipo con la WAN rota no puede
                // llamar al TR-069, y sin TR-069 no se le puede arreglar la
                // WAN. Alguien tiene que abrir ese círculo, y no puede ser
                // siempre una persona mirando la pantalla.
                if ($a->created_at->lt(now()->subMinutes(self::ESPERA_PARA_ABRIR_CAMINO))
                    && empty($a->datos['camino_abierto'])) {
                    $this->destrabarloSolo($a);
                }

                // Y si con el camino abierto sigue sin saludar, un reinicio:
                // el equipo toma el servidor TR-069 al arrancar. Una vez.
                if ($a->created_at->lt(now()->subMinutes(self::ESPERA_PARA_REINICIAR))
                    && !empty($a->datos['camino_abierto'])
                    && empty($a->datos['reinicio_pedido'])) {
                    $this->reiniciarloSolo($a);
                }

                if ($a->created_at->lt(now()->subMinutes(self::ESPERA_SIN_CAMINO_MINUTOS))
                    && ($donde = $this->sinCaminoDeDatos($a))) {
                    $a->fill([
                        'estado'  => 'error',
                        'detalle' => "La ONT {$donde} está registrada en la OLT pero sin service-port: "
                            . 'no tiene camino de datos, por eso no navega ni aparece en el TR-069. '
                            . 'Completale el service-port con la VLAN del cliente desde Admin OLT y reintentá.',
                    ])->save();

                    return;
                }

                if ($a->created_at->lt(now()->subMinutes($espera))) {
                    $a->fill([
                        'estado'  => 'vencido',
                        'detalle' => $sinConfirmar
                            ? "El equipo no apareció en el TR-069 en {$espera} minutos: {$compatible['equipo']} no toma la configuración que le manda la OLT {$compatible['olt']}. "
                                . CompatibilidadDeOnt::queHacerAMano() . ' Después tocá «Reintentar».'
                            : 'El equipo no apareció en el TR-069 en ' . self::ESPERA_MINUTOS . ' minutos. Revisá su acceso remoto y volvé a autorizarlo, o configuralo a mano.',
                    ])->save();

                    return;
                }

                $id = $this->buscarEnElAcs($acs, $a);

                if (!$id) {
                    return;
                }

                $this->empezarAAplicar($acs, $a, $id);
            }
        }

        $d = $acs->dispositivo((string) $a->acs_id);

        if (!$d) {
            throw new \RuntimeException('El equipo ya no está en el TR-069.');
        }

        $igd = isset($d['InternetGatewayDevice']);
        $leido = $igd
            ? (self::hijos($d, self::WAN) || self::hijos($d, self::WLAN))
            : isset($d['Device']['WiFi']);

        // Hasta que el equipo no manda su configuración no se puede decidir qué
        // conexión ajustar ni qué redes WiFi tiene.
        if (!$leido) {
            $a->intentos++;

            // Rotando: primero las conexiones, después el WiFi y las cuentas.
            if ($a->intentos % 3 === 0) {
                $ramas = $igd ? [self::WAN, self::WLAN, 'InternetGatewayDevice.UserInterface'] : ['Device.WiFi'];
                $this->refrescar($acs, $a, (string) $a->acs_id, $igd, $ramas[intdiv($a->intentos, 3) % count($ramas)]);
            }

            if ($a->intentos >= 15) {
                $a->estado = 'error';
                $a->detalle = 'El equipo está en el TR-069 pero no manda su configuración: no se aplicó nada.';
            } else {
                $a->detalle = 'Esperando que el equipo mande su configuración…';
            }

            $a->save();

            return;
        }

        $huawei = ($d['_deviceId']['_OUI'] ?? '') === '00259E'
            || str_contains(strtoupper((string) ($d['_deviceId']['_Manufacturer'] ?? '')), 'HUAWEI');

        // Si el equipo publica dónde va la VLAN de cada conexión, se le puede
        // cargar la conexión sea de la marca que sea. Huawei lo llama
        // X_HW_VLAN y C-Data X_CT-COM_VLANIDMark, pero el modelo es el mismo.
        // Antes se exigía Huawei y a un C-Data se le decía «en esta marca
        // todavía no se configura», y el cliente quedaba sin navegar con el
        // aprovisionamiento en verde.
        $sabeDeWan = ($huawei && $igd) || self::dialectoWan($d) !== null;

        // Lo que ya quedó hecho en una vuelta anterior no se repite; lo que
        // falló porque el equipo tardó (no mandó su WiFi o sus cuentas, no
        // respondió a tiempo) se pide y se vuelve a intentar solo al minuto
        // siguiente. Antes quedaba "Con fallas" y había que tocar Reintentar.
        $datos = $a->datos;
        $resultados = $datos['resultados'] ?? [];
        $base = array_values(array_filter($a->pasos ?? [], fn ($p) => !isset($p['clave'])));

        $pasos = [
            'wan'    => fn () => $this->aplicarWan($acs, $a, $d, $sabeDeWan),
            'mac'    => fn () => $this->registrarMac($a, $d),
            'wifi'   => fn () => $this->aplicarWifi($acs, $a, $d),
            'cuenta' => fn () => $this->aplicarCuenta($acs, $a, $d, $huawei && $igd),
            // Al final: mientras se mueve el TR-069 el equipo deja de atender un momento.
            'tr069'  => fn () => $this->tr069PorInternet($acs, $a, $d),
        ];
        $pedidos = [
            'wan'    => !empty($datos['wan']),
            // Con IP fija el MikroTik sólo le contesta si tiene la MAC de su WAN.
            'mac'    => ($datos['wan']['tipo'] ?? null) === 'static' && $huawei && $igd,
            'wifi'   => !empty($datos['wifi']),
            'cuenta' => !empty($datos['admin']),
            'tr069'  => !empty($datos['wan']) && $huawei && $igd,
        ];
        $pendienteDe = null;

        foreach ($pasos as $clave => $aplicar) {
            if (!$pedidos[$clave] || (($resultados[$clave]['ok'] ?? false) && empty($resultados[$clave]['reintentar']))) {
                continue;
            }

            // La MAC se lee de la conexión ya creada: antes no hay qué leer.
            if ($clave === 'mac' && !($resultados['wan']['ok'] ?? false)) {
                continue;
            }

            // Cancelado a mitad de camino: lo hecho queda (y anotado), lo demás
            // no se aplica.
            if (self::fueCancelado($a)) {
                self::anotarPasosSinEstado($a, array_merge($base, array_values($resultados)));

                return;
            }

            // El TR-069 pasa a la conexión de internet sólo si ésta ya anda
            // (con IP fija, también su MAC en el MikroTik): si no, el equipo
            // quedaría fuera del servidor.
            if ($clave === 'tr069' && (!($resultados['wan']['ok'] ?? false) || ($pedidos['mac'] && !($resultados['mac']['ok'] ?? false)))) {
                continue;
            }

            $r = $aplicar();
            $resultados[$clave] = $r + ['clave' => $clave];
            $pendienteDe ??= $r['reintentar'] ?? null;
        }

        $datos['resultados'] = $resultados;
        $lista = array_merge($base, array_values($resultados));

        if (self::fueCancelado($a)) {
            self::anotarPasosSinEstado($a, $lista);

            return;
        }

        // Contador propio: el de "esperando su configuración" es otro.
        $vuelta = (int) ($datos['reintentos'] ?? 0);

        if ($pendienteDe && $vuelta < self::REINTENTOS) {
            $datos['reintentos'] = ++$vuelta;
            $this->refrescar($acs, $a, (string) $a->acs_id, $igd, $pendienteDe);

            $a->fill([
                'datos'   => $datos,
                'pasos'   => $lista,
                'detalle' => "Esperando al equipo para terminar (intento {$vuelta} de " . self::REINTENTOS . ')…',
            ])->save();

            return;
        }

        $mal = collect($lista)->filter(fn ($p) => !$p['ok'] && empty($p['omitido']));

        // La conexión a internet no es un paso más. Si no quedó, el cliente
        // NO NAVEGA, y da igual que el resto haya salido bien: decir «equipo
        // aprovisionado» manda al técnico a su casa con el servicio caído.
        //
        // Se marcaba como «omitido» —«en esta marca todavía no se configura
        // por TR-069»— y los omitidos no contaban como falla, así que salía
        // en verde. Pasó con una ONU C-Data en la OLT Huawei: acceso de
        // gestión puesto, WiFi puesto, y sin internet.
        $wan = collect($lista)->first(fn ($p) => ($p['clave'] ?? null) === 'wan'
            || str_starts_with(mb_strtolower((string) ($p['paso'] ?? '')), 'conexión a internet'));

        $sinInternet = !empty($datos['wan']) && $wan && !($wan['ok'] ?? false);

        $a->fill([
            'datos'    => $datos,
            'pasos'    => $lista,
            'estado'   => ($mal->isEmpty() && !$sinInternet) ? 'listo' : 'con_errores',
            'detalle'  => match (true) {
                $sinInternet => 'El cliente NO navega: falta cargarle la conexión al equipo. '
                    . rtrim((string) ($wan['detalle'] ?? ''), '.') . '.',
                $mal->isEmpty()   => 'Equipo aprovisionado.',
                $mal->count() === 1 => 'Un paso no se aplicó.',
                default           => "{$mal->count()} pasos no se aplicaron.",
            },
            'listo_en' => now(),
        ])->save();

        // La IP prestada se suelta sólo cuando el equipo quedó andando de
        // verdad: si le falta la conexión, todavía la necesita para que se le
        // pueda entrar y terminarla.
        if ($mal->isEmpty() && !$sinInternet) {
            $this->soltarIpPrestada($a);
        }
    }

    /**
     * Devuelve la IP que se le prestó al equipo para poder entrar.
     *
     * Sólo se suelta si el cliente de verdad quedó en otra conexión: si su
     * plan sigue siendo esa IP fija, era suya desde el principio y se queda.
     */
    private function soltarIpPrestada(Aprovisionamiento $a): void
    {
        $ip = $a->datos['ip_prestada'] ?? null;

        if (!$ip || !$a->user_id) {
            return;
        }

        $tipo = (string) DB::table('user_data')->where('user_id', $a->user_id)->value('connection_type');

        if ($tipo !== 'pppoe') {
            return;
        }

        DB::table('user_data')->where('user_id', $a->user_id)->update(['ip_assignment_id' => null]);

        $datos = $a->datos;
        unset($datos['ip_prestada']);

        $a->timestamps = false;
        $a->datos = $datos;
        $a->pasos = array_merge($a->pasos ?? [], [[
            'paso'    => 'Soltar la IP prestada',
            'ok'      => true,
            'detalle' => "El equipo ya está por PPPoE: se devolvió la IP {$ip} a la tabla.",
        ]]);
        $a->save();
        $a->timestamps = true;
    }

    /**
     * Lo que se decidió de este equipo, guardado en el aprovisionamiento. Se
     * decide una vez; si en el alta "no se sabía" (sólo con el serial), se
     * vuelve a decidir una vez preguntándole a la OLT. Los que se programaron
     * antes de existir esta revisión se deciden en su primera vuelta.
     *
     * @return array<string,mixed>
     */
    private function compatibilidadDe(Aprovisionamiento $a): array
    {
        $guardada = $a->datos['compatibilidad'] ?? null;

        if (is_array($guardada) && ($guardada['puede'] !== CompatibilidadDeOnt::NO_SE_SABE || !empty($guardada['con_olt']) || !empty($guardada['releida']))) {
            return $guardada;
        }

        $g = GestionRemota::where('company_id', $a->company_id)->first();
        $nueva = self::compatibilidad($g, (int) $a->olt_id, (string) $a->fsp, (int) $a->ont_id, (string) $a->serial, true) + ['releida' => true];

        $a->datos = array_merge($a->datos ?? [], ['compatibilidad' => $nueva]);

        if ($nueva['puede'] !== CompatibilidadDeOnt::NO) {
            $a->detalle = self::textoDeEspera($nueva);
        }

        $a->save();

        return $nueva;
    }

    /** El equipo en el ACS, sólo si reportó después de autorizarse. */
    private function buscarEnElAcs(GenieAcs $acs, Aprovisionamiento $a): ?string
    {
        $crudo = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $a->serial));
        $series = EquiposDelAcs::serialesPosibles((string) $a->serial);

        $filas = $acs->dispositivos(['_deviceId._SerialNumber' => ['$in' => $series]], ['_id', '_lastInform']);
        usort($filas, fn ($x, $y) => strcmp((string) ($y['_lastInform'] ?? ''), (string) ($x['_lastInform'] ?? '')));
        $fila = $filas[0] ?? null;

        // Uno viejo en la base del ACS (el equipo estuvo en otro lado) no sirve:
        // hay que esperar a que reporte ya autorizado.
        if (!$fila || empty($fila['_lastInform']) || Carbon::parse($fila['_lastInform'])->lt($a->created_at->copy()->subMinutes(2))) {
            return null;
        }

        return (string) $fila['_id'];
    }

    /**
     * Le pide al equipo una rama de su configuración.
     *
     * Una sola por vez: pedir tres juntas hacía sesiones largas que el equipo
     * no alcanzaba a terminar («session timeout»), la tarea fallaba y quedaba
     * en cola. Con cada reporte se repetía y se acumulaban (PRUEBA_TR llegó a
     * nueve). Por eso también se limpia lo que haya quedado colgado antes,
     * pero sólo lo que encoló este aprovisionamiento: antes se borraba toda la
     * cola del equipo, también lo que había pedido otra pantalla o el operador.
     */
    private function refrescar(GenieAcs $acs, Aprovisionamiento $a, string $id, bool $igd, ?string $rama = null): void
    {
        $rama ??= $igd ? self::WAN : 'Device.WiFi';

        try {
            $propias = array_merge((array) ($a->datos['tareas_acs'] ?? []), $acs->creadas());

            foreach ($acs->tareasPendientes($id) as $vieja) {
                if (self::esPropia($vieja, $propias)) {
                    $acs->borrarTarea((string) $vieja['_id']);
                }
            }
        } catch (\Throwable $e) {
            Log::info('[Aprovisionamiento] No se pudo limpiar la cola del equipo', ['equipo' => $id, 'error' => $e->getMessage()]);
        }

        try {
            $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => $rama]);
        } catch (\Throwable $e) {
            Log::info('[Aprovisionamiento] No se pudo pedir la configuración', ['equipo' => $id, 'rama' => $rama, 'error' => $e->getMessage()]);
        }
    }

    /**
     * ¿La tarea de la cola la encoló este aprovisionamiento? Por su _id o, si
     * la espera se cortó antes de tenerlo, por nombre, objeto y hora.
     *
     * @param array<string,mixed> $tarea la de la cola
     * @param list<array<string,mixed>> $propias
     */
    public static function esPropia(array $tarea, array $propias): bool
    {
        $id = (string) ($tarea['_id'] ?? '');
        $objeto = $tarea['objectName'] ?? (isset($tarea['parameterValues'])
            ? implode(',', array_map(fn ($p) => (string) ($p[0] ?? ''), (array) $tarea['parameterValues']))
            : (isset($tarea['parameterNames']) ? implode(',', (array) $tarea['parameterNames']) : null));
        $cuando = isset($tarea['timestamp']) ? strtotime((string) $tarea['timestamp']) : null;

        foreach ($propias as $p) {
            if (!empty($p['id'])) {
                if ((string) $p['id'] === $id) {
                    return true;
                }

                continue;
            }

            if (($p['name'] ?? null) === ($tarea['name'] ?? null) && ($p['objeto'] ?? null) === $objeto
                && (!$cuando || $cuando >= strtotime((string) ($p['en'] ?? '')))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guarda las tareas que encoló esta vuelta, para poder limpiar después
     * sólo las propias.
     */
    public function anotarTareas(Aprovisionamiento $a, GenieAcs $acs): void
    {
        if (!$acs->creadas() || !$a->exists) {
            return;
        }

        try {
            $datos = $a->datos;
            $datos['tareas_acs'] = array_slice(array_merge((array) ($datos['tareas_acs'] ?? []), $acs->creadas()), -40);
            $a->datos = $datos;
            $a->save();
        } catch (\Throwable $e) {
            Log::info('[Aprovisionamiento] No se pudieron anotar las tareas', ['id' => $a->id, 'error' => $e->getMessage()]);
        }
    }

    // ── Los pasos ─────────────────────────────────────────────────────────

    /**
     * Deja en el equipo la conexión a internet de $wan (por defecto, la del
     * aprovisionamiento).
     *
     * Todo lo que toca las conexiones se hace en el momento o no se hace:
     * borrar una conexión, crearla o cambiarla quedaba en la cola del ACS si el
     * equipo no atendía el aviso, y la hacía en su próximo reporte sin nadie
     * mirando (DOUGLAS_MENDEZ, 18-09: el borrado de su única conexión quedó
     * esperando 8 horas). Si no se puede ya, se saca de la cola y el paso
     * falla con «reintentar»: la tarea de cada minuto lo vuelve a probar.
     *
     * @param array<string,mixed>|null $wan
     */
    public function aplicarWan(GenieAcs $acs, Aprovisionamiento $a, array $d, bool $soportado, ?array $wan = null): array
    {
        $wan ??= $a->datos['wan'];
        $titulo = 'Conexión a internet';

        if (!$soportado) {
            return ['paso' => $titulo, 'ok' => false, 'omitido' => true,
                'detalle' => 'En esta marca la conexión a internet todavía no se configura por TR-069: cargala en el equipo (' . self::resumenWan($wan) . ').'];
        }

        $vlanGestion = (int) (GestionRemota::where('company_id', $this->companyId)->value('vlan') ?: 0);
        $objeto = $wan['tipo'] === 'pppoe' ? 'WANPPPConnection' : 'WANIPConnection';
        $equipo = (string) $a->acs_id;

        // Las conexiones de adentro de cada grupo muchas veces no están leídas
        // (se ven los grupos 1 y 2 pero no lo que tienen): se piden de nuevo.
        try {
            $acs->tarea($equipo, ['name' => 'refreshObject', 'objectName' => self::WAN]);
            $d = $acs->dispositivo($equipo) ?? $d;
        } catch (\Throwable) {
        }

        // Sin saber qué tiene cada grupo no se toca nada: un grupo sin leer se
        // veía vacío y se le metía la conexión nueva al lado de la del dueño
        // anterior, que se quedaba con los puertos LAN y el WiFi (ANGIE_MONTALVO,
        // 19-09: PPPoE conectado y sin internet).
        if ($sinLeer = self::gruposSinLeer($d)) {
            return ['paso' => $titulo, 'ok' => false, 'reintentar' => self::WAN,
                'detalle' => 'No se pudieron leer las conexiones que ya tiene el equipo (grupo ' . implode(', ', $sinLeer) . '): se reintenta sin tocar nada.'];
        }

        // La conexión de gestión no se toca nunca: por ahí llega el TR-069.
        $todas = collect($this->conexiones($d));
        $conexiones = $todas
            ->reject(fn ($c) => ($vlanGestion && $c['vlan'] === $vlanGestion) || $c['servicios'] === 'TR069');

        // Las de otra VLAN vienen del dueño anterior del equipo: no dan servicio
        // y chocan con la del cliente. Se borra cada grupo que sea sólo ajeno;
        // en un grupo compartido, sólo la conexión de internet ajena (sin el
        // TR-069). Los puertos que tenía pasan a la del cliente.
        $ajenas = $conexiones->filter(fn ($c) => $c['vlan'] !== null && $c['vlan'] !== (int) $wan['vlan']);
        $borradas = [];
        $quitados = [];
        $puertos = [];

        foreach ($ajenas->groupBy('dispositivo') as $grupo => $delGrupo) {
            $todoElGrupo = $todas->where('dispositivo', $grupo)->count() === $delGrupo->count();
            $aBorrar = $todoElGrupo ? $delGrupo
                : $delGrupo->filter(fn ($c) => str_contains($c['servicios'], 'INTERNET') && !str_contains($c['servicios'], 'TR069'));

            if ($todoElGrupo) {
                $r = $this->alMomento($acs, $equipo, ['name' => 'deleteObject', 'objectName' => self::WAN . ".{$grupo}"]);

                if ($r['hecha']) {
                    $quitados[] = (string) $grupo;
                }
            } else {
                foreach ($aBorrar as $i => $c) {
                    if (!$this->alMomento($acs, $equipo, ['name' => 'deleteObject', 'objectName' => $c['ruta']])['hecha']) {
                        $aBorrar->forget($i);
                    }
                }
                $r = ['hecha' => $aBorrar->isNotEmpty()];
            }

            if ($r['hecha']) {
                $borradas = array_merge($borradas, $aBorrar->pluck('nombre')->all());
                foreach ($aBorrar as $c) {
                    $puertos += array_filter(self::puertosDe($d, $c['ruta']));
                }
                $rutas = $aBorrar->pluck('ruta')->all();
                $todas = $todas->reject(fn ($c) => in_array($c['ruta'], $rutas, true));
                $conexiones = $conexiones->reject(fn ($c) => in_array($c['ruta'], $rutas, true));
            }
        }

        $conexiones = $conexiones->reject(fn ($c) => in_array((string) $c['dispositivo'], $quitados, true));
        $nota = $borradas ? ' Se borraron las conexiones ajenas: ' . implode(', ', $borradas) . '.' : '';

        // Una del mismo tipo recién creada en una vuelta anterior que no se
        // alcanzó a configurar (sin VLAN ni servicio) se usa en vez de crear otra.
        $existente = $conexiones->first(fn ($c) => $c['objeto'] === $objeto && $c['vlan'] === (int) $wan['vlan'])
            ?? $conexiones->first(fn ($c) => $c['objeto'] === $objeto && str_contains($c['servicios'], 'INTERNET'))
            ?? $conexiones->first(fn ($c) => $c['objeto'] === $objeto && $c['vlan'] === null && $c['servicios'] === '');

        if ($existente) {
            $ruta = $existente['ruta'];
            $como = 'Se ajustó la conexión que ya tenía';
            $servicios = $existente['servicios'];
        } else {
            $choca = $conexiones->first(fn ($c) => $c['vlan'] === (int) $wan['vlan'] || str_contains($c['servicios'], 'INTERNET'));

            // Una conexión de internet de otro tipo en la VLAN del cliente es la
            // que tenía antes de cambiarle la conexión (PRUEBA_TR pasó de IP fija
            // a PPPoE y seguía con la IP fija): se reemplaza por la que dice su
            // ficha. Las de otras VLAN ya se borraron arriba como ajenas.
            if ($choca) {
                $grupo = (string) $choca['dispositivo'];
                $soloEsa = $todas->where('dispositivo', $grupo)->count() === 1;

                $r = $this->alMomento($acs, $equipo, ['name' => 'deleteObject', 'objectName' => $soloEsa ? self::WAN . ".{$grupo}" : $choca['ruta']]);

                if (!$r['hecha']) {
                    return ['paso' => $titulo, 'ok' => false,
                        'detalle' => "No se pudo borrar la conexión anterior {$choca['nombre']} para crear la nueva: {$r['motivo']}", 'reintentar' => self::WAN];
                }

                if ($soloEsa) {
                    $quitados[] = $grupo;
                }

                $todas = $todas->reject(fn ($c) => $c['ruta'] === $choca['ruta']);
                $nota .= " Se reemplazó la conexión anterior {$choca['nombre']}.";
            }

            // Un grupo vacío (de un intento anterior o de una conexión borrada) se
            // reutiliza en vez de crear otro.
            $vacio = collect(self::hijos($d, self::WAN))
                ->reject(fn ($g) => $todas->contains('dispositivo', (string) $g) || in_array((string) $g, $quitados, true))
                ->first();

            $nueva = $vacio !== null ? (string) $vacio : $this->crearObjeto($acs, $equipo, self::WAN);
            $conexion = $nueva ? $this->crearObjeto($acs, $equipo, self::WAN . ".{$nueva}.{$objeto}") : null;

            if (!$conexion) {
                return ['paso' => $titulo, 'ok' => false,
                    'detalle' => 'No se pudo crear la conexión' . ($this->motivo ? " ({$this->motivo})" : '') . '. Si no se resuelve, configurala a mano (' . self::resumenWan($wan) . ').' . $nota,
                    // Si el equipo sólo tardó se vuelve a intentar solo; si la rechazó, no.
                    'reintentar' => in_array($this->motivo, ['', self::SIN_RESPUESTA, self::NO_APARECE], true) ? self::WAN : null];
            }

            $ruta = self::WAN . ".{$nueva}.{$objeto}.{$conexion}";
            $como = 'Se creó la conexión';
            $servicios = '';
        }

        $dialecto = self::dialectoWan($d) ?? ['vlan' => 'X_HW_VLAN', 'servicios' => 'X_HW_SERVICELIST', 'lanbind' => 'X_HW_LANBIND'];
        $valores = $this->valoresWan($a, $ruta, $wan, $servicios, $dialecto);

        $r = $this->alMomento($acs, $equipo, ['name' => 'setParameterValues', 'parameterValues' => $valores]);

        if (!$r['hecha']) {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => "{$como}, pero no se le pudieron cargar los datos: {$r['motivo']}{$nota}",
                // Rechazada por el equipo no se insiste; si no contestó, sí.
                'reintentar' => $r['rechazada'] ? null : self::WAN];
        }

        // Aparte, para que un equipo que no acepte los puertos no tumbe la
        // conexión. C-Data no reparte puertos por conexión: no hay qué pasar.
        if ($puertos && ($dialecto['lanbind'] ?? null)) {
            $r = $this->alMomento($acs, $equipo, ['name' => 'setParameterValues', 'parameterValues' => array_map(
                fn ($p) => ["{$ruta}.{$dialecto['lanbind']}.{$p}", 'true', 'xsd:boolean'], array_keys($puertos))]);

            $nota .= $r['hecha']
                ? ' Los puertos que usaba la anterior (' . implode(', ', array_keys($puertos)) . ') quedaron en la del cliente.'
                : ' No se le pudieron pasar los puertos de la anterior (' . implode(', ', array_keys($puertos)) . "): {$r['motivo']}";
        }

        // Sin ruta por defecto, lo que no está amarrado a una conexión no sale
        // a ningún lado (ANGIE_MONTALVO: al borrar la del dueño anterior quedó
        // vacía y el PPPoE conectado no pasaba tráfico).
        if (self::v($d, self::RUTA_POR_DEFECTO) === null) {
            try {
                $acs->tarea($equipo, ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.Layer3Forwarding'], 20);
                $d = $acs->dispositivo($equipo) ?? $d;
            } catch (\Throwable) {
            }
        }
        $porDefecto = self::v($d, self::RUTA_POR_DEFECTO);
        if ($porDefecto !== null && $porDefecto !== $ruta && ($porDefecto === '' || !$todas->contains('ruta', rtrim((string) $porDefecto, '.')))) {
            $r = $this->alMomento($acs, $equipo, ['name' => 'setParameterValues', 'parameterValues' => [[self::RUTA_POR_DEFECTO, $ruta, 'xsd:string']]]);
            $nota .= $r['hecha'] ? ' La salida por defecto del equipo quedó en esta conexión.' : " No se pudo dejar la salida por defecto en esta conexión: {$r['motivo']}";
        }

        return ['paso' => $titulo, 'ok' => true, 'detalle' => "{$como}: " . self::resumenWan($wan) . '.' . $nota];
    }

    /**
     * Los grupos de conexiones cuyo contenido el ACS no tiene leído: se ven,
     * pero no se sabe si están vacíos.
     *
     * @return list<string>
     */
    public static function gruposSinLeer(array $d): array
    {
        $grupos = \Illuminate\Support\Arr::get($d, self::WAN);

        return array_values(array_filter(self::hijos($d, self::WAN), fn ($g) => !is_array($grupos[$g]['WANIPConnection'] ?? null)
            && !is_array($grupos[$g]['WANPPPConnection'] ?? null)));
    }

    /** @return array<string,bool> los puertos LAN/WiFi de una conexión (Huawei) */
    public static function puertosDe(array $d, string $ruta): array
    {
        $lista = [];

        foreach ((array) (\Illuminate\Support\Arr::get($d, "{$ruta}.X_HW_LANBIND") ?? []) as $nombre => $nodo) {
            if (!str_starts_with((string) $nombre, '_') && is_array($nodo) && array_key_exists('_value', $nodo)) {
                $lista[(string) $nombre] = filter_var($nodo['_value'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $lista;
    }

    /**
     * Lo que se le carga a una conexión para que quede como $wan. $servicios
     * es lo que ya lleva: si trae el TR-069 no se le cambia.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public function valoresWan(Aprovisionamiento $a, string $ruta, array $wan, string $servicios = '', ?array $dialecto = null): array
    {
        $dialecto ??= ['vlan' => 'X_HW_VLAN', 'servicios' => 'X_HW_SERVICELIST'];

        $valores = [
            ["{$ruta}.Enable", 'true', 'xsd:boolean'],
            ["{$ruta}.ConnectionType", 'IP_Routed', 'xsd:string'],
            ["{$ruta}.NATEnabled", 'true', 'xsd:boolean'],
            ["{$ruta}.{$dialecto['vlan']}", (string) $wan['vlan'], 'xsd:unsignedInt'],
        ];

        // Si ya lleva también el TR-069 ("TR069_INTERNET") se deja así: quitárselo
        // lo sacaría del servidor.
        if (!str_contains($servicios, 'TR069')) {
            $valores[] = ["{$ruta}.{$dialecto['servicios']}", 'INTERNET', 'xsd:string'];
        }

        if ($wan['tipo'] === 'pppoe') {
            // En un cambio de conexión la clave nueva todavía no está en la
            // ficha (se guarda cuando anda): viaja cifrada en la WAN pedida.
            $clave = (string) ($wan['clave_cifrada'] ?? (UserData::where('user_id', $a->user_id)->value('pppoe_password') ?? ''));
            $clave = $clave !== '' ? self::descifrar($clave) : '';

            $valores[] = ["{$ruta}.Username", $wan['usuario'], 'xsd:string'];
            if ($clave !== '') {
                $valores[] = ["{$ruta}.Password", $clave, 'xsd:string'];
            }
        } else {
            $valores[] = ["{$ruta}.AddressingType", 'Static', 'xsd:string'];
            $valores[] = ["{$ruta}.ExternalIPAddress", $wan['ip'], 'xsd:string'];
            $valores[] = ["{$ruta}.SubnetMask", $wan['mascara'], 'xsd:string'];
            $valores[] = ["{$ruta}.DefaultGateway", $wan['gateway'], 'xsd:string'];
            $valores[] = ["{$ruta}.DNSServers", $wan['dns'] ?? self::DNS, 'xsd:string'];
        }

        return $valores;
    }

    /**
     * Una tarea sobre las conexiones, hecha en el momento o sacada de la cola.
     *
     * @return array{hecha:bool, rechazada:bool, motivo:string, instancia:mixed}
     */
    private function alMomento(GenieAcs $acs, string $equipo, array $tarea): array
    {
        try {
            $r = $acs->tareaInmediata($equipo, $tarea);
        } catch (\Throwable $e) {
            // Ni siquiera se encoló (el ACS la rechazó o no contestó): se mira
            // que no haya quedado nada de todas formas.
            try {
                $acs->sacarDeLaCola($equipo, $tarea, null, now()->subMinutes(3));
            } catch (\Throwable) {
            }

            return ['hecha' => false, 'rechazada' => false, 'motivo' => 'el servidor TR-069 no respondió (' . mb_substr($e->getMessage(), 0, 80) . ').', 'instancia' => null];
        }

        if ($r['hecha']) {
            return ['hecha' => true, 'rechazada' => false, 'motivo' => '', 'instancia' => $r['instancia']];
        }

        if ($r['incierta']) {
            Log::warning('[Aprovisionamiento] Tarea de conexión sin confirmar: el equipo estaba en sesión', ['equipo' => $equipo, 'tarea' => $tarea['name'] ?? null]);
        }

        return [
            'hecha'     => false,
            'rechazada' => (bool) $r['falla'],
            'motivo'    => $r['falla']
                ? "el equipo la rechazó ({$r['falla']})."
                : ($r['incierta']
                    ? 'el equipo estaba ocupado y no se pudo confirmar ni sacar de la cola: revisá el equipo antes de reintentar.'
                    : 'el equipo no respondió al momento y no se le dejó el cambio en cola (lo haría horas después, sin nadie mirando).'),
            'instancia' => null,
        ];
    }

    /**
     * Deja el TR-069 en la conexión de internet ("TR069_INTERNET") y, cuando el
     * equipo ya reporta por ahí, quita de la OLT la conexión de gestión.
     *
     * La de gestión la crea la OLT ("ont ipconfig") y la vuelve a crear en cada
     * reinicio de la ONT: puede caer en el lugar de la de internet y borrarla.
     * Pasó el 17-09 cuando se reinició la OLT Huawei (DOUGLAS_MENDEZ y
     * LILIANA_COROMOTO). Con una sola conexión, como JULIO_VALLEJO, no pasa.
     */
    public function tr069PorInternet(GenieAcs $acs, Aprovisionamiento $a, array $d, ?array $wan = null): array
    {
        $titulo = 'TR-069 por la conexión de internet';
        $wan ??= $a->datos['wan'];
        $g = GestionRemota::where('company_id', $this->companyId)->first();

        $conexion = collect($this->conexiones($d))
            ->first(fn ($c) => $c['vlan'] === (int) $wan['vlan'] && str_contains($c['servicios'], 'INTERNET'));

        if (!$conexion) {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => 'El equipo todavía no informó su conexión de internet.', 'reintentar' => self::WAN];
        }

        if (!str_contains($conexion['servicios'], 'TR069')) {
            // Cambia la conexión por la que va a llegar el TR-069: en el momento
            // o nada, igual que borrar una conexión.
            $r = $this->alMomento($acs, (string) $a->acs_id, ['name' => 'setParameterValues', 'parameterValues' => [
                ["{$conexion['ruta']}.X_HW_SERVICELIST", 'TR069_INTERNET', 'xsd:string'],
            ]]);

            if ($r['rechazada']) {
                return ['paso' => $titulo, 'ok' => false, 'omitido' => true,
                    'detalle' => "El equipo no aceptó el TR-069 en su conexión de internet ({$r['motivo']}): sigue por la de gestión."];
            }

            return ['paso' => $titulo, 'ok' => false,
                'detalle' => $r['hecha'] ? 'Pasando el TR-069 a la conexión de internet…' : 'No se pudo pasar el TR-069 a la conexión de internet: ' . $r['motivo'],
                'reintentar' => self::WAN];
        }

        // Mientras la OLT le tenga creada la de gestión, la ONT sigue reportando
        // por ésa (la OLT se la marca para el TR-069): no se puede esperar a que
        // cambie sola. Lo que se exige antes de quitarla es que la de internet
        // esté conectada y con su IP; por ahí reportan JULIO_VALLEJO y los demás.
        $estado = (string) self::v($d, "{$conexion['ruta']}.ConnectionStatus");
        $ip = (string) self::v($d, "{$conexion['ruta']}.ExternalIPAddress");

        if ($estado !== 'Connected' || !filter_var($ip, FILTER_VALIDATE_IP) || ($g?->red && self::enRed($ip, (string) $g->red))) {
            return ['paso' => $titulo, 'ok' => false,
                'detalle' => 'El TR-069 ya está en la conexión de internet; se espera que esa conexión quede conectada para quitar la de gestión.',
                'reintentar' => self::WAN];
        }

        $vlanGestion = (int) ($g?->vlan ?: 0);
        $olt = OltAdmin::where('company_id', $this->companyId)->find($a->olt_id);

        if (!$vlanGestion || !$olt) {
            return ['paso' => $titulo, 'ok' => true, 'detalle' => "El TR-069 va por la conexión de internet ({$ip})."];
        }

        // La gestión de la OLT ya no se quita. El Huawei sigue reportando por
        // ella hasta reiniciarse aunque la OLT la borre, y al perder su IP deja
        // de reportar del todo: el 17-09 quedaron así 16 equipos (PRUEBA_TR,
        // LILIANA_GIL, KEYLA_DE_AVILA…). Con la de internet en el lugar 1 y la
        // de gestión en el 2, un reinicio de la OLT no pisa nada; si la de
        // internet está en el 2, darGestionAOnt no crea la de gestión.
        if (!config('services.genieacs.quitar_gestion_olt', false)) {
            return ['paso' => $titulo, 'ok' => true, 'detalle' => "El TR-069 también va por la conexión de internet ({$ip}); se le deja la gestión de la OLT."];
        }

        try {
            $q = app(\App\Services\OltTelnetDispatcher::class)->dispatch((int) $olt->id, 'quitarGestionDeOnt', [
                'fsp' => $a->fsp, 'ont_id' => (int) $a->ont_id, 'vlan' => $vlanGestion,
            ]);
        } catch (\Throwable $e) {
            return ['paso' => $titulo, 'ok' => false,
                'detalle' => "El TR-069 ya va por internet ({$ip}), pero no se pudo quitar la gestión de la OLT: " . mb_substr($e->getMessage(), 0, 120),
                'reintentar' => self::WAN];
        }

        // Otras marcas de OLT no crean esa conexión: no hay nada que quitar.
        if (!is_array($q)) {
            return ['paso' => $titulo, 'ok' => true, 'detalle' => "El TR-069 va por la conexión de internet ({$ip})."];
        }

        if ($q['ok']) {
            $ont = \App\Models\OltOnt::where('olt_id', $olt->id)->where('fsp', $a->fsp)->where('ont_id', $a->ont_id)->first();

            if ($ont) {
                $ont->update(['service_ports' => collect($ont->service_ports ?? [])
                    ->reject(fn ($s) => (int) ($s['vlan'] ?? 0) === $vlanGestion)->values()->all()]);
            }
        }

        return ['paso' => $titulo, 'ok' => (bool) $q['ok'],
            'detalle' => $q['ok'] ? "El TR-069 va por la conexión de internet ({$ip}). {$q['detalle']}." : $q['detalle']];
    }

    public static function enRed(string $ip, string $cidr): bool
    {
        [$red, $bits] = array_pad(explode('/', $cidr), 2, 32);
        $mascara = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mascara) === (ip2long($red) & $mascara);
    }

    /**
     * Los signos que una clave o un nombre de red pueden llevar.
     *
     * La clave viaja por SOAP hasta el equipo y, en algunas marcas, termina
     * metida en una línea de consola. Comillas, barras y acentos graves la
     * parten por la mitad en algún punto del camino, y el equipo contesta
     * «Invalid arguments» sin decir dónde. Con este puñado de signos alcanza
     * para una clave segura y no hay nada que se pueda romper.
     */
    public const SIGNOS_WIFI = '-_.@#$%&*+=!?():,';

    /**
     * La expresión que acepta la clave, o el nombre de la red.
     *
     * El nombre además admite espacios —«Casa de Juan» es un nombre de red
     * como cualquier otro y viaja sin problema—; la clave no, porque hay
     * marcas que la pasan por una línea de consola y ahí el espacio corta.
     */
    public static function patronWifi(bool $conEspacio = false): string
    {
        return '/^[A-Za-z0-9' . ($conEspacio ? ' ' : '') . preg_quote(self::SIGNOS_WIFI, '/') . ']+$/';
    }

    /**
     * Por qué una clave WiFi no va a entrar, dicho en palabras.
     *
     * El equipo sólo contesta «Invalid arguments», así que la explicación
     * tiene que salir de acá.
     *
     * @return string|null El motivo, o null si la clave sirve.
     */
    public static function problemaDeLaClaveWifi(string $clave): ?string
    {
        $largo = strlen($clave);

        if ($largo < 8 || $largo > 63) {
            return "La clave WiFi tiene que tener entre 8 y 63 caracteres; ésta tiene {$largo}.";
        }

        return self::problemaDeLosCaracteres($clave, 'La clave WiFi', false);
    }

    /**
     * Lo mismo para el nombre de la red: una eñe en el SSID rompe igual.
     *
     * @return string|null El motivo, o null si el nombre sirve.
     */
    public static function problemaDelNombreWifi(string $ssid): ?string
    {
        if ($ssid === '' || mb_strlen($ssid) > 32) {
            return 'El nombre de la red tiene que tener entre 1 y 32 caracteres.';
        }

        return self::problemaDeLosCaracteres($ssid, 'El nombre de la red', true);
    }

    /**
     * Lo mismo para la clave de administración del equipo y para la del PPPoE.
     *
     * Acá no se mide el largo: esas claves las pone la marca o el proveedor y
     * las hay de cinco caracteres. Lo que sí importa son los signos, porque
     * viajan por el mismo camino y se rompen igual.
     *
     * @return string|null El motivo, o null si sirve.
     */
    public static function problemaDeUnaClaveDeEquipo(string $clave, string $que = 'La clave'): ?string
    {
        return $clave === '' ? null : self::problemaDeLosCaracteres($clave, $que, false);
    }

    /** El texto que explica qué signo sobra, para la clave o para el nombre. */
    private static function problemaDeLosCaracteres(string $texto, string $que, bool $conEspacio): ?string
    {
        if (preg_match(self::patronWifi($conEspacio), $texto)) {
            return null;
        }

        $raros = [];

        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) as $c) {
            if (!preg_match(self::patronWifi($conEspacio), $c)) {
                $raros[$c === ' ' ? 'espacios' : $c] = true;
            }
        }

        return $que . ' no puede llevar ' . implode(' ', array_keys($raros)) . '. '
            . 'Se admiten letras sin tilde ni eñe, números y estos signos: '
            . implode(' ', str_split(self::SIGNOS_WIFI)) . '.' . ($conEspacio ? ' Los espacios sí valen.' : '');
    }

    private function aplicarWifi(GenieAcs $acs, Aprovisionamiento $a, array $d): array
    {
        $ssid = (string) $a->datos['wifi']['ssid'];
        $clave = (string) $a->wifi_clave;
        $titulo = 'WiFi';

        $redes = array_values(array_filter(EquiposDelAcs::redesWifi($d), fn ($r) => $r['activo'] !== false));

        if (!$redes) {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => 'El equipo no informó redes WiFi encendidas: no se cambió.', 'reintentar' => self::WLAN];
        }

        // El estándar WPA sólo admite ASCII imprimible en la contraseña. Una
        // «ñ» o una tilde hacen que el equipo conteste «cwmp.9003 Invalid
        // arguments», que no le dice nada a nadie. Mejor decirlo acá: nadie
        // va a adivinar que el problema era la eñe.
        if ($problema = self::problemaDeLaClaveWifi($clave)) {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => $problema];
        }

        $valores = [];
        $nombres = [];

        // Las dos bandas con el mismo nombre y la misma clave: así el equipo
        // publica una sola red y manda a cada aparato a 2.4 o 5 GHz solo. Estas
        // Huawei no tienen un interruptor para unirlas; se hace así.
        foreach ($redes as $red) {
            $nombre = $ssid;

            $valores[] = [$red['ruta_ssid'], $nombre, 'xsd:string'];

            foreach ($red['rutas_clave'] ?? [] as $ruta) {
                $valores[] = [$ruta, $clave, 'xsd:string'];
            }

            $nombres[] = "«{$nombre}»";
        }

        if (!collect($redes)->pluck('ruta_clave')->filter()->count()) {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => 'El equipo no deja cambiar la clave WiFi por TR-069.'];
        }

        $r = $acs->tarea((string) $a->acs_id, ['name' => 'setParameterValues', 'parameterValues' => $valores]);

        return $this->resultado($acs, (string) $a->acs_id, $r, $titulo, 'Redes ' . implode(' y ', $nombres) . ' con la clave del aprovisionamiento');
    }

    private function aplicarCuenta(GenieAcs $acs, Aprovisionamiento $a, array $d, bool $soportado): array
    {
        $titulo = 'Cuenta de administración del equipo';
        $g = GestionRemota::where('company_id', $this->companyId)->first();

        // C-Data: la cuenta del proveedor (usuario fijo adminisp). Según el
        // firmware la publica como «X_CATV_» o como «X_CT-COM_»: con una sola
        // ruta, a los equipos del segundo tipo se les decía que no se podía y
        // había que ir al equipo a mano.
        $esCdata = self::v($d, 'InternetGatewayDevice.DeviceInfo.X_CATV_TeleComAccount.Enable') !== null
            || self::v($d, 'InternetGatewayDevice.DeviceInfo.X_CT-COM_TeleComAccount.Enable') !== null
            || str_contains(strtoupper((string) ($d['_deviceId']['_Manufacturer'] ?? '')), 'CDATA')
            || ($d['_deviceId']['_OUI'] ?? '') === '80F7A6';

        if ($esCdata) {
            $r = (new \App\Services\Acs\ClaveDeOnuPorTr069($this->companyId, $acs))->asegurar((string) $a->acs_id, (string) $g?->onu_admin_clave);

            return ['paso' => $titulo, 'ok' => $r['ok'], 'detalle' => $r['detalle']] + (($r['omitido'] ?? false) ? ['omitido' => true] : []);
        }

        if (!$soportado) {
            return ['paso' => $titulo, 'ok' => false, 'omitido' => true, 'detalle' => 'En esta marca todavía no se cambia por TR-069.'];
        }

        $cuentas = self::hijos($d, self::CUENTAS);

        if (!$cuentas || !$g?->onu_admin_usuario || !$g->onu_admin_clave) {
            return ['paso' => $titulo, 'ok' => false, 'omitido' => true, 'detalle' => 'El equipo no informó sus cuentas de acceso web: no se cambió.', 'reintentar' => 'InternetGatewayDevice.UserInterface'];
        }

        // La de nivel 0 es la de administración. Hay modelos que no informan el
        // nivel (HG8145X6-10: sólo la cuenta 2, "admin"): entonces la que tiene
        // el usuario de la empresa o uno de administración, o la única.
        $cuenta = collect($cuentas)->first(fn ($i) => (string) self::v($d, self::CUENTAS . ".{$i}.UserLevel") === '0')
            ?? collect($cuentas)->first(fn ($i) => in_array(strtolower((string) self::v($d, self::CUENTAS . ".{$i}.UserName")),
                array_filter([strtolower((string) $g->onu_admin_usuario), 'admin', 'telecomadmin', 'root']), true))
            ?? (count($cuentas) === 1 ? $cuentas[0] : null);

        if ($cuenta === null) {
            return ['paso' => $titulo, 'ok' => false, 'omitido' => true, 'detalle' => 'No se encontró la cuenta de administración del equipo: no se cambió.'];
        }

        $r = $acs->tarea((string) $a->acs_id, ['name' => 'setParameterValues', 'parameterValues' => [
            [self::CUENTAS . ".{$cuenta}.UserName", $g->onu_admin_usuario, 'xsd:string'],
            [self::CUENTAS . ".{$cuenta}.Password", $g->onu_admin_clave, 'xsd:string'],
        ]]);

        return $this->resultado($acs, (string) $a->acs_id, $r, $titulo, "Usuario {$g->onu_admin_usuario} con la clave de la empresa");
    }

    /**
     * Carga en el ARP del MikroTik la MAC de la WAN de IP fija del cliente.
     *
     * Las VLAN de clientes tienen el ARP en "reply-only": el router sólo le
     * contesta a la IP si la entrada tiene la MAC real del equipo. El alta y
     * los cambios de conexión la dejaban en 00:00:00:00:00:00 y el cliente
     * nunca respondía.
     */
    public function registrarMac(Aprovisionamiento $a, array $d, ?array $wan = null): array
    {
        $titulo = 'MAC en el MikroTik';
        $wan ??= $a->datos['wan'];

        $conexion = collect($this->conexiones($d))
            ->first(fn ($c) => $c['objeto'] === 'WANIPConnection' && $c['vlan'] === (int) $wan['vlan']);
        $mac = $conexion ? strtoupper((string) self::v($d, "{$conexion['ruta']}.MACAddress")) : '';

        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) || $mac === '00:00:00:00:00:00') {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => 'El equipo todavía no informó la MAC de su conexión.', 'reintentar' => self::WAN];
        }

        $api = $this->routerDelCliente((int) $a->user_id);

        if (!$api) {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => "No se pudo entrar al router del cliente: cargá a mano la MAC {$mac} para la IP {$wan['ip']}.", 'reintentar' => self::WAN];
        }

        try {
            $entrada = $api->query((new \RouterOS\Query('/ip/arp/print'))->where('address', $wan['ip']))->read()[0] ?? null;

            if ($entrada && ($entrada['dynamic'] ?? 'false') !== 'true') {
                $api->query((new \RouterOS\Query('/ip/arp/set'))->equal('.id', $entrada['.id'])->equal('mac-address', $mac))->read();
                $como = 'Se actualizó la entrada del ARP';
            } else {
                $red = $this->redEnElRouter($api, $wan['ip']);

                if (!$red) {
                    return ['paso' => $titulo, 'ok' => false, 'detalle' => "No se encontró en el router la red de {$wan['ip']}: cargá a mano la MAC {$mac}."];
                }

                $dni = (string) UserData::where('user_id', $a->user_id)->where('company_id', $this->companyId)->value('dni');
                $api->query((new \RouterOS\Query('/ip/arp/add'))->equal('address', $wan['ip'])->equal('mac-address', $mac)
                    ->equal('interface', $red['interfaz'])->equal('comment', $dni))->read();
                $como = "Se creó la entrada del ARP en {$red['interfaz']}";
            }
        } catch (\Throwable $e) {
            return ['paso' => $titulo, 'ok' => false, 'detalle' => 'No se pudo escribir en el MikroTik: ' . mb_substr($e->getMessage(), 0, 150), 'reintentar' => self::WAN];
        }

        return ['paso' => $titulo, 'ok' => true, 'detalle' => "{$como}: {$wan['ip']} con la MAC {$mac}."];
    }

    /** La conexión al router del cliente, o null si no hay. */
    public function routerDelCliente(int $userId)
    {
        try {
            $routerId = UserData::where('user_id', $userId)->where('company_id', $this->companyId)->value('router_id');
            $token = DB::table('conection_routers')->where('company_id', $this->companyId)
                ->when($routerId, fn ($q) => $q->where('id', $routerId))->orderBy('id')->value('token');

            return $token ? app(\App\Managers\Interfaces\ConectionRouterManagerInterface::class)->conection($token) : null;
        } catch (\Throwable $e) {
            Log::info('[Aprovisionamiento] No se pudo entrar al router del cliente', ['user' => $userId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** La red del router que contiene la IP: gateway, máscara e interfaz. @return array{gateway:string,bits:int,interfaz:string}|null */
    public function redEnElRouter($api, string $ip): ?array
    {
        foreach ($api->query(new \RouterOS\Query('/ip/address/print'))->read() as $fila) {
            if (($fila['disabled'] ?? 'false') === 'true' || !preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', (string) ($fila['address'] ?? ''), $m)) {
                continue;
            }

            $mascara = (-1 << (32 - (int) $m[2])) & 0xFFFFFFFF;

            if ((ip2long($ip) & $mascara) === (ip2long($m[1]) & $mascara)) {
                return ['gateway' => $m[1], 'bits' => (int) $m[2], 'interfaz' => (string) $fila['interface']];
            }
        }

        return null;
    }

    /** Por qué no se pudo crear el último objeto, para contarlo en el paso. */
    private string $motivo = '';

    /**
     * Crea un objeto y devuelve su número.
     *
     * La API de GenieACS no devuelve el número que le dio el equipo: se saca
     * comparando las instancias antes y después (con YINETH_DE_LA_CRUZ el
     * equipo lo creó, se dio por fallido y quedó un grupo vacío). Si quedó en
     * cola se cancela, para que no aparezca vacío después.
     */
    private function crearObjeto(GenieAcs $acs, string $id, string $objeto): ?string
    {
        $this->motivo = '';
        $antes = self::hijos($acs->dispositivo($id) ?? [], $objeto);

        // En el momento o se saca de la cola: si quedara, aparecería vacío después.
        try {
            $r = $acs->tareaInmediata($id, ['name' => 'addObject', 'objectName' => $objeto]);
        } catch (\Throwable $e) {
            $this->motivo = self::SIN_RESPUESTA;

            return null;
        }

        if ($r['instancia'] ?? null) {
            return (string) $r['instancia'];
        }

        if ($r['hecha'] ?? false) {
            $nuevas = array_diff(self::hijos($acs->dispositivo($id) ?? [], $objeto), $antes);

            if ($nuevas) {
                return (string) max(array_map('intval', $nuevas));
            }

            $this->motivo = self::NO_APARECE;

            return null;
        }

        $this->motivo = $r['falla'] ?? self::SIN_RESPUESTA;

        return null;
    }

    private function resultado(GenieAcs $acs, string $id, array $r, string $titulo, string $hecho): array
    {
        if ($r['hecha'] ?? false) {
            return ['paso' => $titulo, 'ok' => true, 'detalle' => "{$hecho}."];
        }

        $falla = ($r['id'] ?? null) ? $acs->fallaDeTarea($id, (string) $r['id']) : null;

        if ($falla) {
            // Una tarea rechazada se reintenta en cada reporte: se saca.
            $acs->borrarTarea((string) $r['id']);

            return ['paso' => $titulo, 'ok' => false, 'detalle' => "El equipo rechazó el cambio: {$falla}"];
        }

        return ['paso' => $titulo, 'ok' => true, 'detalle' => "{$hecho}. Se aplica en cuanto el equipo vuelva a reportar: no se le pudo avisar al momento."];
    }

    /**
     * Cómo llama cada marca a lo mismo.
     *
     * Huawei y C-Data usan el mismo modelo de conexiones —la VLAN y la lista
     * de servicios en la propia conexión— pero con otro prefijo. Con los
     * nombres de Huawei escritos a mano, a un C-Data se le contestaba «en
     * esta marca todavía no se configura por TR-069» y el técnico tenía que
     * ir a cargarle la conexión al equipo, uno por uno.
     *
     * Se decide mirando lo que el equipo publica, no la marca declarada: hay
     * firmwares que dicen una cosa y publican otra.
     *
     * @param array<string,mixed> $d
     * @return array{vlan:string, servicios:string, lanbind:?string}|null
     */
    public static function dialectoWan(array $d): ?array
    {
        foreach (self::hijos($d, self::WAN) as $i) {
            foreach (['WANIPConnection', 'WANPPPConnection'] as $objeto) {
                foreach (self::hijos($d, self::WAN . ".{$i}.{$objeto}") as $j) {
                    $b = self::WAN . ".{$i}.{$objeto}.{$j}";

                    if (self::v($d, "{$b}.X_HW_VLAN") !== null) {
                        return ['vlan' => 'X_HW_VLAN', 'servicios' => 'X_HW_SERVICELIST', 'lanbind' => 'X_HW_LANBIND'];
                    }

                    if (self::v($d, "{$b}.X_CT-COM_VLANIDMark") !== null) {
                        // C-Data no tiene lista de puertos LAN por conexión:
                        // usa un solo campo de texto, así que no se reparten.
                        return ['vlan' => 'X_CT-COM_VLANIDMark', 'servicios' => 'X_CT-COM_SERVICELIST', 'lanbind' => null];
                    }
                }
            }
        }

        return null;
    }

    /** @return list<array{ruta:string, objeto:string, vlan:?int, servicios:string, nombre:string}> */
    public function conexiones(array $d): array
    {
        $lista = [];
        $dialecto = self::dialectoWan($d) ?? ['vlan' => 'X_HW_VLAN', 'servicios' => 'X_HW_SERVICELIST'];

        foreach (self::hijos($d, self::WAN) as $i) {
            foreach (['WANIPConnection', 'WANPPPConnection'] as $objeto) {
                foreach (self::hijos($d, self::WAN . ".{$i}.{$objeto}") as $j) {
                    $b = self::WAN . ".{$i}.{$objeto}.{$j}";
                    $vlan = self::v($d, "{$b}.{$dialecto['vlan']}");

                    $lista[] = [
                        'ruta'      => $b,
                        'objeto'    => $objeto,
                        'vlan'      => $vlan !== null && $vlan !== '' ? (int) $vlan : null,
                        'servicios' => strtoupper((string) self::v($d, "{$b}.{$dialecto['servicios']}")),
                        'nombre'    => (string) (self::v($d, "{$b}.Name") ?: $b),
                        'dispositivo' => (string) $i,
                    ];
                }
            }
        }

        return $lista;
    }

    /** La clave PPPoE se guarda cifrada; si la columna trae texto plano se usa tal cual. */
    public static function descifrar(string $valor): string
    {
        try {
            return (string) decrypt($valor, false);
        } catch (\Throwable) {
            return $valor;
        }
    }

    public static function v(array $d, string $ruta): mixed
    {
        $x = $d;

        foreach (explode('.', $ruta) as $k) {
            if (!is_array($x) || !array_key_exists($k, $x)) {
                return null;
            }
            $x = $x[$k];
        }

        return is_array($x) && array_key_exists('_value', $x) ? $x['_value'] : null;
    }

    /** @return list<string> los números de instancia bajo un objeto */
    public static function hijos(array $d, string $ruta): array
    {
        $x = $d;

        foreach (explode('.', $ruta) as $k) {
            if (!is_array($x) || !isset($x[$k])) {
                return [];
            }
            $x = $x[$k];
        }

        return array_values(array_map('strval', array_filter(array_keys($x), fn ($k) => ctype_digit((string) $k))));
    }
}
