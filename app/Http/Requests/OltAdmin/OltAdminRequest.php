<?php

namespace App\Http\Requests\OltAdmin;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class OltAdminRequest extends FormRequest
{
    use DefaultResponseTrait;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'               => 'required|string',
            'brand'              => 'required|in:huawei,zte,cdata,fiberhome',
            'host'               => 'required|string',
            'port'               => 'nullable|integer',
            'username'           => 'required|string',
            'password'           => 'required|string',
            'access_mode'        => 'required|in:direct,jump',
            'jump_host'          => 'nullable|string',
            'jump_port'          => 'nullable|integer',
            'jump_user'          => 'nullable|string',
            'jump_pass'          => 'nullable|string',
            'ont_lineprofile_id' => 'nullable|integer',
            'ont_srvprofile_id'  => 'nullable|integer',
            'default_vlan'       => 'nullable|integer',
        ];
    }
}
