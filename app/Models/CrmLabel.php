<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmLabel extends Model
{
    protected $table = 'crm_labels';

    protected $fillable = [
        'company_id',
        'name',
        'color',
    ];
}
