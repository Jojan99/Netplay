<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Egresses extends Model
{
    use HasFactory;
    protected $fillable = [
        'id',
        'company_id',
        'id_category_egresses',
        'concept',
        'category',
        'value',
        'user_id',
        'payment_method_id',
        // Egresos profesionales (columnas nuevas; ver 2026_09_17_000004)
        'expense_date',
        'supplier',
        'document_number',
        'notes',
        'attachment_path',
        'attachment_name',
        'recurrence',
        'created_at',
        'updated_at',
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
