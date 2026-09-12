<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un aviso de la red, abierto mientras la situación dure. */
class Alerta extends Model
{
    protected $table = 'alertas';

    protected $fillable = [
        'company_id', 'clave', 'tipo', 'nivel', 'titulo', 'detalle', 'datos',
        'user_id', 'abierta_en', 'vista_en', 'cerrada_en',
    ];

    protected $casts = [
        'datos'      => 'array',
        'abierta_en' => 'datetime',
        'vista_en'   => 'datetime',
        'cerrada_en' => 'datetime',
    ];

    public function scopeAbiertas($q)
    {
        return $q->whereNull('cerrada_en');
    }
}
