<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'first_name', 'last_name', 'dni', 'email',
        'phone', 'address', 'job_title', 'start_date', 'birthday', 'active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'birthday'   => 'date',
        'active'     => 'boolean',
    ];

    public function laborContract()    { return $this->hasOne(EmployeeLaborContract::class); }
    public function affiliation()      { return $this->hasOne(EmployeeAffiliation::class); }
    public function bankAccount()      { return $this->hasOne(EmployeeBankAccount::class); }
    public function equipment()        { return $this->hasMany(EmployeeEquipment::class); }
    public function disciplinary()     { return $this->hasMany(EmployeeDisciplinaryRecord::class)->orderByDesc('incident_date'); }
    public function payrolls()         { return $this->hasMany(EmployeePayroll::class)->orderByDesc('period'); }
}
