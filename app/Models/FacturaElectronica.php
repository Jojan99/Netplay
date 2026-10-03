<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un documento enviado a la DIAN: factura de venta o nota crédito. */
class FacturaElectronica extends Model
{
    public const PENDIENTE = 'pendiente';
    public const EMITIDA   = 'emitida';
    public const RECHAZADA = 'rechazada';
    public const ERROR     = 'error';

    protected $table = 'facturas_electronicas';

    protected $guarded = ['id'];

    protected $casts = [
        'solicitud'  => 'array',
        'respuesta'  => 'array',
        'emitida_en' => 'datetime',
        'base'       => 'float',
        'impuesto'   => 'float',
        'total'      => 'float',
    ];
}
