<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cómo cobra el asistente de cartera de una empresa. */
class CobranzaConfig extends Model
{
    protected $table = 'cobranza_configs';

    protected $fillable = [
        'company_id', 'activa', 'modo',
        'min_facturas', 'min_dias_mora', 'max_dias_mora', 'min_monto',
        'descuento_max_pct', 'descuento_dias', 'cuotas_max', 'plazo_max_dias', 'compromiso_suspende',
        'hora_desde', 'hora_hasta', 'dias', 'max_contactos_dia', 'recordatorios', 'horas_entre_recordatorios',
        'nombre_asistente', 'instrucciones', 'wa_linea_id',
    ];

    protected $casts = [
        'activa'              => 'boolean',
        'compromiso_suspende' => 'boolean',
        'min_monto'           => 'float',
    ];

    public static function deEmpresa(int $companyId): self
    {
        return self::firstOrNew(['company_id' => $companyId]);
    }

    /** ¿Ahora es un momento en que se le puede escribir a un cliente? */
    public function enHorario(?\Carbon\Carbon $cuando = null): bool
    {
        $ahora = ($cuando ?? now())->copy()->timezone('America/Bogota');
        $dias = array_filter(array_map('intval', explode(',', (string) $this->dias)));

        if ($dias && !in_array((int) $ahora->dayOfWeekIso, $dias, true)) {
            return false;
        }

        $hora = $ahora->format('H:i');

        return $hora >= (string) $this->hora_desde && $hora < (string) $this->hora_hasta;
    }
}
