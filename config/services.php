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

    // Servidor TR-069 (GenieACS). Su API (NBI) no tiene autenticación: debe
    // escuchar sólo en localhost y la plataforma la consume desde el backend.
    'genieacs' => [
        'nbi' => env('GENIEACS_NBI_URL', 'http://127.0.0.1:7557'),
        'cwmp_url' => env('GENIEACS_CWMP_URL', 'http://181.48.150.43:7547'),
        // La dirección que se les manda a los equipos desde la OLT. Con el
        // dominio, si el servidor cambia de IP basta con actualizar el DNS.
        'url_equipos' => env('GENIEACS_URL_EQUIPOS', 'http://netplay.com.co:7547'),
    ],

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

// El propio servidor: su IP pública y a dónde se entra por SSH para las
// consultas viejas de la OLT. Van en el .env para que cambiar de servidor no
// obligue a tocar el código. Los valores por defecto son los del servidor
// original (181.48.150.43), para que nada cambie donde el .env no los define.
'servidor' => [
    'ips'      => env('IPS_DEL_SERVIDOR', '181.48.150.43'),
    'ssh_host' => env('SERVIDOR_SSH_HOST', '181.48.150.43'),
],

'netplay_whatsapp' => [
    'enabled'     => env('NETPLAY_WS_ENABLED', true),
    'api_key'     => env('NETPLAY_WS_API_KEY'),
    'instance_id' => env('NETPLAY_WS_INSTANCE_ID'),
    // Corre en la misma máquina que Laravel.
    'base_url'    => env('NETPLAY_WS_URL', 'http://127.0.0.1:3001/crm'),
    // Sin valor por defecto a propósito: la clave sale del .env y no del código.
    'master_key'  => env('NETPLAY_WS_MASTER_KEY'),
],

'meta_whatsapp' => [
    'enabled'          => env('META_WS_ENABLED', false),
    'phone_number_id'  => env('META_WS_PHONE_NUMBER_ID'),
    'access_token'     => env('META_WS_ACCESS_TOKEN'),
    'business_id'      => env('META_WS_BUSINESS_ID'),
    'api_version'      => env('META_WS_API_VERSION', 'v18.0'),
    // App Secret de la app de Meta: con él se valida la firma HMAC de cada
    // webhook entrante. Mientras esté vacío el webhook se acepta sin verificar
    // y queda el aviso en el log, para no cortar mensajes antes de configurarlo.
    'app_secret'       => env('META_WS_APP_SECRET', ''),
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
