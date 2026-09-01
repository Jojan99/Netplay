<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'invoice_id',
        'file_path',
        'file_name',
        'file_hash',
        'reported_amount',
        'detected_amount',
        'payment_date',
        'reference_number',
        'bank_name',
        'ocr_text',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'raw_payload',
    ];

    protected $casts = [
        'reported_amount' => 'float',
        'detected_amount' => 'float',
        'payment_date' => 'date:Y-m-d',
        'raw_payload' => 'array',
        'reviewed_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(UserData::class, 'user_id');
    }

    public function invoice()
    {
        return $this->belongsTo(DetFacturation::class, 'invoice_id');
    }

    public function audits()
    {
        return $this->hasMany(PaymentProofAudit::class, 'payment_proof_id');
    }
}
