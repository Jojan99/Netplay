<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A quién le tocó un envío, y cómo le fue. */
class WaCampaignRecipient extends Model
{
    protected $table = 'wa_campaign_recipients';

    protected $fillable = [
        'campaign_id', 'user_id', 'phone', 'name',
        'status', 'error', 'message_id', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public const PENDIENTE = 'pending';
    public const ENVIADO   = 'sent';
    public const FALLIDO   = 'failed';
}
