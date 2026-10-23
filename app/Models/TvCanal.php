<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un canal del panel y si está al aire, según la última revisión. */
class TvCanal extends Model
{
    protected $table = 'tv_canales';

    protected $fillable = [
        'company_id', 'stream_id', 'nombre', 'fuente', 'vigilar', 'estado', 'estado_desde', 'bitrate', 'espectadores', 'revisado_en', 'previa_en',
    ];

    protected $casts = [
        'vigilar'      => 'boolean',
        'estado_desde' => 'datetime',
        'revisado_en'  => 'datetime',
        'previa_en'    => 'datetime',
    ];
}
