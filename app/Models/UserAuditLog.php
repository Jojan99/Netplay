<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'changed_by',
        'company_id',
        'field_changed',
        'old_value',
        'new_value',
        'description',
    ];
}
