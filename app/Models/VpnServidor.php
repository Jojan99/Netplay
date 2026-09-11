<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VpnServidor extends Model
{
    protected $table = 'vpn_servidor';

    protected $fillable = [
        'interfaz', 'endpoint_host', 'listen_port', 'subred',
        'ip_servidor', 'clave_privada', 'clave_publica', 'activo',
    ];

    protected $casts = [
        'clave_privada' => 'encrypted',
        'activo'        => 'boolean',
        'listen_port'   => 'integer',
    ];

    // La clave privada no sale nunca en una respuesta de la API.
    protected $hidden = ['clave_privada'];
}
