<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProofAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_proof_id',
        'old_status',
        'new_status',
        'changed_by',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function paymentProof()
    {
        return $this->belongsTo(PaymentProof::class, 'payment_proof_id');
    }
}
