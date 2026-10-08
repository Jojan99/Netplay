<?php

namespace App\Services\Clientes;

use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Services\AutoSuspendService;
use App\Services\Facturacion\DescuentoDelCliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El cliente pide suspender el internet por un tiempo (viaje, temporada, lo que sea).
 *
 *  - Al empezar se le cobran, prorrateados, los días que usó desde su último corte
 *    (precio ÷ 30 × días, como a un cliente nuevo): esos días ya no los cobra el
 *    corte, porque mientras esté suspendido no se le factura.
 *  - Se corta en el router y queda con «no reactivar automáticamente»: pagar una
 *    factura vieja no lo puede reactivar antes de tiempo.
 *  - El día que vuelve, si está al día se reactiva solo. Si debe, NO se reactiva:
 *    queda «requiere atención» y se abre una alerta crítica para que alguien lo llame.
 *  - El corte siguiente le cobra solo desde el día en que volvió (ver la facturación).
 *
 * Lo corre clientes:suspensiones-temporales cada hora.
 */
class SuspensionTemporal
{
    /** Más que esto ya no es una pausa: es un retiro. */
    public const MAXIMO_DE_DIAS = 180;

    public const ESTADOS_SIN_SERVICIO = ['activa', 'requiere_atencion'];

    /* ── Programar ────────────────────────────────────────────────────────── */

    /** @return array{ok:bool, mensaje:string, suspension?:object} */
    public function programar(int $companyId, int $userId, string $desde, string $hasta, string $motivo, ?int $autor): array
    {
        $cliente = DB::table('user_data')->where('company_id', $companyId)->where('user_id', $userId)->where('active', 1)
            ->first(['user_id', 'names', 'lastname', 'status_internet_id']);

        if (!$cliente) {
            return ['ok' => false, 'mensaje' => 'Cliente no encontrado o retirado.'];
        }

        try {
            $inicio = Carbon::parse($desde)->startOfDay();
            $fin = Carbon::parse($hasta)->startOfDay();
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'Fechas inválidas.'];
        }

        if ($inicio->lt(today())) {
            return ['ok' => false, 'mensaje' => 'La suspensión no puede empezar en una fecha pasada.'];
        }

        if ($fin->lte($inicio)) {
            return ['ok' => false, 'mensaje' => 'La fecha de regreso tiene que ser después de la de suspensión.'];
        }

        if ($inicio->diffInDays($fin) > self::MAXIMO_DE_DIAS) {
            return ['ok' => false, 'mensaje' => 'Una suspensión temporal no puede pasar de ' . self::MAXIMO_DE_DIAS . ' días.'];
        }

        if (trim($motivo) === '') {
            return ['ok' => false, 'mensaje' => 'Escriba el motivo de la suspensión.'];
        }

        if (DB::table('suspensiones_temporales')->where('company_id', $companyId)->where('user_id', $userId)
            ->whereIn('estado', ['programada', 'activa', 'requiere_atencion'])->exists()) {
            return ['ok' => false, 'mensaje' => 'El cliente ya tiene una suspensión temporal programada o en curso.'];
        }

        $id = DB::table('suspensiones_temporales')->insertGetId([
            'company_id' => $companyId, 'user_id' => $userId,
            'desde' => $inicio->toDateString(), 'hasta' => $fin->toDateString(),
            'motivo' => mb_substr(trim($motivo), 0, 255), 'estado' => 'programada', 'creado_por' => $autor,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->historial($companyId, $userId, $autor, 'suspension_temporal', null, $inicio->toDateString() . ' → ' . $fin->toDateString(),
            'Suspensión temporal programada: ' . trim($motivo));

        // Si empieza hoy, se aplica ya.
        if ($inicio->isToday()) {
            $r = $this->iniciar((int) $id);

            return ['ok' => $r['ok'], 'mensaje' => $r['mensaje'], 'suspension' => $this->de((int) $id)];
        }

        return ['ok' => true, 'mensaje' => "Suspensión programada del {$inicio->format('d/m/Y')} al {$fin->format('d/m/Y')}.", 'suspension' => $this->de((int) $id)];
    }

    /**
     * Lo que se le cobraría al suspender en esa fecha, sin hacer nada.
     *
     * @return array{dias:int, desde:string, precio:float, monto:float, descuento:float}
     */
    public function simular(int $companyId, int $userId, string $desde): array
    {
        return $this->prorrateo($companyId, $userId, Carbon::parse($desde)->startOfDay());
    }

    /* ── Empezar y terminar (lo corre el proceso de cada hora) ─────────────── */

    /** @return array{ok:bool, mensaje:string} */
    public function iniciar(int $id): array
    {
        $s = $this->de($id);

        if (!$s || $s->estado !== 'programada') {
            return ['ok' => false, 'mensaje' => 'La suspensión no está programada.'];
        }

        $desde = Carbon::parse($s->desde)->startOfDay();
        $cobro = $this->prorrateo((int) $s->company_id, (int) $s->user_id, $desde);
        $facturaId = null;

        // 1. La factura de los días usados (si usó alguno desde su último corte).
        if ($cobro['monto'] > 0) {
            $facturaId = $this->facturar((int) $s->company_id, (int) $s->user_id, $desde, $cobro);
        }

        // 2. Se corta: primero la plataforma (con «no reactivar automáticamente») y
        //    después el router, con la misma lógica del corte por mora.
        DB::table('user_data')->where('company_id', $s->company_id)->where('user_id', $s->user_id)
            ->update(['status_internet_id' => 2, 'STATUS' => 1, 'no_reactivar_auto' => 1]);

        $enRouter = app(AutoSuspendService::class)->aplicarEstadoDeLaPlataforma((int) $s->company_id, (int) $s->user_id);

        DB::table('suspensiones_temporales')->where('id', $id)->update([
            'estado' => 'activa', 'factura_id' => $facturaId, 'monto_prorrateo' => $cobro['monto'], 'dias_cobrados' => $cobro['dias'],
            'suspendida_en' => now(), 'updated_at' => now(),
            'nota' => $enRouter ? null : 'No se pudo cortar en el router: revíselo (Clientes → Revisar router).',
        ]);

        $this->registroDeCorte((int) $s->company_id, (int) $s->user_id, 'suspended', 'Suspensión temporal: ' . $s->motivo);
        $this->historial((int) $s->company_id, (int) $s->user_id, null, 'estado_internet', 'ACTIVE', 'INACTIVE',
            "Suspensión temporal hasta el " . Carbon::parse($s->hasta)->format('d/m/Y') . ($cobro['monto'] > 0
                ? '. Se facturaron ' . $cobro['dias'] . ' día(s) usados: $' . number_format($cobro['monto'], 0, ',', '.')
                : '. No había días por cobrar desde el último corte.'));

        return ['ok' => true, 'mensaje' => 'Suspendido hasta el ' . Carbon::parse($s->hasta)->format('d/m/Y')
            . ($cobro['monto'] > 0 ? '. Factura de ' . $cobro['dias'] . ' día(s): $' . number_format($cobro['monto'], 0, ',', '.') : '.')
            . ($enRouter ? '' : ' Ojo: no se pudo cortar en el router.')];
    }

    /**
     * El día de volver. Al día → se reactiva. Con deuda → no se reactiva y se avisa.
     *
     * @return array{ok:bool, mensaje:string}
     */
    public function terminar(int $id, ?int $autor = null): array
    {
        $s = $this->de($id);

        if (!$s || !in_array($s->estado, ['activa', 'requiere_atencion'], true)) {
            return ['ok' => false, 'mensaje' => 'La suspensión no está en curso.'];
        }

        $deuda = $this->deuda((int) $s->company_id, (int) $s->user_id);

        if ($deuda['facturas'] > 0) {
            DB::table('suspensiones_temporales')->where('id', $id)->update([
                'estado' => 'requiere_atencion', 'updated_at' => now(),
                'nota' => "Terminó la suspensión pero debe {$deuda['facturas']} factura(s) por $" . number_format($deuda['monto'], 0, ',', '.') . ': no se reactivó.',
            ]);

            return ['ok' => false, 'mensaje' => "No se reactivó: debe {$deuda['facturas']} factura(s) por $" . number_format($deuda['monto'], 0, ',', '.') . '.'];
        }

        DB::table('user_data')->where('company_id', $s->company_id)->where('user_id', $s->user_id)
            ->update(['status_internet_id' => 1, 'STATUS' => 0, 'no_reactivar_auto' => 0]);

        $enRouter = app(AutoSuspendService::class)->aplicarEstadoDeLaPlataforma((int) $s->company_id, (int) $s->user_id);

        DB::table('suspensiones_temporales')->where('id', $id)->update([
            'estado' => 'terminada', 'reactivada_en' => now(), 'updated_at' => now(),
            'nota' => $enRouter ? null : 'Se reactivó en la plataforma pero no en el router: revíselo (Clientes → Revisar router).',
        ]);

        $this->registroDeCorte((int) $s->company_id, (int) $s->user_id, 'reactivated', 'Fin de la suspensión temporal');
        $this->historial((int) $s->company_id, (int) $s->user_id, $autor, 'estado_internet', 'INACTIVE', 'ACTIVE', 'Fin de la suspensión temporal: reactivado.');

        try {
            app(\App\Services\WhatsApp\ServiceNotifier::class)->reactivados((int) $s->company_id, [(int) $s->user_id]);
        } catch (\Throwable $e) {
            // Reactivar es lo importante: el aviso por WhatsApp no lo puede tumbar.
        }

        return ['ok' => true, 'mensaje' => 'Reactivado.' . ($enRouter ? '' : ' Ojo: no se pudo habilitar en el router.')];
    }

    /** Cancelar una programada, o terminar antes una en curso. */
    public function cancelar(int $id, ?int $autor): array
    {
        $s = $this->de($id);

        if (!$s) {
            return ['ok' => false, 'mensaje' => 'Suspensión no encontrada.'];
        }

        if ($s->estado === 'programada') {
            DB::table('suspensiones_temporales')->where('id', $id)->update(['estado' => 'cancelada', 'updated_at' => now()]);
            $this->historial((int) $s->company_id, (int) $s->user_id, $autor, 'suspension_temporal', null, null, 'Suspensión temporal cancelada antes de empezar.');

            return ['ok' => true, 'mensaje' => 'Suspensión cancelada.'];
        }

        return $this->terminar($id, $autor);
    }

    /**
     * Lo de cada hora: empezar las que tocan, terminar las que tocan, y dar por
     * terminadas las que alguien reactivó a mano.
     *
     * @return array{iniciadas:int, terminadas:int, con_deuda:int}
     */
    public function revisar(): array
    {
        $r = ['iniciadas' => 0, 'terminadas' => 0, 'con_deuda' => 0];

        foreach (DB::table('suspensiones_temporales')->where('estado', 'programada')->whereDate('desde', '<=', today())->pluck('id') as $id) {
            $r['iniciadas'] += (int) $this->iniciar((int) $id)['ok'];
        }

        foreach (DB::table('suspensiones_temporales')->where('estado', 'activa')->whereDate('hasta', '<=', today())->pluck('id') as $id) {
            $this->terminar((int) $id)['ok'] ? $r['terminadas']++ : $r['con_deuda']++;
        }

        // Alguien lo reactivó a mano (pagó lo que debía, o se le adelantó la vuelta): la
        // suspensión termina ahí, y el corte le cobra desde ese día.
        $reactivadas = DB::table('suspensiones_temporales as s')
            ->join('user_data as u', fn ($j) => $j->on('u.user_id', '=', 's.user_id')->on('u.company_id', '=', 's.company_id'))
            ->whereIn('s.estado', self::ESTADOS_SIN_SERVICIO)
            ->where('u.status_internet_id', 1)
            ->pluck('s.id');

        foreach ($reactivadas as $id) {
            DB::table('suspensiones_temporales')->where('id', $id)->update([
                'estado' => 'terminada', 'reactivada_en' => now(), 'updated_at' => now(), 'nota' => 'Reactivado a mano.',
            ]);
        }

        return $r;
    }

    /* ── Para la facturación del corte ──────────────────────────────────────── */

    /**
     * Desde cuándo hay que cobrarle en este corte a quien volvió de una suspensión
     * temporal después del corte anterior. Null si no aplica.
     */
    public static function volvioDespuesDe(int $userId, \Carbon\CarbonInterface $corteAnterior): ?Carbon
    {
        $v = DB::table('suspensiones_temporales')->where('user_id', $userId)->where('estado', 'terminada')
            ->where('reactivada_en', '>', $corteAnterior)->max('reactivada_en');

        return $v ? Carbon::parse($v)->startOfDay() : null;
    }

    /* ── Interno ──────────────────────────────────────────────────────────── */

    public function de(int $id): ?object
    {
        return DB::table('suspensiones_temporales')->where('id', $id)->first();
    }

    /**
     * Días usados desde el último corte (o desde que entró, o desde que volvió de
     * otra suspensión) hasta $desde, y lo que valen.
     *
     * @return array{dias:int, desde:string, precio:float, monto:float, descuento:float}
     */
    private function prorrateo(int $companyId, int $userId, Carbon $desde): array
    {
        $c = DB::table('cab_facturations as cab')
            ->join('user_data as u', 'u.user_id', '=', 'cab.user_id')
            ->join('internet_plans as p', 'p.id', '=', 'u.internet_plans_id')
            ->where('cab.company_id', $companyId)->where('cab.user_id', $userId)
            ->first(['cab.id as cab_id', 'cab.group', 'cab.created_at', 'p.monthly_price',
                'u.descuento_tipo', 'u.descuento_valor', 'u.descuento_motivo', 'u.descuento_hasta']);

        if (!$c) {
            return ['dias' => 0, 'desde' => $desde->toDateString(), 'precio' => 0.0, 'monto' => 0.0, 'descuento' => 0.0];
        }

        $inicio = $this->ultimoCorte($companyId, (int) $c->group, $desde);

        // Lo que ya facturó el corte no se vuelve a cobrar: si la factura del mes ya salió
        // (por ejemplo, la suspensión empieza el mismo día del corte y el proceso de la
        // 1 a. m. ya corrió), se cuenta desde ella.
        $ultimaDelMes = DB::table('det_facturations')->where('cab_id', $c->cab_id)->where('create_facture_manual', 0)
            ->whereNull('anulada_en')->where('date_facturation', '<=', $desde->toDateString())->max('date_facturation');
        if ($ultimaDelMes && Carbon::parse($ultimaDelMes)->startOfDay()->gt($inicio)) {
            $inicio = Carbon::parse($ultimaDelMes)->startOfDay();
        }

        // Si entró o volvió de otra suspensión después del corte, se cuenta desde ahí.
        if ($c->created_at && Carbon::parse($c->created_at)->startOfDay()->gt($inicio)) {
            $inicio = Carbon::parse($c->created_at)->startOfDay();
        }
        if ($volvio = self::volvioDespuesDe($userId, $inicio)) {
            $inicio = $volvio;
        }

        $dias = $inicio->lt($desde) ? min(30, (int) $inicio->diffInDays($desde)) : 0;
        $precio = (float) $c->monthly_price;
        $bruto = round($precio / 30 * $dias);
        $trato = DescuentoDelCliente::paraFactura($c, $bruto);

        return [
            'dias' => $dias,
            'desde' => $inicio->toDateString(),
            'precio' => $precio,
            'monto' => (float) $bruto,
            'descuento' => (float) $trato['monto'],
            'trato' => $trato,
            'cab_id' => (int) $c->cab_id,
        ];
    }

    /** El último día de corte del grupo del cliente ANTES de $fecha (el de ese mismo día lo decide la factura que ya salió). */
    private function ultimoCorte(int $companyId, int $grupo, Carbon $fecha): Carbon
    {
        // Como lo busca el proceso de facturación (por número de grupo); si no, por id, que es
        // lo que exige la llave foránea de cab_facturations.group.
        $dia = (int) (DB::table('company_billing_schedules')->where('company_id', $companyId)->where('grupo', $grupo)->value('billing_day')
            ?: DB::table('company_billing_schedules')->where('company_id', $companyId)->where('id', $grupo)->value('billing_day'));

        if ($dia <= 0) {
            return $fecha->copy()->startOfMonth();
        }

        $corte = $fecha->copy()->startOfMonth()->day(min($dia, $fecha->daysInMonth));

        if ($corte->gte($fecha)) {
            $anterior = $fecha->copy()->subMonthNoOverflow()->startOfMonth();
            $corte = $anterior->day(min($dia, $anterior->daysInMonth));
        }

        return $corte->startOfDay();
    }

    /** Crea la factura de los días usados, con la numeración de siempre. */
    private function facturar(int $companyId, int $userId, Carbon $desde, array $cobro): ?int
    {
        $req = new CreateFacturationRequest();
        $req->merge([
            'cab_id' => $cobro['cab_id'],
            'date_facturation' => $desde->toDateString(),
            'date_create_facturation' => today()->toDateString(),
            'total' => 1,
            'price_total' => $cobro['monto'],
            'discount' => $cobro['descuento'] > 0 ? 1 : 0,
            'price_discount' => $cobro['descuento'],
            'porcentage_discount' => $cobro['trato']['porcentaje'] ?? 0,
            'days_facture' => $cobro['dias'],
            // Manual: no cuenta como «la factura del mes» y no frena la del corte.
            'create_facture_manual' => 1,
            'observacion' => "Suspensión temporal: {$cobro['dias']} día(s) usados del " . Carbon::parse($cobro['desde'])->format('d/m/Y')
                . ' al ' . $desde->copy()->subDay()->format('d/m/Y') . '.',
        ]);

        try {
            $det = app(\App\Repositories\FacturationRepository::class)->createDetFacturation($req);

            return $det?->id ? (int) $det->id : null;
        } catch (\Throwable $e) {
            Log::error('[Suspensión temporal] No se pudo crear la factura de los días usados', ['cliente' => $userId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Facturas sin pagar, con el mismo criterio con que el corte automático decide reactivar. */
    private function deuda(int $companyId, int $userId): array
    {
        $f = DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->where('cb.company_id', $companyId)->where('cb.user_id', $userId)
            ->where('df.paid', 0)->whereNull('df.anulada_en')->where('df.abone', '!=', 1)
            ->selectRaw('COUNT(*) n, COALESCE(SUM(df.price_total - COALESCE(df.price_discount, 0) - COALESCE(df.price_abone, 0)), 0) m')
            ->first();

        return ['facturas' => (int) $f->n, 'monto' => (float) $f->m];
    }

    private function registroDeCorte(int $companyId, int $userId, string $accion, string $detalle): void
    {
        DB::table('auto_suspend_logs')->insert([
            'company_id' => $companyId, 'user_id' => $userId, 'action' => $accion, 'motivo' => 'temporal',
            'detalle' => mb_substr($detalle, 0, 255), 'invoices_count' => 0, 'created_at' => now(),
        ]);
    }

    private function historial(int $companyId, int $userId, ?int $autor, string $campo, ?string $antes, ?string $ahora, string $descripcion): void
    {
        DB::table('user_audit_logs')->insert([
            'user_id' => $userId, 'changed_by' => $autor ?? 0, 'company_id' => $companyId,
            'field_changed' => $campo, 'old_value' => $antes, 'new_value' => $ahora,
            'description' => mb_substr($descripcion, 0, 255), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
