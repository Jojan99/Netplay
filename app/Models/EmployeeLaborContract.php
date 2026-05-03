<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLaborContract extends Model
{
    protected $table    = 'employee_labor_contracts';
    protected $fillable = ['company_id','employee_id','type','duration_months','salary','start_date','end_date'];
    protected $casts    = ['start_date' => 'date', 'end_date' => 'date', 'salary' => 'float'];
}
