<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaBotSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'phone',
        'current_flow',
        'current_step',
        'data',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'expires_at' => 'datetime',
    ];
}
