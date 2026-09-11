<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OltAdmin extends Model
{
    protected $table = 'olt_admins';

    protected $fillable = [
        'company_id',
        'name',
        'brand',
        'model',
        'host',
        'port',
        'username',
        'password',
        'access_mode',
        'jump_host',
        'jump_port',
        'jump_user',
        'jump_pass',
        'ont_lineprofile_id',
        'ont_srvprofile_id',
        'default_vlan',
        'snmp_community',
        'snmp_version',
        'snmp_port',
        'snmp_host',
        'snmp_jump_host',
        'snmp_jump_port',
        'snmp_jump_user',
        'snmp_jump_pass',
        'enable_password',
        'zte_onu_type',
        'zte_dba_profile',
        'vsol_onu_profile',
        'photo_path',
    ];

    protected $hidden = ['password', 'enable_password', 'jump_pass'];
}
