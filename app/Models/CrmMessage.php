<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmMessage extends Model
{
    protected $table = 'crm_messages';

    public $timestamps = true;

    protected $fillable = [
        'conversation_id',
        'sender_type',
        'content',
        'message_type',
    ];
}
