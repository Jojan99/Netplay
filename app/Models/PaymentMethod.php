<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = ['company_id', 'name', 'active'];

    protected $casts = ['active' => 'boolean'];
}
