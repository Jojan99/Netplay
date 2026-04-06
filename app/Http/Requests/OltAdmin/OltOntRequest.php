<?php

namespace App\Http\Requests\OltAdmin;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class OltOntRequest extends FormRequest
{
    use DefaultResponseTrait;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'fsp'          => 'required|string',   // "0/0/3"
            'serial'       => 'nullable|string',   // register
            'description'  => 'nullable|string',
            'ont_id'       => 'nullable|integer',  // delete/assign
            'service_port' => 'nullable|integer',  // delete/assign
            'vlan'         => 'nullable|integer',  // assign
        ];
    }
}
