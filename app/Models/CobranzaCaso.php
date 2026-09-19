<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un cliente al que hay que cobrarle, y lo que pasó con él. */
class CobranzaCaso extends Model
{
    protected $table = 'cobranza_casos';

    /** Esperan que alguien decida (la burbuja del panel los cuenta). */
    public const PENDIENTES = ['detectado', 'escalado'];

    /**
     * El asistente está a cargo: lo que escriba el cliente lo contesta él.
     * Con acuerdo sigue atendiendo (el link otra vez, una duda) hasta que se
     * cierre el caso o alguien del equipo lo tome.
     */
    public const CONVERSANDO = ['contactado', 'negociando', 'acuerdo'];

    /** Abiertos: no se abre otro caso para el mismo cliente. */
    public const ABIERTOS = ['detectado', 'autorizado', 'contactado', 'negociando', 'acuerdo', 'escalado'];

    protected $fillable = [
        'company_id', 'user_id', 'estado', 'resultado', 'motivo', 'deuda', 'facturas', 'dias_mora',
        'telefono', 'conversation_id', 'wa_linea_id', 'autorizado_por', 'autorizado_en', 'contactado_en',
        'ultimo_mensaje_en', 'ultima_respuesta_en', 'recordatorios', 'compromisos', 'descuentos',
        'descuento_vence', 'resumen', 'historial', 'visto', 'consultas_ia', 'clave_ia',
    ];

    protected $casts = [
        'deuda'               => 'float',
        'compromisos'         => 'array',
        'descuentos'          => 'array',
        'historial'           => 'array',
        'visto'               => 'boolean',
        'autorizado_en'       => 'datetime',
        'contactado_en'       => 'datetime',
        'ultimo_mensaje_en'   => 'datetime',
        'ultima_respuesta_en' => 'datetime',
        'descuento_vence'     => 'datetime',
    ];

    /** El caso en el que el asistente le está hablando a ese teléfono, si hay. */
    public static function conversandoCon(int $companyId, string $telefono): ?self
    {
        return self::where('company_id', $companyId)
            ->where('telefono', $telefono)
            ->whereIn('estado', self::CONVERSANDO)
            ->latest('id')
            ->first();
    }
}
