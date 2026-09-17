<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una corrida del importador de clientes: lectura, vista previa y ejecución. */
class Importacion extends Model
{
    protected $table = 'importaciones';

    protected $fillable = [
        'company_id', 'user_id', 'origen', 'metodo', 'estado', 'api_url', 'api_token',
        'archivo', 'nombre_archivo', 'columnas', 'mapeo', 'opciones', 'analisis',
        'total', 'procesadas', 'creados', 'actualizados', 'omitidos', 'errores',
        'detalle', 'iniciada_en', 'terminada_en',
    ];

    protected $hidden = ['api_token', 'archivo'];

    protected $casts = [
        'api_token'    => 'encrypted',
        'columnas'     => 'array',
        'mapeo'        => 'array',
        'opciones'     => 'array',
        'analisis'     => 'array',
        'iniciada_en'  => 'datetime',
        'terminada_en' => 'datetime',
        'created_at'   => 'datetime:Y-m-d H:i:s',
        'updated_at'   => 'datetime:Y-m-d H:i:s',
    ];

    public function filas()
    {
        return $this->hasMany(ImportacionFila::class, 'importacion_id');
    }
}
