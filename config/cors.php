<?php

return [

    'paths' => [
        'api/*',
        'broadcasting/auth',
        'sanctum/csrf-cookie'
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:4200',
        'https://netplay.com.co',
        'https://www.netplay.com.co',
        // Dominio nuevo (migración): los dos conviven mientras el viejo redirige.
        'https://netvula.com',
        'https://www.netvula.com',
    ],

    // El subdominio de cada empresa (netplay.netvula.com).
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.' . preg_quote(env('PLATAFORMA_DOMINIO', 'netvula.com'), '#') . '$#',
    ],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'Accept',
        'Origin'
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
