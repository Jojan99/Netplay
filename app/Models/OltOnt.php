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
    ];

    protected $casts = [
        'service_ports' => 'array',
        'synced_at'     => 'datetime',
    ];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(OltAdmin::class, 'olt_id');
    }

    public function client(): BelongsTo
    {
        // user_data_id stores users.id (auth table), so owner key is user_id
        // Ojo con el nombre: olt_onts.user_data_id guarda el id de USUARIO
        // (users.id), no el de la ficha (user_data.id). Por eso la relación
        // cruza contra user_data.user_id. Todo lo que escribe o lee esta
        // columna tiene que usar el id de usuario.
        return $this->belongsTo(UserData::class, 'user_data_id', 'user_id');
    }
}
