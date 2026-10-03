<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una conversación de soporte que lleva el asistente por WhatsApp.
 *
 *   activo     el asistente está conversando
 *   esperando  dejó algo en marcha (habilitando el equipo para cambiarle la clave)
 *   resuelto   el cliente quedó bien
 *   escalado   lo pasó a una persona (con o sin ticket)
 *   humano     una persona de la empresa contestó y el asistente se retiró
 *   cerrado    se quedó sin respuesta
 */
class SoporteCaso extends Model
{
    use \App\Models\Concerns\FechasEnHoraLocal;

    protected $table = 'soporte_casos';

    public const ABIERTOS = ['activo', 'esperando'];

    protected $fillable = [
        'company_id', 'user_id', 'telefono', 'provider', 'wa_linea_id', 'conversation_id', 'estado', 'verificado',
        'resultado', 'resumen', 'historial', 'diagnostico', 'pendiente', 'ticket_id', 'ultimo_mensaje_id', 'pauso_bot',
        'consultas_ia', 'ultimo_mensaje_en', 'ultima_respuesta_en', 'cerrado_en',
    ];

    /** Lo pendiente puede llevar la clave nueva del cliente: nunca sale en una respuesta. */
    protected $hidden = ['pendiente', 'historial'];

    protected $casts = [
        'verificado' => 'boolean', 'pauso_bot' => 'boolean', 'historial' => 'array', 'diagnostico' => 'array',
        'pendiente' => 'encrypted:array',
        'ultimo_mensaje_en' => 'datetime', 'ultima_respuesta_en' => 'datetime', 'cerrado_en' => 'datetime',
    ];

    public static function abiertoCon(int $companyId, string $provider, string $telefono): ?self
    {
        return self::where('company_id', $companyId)->where('provider', $provider)->where('telefono', $telefono)
            ->whereIn('estado', self::ABIERTOS)->latest('id')->first();
    }

    public function abierto(): bool
    {
        return in_array($this->estado, self::ABIERTOS, true);
    }
}
