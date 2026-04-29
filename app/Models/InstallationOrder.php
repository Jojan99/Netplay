<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InstallationOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_data_id',
        'client_name',
        'client_dni',
        'client_phone',
        'client_email',
        'address',
        'neighborhood',
        'internet_plan_id',
        'scheduled_date',
        'scheduled_time',
        'status',
        'payment_status',
        'payment_amount',
        'payment_reference',
        'payment_image_url',
        'payment_method_id',
        'technician_ids',
        'installation_cost',
        'commission_amount',
        'observations',
        'technical_notes',
        'created_by',
        'assigned_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'schedician_time' => 'datetime:H:i:s',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'payment_amount' => 'decimal:2',
        'installation_cost' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'technician_ids' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function client()
    {
        return $this->belongsTo(UserData::class, 'user_data_id');
    }

    public function plan()
    {
        return $this->belongsTo(InternetPlan::class, 'internet_plan_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function technicians()
    {
        return $this->belongsToMany(Employee::class, 'installation_order_employee')
            ->withPivot('commission_amount')
            ->withTimestamps();
    }
    
    public function getTechniciansListAttribute()
    {
        if (!empty($this->technician_ids)) {
            return Employee::whereIn('id', $this->technician_ids)->get();
        }
        return collect([]);
    }
    
    public function getTechniciansAttribute()
    {
        if (!empty($this->technician_ids)) {
            return Employee::whereIn('id', $this->technician_ids)->get()->toArray();
        }
        return [];
    }

    public function getIsPaidAttribute()
    {
        return in_array($this->payment_status, ['paid', 'verified']);
    }

    public function getCanStartAttribute()
    {
        return $this->status === 'confirmed';
    }

    public function getCanCompleteAttribute()
    {
        return $this->status === 'in_progress';
    }

    public function logs()
    {
        return $this->hasMany(InstallationLog::class, 'installation_id')->orderBy('created_at', 'desc');
    }
}