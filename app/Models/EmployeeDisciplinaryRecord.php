<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDisciplinaryRecord extends Model
{
    protected $table    = 'employee_disciplinary_records';
    protected $fillable = ['company_id','employee_id','incident_date','reason','description','resolution'];
    protected $casts    = ['incident_date' => 'date'];
}
