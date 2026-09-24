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
        'subdomain',
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
        'wa_provider',
        'wa_phone_number_id',
        'wa_business_id',
        'wa_access_token',
        'whatsapp_enabled',
        'invoice_whatsapp_enabled',
        'email_enabled',
        'email_daily_limit',
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
        'invoice_template_id',
        'invoice_prefix',
        // Pasarela de pago online
        'pg_gateway',
        'pg_sandbox',
        'pg_active',
        'pg_public_key',
        'pg_private_key',
        'pg_events_secret',
        'pg_integrity_secret',
        'pg_client_id',
        'pg_office_id',
        // OnePay: el token fijo de la cabecera del webhook y la plantilla de
        // WhatsApp con la que manda el cobro.
        'pg_webhook_token',
        'pg_template_id',
        // Correo propio con Mailjet (sin esto se usa la cuenta de la plataforma)
        'mailjet_activo',
        'mailjet_api_key',
        'mailjet_api_secret',
        'mailjet_from_email',
        'mailjet_from_name',
        'mailjet_verificado_en',
    ];

    // El secreto de Mailjet nunca sale en un JSON de la empresa.
    protected $hidden = [
        'mailjet_api_secret',
    ];

    protected $casts = [
        // Credenciales cifradas en reposo.
        //
        // Con esto un dump de la base, un backup viejo o un respaldo que se
        // filtre ya no entregan las llaves de cobro ni el token de WhatsApp
        // Business de cada empresa. En el código se siguen leyendo igual
        // ($company->pg_private_key): Eloquent descifra al vuelo, así que las
        // pasarelas y el panel funcionan sin cambios.
        'pg_private_key'      => 'encrypted',
        'pg_events_secret'    => 'encrypted',
        'pg_integrity_secret' => 'encrypted',
        'pg_webhook_token'    => 'encrypted',
        'wa_access_token'     => 'encrypted',
        'mailjet_api_secret'  => 'encrypted',

        'email_verified_at' => 'datetime',
        'created_at'        => 'datetime:Y-m-d H:i:s',
        'updated_at'        => 'datetime:Y-m-d H:i:s',
        'pg_active'         => 'boolean',
        'pg_sandbox'        => 'boolean',
        'email_enabled'             => 'boolean',
        'whatsapp_enabled'          => 'boolean',
        'invoice_whatsapp_enabled'  => 'boolean',
        'email_daily_limit'         => 'integer',
        'mailjet_activo'            => 'boolean',
        'mailjet_verificado_en'     => 'datetime',
    ];

    public function invoiceTemplate()
    {
        return $this->belongsTo(InvoiceTemplate::class);
    }
}
