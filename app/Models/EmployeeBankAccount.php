<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeBankAccount extends Model
{
    protected $table    = 'employee_bank_accounts';
    protected $fillable = ['company_id','employee_id','bank_name','account_type','account_number'];
}
