<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractPdfField extends Model
{
    protected $fillable = [
        'contract_id',
        'variable',
        'page',
        'x',
        'y',
        'font_size',
        'color',
        'max_width',
    ];

    protected $casts = [
        'page'      => 'integer',
        'x'         => 'float',
        'y'         => 'float',
        'font_size' => 'integer',
        'max_width' => 'integer',
    ];
}
