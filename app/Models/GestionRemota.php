<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El acceso remoto a los equipos de los clientes de una empresa. */
class GestionRemota extends Model
{
    protected $table = 'gestion_remota';

    protected $fillable = [
        'company_id', 'activa', 'vlan', 'red', 'gateway', 'pool_desde', 'pool_hasta',
        'router_id', 'interfaz', 'uplinks', 'aplicada_en', 'notas',
    ];

    protected $casts = [
        'activa'      => 'boolean',
        'uplinks'     => 'array',
        'aplicada_en' => 'datetime',
    ];
}
