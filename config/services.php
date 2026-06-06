<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'whatsapp' => [
    'enabled'  => env('WHATSAPP_ENABLED', false),
    'token'    => env('ULTRAMSG_TOKEN'),
    'instance' => env('ULTRAMSG_INSTANCE'),

    
],

'netplay_whatsapp' => [
    'enabled'     => env('NETPLAY_WS_ENABLED', true),
    'api_key'     => env('NETPLAY_WS_API_KEY'),
    'instance_id' => env('NETPLAY_WS_INSTANCE_ID'),
    'base_url'    => env('NETPLAY_WS_URL', 'http://181.48.150.43:3001/crm'),
    'master_key'  => env('NETPLAY_WS_MASTER_KEY', 'netplay_master_2026_xK9pLmQr'),
],

'netplay_payments' => [
    'nequi_number' => env('NETPLAY_NEQUI_NUMBER', ''), // Ej: 3221234567
    'daviplata_number' => env('NETPLAY_DAVIPLATA_NUMBER', ''),
    'bank_account' => env('NETPLAY_BANK_ACCOUNT', ''),
],
'whatchimp' => [
    'token' => env('WATCHCHIMP_TOKEN'),
    'phone_id' => env('WATCHCHIMP_PHONE_ID'),
],

'mailjet' => [
    'api_key_public'  => env('MAILJET_APIKEY_PUBLIC'),
    'api_key_private' => env('MAILJET_APIKEY_PRIVATE'),
    'from_email'      => env('MAILJET_FROM_EMAIL', 'atencionalcliente@netplay.com.co'),
    'from_name'       => env('MAILJET_FROM_NAME', 'Netplay ISP'),
],



];
