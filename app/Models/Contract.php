<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    protected $fillable = [
        'company_id',
        'title',
        'content',
        'active',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function clientContracts()
    {
        return $this->hasMany(ClientContract::class);
    }
}
