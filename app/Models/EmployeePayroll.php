<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePayroll extends Model
{
    protected $table    = 'employee_payrolls';
    protected $fillable = ['company_id','employee_id','period','base_salary','bonuses','deductions','total_paid','payment_date','payment_method','notes'];
    protected $casts    = ['payment_date' => 'date', 'base_salary' => 'float', 'bonuses' => 'float', 'deductions' => 'float', 'total_paid' => 'float'];
}
