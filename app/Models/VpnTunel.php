<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VpnTunel extends Model
{
    protected $table = 'vpn_tuneles';

    protected $fillable = [
        'company_id', 'nombre', 'router_id',
        'clave_privada', 'clave_publica', 'clave_compartida',
        'ip_tunel', 'redes_remotas', 'puerto_router', 'keepalive',
        'activo', 'ultimo_saludo', 'bytes_rx', 'bytes_tx', 'aplicado_en', 'notas',
    ];

    protected $casts = [
        'redes_remotas'    => 'array',
        'clave_privada'    => 'encrypted',
        'clave_compartida' => 'encrypted',
        'activo'           => 'boolean',
        'ultimo_saludo'    => 'datetime',
        'aplicado_en'      => 'datetime',
        'puerto_router'    => 'integer',
        'keepalive'        => 'integer',
    ];

    /**
     * Las claves del router no se devuelven en los listados: sólo al momento
     * de entregar el script, que es la única vez que el operador las necesita.
     */
    protected $hidden = ['clave_privada', 'clave_compartida'];

    /** ¿Saludó hace poco? WireGuard renueva cada dos minutos como máximo. */
    public function getConectadoAttribute(): bool
    {
        return $this->ultimo_saludo !== null
            && $this->ultimo_saludo->gt(now()->subMinutes(3));
    }

    protected $appends = ['conectado'];
}
