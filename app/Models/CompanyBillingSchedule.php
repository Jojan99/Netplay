<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyBillingSchedule extends Model
{
    protected $fillable = [
        'company_id',
        'grupo',
        'billing_day',
        'billing_hour',
        'active',
    ];

    protected $casts = [
        'active'       => 'boolean',
        'billing_day'  => 'integer',
        'billing_hour' => 'integer',
        'grupo'        => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
