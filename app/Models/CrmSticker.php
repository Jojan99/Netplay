<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmSticker extends Model
{
    protected $table = 'crm_stickers';

    protected $fillable = [
        'company_id',
        'media_url',
        'name',
    ];
}
