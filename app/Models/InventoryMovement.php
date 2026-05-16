<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryMovement extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_ENTRADA = 'entrada';
    public const TYPE_SALIDA = 'salida';
    public const TYPE_AJUSTE = 'ajuste';

    public static array $types = [
        self::TYPE_ENTRADA,
        self::TYPE_SALIDA,
        self::TYPE_AJUSTE,
    ];

    protected $fillable = [
        'company_id',
        'inventory_id',
        'type',
        'quantity',
        'unit_price',
        'balance_after',
        'cost_before',
        'cost_after',
        'description',
        'reference',
        'batch_number',
        'expiry_date',
        'user_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'cost_before' => 'decimal:2',
        'cost_after' => 'decimal:2',
        'expiry_date' => 'date:Y-m-d',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeByCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByItem($query, int $inventoryId)
    {
        return $query->where('inventory_id', $inventoryId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
