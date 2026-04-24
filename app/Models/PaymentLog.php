<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentLog extends Model
{
    protected $fillable = [
        'company_id',
        'det_facturation_id',
        'cab_id',
        'number_facture',
        'client_name',
        'recorded_by_user_id',
        'amount',
        'type',
        'notes',
        'payment_method_id',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function paymentMethod()
    {
        return $this->belongsTo(\App\Models\PaymentMethod::class, 'payment_method_id');
    }
}
