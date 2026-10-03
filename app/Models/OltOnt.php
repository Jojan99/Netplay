<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltOnt extends Model
{
    protected $table = 'olt_onts';

    protected $fillable = [
        'olt_id',
        'fsp',
        'ont_id',
        'serial',
        'description',
        'user_data_id',
        'status',
        'service_ports',
        'synced_at',
        // Cuándo se le dio acceso remoto. Sin estar aquí, update() lo
        // descartaba en silencio y el equipo seguía figurando como pendiente.
        'gestion_en',
    ];

    protected $casts = [
        'service_ports' => 'array',
        'synced_at'     => 'datetime',
        'gestion_en'    => 'datetime',
    ];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(OltAdmin::class, 'olt_id');
    }

    public function client(): BelongsTo
    {
        // olt_onts.user_data_id guarda el id de «user_data» (así lo escribe
        // InstalarYAprovisionar::hacer() y el resto del alta de OLT/inventario), no el de
        // «users». El comentario que estaba acá decía lo contrario y la relación cruzaba
        // contra user_data.user_id: cuando el user_data_id de una ONT coincidía de casualidad
        // con el users.id de otro cliente —dos contadores distintos—, esta pantalla mostraba
        // a ese otro cliente como dueño del equipo. Pasó de verdad: una ONT de un cliente de
        // prueba mostraba a un cliente real sin ninguna relación entre los dos.
        return $this->belongsTo(UserData::class, 'user_data_id', 'id');
    }
}
