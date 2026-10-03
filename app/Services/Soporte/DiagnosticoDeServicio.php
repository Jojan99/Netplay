<?php

namespace App\Services\Soporte;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\Alerta;
use App\Models\OltOnt;
use App\Services\Acs\EquiposDelAcs;
use App\Services\Olt\EstadoDeUnaOnt;
use App\Services\Plataforma\ComplementoTr069;
use App\Services\Red\ClienteEnElRouter;
use Illuminate\Support\Facades\DB;

/**
 * Revisa de punta a punta el servicio de un cliente y dice, en una frase, qué le pasa.
 *
 * Es el mismo recorrido que hace un operador cuando alguien llama porque «no tiene internet»:
 * ¿está suspendido?, ¿hay una falla en su zona?, ¿el equipo está prendido y con buena señal?,
 * ¿tiene sesión en el router?, ¿responde al ping?, ¿qué dice su equipo por TR-069?
 *
 * No depende de la sesión del panel: lo usa el asistente de soporte desde un proceso aparte.
 */
class DiagnosticoDeServicio
{
    public function __construct(private int $companyId, private int $userId) {}

    /**
     * @return array{conclusion:string, clave:string, para_el_cliente:string, hechos:list<string>, datos:array<string,mixed>}
     */
    public function completo(bool $conPing = true): array
    {
        $cuenta = $this->cuenta();
        $ont    = $this->ont();
        $router = $this->router();
        $acs    = $this->tr069();
        $conectado = $router['tipo'] === 'pppoe' ? $router['sesion'] : $router['arp'];
        $ping   = $conPing && $cuenta['activo'] && $ont['en_linea'] !== false && $conectado ? $this->ping() : null;

        [$clave, $conclusion, $paraElCliente] = $this->concluir($cuenta, $ont, $router, $acs, $ping);

        return [
            'conclusion' => $conclusion, 'clave' => $clave, 'para_el_cliente' => $paraElCliente,
            'hechos' => array_values(array_filter(array_merge([$cuenta['linea']], $ont['lineas'], $router['lineas'], [$acs['linea'], $ping['linea'] ?? null]))),
            'datos' => ['cuenta' => $cuenta, 'ont' => array_diff_key($ont, ['lineas' => 1]), 'router' => array_diff_key($router, ['lineas' => 1]), 'tr069' => array_diff_key($acs, ['linea' => 1]), 'ping' => $ping],
        ];
    }

    // ── Revisiones ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function cuenta(): array
    {
        $u = DB::table('user_data as u')->leftJoin('internet_status as s', 's.id', '=', 'u.status_internet_id')->leftJoin('internet_plans as p', 'p.id', '=', 'u.internet_plans_id')
            ->where('u.company_id', $this->companyId)->where('u.user_id', $this->userId)
            ->first(['u.active', 'u.status_internet_id', 's.name as estado', 'p.plan_name', 'u.connection_type']);

        $vencidas = DB::table('det_facturations as d')->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.user_id', $this->userId)->where('cab.company_id', $this->companyId)
            ->where('d.paid', 0)->whereNull('d.anulada_en')->whereDate('d.date_facturation', '<', now()->toDateString())->count();

        $activo = $u && (int) $u->active === 1 && (int) $u->status_internet_id === 1;

        return [
            'existe' => (bool) $u, 'activo' => $activo, 'vencidas' => $vencidas, 'plan' => $u->plan_name ?? null, 'tipo' => $u->connection_type ?? 'static',
            'linea' => ($activo ? 'Servicio ACTIVO' : 'Servicio SUSPENDIDO') . ($vencidas ? " · {$vencidas} factura(s) vencida(s)" : ' · sin facturas vencidas') . (!empty($u->plan_name) ? " · plan {$u->plan_name}" : ''),
        ];
    }

    /** La ONT del cliente: en línea o no, por qué se cayó, y la señal. */
    public function ont(): array
    {
        $r = ['lineas' => [], 'hay' => false, 'serial' => null, 'error' => false, 'en_linea' => null, 'causa' => null, 'ultima_caida' => null, 'estado' => null, 'potencia' => null, 'voltaje' => null, 'corte' => null, 'olt_id' => null, 'fsp' => null, 'ont_id' => null];

        // olt_onts.user_data_id guarda el id de la ficha, no el de «users».
        $ficha = (int) DB::table('user_data')->where('user_id', $this->userId)->where('company_id', $this->companyId)->value('id');
        $asignada = $ficha ? OltOnt::where('user_data_id', $ficha)->whereHas('olt', fn ($q) => $q->where('company_id', $this->companyId))->with('olt')->first() : null;

        if (!$asignada) {
            $r['lineas'][] = 'EQUIPO: el cliente NO tiene equipo (ONT) asignado en el sistema. No se sabe si está encendido ni cómo le llega la fibra: no afirme nada sobre su equipo.';

            return $r;
        }

        $r['hay'] = true;
        $r['serial'] = \App\Support\Serial::canonico((string) $asignada->serial) ?: null;
        $olt = $asignada->olt;

        if ($r['serial']) {
            $r['lineas'][] = "Equipo del cliente: serial {$r['serial']} (se le puede decir si lo pregunta).";
        }

        $r['olt_id'] = (int) $olt->id; $r['fsp'] = (string) $asignada->fsp; $r['ont_id'] = (int) $asignada->ont_id;

        if ($corte = Alerta::where('company_id', $this->companyId)->abiertas()->where('clave', "pon:{$olt->id}:{$asignada->fsp}")->first()) {
            $r['corte'] = $corte->titulo;
            $r['lineas'][] = "FALLA GENERAL abierta en su sector: {$corte->titulo}.";
        } elseif ($falla = \App\Services\Red\FallasDeSector::delCliente($this->companyId, $this->userId)) {
            // La del módulo de fallas de sector: ya se le avisó (o se le va a avisar) por WhatsApp.
            $r['corte'] = 'Falla de sector desde las ' . \Carbon\Carbon::parse($falla->empezo_en)->format('g:i a');
            $r['lineas'][] = "FALLA GENERAL abierta en su sector: {$falla->caidas} de {$falla->total} equipos caídos en el puerto {$falla->fsp} desde las "
                . \Carbon\Carbon::parse($falla->empezo_en)->format('g:i a') . '. Se le avisa por WhatsApp cuando vuelva.';
        }

        try {
            $vivo = EstadoDeUnaOnt::de($olt, (string) $asignada->fsp, (int) $asignada->ont_id, true);
        } catch (\Throwable $e) {
            $vivo = ['error' => $e->getMessage()];
        }

        if (!empty($vivo['error'])) {
            $r['error'] = true;
            $r['lineas'][] = 'ONT: no se pudo consultar la OLT en este momento.';

            return $r;
        }

        $r['en_linea'] = ($vivo['status'] ?? null) === 'online';
        $r['causa'] = $vivo['causa_caida'] ?? null;
        $r['ultima_caida'] = $vivo['ultima_caida'] ?? null;
        $r['estado'] = $vivo['estado'] ?? null;
        $r['potencia'] = $vivo['potencia'] ?? null;
        $r['voltaje'] = $vivo['voltaje'] ?? null;

        if ($r['en_linea']) {
            $senal = ['buena' => 'buena', 'regular' => 'regular', 'baja' => 'BAJA', 'critica' => 'CRÍTICA', 'saturada' => 'SATURADA'][$r['estado']] ?? 'sin medición';
            $r['lineas'][] = "ONT EN LÍNEA · señal de fibra {$senal}" . ($r['potencia'] !== null ? " ({$r['potencia']} dBm)" : '')
                . ($r['voltaje'] !== null && ($r['voltaje'] < 3.1 || $r['voltaje'] > 3.6) ? " · voltaje fuera de rango ({$r['voltaje']} V)" : '');

            if ($r['ultima_caida'] && now()->diffInHours($r['ultima_caida']) < 24) {
                $r['lineas'][] = 'Su última caída fue ' . \Carbon\Carbon::parse($r['ultima_caida'])->diffForHumans() . ': ' . ($vivo['causa_texto'] ?? 'causa no informada') . '.';
            }
        } else {
            $porque = ['energia' => 'se quedó SIN ENERGÍA (el equipo avisó antes de apagarse)', 'fibra' => 'PERDIÓ LA SEÑAL DE FIBRA', 'reinicio' => 'se está reiniciando', 'desactivada' => 'está desactivada en la OLT'][$r['causa']] ?? 'no informó la causa';
            $r['lineas'][] = "ONT FUERA DE LÍNEA · {$porque}" . ($r['ultima_caida'] ? ' · desde ' . \Carbon\Carbon::parse($r['ultima_caida'])->diffForHumans() : '') . '.';
        }

        return $r;
    }

    /** Su sesión PPPoE o su ARP en el MikroTik. */
    public function router(): array
    {
        $r = ['lineas' => [], 'leido' => false, 'tipo' => null, 'sesion' => false, 'arp' => false, 'moroso' => false];

        try {
            $hay = $this->enElRouter()->queHay($this->userId);
        } catch (\Throwable $e) {
            $hay = ['ok' => false];
        }

        if (!($hay['ok'] ?? false)) {
            $r['lineas'][] = 'Router principal: no se pudo consultar en este momento.';

            return $r;
        }

        $r['leido'] = true;
        $r['tipo'] = $hay['tipo'];
        $r['moroso'] = collect($hay['listas'])->contains(fn ($l) => strtolower((string) $l['lista']) === 'morosos');

        if ($hay['tipo'] === 'pppoe') {
            $conSesion = collect($hay['pppoe'])->first(fn ($p) => !empty($p['sesion']));
            $r['sesion'] = (bool) $conSesion;
            $r['lineas'][] = match (true) {
                (bool) $conSesion => 'PPPoE CONECTADO (sesión abierta hace ' . ($conSesion['sesion']['desde'] ?? '?') . ').',
                empty($hay['pppoe']) => 'PPPoE: no existe la credencial del cliente en el router.',
                collect($hay['pppoe'])->every(fn ($p) => !$p['habilitado']) => 'PPPoE: la credencial está DESHABILITADA (cortada).',
                default => 'PPPoE: el equipo NO tiene sesión abierta (no logra autenticarse).',
            };
        } else {
            $arp = collect($hay['arp'])->first(fn ($a) => $a['habilitado']);
            $r['arp'] = (bool) $arp;
            $r['lineas'][] = $arp ? 'IP fija habilitada en el router.' : 'IP fija: no tiene entrada activa en el router.';
        }

        if ($r['moroso']) {
            $r['lineas'][] = 'Está en la lista de corte por mora del router.';
        }

        return $r;
    }

    /** Lo que dice su equipo por TR-069, sin credenciales. */
    public function tr069(): array
    {
        $r = ['linea' => null, 'permitido' => ComplementoTr069::permitido($this->companyId), 'reporta' => false, 'id' => null, 'redes' => [], 'dispositivos' => 0, 'encendido_hace' => null];

        if (!$r['permitido']) {
            $r['linea'] = 'Gestión remota del equipo: no disponible para esta empresa.';

            return $r;
        }

        try {
            $equipo = (new EquiposDelAcs($this->companyId))->deCliente($this->userId);
        } catch (\Throwable $e) {
            $r['linea'] = 'Gestión remota: no se pudo consultar.';

            return $r;
        }

        if (!$equipo) {
            $r['linea'] = 'Gestión remota: el equipo todavía NO reporta (no se le pueden hacer cambios a distancia hasta habilitarla).';

            return $r;
        }

        $r['reporta'] = (bool) ($equipo['reportando'] ?? false);
        $r['id'] = $equipo['id'];
        $r['encendido_hace'] = $equipo['encendido_hace'] ?? null;
        $r['dispositivos'] = count(array_filter($equipo['equipos'] ?? [], fn ($e) => $e['activo'] ?? false));
        // Sólo lo que hace falta para hablar con el cliente: nombre de la red y banda. Ni claves ni rutas.
        $r['redes'] = array_values(array_map(fn ($w) => ['indice' => (int) $w['indice'], 'nombre' => $w['ssid'], 'banda' => $w['banda'] ?? null, 'activa' => ($w['activo'] ?? null) !== false, 'se_puede_cambiar_clave' => !empty($w['ruta_clave'])],
            array_filter($equipo['wifi'] ?? [], fn ($w) => !empty($w['ssid']))));

        $nombres = implode(', ', array_map(fn ($w) => '«' . $w['nombre'] . '»', array_filter($r['redes'], fn ($w) => $w['activa'])));
        $r['linea'] = 'Gestión remota: el equipo ' . ($r['reporta'] ? 'REPORTA ahora' : 'NO está reportando ahora (lo que sigue es de su última lectura, puede estar desactualizado)') . " · {$r['dispositivos']} aparato(s) conectados"
            . ($nombres ? " · redes WiFi: {$nombres}" : '')
            . ($r['encendido_hace'] !== null && $r['encendido_hace'] < 900 ? ' · se reinició hace menos de 15 minutos' : '') . '.';

        return $r;
    }

    /** Ping desde el router principal al equipo del cliente. */
    public function ping(int $cuantos = 5): array
    {
        try {
            $p = $this->enElRouter()->ping($this->userId, $cuantos);
        } catch (\Throwable $e) {
            $p = ['ok' => false, 'error' => $e->getMessage()];
        }

        if (!($p['ok'] ?? false)) {
            return ['ok' => false, 'linea' => 'Ping: no se pudo hacer (' . mb_substr((string) ($p['error'] ?? 'sin respuesta'), 0, 120) . ').'];
        }

        $p['linea'] = $p['recibidos'] === 0
            ? "Ping: NO RESPONDE ({$p['enviados']} enviados, ninguno volvió)."
            : "Ping: respondió {$p['recibidos']} de {$p['enviados']}" . ($p['perdida'] > 0 ? " (PIERDE {$p['perdida']} %)" : ' (sin pérdida)') . " · promedio {$p['promedio_ms']} ms" . ($p['promedio_ms'] > 150 ? ' (LENTO)' : '') . '.';
        // La IP no le sirve al cliente y no hace falta que viaje al modelo.
        unset($p['ip']);

        return $p;
    }

    private function enElRouter(): ClienteEnElRouter
    {
        return new ClienteEnElRouter(app(ConectionRouterManagerInterface::class), $this->companyId);
    }

    // ── Conclusión ───────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:string}  [clave, para el operador, para decirle al cliente] */
    private function concluir(array $cuenta, array $ont, array $router, array $acs, ?array $ping): array
    {
        return match (true) {
            !$cuenta['existe'] => ['sin_cliente', 'No se encontró el cliente.', 'No encuentro su servicio con esos datos.'],
            !$cuenta['activo'] || $router['moroso'] => ['suspendido', 'Servicio suspendido' . ($cuenta['vencidas'] ? " por mora ({$cuenta['vencidas']} factura/s vencida/s)" : '') . ': se resuelve con el pago, no con un técnico.',
                'Su servicio está suspendido' . ($cuenta['vencidas'] ? ' por facturas pendientes' : '') . '. Al registrar el pago se reactiva.'],
            $ont['corte'] !== null => ['falla_general', 'Falla general en su sector: ya está reportada.', 'Hay una falla en su sector que ya estamos atendiendo. No es su equipo: en cuanto se resuelva vuelve el servicio.'],
            // Sin ONT asignada no se puede decir nada del equipo ni de la fibra.
            !$ont['hay'] && $router['leido'] && ($router['tipo'] === 'pppoe' ? !$router['sesion'] : !$router['arp']) => ['sin_equipo_sin_conexion',
                'El cliente no tiene equipo asignado en el sistema y tampoco está conectado en el router: no se puede revisar a distancia.',
                'En este momento su servicio no aparece conectado, y no tengo su equipo registrado para revisarlo a distancia.'],
            !$ont['hay'] && $ping !== null && ($ping['ok'] ?? false) && $ping['recibidos'] === 0 => ['sin_equipo_sin_conexion',
                'El cliente no tiene equipo asignado en el sistema y su dirección NO responde al ping: el equipo está apagado, desconectado o sin señal.',
                'Su equipo no está respondiendo desde nuestra red: puede estar apagado, desconectado o sin señal. Revise que esté encendido y con sus cables bien puestos.'],
            !$ont['hay'] => ['sin_equipo', 'El cliente no tiene equipo asignado en el sistema: no se puede revisar la fibra ni el equipo.'
                . ($ping !== null && ($ping['ok'] ?? false) && $ping['recibidos'] > 0 ? ' La conexión en el router está arriba y responde al ping.' : ''),
                $ping !== null && ($ping['ok'] ?? false) && $ping['recibidos'] > 0
                    ? 'Su conexión aparece activa y respondiendo, pero no tengo su equipo registrado para revisarlo a fondo.'
                    : 'No tengo su equipo registrado en el sistema para revisarlo a distancia.'],
            $ont['en_linea'] === false && $ont['causa'] === 'energia' => ['sin_energia', 'El equipo se quedó sin energía: que el cliente revise enchufe y luz.', 'Su equipo está apagado: se quedó sin energía. Revise que esté bien enchufado y que el tomacorriente tenga corriente.'],
            $ont['en_linea'] === false && $ont['causa'] === 'fibra' => ['sin_fibra', 'Perdió la señal de fibra: requiere técnico si el cable de la casa está bien.', 'Su equipo no está recibiendo la señal de la fibra. Revise que el cable delgado (amarillo o blanco) esté bien conectado y sin doblarse; si la luz roja LOS sigue encendida, hay que enviar un técnico.'],
            $ont['en_linea'] === false => ['equipo_apagado', 'La ONT está fuera de línea sin causa clara.', 'No estamos viendo su equipo en línea. ¿Tiene luces encendidas? Si está apagado, revise el enchufe.'],
            in_array($ont['estado'], ['critica', 'saturada', 'baja'], true) => ['senal_mala', 'Señal óptica ' . $ont['estado'] . ': requiere técnico (empalme, conector o roseta).', 'Su equipo está conectado pero la señal de la fibra llega débil, y eso causa cortes. Hay que enviar un técnico a revisarla.'],
            $router['leido'] && $router['tipo'] === 'pppoe' && !$router['sesion'] => ['sin_sesion',
                $ont['en_linea'] === true ? 'ONT en línea pero sin sesión PPPoE.' : 'Sin sesión PPPoE, y la OLT no se pudo consultar.',
                ($ont['en_linea'] === true ? 'Su equipo está encendido y la fibra llega bien, pero no logra conectarse.' : 'Su equipo no está logrando conectarse.') . ' Apáguelo, espere un minuto y enciéndalo; si sigue igual lo revisa un técnico.'],
            $router['leido'] && $router['tipo'] === 'static' && !$router['arp'] => ['sin_ip', 'Sin entrada activa en el router.', 'Su equipo está encendido pero su conexión no está habilitada en nuestra red. Lo revisa un asesor.'],
            $ping !== null && ($ping['ok'] ?? false) && $ping['recibidos'] === 0 => ['no_responde', 'La red llega pero el equipo no responde al ping.', 'Su equipo aparece conectado pero no está respondiendo. Apáguelo y enciéndalo; si no mejora, lo revisa un técnico.'],
            $ping !== null && ($ping['ok'] ?? false) && ($ping['perdida'] > 0 || ($ping['promedio_ms'] ?? 0) > 150) => ['inestable', 'Conexión inestable hasta el equipo (pérdida o demora en el ping).', 'Su conexión está llegando inestable. Vamos a revisarla.'],
            $ont['error'] || !$router['leido'] => ['incompleto', 'No se pudo revisar todo.', 'No pude revisar todo su servicio en este momento.'],
            default => ['red_bien', 'La red llega bien hasta el equipo: el problema es del WiFi o de un aparato del cliente.', 'Su servicio está llegando bien hasta su equipo. Si le falla en un aparato, suele ser la señal del WiFi: acérquese al equipo, o apáguelo y enciéndalo.'],
        };
    }
}
