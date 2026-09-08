<?php

namespace App\Support;

/**
 * Catálogo único de módulos del panel. Es la fuente de verdad para:
 *  - la pantalla de permisos por perfil (activar / desactivar por empresa),
 *  - los valores por defecto al crear una empresa,
 *  - el middleware `module:` que protege las rutas del API.
 *
 * La clave debe coincidir con `data.module` de la ruta del frontend.
 */
class Modules
{
    /** grupo => [clave => [label, description, admin_only?]] */
    public const CATALOG = [
        'Clientes y servicio' => [
            'usuario'         => ['label' => 'Clientes',            'description' => 'Alta, edición y estado de los clientes.'],
            'planes-internet' => ['label' => 'Planes de internet',  'description' => 'Velocidades y precios de los planes.'],
            'contratos'       => ['label' => 'Contratos',           'description' => 'Contratos y plantilla de firma.'],
            'installations'   => ['label' => 'Instalaciones',       'description' => 'Órdenes de instalación y su agenda.'],
            'transfers'       => ['label' => 'Traslados',           'description' => 'Traslados de servicio entre direcciones.'],
        ],
        'Soporte' => [
            'created-ticket'  => ['label' => 'Crear tickets',       'description' => 'Registrar tickets de soporte.'],
            'view-ticket'     => ['label' => 'Ver tickets',         'description' => 'Bandeja y seguimiento de tickets.'],
            'technician-map'  => ['label' => 'Mapa de técnicos',    'description' => 'Ubicación de los técnicos en campo.'],
        ],
        'Facturación' => [
            'finanzas'        => ['label' => 'Finanzas',            'description' => 'Facturación, cartera y recaudo.'],
            'egresos'         => ['label' => 'Egresos',             'description' => 'Gastos y salidas de dinero.'],
            'report-paid'     => ['label' => 'Reporte de pagos',    'description' => 'Pagos recibidos y exportación.'],
            'history-facture' => ['label' => 'Historial de facturas', 'description' => 'Facturas emitidas y procesos.'],
            'resumen'         => ['label' => 'Resumen',             'description' => 'Indicadores del negocio.'],
            'billing-config'  => ['label' => 'Configuración de facturación', 'description' => 'Grupos, plantilla y métodos de pago.'],
            'payment-gateway' => ['label' => 'Pasarela de pago',    'description' => 'Pagos en línea y cortes por mora.'],
        ],
        'Red' => [
            'mikrotik'        => ['label' => 'MikroTik',            'description' => 'Routers, colas y control de ancho de banda.'],
            'olt-admin'       => ['label' => 'OLT',                 'description' => 'Administración de OLT y ONT.'],
            'olt-detail'      => ['label' => 'Detalle de OLT',      'description' => 'Consulta detallada de puertos y ONT.'],
            'router'          => ['label' => 'Router del cliente',  'description' => 'Gestión TR-069 del equipo del cliente.'],
            'inventory'       => ['label' => 'Inventario',          'description' => 'Equipos, categorías y movimientos.'],
        ],
        'Comunicación' => [
            'crm'             => ['label' => 'CRM / WhatsApp',      'description' => 'Bandeja de conversaciones y campañas.'],
            'whatsapp'        => ['label' => 'WhatsApp (config.)',  'description' => 'Instancias, plantillas y automatizaciones.'],
        ],
        'Administración' => [
            'staff'           => ['label' => 'Staff',               'description' => 'Usuarios del panel y sus perfiles.'],
            'empleados'       => ['label' => 'Empleados',           'description' => 'Nómina, contratos y dotación.'],
            'permisos'        => ['label' => 'Permisos por perfil', 'description' => 'Qué módulo ve cada perfil.', 'admin_only' => true],
        ],
    ];

    /** Módulos por defecto al crear una empresa. */
    public const DEFAULTS = [
        'ADMIN'    => '*',   // todos
        'TECNICO'  => ['created-ticket', 'view-ticket', 'crm', 'installations', 'technician-map', 'usuario', 'router', 'olt-detail'],
        'CONTADOR' => ['finanzas', 'egresos', 'report-paid', 'history-facture', 'inventory', 'resumen', 'usuario'],
    ];

    /** @return string[] todas las claves del catálogo */
    public static function keys(): array
    {
        $out = [];
        foreach (self::CATALOG as $modules) {
            $out = array_merge($out, array_keys($modules));
        }
        return $out;
    }

    /** @return array<int, array{group:string, module:string, label:string, description:string, admin_only:bool}> */
    public static function flat(): array
    {
        $out = [];
        foreach (self::CATALOG as $group => $modules) {
            foreach ($modules as $key => $meta) {
                $out[] = [
                    'group'       => $group,
                    'module'      => $key,
                    'label'       => $meta['label'],
                    'description' => $meta['description'],
                    'admin_only'  => (bool)($meta['admin_only'] ?? false),
                ];
            }
        }
        return $out;
    }

    /** Módulos por defecto de un rol, resolviendo el comodín. */
    public static function defaultsFor(string $role): array
    {
        $def = self::DEFAULTS[strtoupper($role)] ?? [];
        return $def === '*' ? self::keys() : $def;
    }
}
