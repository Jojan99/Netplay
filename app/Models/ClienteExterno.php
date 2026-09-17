<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El id que tiene un cliente en la plataforma de la que se importó. */
class ClienteExterno extends Model
{
    protected $table = 'clientes_externos';

    protected $fillable = ['company_id', 'origen', 'external_id', 'user_id', 'importacion_id', 'saldo_origen'];
}
