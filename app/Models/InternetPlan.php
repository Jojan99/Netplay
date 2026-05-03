<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternetPlan extends Model
{
    use HasFactory;
    protected $fillable = [
        'company_id',
        'plan_name',
        'download_speed',
        'upload_speed',
        'monthly_price',
        'description',
        'type',
        'active',
    ];

    protected $casts = [
        'active'       => 'boolean',
        'monthly_price'=> 'float',
        'created_at'   => 'datetime:Y-m-d H:i:s',
        'updated_at'   => 'datetime:Y-m-d H:i:s',
    ];

    public function clients()
    {
        return $this->hasMany(UserData::class, 'internet_plans_id');
    }
}
