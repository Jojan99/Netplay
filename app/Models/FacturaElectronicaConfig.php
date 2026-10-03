<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Con qué proveedor y con qué ajustes factura electrónicamente una empresa. */
class FacturaElectronicaConfig extends Model
{
    protected $table = 'factura_electronica_configs';

    protected $fillable = ['company_id', 'proveedor', 'activa', 'automatica', 'emitir_desde', 'credenciales', 'ajustes', 'verificada_en'];

    /** Las credenciales del proveedor nunca salen en una respuesta. */
    protected $hidden = ['credenciales'];

    protected $casts = [
        'activa'        => 'boolean',
        'automatica'    => 'boolean',
        'emitir_desde'  => 'date:Y-m-d',
        'credenciales'  => 'encrypted:array',
        'ajustes'       => 'array',
        'verificada_en' => 'datetime',
    ];

    public function ajuste(string $clave, mixed $porDefecto = null): mixed
    {
        return data_get($this->ajustes ?? [], $clave, $porDefecto);
    }
}
