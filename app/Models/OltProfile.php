<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OltProfile extends Model
{
    protected $fillable = ['olt_id', 'type', 'profile_id', 'profile_name', 'synced_at'];

    protected $casts = ['synced_at' => 'datetime'];
}
