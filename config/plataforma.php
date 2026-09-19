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
     * La consola de Netvula vive en su propia dirección.
     *
     * No es el panel de ninguna empresa: tiene sus propios usuarios, su propio
     * ingreso y su propio token. Fuera de este host sus rutas no existen (404),
     * y en este host no se sirve ni el panel de empresas ni el portal de
     * clientes. 'admin' ya está en la lista de reservados, así que ninguna
     * empresa lo puede tomar.
     *
     * Con CONSOLA_HOST vacío la consola queda apagada por completo.
     */
    'consola_host' => env('CONSOLA_HOST', 'admin.' . env('PLATAFORMA_DOMINIO', 'netvula.com')),

    /** Cuánto dura la sesión de la consola, en minutos. */
    'consola_minutos' => (int) env('CONSOLA_MINUTOS', 480),

    /**
     * Mientras el DNS comodín (*.netvula.com) y su certificado no estén listos,
     * nadie se manda a un subdominio: el login sigue entrando en la raíz y los
     * enlaces salen con APP_URL. Con esto en false lo único que cambia es que
     * cada empresa ya tiene reservado su subdominio.
     */
    'subdominios_activos' => (bool) env('SUBDOMINIOS_ACTIVOS', false),

    /**
     * Dominios propios de empresas: dominio => subdominio de la empresa.
     *
     * La empresa que entra por su propio dominio queda identificada igual que
     * por su subdominio, así su portal sólo recibe a sus clientes y muestra su
     * marca. El "www." se ignora.
     */
    'dominios_propios' => [
        'netplay.com.co' => 'netplay',
    ],

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
     * Todos los planes traen todas las funciones: sólo cambia cuántos clientes
     * activos caben. Precios en pesos colombianos, más IVA; null muestra
     * "Consultanos". El tope de clientes y la prueba todavía no se aplican en
     * el panel: son lo que ofrece la página.
     */
    'moneda' => 'COP',

    'prueba_dias' => 15,

    'nota_precios' => 'Precios mensuales en pesos colombianos, más IVA. Con pago anual, 2 meses gratis.',

    /** Lo que trae cualquier plan. */
    'incluye_todos' => [
        'Clientes, planes, contratos, instalaciones y traslados',
        'Facturación, cartera, abonos y cortes automáticos por mora',
        'Pasarela de pago en línea',
        'OLT Huawei y C-Data: autorizar ONT, señal, perfiles y VLAN',
        'MikroTik: PPPoE, colas y control de ancho de banda',
        'TR-069: WiFi, reinicio y consumo del equipo del cliente',
        'CRM de WhatsApp: bandeja del equipo, avisos y campañas',
        'Portal de clientes con tu nombre y tu logo',
        'Tickets, mapa de técnicos, inventario y empleados',
        'Alertas de caída de red al grupo de WhatsApp',
    ],

    'planes' => [
        [
            'clave'          => 'arranque',
            'nombre'         => 'Arranque',
            'para'           => 'ISP que empieza o se pasa de hojas de cálculo',
            'precio_mensual' => 79000,
            'precio_anual'   => 790000,
            'clientes'       => 300,
            'destacado'      => false,
            'incluye'        => [
                'Todas las funciones de la plataforma',
                'Soporte por WhatsApp y correo',
            ],
        ],
        [
            'clave'          => 'operador',
            'nombre'         => 'Operador',
            'para'           => 'Red FTTH con OLT y MikroTik en crecimiento',
            'precio_mensual' => 249000,
            'precio_anual'   => 2490000,
            'clientes'       => 1500,
            'destacado'      => true,
            'incluye'        => [
                'Todas las funciones de la plataforma',
                'Migración de tus clientes incluida',
                'Soporte prioritario por WhatsApp',
            ],
        ],
        [
            'clave'          => 'red',
            'nombre'         => 'Red completa',
            'para'           => 'Varias OLT, sedes y equipos de trabajo',
            'precio_mensual' => 499000,
            'precio_anual'   => 4990000,
            'clientes'       => null,
            'destacado'      => false,
            'incluye'        => [
                'Todas las funciones de la plataforma',
                'Migración de tus clientes incluida',
                'Acompañamiento en la puesta en marcha',
            ],
        ],
    ],
];
