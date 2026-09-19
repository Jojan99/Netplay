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
        // acs.netvula.com va en Cloudflare como "Solo DNS": el proxy no pasa el 7547.
        'cwmp_url' => env('GENIEACS_CWMP_URL', 'http://acs.netvula.com:7547'),
        // La dirección que se les manda a los equipos desde la OLT. Con el
        // dominio, si el servidor cambia de IP basta con actualizar el DNS.
        'url_equipos' => env('GENIEACS_URL_EQUIPOS', 'http://acs.netvula.com:7547'),
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
// obligue a tocar el código. Sin valores por defecto: eran la infraestructura
// de Netplay y las consultas viejas de la OLT están deshabilitadas.
'servidor' => [
    'ips'      => env('IPS_DEL_SERVIDOR', ''),
    'ssh_host' => env('SERVIDOR_SSH_HOST'),
],

'netplay_whatsapp' => [
    'enabled'     => env('NETPLAY_WS_ENABLED', true),
    'api_key'     => env('NETPLAY_WS_API_KEY'),
    'instance_id' => env('NETPLAY_WS_INSTANCE_ID'),
    // Corre en la misma máquina que Laravel.
    'base_url'    => env('NETPLAY_WS_URL', 'http://127.0.0.1:3001/crm'),
    // Sin valor por defecto a propósito: la clave sale del .env y no del código.
    'master_key'  => env('NETPLAY_WS_MASTER_KEY'),
    // Base del servicio Node (misma instancia de MySQL): de ahí sale el número de cada instancia.
    'db_name'     => env('NETPLAY_WS_DB_NAME', 'whatsapp_service'),
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
    // Token que Meta manda al verificar el webhook (hub.verify_token).
    'verify_token'     => env('META_WS_VERIFY_TOKEN', 'netplay_verify_token_2026'),
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
    'from_email'      => env('MAILJET_FROM_EMAIL', 'no-reply@netvula.com'),
    'from_name'       => env('MAILJET_FROM_NAME', 'Netvula'),
],




    /*
     * IA del asistente de cobranza. COBRANZA_IA elige el proveedor:
     *   compatible (por defecto): API con formato OpenAI. Sirve Google Gemini
     *     (plan gratis), Groq (gratis con límites), OpenRouter u OpenAI.
     *   anthropic: Claude (de pago), con ANTHROPIC_API_KEY.
     * Sin la clave del proveedor elegido el asistente queda apagado.
     */
    'cobranza_ia' => [
        'proveedor' => env('COBRANZA_IA', 'compatible'),
        'key'       => env('COBRANZA_IA_KEY'),
        'url'       => env('COBRANZA_IA_URL', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'),
        // Lista: si uno agota su cupo del día se usa el siguiente.
        'modelo'    => env('COBRANZA_IA_MODELO', 'gemini-3.5-flash-lite,gemini-3.1-flash-lite,gemini-flash-lite-latest,gemini-3.5-flash,gemini-3.6-flash'),
    ],

    'anthropic' => [
        'key'    => env('ANTHROPIC_API_KEY'),
        'modelo' => env('COBRANZA_MODELO', 'claude-sonnet-5'),
        'url'    => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1/messages'),
    ],

];
