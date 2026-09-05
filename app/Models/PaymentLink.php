<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Link de pago compartible. No guarda el checkout de la pasarela: ese se genera
 * en el momento en que alguien abre el link, para que el monto sea siempre el
 * saldo vigente y no uno congelado al momento de enviarlo.
 */
class PaymentLink extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'token',
        'scope',
        'invoice_ids',
        'created_via',
        'expires_at',
        'max_uses',
        'used_count',
        'last_reference',
        'last_used_at',
    ];

    protected $casts = [
        'invoice_ids'  => 'array',
        'expires_at'   => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->used_count >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        return !$this->isExpired() && !$this->isExhausted();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
