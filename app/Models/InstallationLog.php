<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallationLog extends Model
{
    protected $table = 'installation_logs';
    
    protected $fillable = [
        'installation_id',
        'action',
        'description',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function installation()
    {
        return $this->belongsTo(InstallationOrder::class, 'installation_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getCreatedByNameAttribute()
    {
        return $this->creator?->name ?? $this->creator?->username ?? 'Sistema';
    }
}