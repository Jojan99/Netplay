<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAffiliation extends Model
{
    protected $table    = 'employee_affiliations';
    protected $fillable = ['company_id','employee_id','arl','eps','pension_fund','compensation_fund'];
}
