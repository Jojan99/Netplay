<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlinePaymentTransaction extends Model
{
    protected $fillable = [
        'company_id',
        'det_facturation_id',
        'invoice_ids',
        'reference',
        'gateway',
        'sandbox',
        'amount',
        'status',
        'customer_name',
        'customer_email',
        'gateway_transaction_id',
        'gateway_payload',
        'allocation_done',
        'initiated_at',
        'paid_at',
    ];

    protected $casts = [
        'sandbox'          => 'boolean',
        'allocation_done'  => 'boolean',
        'invoice_ids'      => 'array',
        'gateway_payload'  => 'array',
        'initiated_at'     => 'datetime:Y-m-d H:i:s',
        'paid_at'          => 'datetime:Y-m-d H:i:s',
        'created_at'       => 'datetime:Y-m-d H:i:s',
        'updated_at'       => 'datetime:Y-m-d H:i:s',
    ];

    public function invoice()
    {
        return $this->belongsTo(DetFacturation::class, 'det_facturation_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
