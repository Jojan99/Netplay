<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;


class InternetPlan extends Authenticatable
{
    use HasFactory;
    protected $fillable = [
        'plan_name',
        'download_speed',
        'upload_speed',
        'monthly_price',
        'description'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'active' => 'boolean'
    ];
}
