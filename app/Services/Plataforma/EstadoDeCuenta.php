<?php

namespace App\Services\Plataforma;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La cuenta de una empresa con Netvula, vista desde la empresa: si la prueba
 * está por vencer, si debe un pago, hasta cuándo tiene acceso y por qué quedó
 * suspendida. Y la revisión diaria que avisa primero y suspende después.
 *
 * La suspensión cierra el panel del operador y nada más: los clientes de la
 * empresa siguen con internet y con su portal.
 */
class EstadoDeCuenta
{
    public const MOTIVO_PRUEBA = 'Terminó el periodo de prueba y no se activó un plan.';
    public const MOTIVO_MORA   = 'Hay un pago pendiente de la suscripción.';

    /**
     * Lo que se le muestra a la empresa en el aviso del panel y en la pantalla
     * de cuenta suspendida.
     *
     * nivel: ok | aviso (falta poco) | urgente (ya venció, corre la gracia) | suspendida
     */
    public static function de(int $companyId): array
    {
        $empresa = DB::table('companies')->where('id', $companyId)
            ->first(['id', 'name', 'plataforma_suspendida', 'plataforma_suspendida_motivo']);
        $s = SuscripcionDeEmpresa::asegurar($companyId);

        $base = [
            'empresa'    => $empresa->name ?? null,
            'nivel'      => 'ok',
            'mensaje'    => null,
            'estado'     => $s->estado ?? null,
            'plan'       => $s && $s->plan_id ? DB::table('plataforma_planes')->where('id', $s->plan_id)->value('nombre') : null,
            'suspendida' => (bool) ($empresa->plataforma_suspendida ?? false),
            'motivo'     => $empresa->plataforma_suspendida_motivo ?? null,
            'prueba_hasta' => $s->prueba_hasta ?? null,
            'limite'     => null,
            'pendiente'  => 0.0,
            'vence'      => null,
            'soporte'    => self::soporte(),
            'complementos' => ['tr069' => ComplementoTr069::resumen($companyId)],
        ];

        if (!$empresa || !$s) {
            return $base;
        }

        $cobros = self::cobrosPendientes($companyId);
        $base['pendiente'] = $cobros['saldo'];
        $base['vence']     = $cobros['vence'];

        if ($base['suspendida']) {
            return ['nivel' => 'suspendida', 'mensaje' => 'El acceso de su empresa a la plataforma está suspendido.'] + $base;
        }

        $hoy   = now()->startOfDay();
        $aviso = (int) config('plataforma.suspension.aviso_dias', 5);

        // Vencida: corre la gracia. El límite sale del primer aviso; si la
        // revisión todavía no pasó, se cuenta desde hoy.
        if (self::vencida($s, $cobros)) {
            $limite = self::limite($s);
            $cierre = config('plataforma.suspension.automatica', true)
                ? ' El acceso al panel se suspende después del ' . self::fecha($limite) . '.'
                : '';

            $mensaje = $s->estado === 'prueba'
                ? 'Su periodo de prueba terminó el ' . self::fecha($s->prueba_hasta) . '. Escríbanos para activar un plan.' . $cierre
                : 'Tiene un pago pendiente de ' . self::pesos($cobros['saldo']) . ' que venció el ' . self::fecha($cobros['vence']) . '.' . $cierre;

            return ['nivel' => 'urgente', 'mensaje' => $mensaje, 'limite' => $limite] + $base;
        }

        // Falta poco para que venza la prueba.
        if ($s->estado === 'prueba' && $s->prueba_hasta && !$cobros['saldo']) {
            $dias = (int) $hoy->diffInDays(Carbon::parse($s->prueba_hasta)->startOfDay(), false);

            if ($dias >= 0 && $dias <= $aviso) {
                $cuando = $dias === 0 ? 'hoy' : ($dias === 1 ? 'mañana' : 'en ' . $dias . ' días');

                return ['nivel' => 'aviso', 'mensaje' => 'Su periodo de prueba termina ' . $cuando . ' (' . self::fecha($s->prueba_hasta) . '). Escríbanos para activar un plan y seguir sin interrupciones.'] + $base;
            }
        }

        // Hay un cobro por pagar que todavía no venció.
        if ($cobros['saldo'] > 0 && $cobros['vence']) {
            $dias = (int) $hoy->diffInDays(Carbon::parse($cobros['vence'])->startOfDay(), false);

            if ($dias >= 0 && $dias <= $aviso) {
                return ['nivel' => 'aviso', 'mensaje' => 'Tiene un pago de ' . self::pesos($cobros['saldo']) . ' que vence el ' . self::fecha($cobros['vence']) . '.'] + $base;
            }
        }

        // Usa el TR-069 y todavía no lo contrató: se le avisa antes de que se cierre.
        $tr069 = $base['complementos']['tr069'];

        if ($tr069['lo_usa'] && !$tr069['contratado'] && !$tr069['exigido'] && $tr069['desde']) {
            return ['nivel' => 'aviso', 'mensaje' => 'Desde el ' . self::fecha($tr069['desde']) . ', la gestión por TR-069 (WiFi, reinicio, consumo y configuración automática de la ONT) pasa a ser un complemento de ' . self::pesos($tr069['precio']) . ' al mes. Escríbanos para activarlo y no perderla.'] + $base;
        }

        return $base;
    }

    /**
     * La revisión diaria: marca moras, anota el primer aviso de cada vencida
     * y suspende a las que ya agotaron la gracia.
     *
     * @return array{avisadas: list<string>, suspendidas: list<string>, en_gracia: list<string>}
     */
    public static function revisar(): array
    {
        $resumen = ['avisadas' => [], 'suspendidas' => [], 'en_gracia' => []];

        if (!SuscripcionDeEmpresa::hayTablas()) {
            return $resumen;
        }

        SuscripcionDeEmpresa::asegurarTodas();
        FacturacionDeLaPlataforma::marcarMoras();

        $automatica = (bool) config('plataforma.suspension.automatica', true);
        $hoy = now()->startOfDay();

        $filas = DB::table('plataforma_suscripciones as s')
            ->join('companies as e', 'e.id', '=', 's.company_id')
            ->where('e.plataforma_suspendida', 0)
            ->whereIn('s.estado', ['prueba', 'en_mora'])
            ->get(['s.*', 'e.name as empresa']);

        foreach ($filas as $s) {
            $companyId = (int) $s->company_id;
            $cobros    = self::cobrosPendientes($companyId);

            if (!self::vencida($s, $cobros)) {
                // Se puso al día o le extendieron la prueba: el aviso viejo ya no cuenta.
                if ($s->aviso_vencimiento_en) {
                    DB::table('plataforma_suscripciones')->where('id', $s->id)->update(['aviso_vencimiento_en' => null, 'updated_at' => now()]);
                }
                continue;
            }

            if (!$s->aviso_vencimiento_en) {
                DB::table('plataforma_suscripciones')->where('id', $s->id)->update(['aviso_vencimiento_en' => now(), 'updated_at' => now()]);
                $resumen['avisadas'][] = $s->empresa;
                continue;
            }

            if (!$automatica || $hoy->lte(Carbon::parse(self::limite($s)))) {
                $resumen['en_gracia'][] = $s->empresa . ' (hasta ' . self::limite($s) . ')';
                continue;
            }

            $motivo = $s->estado === 'prueba' ? self::MOTIVO_PRUEBA : self::MOTIVO_MORA;

            DB::transaction(function () use ($s, $companyId, $motivo) {
                DB::table('companies')->where('id', $companyId)->update([
                    'plataforma_suspendida'        => 1,
                    'plataforma_suspendida_motivo' => $motivo,
                    'updated_at'                   => now(),
                ]);
                DB::table('plataforma_suscripciones')->where('id', $s->id)->update([
                    'estado'             => 'suspendida',
                    'suspendida_auto_en' => now(),
                    'updated_at'         => now(),
                ]);
            });

            Bitacora::anotar('empresa.suspendida', $companyId, ['motivo' => $motivo, 'automatica' => true], 'empresa', $companyId);
            $resumen['suspendidas'][] = $s->empresa;
        }

        return $resumen;
    }

    /**
     * Al saldarse un cobro: se olvida el aviso y, si la había suspendido la
     * revisión automática, la empresa vuelve a entrar sin esperar a nadie.
     */
    public static function alPonerseAlDia(int $companyId): void
    {
        $s = DB::table('plataforma_suscripciones')->where('company_id', $companyId)->first(['id', 'suspendida_auto_en']);

        if (!$s) {
            return;
        }

        if ($s->suspendida_auto_en) {
            DB::table('companies')->where('id', $companyId)->update([
                'plataforma_suspendida'        => 0,
                'plataforma_suspendida_motivo' => null,
                'updated_at'                   => now(),
            ]);
            Bitacora::anotar('empresa.reactivada', $companyId, ['automatica' => true, 'por' => 'pago registrado'], 'empresa', $companyId);

            if (AvisosDeCuentaPorCorreo::activos()) {
                AvisosDeCuentaPorCorreo::alReactivar($companyId);
            }
        }

        self::olvidarAviso($companyId);
    }

    /** Cuando una persona interviene desde la consola, la cuenta de la gracia arranca de nuevo. */
    public static function olvidarAviso(int $companyId): void
    {
        DB::table('plataforma_suscripciones')->where('company_id', $companyId)->update([
            'aviso_vencimiento_en' => null,
            'suspendida_auto_en'   => null,
            'updated_at'           => now(),
        ]);
    }

    /**
     * ¿Está vencida? Una prueba que ya pasó su fecha, salvo que tenga un cobro
     * emitido todavía dentro del plazo para pagar; o una suscripción en mora.
     */
    private static function vencida(object $s, array $cobros): bool
    {
        if ($s->estado === 'en_mora') {
            return true;
        }

        if ($s->estado !== 'prueba' || !$s->prueba_hasta) {
            return false;
        }

        if (Carbon::parse($s->prueba_hasta)->startOfDay()->gte(now()->startOfDay())) {
            return false;
        }

        return !($cobros['saldo'] > 0 && !$cobros['vencido']);
    }

    /** El último día con acceso: el primer aviso más los días de gracia. */
    private static function limite(object $s): string
    {
        $desde = $s->aviso_vencimiento_en ? Carbon::parse($s->aviso_vencimiento_en) : now();

        return $desde->startOfDay()->addDays((int) config('plataforma.suspension.gracia_dias', 5))->toDateString();
    }

    /** @return array{saldo: float, vence: ?string, vencido: bool} */
    private static function cobrosPendientes(int $companyId): array
    {
        $cobros = DB::table('plataforma_cobros')->where('company_id', $companyId)->where('estado', 'pendiente')
            ->orderBy('vence')->get(['total', 'pagado', 'vence']);

        $vence = $cobros->first()->vence ?? null;

        return [
            'saldo'   => round((float) $cobros->sum(fn ($c) => (float) $c->total - (float) $c->pagado), 2),
            'vence'   => $vence,
            'vencido' => $vence !== null && $vence < now()->toDateString(),
        ];
    }

    /** @return array{whatsapp: ?string, correo: ?string} */
    private static function soporte(): array
    {
        $wa = preg_replace('/\D/', '', (string) config('plataforma.soporte.whatsapp'));

        return [
            'whatsapp' => $wa ?: null,
            'correo'   => config('plataforma.soporte.correo') ?: null,
        ];
    }

    private static function fecha(?string $fecha): string
    {
        return $fecha ? Carbon::parse($fecha)->locale('es')->isoFormat('D [de] MMMM') : '';
    }

    private static function pesos(float $monto): string
    {
        return '$' . number_format($monto, 0, ',', '.');
    }
}
