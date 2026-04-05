<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    protected $fillable = ['company_id', 'name', 'description'];

    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'category_id');
    }
}
