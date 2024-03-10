<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;


class DetFacturation extends Authenticatable
{
    use HasFactory;
    protected $fillable = [
        'cab_id',
        'date_facturation',
        'number_facture',
        'date_create_facturation',
        'total',
        'price_total',
        'abone',
        'days_facture',
        'discount',
        'price_discount',
        'porcentage_discount',
        'paid'

    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
