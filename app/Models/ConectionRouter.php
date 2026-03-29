<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConectionRouter extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'host',
        'user',
        'pass',
        'port',
    ];
}
