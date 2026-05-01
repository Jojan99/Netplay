<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'first_name', 'last_name', 'dni', 'email',
        'phone', 'address', 'job_title', 'start_date', 'birthday', 'active',
        'latitude', 'longitude', 'last_location_update',
    ];

    protected $casts = [
        'start_date'         => 'date',
        'birthday'           => 'date',
        'active'             => 'boolean',
        'latitude'           => 'decimal:8',
        'longitude'          => 'decimal:8',
        'last_location_update' => 'datetime',
    ];

    public function laborContract()    { return $this->hasOne(EmployeeLaborContract::class); }
    public function affiliation()      { return $this->hasOne(EmployeeAffiliation::class); }
    public function bankAccount()      { return $this->hasOne(EmployeeBankAccount::class); }
    public function equipment()        { return $this->hasMany(EmployeeEquipment::class); }
    public function disciplinary()     { return $this->hasMany(EmployeeDisciplinaryRecord::class)->orderByDesc('incident_date'); }
    public function payrolls()         { return $this->hasMany(EmployeePayroll::class)->orderByDesc('period'); }
}
