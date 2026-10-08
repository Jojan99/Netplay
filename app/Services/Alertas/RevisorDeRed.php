<?php

namespace App\Services\Alertas;

use App\Models\Alerta;
use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\Models\VpnTunel;
use App\Services\Olt\SenalDeLaOlt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Revisa la red y deja anotado lo que hay que mirar.
 *
 * La idea es enterarse antes que el cliente: una fibra con la señal caída,
 * un puerto PON que se apagó entero, un túnel que no saluda. Cada situación
 * abre un aviso con una clave estable, se actualiza mientras dure y se cierra
 * sola cuando se arregla, para que la lista sea lo que pasa ahora y no un
 * historial que nadie mira.
 */
class RevisorDeRed
{
    /** Una ONT sola apagada no es noticia; medio puerto apagado, sí. */
    private const ONTS_PARA_CORTE = 5;
    private const PORCENTAJE_CORTE = 0.6;

    /** Sin saludo por más de esto, el túnel se da por caído. */
    private const MINUTOS_TUNEL = 30;

    public function __construct(private int $companyId) {}

    /** @return array{abiertas:int, cerradas:int} */
    public function revisar(): array
    {
        $vistas = [];

        foreach ([$this->senalYCortes(...), $this->tuneles(...), $this->oltsSinSincronizar(...), $this->datosIncompletos(...), $this->sinCaminoDeDatos(...), $this->routerContraPlataforma(...), $this->chatsSinAtender(...)] as $revision) {
            try {
                $vistas = array_merge($vistas, $revision());
            } catch (\Throwable $e) {
                Log::warning('[Alertas] Una revisión falló', ['empresa' => $this->companyId, 'error' => $e->getMessage()]);
            }
        }

        // Lo que ya no aparece, se cierra: la alerta vive mientras el problema.
        $cerradas = Alerta::where('company_id', $this->companyId)
            ->abiertas()
            // Las de inventario no son de esta revisión: las abre y las cierra AvisosDeInventario.
            ->where('tipo', '!=', 'inventario')
            ->when($vistas, fn ($q) => $q->whereNotIn('clave', $vistas))
            ->update(['cerrada_en' => now()]);

        return ['abiertas' => count($vistas), 'cerradas' => $cerradas];
    }

    // ── Revisiones ────────────────────────────────────────────────────────

    /**
     * Señal óptica fuera de rango y puertos PON que se cayeron enteros.
     *
     * @return list<string>
     */
    private function senalYCortes(): array
    {
        $claves = [];

        foreach (OltAdmin::where('company_id', $this->companyId)->get() as $olt) {
            // En la revisión sí se espera el barrido: corre en segundo plano y
            // de paso deja la medición lista para la pantalla.
            $medicion = SenalDeLaOlt::medirSiHaceFalta($olt);

            $clientes = OltOnt::where('olt_id', $olt->id)
                ->whereNotNull('user_data_id')
                ->get(['fsp', 'ont_id', 'user_data_id', 'description'])
                ->keyBy(fn ($o) => $o->fsp . ':' . $o->ont_id);

            $apagadasPorPuerto = [];
            $totalPorPuerto = [];

            foreach ($medicion['onts'] ?? [] as $ont) {
                $clave = $ont['fsp'] . ':' . $ont['ont_id'];
                $totalPorPuerto[$ont['fsp']] = ($totalPorPuerto[$ont['fsp']] ?? 0) + 1;

                if (($ont['status'] ?? null) === 'offline') {
                    $apagadasPorPuerto[$ont['fsp']] = ($apagadasPorPuerto[$ont['fsp']] ?? 0) + 1;
                }

                if (!in_array($ont['estado'] ?? '', ['baja', 'critica', 'saturada'], true)) {
                    continue;
                }

                $ligada = $clientes[$clave] ?? null;
                // La ONT guarda el id de la FICHA del cliente; el nombre y la alerta van por el de «users».
                $usuario = $ligada ? $this->usuarioDeLaFicha((int) $ligada->user_data_id) : null;
                $nombre = $usuario ? $this->nombreDelCliente($usuario) : ($ont['description'] ?? $clave);

                $claves[] = $this->anotar(
                    "senal:{$olt->id}:{$clave}",
                    'senal',
                    $ont['estado'] === 'critica' || $ont['estado'] === 'saturada' ? 'critico' : 'aviso',
                    "Señal {$this->enCastellano($ont['estado'])} · {$nombre}",
                    $this->explicacionSenal($ont),
                    [
                        'olt' => $olt->name, 'fsp' => $ont['fsp'], 'ont_id' => $ont['ont_id'],
                        'potencia' => $ont['potencia'], 'estado' => $ont['estado'],
                    ],
                    $usuario,
                );
            }

            // Muchas ONT apagadas en el mismo puerto es fibra cortada, no
            // clientes que apagaron el equipo.
            $puertosCortados = [];

            foreach ($apagadasPorPuerto as $fsp => $apagadas) {
                $total = $totalPorPuerto[$fsp] ?? 0;

                if ($apagadas < self::ONTS_PARA_CORTE || $total === 0 || $apagadas / $total < self::PORCENTAJE_CORTE) {
                    continue;
                }

                $puertosCortados[$fsp] = true;

                $claves[] = $this->anotar(
                    "pon:{$olt->id}:{$fsp}",
                    'corte',
                    'critico',
                    "Puerto {$fsp} de {$olt->name} con {$apagadas} de {$total} equipos apagados",
                    'Cuando se apaga casi todo un puerto suele ser la fibra troncal o el puerto de la OLT, no los clientes.',
                    ['olt' => $olt->name, 'fsp' => $fsp, 'apagadas' => $apagadas, 'total' => $total],
                );
            }

            $claves = array_merge($claves, $this->caidasPorFibra($olt, $medicion['onts'] ?? [], $clientes, $puertosCortados));
        }

        return $claves;
    }

    /**
     * Clientes caídos por la fibra, no por la luz.
     *
     * Un equipo que se queda sin energía avisa "dying-gasp" antes de apagarse;
     * uno que pierde la señal óptica no alcanza: eso es fibra cortada, conector
     * suelto o roseta, y pide técnico. Los apagados no se avisan (serían ruido)
     * y si cayó el puerto entero ya está el aviso del corte.
     *
     * @return list<string>
     */
    private function caidasPorFibra(OltAdmin $olt, array $onts, $clientes, array $puertosCortados): array
    {
        // La causa de caída sale de la MIB de Huawei.
        if (strtolower((string) $olt->brand) !== 'huawei') {
            return [];
        }

        try {
            $causas = (new \App\Services\HuaweiSnmpReader($olt))->causasDeCaida();
        } catch (\Throwable $e) {
            Log::warning('[Alertas] No se pudo leer la causa de caída', ['olt' => $olt->id, 'error' => $e->getMessage()]);
            return [];
        }

        // 1 LOS, 2 LOSi/LOBi, 3 LOFi, 4 SFi, 5 LOAi, 6 LOAMi: todas ópticas.
        $deFibra = [1, 2, 3, 4, 5, 6];
        $claves = [];

        foreach ($onts as $ont) {
            $clave  = $ont['fsp'] . ':' . $ont['ont_id'];
            $ligada = $clientes[$clave] ?? null;

            if (!$ligada || ($ont['status'] ?? null) !== 'offline' || isset($puertosCortados[$ont['fsp']])
                || !in_array($causas[$clave] ?? null, $deFibra, true)) {
                continue;
            }

            // olt_onts.user_data_id guarda el id de la FICHA (user_data.id), no el de «users».
            $usuario = $this->usuarioDeLaFicha((int) $ligada->user_data_id);
            $claves[] = $this->anotar(
                "caida:{$olt->id}:{$clave}",
                'caida',
                'critico',
                'Cliente caído por fibra · ' . ($usuario ? $this->nombreDelCliente($usuario) : "equipo {$clave}"),
                'La ONT perdió la señal óptica y no avisó corte de luz: fibra cortada, conector suelto o roseta dañada.',
                ['olt' => $olt->name, 'fsp' => $ont['fsp'], 'ont_id' => $ont['ont_id'], 'causa' => $causas[$clave]],
                $usuario,
            );
        }

        return $claves;
    }

    /** @return list<string> */
    private function tuneles(): array
    {
        $claves = [];

        // El saludo vive en WireGuard, no en la base: sin refrescar, un túnel
        // sano aparecía como caído sólo porque nadie abrió la pantalla.
        try {
            \App\Services\Vpn\ServidorVpn::estado($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[Alertas] No se pudo refrescar el estado de los túneles', ['error' => $e->getMessage()]);
        }

        foreach (VpnTunel::where('company_id', $this->companyId)->where('activo', true)->get() as $tunel) {
            if ($tunel->ultimo_saludo && $tunel->ultimo_saludo->gt(now()->subMinutes(self::MINUTOS_TUNEL))) {
                continue;
            }

            $claves[] = $this->anotar(
                "tunel:{$tunel->id}",
                'tunel',
                'critico',
                "El túnel «{$tunel->nombre}» no está saludando",
                'Sin túnel no se gestionan las OLT de ese nodo ni los equipos de sus clientes. '
                . 'Último saludo: ' . ($tunel->ultimo_saludo?->diffForHumans() ?? 'nunca') . '.',
                ['tunel' => $tunel->nombre, 'ultimo_saludo' => $tunel->ultimo_saludo?->toIso8601String()],
            );
        }

        return $claves;
    }

    /**
     * Una OLT que hace horas no se deja leer: o está caída o se perdió el
     * camino hasta ella.
     *
     * @return list<string>
     */
    private function oltsSinSincronizar(): array
    {
        $claves = [];

        foreach (OltAdmin::where('company_id', $this->companyId)->get() as $olt) {
            $ultima = OltOnt::where('olt_id', $olt->id)->max('synced_at');

            // Que nadie haya abierto la pantalla en seis horas no es una
            // falla: se avisa sólo si además la OLT no responde.
            if (($ultima && now()->parse($ultima)->gt(now()->subHours(6))) || $this->responde((string) $olt->host)) {
                continue;
            }

            $claves[] = $this->anotar(
                "olt:{$olt->id}",
                'olt',
                'critico',
                "La OLT {$olt->name} no responde",
                'No contesta en ' . $olt->host . '. Última lectura de sus ONT: '
                . ($ultima ? now()->parse($ultima)->diffForHumans() : 'nunca')
                . '. Puede ser la OLT, el túnel o el camino hasta ella.',
                ['olt' => $olt->name, 'host' => $olt->host, 'ultima_lectura' => (string) $ultima],
            );
        }

        return $claves;
    }

    /**
     * Datos del sistema que no cuadran y terminan en un cliente sin servicio o
     * en un equipo que no se puede gestionar.
     *
     * @return list<string>
     */
    /**
     * ONT registradas que se quedaron sin service-port.
     *
     * Es el peor caso silencioso de la red: la ONT prende, la OLT la ve
     * online, la fibra está bien —y el cliente no navega, porque sin
     * service-port no hay camino de datos. Pasa cuando se desautoriza y se
     * vuelve a autorizar sin VLAN. Antes no lo sabía nadie hasta que el
     * cliente llamaba.
     *
     * @return list<string>
     */
    /** Segunda lectura, sólo de esa ONT, antes de dar por cierto que le falta. */
    private function confirmaQueLeFalta(int $oltId, string $fsp, int $ontId): bool
    {
        try {
            $respuesta = app(\App\Services\OltTelnetDispatcher::class)
                ->dispatch($oltId, 'getServicePorts', ['fsp' => $fsp, 'ont_id' => $ontId]) ?: [];
        } catch (\Throwable $e) {
            return false;
        }

        $delPuerto = collect($respuesta)->filter(
            fn ($sp) => str_contains(str_replace(['gpon', 'epon'], '', (string) ($sp['port'] ?? '')), $fsp),
        );

        // Sin datos del puerto, la lectura no sirve para afirmar nada.
        return $delPuerto->isNotEmpty()
            && !$delPuerto->contains(fn ($sp) => (int) ($sp['ont_id'] ?? -1) === $ontId);
    }

    private function sinCaminoDeDatos(): array
    {
        $claves = [];

        foreach (OltAdmin::where('company_id', $this->companyId)->get() as $olt) {
            try {
                $incompletas = app(\App\UseCases\OltAdmin\OltAdminUseCase::class)->ontsIncompletas($olt->id);
            } catch (\Throwable $e) {
                Log::warning('[Alertas] No se pudo revisar los service-ports', ['olt' => $olt->id, 'error' => $e->getMessage()]);
                continue;
            }

            foreach ($incompletas as $ont) {
                if (!in_array('service-port', $ont['falta'] ?? [], true)) {
                    continue;
                }

                // Se vuelve a preguntar por esa ONT antes de avisar: la lectura
                // por puerto viene paginada y una respuesta cortada hace
                // parecer que falta un service-port que sí está. Avisar de más
                // aquí es mandar a un técnico a una casa donde todo funciona.
                if (!$this->confirmaQueLeFalta($olt->id, $ont['fsp'], (int) $ont['ont_id'])) {
                    continue;
                }

                $fila = OltOnt::where('olt_id', $olt->id)->where('fsp', $ont['fsp'])->where('ont_id', $ont['ont_id'])->first();
                $usuario = $fila?->user_data_id ? $this->usuarioDeLaFicha((int) $fila->user_data_id) : null;
                $nombre = $usuario
                    ? $this->nombreDelCliente($usuario)
                    : str_replace('_', ' ', (string) ($ont['descripcion'] ?: 'Equipo ' . $ont['fsp'] . ':' . $ont['ont_id']));

                $claves[] = $this->anotar(
                    "sin-service-port:{$olt->id}:{$ont['fsp']}:{$ont['ont_id']}",
                    'datos',
                    'critico',
                    "{$nombre} no tiene camino de datos",
                    'La ONT está registrada en la OLT pero sin service-port: prende y no navega. '
                        . 'Se arregla desde Admin OLT, completando el service-port con la VLAN del cliente.',
                    ['olt' => $olt->name, 'fsp' => $ont['fsp'], 'ont_id' => $ont['ont_id'], 'serial' => $ont['serial']],
                    $usuario,
                );
            }
        }

        return $claves;
    }

    private function datosIncompletos(): array
    {
        $claves = [];

        // Un cliente de IP fija sin IP asignada no navega y nadie se entera
        // hasta que llama: no tiene entrada en el ARP del router.
        $sinIp = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('u.company_id', $this->companyId)
            ->where('ud.active', 1)
            ->where(fn ($q) => $q->where('ud.connection_type', '!=', 'pppoe')->orWhereNull('ud.connection_type'))
            ->whereNull('t.ip')
            ->get(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni']);

        foreach ($sinIp as $c) {
            $nombre = trim($c->names . ' ' . $c->lastname);

            $claves[] = $this->anotar(
                "sin-ip:{$c->user_id}",
                'datos',
                'aviso',
                "{$nombre} figura con IP fija pero no tiene IP asignada",
                'Sin IP no queda en el ARP del router, así que no navega. Asignale una o pasalo a PPPoE.',
                ['documento' => $c->dni],
                (int) $c->user_id,
            );
        }

        // Dos OLT con la misma dirección privada: la plataforma llega por IP,
        // así que sólo alcanza a una de las dos.
        $mias = OltAdmin::where('company_id', $this->companyId)->get(['id', 'name', 'host']);
        $ajenas = OltAdmin::where('company_id', '!=', $this->companyId)
            ->whereIn('host', $mias->pluck('host')->filter()->all())
            ->get(['name', 'host']);

        foreach ($mias as $olt) {
            $choca = $ajenas->firstWhere('host', $olt->host);

            if (!$choca) {
                continue;
            }

            $claves[] = $this->anotar(
                "olt-ip:{$olt->id}",
                'datos',
                'aviso',
                "La OLT {$olt->name} comparte dirección con otra del sistema",
                "Otra OLT usa la misma dirección {$olt->host}. Como se llega por IP, sólo una de las dos queda alcanzable: "
                . 'conviene cambiarle la dirección a una.',
                ['olt' => $olt->name, 'host' => $olt->host],
            );
        }

        return $claves;
    }

    /**
     * Quién está de una forma en la plataforma y de otra en el MikroTik.
     *
     * Lo crítico es el activo que el router tiene cortado: paga y no tiene
     * internet, y nadie se entera hasta que escribe. Antes había que abrir
     * «Revisar router» para verlo; ahora se avisa solo (ver PlataformaContraRouter).
     *
     * @return list<string>
     */
    private function routerContraPlataforma(): array
    {
        $r = app(\App\Services\Red\PlataformaContraRouter::class)->revisar($this->companyId);
        $claves = [];

        // Un router que no se pudo leer no dice nada: sus avisos abiertos se dejan
        // como están, en vez de cerrarlos como si se hubieran resuelto.
        if (collect($r['routers'])->contains(fn ($x) => $x['error'] !== null)) {
            $claves = Alerta::where('company_id', $this->companyId)->abiertas()->where('tipo', 'router')->pluck('clave')->all();
        }

        foreach ($r['problemas'] as $p) {
            // El cliente de IP fija sin IP ya tiene su aviso en datosIncompletos.
            if ($p['problema'] === 'no_esta' && $p['tipo'] === 'static' && $p['donde'] === 'sin IP') {
                continue;
            }

            $quien = "{$p['nombre']} ({$p['dni']})";
            $donde = ($p['tipo'] === 'pppoe' ? 'PPPoE ' : 'IP ') . $p['donde'];

            [$nivel, $titulo, $detalle] = match ($p['problema']) {
                'activo_cortado' => ['critico', "{$quien} está activo pero cortado en el router",
                    "En la plataforma está activo y en el MikroTik «{$p['router']}» está cortado ({$donde}): paga y no tiene internet. "
                    . 'Corríjalo desde Clientes → Revisar router → Habilitar en el router.'],
                'suspendido_navegando' => ['aviso', "{$quien} está suspendido pero navegando",
                    "En la plataforma está suspendido y en el MikroTik «{$p['router']}» sigue habilitado ({$donde}). "
                    . 'Corríjalo desde Clientes → Revisar router → Cortar en el router.'],
                default => ['aviso', "{$quien} no está en el router",
                    "Cliente vigente que no aparece en el MikroTik «{$p['router']}» ({$donde}). Revise su IP o su usuario PPPoE, o reinstálelo."],
            };

            $claves[] = $this->anotar("router:{$p['problema']}:{$p['user_id']}", 'router', $nivel, $titulo, $detalle,
                ['documento' => $p['dni'], 'router' => $p['router'], 'donde' => $p['donde']], (int) $p['user_id']);
        }

        return array_values(array_unique($claves));
    }

    /** Minutos que puede esperar un chat que el bot pasó a un asesor. */
    private const MINUTOS_PARA_ATENDER = 15;

    /**
     * Chats que el bot le pasó a un asesor y nadie contestó.
     *
     * El bot ya avisa y se calla, pero si nadie mira la bandeja el cliente queda
     * esperando: el primer caso que se corrigió («ya pagué y no tenemos
     * internet») esperó dos horas. Crítico, para que llegue también al grupo. Se
     * cierra solo cuando un asesor escribe en ese chat.
     *
     * @return list<string>
     */
    private function chatsSinAtender(): array
    {
        $claves = [];

        // El último traspaso de cada conversación abierta en el último día. Todos los
        // bots (Meta y WhatsApp Web) cierran el mensaje igual.
        $traspasos = DB::table('crm_messages as m')
            ->join('crm_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('crm_customers as k', 'k.id', '=', 'c.customer_id')
            ->where('c.company_id', $this->companyId)
            ->whereIn('c.status', ['new', 'in_progress'])
            ->where('m.sender_type', 'system')
            ->where(fn ($q) => $q->where('m.content', 'like', '%Le paso con un asesor%')->orWhere('m.content', 'like', '%le responde por este mismo chat%'))
            ->where('m.created_at', '>=', now()->subDay())
            ->where('m.created_at', '<=', now()->subMinutes(self::MINUTOS_PARA_ATENDER))
            ->groupBy('c.id', 'c.provider', 'k.name', 'k.phone', 'k.user_id')
            ->selectRaw('c.id, c.provider, k.name, k.phone, k.user_id, MAX(m.created_at) AS desde')
            ->get();

        foreach ($traspasos as $t) {
            $contesto = DB::table('crm_messages')
                ->where('conversation_id', $t->id)
                ->where('sender_type', 'agent')
                ->where('created_at', '>=', $t->desde)
                ->exists();

            if ($contesto) {
                continue;
            }

            // Sin nombre guardado (o el genérico del CRM), el teléfono dice más.
            $nombre = trim((string) $t->name);
            $quien = ($nombre === '' || $nombre === 'Cliente WhatsApp') ? (string) $t->phone : $nombre;
            $canal = $t->provider === 'meta' ? 'WhatsApp (Meta)' : 'WhatsApp Web';
            $hora = \Illuminate\Support\Carbon::parse($t->desde)->format('H:i');

            $claves[] = $this->anotar(
                "chat-sin-atender:{$t->id}",
                'crm',
                'critico',
                "{$quien} espera un asesor desde las {$hora}",
                "El bot le pasó el chat a un asesor por {$canal} ({$t->phone}) y nadie le ha contestado. Ábralo en el CRM.",
                ['conversacion' => (int) $t->id, 'telefono' => $t->phone],
                $t->user_id ? (int) $t->user_id : null,
            );
        }

        return $claves;
    }

    // ── Interno ───────────────────────────────────────────────────────────

    /** Abre el aviso o lo mantiene al día, y devuelve su clave. */
    private function anotar(string $clave, string $tipo, string $nivel, string $titulo, string $detalle, array $datos = [], ?int $userId = null): string
    {
        $alerta = Alerta::firstOrNew(['company_id' => $this->companyId, 'clave' => $clave]);

        // Si estaba cerrada y vuelve, es un problema nuevo: fecha nueva y se
        // vuelve a avisar al grupo (abrir y, después, el resuelto).
        if ($alerta->exists && $alerta->cerrada_en !== null) {
            $alerta->abierta_en = null;
            $alerta->avisada_en = null;
            $alerta->cierre_avisado_en = null;
        }

        $alerta->fill([
            'tipo' => $tipo, 'nivel' => $nivel, 'titulo' => $titulo, 'detalle' => $detalle,
            'datos' => $datos, 'user_id' => $userId, 'cerrada_en' => null,
        ]);

        // La fecha de apertura es la de la primera vez: sirve para saber si
        // esto lleva cinco minutos o tres días.
        $alerta->abierta_en ??= now();
        $alerta->save();

        return $clave;
    }

    /** Un ping corto: sirve para distinguir "nadie la miró" de "está caída". */
    private function responde(string $host): bool
    {
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        exec('ping -c 1 -W 2 ' . escapeshellarg($host) . ' 2>/dev/null', $salida, $codigo);

        return $codigo === 0;
    }

    /**
     * El id de «users» del cliente dueño de una ficha.
     *
     * «olt_onts.user_data_id» guarda el id de la ficha (user_data.id). Usarlo como si fuera el de
     * «users» nombraba en la alerta a OTRO cliente: el que tuviera ese número como id de usuario.
     */
    private function usuarioDeLaFicha(int $fichaId): ?int
    {
        static $cache = [];

        if (!array_key_exists($fichaId, $cache)) {
            $id = DB::table('user_data')->where('id', $fichaId)->where('company_id', $this->companyId)->value('user_id');
            $cache[$fichaId] = $id ? (int) $id : null;
        }

        return $cache[$fichaId];
    }

    private function nombreDelCliente(int $userId): string
    {
        static $cache = [];

        return $cache[$userId] ??= (string) DB::table('user_data')
            ->where('user_id', $userId)
            ->selectRaw("TRIM(CONCAT(names, ' ', lastname)) as n")
            ->value('n') ?: "Cliente {$userId}";
    }

    private function enCastellano(string $estado): string
    {
        return ['baja' => 'baja', 'critica' => 'crítica', 'saturada' => 'saturada'][$estado] ?? $estado;
    }

    private function explicacionSenal(array $ont): string
    {
        $dbm = $ont['potencia'] !== null ? $ont['potencia'] . ' dBm' : 'sin medición';

        return match ($ont['estado']) {
            'saturada' => "Recibe demasiada luz ({$dbm}): el equipo está muy cerca o falta un atenuador.",
            'critica'  => "Recibe muy poca luz ({$dbm}): revise el empalme, el conector o la roseta.",
            default    => "La señal va justa ({$dbm}): todavía navega, pero con lluvia o un empalme más ya falla.",
        };
    }
}
