<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cómo quiere cada empresa que trabaje su asistente de soporte. */
class SoporteConfig extends Model
{
    protected $table = 'soporte_configs';

    protected $fillable = [
        'company_id', 'activa', 'canal_web', 'canal_meta', 'nombre_asistente', 'instrucciones',
        'permite_cambiar_clave', 'exige_telefono_registrado', 'permite_reiniciar', 'crea_tickets',
        'max_casos_dia', 'minutos_inactividad', 'palabras',
    ];

    protected $casts = [
        'activa' => 'boolean', 'canal_web' => 'boolean', 'canal_meta' => 'boolean',
        'permite_cambiar_clave' => 'boolean', 'exige_telefono_registrado' => 'boolean',
        'permite_reiniciar' => 'boolean', 'crea_tickets' => 'boolean',
        'max_casos_dia' => 'integer', 'minutos_inactividad' => 'integer',
    ];

    /** La de la empresa; si nunca la guardó, una apagada con los valores de fábrica. */
    public static function deEmpresa(int $companyId): self
    {
        return self::where('company_id', $companyId)->first() ?? new self([
            'company_id' => $companyId, 'activa' => false, 'canal_web' => true, 'canal_meta' => true,
            'nombre_asistente' => 'Asistente de soporte', 'permite_cambiar_clave' => true, 'exige_telefono_registrado' => true,
            'permite_reiniciar' => true, 'crea_tickets' => true, 'max_casos_dia' => 80, 'minutos_inactividad' => 30,
        ]);
    }

    public function atiende(string $provider): bool
    {
        return $this->activa && ($provider === 'meta' ? $this->canal_meta : $this->canal_web);
    }
}
