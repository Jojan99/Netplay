<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Qué plantilla de Meta se manda ante cada hecho del negocio.
 *
 * El catálogo de eventos y de variables vive aquí para que el panel y el
 * código que envía hablen del mismo vocabulario.
 */
class WaTemplateBinding extends Model
{
    protected $table = 'wa_template_bindings';

    protected $fillable = [
        'company_id', 'event', 'template_name', 'language', 'enabled', 'params',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'params'  => 'array',
    ];

    /** Hechos que hoy saben notificar solos. */
    public const EVENTS = [
        'pago_aprobado' => [
            'label'       => 'Pago aprobado',
            'description' => 'El cliente pagó y el dinero ya se acreditó sobre sus facturas.',
            'suggested'   => 'pago_exitoso',
        ],
        'pago_pendiente' => [
            'label'       => 'Pago pendiente',
            'description' => 'El cliente generó el cobro pero todavía no se acredita (efectivo, transferencia).',
            'suggested'   => 'pago_pendiente',
        ],
        'pago_fallido' => [
            'label'       => 'Pago rechazado o anulado',
            'description' => 'La pasarela rechazó el pago o la transacción fue anulada.',
            'suggested'   => 'pago_no_completado',
        ],
    ];

    /**
     * Variables que el sistema sabe llenar. La clave es lo que se guarda en
     * `params`; el orden de la lista es el orden de los {{n}} de la plantilla.
     */
    public const VARIABLES = [
        'cliente'          => ['label' => 'Nombre del cliente',      'example' => 'Manuel'],
        'cliente_completo' => ['label' => 'Nombre completo',         'example' => 'Manuel Pombo'],
        'valor'            => ['label' => 'Valor pagado',            'example' => '$60.000'],
        'plan'             => ['label' => 'Plan contratado',         'example' => 'INTERNET 100MG'],
        'factura'          => ['label' => 'Número de factura',       'example' => 'NT16531'],
        'referencia'       => ['label' => 'Comprobante del pago',    'example' => '594192'],
        'medio_pago'       => ['label' => 'Medio de pago',           'example' => 'Nequi'],
        'saldo'            => ['label' => 'Saldo que queda',         'example' => '$0'],
        'estado_facturas'  => ['label' => 'Estado de las facturas',  'example' => 'tu factura quedó pagada'],
        'empresa'          => ['label' => 'Nombre de la empresa',    'example' => 'Netplay'],
        'soporte'          => ['label' => 'Teléfono de soporte',     'example' => '3245127869'],
        'fecha'            => ['label' => 'Fecha del pago',          'example' => '06/09/2026'],
    ];

    public function isUsable(): bool
    {
        return $this->enabled && !empty($this->template_name);
    }
}
