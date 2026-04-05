<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmNote extends Model
{
    protected $table = 'crm_notes';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'content',
    ];
}
