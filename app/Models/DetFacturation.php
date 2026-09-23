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
        'email_sent_at',
        'anulada_en',
        'anulada_por',
        'anulada_motivo',
        'observacion',
    ];

    /**
     * Una factura anulada no se cuenta.
     *
     * Deja de sumar a la cartera, no dispara suspensiones, no entra en los
     * recordatorios ni en la cobranza. Sigue existiendo y se puede ver, pero
     * para todo lo que pregunte «¿qué debe este cliente?» no está.
     *
     * Para verlas hay que pedirlas expresamente: DetFacturation::conAnuladas().
     */
    protected static function booted(): void
    {
        static::addGlobalScope('sinAnuladas', function ($q) {
            $q->whereNull($q->getModel()->getTable() . '.anulada_en');
        });
    }

    /** Incluye las anuladas, para listarlas o auditarlas. */
    public static function conAnuladas(): \Illuminate\Database\Eloquent\Builder
    {
        return static::withoutGlobalScope('sinAnuladas');
    }

    /**
     * Saldo pendiente de la factura.
     *
     * Ojo con la convención de columnas, es fácil equivocarse:
     *   price_abone → monto abonado (dinero)
     *   abone       → bandera 0/1 de "tiene abono", NO es un monto
     */
    public function outstanding(): float
    {
        return round(max(0.0,
            (float) $this->price_total
            - (float) ($this->price_discount ?? 0)
            - (float) ($this->price_abone ?? 0)
        ), 2);
    }

    /** Monto ya abonado a esta factura. */
    public function amountPaid(): float
    {
        return round((float) ($this->price_abone ?? 0), 2);
    }

    /** Total neto de la factura, ya descontado. */
    public function netTotal(): float
    {
        return round(max(0.0, (float) $this->price_total - (float) ($this->price_discount ?? 0)), 2);
    }

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
