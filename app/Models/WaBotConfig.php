<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaBotConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'enabled',
        'trigger_word',
        'welcome_message',
        'menu_type',
        'menu_title',
        'options',
        'flows',
        'variables',
        'settings',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'options' => 'array',
        'flows' => 'array',
        'variables' => 'array',
        'settings' => 'array',
    ];
}
