<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\Aprovisionamiento;
use App\Models\GestionRemota;
use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\Models\UserData;
use App\Services\Acs\EquiposDelAcs;
use App\Services\Acs\GenieAcs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Cambio de conexión de un cliente con ONT en el TR-069 (IP fija ↔ PPPoE, o
 * una IP fija nueva), sin dejarlo nunca a medias.
 *
 * El 18-09 DOUGLAS_MENDEZ quedó sin internet: la plataforma le borró primero el
 * ARP del MikroTik y le creó el PPPoE, y después mandó a la ONT borrar su única
 * conexión (que llevaba también el TR-069). El equipo no atendía el aviso del
 * ACS —la dirección guardada era de una gestión que ya no existía, y por su
 * IP de internet el aviso no llega—, así que el borrado quedó en cola para su
 * próximo reporte (cada 8 horas) y el router ya estaba cambiado.
 *
 * Ahora el orden es otro:
 *   1. En el router se agrega lo nuevo sin sacar lo viejo (el secret PPPoE, o
 *      la entrada del ARP de la IP nueva): el cliente sigue navegando.
 *   2. Se cambia la ONT, todo en el momento (si el ACS no puede hacerlo ya, se
 *      saca de la cola: nada destructivo queda esperando).
 *   3. Se confirma que lo nuevo anda (sesión PPPoE en el MikroTik, o la ONT con
 *      la IP nueva conectada y su MAC en el ARP).
 *   4. Recién ahí se saca lo viejo del router y la ficha pasa al tipo nuevo.
 *   Si 2 o 3 fallan o vencen, la ONT vuelve a su conexión de antes, lo nuevo
 *   del router se deshabilita/borra y la ficha nunca cambió.
 *
 * Si el TR-069 del equipo va por su propia conexión de internet (la que hay que
 * reemplazar), el cambio se hace "en paralelo" y sin tocar la OLT:
 *   a. se crea la conexión nueva al lado de la vieja, sólo con INTERNET (la
 *      vieja sigue con el TR-069 y el tráfico);
 *   b. se confirma que la nueva levanta;
 *   c. se le pasa el TR-069 (y los puertos) a la nueva y se espera que el
 *      equipo reporte por ahí;
 *   d. recién entonces se borra la vieja y se limpia el router.
 * Hasta c, si algo falla se borra la nueva y queda como estaba. Ninguna tarea
 * que quede en cola es peligrosa: hasta c sólo agregan, y el borrado de la
 * vieja se pide cuando el TR-069 ya va por la nueva. Si el aviso del ACS llega
 * por su IP de internet (DOUGLAS_MENDEZ: sí, 1,8 s) es todo al momento; si no,
 * se lo pone a reportar cada minuto (etiqueta cambio_rapido) mientras dura.
 *
 * (Antes se le daba una gestión temporal desde la OLT: no servía si su
 * conexión estaba en el lugar 2, que es donde la OLT la crea. Los cambios en
 * curso con ese modo terminan igual.)
 *
 * Mientras dura, la ficha sigue con el tipo de antes (es el que anda) y el
 * cambio en curso se ve como un aprovisionamiento "aplicando" con origen
 * cambio_de_conexion: la ficha lo muestra como "Cambiando a …".
 */
class CambioDeConexion
{
    /** Hasta que el equipo se reporte por la gestión temporal. */
    public const ESPERA_GESTION_MIN = 10;
    /** Para cargar la conexión nueva en la ONT (si no contestó al momento). */
    public const ESPERA_WAN_MIN = 3;
    /** Hasta que la conexión nueva levante. */
    public const ESPERA_CONFIRMAR_MIN = 5;
    /** Para volver a dejar la ONT como estaba. */
    public const ESPERA_RESTAURAR_MIN = 6;
    /** Para pasar el TR-069 a internet y quitar la gestión temporal. */
    public const ESPERA_TR069_MIN = 20;
    /** Para terminar de escribir en el router. */
    public const ESPERA_ROUTER_MIN = 15;

    private const WAN = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';

    /** Fases antes de confirmar: se pueden cancelar y, si fallan, se deshacen. */
    private const ANTES_DE_CONFIRMAR = ['gestion', 'wan', 'confirmar', 'p_preparar', 'p_grupo', 'p_conexion', 'p_datos', 'p_confirmar'];

    /** En paralelo: hasta que el equipo haga cada paso (si reporta cada minuto, sobra). */
    public const ESPERA_PASO_MIN = 8;
    /** En paralelo: hasta el primer reporte, si el aviso no llega (reporta cada 15 min). */
    public const ESPERA_PRIMER_REPORTE_MIN = 20;
    /** En paralelo: hasta que reporte por la conexión nueva. */
    public const ESPERA_REPORTE_NUEVA_MIN = 15;

    /** Etiqueta del ACS que lo pone a reportar cada minuto (preset intervalo-huawei). */
    public const ETIQUETA_RAPIDO = 'cambio_rapido';

    private AprovisionamientoDeOnt $prov;

    public function __construct(private int $companyId)
    {
        $this->prov = new AprovisionamientoDeOnt($companyId);
    }

    // ── ¿Se puede y cómo? ─────────────────────────────────────────────────

    /** El cambio que el cliente tiene en curso, si hay uno. */
    public static function enCurso(int $companyId, int $userId): ?Aprovisionamiento
    {
        return Aprovisionamiento::where('company_id', $companyId)->where('user_id', $userId)
            ->whereIn('estado', ['esperando', 'aplicando'])
            ->latest('id')->get()
            ->first(fn (Aprovisionamiento $a) => !empty($a->datos['cambio']));
    }

    /**
     * Cómo se le puede cambiar la conexión a la ONT del cliente.
     *
     * modo:
     *   sin_ont           la plataforma no maneja su equipo (sin ONT, o el
     *                     aprovisionamiento apagado): sólo el router, como antes
     *   instantaneo       el ACS le habla al momento (gestión vigente)
     *   paralela          el TR-069 va por su internet: la nueva se arma al lado de la vieja
     *   gestion_temporal  (ya no se planea) gestión temporal en la OLT Huawei
     *   no_se_puede       hay que hacerlo en el equipo (motivo y qué hacer)
     *   no_responde       tiene gestión pero ahora no contesta
     *
     * @return array<string,mixed>
     */
    public function planear(int $userId): array
    {
        $g = GestionRemota::where('company_id', $this->companyId)->first();

        if (!$g?->aprovisionar || !$g->aprov_wan) {
            return ['modo' => 'sin_ont', 'motivo' => 'El aprovisionamiento está apagado.'];
        }

        $ont = OltOnt::whereHas('olt', fn ($q) => $q->where('company_id', $this->companyId))
            ->where('user_data_id', $userId)->orderByDesc('updated_at')->first();

        if (!$ont || !$ont->serial) {
            return ['modo' => 'sin_ont', 'motivo' => 'El cliente no tiene ONT.'];
        }

        $aMano = 'Cargue el cambio en el equipo (o que lo haga un técnico) y después aplicalo aquí con «sólo el router».';
        $no = fn (string $motivo, ?string $queHacer = null) => ['modo' => 'no_se_puede', 'motivo' => $motivo, 'que_hacer' => $queHacer ?? $aMano, 'ont' => $ont];

        $acs = GenieAcs::deEmpresa($this->companyId);

        try {
            $acsId = self::buscarEnElAcs($acs, (string) $ont->serial);
            $d = $acsId ? $acs->dispositivo($acsId) : null;
        } catch (\Throwable $e) {
            return $no('No se pudo consultar el servidor TR-069 (' . mb_substr($e->getMessage(), 0, 80) . '): no se tocó nada.', 'Pruebe de nuevo en unos minutos.');
        }

        if (!$acsId || !$d) {
            return $no('El equipo no está en el servidor TR-069: la plataforma no puede cambiarle la conexión. No se tocó el router.');
        }

        $huawei = (($d['_deviceId']['_OUI'] ?? '') === '00259E' || str_contains(strtoupper((string) ($d['_deviceId']['_Manufacturer'] ?? '')), 'HUAWEI'))
            && isset($d['InternetGatewayDevice']);

        if (!$huawei) {
            return $no('En esta marca de equipo la conexión a internet todavía no se configura por TR-069. No se tocó el router.');
        }

        $vlan = $this->vlanDeServicio($ont, (int) ($g->vlan ?: 0));

        if (!$vlan) {
            return $no('No se sabe por qué VLAN sale la ONT (no tiene service-port de servicio anotado ni se pudo leer de la OLT). No se tocó el router.');
        }

        // Si la conexión de internet lleva también el TR-069, la nueva tiene
        // que quedar igual al terminar (y sin la gestión de la OLT).
        $tr069EnInternet = collect($this->prov->conexiones($d))
            ->contains(fn ($c) => $c['vlan'] === $vlan && str_contains($c['servicios'], 'TR069'));
        $base = ['ont' => $ont, 'acs_id' => $acsId, 'vlan' => $vlan, 'dispositivo' => $d, 'tr069_en_internet' => $tr069EnInternet];
        $alcance = $this->alcance($d, $g);

        // Sólo es "al momento" si el aviso llega de verdad: la dirección que
        // el ACS tiene guardada puede ser de una gestión que ya no existe.
        if ($alcance['por_gestion']) {
            $prueba = ['name' => 'refreshObject', 'objectName' => self::WAN];

            try {
                $r = $acs->tarea($acsId, $prueba, 30);
            } catch (\Throwable) {
                $r = ['hecha' => false, 'id' => null];
            }

            if ($r['hecha'] ?? false) {
                return ['modo' => 'instantaneo', 'motivo' => $alcance['motivo'], 'dispositivo' => $acs->dispositivo($acsId) ?? $d] + $base;
            }

            // Una lectura: inofensiva, pero no se deja colgada. Una sola vuelta
            // (sin esperas): esto corre mientras el operador espera la respuesta.
            try {
                $acs->sacarDeLaCola($acsId, $prueba, $r['id'] ?? null, now()->subMinute(), 1);
            } catch (\Throwable) {
            }

            $alcance['motivo'] = "El ACS tiene guardada la dirección {$alcance['host']} (gestión), pero el aviso no llegó: es vieja o el equipo no contesta.";
        }

        // ¿El TR-069 va por su conexión de internet? Entonces es justo la que
        // se cambia, y la única forma de hablarle al momento es la gestión temporal.
        $vlanGestion = (int) ($g->vlan ?: 0);
        $porInternet = collect($this->prov->conexiones($d))
            ->contains(fn ($c) => $c['vlan'] !== $vlanGestion && str_contains($c['servicios'], 'TR069'));

        if (!$porInternet) {
            return ['modo' => 'no_responde', 'motivo' => $alcance['motivo'] . ' Último reporte: ' . self::haceCuanto($d['_lastInform'] ?? null) . '. No se tocó nada.',
                'que_hacer' => 'Revise que el equipo esté encendido y pruebe de nuevo en unos minutos, o cargue el cambio en el equipo y aplicalo con «sólo el router».'] + $base;
        }

        // La conexión nueva se arma al lado de la que tiene (que sigue con el
        // TR-069), se confirma, se le pasa el TR-069 y recién ahí se borra la
        // vieja: el equipo nunca queda sin por dónde hablarle. No se toca la
        // OLT. Si el aviso llega por su IP de internet es al momento; si no,
        // cada paso se hace en su próximo reporte (se lo pone a reportar cada
        // minuto mientras dura).
        $alMomento = $this->llegaAlMomento($acs, $acsId);

        return ['modo' => 'paralela', 'al_momento' => $alMomento,
            'motivo' => $alMomento ? 'El TR-069 va por su conexión de internet y el aviso llega al momento.'
                : 'El TR-069 va por su conexión de internet y el aviso no llega: cada paso se hace cuando el equipo reporta.'] + $base;
    }

    /**
     * ¿La dirección que el ACS tiene para avisarle al equipo sirve? Sólo si es
     * de la red de gestión y corresponde a una conexión de gestión que el
     * equipo informó. Por su IP de internet a veces llega y a veces no (el
     * 18-09 DOUGLAS_MENDEZ contestó en 1,8 s por 192.168.107.62:8085): eso lo
     * resuelve el modo "paralela", que funciona igual en los dos casos.
     *
     * @return array{por_gestion:bool, host:?string, motivo:string}
     */
    public function alcance(array $d, ?GestionRemota $g): array
    {
        $url = (string) (AprovisionamientoDeOnt::v($d, 'InternetGatewayDevice.ManagementServer.ConnectionRequestURL')
            ?? AprovisionamientoDeOnt::v($d, 'Device.ManagementServer.ConnectionRequestURL') ?? '');
        $host = parse_url($url, PHP_URL_HOST) ?: null;

        if (!$host || !filter_var($host, FILTER_VALIDATE_IP)) {
            return ['por_gestion' => false, 'host' => $host, 'motivo' => 'El ACS no tiene una dirección para avisarle al equipo.'];
        }

        if (!$g?->red || !AprovisionamientoDeOnt::enRed($host, (string) $g->red)) {
            return ['por_gestion' => false, 'host' => $host,
                'motivo' => "El equipo se reporta por su conexión de internet ({$host}): el ACS no le puede avisar al momento, los cambios le llegarían recién en su próximo reporte."];
        }

        $suya = collect($this->prov->conexiones($d))->first(fn ($c) => $c['vlan'] === (int) $g->vlan
            && (string) AprovisionamientoDeOnt::v($d, "{$c['ruta']}.ExternalIPAddress") === $host);

        if (!$suya) {
            return ['por_gestion' => false, 'host' => $host,
                'motivo' => "La dirección que tiene el ACS ({$host}) no es de ninguna conexión de gestión del equipo: está vieja."];
        }

        return ['por_gestion' => true, 'host' => $host, 'motivo' => "El ACS le avisa por la gestión ({$host})."];
    }

    // ── Arranque (desde la ficha) ─────────────────────────────────────────

    /**
     * Prepara el router (agrega lo nuevo, no saca nada) y deja programado el
     * cambio en la ONT. La ficha del cliente no cambia todavía.
     *
     * @param array<string,mixed> $plan  lo que devolvió planear()
     * @param array<string,mixed> $de    cómo se conecta hoy
     * @param array<string,mixed> $a     cómo se va a conectar
     * @param array<string,mixed> $wanNueva / $wanAnterior  la conexión de la ONT (formato de AprovisionamientoDeOnt)
     * @return array{ok:bool, mensaje:string, id:?int}
     */
    public function iniciar(int $userId, int $routerId, array $plan, array $de, array $a, array $wanNueva, array $wanAnterior): array
    {
        $cliente = UserData::where('user_id', $userId)->where('company_id', $this->companyId)->first();
        $ont = $plan['ont'];

        try {
            $previo = $this->prepararRouter($routerId, $cliente, $de, $a, $wanAnterior, $plan['dispositivo'] ?? []);
        } catch (\Throwable $e) {
            Log::warning('[Cambio de conexión] No se pudo preparar el router', ['user' => $userId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'mensaje' => 'No se pudo preparar el router: ' . $e->getMessage() . '. No se cambió nada.', 'id' => null];
        }

        if (!$previo['ok']) {
            return ['ok' => false, 'mensaje' => $previo['mensaje'], 'id' => null];
        }

        Aprovisionamiento::where('company_id', $this->companyId)->where('serial', $ont->serial)
            ->whereIn('estado', ['esperando', 'aplicando', 'no_aplica'])
            ->update(['estado' => 'reemplazado', 'detalle' => 'Se pidió un cambio de conexión.']);

        $temporal = $plan['modo'] === 'gestion_temporal';
        $paralela = $plan['modo'] === 'paralela';
        $resumen = self::texto($de) . ' → ' . self::texto($a);

        $nuevo = Aprovisionamiento::create([
            'company_id' => $this->companyId,
            'olt_id'     => $ont->olt_id,
            'fsp'        => $ont->fsp,
            'ont_id'     => $ont->ont_id,
            'serial'     => $ont->serial,
            'user_id'    => $userId,
            'acs_id'     => $plan['acs_id'],
            'estado'     => 'aplicando',
            'datos'      => [
                'cliente' => trim(($cliente->names ?? '') . ' ' . ($cliente->lastname ?? '')),
                'wan'     => $wanNueva,
                'avisos'  => [],
                'origen'  => 'cambio_de_conexion',
                'cambio'  => [
                    'modo'         => $plan['modo'],
                    'fase'         => $paralela ? 'p_preparar' : ($temporal ? 'gestion' : 'wan'),
                    'al_momento'   => (bool) ($plan['al_momento'] ?? false),
                    'fase_desde'   => now()->toIso8601String(),
                    'router_id'    => $routerId,
                    'de'           => $de,
                    'a'            => $a,
                    'wan_anterior' => $wanAnterior,
                    'router_previo' => $previo['previo'],
                    'tr069_en_internet' => (bool) ($plan['tr069_en_internet'] ?? false),
                ],
            ],
            'pasos' => [
                ['paso' => 'Cambio de conexión pedido desde la ficha', 'ok' => true, 'detalle' => $resumen],
                ['paso' => 'Router preparado', 'ok' => true, 'detalle' => $previo['mensaje']],
            ],
            'detalle' => $paralela ? 'Preparando la conexión nueva al lado de la actual…'
                : ($temporal ? 'Dándole al equipo una gestión temporal desde la OLT…' : 'Cambiando la conexión en la ONT…'),
        ]);

        return ['ok' => true, 'id' => $nuevo->id, 'mensaje' => 'Cambio en curso: ' . $resumen . '. ' . $previo['mensaje']
            . ($paralela
                ? ' La conexión nueva se arma al lado de la actual y la vieja se borra recién cuando la nueva anda y el equipo reporta por ella'
                    . (($plan['al_momento'] ?? false) ? ' (unos minutos).' : ': el equipo no atiende el aviso, así que cada paso se hace cuando reporta (hasta 15 min el primero, después cada minuto).')
                : ($temporal
                    ? ' Como el TR-069 del equipo va por su conexión de internet, primero se le da una gestión temporal desde la OLT (unos minutos); después se cambia la ONT al momento y se le quita.'
                    : ' La ONT se cambia al momento.'))
            . ' El cliente sigue con ' . self::texto($de) . ' hasta que lo nuevo se confirme; si no se confirma, queda como estaba.'];
    }

    // ── La tarea de cada minuto ───────────────────────────────────────────

    public function avanzar(Aprovisionamiento $a): void
    {
        $candado = Cache::lock("cambio-de-conexion:{$a->id}", 600);

        if (!$candado->get()) {
            return;
        }

        $acs = GenieAcs::deEmpresa($this->companyId);

        try {
            $a->refresh();

            if (!in_array($a->estado, ['esperando', 'aplicando'], true)) {
                return;
            }

            // Hasta 6 fases por vuelta: las que no esperan a nadie siguen de largo.
            for ($i = 0; $i < 6; $i++) {
                $antes = $this->c($a)['fase'];

                try {
                    $this->fase($a, $acs);
                } catch (\Throwable $e) {
                    $this->alFallarUnaVuelta($a, $e);

                    break;
                }

                if (!in_array($a->estado, ['esperando', 'aplicando'], true) || $this->c($a)['fase'] === $antes) {
                    break;
                }
            }
        } finally {
            $this->prov->anotarTareas($a, $acs);
            $candado->release();
        }
    }

    private function fase(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);

        // Cancelado por el operador antes de confirmar: se deshace.
        if (!empty($c['cancelar']) && in_array($c['fase'], self::ANTES_DE_CONFIRMAR, true)) {
            $this->fallar($a, 'Cancelado por el usuario a las ' . now()->format('H:i') . '.');

            return;
        }

        match ($c['fase']) {
            'gestion'         => $this->faseGestion($a, $acs),
            'wan'             => $this->faseWan($a, $acs),
            'confirmar'       => $this->faseConfirmar($a, $acs),
            'router'          => $this->faseRouter($a),
            'tr069'           => $this->faseTr069($a, $acs),
            'deshacer_ont'    => $this->faseDeshacerOnt($a, $acs),
            'deshacer_tr069'  => $this->faseDeshacerTr069($a, $acs),
            'deshacer_router' => $this->faseDeshacerRouter($a),
            'p_preparar'      => $this->fasePreparar($a, $acs),
            'p_grupo'         => $this->faseGrupo($a, $acs),
            'p_conexion'      => $this->faseConexion($a, $acs),
            'p_datos'         => $this->faseDatos($a, $acs),
            'p_confirmar'     => $this->faseConfirmarNueva($a, $acs),
            'p_tr069'         => $this->fasePasarTr069($a, $acs),
            'p_reporte'       => $this->faseReportaPorLaNueva($a, $acs),
            'p_vieja'         => $this->faseBorrarVieja($a, $acs),
            'p_fin'           => $this->faseFinParalela($a, $acs),
            'p_deshacer'      => $this->faseDeshacerParalela($a, $acs),
            default           => null,
        };
    }

    /** Una vuelta que tiró una excepción (ACS o router caídos): se reintenta hasta vencer la fase. */
    private function alFallarUnaVuelta(Aprovisionamiento $a, \Throwable $e): void
    {
        Log::warning('[Cambio de conexión] Falló una vuelta', ['id' => $a->id, 'fase' => $this->c($a)['fase'], 'error' => $e->getMessage()]);

        $c = $this->c($a);
        $texto = mb_substr($e->getMessage(), 0, 120);

        if (in_array($c['fase'], self::ANTES_DE_CONFIRMAR, true) && $this->vencida($c, self::ESPERA_CONFIRMAR_MIN + self::ESPERA_GESTION_MIN)) {
            $this->fallar($a, "No se pudo seguir ({$texto}).");

            return;
        }

        if ((str_starts_with($c['fase'], 'deshacer') || $c['fase'] === 'p_deshacer') && $this->vencida($c, 30)) {
            $this->terminar($a, 'error', 'No se pudo terminar de dejarlo como estaba: ' . $texto . '. ' . $this->queRevisar($a));

            return;
        }

        // Ya confirmado (router, TR-069): el cliente anda con lo nuevo.
        if (in_array($c['fase'], ['router', 'tr069', 'p_tr069', 'p_reporte', 'p_vieja', 'p_fin'], true) && $this->vencida($c, 30)) {
            $this->terminar($a, 'con_errores', 'El cliente anda con ' . self::texto($c['a']) . ', pero no se pudo terminar (' . $texto . '): revise que en el router no quede ' . self::texto($c['de']) . '.');

            return;
        }

        $a->detalle = mb_substr('Reintentando: ' . $texto, 0, 250);
        $a->save();
    }

    // ── Hacia adelante ────────────────────────────────────────────────────

    private function faseGestion(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $g = GestionRemota::where('company_id', $this->companyId)->first();

        if (empty($c['gestion']['pedida_en'])) {
            $r = (new GestionRemotaDeOnt($this->companyId, app(ConectionRouterManagerInterface::class)))->darGestionTemporal((int) $a->olt_id, (string) $a->fsp, (int) $a->ont_id);

            if (!$r['ok']) {
                // Si la OLT alcanzó a crear algo, se quita.
                if ($r['creo_algo'] ?? false) {
                    $this->quitarGestion($a);
                }

                $this->paso($a, 'Gestión temporal desde la OLT', false, $r['detalle']);
                $this->fallar($a, 'No se le pudo dar la gestión temporal desde la OLT: ' . $r['detalle']);

                return;
            }

            $this->actualizar($a, ['gestion' => ['pedida_en' => now()->toIso8601String(), 'sp' => $r['sp'] ?? null]]);
            $this->paso($a, 'Gestión temporal desde la OLT', true, $r['detalle'] . '. Esperando que el equipo se reporte por ahí…');
            $this->detalle($a, 'Esperando que el equipo se reporte por la gestión temporal…');
        }

        // Se reporta por la gestión cuando el ACS tiene una dirección de esa
        // red y el aviso llega (se prueba con una lectura inofensiva).
        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) {
                $this->pausa(15);
            }

            $d = $acs->dispositivo((string) $a->acs_id) ?? [];
            $url = (string) (AprovisionamientoDeOnt::v($d, 'InternetGatewayDevice.ManagementServer.ConnectionRequestURL') ?? '');
            $host = parse_url($url, PHP_URL_HOST) ?: '';

            if (!$host || !$g?->red || !AprovisionamientoDeOnt::enRed($host, (string) $g->red)) {
                continue;
            }

            if ($this->llegaAlMomento($acs, (string) $a->acs_id)) {
                $this->paso($a, 'El equipo se reporta por la gestión temporal', true, "El ACS le avisa al momento por {$host}.");
                $this->aFase($a, 'wan', 'Cambiando la conexión en la ONT…');

                return;
            }
        }

        if ($this->vencida($this->c($a), self::ESPERA_GESTION_MIN)) {
            $this->fallar($a, 'El equipo no se reportó por la gestión temporal en ' . self::ESPERA_GESTION_MIN . ' minutos: el cambio hay que hacerlo en el equipo o con un técnico.');
        }
    }

    private function faseWan(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $d = $acs->dispositivo((string) $a->acs_id);

        if (!$d) {
            throw new \RuntimeException('El equipo ya no está en el TR-069.');
        }

        $r = $this->prov->aplicarWan($acs, $a, $d, true, $a->datos['wan']);
        $this->paso($a, 'Conexión nueva en la ONT', $r['ok'], $r['detalle']);

        if ($r['ok']) {
            $this->aFase($a, 'confirmar', 'Esperando que la conexión nueva levante…');

            return;
        }

        // Rechazada por el equipo, o sin contestar pasado el tiempo: se deshace.
        if (empty($r['reintentar']) || $this->vencida($c, self::ESPERA_WAN_MIN)) {
            $this->fallar($a, 'No se pudo cambiar la conexión en la ONT: ' . $r['detalle']);

            return;
        }

        $this->detalle($a, 'El equipo no respondió al momento; se vuelve a probar…');
    }

    private function faseConfirmar(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);

        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) {
                $this->pausa(15);
            }

            $d = $this->leer($acs, (string) $a->acs_id);
            $r = $this->funciona($a, $d, $a->datos['wan'], $c['a'], $c['router_previo']['sesion_antes'] ?? null);

            if ($r['ok']) {
                $this->paso($a, 'Conexión nueva confirmada', true, $r['detalle']);
                $this->aFase($a, 'router', 'Sacando del router la conexión anterior…');

                return;
            }

            $ultimo = $r['detalle'];
        }

        if ($this->vencida($c, self::ESPERA_CONFIRMAR_MIN)) {
            $this->fallar($a, 'La conexión nueva no levantó en ' . self::ESPERA_CONFIRMAR_MIN . ' minutos (' . ($ultimo ?? 'sin datos') . ').');

            return;
        }

        $this->detalle($a, 'Esperando que la conexión nueva levante: ' . ($ultimo ?? ''));
    }

    /**
     * Lo nuevo anda: la ficha pasa al tipo nuevo y se saca del router lo viejo.
     */
    private function faseRouter(Aprovisionamiento $a): void
    {
        $c = $this->c($a);

        if (empty($c['ficha_actualizada'])) {
            $this->actualizarFicha($a);
            $this->actualizar($a, ['ficha_actualizada' => true]);
            $this->paso($a, 'Ficha del cliente', true, 'Ahora figura con ' . self::texto($c['a']) . '.');
        }

        try {
            $hecho = $this->finalizarRouter($a);
        } catch (\Throwable $e) {
            if ($this->vencida($c, self::ESPERA_ROUTER_MIN)) {
                $this->terminar($a, 'con_errores', 'El cliente ya anda con ' . self::texto($c['a']) . ', pero no se pudo sacar del router ' . self::texto($c['de'])
                    . ' (' . mb_substr($e->getMessage(), 0, 80) . '): retírelo a mano.');

                return;
            }

            throw $e;
        }

        $this->paso($a, 'Conexión anterior quitada del router', true, $hecho);

        if ($c['modo'] === 'paralela') {
            $this->aFase($a, 'p_fin', 'Terminando…');

            return;
        }

        // El TR-069 iba por la conexión de internet: la nueva también lo lleva
        // y la gestión de la OLT (temporal o la que tenía) se quita.
        if ($c['modo'] === 'gestion_temporal' || !empty($c['tr069_en_internet'])) {
            $this->aFase($a, 'tr069', 'Pasando el TR-069 a la conexión nueva y quitando la gestión de la OLT…');

            return;
        }

        $this->terminar($a, 'listo', 'Conexión cambiada: ' . self::texto($c['de']) . ' → ' . self::texto($c['a']) . '.');
    }

    private function faseTr069(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);

        for ($i = 0; $i < 2; $i++) {
            if ($i > 0) {
                $this->pausa(20);
            }

            $d = $this->leer($acs, (string) $a->acs_id);
            $r = $this->prov->tr069PorInternet($acs, $a, $d, $a->datos['wan']);

            if ($r['ok']) {
                // La gestión de la OLT ya no se quita (sin ella el Huawei deja de
                // reportar): el detalle dice lo que se hizo.
                $sigue = str_contains($r['detalle'], 'se le deja la gestión');
                $this->paso($a, $sigue ? 'TR-069 en la conexión nueva' : 'Gestión temporal quitada', true, $r['detalle']);
                $this->terminar($a, 'listo', 'Conexión cambiada: ' . self::texto($c['de']) . ' → ' . self::texto($c['a']) . '. '
                    . ($sigue ? 'El TR-069 va también por la conexión nueva y se le deja la gestión de la OLT.' : 'El TR-069 volvió a su conexión de internet y se quitó la gestión temporal.'));

                return;
            }

            if (!empty($r['omitido'])) {
                $this->paso($a, 'Gestión temporal', false, $r['detalle']);
                $this->terminar($a, 'con_errores', 'Conexión cambiada y funcionando, pero ' . lcfirst($r['detalle']) . ' Queda con la gestión de la OLT: en un reinicio de la OLT puede pisarle la conexión.');

                return;
            }

            $ultimo = $r['detalle'];
        }

        if ($this->vencida($c, self::ESPERA_TR069_MIN)) {
            $this->paso($a, 'Gestión temporal', false, $ultimo ?? '');
            $this->terminar($a, 'con_errores', 'Conexión cambiada y funcionando, pero falta quitar la gestión temporal (' . ($ultimo ?? '') . '). Toque «Reintentar».');

            return;
        }

        $this->detalle($a, $ultimo ?? 'Pasando el TR-069 a la conexión nueva…');
    }

    // ── En paralelo (el TR-069 va por la conexión que se cambia) ──────────

    /** Lee sus conexiones y ubica la de antes. La etiqueta lo pone a reportar cada minuto. */
    private function fasePreparar(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $equipo = (string) $a->acs_id;

        if (empty($this->p($a)['etiqueta'])) {
            $acs->etiquetar($equipo, self::ETIQUETA_RAPIDO);
            $this->guardarP($a, ['etiqueta' => true]);
        }

        $r = $this->tareaEnParalelo($a, $acs, 'leer', ['name' => 'refreshObject', 'objectName' => self::WAN]);

        if ($r['estado'] === 'esperando') {
            if ($this->vencida($c, self::ESPERA_PRIMER_REPORTE_MIN)) {
                $this->fallar($a, 'El equipo no reportó al TR-069 en ' . self::ESPERA_PRIMER_REPORTE_MIN . ' minutos.');

                return;
            }

            $d = $acs->dispositivo($equipo) ?? [];
            $this->detalle($a, 'Esperando que el equipo reporte (el último fue ' . self::haceCuanto($d['_lastInform'] ?? null) . '; lo hace cada 15 min). Desde ahí cada paso tarda un minuto…');

            return;
        }

        if ($r['estado'] === 'rechazada') {
            $this->fallar($a, "El equipo no dejó leer sus conexiones ({$r['motivo']}).");

            return;
        }

        $d = $acs->dispositivo($equipo) ?? [];
        $vieja = $this->conexionAnterior($d, $c['wan_anterior']);

        if (!$vieja) {
            $this->fallar($a, 'El equipo no tiene la conexión que figura en la ficha (' . AprovisionamientoDeOnt::resumenWan($c['wan_anterior']) . '): no se sabe cuál reemplazar.');

            return;
        }

        $this->guardarP($a, [
            'ruta_vieja'       => $vieja['ruta'],
            'grupo_viejo'      => $vieja['dispositivo'],
            'servicios_viejos' => $vieja['servicios'],
            'ip_vieja'         => (string) AprovisionamientoDeOnt::v($d, "{$vieja['ruta']}.ExternalIPAddress"),
            'grupos_antes'     => AprovisionamientoDeOnt::hijos($d, self::WAN),
        ]);
        $this->paso($a, 'Conexión actual del equipo', true, "{$vieja['nombre']} (" . ($vieja['servicios'] ?: 'sin servicios') . '): sigue como está hasta que la nueva ande.');
        $this->aFase($a, 'p_grupo', 'Creando la conexión nueva al lado de la actual…');
    }

    /** El lugar (WANConnectionDevice) de la conexión nueva: uno vacío, o uno nuevo. */
    private function faseGrupo(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $p = $this->p($a);
        $d = $acs->dispositivo((string) $a->acs_id) ?? [];
        $usados = collect($this->prov->conexiones($d))->pluck('dispositivo')->map(fn ($g) => (string) $g);

        if (empty($p['tareas']['grupo'])) {
            $vacio = collect(AprovisionamientoDeOnt::hijos($d, self::WAN))->first(fn ($g) => !$usados->contains((string) $g));

            if ($vacio !== null) {
                $this->guardarP($a, ['grupo' => (string) $vacio, 'grupo_creado' => false]);
                $this->aFase($a, 'p_conexion', 'Creando la conexión nueva al lado de la actual…');

                return;
            }
        }

        $r = $this->tareaEnParalelo($a, $acs, 'grupo', ['name' => 'addObject', 'objectName' => self::WAN]);

        if ($this->esperandoPaso($a, $r, 'No se pudo crear la conexión nueva', 'Esperando que el equipo cree la conexión nueva…')) {
            return;
        }

        $grupo = $r['instancia'] ?? null;

        if (!$grupo) {
            $d = $this->releer($a, $acs, self::WAN);
            $nuevos = array_diff(AprovisionamientoDeOnt::hijos($d, self::WAN), (array) ($p['grupos_antes'] ?? []));
            $grupo = $nuevos ? max(array_map('intval', $nuevos)) : null;
        }

        if (!$grupo) {
            $this->fallar($a, 'El equipo dijo que creó la conexión nueva pero no aparece.');

            return;
        }

        $this->guardarP($a, ['grupo' => (string) $grupo, 'grupo_creado' => true]);
        $this->aFase($a, 'p_conexion', 'Creando la conexión nueva al lado de la actual…');
    }

    private function faseConexion(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $p = $this->p($a);
        $objeto = self::WAN . ".{$p['grupo']}." . ($a->datos['wan']['tipo'] === 'pppoe' ? 'WANPPPConnection' : 'WANIPConnection');

        $r = $this->tareaEnParalelo($a, $acs, 'conexion', ['name' => 'addObject', 'objectName' => $objeto]);

        if ($this->esperandoPaso($a, $r, 'No se pudo crear la conexión nueva', 'Esperando que el equipo cree la conexión nueva…')) {
            return;
        }

        $instancia = $r['instancia'] ?? null;

        if (!$instancia) {
            $hijos = AprovisionamientoDeOnt::hijos($this->releer($a, $acs, $objeto), $objeto);
            $instancia = $hijos ? max(array_map('intval', $hijos)) : null;
        }

        if (!$instancia) {
            $this->fallar($a, 'El equipo dijo que creó la conexión nueva pero no aparece.');

            return;
        }

        $this->guardarP($a, ['ruta' => "{$objeto}.{$instancia}"]);
        $this->aFase($a, 'p_datos', 'Cargando los datos de la conexión nueva…');
    }

    /**
     * Sólo INTERNET y sin puertos: no lleva ni el TR-069 ni el tráfico del
     * cliente hasta que se confirme.
     */
    private function faseDatos(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $p = $this->p($a);
        $wan = $a->datos['wan'];
        $valores = $this->prov->valoresWan($a, $p['ruta'], $wan, '');

        // Un Huawei puede crearla con los puertos ya asignados: se le sacan.
        foreach ($this->puertos($acs->dispositivo((string) $a->acs_id) ?? [], $p['ruta_vieja']) as $puerto => $_) {
            $valores[] = ["{$p['ruta']}.X_HW_LANBIND.{$puerto}", 'false', 'xsd:boolean'];
        }

        $r = $this->tareaEnParalelo($a, $acs, 'datos', ['name' => 'setParameterValues', 'parameterValues' => $valores]);

        if ($this->esperandoPaso($a, $r, 'El equipo no aceptó los datos de la conexión nueva', 'Esperando que el equipo cargue la conexión nueva…')) {
            return;
        }

        $this->paso($a, 'Conexión nueva creada al lado de la actual', true, AprovisionamientoDeOnt::resumenWan($wan) . ' (todavía sin el TR-069 ni los puertos).');
        $this->aFase($a, 'p_confirmar', 'Esperando que la conexión nueva levante…');
    }

    private function faseConfirmarNueva(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $ruta = $this->p($a)['ruta'];

        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) {
                $this->pausa(15);
            }

            $r = $this->nuevaAnda($a, $this->releer($a, $acs, $ruta), $ruta);

            if ($r['ok']) {
                $this->paso($a, 'Conexión nueva confirmada', true, $r['detalle']);
                $this->aFase($a, 'p_tr069', 'Pasando el TR-069 y los puertos a la conexión nueva…');

                return;
            }

            $ultimo = $r['detalle'];
        }

        if ($this->vencida($c, self::ESPERA_PASO_MIN)) {
            $this->fallar($a, 'La conexión nueva no levantó en ' . self::ESPERA_PASO_MIN . ' minutos (' . ($ultimo ?? 'sin datos') . ').');

            return;
        }

        $this->detalle($a, 'Esperando que la conexión nueva levante: ' . ($ultimo ?? ''));
    }

    /**
     * El TR-069 y los puertos pasan a la nueva (y la vieja queda sólo con
     * INTERNET). Hecho esto ya no se vuelve atrás: la nueva está confirmada.
     */
    private function fasePasarTr069(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $p = $this->p($a);
        $valores = [];

        if (str_contains((string) $p['servicios_viejos'], 'TR069')) {
            $valores[] = ["{$p['ruta']}.X_HW_SERVICELIST", 'TR069_INTERNET', 'xsd:string'];
            $valores[] = ["{$p['ruta_vieja']}.X_HW_SERVICELIST", 'INTERNET', 'xsd:string'];
        }

        foreach ($this->puertos($acs->dispositivo((string) $a->acs_id) ?? [], $p['ruta_vieja']) as $puerto => $usado) {
            if ($usado) {
                $valores[] = ["{$p['ruta']}.X_HW_LANBIND.{$puerto}", 'true', 'xsd:boolean'];
                $valores[] = ["{$p['ruta_vieja']}.X_HW_LANBIND.{$puerto}", 'false', 'xsd:boolean'];
            }
        }

        if (!$valores) {
            $this->paso($a, 'TR-069', true, 'La conexión de antes no llevaba el TR-069 ni puertos asignados: no hay nada que pasar.');
            $this->aFase($a, 'p_vieja', 'Borrando la conexión de antes…');

            return;
        }

        $r = $this->tareaEnParalelo($a, $acs, 'tr069', ['name' => 'setParameterValues', 'parameterValues' => $valores]);

        if ($r['estado'] === 'rechazada') {
            $this->fallar($a, "El equipo no aceptó pasar el TR-069 a la conexión nueva ({$r['motivo']}).");

            return;
        }

        if ($r['estado'] === 'esperando') {
            // Sólo se abandona si se la puede sacar de la cola: si ya la está
            // haciendo, hay que seguir adelante.
            $id = $p['tareas']['tr069']['id'] ?? null;

            if ($this->vencida($c, self::ESPERA_PASO_MIN) && $id && $acs->sigueEnCola((string) $a->acs_id, $id) && $acs->borrarTarea($id)
                && $acs->sigueEnCola((string) $a->acs_id, $id) === false) {
                $this->fallar($a, 'El equipo no tomó el cambio del TR-069 en ' . self::ESPERA_PASO_MIN . ' minutos.');

                return;
            }

            $this->detalle($a, 'Esperando que el equipo pase el TR-069 a la conexión nueva…');

            return;
        }

        $this->guardarP($a, ['tr069_en' => now()->toIso8601String()]);
        $this->paso($a, 'TR-069 y puertos pasados a la conexión nueva', true, 'La de antes queda sólo con INTERNET hasta borrarla.');
        $this->aFase($a, 'p_reporte', 'Esperando que el equipo reporte por la conexión nueva…');
    }

    private function faseReportaPorLaNueva(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $p = $this->p($a);
        $desde = \Carbon\Carbon::parse($p['tr069_en']);

        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) {
                $this->pausa(15);
            }

            $d = $acs->dispositivo((string) $a->acs_id) ?? [];
            $url = (string) (AprovisionamientoDeOnt::v($d, 'InternetGatewayDevice.ManagementServer.ConnectionRequestURL') ?? '');
            $host = parse_url($url, PHP_URL_HOST) ?: '';
            $ultimo = $d['_lastInform'] ?? null;

            if ($ultimo && \Carbon\Carbon::parse($ultimo)->gt($desde) && $host !== '' && $host !== ($p['ip_vieja'] ?? '')) {
                $this->paso($a, 'El equipo reporta por la conexión nueva', true, "El ACS le habla por {$host}.");
                $this->aFase($a, 'p_vieja', 'Borrando la conexión de antes…');

                return;
            }
        }

        if ($this->vencida($c, self::ESPERA_REPORTE_NUEVA_MIN)) {
            $acs->quitarEtiqueta((string) $a->acs_id, self::ETIQUETA_RAPIDO);
            $this->terminar($a, 'con_errores', 'El cliente navega por la conexión nueva, pero el equipo no volvió a reportar al TR-069 en '
                . self::ESPERA_REPORTE_NUEVA_MIN . ' minutos. No se borró la conexión de antes ni se tocó el router (quedan las dos): revise el equipo antes de seguir.');

            return;
        }

        $this->detalle($a, 'Esperando que el equipo reporte por la conexión nueva…');
    }

    /** El TR-069 ya va por la nueva: la de antes se puede borrar aunque quede en cola. */
    private function faseBorrarVieja(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $p = $this->p($a);
        $d = $acs->dispositivo((string) $a->acs_id) ?? [];
        $solaEnSuGrupo = collect($this->prov->conexiones($d))->where('dispositivo', $p['grupo_viejo'])->count() <= 1;
        $objeto = $solaEnSuGrupo ? self::WAN . ".{$p['grupo_viejo']}" : $p['ruta_vieja'];

        $r = $this->tareaEnParalelo($a, $acs, 'vieja', ['name' => 'deleteObject', 'objectName' => $objeto]);

        if ($r['estado'] === 'esperando' && !$this->vencida($c, self::ESPERA_PASO_MIN)) {
            $this->detalle($a, 'Esperando que el equipo borre la conexión de antes…');

            return;
        }

        match ($r['estado']) {
            'hecha'     => $this->paso($a, 'Conexión de antes borrada del equipo', true, 'Queda sólo la nueva.'),
            'rechazada' => $this->paso($a, 'Conexión de antes', false, "El equipo no dejó borrarla ({$r['motivo']}): no lleva el TR-069 ni los puertos, se puede borrar a mano."),
            default     => $this->paso($a, 'Conexión de antes', false, 'Quedó pedido borrarla: el equipo lo hace en su próximo reporte (ya no lleva el TR-069 ni los puertos).'),
        };

        $this->aFase($a, 'router', 'Sacando del router la conexión anterior…');
    }

    private function faseFinParalela(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $acs->quitarEtiqueta((string) $a->acs_id, self::ETIQUETA_RAPIDO);

        $this->terminar($a, 'listo', 'Conexión cambiada: ' . self::texto($c['de']) . ' → ' . self::texto($c['a'])
            . '. Se armó al lado de la de antes, el TR-069 pasó a la nueva y la de antes se borró.');
    }

    /** No se completó: se borra la nueva (la de antes nunca se tocó) y se sigue con el router. */
    private function faseDeshacerParalela(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $p = $this->p($a);
        $equipo = (string) $a->acs_id;

        // Lo que quedó en cola de este cambio sin hacerse, se saca.
        if (empty($p['cola_limpia'])) {
            foreach ((array) ($p['tareas'] ?? []) as $t) {
                if (empty($t['hecha']) && !empty($t['id'])) {
                    $acs->borrarTarea((string) $t['id']);
                }
            }

            $this->guardarP($a, ['cola_limpia' => true]);
            $p = $this->p($a);
        }

        $objeto = !empty($p['grupo_creado']) && !empty($p['grupo']) ? self::WAN . ".{$p['grupo']}" : ($p['ruta'] ?? null);

        if ($objeto) {
            $r = $this->tareaEnParalelo($a, $acs, 'deshacer', ['name' => 'deleteObject', 'objectName' => $objeto]);

            if ($r['estado'] === 'esperando' && !$this->vencida($c, self::ESPERA_PASO_MIN)) {
                $this->detalle($a, 'Borrando la conexión nueva: esperando al equipo…');

                return;
            }

            match ($r['estado']) {
                'hecha'     => $this->paso($a, 'Conexión nueva borrada', true, 'La ONT quedó sólo con su conexión de antes.'),
                'rechazada' => $this->paso($a, 'Conexión nueva', false, "No se pudo borrar ({$r['motivo']}): no lleva el TR-069 ni los puertos, se puede borrar a mano."),
                default     => $this->paso($a, 'Conexión nueva', false, 'Quedó pedido borrarla: el equipo lo hace en su próximo reporte (no lleva el TR-069 ni los puertos).'),
            };
        } else {
            $this->paso($a, 'ONT', true, 'No se llegó a crear nada: sigue con su conexión de antes.');
        }

        $acs->quitarEtiqueta($equipo, self::ETIQUETA_RAPIDO);
        $this->aFase($a, 'deshacer_router', 'Dejando el router como estaba…');
    }

    /**
     * Una tarea del cambio en paralelo: hecha al momento si el aviso llega, o
     * en cola para el próximo reporte. Se anota una sola vez por clave; las
     * vueltas siguientes miran si ya la hizo o la rechazó.
     *
     * @return array{estado:'hecha'|'esperando'|'rechazada', instancia?:mixed, motivo?:string}
     */
    private function tareaEnParalelo(Aprovisionamiento $a, GenieAcs $acs, string $clave, array $tarea): array
    {
        $equipo = (string) $a->acs_id;
        $t = $this->p($a)['tareas'][$clave] ?? null;

        if (!$t) {
            $desde = now()->subSeconds(2);
            $r = $acs->tarea($equipo, $tarea, 30);
            $t = ['id' => $r['id'] ?? ($r['hecha'] ? null : $acs->idEnCola($equipo, $tarea, $desde)), 'en' => $desde->toIso8601String(),
                'hecha' => (bool) $r['hecha'], 'instancia' => $r['instancia'] ?? null, 'nombre' => (string) ($tarea['name'] ?? '')];
            $this->guardarTarea($a, $clave, $t);

            if ($t['hecha']) {
                return ['estado' => 'hecha', 'instancia' => $t['instancia']];
            }
        }

        if (!empty($t['hecha'])) {
            return ['estado' => 'hecha', 'instancia' => $t['instancia'] ?? null];
        }

        if (empty($t['id'])) {
            $t['id'] = $acs->idEnCola($equipo, $tarea, \Carbon\Carbon::parse($t['en']));

            // Ni con _id ni en la cola: ya la hizo (quien llama lo confirma
            // mirando el equipo).
            if (!$t['id']) {
                $t['hecha'] = true;
                $this->guardarTarea($a, $clave, $t);

                return ['estado' => 'hecha', 'instancia' => null];
            }

            $this->guardarTarea($a, $clave, $t);
        }

        $falla = $acs->fallaDeTarea($equipo, (string) $t['id']);

        // Una sesión cortada o vencida no es un rechazo del equipo: se vuelve a
        // pedir (hasta 3 veces). PRUEBA_TR, en su primer reporte después de un
        // día, cortó la sesión en la primera lectura.
        $veces = (int) ($this->p($a)['reintentos'][$clave] ?? 0);

        if ($falla && preg_match('/session_terminated|timeout|connection/i', $falla) && $veces < 3) {
            $acs->borrarTarea((string) $t['id']);
            $p = $this->p($a);
            unset($p['tareas'][$clave]);
            $p['reintentos'][$clave] = $veces + 1;
            $this->actualizar($a, ['p' => $p]);

            return ['estado' => 'esperando'];
        }

        if ($falla) {
            // Rechazada: GenieACS la reintentaría en cada reporte.
            $acs->borrarTarea((string) $t['id']);
            $t['rechazada'] = $falla;
            $this->guardarTarea($a, $clave, $t);

            return ['estado' => 'rechazada', 'motivo' => $falla];
        }

        if ($acs->sigueEnCola($equipo, (string) $t['id']) !== false) {
            return ['estado' => 'esperando'];
        }

        $t['hecha'] = true;
        $this->guardarTarea($a, $clave, $t);

        return ['estado' => 'hecha', 'instancia' => null];
    }

    /**
     * Para los pasos que agregan (grupo, conexión, datos): esperando, sigue
     * esperando hasta vencer; rechazada o vencida, se deshace.
     * Devuelve true si el paso no terminó (quien llama sale).
     */
    private function esperandoPaso(Aprovisionamiento $a, array $r, string $siFalla, string $mientras): bool
    {
        if ($r['estado'] === 'rechazada') {
            $this->fallar($a, "{$siFalla}: el equipo la rechazó ({$r['motivo']}).");

            return true;
        }

        if ($r['estado'] === 'esperando') {
            if ($this->vencida($this->c($a), self::ESPERA_PASO_MIN)) {
                $this->fallar($a, "{$siFalla}: el equipo no lo hizo en " . self::ESPERA_PASO_MIN . ' minutos.');
            } else {
                $this->detalle($a, $mientras);
            }

            return true;
        }

        return false;
    }

    /** Relee un objeto (al momento, o en el próximo reporte sin encolar dos veces lo mismo). */
    private function releer(Aprovisionamiento $a, GenieAcs $acs, string $objeto): array
    {
        $equipo = (string) $a->acs_id;
        $tarea = ['name' => 'refreshObject', 'objectName' => $objeto];

        try {
            if (!$acs->idEnCola($equipo, $tarea, now()->subHour())) {
                $acs->tarea($equipo, $tarea, 30);
            }
        } catch (\Throwable) {
        }

        return $acs->dispositivo($equipo) ?? [];
    }

    /**
     * ¿La conexión nueva anda? PPPoE: su sesión en el MikroTik. IP fija: la
     * ONT informa esa IP conectada en la conexión nueva, y la entrada del ARP
     * (creada con la MAC de la vieja) pasa a la MAC de la nueva.
     *
     * @return array{ok:bool, detalle:string}
     */
    private function nuevaAnda(Aprovisionamiento $a, array $d, string $ruta): array
    {
        $c = $this->c($a);
        $wan = $a->datos['wan'];

        if ($wan['tipo'] === 'pppoe') {
            return $this->funciona($a, $d, $wan, $c['a'], $c['router_previo']['sesion_antes'] ?? null);
        }

        $ip = (string) AprovisionamientoDeOnt::v($d, "{$ruta}.ExternalIPAddress");
        $estado = (string) AprovisionamientoDeOnt::v($d, "{$ruta}.ConnectionStatus");
        $mac = strtoupper((string) AprovisionamientoDeOnt::v($d, "{$ruta}.MACAddress"));

        if ($ip !== (string) $wan['ip']) {
            return ['ok' => false, 'detalle' => "la ONT todavía no informa la IP {$wan['ip']} en la conexión nueva"];
        }

        if ($estado !== 'Connected') {
            return ['ok' => false, 'detalle' => "la ONT informa la IP {$wan['ip']} pero no conectada ({$estado})"];
        }

        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) || $mac === '00:00:00:00:00:00') {
            return ['ok' => false, 'detalle' => 'el equipo todavía no informó la MAC de la conexión nueva'];
        }

        $api = $this->api($a);
        $entrada = collect($api->query((new Query('/ip/arp/print'))->where('address', (string) $wan['ip']))->read())
            ->first(fn ($e) => ($e['dynamic'] ?? 'false') !== 'true');

        if (!$entrada) {
            return ['ok' => false, 'detalle' => "no está la entrada del ARP de {$wan['ip']}"];
        }

        if (strtoupper((string) ($entrada['mac-address'] ?? '')) !== $mac) {
            $api->query((new Query('/ip/arp/set'))->equal('.id', $entrada['.id'])->equal('mac-address', $mac))->read();
        }

        return ['ok' => true, 'detalle' => "La ONT está conectada con {$wan['ip']}; el ARP quedó con la MAC {$mac}."];
    }

    /**
     * Los puertos (LAN y WiFi) de una conexión: nombre => asignado. Sólo los
     * que el equipo informó: escribir uno que no tiene rechaza todo el cambio.
     *
     * @return array<string,bool>
     */
    private function puertos(array $d, string $ruta): array
    {
        $lista = [];

        foreach ((array) (\Illuminate\Support\Arr::get($d, "{$ruta}.X_HW_LANBIND") ?? []) as $nombre => $nodo) {
            if (!str_starts_with((string) $nombre, '_') && is_array($nodo) && array_key_exists('_value', $nodo)) {
                $lista[(string) $nombre] = filter_var($nodo['_value'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $lista;
    }

    /** @return array<string,mixed> */
    private function p(Aprovisionamiento $a): array
    {
        return (array) ($this->c($a)['p'] ?? []);
    }

    /** @param array<string,mixed> $cambios */
    private function guardarP(Aprovisionamiento $a, array $cambios): void
    {
        $this->actualizar($a, ['p' => array_merge($this->p($a), $cambios)]);
    }

    private function guardarTarea(Aprovisionamiento $a, string $clave, array $t): void
    {
        $p = $this->p($a);
        $p['tareas'][$clave] = $t;
        $this->actualizar($a, ['p' => $p]);
    }

    // ── Hacia atrás ───────────────────────────────────────────────────────

    /** Algo falló antes de confirmar: se deja todo como estaba. */
    private function fallar(Aprovisionamiento $a, string $motivo): void
    {
        $this->actualizar($a, ['motivo_falla' => $motivo, 'deshacer_desde' => now()->toIso8601String()]);
        $this->paso($a, 'No se completó', false, $motivo);

        // En paralelo la conexión de antes nunca se tocó: sólo se borra la nueva.
        if (($this->c($a)['modo'] ?? null) === 'paralela') {
            $this->aFase($a, 'p_deshacer', 'No se completó: se borra la conexión nueva y queda como estaba…');

            return;
        }

        $this->aFase($a, 'deshacer_ont', 'No se completó: se deja como estaba…');
    }

    private function faseDeshacerOnt(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);
        $anterior = $c['wan_anterior'];
        $d = $this->leer($acs, (string) $a->acs_id);

        // Si la ONT sigue con la conexión de antes (no se llegó a tocar, o ya
        // se volvió), no hay nada que hacer en ella.
        if ($this->tieneLaAnterior($d, $anterior)) {
            $this->paso($a, 'ONT', true, 'Sigue con su conexión de antes (' . AprovisionamientoDeOnt::resumenWan($anterior) . ').');
            $this->despuesDeLaOnt($a);

            return;
        }

        $r = $this->prov->aplicarWan($acs, $a, $d, true, $anterior);

        if ($r['ok']) {
            $d = $this->leer($acs, (string) $a->acs_id);

            // Con IP fija la conexión rehecha puede tener otra MAC: el ARP de
            // siempre tiene que tenerla o el router no le contesta.
            if ($anterior['tipo'] === 'static') {
                $this->prov->registrarMac($a, $d, $anterior);
            }

            $this->actualizar($a, ['ont_restaurada' => true]);
            $this->paso($a, 'ONT devuelta a su conexión de antes', true, $r['detalle']);
            $this->despuesDeLaOnt($a);

            return;
        }

        if ($this->vencida($c, self::ESPERA_RESTAURAR_MIN)) {
            $this->actualizar($a, ['ont_sin_restaurar' => true]);
            $this->paso($a, 'ONT', false, 'No se la pudo volver a su conexión de antes: ' . $r['detalle']);
            $this->despuesDeLaOnt($a);

            return;
        }

        $this->detalle($a, 'Volviendo la ONT a su conexión de antes…');
    }

    private function despuesDeLaOnt(Aprovisionamiento $a): void
    {
        $c = $this->c($a);

        // Con gestión temporal hay que quitarla; y si la conexión de antes
        // llevaba el TR-069 y se la rehízo, hay que devolvérselo.
        if (($c['modo'] === 'gestion_temporal' && !empty($c['gestion']['pedida_en']))
            || (!empty($c['tr069_en_internet']) && !empty($c['ont_restaurada']))) {
            $this->aFase($a, 'deshacer_tr069', 'Devolviendo el TR-069 a la conexión de antes…');

            return;
        }

        $this->aFase($a, 'deshacer_router', 'Dejando el router como estaba…');
    }

    private function faseDeshacerTr069(Aprovisionamiento $a, GenieAcs $acs): void
    {
        $c = $this->c($a);

        // Sin su conexión de antes, la gestión temporal es lo único por lo
        // que se le puede llegar: se deja.
        if (!empty($c['ont_sin_restaurar'])) {
            $this->paso($a, 'Gestión temporal', false, 'Se le deja la gestión temporal de la OLT para poder arreglarlo a distancia.');
            $this->aFase($a, 'deshacer_router', 'Dejando el router como estaba…');

            return;
        }

        $d = $this->leer($acs, (string) $a->acs_id);
        $r = $this->prov->tr069PorInternet($acs, $a, $d, $c['wan_anterior']);

        if ($r['ok'] || !empty($r['omitido']) || $this->vencida($c, self::ESPERA_TR069_MIN)) {
            if (!$r['ok']) {
                $this->actualizar($a, ['gestion_quedo' => true]);
            }

            $this->paso($a, 'Gestión temporal', $r['ok'], $r['ok'] ? $r['detalle'] : 'No se pudo quitar: ' . $r['detalle']);
            $this->aFase($a, 'deshacer_router', 'Dejando el router como estaba…');

            return;
        }

        $this->detalle($a, 'Quitando la gestión temporal: ' . $r['detalle']);
    }

    private function faseDeshacerRouter(Aprovisionamiento $a): void
    {
        $c = $this->c($a);
        $hecho = $this->deshacerRouter($a);

        $this->paso($a, 'Router como estaba', true, $hecho);

        $motivo = (string) ($c['motivo_falla'] ?? 'No se completó.');

        if (!empty($c['ont_sin_restaurar'])) {
            $this->terminar($a, 'error', "No se cambió: {$motivo} Y la ONT no se pudo volver a su conexión de antes: el cliente puede estar sin internet. " . $this->queRevisar($a));

            return;
        }

        $this->terminar($a, 'revertido', "No se cambió: {$motivo} El cliente sigue con " . self::texto($c['de']) . '.'
            . (!empty($c['gestion_quedo']) ? ' Quedó con la gestión temporal de la OLT: quitala desde Acceso remoto.' : ''));
    }

    // ── Comprobaciones ────────────────────────────────────────────────────

    /**
     * ¿La conexión anda? PPPoE: su sesión en el MikroTik (o, si no se puede
     * leer el router, la ONT conectada con IP). IP fija: la ONT con esa IP
     * conectada y su MAC en el ARP (con reply-only es lo que hace que el
     * router le conteste).
     *
     * @return array{ok:bool, detalle:string}
     */
    private function funciona(Aprovisionamiento $a, array $d, array $wan, array $lado, ?string $sesionVieja = null): array
    {
        $conexion = collect($this->prov->conexiones($d))->first(fn ($x) => $x['vlan'] === (int) $wan['vlan']
            && $x['objeto'] === ($wan['tipo'] === 'pppoe' ? 'WANPPPConnection' : 'WANIPConnection'));
        $estado = $conexion ? (string) AprovisionamientoDeOnt::v($d, "{$conexion['ruta']}.ConnectionStatus") : '';
        $ip = $conexion ? (string) AprovisionamientoDeOnt::v($d, "{$conexion['ruta']}.ExternalIPAddress") : '';

        if ($wan['tipo'] === 'pppoe') {
            try {
                $sesion = $this->pppoe($a)->sesionActiva((string) $lado['usuario']);

                if ($sesion && $sesionVieja !== null && ($sesion['.id'] ?? null) === $sesionVieja) {
                    return ['ok' => false, 'detalle' => "la sesión PPPoE de {$lado['usuario']} todavía es la de antes del cambio"];
                }

                return $sesion
                    ? ['ok' => true, 'detalle' => "Sesión PPPoE de {$lado['usuario']} activa en el MikroTik (" . ($sesion['address'] ?? 's/IP') . ').']
                    : ['ok' => false, 'detalle' => "todavía no hay sesión PPPoE de {$lado['usuario']} en el MikroTik"];
            } catch (\Throwable) {
                return $estado === 'Connected' && filter_var($ip, FILTER_VALIDATE_IP)
                    ? ['ok' => true, 'detalle' => "La ONT informa la conexión PPPoE conectada con {$ip} (el router no se pudo leer)."]
                    : ['ok' => false, 'detalle' => 'no se pudo leer el router y la ONT no informa la conexión conectada'];
            }
        }

        if (!$conexion || $ip !== (string) $wan['ip']) {
            return ['ok' => false, 'detalle' => "la ONT todavía no informa la IP {$wan['ip']}"];
        }

        if ($estado !== 'Connected') {
            return ['ok' => false, 'detalle' => "la ONT informa la IP {$wan['ip']} pero no conectada ({$estado})"];
        }

        $mac = $this->prov->registrarMac($a, $d, $wan);

        return $mac['ok']
            ? ['ok' => true, 'detalle' => "La ONT está conectada con {$wan['ip']}. {$mac['detalle']}"]
            : ['ok' => false, 'detalle' => lcfirst($mac['detalle'])];
    }

    /** ¿La ONT tiene la conexión de antes, con sus datos? */
    private function tieneLaAnterior(array $d, array $wan): bool
    {
        return $this->conexionAnterior($d, $wan) !== null;
    }

    /** La conexión de antes en la ONT, o null. */
    private function conexionAnterior(array $d, array $wan): ?array
    {
        return collect($this->prov->conexiones($d))->first(function ($x) use ($d, $wan) {
            if ($x['vlan'] !== (int) $wan['vlan']) {
                return false;
            }

            return $wan['tipo'] === 'pppoe'
                ? $x['objeto'] === 'WANPPPConnection' && (string) AprovisionamientoDeOnt::v($d, "{$x['ruta']}.Username") === (string) $wan['usuario']
                : $x['objeto'] === 'WANIPConnection' && (string) AprovisionamientoDeOnt::v($d, "{$x['ruta']}.ExternalIPAddress") === (string) $wan['ip']
                    && strcasecmp((string) AprovisionamientoDeOnt::v($d, "{$x['ruta']}.AddressingType"), 'Static') === 0;
        });
    }

    /** Lectura inofensiva con aviso: si vuelve hecha, el ACS le habla al momento. */
    private function llegaAlMomento(GenieAcs $acs, string $acsId): bool
    {
        $prueba = ['name' => 'refreshObject', 'objectName' => self::WAN];
        $r = $acs->tarea($acsId, $prueba, 30);

        if (!($r['hecha'] ?? false)) {
            try {
                $acs->sacarDeLaCola($acsId, $prueba, $r['id'] ?? null, now()->subMinute(), 2);
            } catch (\Throwable) {
            }
        }

        return (bool) ($r['hecha'] ?? false);
    }

    /** El equipo, con sus conexiones releídas si contesta. */
    private function leer(GenieAcs $acs, string $acsId): array
    {
        $this->llegaAlMomento($acs, $acsId);

        return $acs->dispositivo($acsId) ?? [];
    }

    // ── Router ────────────────────────────────────────────────────────────

    /**
     * Agrega en el router lo nuevo sin sacar nada: el cliente sigue navegando
     * con lo de antes. Devuelve lo necesario para deshacerlo.
     *
     * @return array{ok:bool, mensaje:string, previo:array<string,mixed>}
     */
    private function prepararRouter(int $routerId, UserData $cliente, array $de, array $a, array $wanAnterior, array $d): array
    {
        $token = $this->token($routerId);

        if ($a['tipo'] === 'pppoe') {
            $pppoe = new ServicioPppoe(app(ConectionRouterManagerInterface::class), $token);
            $antes = $pppoe->secret((string) $a['usuario']);
            // Si ya hay una sesión con ese usuario (se le cambia sólo la clave),
            // la que confirma el cambio tiene que ser otra, nueva.
            $sesionAntes = $pppoe->sesionActiva((string) $a['usuario']);

            $pppoe->crear((string) $a['usuario'], AprovisionamientoDeOnt::descifrar((string) $a['clave_cifrada']), (string) $a['perfil'], (string) $cliente->dni);
            $pppoe->reactivar((string) $a['usuario']);

            return ['ok' => true, 'previo' => ['secret' => $antes ? ['cifrado' => Crypt::encryptString(json_encode($antes))] : null,
                'sesion_antes' => $sesionAntes['.id'] ?? null],
                'mensaje' => $antes
                    ? "Se habilitó el usuario PPPoE «{$a['usuario']}» (ya estaba en el router)" . ($de['tipo'] === 'static' ? '; su IP fija sigue activa' : '') . '.'
                    : "Se creó el usuario PPPoE «{$a['usuario']}»" . ($de['tipo'] === 'static' ? '; su IP fija sigue activa' : '') . '.'];
        }

        // IP fija: la entrada del ARP de la IP nueva, con la MAC que tiene hoy
        // la conexión del equipo (la real se carga cuando la ONT la crea).
        $api = app(ConectionRouterManagerInterface::class)->conection($token);
        $ipFija = new IpFijaEnElRouter($api, $this->companyId);
        $revision = $ipFija->revisar((string) $a['ip'], (string) $a['interfaz'], (int) $cliente->user_id);

        if (!$revision['ok']) {
            return ['ok' => false, 'mensaje' => $revision['mensaje'], 'previo' => []];
        }

        $mac = $this->macActual($d, $wanAnterior);
        $huerfana = $revision['huerfana'];
        $arp = $ipFija->aplicar($revision, (string) $a['ip'], (string) $a['interfaz'], (string) $cliente->dni, $mac);

        $q = new Query('/ip/arp/print');
        $q->where('address', (string) $a['ip']);
        $creada = collect($api->query($q)->read())->first(fn ($e) => ($e['dynamic'] ?? 'false') !== 'true');

        return ['ok' => true, 'mensaje' => "Se agregó al ARP la IP {$a['ip']}" . ($de['tipo'] === 'static' ? " (la {$de['ip']} sigue activa)" : ' (el PPPoE sigue activo)') . '.'
            . ($arp['accion'] === 'reutilizada' ? ' ' . $arp['mensaje'] : ''),
            'previo' => [
                'arp' => [
                    'accion'   => $arp['accion'],
                    'id'       => $creada['.id'] ?? null,
                    // La entrada sin cliente que se tomó, para devolverla como estaba.
                    'huerfana' => $arp['accion'] === 'reutilizada' && $huerfana ? [
                        'id' => $huerfana['.id'] ?? null, 'dinamica' => ($huerfana['dynamic'] ?? 'false') === 'true',
                        'comment' => $huerfana['comment'] ?? '', 'mac' => $huerfana['mac-address'] ?? '', 'disabled' => $huerfana['disabled'] ?? 'false',
                    ] : null,
                    'comment_anterior' => $arp['comment_anterior'] ?? null,
                ],
            ]];
    }

    /** Lo nuevo anda: se saca lo viejo. */
    private function finalizarRouter(Aprovisionamiento $a): string
    {
        $c = $this->c($a);
        $de = $c['de'];
        $nuevo = $c['a'];
        $hecho = [];

        if ($de['tipo'] === 'static' && ($nuevo['tipo'] === 'pppoe' || $nuevo['ip'] !== $de['ip'])) {
            $n = $this->borrarArpDe($a, (string) $de['ip']);
            $hecho[] = $n ? "Se sacó del ARP la IP {$de['ip']}." : "La IP {$de['ip']} ya no estaba en el ARP.";
        }

        if ($de['tipo'] === 'pppoe') {
            $pppoe = $this->pppoe($a);

            if ($nuevo['tipo'] === 'static') {
                $pppoe->suspender((string) $de['usuario']);
                $hecho[] = "Se deshabilitó el usuario PPPoE «{$de['usuario']}».";
            } elseif ($nuevo['usuario'] !== $de['usuario']) {
                $pppoe->eliminar((string) $de['usuario']);
                $hecho[] = "Se borró el usuario PPPoE anterior «{$de['usuario']}».";
            }
        }

        return implode(' ', $hecho) ?: 'No había nada que sacar.';
    }

    /** No se confirmó: se saca/deshabilita lo que se agregó. */
    private function deshacerRouter(Aprovisionamiento $a): string
    {
        $c = $this->c($a);
        $nuevo = $c['a'];
        $previo = $c['router_previo'] ?? [];

        if ($nuevo['tipo'] === 'pppoe') {
            $pppoe = $this->pppoe($a);
            $antes = !empty($previo['secret']['cifrado']) ? json_decode(Crypt::decryptString($previo['secret']['cifrado']), true) : null;

            if ($antes) {
                $pppoe->restaurar($antes);

                return "El usuario PPPoE «{$nuevo['usuario']}» quedó como estaba" . (($antes['disabled'] ?? 'false') === 'true' ? ' (deshabilitado).' : '.');
            }

            $pppoe->suspender((string) $nuevo['usuario']);

            return "Se deshabilitó el usuario PPPoE «{$nuevo['usuario']}» que se había creado.";
        }

        $arp = $previo['arp'] ?? [];
        $api = $this->api($a);

        if (($arp['accion'] ?? null) === 'creada') {
            $this->borrarArpDe($a, (string) $nuevo['ip'], $arp['id'] ?? null);

            return "Se sacó del ARP la IP {$nuevo['ip']} que se había agregado.";
        }

        if (($arp['accion'] ?? null) === 'reutilizada' && !empty($arp['huerfana'])) {
            $h = $arp['huerfana'];

            if ($h['dinamica']) {
                // Se había fijado una estática encima de la aprendida: se saca.
                $this->borrarArpDe($a, (string) $nuevo['ip'], $arp['id'] ?? null);
            } elseif ($h['id']) {
                $q = (new Query('/ip/arp/set'))->equal('.id', $h['id'])->equal('comment', (string) $h['comment'])
                    ->equal('disabled', $h['disabled'] === 'true' ? 'yes' : 'no');
                if ($h['mac'] !== '') {
                    $q->equal('mac-address', (string) $h['mac']);
                }
                $api->query($q)->read();
            }

            return "La entrada del ARP de {$nuevo['ip']} volvió a quedar como estaba.";
        }

        return 'No había nada que sacar del router.';
    }

    /** Saca del ARP la entrada de esa IP (la propia del cliente, o la de ese id). */
    private function borrarArpDe(Aprovisionamiento $a, string $ip, ?string $id = null): int
    {
        $api = $this->api($a);
        $q = new Query('/ip/arp/print');
        $q->where('address', $ip);

        $identidad = IdentidadEnElRouter::deUsuario((int) $a->user_id, $this->companyId);
        $dni = (string) UserData::where('user_id', $a->user_id)->where('company_id', $this->companyId)->value('dni');
        $borradas = 0;

        foreach ($api->query($q)->read() as $e) {
            $esSuya = ($id !== null && ($e['.id'] ?? null) === $id)
                || trim((string) ($e['comment'] ?? '')) === $dni
                || ($identidad && IdentidadEnElRouter::suyas([$e], $identidad, $this->companyId));

            if (!$esSuya || ($e['dynamic'] ?? 'false') === 'true') {
                continue;
            }

            $api->query((new Query('/ip/arp/remove'))->equal('.id', $e['.id']))->read();
            $borradas++;
        }

        return $borradas;
    }

    /** Lo nuevo quedó andando: la ficha pasa al tipo nuevo. */
    private function actualizarFicha(Aprovisionamiento $a): void
    {
        $c = $this->c($a);
        $nuevo = $c['a'];
        $cliente = UserData::where('user_id', $a->user_id)->where('company_id', $this->companyId)->firstOrFail();

        if ($nuevo['tipo'] === 'pppoe') {
            $cliente->connection_type = 'pppoe';
            $cliente->pppoe_user = $nuevo['usuario'];
            $cliente->pppoe_password = AprovisionamientoDeOnt::descifrar((string) $nuevo['clave_cifrada']);
            $cliente->pppoe_profile = $nuevo['perfil'];

            // La IP fija se libera: con PPPoE la asigna el pool.
            if ($c['de']['tipo'] === 'static') {
                $cliente->ip_assignment_id = null;
            }

            $cliente->save();

            return;
        }

        $cliente->connection_type = 'static';
        $cliente->pppoe_user = null;
        $cliente->pppoe_password = null;
        $cliente->pppoe_profile = null;
        $cliente->save();

        $ficha = $c['de']['tipo'] === 'static'
            ? AsignacionDeIp::asignar((int) $a->user_id, (string) $nuevo['ip'], $this->companyId)
            : AsignacionDeIp::fichaPropia((int) $a->user_id, (string) $nuevo['ip'], $this->companyId);

        $fichaId = (int) (is_numeric($ficha) ? $ficha : UserData::where('user_id', $a->user_id)->value('ip_assignment_id'));
        $arp = $c['router_previo']['arp'] ?? [];

        if (($arp['accion'] ?? null) === 'reutilizada') {
            IpFijaEnElRouter::recordarNombreAnterior($this->companyId, (int) $a->user_id, $arp['comment_anterior'] ?? null, (string) $cliente->dni);
        }

        // La MAC que quedó en el ARP (la de la conexión nueva de la ONT).
        try {
            $q = new Query('/ip/arp/print');
            $q->where('address', (string) $nuevo['ip']);
            $mac = strtoupper((string) ($this->api($a)->query($q)->read()[0]['mac-address'] ?? ''));

            if ($fichaId && preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) && $mac !== '00:00:00:00:00:00') {
                DB::table('tabla_ips')->where('id', $fichaId)->update(['mac' => $mac]);
            }
        } catch (\Throwable) {
        }
    }

    /** Qué revisar cuando no se pudo dejar todo como estaba. */
    private function queRevisar(Aprovisionamiento $a): string
    {
        $c = $this->c($a);

        return 'Revise el cliente: en el router tiene que quedar sólo ' . self::texto($c['de'])
            . ' y la ONT con ' . AprovisionamientoDeOnt::resumenWan($c['wan_anterior']) . '.';
    }

    // ── Apoyo ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function c(Aprovisionamiento $a): array
    {
        return (array) ($a->datos['cambio'] ?? []);
    }

    /** @param array<string,mixed> $cambios */
    private function actualizar(Aprovisionamiento $a, array $cambios): void
    {
        $datos = $a->datos;
        $datos['cambio'] = array_merge((array) ($datos['cambio'] ?? []), $cambios);
        $a->datos = $datos;
        $a->save();
    }

    private function aFase(Aprovisionamiento $a, string $fase, string $detalle): void
    {
        $this->actualizar($a, ['fase' => $fase, 'fase_desde' => now()->toIso8601String()]);
        $this->detalle($a, $detalle);
    }

    private function vencida(array $c, int $minutos): bool
    {
        return \Carbon\Carbon::parse($c['fase_desde'] ?? now())->addMinutes($minutos)->isPast();
    }

    private function paso(Aprovisionamiento $a, string $paso, bool $ok, string $detalle): void
    {
        $pasos = (array) ($a->pasos ?? []);
        $ultimo = end($pasos) ?: null;

        // El mismo paso con el mismo resultado (reintento del minuto
        // siguiente) no se repite: se actualiza la hora y cuántas veces.
        if ($ultimo && $ultimo['paso'] === $paso && $ultimo['ok'] === $ok && preg_replace('/ \(\d+ intentos\)$/', '', $ultimo['detalle']) === $detalle) {
            $veces = (int) ($ultimo['veces'] ?? 1) + 1;
            $pasos[count($pasos) - 1] = ['paso' => $paso, 'ok' => $ok, 'detalle' => "{$detalle} ({$veces} intentos)", 'hora' => now()->format('H:i:s'), 'veces' => $veces];
        } else {
            $pasos[] = ['paso' => $paso, 'ok' => $ok, 'detalle' => $detalle, 'hora' => now()->format('H:i:s')];
        }

        $a->pasos = array_slice($pasos, -30);
        $a->save();
    }

    private function detalle(Aprovisionamiento $a, string $texto): void
    {
        $a->detalle = mb_substr($texto, 0, 250);
        $a->save();
    }

    private function terminar(Aprovisionamiento $a, string $estado, string $texto): void
    {
        $this->actualizar($a, ['fase' => 'fin', 'fase_desde' => now()->toIso8601String()]);
        $a->fill(['estado' => $estado, 'detalle' => mb_substr($texto, 0, 250), 'listo_en' => now()])->save();

        $pasos = (array) $a->pasos;
        $pasos[] = ['paso' => 'Resultado', 'ok' => $estado === 'listo', 'detalle' => $texto, 'hora' => now()->format('H:i:s')];
        $a->pasos = array_slice($pasos, -30);
        $a->save();

        Log::info('[Cambio de conexión] Terminó', ['id' => $a->id, 'user' => $a->user_id, 'estado' => $estado, 'detalle' => $texto]);
    }

    private function quitarGestion(Aprovisionamiento $a): void
    {
        try {
            $vlan = (int) (GestionRemota::where('company_id', $this->companyId)->value('vlan') ?: 0);
            app(\App\Services\OltTelnetDispatcher::class)->dispatch((int) $a->olt_id, 'quitarGestionDeOnt', [
                'fsp' => $a->fsp, 'ont_id' => (int) $a->ont_id, 'vlan' => $vlan,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Cambio de conexión] No se pudo quitar la gestión temporal', ['id' => $a->id, 'error' => $e->getMessage()]);
        }
    }

    private function token(int $routerId): string
    {
        $token = DB::table('conection_routers')->where('company_id', $this->companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))->orderBy('id')->value('token');

        if (!$token) {
            throw new \RuntimeException('El cliente no tiene un router configurado.');
        }

        return (string) $token;
    }

    private function api(Aprovisionamiento $a)
    {
        return app(ConectionRouterManagerInterface::class)->conection($this->token((int) ($this->c($a)['router_id'] ?? 0)));
    }

    private function pppoe(Aprovisionamiento $a): ServicioPppoe
    {
        return new ServicioPppoe(app(ConectionRouterManagerInterface::class), $this->token((int) ($this->c($a)['router_id'] ?? 0)));
    }

    /** La MAC de la conexión que tiene hoy la ONT (para no dejar el ARP en 00:00…). */
    private function macActual(array $d, array $wan): string
    {
        foreach ($this->prov->conexiones($d) as $x) {
            if ($x['vlan'] === (int) $wan['vlan']) {
                $mac = strtoupper((string) AprovisionamientoDeOnt::v($d, "{$x['ruta']}.MACAddress"));

                if (preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) && $mac !== '00:00:00:00:00:00') {
                    return $mac;
                }
            }
        }

        return '';
    }

    /** La VLAN de servicio de la ONT (la que no es la de gestión). */
    private function vlanDeServicio(OltOnt $ont, int $vlanGestion): ?int
    {
        $vlan = collect($ont->service_ports ?? [])->pluck('vlan')->map(fn ($v) => (int) $v)->first(fn ($v) => $v && $v !== $vlanGestion);

        if (!$vlan) {
            try {
                $vlan = collect(app(\App\Services\OltTelnetDispatcher::class)->dispatch((int) $ont->olt_id, 'getServicePorts', [
                    'fsp' => $ont->fsp, 'ont_id' => (int) $ont->ont_id,
                ]) ?: [])->pluck('vlan')->map(fn ($v) => (int) $v)->first(fn ($v) => $v && $v !== $vlanGestion);
            } catch (\Throwable) {
            }
        }

        return $vlan ?: null;
    }

    public static function buscarEnElAcs(GenieAcs $acs, string $serial): ?string
    {
        $crudo = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $serial));
        $filas = $acs->dispositivos(['_deviceId._SerialNumber' => ['$in' => EquiposDelAcs::serialesPosibles((string) $serial)]], ['_id', '_lastInform']);
        usort($filas, fn ($x, $y) => strcmp((string) ($y['_lastInform'] ?? ''), (string) ($x['_lastInform'] ?? '')));

        return isset($filas[0]['_id']) ? (string) $filas[0]['_id'] : null;
    }

    private static function haceCuanto(?string $cuando): string
    {
        return $cuando ? \Carbon\Carbon::parse($cuando)->locale('es')->diffForHumans() : 'nunca';
    }

    /** "IP fija 192.168.107.62" / "PPPoE 72002503". */
    public static function texto(array $lado): string
    {
        return $lado['tipo'] === 'pppoe' ? "PPPoE «{$lado['usuario']}»" : "IP fija {$lado['ip']}";
    }

    /** Separado para que las pruebas no esperen de verdad. */
    private function pausa(int $segundos): void
    {
        sleep((int) config('services.genieacs.pausa_cambio', $segundos));
    }
}
