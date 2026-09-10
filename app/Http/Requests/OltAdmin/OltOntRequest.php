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
            // A quién se le está poniendo esta ONT. El nombre igual viaja a la
            // OLT como descripción; esto guarda el vínculo del lado nuestro.
            'user_data_id'    => 'nullable|integer|exists:user_data,id',
            'line_profile_id' => 'nullable|integer',
            'srv_profile_id'  => 'nullable|integer',
        ];
    }
}
