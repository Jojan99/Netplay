<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeEquipment extends Model
{
    protected $table    = 'employee_equipment';
    protected $fillable = ['company_id','employee_id','name','description','serial','condition','assigned_at','returned_at'];
    protected $casts    = ['assigned_at' => 'date', 'returned_at' => 'date'];
}
