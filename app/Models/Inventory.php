<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'company_id', 'category_id', 'name', 'description',
        'quantity', 'unit_price', 'unit',
    ];

    public function category()
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_id');
    }
}
