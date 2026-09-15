<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una ONT autorizada que espera, o ya recibió, su configuración por TR-069. */
class Aprovisionamiento extends Model
{
    protected $table = 'aprovisionamientos';

    protected $fillable = [
        'company_id', 'olt_id', 'fsp', 'ont_id', 'serial', 'user_id', 'datos',
        'wifi_clave', 'estado', 'acs_id', 'pasos', 'intentos', 'detalle', 'listo_en',
    ];

    protected $hidden = ['wifi_clave'];

    protected $casts = [
        'datos'      => 'array',
        'pasos'      => 'array',
        'wifi_clave' => 'encrypted',
        'listo_en'   => 'datetime',
    ];
}
