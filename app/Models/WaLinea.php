<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una línea de WhatsApp Web de la empresa (una instancia del servicio Node).
 *
 * La api_key es de la empresa (companies.wa_api_key), no de la línea: todas las
 * instancias de una misma empresa comparten credencial y se distinguen por
 * instance_id.
 */
class WaLinea extends Model
{
    protected $table = 'wa_lineas';

    protected $fillable = [
        'company_id', 'instance_id', 'nombre', 'telefono',
        'estado', 'activa', 'principal', 'sincronizado_en',
    ];

    protected $casts = [
        'activa'          => 'boolean',
        'principal'       => 'boolean',
        'sincronizado_en' => 'datetime',
    ];
}
