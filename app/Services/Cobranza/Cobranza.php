<?php

namespace App\Services\Cobranza;

use App\Models\CobranzaCaso;
use App\Models\CobranzaConfig;
use App\Models\PaymentCommitment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El ciclo de la cobranza de una empresa, cada pocos minutos:
 *   1. cierra lo que ya se pagó y devuelve los descuentos que vencieron;
 *   2. detecta clientes nuevos que cumplen las reglas (y, en modo automático,
 *      los deja autorizados);
 *   3. en horario, el asistente escribe a los autorizados (con tope diario) y
 *      recuerda a los que no contestaron.
 */
class Cobranza
{
    /** Tras cerrar o descartar un caso, cuánto esperar para volver a abrirlo. */
    private const DIAS_DE_CALMA = 7;

    /** Casos nuevos por revisión (cada 5 minutos). */
    private const NUEVOS_POR_VUELTA = 30;

    /** En modo manual, casos esperando que alguien los autorice o descarte. */
    private const MAX_EN_ESPERA = 40;

    private CobranzaConfig $cfg;

    public function __construct(private int $companyId)
    {
        $this->cfg = CobranzaConfig::deEmpresa($companyId);
    }

    /** @return array<string,int> lo que hizo, para el log y para "Revisar ahora". */
    public function revisar(): array
    {
        $hecho = ['cerrados' => 0, 'detectados' => 0, 'respondidos' => 0, 'contactados' => 0, 'recordados' => 0];

        if (!$this->cfg->exists || !$this->cfg->activa) {
            return $hecho;
        }

        $hecho['cerrados'] = $this->cerrarLoResuelto();
        $hecho['detectados'] = $this->detectar();

        // Lo que el cliente escribió y quedó sin respuesta (la IA estaba
        // ocupada) se contesta aunque sea fuera de horario: él escribió.
        if (Ia::disponible()) {
            $hecho['respondidos'] = $this->responderPendientes();
        }

        if ($this->cfg->enHorario() && Ia::disponible()) {
            $hecho['contactados'] = $this->contactarAutorizados();
            $hecho['recordados'] = $this->recordar();
        }

        return $hecho;
    }

    // ── 1. Lo resuelto ────────────────────────────────────────────────────

    private function cerrarLoResuelto(): int
    {
        $n = 0;

        foreach (CobranzaCaso::where('company_id', $this->companyId)->whereIn('estado', CobranzaCaso::ABIERTOS)->get() as $caso) {
            $deuda = Deuda::de($this->companyId, (int) $caso->user_id);

            if ($deuda->total() <= 0) {
                $conversando = in_array($caso->estado, CobranzaCaso::CONVERSANDO, true);
                $caso->fill(['estado' => 'pagado', 'resultado' => 'pagado', 'deuda' => 0, 'descuentos' => null, 'descuento_vence' => null, 'visto' => false])->save();

                if ($conversando && Ia::disponible()) {
                    try {
                        (new Asistente($caso))->agradecer();
                    } catch (\Throwable $e) {
                        Log::info('[Cobranza] No se pudo agradecer el pago', ['caso' => $caso->id, 'error' => $e->getMessage()]);
                    }
                }

                $n++;
                continue;
            }

            // Descuento vencido sin pagar: las facturas vuelven como estaban.
            if ($caso->descuentos && $caso->descuento_vence && $caso->descuento_vence->isPast()) {
                Herramientas::revertirDescuento($caso);
            }

            // Acuerdo cuyos compromisos ya no están vigentes y sigue debiendo:
            // se incumplió. Se cierra; tras la calma se vuelve a detectar.
            if ($caso->estado === 'acuerdo' && $caso->compromisos) {
                $ids = array_column((array) $caso->compromisos, 'id');
                $vigentes = PaymentCommitment::whereIn('id', $ids)->where('status', 'pending')->count();

                if ($vigentes === 0) {
                    $caso->fill(['estado' => 'cerrado', 'resultado' => 'compromiso_incumplido', 'visto' => false])->save();
                    $n++;
                }
            }

            // Sigue igual: se actualizan las cifras que ve el panel.
            $caso->fill(['deuda' => $deuda->total(), 'facturas' => $deuda->cantidad(), 'dias_mora' => $deuda->diasMora()])->save();
        }

        return $n;
    }

    // ── 2. Detectar ───────────────────────────────────────────────────────

    public function detectar(): int
    {
        $hoy = now('America/Bogota')->startOfDay();

        $candidatos = DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->join('user_data as ud', 'ud.user_id', '=', 'cab.user_id')
            ->where('cab.company_id', $this->companyId)
            ->where('ud.company_id', $this->companyId)
            ->where('ud.active', 1)
            ->where('d.paid', 0)
            ->whereRaw('(d.price_total - COALESCE(d.price_discount,0) - COALESCE(d.price_abone,0)) > 0')
            ->whereRaw("CHAR_LENGTH(REGEXP_REPLACE(COALESCE(ud.phone,''), '[^0-9]', '')) >= 10")
            ->groupBy('cab.user_id', 'ud.phone')
            ->havingRaw('COUNT(*) >= ?', [max(1, (int) $this->cfg->min_facturas)])
            ->havingRaw('SUM(d.price_total - COALESCE(d.price_discount,0) - COALESCE(d.price_abone,0)) >= ?', [(float) $this->cfg->min_monto])
            ->havingRaw('MIN(d.date_facturation) <= ?', [$hoy->copy()->subDays((int) $this->cfg->min_dias_mora)->toDateString()])
            ->when((int) $this->cfg->max_dias_mora > 0, fn ($q) => $q->havingRaw('MIN(d.date_facturation) >= ?', [$hoy->copy()->subDays((int) $this->cfg->max_dias_mora)->toDateString()]))
            ->select('cab.user_id', 'ud.phone',
                DB::raw('COUNT(*) as facturas'),
                DB::raw('SUM(d.price_total - COALESCE(d.price_discount,0) - COALESCE(d.price_abone,0)) as deuda'),
                DB::raw('MIN(d.date_facturation) as vieja'))
            ->orderByRaw('SUM(d.price_total - COALESCE(d.price_discount,0) - COALESCE(d.price_abone,0)) DESC')
            ->get();

        if ($candidatos->isEmpty()) {
            return 0;
        }

        $usuarios = $candidatos->pluck('user_id')->all();

        // Ya en un caso abierto, recién cerrado, o que pidió no ser contactado.
        $conCaso = CobranzaCaso::where('company_id', $this->companyId)->whereIn('user_id', $usuarios)
            ->where(fn ($q) => $q->whereIn('estado', CobranzaCaso::ABIERTOS)
                ->orWhere('updated_at', '>=', now()->subDays(self::DIAS_DE_CALMA))
                ->orWhereIn('resultado', ['no_contactar', 'numero_equivocado']))
            ->pluck('user_id')->all();

        // Con un compromiso de pago vigente ya hay un acuerdo: no se molesta.
        $conCompromiso = PaymentCommitment::where('company_id', $this->companyId)->whereIn('user_id', $usuarios)
            ->where('status', 'pending')->pluck('user_id')->all();

        $saltar = array_flip(array_merge($conCaso, $conCompromiso));
        $nuevos = 0;
        $auto = $this->cfg->modo === 'automatico';

        // Cuántos pueden esperar a la vez: en manual, lo que una persona
        // alcanza a revisar; en automático, dos días de envíos. Cuando se
        // atienden, entran los siguientes (los de más deuda primero).
        $enEspera = CobranzaCaso::where('company_id', $this->companyId)->where('estado', $auto ? 'autorizado' : 'detectado')->count();
        $cupo = min(self::NUEVOS_POR_VUELTA, max(0, ($auto ? 2 * (int) $this->cfg->max_contactos_dia : self::MAX_EN_ESPERA) - $enEspera));

        foreach ($candidatos as $c) {
            if (isset($saltar[$c->user_id])) {
                continue;
            }

            // Por vuelta, los de más deuda primero y con tope: la bandeja no se
            // llena de golpe con toda la cartera vieja.
            if ($nuevos >= $cupo) {
                break;
            }

            $dias = max(0, (int) \Carbon\Carbon::parse($c->vieja)->startOfDay()->diffInDays($hoy, false));

            CobranzaCaso::create([
                'company_id' => $this->companyId,
                'user_id'    => $c->user_id,
                'estado'     => $auto ? 'autorizado' : 'detectado',
                'motivo'     => "{$c->facturas} factura(s) sin pagar, la más vieja con {$dias} días.",
                'deuda'      => round((float) $c->deuda, 2),
                'facturas'   => (int) $c->facturas,
                'dias_mora'  => $dias,
                'telefono'   => self::telefono((string) $c->phone),
                'autorizado_en' => $auto ? now() : null,
                'visto'      => false,
            ]);
            $nuevos++;
        }

        return $nuevos;
    }

    // ── 3. Escribir ───────────────────────────────────────────────────────

    private function contactarAutorizados(): int
    {
        $hoy = CobranzaCaso::where('company_id', $this->companyId)
            ->where('contactado_en', '>=', now('America/Bogota')->startOfDay()->utc())->count();
        $cupo = max(0, (int) $this->cfg->max_contactos_dia - $hoy);
        $n = 0;

        foreach (CobranzaCaso::where('company_id', $this->companyId)->where('estado', 'autorizado')
                     ->orderByDesc('deuda')->limit($cupo)->get() as $caso) {
            if ($this->contactar($caso)) {
                $n++;
            }
        }

        return $n;
    }

    /** El asistente abre la conversación. */
    public function contactar(CobranzaCaso $caso): bool
    {
        if (!$caso->telefono) {
            $caso->fill(['estado' => 'escalado', 'motivo' => 'El cliente no tiene un teléfono válido en su ficha.'])->save();

            return false;
        }

        // El cliente contesta a un número que la empresa conoce: no se le pide
        // la cédula (la puerta de identificación lo deja pasar).
        $cliente = DB::table('user_data')->where('user_id', $caso->user_id)->first(['names', 'lastname', 'dni']);
        DB::table('crm_identificaciones')->updateOrInsert(
            ['company_id' => $this->companyId, 'provider' => 'netplay', 'phone' => $caso->telefono],
            ['estado' => 'identificado', 'dni' => $cliente->dni ?? null, 'user_id' => $caso->user_id,
                'nombre' => trim(($cliente->names ?? '') . ' ' . ($cliente->lastname ?? '')), 'updated_at' => now(), 'created_at' => now()]
        );

        try {
            if (!(new Asistente($caso))->iniciar()) {
                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('[Cobranza] No se pudo iniciar la conversación', ['caso' => $caso->id, 'error' => $e->getMessage()]);
            $caso->fill(['motivo' => mb_substr('No se pudo escribir: ' . $e->getMessage(), 0, 250)])->save();

            return false;
        }

        $caso->refresh();

        if ($caso->estado === 'autorizado') {
            $caso->fill(['estado' => 'contactado', 'contactado_en' => now()])->save();
        }

        // Un intento anterior que falló dejó su error: ya no aplica.
        if (str_starts_with((string) $caso->motivo, 'No se pudo escribir')) {
            $caso->fill(['motivo' => null])->save();
        }

        return true;
    }

    private function recordar(): int
    {
        $n = 0;
        $horas = max(1, (int) $this->cfg->horas_entre_recordatorios);

        foreach (CobranzaCaso::where('company_id', $this->companyId)->where('estado', 'contactado')
                     ->whereNull('ultima_respuesta_en')
                     ->where('ultimo_mensaje_en', '<=', now()->subHours($horas))->get() as $caso) {
            if ((int) $caso->recordatorios >= (int) $this->cfg->recordatorios) {
                $caso->fill(['estado' => 'sin_respuesta', 'resultado' => 'sin_respuesta', 'visto' => false])->save();
                continue;
            }

            try {
                if ((new Asistente($caso))->recordar()) {
                    $caso->increment('recordatorios');
                    $n++;
                }
            } catch (\Throwable $e) {
                Log::warning('[Cobranza] No se pudo recordar', ['caso' => $caso->id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    /**
     * Conversaciones donde lo último lo escribió el cliente hace más de dos
     * minutos y nadie le contestó. Si en media hora no se pudo, pasa a una
     * persona: no puede quedar esperando.
     */
    private function responderPendientes(): int
    {
        $n = 0;

        foreach (CobranzaCaso::where('company_id', $this->companyId)->whereIn('estado', CobranzaCaso::CONVERSANDO)
                     ->whereNotNull('conversation_id')->get() as $caso) {
            $ultimoCliente = DB::table('crm_messages')->where('conversation_id', $caso->conversation_id)
                ->where('sender_type', 'customer')->orderByDesc('id')->first(['id', 'created_at']);
            $ultimoNuestro = (int) DB::table('crm_messages')->where('conversation_id', $caso->conversation_id)
                ->where('sender_type', '!=', 'customer')->max('id');

            if (!$ultimoCliente || $ultimoCliente->id < $ultimoNuestro || \Carbon\Carbon::parse($ultimoCliente->created_at)->gt(now()->subMinutes(2))) {
                continue;
            }

            if (\Carbon\Carbon::parse($ultimoCliente->created_at)->lt(now()->subMinutes(30))) {
                $caso->fill(['estado' => 'escalado', 'motivo' => 'El cliente escribió y el asistente no pudo responder en 30 minutos (la IA no estaba disponible).', 'visto' => false])->save();
                continue;
            }

            (new \App\Jobs\ResponderCobranza((int) $caso->id, (int) $ultimoCliente->id, false))->handle();
            $n++;
        }

        return $n;
    }

    /** +57 300… → 57300…; 10 dígitos → se le antepone 57. */
    public static function telefono(string $crudo): ?string
    {
        $d = preg_replace('/\D+/', '', $crudo);

        if (strlen($d) === 10) {
            $d = '57' . $d;
        }

        return strlen($d) >= 11 ? $d : null;
    }
}
