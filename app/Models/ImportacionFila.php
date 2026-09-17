<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un cliente leído del origen, ya normalizado, y qué pasó al importarlo. */
class ImportacionFila extends Model
{
    protected $table = 'importacion_filas';

    protected $fillable = [
        'importacion_id', 'company_id', 'fila', 'external_id', 'dni', 'nombre', 'datos',
        'avisos', 'previo', 'existente_user_id', 'resultado', 'mensaje', 'user_id',
    ];

    protected $casts = [
        // Trae la contraseña PPPoE del cliente: no queda en claro en la base.
        'datos'  => 'encrypted:array',
        'avisos' => 'array',
    ];
}
