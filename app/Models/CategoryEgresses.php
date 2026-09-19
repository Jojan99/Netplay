<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de tipos de egreso. Es por empresa (company_id): el módulo nunca
 * debe mostrar ni dejar tocar las categorías de otra.
 */
class CategoryEgresses extends Model
{
    protected $table = 'category_egresses';

    protected $fillable = ['company_id', 'name', 'active', 'color'];

    protected $casts = ['active' => 'boolean'];
}
