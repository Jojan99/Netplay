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
     * Quién responde legalmente por la plataforma. Sale en la política de
     * tratamiento de datos y en los términos de netvula.com: la Ley 1581 y el
     * Decreto 1377 exigen que figuren la razón social, el domicilio y los
     * canales para ejercer los derechos. Lo que quede vacío no se muestra.
     *
     * 'version' es la de los textos legales: se guarda junto a la aceptación
     * de cada empresa al registrarse, como prueba de qué texto aceptó. Hay
     * que subirla cada vez que cambie el fondo de los términos o la política.
     */
    'legal' => [
        'razon_social' => env('PLATAFORMA_RAZON_SOCIAL', env('PLATAFORMA_NOMBRE', 'Netvula')),
        'nit'          => env('PLATAFORMA_NIT'),
        'direccion'    => env('PLATAFORMA_DIRECCION'),
        'ciudad'       => env('PLATAFORMA_CIUDAD'),
        'correo'       => env('PLATAFORMA_CORREO_DATOS'),
        'telefono'     => env('PLATAFORMA_TELEFONO'),
        'version'      => '2026-09-30',
        'actualizado'  => '30 de septiembre de 2026',
    ],

    /**
     * Qué pasa cuando a una empresa se le vence la prueba o un cobro.
     *
     * La revisión diaria (plataforma:revisar-suscripciones) avisa primero y
     * suspende después: el panel muestra el aviso desde 'aviso_dias' antes del
     * vencimiento, y el acceso se cierra 'gracia_dias' después del primer aviso
     * de vencida. Con 'automatica' en false sólo avisa: suspender vuelve a ser
     * una decisión manual desde la consola.
     */
    'suspension' => [
        'automatica'  => (bool) env('PLATAFORMA_SUSPENSION_AUTOMATICA', true),
        'aviso_dias'  => (int) env('PLATAFORMA_AVISO_DIAS', 5),
        'gracia_dias' => (int) env('PLATAFORMA_GRACIA_DIAS', 5),
    ],

    /**
     * Funciones que se contratan aparte del plan.
     *
     * TR-069: gestión remota del equipo del cliente y configuración
     * automática de la ONT. Se cobra por 'tramos' [hasta cuántos equipos,
     * precio mensual], según los equipos de la empresa que reportan al ACS;
     * por encima del último tramo se pacta el precio en la suscripción.
     * 'desde' es el día en que empieza a exigirse: antes, todo sigue abierto
     * y a quien lo usa se le avisa. Son los 30 días de aviso de cambio de
     * precio que prometen los términos.
     */
    'complementos' => [
        'tr069' => [
            'tramos' => [
                [500,  59900],
                [1000, 89900],
                [2000, 179000],
            ],
            'desde'  => env('PLATAFORMA_TR069_DESDE', '2026-10-30'),
            // Precio fijo del complemento según el plan (clave de plataforma_planes), sin importar
            // los equipos. 0 = incluido en el plan. Decidido el 2026-10-02 para quedar parejos con
            // WispHub + SmartOLT de 1.500 clientes en adelante.
            'por_plan' => [
                'operador-plus' => 89900,
                'red'           => 0,
            ],
        ],
    ],

    /**
     * El comparativo de precios de la página pública.
     *
     * Publicidad comparativa: es legal mientras sea cierta, comprobable y de
     * cosas equivalentes (Ley 256 de 1996, art. 13). Por eso cada cifra sale
     * de la página oficial de precios del proveedor, va con su fecha y su
     * fuente, y sólo se usan nombres, nunca logos. Los precios ajenos cambian:
     * HAY QUE REVISARLO cada mes y actualizar 'fecha', 'trm' y 'usd'. Con
     * 'activo' en false la sección desaparece de la página.
     *
     * 'usd' es el precio de lista mensual, sin impuestos, para cada tamaño de
     * 'tamanos', sumando lo necesario para cubrir gestión del ISP y de la OLT:
     *   WispHub: Básico 20 (hasta 200), Profesional 50 (hasta 800) o Enterprise 80 (sin tope)
     *   Mikrowisp: Premium 40 (200), Gold I 54 (300), Platinum II 85 (1.000) o Ilimitada 120
     *   AdminOLT Pro y SmartOLT: 25 por cada OLT
     * El precio de Netvula no se escribe aquí: sale de los planes vigentes.
     */
    'comparativo' => [
        'activo'      => (bool) env('PLATAFORMA_COMPARATIVO', true),
        'fecha_texto' => '30 de septiembre de 2026',
        'trm'         => 3341.23,
        'tamanos'     => [
            // El plan más chico de ellos (200) contra el más chico nuestro (300): la comparación no empieza
            // justo encima de su corte, donde la diferencia se vería inflada.
            ['clientes' => 200,  'olts' => 1],
            ['clientes' => 300,  'olts' => 1],
            ['clientes' => 900,  'olts' => 2],
            ['clientes' => 1500, 'olts' => 2],
            ['clientes' => 3000, 'olts' => 4],
        ],
        'alternativas' => [
            ['nombre' => 'WispHub + AdminOLT',   'detalle' => 'Gestión del ISP y gestor de OLT, contratados por separado', 'usd' => [45, 75, 130, 130, 180]],
            ['nombre' => 'Mikrowisp + SmartOLT', 'detalle' => 'Gestión del ISP y gestor de OLT, contratados por separado', 'usd' => [65, 79, 135, 170, 220]],
        ],
        // El complemento TR-069 frente al de quien lo vende aparte, por los mismos tramos de
        // 'complementos.tr069.tramos' (500 / 1.000 / 2.000 equipos). AdminOLT: USD 50 / 70 / 100.
        'tr069' => [
            ['nombre' => 'AdminOLT', 'detalle' => 'Complemento TR-069, aparte del cobro por OLT', 'usd' => [50, 70, 100]],
        ],
        'fuentes' => [
            ['nombre' => 'WispHub',   'url' => 'https://wisphub.net/precios/'],
            ['nombre' => 'AdminOLT',  'url' => 'https://adminolt.com/precios/'],
            ['nombre' => 'Mikrowisp', 'url' => 'https://mikrowisp.net/clientes/index.php/store/mikrowisp-manager'],
            ['nombre' => 'SmartOLT',  'url' => 'https://www.smartolt.com/'],
        ],
    ],

    /** A dónde escribe una empresa para pagar o reactivar su cuenta. */
    /**
     * Los correos de cobro a las empresas: recordatorio antes de vencer, aviso de vencido con la
     * fecha límite, último aviso, cuenta suspendida y cuenta reactivada. Salen de la revisión
     * diaria (plataforma:revisar-suscripciones). Apagados, la revisión sólo dice a quién le
     * escribiría. 'copia' recibe una copia de cada uno, para llevar el control.
     */
    'avisos_correo' => [
        'activos' => (bool) env('PLATAFORMA_AVISOS_CORREO', false),
        'copia'   => env('PLATAFORMA_AVISOS_CORREO_COPIA'),
    ],

    'soporte' => [
        'whatsapp' => env('PLATAFORMA_SOPORTE_WHATSAPP', env('PLATAFORMA_TELEFONO')),
        'correo'   => env('PLATAFORMA_SOPORTE_CORREO', env('PLATAFORMA_CORREO_DATOS')),
    ],

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

    'nota_precios' => 'Precios mensuales en pesos colombianos, más IVA. Con pago anual, 2 meses gratis. '
        . 'Complemento opcional TR-069 (WiFi, reinicio y consumo del equipo del cliente, y configuración automática de la ONT): '
        . 'desde $59.900 al mes según la cantidad de equipos; en Operador Plus, $89.900 fijo, y en Red completa, incluido.',

    /** Lo que trae cualquier plan. */
    'incluye_todos' => [
        'Clientes, planes, contratos, instalaciones y traslados',
        'Facturación, cartera, abonos y cortes automáticos por mora',
        'Pasarela de pago en línea',
        'OLT Huawei, ZTE y C-Data: autorizar ONT, señal, perfiles y VLAN, sin costo por OLT',
        'MikroTik: PPPoE, colas y control de ancho de banda',
        'CRM de WhatsApp: bandeja del equipo, avisos y campañas',
        'Portal de clientes con su nombre y su logo',
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
