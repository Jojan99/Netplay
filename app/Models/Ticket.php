<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'user_id',
        'address',
        'date',
        'service_id',
        'priority_id',
        'status_id',
        'technical_id',
        'observation',
        'cedula',
        'phone',
        'user_created_id',
        'started_at',
        'finished_at',

    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'datetime:Y-m-d H:i:s',  // Si 'date' es un datetime, lo formateamos
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
