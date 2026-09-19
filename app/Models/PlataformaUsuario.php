<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una persona de Netvula, de las que entran a la consola.
 *
 * No es un `User`: no tiene empresa, no tiene perfil y no aparece en ningún
 * listado del panel. Se crea sólo con `php artisan consola:usuario`.
 */
class PlataformaUsuario extends Model
{
    protected $table = 'plataforma_usuarios';

    protected $fillable = ['nombre', 'email', 'password', 'activo'];

    /** El hash de la clave no sale nunca en un JSON. */
    protected $hidden = ['password'];

    protected $casts = [
        'activo'         => 'boolean',
        'ultimo_ingreso' => 'datetime',
        'created_at'     => 'datetime:Y-m-d H:i:s',
        'updated_at'     => 'datetime:Y-m-d H:i:s',
    ];
}
