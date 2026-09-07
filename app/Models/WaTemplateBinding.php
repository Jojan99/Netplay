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
        'company_id', 'event', 'template_name', 'language', 'enabled', 'params', 'config',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'params'  => 'array',
        'config'  => 'array',
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
            'suggested'   => 'pago_cancelado',
        ],
        'recordatorio_pago' => [
            'label'       => 'Recordatorio de pago',
            'description' => 'Le avisa al cliente que su factura está por vencer, antes de que entre en mora.',
            'suggested'   => 'recordatorio_de_pago',
            'programado'  => true,
        ],
        'envio_factura' => [
            'label'       => 'Envío de la factura mensual',
            'description' => 'Sale con el proceso de facturación, cuando se genera la factura del mes.',
            'suggested'   => 'envio_factura',
            'proceso'     => true,
        ],
        'servicio_reactivado' => [
            'label'       => 'Servicio reactivado',
            'description' => 'El cliente pagó y su servicio volvió a quedar activo.',
            'suggested'   => 'servicio_reactivado',
        ],
        'suspension_mora' => [
            'label'       => 'Aviso de suspensión por mora',
            'description' => 'Le avisa al cliente que su servicio se suspenderá si no paga.',
            'suggested'   => 'suspendido_por_mora',
            'programado'  => true,
        ],
    ];

    /**
     * Cuándo sale cada aviso programado.
     *
     * El plazo cambia por empresa —unas cobran a los 5 días de facturar, otras
     * el día 5 del mes— así que no puede estar escrito en el código.
     */
    public const REFERENCIAS = [
        'fecha_factura' => [
            'label'       => 'Fecha de la factura',
            'description' => 'Cuenta los días desde que se emitió cada factura. Cada una lleva su propia cuenta.',
        ],
        'dia_de_corte' => [
            'label'       => 'Día de corte del mes',
            'description' => 'Cuenta hacia atrás desde el día del mes en que se suspende. Un solo envío mensual.',
        ],
    ];

    /** Ajustes por defecto de un aviso programado, si nadie los ha tocado. */
    public const CONFIG_POR_DEFECTO = [
        'referencia'   => 'dia_de_corte',
        'dias_antes'   => 2,
        'dias_plazo'   => 5,
        'solo_con_deuda' => true,
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
        'fecha_vencimiento'=> ['label' => 'Fecha de vencimiento',    'example' => '20/09/2026'],
        'fecha_emision'    => ['label' => 'Fecha de emisión',        'example' => '15/09/2026'],
        'dias_mora'        => ['label' => 'Días de mora',            'example' => '12'],
        'total_pendiente'  => ['label' => 'Total que debe',          'example' => '$120.000'],
        'texto_libre'      => ['label' => 'Texto que tú escribes',   'example' => 'El servicio estará en mantenimiento el sábado.'],
    ];

    /**
     * ¿Está permitido este aviso?
     *
     * Sin fila configurada se responde que sí: el envío de la factura ya venía
     * funcionando antes de que existiera este interruptor, y apagarlo por la
     * simple ausencia de un registro dejaría a los clientes sin su factura sin
     * que nadie lo hubiera pedido.
     */
    public static function permitido(int $companyId, string $event): bool
    {
        $row = static::where('company_id', $companyId)->where('event', $event)->first();

        return $row === null || (bool) $row->enabled;
    }

    public function isUsable(): bool
    {
        return $this->enabled && !empty($this->template_name);
    }

    /** Los ajustes guardados, completados con los valores por defecto. */
    public function ajustes(): array
    {
        return array_merge(self::CONFIG_POR_DEFECTO, (array) ($this->config ?? []));
    }
}
