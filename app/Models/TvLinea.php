<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** La línea de TV de un cliente en el panel: el usuario y la clave con que ve los canales. */
class TvLinea extends Model
{
    protected $table = 'tv_lineas';

    protected $fillable = [
        'company_id', 'user_id', 'xui_id', 'usuario', 'clave', 'bouquets', 'pantallas', 'estado', 'origen',
        'vinculo', 'sugerido_user_id', 'sugerido_motivo', 'ultima_actividad_en', 'error',
    ];

    protected $casts = [
        'clave'               => 'encrypted',
        'bouquets'            => 'array',
        'ultima_actividad_en' => 'datetime',
    ];
}
