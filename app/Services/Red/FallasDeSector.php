<?php

namespace App\Services\Red;

use App\Models\OltAdmin;
use App\Services\Alertas\AvisosAlGrupo;
use App\Services\HuaweiSnmpReader;
use App\Services\Olt\SenalDeLaOlt;
use App\Services\Olt\SnmpZte;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fallas de sector: muchas ONT del mismo puerto PON caídas a la vez.
 *
 * Cuando eso pasa, cada cliente escribe por su lado y el asesor contesta lo mismo cincuenta
 * veces. Aquí se detecta la caída del puerto, se confirma que dura (un parpadeo no es una
 * falla), se avisa a los clientes afectados por WhatsApp y, cuando vuelve, se les dice que
 * ya quedó. El asistente de soporte consulta lo mismo para no abrir tickets de una falla
 * que ya se conoce.
 *
 * Todo lo ajustable vive en fallas_sector_config (una fila por empresa) y se cambia desde
 * el módulo «Fallas de sector»: cuántas ONT, qué porcentaje, cuánto esperar, si avisa solo
 * o espera aprobación, horario de silencio, por qué línea y con qué mensajes.
 *
 * Ciclo de una falla: detectada → (por_aprobar) → activa → resuelta. Si se recupera antes
 * de confirmarse, o alguien la descarta (mantenimiento programado), queda «descartada» y
 * no se le escribe a nadie.
 */
class FallasDeSector
{
    public const MENSAJE_INICIO = "Hola {cliente} 👋\n\n"
        . "Detectamos una falla en el servicio de internet de {sector} y nuestro equipo técnico ya está trabajando para solucionarla.\n\n"
        . "No es necesario que la reporte ni que reinicie su equipo: le avisaremos por aquí apenas se restablezca.\n\n"
        . '{empresa}';

    public const MENSAJE_FIN = "Hola {cliente} ✅\n\n"
        . "El servicio de internet de {sector} ya fue restablecido.\n\n"
        . "Si todavía no tiene conexión, apague su equipo, espere 10 segundos y vuelva a encenderlo. Si sigue igual, escríbanos por aquí.\n\n"
        . "Gracias por su paciencia.\n{empresa}";

    /** Estados en los que la falla sigue abierta. */
    public const ABIERTAS = ['detectada', 'por_aprobar', 'activa'];

    /** Lo que se guarda tal cual desde el módulo. */
    public const CAMPOS = [
        'activo', 'modo', 'minimo_onts', 'porcentaje', 'minutos_revision', 'minutos_confirmacion', 'minutos_resolucion',
        'contar_cortes_de_luz', 'avisar_inicio', 'avisar_fin', 'avisar_grupo', 'incluir_suspendidos', 'wa_linea_id',
        'segundos_entre_mensajes', 'silencio_desde', 'silencio_hasta', 'mensaje_inicio', 'mensaje_fin',
        'tickets_para_masiva', 'minutos_ventana_tickets', 'cerrar_tickets',
    ];

    /** El «puerto» de una falla de la OLT entera: no responde, o una racha de tickets. */
    public const TODA_LA_OLT = '*';

    /** Por dónde entran los reportes de clientes: los que cuentan para una racha de tickets. */
    private const FUENTES_DE_CLIENTES = ['asistente', 'client_portal', 'portal', 'cliente', 'whatsapp'];

    /** @var \Closure|null Para las pruebas: reemplaza el envío real por WhatsApp. */
    private ?\Closure $enviarCon;

    /** @var array<int, array<string,mixed>> Lo que se leyó de cada OLT en esta pasada (para el módulo). */
    public array $lecturas = [];

    public function __construct(private int $companyId, ?\Closure $enviarCon = null)
    {
        $this->enviarCon = $enviarCon;
    }

    // ── Configuración ────────────────────────────────────────────────────

    public static function config(int $companyId): object
    {
        $fila = DB::table('fallas_sector_config')->where('company_id', $companyId)->first();

        $base = (object) [
            'company_id' => $companyId, 'activo' => 0, 'modo' => 'aprobar', 'minimo_onts' => 5, 'porcentaje' => 30,
            'minutos_revision' => 3, 'minutos_confirmacion' => 5, 'minutos_resolucion' => 5, 'contar_cortes_de_luz' => 1,
            'avisar_inicio' => 1, 'avisar_fin' => 1, 'avisar_grupo' => 1, 'incluir_suspendidos' => 0, 'wa_linea_id' => null,
            'segundos_entre_mensajes' => 3, 'silencio_desde' => null, 'silencio_hasta' => null,
            'mensaje_inicio' => null, 'mensaje_fin' => null, 'revisado_en' => null,
            'tickets_para_masiva' => 4, 'minutos_ventana_tickets' => 30, 'cerrar_tickets' => 1,
        ];

        return $fila ? (object) array_merge((array) $base, (array) $fila) : $base;
    }

    public static function guardarConfig(int $companyId, array $datos): object
    {
        $valores = array_intersect_key($datos, array_flip(self::CAMPOS));

        // Un mensaje igual al de fábrica se guarda vacío: así, si se mejora el texto, le llega.
        foreach (['mensaje_inicio' => self::MENSAJE_INICIO, 'mensaje_fin' => self::MENSAJE_FIN] as $campo => $defecto) {
            if (array_key_exists($campo, $valores) && trim((string) $valores[$campo]) === trim($defecto)) {
                $valores[$campo] = null;
            }
        }

        DB::table('fallas_sector_config')->updateOrInsert(['company_id' => $companyId], $valores + ['updated_at' => now(), 'created_at' => now()]);

        return self::config($companyId);
    }

    // ── Revisión ─────────────────────────────────────────────────────────

    /**
     * Una pasada: lee las OLT, abre/actualiza/cierra fallas y manda los avisos pendientes.
     *
     * @return array{revisada:bool, abiertas:int, avisados:int, motivo?:string}
     */
    public function revisar(bool $forzar = false): array
    {
        $cfg = self::config($this->companyId);

        if (!$cfg->activo && !$forzar) {
            return ['revisada' => false, 'abiertas' => 0, 'avisados' => 0, 'motivo' => 'apagado'];
        }

        if (!$forzar && $cfg->revisado_en && now()->diffInSeconds($cfg->revisado_en) < max(1, (int) $cfg->minutos_revision) * 60 - 10) {
            // No toca leer la OLT, pero lo que quedó por avisar (una falla recién aprobada, el fin
            // de un horario de silencio) sale igual.
            return ['revisada' => false, 'abiertas' => 0, 'avisados' => $this->enviarPendientes($cfg), 'motivo' => 'todavía no toca'];
        }

        DB::table('fallas_sector_config')->where('company_id', $this->companyId)->update(['revisado_en' => now()]);

        foreach (OltAdmin::where('company_id', $this->companyId)->get() as $olt) {
            $noContesto = false;

            try {
                $estados = $this->leer($olt, (bool) $cfg->contar_cortes_de_luz);
                // Huawei y ZTE se leen en vivo: una lectura vacía es que la OLT no contestó. Las
                // demás marcas leen la última medición guardada, y que esté vieja no dice nada de la OLT.
                $noContesto = !$estados && in_array(strtolower((string) $olt->brand), ['huawei', 'zte'], true);
            } catch (\Throwable $e) {
                Log::warning('[Fallas de sector] No se pudo leer la OLT', ['olt' => $olt->id, 'error' => $e->getMessage()]);
                $estados = null;
                $noContesto = true;
            }

            // Sin lectura no se decide nada puerto por puerto; pero una OLT que deja de
            // responder es, ella sola, una falla masiva (22-10-2026: la OLT 5 no respondió de
            // 13:03 a 13:25 y Kloe abrió un ticket por cliente sin que nada lo avisara).
            if ($estados === null || $estados === []) {
                $this->lecturas[$olt->id] = ['olt' => $olt->name, 'error' => 'sin lectura'];
                if ($noContesto) {
                    $this->sinLectura($olt, $cfg);
                }
                continue;
            }

            $this->lecturas[$olt->id] = ['olt' => $olt->name, 'onts' => count($estados), 'caidas' => count(array_filter($estados, fn ($e) => $e['cuenta']))];
            $this->evaluarOlt($olt, $estados, $cfg);
        }

        // Con el módulo apagado se puede revisar a mano para ver qué detecta, pero no se le escribe a nadie.
        $avisados = $cfg->activo ? $this->enviarPendientes($cfg) : 0;

        return [
            'revisada' => true,
            'abiertas' => DB::table('fallas_sector')->where('company_id', $this->companyId)->whereIn('estado', self::ABIERTAS)->count(),
            'avisados' => $avisados,
        ];
    }

    /**
     * Estado de cada ONT de la OLT: ['fsp:ont' => ['cuenta' => caída que cuenta para la falla]].
     *
     * Huawei y ZTE se leen en vivo por SNMP (un walk, un par de segundos). Las demás marcas,
     * de la última medición guardada por las alertas (hasta 20 minutos de vieja).
     *
     * @return array<string, array{cuenta:bool}>|null
     */
    protected function leer(OltAdmin $olt, bool $contarLuz): ?array
    {
        $marca = strtolower((string) $olt->brand);
        $r = [];

        if ($marca === 'huawei') {
            $snmp = new HuaweiSnmpReader($olt);
            $enLinea = $snmp->enLinea();
            // La causa sólo hace falta para descontar los cortes de luz.
            $causas = !$contarLuz && in_array(false, $enLinea, true) ? $snmp->causasDeCaida() : [];

            foreach ($enLinea as $clave => $online) {
                $r[$clave] = ['cuenta' => !$online && ($contarLuz || ($causas[$clave] ?? null) !== 13)];
            }

            return $r;
        }

        if ($marca === 'zte') {
            foreach ((new SnmpZte(new HuaweiSnmpReader($olt)))->onts() as $o) {
                $caida = $o['status'] !== 'online';
                $r[$o['fsp'] . ':' . $o['ont_id']] = ['cuenta' => $caida && ($contarLuz || ($o['fase'] ?? '') !== 'DyingGasp')];
            }

            return $r;
        }

        $medicion = SenalDeLaOlt::de($olt);
        if (empty($medicion['medido_en']) || now()->diffInMinutes($medicion['medido_en']) > 20) {
            return null;
        }

        foreach ($medicion['onts'] ?? [] as $o) {
            $r[$o['fsp'] . ':' . $o['ont_id']] = ['cuenta' => ($o['status'] ?? null) === 'offline'];
        }

        return $r;
    }

    private function evaluarOlt(OltAdmin $olt, array $estados, object $cfg): void
    {
        $porPuerto = [];

        foreach ($estados as $clave => $e) {
            [$fsp, $ont] = explode(':', $clave);
            $porPuerto[$fsp]['total'] = ($porPuerto[$fsp]['total'] ?? 0) + 1;
            $porPuerto[$fsp]['caidas'] ??= [];

            if ($e['cuenta']) {
                $porPuerto[$fsp]['caidas'][] = (int) $ont;
            }
        }

        $abiertas = DB::table('fallas_sector')->where('company_id', $this->companyId)->where('olt_id', $olt->id)
            ->whereIn('estado', self::ABIERTAS)->get()->keyBy('fsp');

        foreach ($porPuerto as $fsp => $p) {
            $caidas = count($p['caidas']);
            $esFalla = $caidas >= max(1, (int) $cfg->minimo_onts) && $p['total'] > 0 && $caidas * 100 / $p['total'] >= (int) $cfg->porcentaje;
            $falla = $abiertas[$fsp] ?? null;

            if ($esFalla) {
                $falla = $this->abrirOActualizar($olt, (string) $fsp, $falla, $caidas, (int) $p['total']);
                $this->anotarAfectados($falla, $olt, (string) $fsp, $p['caidas'], (bool) $cfg->incluir_suspendidos);
                $this->confirmarSiDura($falla, $cfg);
                continue;
            }

            if ($falla) {
                $this->alVolver($falla, $cfg, $caidas, (int) $p['total']);
            }
        }

        // Un puerto que ya ni aparece en la lectura (se quitaron las ONT) no deja la falla abierta.
        // La de toda la OLT (fsp «*») tampoco aparece: que la OLT conteste ya es que volvió, salvo
        // la que abrieron los tickets, que espera a que esos clientes estén en línea.
        foreach ($abiertas as $fsp => $falla) {
            if (isset($porPuerto[$fsp])) {
                continue;
            }

            if ((string) $fsp === self::TODA_LA_OLT && ($falla->origen ?? '') === 'tickets' && !$this->volvieronLosDeLosTickets($falla, $estados)) {
                if ($falla->volvio_en) {
                    DB::table('fallas_sector')->where('id', $falla->id)->update(['volvio_en' => null, 'updated_at' => now()]);
                }
                continue;
            }

            $this->alVolver($falla, $cfg, 0, 0);
        }
    }

    private function abrirOActualizar(OltAdmin $olt, string $fsp, ?object $falla, int $caidas, int $total): object
    {
        if (!$falla) {
            $id = DB::table('fallas_sector')->insertGetId([
                'company_id' => $this->companyId, 'olt_id' => $olt->id, 'fsp' => $fsp, 'estado' => 'detectada',
                'caidas' => $caidas, 'maximo_caidas' => $caidas, 'total' => $total, 'empezo_en' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('fallas_sector')->find($id);
        }

        DB::table('fallas_sector')->where('id', $falla->id)->update([
            'caidas' => $caidas, 'maximo_caidas' => max($caidas, (int) $falla->maximo_caidas), 'total' => $total,
            'volvio_en' => null, 'updated_at' => now(),
        ]);

        return DB::table('fallas_sector')->find($falla->id);
    }

    /** Los clientes de las ONT caídas del puerto: los que se van a avisar. */
    private function anotarAfectados(object $falla, OltAdmin $olt, string $fsp, array $ontsCaidas, bool $conSuspendidos): void
    {
        if (!$ontsCaidas) {
            return;
        }

        // olt_onts.user_data_id es el id de la FICHA (user_data.id), no el de «users».
        $clientes = DB::table('olt_onts as o')->join('user_data as ud', 'ud.id', '=', 'o.user_data_id')
            ->where('o.olt_id', $olt->id)->where('o.fsp', $fsp)->whereIn('o.ont_id', $ontsCaidas)
            ->where('ud.active', 1)
            ->when(!$conSuspendidos, fn ($q) => $q->where('ud.status_internet_id', 1))
            ->get(['ud.user_id', 'ud.phone', 'o.ont_id']);

        foreach ($clientes as $c) {
            DB::table('fallas_sector_clientes')->insertOrIgnore([
                'falla_id' => $falla->id, 'user_id' => $c->user_id, 'telefono' => $c->phone,
                'ont' => "{$fsp}:{$c->ont_id}", 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function confirmarSiDura(object $falla, object $cfg): void
    {
        if ($falla->estado !== 'detectada' || now()->diffInSeconds($falla->empezo_en) < (int) $cfg->minutos_confirmacion * 60) {
            return;
        }

        $automatico = $cfg->modo === 'automatico';

        DB::table('fallas_sector')->where('id', $falla->id)->update([
            'estado' => $automatico ? 'activa' : 'por_aprobar', 'confirmada_en' => now(),
            'aprobada_en' => $automatico ? now() : null, 'updated_at' => now(),
        ]);

        if ($cfg->avisar_grupo) {
            $afectados = DB::table('fallas_sector_clientes')->where('falla_id', $falla->id)->count();
            $this->alGrupo("🔴 *Falla " . ($falla->fsp === self::TODA_LA_OLT ? 'masiva' : 'de sector') . "* · " . $this->nombreDelSector($falla, true) . "\n"
                . ($falla->fsp === self::TODA_LA_OLT
                    ? 'La OLT no responde desde las ' . \Carbon\Carbon::parse($falla->empezo_en)->format('g:i a') . ".\n"
                    : "{$falla->caidas} de {$falla->total} equipos caídos desde las " . \Carbon\Carbon::parse($falla->empezo_en)->format('g:i a') . ".\n")
                . ($automatico
                    ? ($cfg->avisar_inicio ? "Se les está avisando a {$afectados} clientes por WhatsApp." : 'El aviso a los clientes está apagado.')
                    : "Hay {$afectados} clientes afectados. Apruebe el aviso en *Red → Fallas de sector* (o descártela si es un mantenimiento)."));
        }
    }

    private function alVolver(object $falla, object $cfg, int $caidas, int $total): void
    {
        // Se recuperó antes de confirmarse: fue un parpadeo, no se le dice nada a nadie.
        if ($falla->estado === 'detectada') {
            DB::table('fallas_sector')->where('id', $falla->id)->update([
                'estado' => 'descartada', 'resuelta_en' => now(), 'caidas' => $caidas,
                'nota' => 'Se recuperó antes de confirmarse.', 'updated_at' => now(),
            ]);

            return;
        }

        if (!$falla->volvio_en) {
            DB::table('fallas_sector')->where('id', $falla->id)->update(['volvio_en' => now(), 'caidas' => $caidas, 'updated_at' => now()]);

            return;
        }

        if (now()->diffInSeconds($falla->volvio_en) >= (int) $cfg->minutos_resolucion * 60) {
            $this->resolver($falla, $cfg);
        }
    }

    private function resolver(object $falla, object $cfg, ?string $nota = null): void
    {
        DB::table('fallas_sector')->where('id', $falla->id)->update([
            'estado' => 'resuelta', 'resuelta_en' => now(), 'caidas' => 0, 'nota' => $nota ?? $falla->nota, 'updated_at' => now(),
        ]);

        // Los tickets que abrieron los clientes por esta falla ya no tienen nada que atender.
        $cerrados = $cfg->cerrar_tickets && $falla->estado !== 'detectada' ? self::cerrarTickets($this->companyId, $falla) : [];

        if ($cfg->avisar_grupo && $falla->estado !== 'detectada') {
            $duro = \Carbon\Carbon::parse($falla->empezo_en)->diffForHumans(now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]);
            $this->alGrupo("🟢 *Falla " . ($falla->fsp === self::TODA_LA_OLT ? 'masiva' : 'de sector') . " resuelta* · " . $this->nombreDelSector($falla, true) . "\nDuró {$duro}."
                . ($cerrados ? "\nSe cerraron " . count($cerrados) . ' ticket' . (count($cerrados) === 1 ? '' : 's') . ' asociados: #' . implode(', #', $cerrados) . '.' : ''));
        }
    }

    // ── Avisos a los clientes ────────────────────────────────────────────

    /** @return int mensajes enviados en esta pasada */
    public function enviarPendientes(?object $cfg = null): int
    {
        $cfg ??= self::config($this->companyId);

        if ($this->enSilencio($cfg)) {
            return 0;
        }

        $enviados = 0;

        if ($cfg->avisar_inicio) {
            $pendientes = DB::table('fallas_sector_clientes as c')->join('fallas_sector as f', 'f.id', '=', 'c.falla_id')
                ->where('f.company_id', $this->companyId)->where('f.estado', 'activa')->where('f.mantenimiento', 0)
                ->whereNull('c.avisado_inicio_en')->whereNull('c.error')
                ->get(['c.*', 'f.olt_id', 'f.fsp']);

            foreach ($pendientes as $p) {
                $enviados += $this->avisarA($p, 'inicio', $cfg) ? 1 : 0;
            }
        }

        if ($cfg->avisar_fin) {
            // Sólo a quien se le avisó la caída, y sólo de fallas de las últimas 12 horas.
            $pendientes = DB::table('fallas_sector_clientes as c')->join('fallas_sector as f', 'f.id', '=', 'c.falla_id')
                ->where('f.company_id', $this->companyId)->where('f.estado', 'resuelta')->where('f.resuelta_en', '>=', now()->subHours(12))
                ->whereNotNull('c.avisado_inicio_en')->whereNull('c.avisado_fin_en')
                ->get(['c.*', 'f.olt_id', 'f.fsp']);

            foreach ($pendientes as $p) {
                $enviados += $this->avisarA($p, 'fin', $cfg) ? 1 : 0;
            }
        }

        return $enviados;
    }

    private function avisarA(object $p, string $cual, object $cfg): bool
    {
        $cliente = DB::table('user_data')->where('user_id', $p->user_id)->first(['names', 'phone']);
        $telefono = preg_replace('/\D/', '', (string) ($cliente->phone ?? $p->telefono));
        $columna = $cual === 'inicio' ? 'avisado_inicio_en' : 'avisado_fin_en';

        if (strlen($telefono) < 10) {
            DB::table('fallas_sector_clientes')->where('id', $p->id)->update(['error' => 'Sin teléfono válido', 'updated_at' => now()]);

            return false;
        }

        $texto = $this->rellenar($cual === 'inicio' ? ($cfg->mensaje_inicio ?: self::MENSAJE_INICIO) : ($cfg->mensaje_fin ?: self::MENSAJE_FIN),
            (string) ($cliente->names ?? ''), (object) ['olt_id' => $p->olt_id, 'fsp' => $p->fsp]);

        try {
            $this->enviar($telefono, $texto, $cfg);
            DB::table('fallas_sector_clientes')->where('id', $p->id)->update([$columna => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('fallas_sector_clientes')->where('id', $p->id)->update(['error' => Str::limit($e->getMessage(), 240), 'updated_at' => now()]);
            Log::warning('[Fallas de sector] No se pudo avisar', ['user_id' => $p->user_id, 'error' => $e->getMessage()]);

            return false;
        }

        if ($this->enviarCon === null && (int) $cfg->segundos_entre_mensajes > 0) {
            sleep(min(30, (int) $cfg->segundos_entre_mensajes));
        }

        return true;
    }

    private function enviar(string $telefono, string $texto, object $cfg): void
    {
        if ($this->enviarCon) {
            ($this->enviarCon)($telefono, $texto);

            return;
        }

        $instancia = $cfg->wa_linea_id ? DB::table('wa_lineas')->where('id', $cfg->wa_linea_id)->value('instance_id') : null;
        $r = (new WhatsAppService($this->companyId, false, 'netplay', $instancia))->mensajeInformativo($telefono, $texto);

        if (($r['status'] ?? null) === 'error') {
            throw new \RuntimeException((string) ($r['message'] ?? 'WhatsApp no lo envió'));
        }
    }

    public function rellenar(string $plantilla, string $nombres, object $falla): string
    {
        $empresa = DB::table('companies')->where('id', $this->companyId)->first(['name', 'invoice_business_name']);
        $primer = Str::title(Str::lower(Str::before(trim($nombres) . ' ', ' ')));

        return strtr($plantilla, [
            '{cliente}' => $primer ?: 'cliente',
            '{sector}'  => $this->nombreDelSector($falla),
            '{empresa}' => (string) (($empresa->invoice_business_name ?? '') ?: ($empresa->name ?? '')),
        ]);
    }

    /** El nombre que la empresa le dio al puerto («Villa Katanga»), o «su zona». */
    public function nombreDelSector(object $falla, bool $paraElEquipo = false): string
    {
        if ((string) $falla->fsp === self::TODA_LA_OLT) {
            return $paraElEquipo ? 'toda la ' . (DB::table('olt_admins')->where('id', $falla->olt_id)->value('name') ?: 'OLT') : 'su zona';
        }

        $nombre = DB::table('fallas_sector_nombres')->where('olt_id', $falla->olt_id)->where('fsp', $falla->fsp)->value('nombre');

        if (!$paraElEquipo) {
            return $nombre ?: 'su zona';
        }

        $olt = DB::table('olt_admins')->where('id', $falla->olt_id)->value('name');

        return trim(($nombre ? "{$nombre} · " : '') . "puerto {$falla->fsp} de {$olt}");
    }

    private function enSilencio(object $cfg): bool
    {
        if (!$cfg->silencio_desde || !$cfg->silencio_hasta) {
            return false;
        }

        $ahora = now()->format('H:i');
        [$desde, $hasta] = [$cfg->silencio_desde, $cfg->silencio_hasta];

        return $desde <= $hasta ? ($ahora >= $desde && $ahora < $hasta) : ($ahora >= $desde || $ahora < $hasta);
    }

    private function alGrupo(string $texto): void
    {
        if ($this->enviarCon) {
            return;
        }

        try {
            (new AvisosAlGrupo($this->companyId))->avisar($texto);
        } catch (\Throwable $e) {
            Log::warning('[Fallas de sector] No se pudo avisar al grupo', ['error' => $e->getMessage()]);
        }
    }

    // ── Acciones desde el módulo ─────────────────────────────────────────

    public function aprobar(int $fallaId, int $porUsuario): bool
    {
        return DB::table('fallas_sector')->where('company_id', $this->companyId)->where('id', $fallaId)->where('estado', 'por_aprobar')
            ->update(['estado' => 'activa', 'aprobada_en' => now(), 'aprobada_por' => $porUsuario, 'updated_at' => now()]) > 0;
    }

    /** Mantenimiento programado o falsa alarma: no se le escribe a nadie (ni al volver). */
    public function descartar(int $fallaId, int $porUsuario, ?string $nota): bool
    {
        return DB::table('fallas_sector')->where('company_id', $this->companyId)->where('id', $fallaId)->whereIn('estado', self::ABIERTAS)
            ->update(['estado' => 'descartada', 'mantenimiento' => 1, 'resuelta_en' => now(), 'aprobada_por' => $porUsuario,
                'nota' => Str::limit((string) ($nota ?: 'Descartada desde el módulo'), 240), 'updated_at' => now()]) > 0;
    }

    /** La dan por arreglada a mano: se cierra y, si estaba avisada, se manda el «ya volvió». */
    public function resolverAMano(int $fallaId): bool
    {
        $falla = DB::table('fallas_sector')->where('company_id', $this->companyId)->where('id', $fallaId)->whereIn('estado', ['por_aprobar', 'activa'])->first();

        if (!$falla) {
            return false;
        }

        $this->resolver($falla, self::config($this->companyId), 'Cerrada a mano desde el módulo.');

        return true;
    }

    // ── Para el asistente de soporte ─────────────────────────────────────

    /** La falla abierta (confirmada) que tiene a este cliente sin servicio, si hay. */
    public static function delCliente(int $companyId, int $userId): ?object
    {
        $falla = DB::table('fallas_sector as f')->join('fallas_sector_clientes as c', 'c.falla_id', '=', 'f.id')
            ->where('f.company_id', $companyId)->where('c.user_id', $userId)->whereIn('f.estado', ['por_aprobar', 'activa'])
            ->orderByDesc('f.id')->first(['f.*']);

        if ($falla) {
            return $falla;
        }

        // La de toda la OLT del cliente, aunque no esté anotado (la abrieron los tickets de otros).
        $olts = self::oltsDelCliente($companyId, $userId);

        return $olts ? DB::table('fallas_sector')->where('company_id', $companyId)->where('fsp', self::TODA_LA_OLT)
            ->whereIn('olt_id', $olts)->whereIn('estado', ['por_aprobar', 'activa'])->orderByDesc('id')->first() : null;
    }

    /** @return list<int> */
    private static function oltsDelCliente(int $companyId, int $userId): array
    {
        // olt_onts.user_data_id es el id de la ficha, no el de «users».
        return DB::table('olt_onts as o')->join('user_data as ud', 'ud.id', '=', 'o.user_data_id')
            ->where('ud.company_id', $companyId)->where('ud.user_id', $userId)->distinct()->pluck('o.olt_id')->map(fn ($v) => (int) $v)->all();
    }

    // ── Fallas masivas y tickets ─────────────────────────────────────────

    /**
     * Al crear un ticket: si el cliente está en una falla abierta, el ticket queda atado a
     * ella. Si no, se mira si es el que completa una racha de reportes de la misma OLT (varios
     * clientes distintos en pocos minutos): eso es una masiva y se abre, con todos atados.
     *
     * @return int|null el id de la falla a la que quedó atado
     */
    public static function alCrearTicket(int $companyId, int $ticketId): ?int
    {
        try {
            $t = DB::table('tickets')->where('company_id', $companyId)->where('id', $ticketId)->first(['id', 'user_id', 'falla_id', 'status_id']);
            $cfg = self::config($companyId);

            if (!$t || !$t->user_id || $t->falla_id || (int) $t->status_id === 3 || !$cfg->activo) {
                return null;
            }

            $falla = self::delCliente($companyId, (int) $t->user_id) ?? (new self($companyId))->masivaPorTickets((int) $t->user_id, $cfg);

            if (!$falla) {
                return null;
            }

            self::atar($companyId, $falla, [(int) $t->id]);

            return (int) $falla->id;
        } catch (\Throwable $e) {
            Log::warning('[Fallas de sector] No se pudo revisar el ticket contra las fallas', ['ticket' => $ticketId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Abre la masiva de la OLT del cliente si hay racha de reportes; null si no la hay. */
    private function masivaPorTickets(int $userId, object $cfg): ?object
    {
        foreach (self::oltsDelCliente($this->companyId, $userId) as $oltId) {
            $recientes = DB::table('tickets as t')
                ->join('user_data as ud', fn ($j) => $j->on('ud.user_id', '=', 't.user_id')->on('ud.company_id', '=', 't.company_id'))
                ->join('olt_onts as o', 'o.user_data_id', '=', 'ud.id')
                ->where('t.company_id', $this->companyId)->where('o.olt_id', $oltId)
                ->whereIn('t.status_id', [1, 2])->whereNull('t.falla_id')->whereIn('t.source', self::FUENTES_DE_CLIENTES)
                ->where('t.created_at', '>=', now()->subMinutes(max(5, (int) $cfg->minutos_ventana_tickets)))
                ->distinct()->get(['t.id', 't.user_id', 't.created_at']);

            if ($recientes->unique('user_id')->count() < max(2, (int) $cfg->tickets_para_masiva)) {
                continue;
            }

            $automatico = $cfg->modo === 'automatico';
            $total = DB::table('olt_onts')->where('olt_id', $oltId)->count();
            $id = DB::table('fallas_sector')->insertGetId([
                'company_id' => $this->companyId, 'olt_id' => $oltId, 'fsp' => self::TODA_LA_OLT, 'origen' => 'tickets',
                'estado' => $automatico ? 'activa' : 'por_aprobar', 'caidas' => $recientes->unique('user_id')->count(), 'maximo_caidas' => $recientes->unique('user_id')->count(),
                'total' => $total, 'empezo_en' => $recientes->min('created_at'), 'confirmada_en' => now(), 'aprobada_en' => $automatico ? now() : null,
                'nota' => $recientes->unique('user_id')->count() . ' clientes reportaron estar sin servicio en ' . (int) $cfg->minutos_ventana_tickets . ' minutos.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $falla = DB::table('fallas_sector')->find($id);
            self::atar($this->companyId, $falla, $recientes->pluck('id')->map(fn ($v) => (int) $v)->all());

            if ($cfg->avisar_grupo) {
                $this->alGrupo('🔴 *Falla masiva* · ' . $this->nombreDelSector($falla, true) . "\n"
                    . $falla->nota . "\nTickets: #" . $recientes->pluck('id')->implode(', #') . ".\n"
                    . 'Se cierran solos cuando la falla se resuelva.'
                    . ($automatico ? '' : ' Apruebe el aviso a los clientes en *Red → Fallas por sector* (o descártela si no es una masiva).'));
            }

            return $falla;
        }

        return null;
    }

    /** Ata los tickets a la falla, con su nota, y anota a sus clientes como afectados. */
    public static function atar(int $companyId, object $falla, array $ticketIds): void
    {
        $sector = (new self($companyId))->nombreDelSector($falla, true);

        foreach (DB::table('tickets')->where('company_id', $companyId)->whereIn('id', $ticketIds)->whereNull('falla_id')->get(['id', 'user_id', 'technical_id']) as $t) {
            DB::table('tickets')->where('id', $t->id)->update(['falla_id' => $falla->id, 'updated_at' => now()]);
            self::nota($companyId, $t, "🔴 Asociado a la falla masiva #{$falla->id} ({$sector}). Se cierra solo cuando la falla se resuelva.");

            DB::table('fallas_sector_clientes')->insertOrIgnore([
                'falla_id' => $falla->id, 'user_id' => $t->user_id,
                'telefono' => DB::table('user_data')->where('company_id', $companyId)->where('user_id', $t->user_id)->value('phone'),
                'ont' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Cierra los tickets abiertos de la falla. @return list<int> los que se cerraron */
    public static function cerrarTickets(int $companyId, object $falla): array
    {
        $sector = (new self($companyId))->nombreDelSector($falla, true);
        $cerrados = [];

        foreach (DB::table('tickets')->where('company_id', $companyId)->where('falla_id', $falla->id)->whereIn('status_id', [1, 2])->get(['id', 'user_id', 'technical_id']) as $t) {
            DB::table('tickets')->where('id', $t->id)->update(['status_id' => 3, 'finished_at' => now(), 'closed_at' => now(), 'updated_at' => now()]);
            self::nota($companyId, $t, "✅ Cierre: se resolvió la falla masiva #{$falla->id} ({$sector}). Cerrado automáticamente.");
            $cerrados[] = (int) $t->id;
        }

        return $cerrados;
    }

    private static function nota(int $companyId, object $ticket, string $texto): void
    {
        // La nota pide un autor: el técnico del ticket o, si no tiene, un administrador de la empresa.
        $autor = $ticket->technical_id ?: DB::table('users as u')->join('profiles as p', 'p.id', '=', 'u.profile_id')
            ->where('u.company_id', $companyId)->whereRaw('UPPER(p.name) = ?', ['ADMIN'])->orderBy('u.id')->value('u.id');

        if (!$autor) {
            return;
        }

        DB::table('ticket_notes')->insert([
            'ticket_id' => $ticket->id, 'company_id' => $companyId, 'user_id' => $autor, 'note' => $texto,
            'photos' => null, 'audio_path' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** La OLT no contestó: si dura, es una falla de toda la OLT. */
    private function sinLectura(OltAdmin $olt, object $cfg): void
    {
        $total = DB::table('olt_onts')->where('olt_id', $olt->id)->count();

        if ($total < max(1, (int) $cfg->minimo_onts)) {
            return;
        }

        $falla = DB::table('fallas_sector')->where('company_id', $this->companyId)->where('olt_id', $olt->id)
            ->where('fsp', self::TODA_LA_OLT)->whereIn('estado', self::ABIERTAS)->first();

        if (!$falla) {
            $id = DB::table('fallas_sector')->insertGetId([
                'company_id' => $this->companyId, 'olt_id' => $olt->id, 'fsp' => self::TODA_LA_OLT, 'origen' => 'olt', 'estado' => 'detectada',
                'caidas' => $total, 'maximo_caidas' => $total, 'total' => $total, 'empezo_en' => now(),
                'nota' => 'La OLT no responde.', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $falla = DB::table('fallas_sector')->find($id);

            // Afectados: todos los clientes de la OLT (los que se avisan si la falla se aprueba).
            foreach (DB::table('olt_onts as o')->join('user_data as ud', 'ud.id', '=', 'o.user_data_id')->where('o.olt_id', $olt->id)
                ->where('ud.active', 1)->when(!$cfg->incluir_suspendidos, fn ($q) => $q->where('ud.status_internet_id', 1))
                ->get(['ud.user_id', 'ud.phone', 'o.fsp', 'o.ont_id'])->chunk(200) as $lote) {
                DB::table('fallas_sector_clientes')->insertOrIgnore($lote->map(fn ($c) => [
                    'falla_id' => $id, 'user_id' => $c->user_id, 'telefono' => $c->phone, 'ont' => "{$c->fsp}:{$c->ont_id}",
                    'created_at' => now(), 'updated_at' => now(),
                ])->values()->all());
            }
        } elseif ($falla->volvio_en) {
            DB::table('fallas_sector')->where('id', $falla->id)->update(['volvio_en' => null, 'updated_at' => now()]);
            $falla = DB::table('fallas_sector')->find($falla->id);
        }

        $this->confirmarSiDura($falla, $cfg);
    }

    /** ¿Ya están en línea los equipos de los clientes de los tickets, y no llegan reportes nuevos? */
    private function volvieronLosDeLosTickets(object $falla, array $estados): bool
    {
        if (DB::table('tickets')->where('falla_id', $falla->id)->where('created_at', '>=', now()->subMinutes(15))->exists()) {
            return false;
        }

        $onts = DB::table('tickets as t')
            ->join('user_data as ud', fn ($j) => $j->on('ud.user_id', '=', 't.user_id')->on('ud.company_id', '=', 't.company_id'))
            ->join('olt_onts as o', 'o.user_data_id', '=', 'ud.id')
            ->where('t.falla_id', $falla->id)->where('o.olt_id', $falla->olt_id)->get(['o.fsp', 'o.ont_id']);

        foreach ($onts as $o) {
            if (($estados["{$o->fsp}:{$o->ont_id}"]['cuenta'] ?? false) === true) {
                return false;
            }
        }

        return true;
    }
}
