<?php

/**
 * La plataforma como producto: el dominio base, el subdominio de cada empresa
 * y lo que se muestra en la página pública (planes y precios).
 */
return [

    /** netvula.com: la raíz es la página pública; cada empresa entra por empresa.netvula.com. */
    'dominio' => env('PLATAFORMA_DOMINIO', 'netvula.com'),

    'nombre' => env('PLATAFORMA_NOMBRE', 'Netvula'),

    /**
     * Mientras el DNS comodín (*.netvula.com) y su certificado no estén listos,
     * nadie se manda a un subdominio: el login sigue entrando en la raíz y los
     * enlaces salen con APP_URL. Con esto en false lo único que cambia es que
     * cada empresa ya tiene reservado su subdominio.
     */
    'subdominios_activos' => (bool) env('SUBDOMINIOS_ACTIVOS', false),

    /** Los que no puede tomar ninguna empresa: son de la plataforma o se prestan a engaño. */
    'reservados' => [
        'www', 'api', 'app', 'admin', 'administrador', 'panel', 'portal', 'login', 'registro', 'register',
        'mail', 'correo', 'webmail', 'smtp', 'imap', 'pop', 'ftp', 'ns', 'ns1', 'ns2', 'dns', 'mx',
        'acs', 'tr069', 'cwmp', 'genieacs', 'vpn', 'wg', 'wireguard', 'olt', 'mikrotik', 'radius',
        'soporte', 'ayuda', 'help', 'support', 'status', 'estado', 'blog', 'docs', 'cdn', 'static',
        'assets', 'media', 'storage', 'files', 'dev', 'test', 'pruebas', 'staging', 'demo', 'beta',
        'pagos', 'pay', 'factura', 'facturas', 'billing', 'cuenta', 'cuentas', 'seguridad', 'security',
        'netvula', 'cpanel', 'whm', 'root', 'sistema', 'system', 'm', 'movil', 'wa', 'whatsapp',
    ],

    /**
     * Planes que se muestran en netvula.com.
     *
     * precio_mensual en pesos colombianos; null muestra "Consultanos". Los
     * límites son informativos: todavía no se aplican en el panel.
     */
    'moneda' => 'COP',

    'planes' => [
        [
            'clave'          => 'arranque',
            'nombre'         => 'Arranque',
            'para'           => 'ISP que empieza o se pasa de hojas de cálculo',
            'precio_mensual' => null,
            'clientes'       => 300,
            'destacado'      => false,
            'incluye'        => [
                'Clientes, planes, contratos e instalaciones',
                'Facturación, cartera y reporte de pagos',
                'Portal de clientes con tu nombre y logo',
                'Tickets de soporte y mapa de técnicos',
                '1 línea de WhatsApp para avisos y cobros',
            ],
        ],
        [
            'clave'          => 'operador',
            'nombre'         => 'Operador',
            'para'           => 'Red FTTH con OLT y MikroTik en producción',
            'precio_mensual' => null,
            'clientes'       => 1500,
            'destacado'      => true,
            'incluye'        => [
                'Todo lo de Arranque',
                'OLT Huawei y C-Data: autorizar, señal y perfiles',
                'MikroTik: PPPoE, colas y cortes por mora',
                'TR-069: WiFi, reinicio y consumo del equipo del cliente',
                'CRM de WhatsApp con varias líneas y campañas',
                'Pasarela de pago en línea',
            ],
        ],
        [
            'clave'          => 'red',
            'nombre'         => 'Red completa',
            'para'           => 'Varias OLT, sedes y equipos de trabajo',
            'precio_mensual' => null,
            'clientes'       => null,
            'destacado'      => false,
            'incluye'        => [
                'Todo lo de Operador',
                'Clientes y OLT sin tope',
                'Servidor TR-069 propio o el de la plataforma',
                'Alertas de caída de red al grupo de WhatsApp',
                'Acompañamiento en la puesta en marcha',
            ],
        ],
    ],
];
