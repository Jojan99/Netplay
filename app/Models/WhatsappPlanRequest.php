<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappPlanRequest extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'status', 'notes', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
