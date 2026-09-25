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

    /** Ni el hash de la clave ni el secreto del authenticator salen en un JSON. */
    protected $hidden = ['password', 'totp_secreto', 'totp_recuperacion'];

    protected $casts = [
        'activo'         => 'boolean',
        'ultimo_ingreso' => 'datetime',
        // El secreto del authenticator va cifrado: quien lea la base no puede
        // generar códigos. Los de recuperación van con hash, que es más
        // fuerte: ni descifrándolos sirven.
        'totp_secreto'      => 'encrypted',
        'totp_recuperacion' => 'encrypted:array',
        'totp_activo_en'    => 'datetime',
        'created_at'     => 'datetime:Y-m-d H:i:s',
        'updated_at'     => 'datetime:Y-m-d H:i:s',
    ];

    /** ¿Tiene el authenticator confirmado y exigible? */
    public function tieneSegundoFactor(): bool
    {
        return $this->totp_activo_en !== null && !empty($this->totp_secreto);
    }
}
