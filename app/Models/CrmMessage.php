<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmMessage extends Model
{
    protected $table = 'crm_messages';

    public $timestamps = true;

    protected $fillable = [
        'conversation_id',
        // La línea de WhatsApp Web por la que entró o salió el mensaje.
        'wa_linea_id',
        'sender_type',
        'content',
        'message_type',
        'sender_user_id',
        'media_url',
        'mime_type',
        'external_id',
        'status',
        'quoted_message_id',
        'is_forwarded',
        'forwarded_from_id',
        'agent_signature',
        'participant_phone',
        'participant_name',
    ];
}
