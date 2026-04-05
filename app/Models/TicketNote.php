<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketNote extends Model
{
    protected $fillable = [
        'ticket_id',
        'company_id',
        'user_id',
        'note',
        'photos',
        'audio_path',
    ];

    protected $casts = [
        'photos' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
