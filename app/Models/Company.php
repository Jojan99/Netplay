<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'nit',
        'email',
        'phone',
        'address',
        'logo',
        'active',
        'verification_token',
        'email_verified_at',
        'wa_company_id',
        'wa_api_key',
        'wa_instance_id',
        'whatsapp_enabled',
        // Invoice template config
        'invoice_business_name',
        'invoice_nit',
        'invoice_phone',
        'invoice_address',
        'invoice_city',
        'invoice_country',
        'invoice_iva_condition',
        'invoice_economic_activity',
        'invoice_payment_info',
        'invoice_footer',
        'invoice_logo_url',
        'invoice_logo_base64',
        // Pasarela de pago online
        'pg_gateway',
        'pg_sandbox',
        'pg_active',
        'pg_public_key',
        'pg_private_key',
        'pg_events_secret',
        'pg_integrity_secret',
        'pg_client_id',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'created_at'        => 'datetime:Y-m-d H:i:s',
        'updated_at'        => 'datetime:Y-m-d H:i:s',
        'pg_active'         => 'boolean',
        'pg_sandbox'        => 'boolean',
    ];
}
