<?php

use Illuminate\Database\Eloquent\Model;

class CrmConversationAssignment extends Model
{
    protected $table = 'crm_conversation_assignments';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'from_user_id',
        'to_user_id',
        'reason',
        'created_at'
    ];
}
