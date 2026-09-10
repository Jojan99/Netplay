<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class UserData extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

     /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'company_id',
        'gender_id',
        'country_id',
        'dni_id',
        'names',
        'lastname',
        'internet_plans_id',
        'status_internet_id',
        'ip_assignment_id',
        'address',
        'dni',
        'email',
        'phone',
        'birthday',
        'role_id',
        'active',
        'status',
        'whatsapp_enabled',
        // router_id faltaba: Eloquent lo descartaba en silencio y todos los
        // clientes creados desde el panel quedaban sin router asignado.
        'router_id',
        'connection_type',
        'pppoe_user',
        'pppoe_password',
        'pppoe_profile',
    ];

     /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'active' => 'boolean',
        // Es la credencial con la que el cliente entra a la red: no queda en
        // claro en la base.
        'pppoe_password' => 'encrypted',
    ];
}
