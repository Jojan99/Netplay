<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;


class DetFacturation extends Authenticatable
{
    use HasFactory;
    protected $fillable = [
        'id',
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
        'create_facture_manual',
        'paid',
        'price_abone',
        'log_id',
        'paid_at',
        'paid_by_user_id',
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
