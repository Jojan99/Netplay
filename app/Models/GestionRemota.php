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
        'acs_usuario', 'acs_clave', 'perfiles_acs',
        // Aprovisionamiento automático al autorizar (ver AprovisionamientoDeOnt).
        'aprovisionar', 'aprov_wan', 'aprov_wifi', 'aprov_admin', 'wifi_prefijo',
        'onu_admin_usuario', 'onu_admin_clave',
    ];

    /** Las claves no salen nunca en una respuesta. */
    protected $hidden = ['acs_clave', 'onu_admin_clave'];

    protected $casts = [
        'activa'      => 'boolean',
        'uplinks'     => 'array',
        'aplicada_en' => 'datetime',
        'acs_clave'   => 'encrypted',
        'perfiles_acs' => 'array',
        'aprovisionar' => 'boolean',
        'aprov_wan'    => 'boolean',
        'aprov_wifi'   => 'boolean',
        'aprov_admin'  => 'boolean',
        'onu_admin_clave' => 'encrypted',
    ];
}
