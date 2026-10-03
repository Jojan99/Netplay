<?php

namespace App\Services\Soporte;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\GestionRemota;
use App\Models\OltAdmin;
use App\Models\SoporteCaso;
use App\Models\SoporteConfig;
use App\Services\Acs\EquiposDelAcs;
use App\Services\Acs\RouterDelCliente;
use App\Services\Plataforma\ComplementoTr069;
use App\Services\Red\AprovisionamientoDeOnt;
use App\Services\Red\GestionRemotaDeOnt;
use App\Services\Red\TareasDeGestion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lo único que el asistente de soporte puede HACER. La IA propone; aquí se
 * valida todo contra lo que la empresa permite y contra quién es el cliente.
 *
 * Regla de oro, escrita en el código y no sólo en las instrucciones: el
 * asistente NUNCA cambia el nombre de una red WiFi. La herramienta de la clave
 * no recibe ningún nombre y siempre manda el nombre en blanco (sin cambio).
 */
class Herramientas
{
    private const CLAVES_POR_DIA = 3;
    private const PINGS_POR_CASO = 3;
    private const INTENTOS_DE_CEDULA = 4;
    private const REINICIANDO = 'Listo: su equipo se está reiniciando. En 2 o 3 minutos vuelve el servicio; cuando encienda de nuevo me cuenta cómo quedó.';

    /** @param string $ultimoTexto Lo último que escribió el cliente: de ahí sale si confirmó o no. */
    public function __construct(private SoporteCaso $caso, private SoporteConfig $cfg, private string $ultimoTexto = '') {}

    /** @return list<array<string,mixed>> */
    public function definiciones(): array
    {
        $nada = ['type' => 'object', 'properties' => (object) []];
        return array_values(array_filter([
            [
                'name' => 'identificar_cliente',
                'description' => 'Busca al cliente por el número de documento del titular del servicio. Úsala cuando todavía no se sabe quién es el cliente, o cuando escribe por el servicio de otra persona.',
                'input_schema' => ['type' => 'object', 'properties' => ['documento' => ['type' => 'string', 'description' => 'Número de documento, sólo dígitos.']], 'required' => ['documento']],
            ],
            [
                'name' => 'diagnosticar',
                'description' => 'Revisa el servicio del cliente de punta a punta: estado de la cuenta, fallas en su sector, el equipo (encendido, señal de fibra, causa de la última caída), la conexión en el router, una prueba de ping y sus redes WiFi. Tarda unos segundos. Úsala SIEMPRE antes de opinar sobre una falla.',
                'input_schema' => $nada,
            ],
            [
                'name' => 'hacer_ping',
                'description' => 'Repite la prueba de ping hacia el equipo del cliente (10 paquetes), para confirmar si mejoró después de un reinicio o de un cambio.',
                'input_schema' => $nada,
            ],
            $this->cfg->permite_cambiar_clave ? [
                'name' => 'cambiar_clave_wifi',
                'description' => 'Cambia la CONTRASEÑA de las redes WiFi del cliente (la misma para todas sus redes). No cambia el nombre de la red: eso no se puede hacer por aquí. Úsela apenas el cliente diga la clave nueva: la primera vez el sistema le pregunta al cliente si confirma (usted no escribe nada), y cuando el cliente responda que sí, úsela otra vez con la misma clave y entonces se aplica.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'clave_nueva' => ['type' => 'string', 'description' => 'La clave tal cual la escribió el cliente: de 8 a 63 caracteres, sin espacios, eñes ni tildes.'],
                ], 'required' => ['clave_nueva']],
            ] : null,
            $this->cfg->permite_reiniciar ? [
                'name' => 'reiniciar_equipo',
                'description' => 'Reinicia a distancia el equipo del cliente. Se queda sin internet 2 o 3 minutos. La primera vez el sistema le pregunta al cliente si acepta (usted no escribe nada); cuando responda que sí, úsela otra vez y entonces se reinicia.',
                'input_schema' => $nada,
            ] : null,
            $this->cfg->crea_tickets ? [
                'name' => 'crear_ticket',
                'description' => 'Deja una orden para que un técnico revise el servicio (visita o revisión). Úsala cuando el diagnóstico dice que hace falta un técnico o cuando lo que se pudo hacer a distancia no lo resolvió.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'resumen' => ['type' => 'string', 'description' => 'Qué le pasa al cliente y qué se intentó, en una o dos frases.'],
                    'prioridad' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja'], 'description' => 'alta: sin servicio. media: servicio con fallas. baja: lo demás.'],
                ], 'required' => ['resumen', 'prioridad']],
            ] : null,
            [
                'name' => 'pasar_a_asesor',
                'description' => 'Deja la conversación en manos de una persona de la empresa. Úsala si el cliente lo pide, está molesto, pregunta por pagos, facturas o planes, quiere cambiar el nombre de su red, o pide algo que usted no puede hacer.',
                'input_schema' => ['type' => 'object', 'properties' => ['motivo' => ['type' => 'string', 'description' => 'Para el asesor: qué necesita el cliente, en una frase.']], 'required' => ['motivo']],
            ],
            [
                'name' => 'cerrar_caso',
                'description' => 'Termina la atención. Úsala cuando el cliente confirma que quedó resuelto, cuando ya se despidió, o cuando lo que escribió no era un pedido de soporte.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'resultado' => ['type' => 'string', 'enum' => ['resuelto', 'no_era_soporte', 'queda_con_ticket']],
                    'resumen' => ['type' => 'string', 'description' => 'Qué pasó, en una frase.'],
                ], 'required' => ['resultado']],
            ],
        ]));
    }

    /**
     * «decir» es un mensaje fijo para el cliente: cuando viene, se manda ése y no
     * lo que redacte la IA. Así la pregunta de confirmación y el resultado de un
     * cambio en el equipo nunca dependen de cómo lo cuente el modelo.
     *
     * @return array{ok:bool, texto:string, fin?:bool, decir?:string}
     */
    public function ejecutar(string $nombre, array $in): array
    {
        try {
            return match ($nombre) {
                'identificar_cliente' => $this->identificar((string) ($in['documento'] ?? '')),
                'diagnosticar' => $this->diagnosticar(),
                'hacer_ping' => $this->ping(),
                'cambiar_clave_wifi' => $this->cambiarClave((string) ($in['clave_nueva'] ?? '')),
                'reiniciar_equipo' => $this->reiniciar(),
                'crear_ticket' => $this->ticket((string) ($in['resumen'] ?? ''), (string) ($in['prioridad'] ?? 'media')),
                'pasar_a_asesor' => $this->pasar((string) ($in['motivo'] ?? '')),
                'cerrar_caso' => $this->cerrar((string) ($in['resultado'] ?? 'resuelto'), (string) ($in['resumen'] ?? '')),
                default => self::no('Esa herramienta no existe.'),
            };
        } catch (\InvalidArgumentException $e) {
            return self::no($e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('[Soporte] Falló una herramienta', ['caso' => $this->caso->id, 'herramienta' => $nombre, 'error' => $e->getMessage()]);

            return self::no('No se pudo hacer en este momento por un problema técnico. No lo reintente: ofrézcale pasar el caso a un asesor.');
        }
    }

    // ── Quién es ─────────────────────────────────────────────────────────────

    private function identificar(string $documento): array
    {
        $doc = preg_replace('/\D/', '', $documento);

        if (strlen($doc) < 5) {
            return self::no('Eso no parece un número de documento. Pídaselo de nuevo.');
        }

        if ($this->contar('intentos_id') > self::INTENTOS_DE_CEDULA) {
            return self::no('Ya se intentó varias veces sin encontrarlo. Pase el caso a un asesor.');
        }

        $c = DB::table('user_data')->where('company_id', $this->caso->company_id)->where('dni', $doc)->orderByDesc('active')->first(['user_id', 'names', 'active']);

        if (!$c) {
            return self::no('No hay ningún cliente con ese documento. Pídale que lo revise (debe ser el del titular del servicio).');
        }

        [, $verificado] = AgenteDeSoporte::quienEs((int) $this->caso->company_id, (string) $this->caso->telefono, (int) $c->user_id);
        $this->caso->fill(['user_id' => (int) $c->user_id, 'verificado' => $verificado])->save();

        return self::si('Cliente encontrado: ' . self::primerNombre($c->names) . '. '
            . ($verificado ? 'Escribe desde su teléfono registrado.' : 'OJO: NO escribe desde el teléfono registrado del titular' . ($this->cfg->exige_telefono_registrado ? ': se le puede diagnosticar, pero no cambiarle la clave ni reiniciarle el equipo.' : '.'))
            . ' Ahora use diagnosticar.');
    }

    // ── Diagnóstico ──────────────────────────────────────────────────────────

    private function diagnosticar(): array
    {
        if (!$uid = (int) $this->caso->user_id) {
            return self::no('Todavía no se sabe quién es el cliente. Pídale el número de documento del titular y use identificar_cliente.');
        }

        // Dos diagnósticos seguidos dan lo mismo y cada uno le habla a la OLT y al router.
        $previo = (array) $this->caso->diagnostico;

        if (!empty($previo['en']) && now()->diffInSeconds($previo['en']) < 90 && !empty($previo['texto'])) {
            return self::si("(Diagnóstico de hace un momento)\n" . $previo['texto']);
        }

        $d = (new DiagnosticoDeServicio((int) $this->caso->company_id, $uid))->completo();
        $acs = $d['datos']['tr069'];

        $texto = "CONCLUSIÓN: {$d['conclusion']}\n"
            . "LO QUE SE ENCONTRÓ:\n- " . implode("\n- ", $d['hechos']) . "\n"
            . "QUÉ DECIRLE AL CLIENTE (con sus palabras, corto): {$d['para_el_cliente']}\n"
            . 'QUÉ SIGUE: ' . $this->queSigue($d['clave'], $acs);

        $this->anotar(['clave' => $d['clave'], 'conclusion' => $d['conclusion'], 'hechos' => $d['hechos'], 'texto' => $texto, 'en' => now()->toIso8601String(),
            'redes' => array_column(array_filter($acs['redes'] ?? [], fn ($r) => $r['activa']), 'nombre')]);

        return self::si($texto);
    }

    private function queSigue(string $clave, array $acs): string
    {
        return match ($clave) {
            'suspendido' => 'No es una falla. Explíquele y, si quiere pagar o reclama, use pasar_a_asesor. No cree ticket.',
            'falla_general' => 'No cree ticket ni reinicie nada: la falla ya está reportada. Tranquilícelo y cierre el caso cuando se despida.',
            'sin_energia', 'equipo_apagado' => 'Pídale que revise el enchufe y las luces del equipo y que le cuente. Si dice que está encendido y sigue igual, cree un ticket.',
            'sin_fibra', 'senal_mala' => 'Esto no se arregla a distancia. Si el cable de la casa está bien conectado, cree un ticket' . ($this->cfg->crea_tickets ? '.' : ' (no disponible: pase a un asesor).'),
            'sin_sesion', 'no_responde', 'inestable' => ($this->cfg->permite_reiniciar ? 'Ofrézcale reiniciar el equipo a distancia (reiniciar_equipo) o que lo apague y encienda; ' : 'Pídale apagar y encender el equipo; ') . 'después de 3 minutos use hacer_ping. Si sigue igual, cree un ticket.',
            'sin_ip', 'incompleto', 'sin_cliente' => 'Pase el caso a un asesor.',
            'sin_equipo', 'sin_equipo_sin_conexion' => 'NO le diga que su equipo está encendido, conectado ni con buena señal: no hay equipo registrado para saberlo, y si el ping no respondió, dígale que su equipo NO está respondiendo. No ofrezca reiniciar ni cambiar la clave. Pregúntele qué luces tiene encendidas el equipo y '
                . ($this->cfg->crea_tickets ? 'cree un ticket para que un técnico lo revise y registre el equipo.' : 'pase el caso a un asesor.'),
            default => 'La red está bien. Pregúntele qué nota exactamente (¿un solo aparato?, ¿lejos del equipo?, ¿a ciertas horas?). Si lo que quiere es cambiar la clave del WiFi, '
                . ($this->cfg->permite_cambiar_clave ? 'pídale la clave nueva y use cambiar_clave_wifi.' : 'pase a un asesor.')
                . (($acs['dispositivos'] ?? 0) >= 12 ? " Tiene {$acs['dispositivos']} aparatos conectados: muchos aparatos a la vez pueden volver lento el servicio; cambiar la clave saca a los que no deberían estar." : ''),
        };
    }

    private function ping(): array
    {
        if (!$uid = (int) $this->caso->user_id) {
            return self::no('Todavía no se sabe quién es el cliente.');
        }

        if ($this->contar('pings') > self::PINGS_POR_CASO) {
            return self::no('Ya se hicieron varias pruebas. Si sigue fallando, cree un ticket.');
        }

        $p = (new DiagnosticoDeServicio((int) $this->caso->company_id, $uid))->ping(10);

        return ['ok' => (bool) ($p['ok'] ?? false), 'texto' => $p['linea']];
    }

    // ── La clave del WiFi ────────────────────────────────────────────────────

    private function cambiarClave(string $clave): array
    {
        if (!$this->cfg->permite_cambiar_clave) {
            return self::no('La empresa no permite cambiar la clave por este medio. Pase a un asesor.');
        }

        if ($motivo = $this->noPuedeTocar()) {
            return self::no($motivo);
        }

        if ($problema = AprovisionamientoDeOnt::problemaDeLaClaveWifi($clave)) {
            return self::no($problema . ' Pídale otra clave: de 8 a 63 caracteres, sólo letras sin tilde, números y símbolos comunes, sin espacios ni eñes.');
        }

        if (!$this->confirmado($clave)) {
            return self::no('TODAVÍA NO SE CAMBIÓ NADA. El sistema ya le preguntó al cliente si confirma. Cuando responda que sí, use otra vez esta herramienta con la misma clave.')
                + ['decir' => "Para confirmar: la clave nueva de su WiFi sería\n\n{$clave}\n\nEl nombre de la red sigue igual. Al cambiarla, todos sus aparatos se desconectan y hay que volver a conectarlos con esta clave. ¿Confirma el cambio?"];
        }

        if (!ComplementoTr069::permitido((int) $this->caso->company_id)) {
            return self::no('La empresa no tiene activa la gestión remota de equipos. ' . ($this->cfg->crea_tickets ? 'Cree un ticket para que un técnico haga el cambio.' : 'Pase a un asesor.'));
        }

        $llave = "soporte:claves:{$this->caso->user_id}:" . now()->toDateString();

        if ((int) Cache::get($llave, 0) >= self::CLAVES_POR_DIA) {
            return self::no('Hoy ya se le cambió la clave varias veces a este cliente. Si necesita otro cambio, pase a un asesor.');
        }

        $r = self::aplicarClave($this->caso, $clave);

        if ($r['estado'] === 'sin_equipo') {
            $habilitar = $this->habilitarGestion();

            if ($habilitar === null && ($r['existe'] ?? false)) {
                // El equipo ya es conocido pero ahora no contesta: la clave queda en su cola.
                $r = self::aplicarClave($this->caso, $clave, false);
            } elseif ($habilitar === null) {
                return self::no('Este equipo no se puede gestionar a distancia todavía. ' . ($this->cfg->crea_tickets ? 'Cree un ticket para que un técnico haga el cambio de clave.' : 'Pase el caso a un asesor.'));
            } else {
                $this->caso->fill(['estado' => 'esperando', 'pendiente' => ['tipo' => 'clave', 'clave' => $clave, 'tarea' => $habilitar, 'desde' => now()->toIso8601String()]])->save();
                $this->anotar(['acciones' => array_merge($this->acciones(), ['clave_en_curso'])]);

                return self::si('EN CURSO, TODAVÍA NO ESTÁ CAMBIADA: el equipo se está habilitando para gestionarlo a distancia. El sistema ya le explicó al cliente y le avisará cuando quede.')
                    + ['decir' => 'Su equipo todavía no tenía activa la gestión a distancia y la estoy habilitando ahora. Tarda entre 2 y 10 minutos: apenas la clave quede cambiada le aviso por este mismo chat. Mientras tanto siga usando la clave de siempre.'];
            }
        }

        if ($r['estado'] === 'error') {
            return self::no($r['detalle']);
        }

        Cache::put($llave, (int) Cache::get($llave, 0) + 1, now()->endOfDay());
        $this->anotar(['acciones' => array_merge($this->acciones(), [$r['estado'] === 'hecha' ? 'clave_cambiada' : 'clave_programada'])]);
        $redes = $r['redes'] ? ' Redes: ' . implode(', ', array_map(fn ($n) => "«{$n}»", $r['redes'])) . '.' : '';

        return $r['estado'] === 'hecha'
            ? self::si("LISTO: la clave quedó cambiada.{$redes} El sistema ya se lo dijo al cliente.")
                + ['decir' => 'Listo: la clave de su WiFi ya quedó cambiada. El nombre de la red sigue igual. Sus aparatos se desconectaron: vuelva a conectarlos con la clave nueva y me cuenta si le funcionó.']
            : self::si("PROGRAMADO: el equipo no contestó en este momento; la clave se aplica sola apenas se comunique.{$redes} El sistema ya se lo dijo al cliente.")
                + ['decir' => 'Su equipo no contestó en este momento, así que el cambio quedó programado: la clave nueva se aplica sola en unos minutos. Cuando sus aparatos se desconecten, vuelva a conectarlos con la clave nueva. Si en 15 minutos sigue entrando con la anterior, escríbame.'];
    }

    /**
     * Manda la clave nueva al equipo del cliente por TR-069, a todas sus redes
     * encendidas. El nombre de la red va SIEMPRE en blanco: no se toca.
     *
     * @return array{estado:string, existe?:bool, redes?:list<string>, detalle?:string}
     */
    public static function aplicarClave(SoporteCaso $caso, string $clave, bool $soloSiReporta = true): array
    {
        $acs = new EquiposDelAcs((int) $caso->company_id);
        $equipo = $acs->deCliente((int) $caso->user_id);

        if (!$equipo || ($soloSiReporta && !($equipo['reportando'] ?? false))) {
            return ['estado' => 'sin_equipo', 'existe' => (bool) $equipo];
        }

        $redes = array_values(array_filter($equipo['wifi'] ?? [], fn ($w) => !empty($w['ruta_clave']) && !empty($w['ssid'])));

        if (!$redes) {
            return ['estado' => 'error', 'detalle' => 'Este modelo de equipo no deja cambiar la clave a distancia. Cree un ticket o pase a un asesor.'];
        }

        $elegida = collect($redes)->firstWhere('activo', true) ?? $redes[0];

        // null = el nombre de la red no se cambia. El asistente no tiene forma de mandar otro valor.
        $r = $acs->cambiarWifi((string) $equipo['id'], (int) $elegida['indice'], null, $clave, true);

        return [
            'estado' => ($r['hecha'] ?? false) ? 'hecha' : 'en_cola',
            'redes' => array_values(array_unique(array_column(array_filter($redes, fn ($w) => ($w['activo'] ?? null) === true || $w['indice'] === $elegida['indice']), 'ssid'))),
        ];
    }

    /**
     * Si el equipo no reporta pero la empresa tiene la gestión remota montada y
     * la ONT está en línea, se le habilita (el mismo «Dar acceso remoto» del
     * panel, sin reiniciar ni limpiar nada). Devuelve la tarea, o null si no aplica.
     */
    private function habilitarGestion(): ?string
    {
        $companyId = (int) $this->caso->company_id;

        if (!GestionRemota::where('company_id', $companyId)->where('activa', 1)->exists()) {
            return null;
        }

        $ont = (new DiagnosticoDeServicio($companyId, (int) $this->caso->user_id))->ont();

        if (!$ont['hay'] || $ont['en_linea'] !== true) {
            return null;
        }

        $olt = OltAdmin::where('id', $ont['olt_id'])->where('company_id', $companyId)->first();

        if (!$olt || !GestionRemotaDeOnt::admiteGestion((string) $olt->brand)) {
            return null;
        }

        $id = TareasDeGestion::crear($companyId, 'dar_acceso', ['olt_id' => (int) $ont['olt_id'], 'fsp' => (string) $ont['fsp'], 'ont_id' => (int) $ont['ont_id']]);
        TareasDeGestion::lanzar($id);

        return $id;
    }

    // ── Reinicio ─────────────────────────────────────────────────────────────

    private function reiniciar(): array
    {
        if (!$this->cfg->permite_reiniciar) {
            return self::no('La empresa no permite reiniciar equipos por este medio. Pídale que lo apague y lo encienda.');
        }

        if ($motivo = $this->noPuedeTocar()) {
            return self::no($motivo);
        }

        if (!$this->confirmado(null)) {
            return self::no('TODAVÍA NO SE REINICIÓ NADA. El sistema ya le preguntó al cliente si acepta. Cuando responda que sí, use otra vez esta herramienta.')
                + ['decir' => 'Puedo reiniciar su equipo a distancia para refrescar la conexión. Se queda sin internet 2 o 3 minutos mientras vuelve a encender. ¿Acepta que lo reinicie ahora?'];
        }

        $companyId = (int) $this->caso->company_id;
        $uid = (int) $this->caso->user_id;

        if (ComplementoTr069::permitido($companyId) && (new EquiposDelAcs($companyId))->deCliente($uid)) {
            $r = (new RouterDelCliente($uid, $companyId))->reiniciar();
            $this->anotar(['acciones' => array_merge($this->acciones(), ['reinicio'])]);

            return ($r['hecha'] ?? false)
                ? self::si('El equipo se está reiniciando. El sistema ya se lo dijo al cliente. Cuando el cliente vuelva a escribir, puede comprobar con hacer_ping.') + ['decir' => self::REINICIANDO]
                : self::si('Se le pidió al equipo que se reinicie; lo hará apenas se comunique. El sistema ya se lo dijo al cliente.')
                    + ['decir' => 'Le pedí a su equipo que se reinicie, pero no contestó en este momento. Si en 5 minutos no se ha reiniciado, desenchúfelo 30 segundos y vuelva a enchufarlo, y me cuenta cómo quedó.'];
        }

        // Sin TR-069: por la OLT, con el mismo límite de tiempo.
        $ont = (new DiagnosticoDeServicio($companyId, $uid))->ont();

        if (!$ont['hay'] || $ont['en_linea'] !== true) {
            return self::no('No se puede reiniciar a distancia. Pídale que lo desenchufe 30 segundos y lo vuelva a enchufar.');
        }

        if (!Cache::add("router-cliente:reinicio:{$uid}", now()->toIso8601String(), now()->addMinutes(RouterDelCliente::ESPERA_REINICIO))) {
            return self::no('Ya se reinició hace poco. Hay que esperar unos minutos a que termine de conectarse.');
        }

        $r = (new GestionRemotaDeOnt($companyId, app(ConectionRouterManagerInterface::class)))->reiniciarEquipo((int) $ont['olt_id'], (string) $ont['fsp'], (int) $ont['ont_id']);

        if (!($r['ok'] ?? false)) {
            Cache::forget("router-cliente:reinicio:{$uid}");

            return self::no('No se pudo reiniciar a distancia. Pídale que lo desenchufe 30 segundos y lo vuelva a enchufar.');
        }

        $this->anotar(['acciones' => array_merge($this->acciones(), ['reinicio'])]);

        return self::si('El equipo se está reiniciando. El sistema ya se lo dijo al cliente.') + ['decir' => self::REINICIANDO];
    }

    /**
     * La confirmación del cliente no se le cree a la IA: se comprueba aquí, contra
     * lo que de verdad se escribió en la conversación.
     *
     * Vale cuando el último mensaje que el cliente recibió de nosotros fue la
     * pregunta (con esa misma clave escrita, o proponiendo el reinicio) y lo
     * que él contestó después dice que sí.
     *
     * @param string|null $clave La clave a cambiar, o null si lo que se confirma es un reinicio.
     */
    private function confirmado(?string $clave): bool
    {
        if (!$this->caso->conversation_id || !self::diceQueSi($this->ultimoTexto)) {
            return false;
        }

        $q = DB::table('crm_messages')->where('conversation_id', $this->caso->conversation_id)->where('sender_type', '!=', 'customer');

        if ($this->caso->ultimo_mensaje_id) {
            $q->where('id', '<', $this->caso->ultimo_mensaje_id);
        }

        $pregunta = (string) $q->orderByDesc('id')->value('content');

        return $clave !== null
            ? $clave !== '' && str_contains($pregunta, $clave) && str_contains($pregunta, '?')
            : (bool) preg_match('/reinici/iu', $pregunta) && str_contains($pregunta, '?');
    }

    public static function diceQueSi(string $texto): bool
    {
        $t = ' ' . trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', strtr(mb_strtolower($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'])))) . ' ';

        if (preg_match('/ (no|nop|nel|todavia no|aun no|mejor no|espere|espera|cancele|cancela|ya no) /', $t)) {
            return false;
        }

        return (bool) preg_match('/ (si|sii|siii|sip|sisas|claro|confirmo|confirmado|confirmada|dale|hagale|hagalo|hazlo|de una|listo|ok|okay|okey|oki|correcto|por favor|porfa|adelante|cambiela|cambiala|cambiemela|reinicielo|reinicialo|reiniciela|proceda|esta bien|bueno|vale|acepto|de acuerdo|afirmativo) /', $t);
    }

    /** Cambiar algo del equipo exige saber quién es y, si la empresa lo pide, que escriba desde su teléfono registrado. */
    private function noPuedeTocar(): ?string
    {
        if (!$this->caso->user_id) {
            return 'Todavía no se sabe quién es el cliente. Pídale el documento del titular y use identificar_cliente.';
        }

        if ($this->cfg->exige_telefono_registrado && !$this->caso->verificado) {
            return 'No se puede: por seguridad los cambios en el equipo sólo se hacen cuando escriben desde el teléfono registrado del titular, y este número no lo es. Explíquele eso y ofrézcale pasar con un asesor.';
        }

        return null;
    }

    // ── Ticket, asesor y cierre ──────────────────────────────────────────────

    private function ticket(string $resumen, string $prioridad): array
    {
        if (!$this->cfg->crea_tickets) {
            return self::no('La empresa no permite crear tickets por este medio. Pase a un asesor.');
        }

        if (!$this->caso->user_id) {
            return self::no('Todavía no se sabe quién es el cliente. Pídale el documento del titular.');
        }

        $r = self::crearTicket($this->caso, $resumen, $prioridad);
        $this->anotar(['acciones' => array_merge($this->acciones(), ['ticket'])]);

        return self::si(($r['nuevo'] ? "Ticket #{$r['id']} creado." : "El cliente ya tenía abierto el ticket #{$r['id']}: se le agregó esta novedad.")
            . ' Dígale el número y que un técnico de la empresa lo va a contactar para coordinar. No prometa una hora.');
    }

    /** @return array{id:int, nuevo:bool} */
    public static function crearTicket(SoporteCaso $caso, string $resumen, string $prioridad = 'media'): array
    {
        $companyId = (int) $caso->company_id;
        $dx = (array) $caso->diagnostico;
        $nota = '[Asistente de soporte por WhatsApp] ' . mb_substr(trim($resumen) ?: 'El cliente reportó una falla.', 0, 500)
            . (!empty($dx['conclusion']) ? "\nDiagnóstico: {$dx['conclusion']}\n- " . implode("\n- ", array_map(fn ($h) => preg_replace('/ · redes WiFi:.*$/u', '.', $h), (array) ($dx['hechos'] ?? []))) : '');

        if ($caso->ticket_id) {
            return ['id' => (int) $caso->ticket_id, 'nuevo' => false];
        }

        // Si ya tiene uno sin terminar, no se le abre otro.
        $abierto = DB::table('tickets')->where('company_id', $companyId)->where('user_id', $caso->user_id)->whereIn('status_id', [1, 2])
            ->where('created_at', '>=', now()->subDays(15))->orderByDesc('id')->first(['id', 'observation']);

        if ($abierto) {
            DB::table('tickets')->where('id', $abierto->id)->update(['observation' => mb_substr(trim($abierto->observation . "\n\n" . now()->format('d/m H:i') . ' ' . $nota), 0, 60000), 'updated_at' => now()]);
            $caso->fill(['ticket_id' => $abierto->id])->save();

            return ['id' => (int) $abierto->id, 'nuevo' => false];
        }

        $cliente = DB::table('user_data')->where('company_id', $companyId)->where('user_id', $caso->user_id)->first(['address', 'dni', 'phone']);
        $servicio = DB::table('ticket_type_services')->whereIn('company_id', [$companyId, 0])->where('active', 1)
            ->orderByRaw("CASE WHEN UPPER(name) LIKE 'SOPORTE%' THEN 0 WHEN UPPER(name) LIKE 'DA%' THEN 1 ELSE 2 END")->orderByDesc('company_id')->value('id');
        $prioridadId = DB::table('ticket_type_prioritys')->whereIn('company_id', [$companyId, 0])->where('active', 1)
            ->orderByRaw('CASE WHEN UPPER(name) = ? THEN 0 ELSE 1 END', [mb_strtoupper($prioridad)])->orderByDesc('company_id')->value('id');

        $datos = [
            'company_id' => $companyId, 'user_id' => $caso->user_id, 'address' => $cliente->address ?? 'Sin dirección', 'date' => now()->toDateString(),
            'service_id' => $servicio, 'priority_id' => $prioridadId, 'status_id' => 1, 'observation' => $nota,
            'cedula' => $cliente->dni ?? '0', 'phone' => $cliente->phone ?? $caso->telefono, 'reopened_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ];

        if (\Illuminate\Support\Facades\Schema::hasColumn('tickets', 'source')) {
            $datos['source'] = 'asistente';
        }

        $id = (int) DB::table('tickets')->insertGetId($datos);
        $caso->fill(['ticket_id' => $id])->save();

        return ['id' => $id, 'nuevo' => true];
    }

    private function pasar(string $motivo): array
    {
        self::escalar($this->caso, $motivo ?: 'El cliente pidió hablar con una persona.');

        return ['ok' => true, 'fin' => true, 'texto' => 'Hecho: la conversación quedó para un asesor. Despídase en una frase diciéndole que un asesor le sigue atendiendo por este mismo chat. No prometa un tiempo.'];
    }

    /** La conversación queda a la vista de los agentes, sin dueño, y el otro bot sigue callado. */
    public static function escalar(SoporteCaso $caso, string $motivo): void
    {
        AgenteDeSoporte::cerrar($caso, 'escalado', 'paso_a_asesor', false, mb_substr($motivo, 0, 500));
        self::aLaVista($caso);
    }

    /**
     * Deja la conversación como «nueva» para que los agentes la vean pendiente.
     * Se repite después de cada mensaje del asistente, porque al guardarse como
     * mensaje de agente el CRM la marca «en curso» aunque nadie la haya tomado.
     */
    public static function aLaVista(SoporteCaso $caso): void
    {
        if ($caso->conversation_id) {
            DB::table('crm_conversations')->where('id', $caso->conversation_id)->whereNull('assigned_user_id')->where('status', '!=', 'closed')->update(['status' => 'new', 'updated_at' => now()]);
        }
    }

    private function cerrar(string $resultado, string $resumen): array
    {
        $resultado = in_array($resultado, ['resuelto', 'no_era_soporte', 'queda_con_ticket'], true) ? $resultado : 'resuelto';
        AgenteDeSoporte::cerrar($this->caso, $resultado === 'no_era_soporte' ? 'cerrado' : 'resuelto', $resultado, true, mb_substr($resumen, 0, 500) ?: null);

        return ['ok' => true, 'fin' => true, 'texto' => $resultado === 'no_era_soporte'
            ? 'Caso cerrado. No le escriba nada sobre soporte: si hace falta, una frase corta y amable.'
            : 'Caso cerrado. Despídase en una frase.'];
    }

    // ── Notas del caso ───────────────────────────────────────────────────────

    /** @return list<string> */
    public function acciones(): array
    {
        return array_values(array_unique((array) (((array) $this->caso->diagnostico)['acciones'] ?? [])));
    }

    private function anotar(array $cambios): void
    {
        $this->caso->diagnostico = array_merge((array) $this->caso->diagnostico, $cambios);
        $this->caso->save();
    }

    private function contar(string $que): int
    {
        $n = (int) (((array) $this->caso->diagnostico)[$que] ?? 0) + 1;
        $this->anotar([$que => $n]);

        return $n;
    }

    public static function primerNombre(?string $nombres): string
    {
        return mb_convert_case((string) (preg_split('/\s+/', trim((string) $nombres))[0] ?? ''), MB_CASE_TITLE) ?: 'Cliente';
    }

    private static function si(string $texto): array
    {
        return ['ok' => true, 'texto' => $texto];
    }

    private static function no(string $texto): array
    {
        return ['ok' => false, 'texto' => $texto];
    }
}
