<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmMessage extends Model
{
    protected $table = 'crm_messages';

    public $timestamps = true; // 🔴 ESTA ES LA LÍNEA QUE FALTA

    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'sender_type',
        'message_type',
        'content',
        'media_url',     // 👈 🔥 ESTO FALTABA
        'external_id',
        'created_at',
        'updated_at',
    ];
}
