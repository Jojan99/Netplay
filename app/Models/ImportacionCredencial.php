<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** La URL y el token de la API de origen, guardados a pedido de la empresa. */
class ImportacionCredencial extends Model
{
    protected $table = 'importacion_credenciales';

    protected $fillable = ['company_id', 'origen', 'api_url', 'api_token'];

    protected $hidden = ['api_token'];

    protected $casts = ['api_token' => 'encrypted'];
}
