<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentCommitment extends Model
{
    protected $fillable = [
        'company_id', 'cab_id', 'user_id',
        'commitment_date', 'amount_committed', 'notes',
        'auto_suspend', 'status', 'created_by',
        'fulfilled_at', 'suspended_at',
    ];

    protected $casts = [
        'commitment_date'  => 'date',
        'fulfilled_at'     => 'datetime',
        'suspended_at'     => 'datetime',
        'auto_suspend'     => 'boolean',
        'amount_committed' => 'float',
    ];

    /** Facturas específicas incluidas en este compromiso */
    public function invoices()
    {
        return $this->belongsToMany(
            DetFacturation::class,
            'payment_commitment_invoices',
            'commitment_id',
            'det_facturation_id'
        )->withTimestamps();
    }
}
