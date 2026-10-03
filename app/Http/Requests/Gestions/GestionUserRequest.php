<?php

namespace App\Http\Requests\Gestions;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class GestionUserRequest extends FormRequest
{
    use DefaultResponseTrait;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id_user' => 'int',
            'ip' => 'string',
            'dni' => 'string',
            'status' => 'int',
            'count' => 'int',
            'service_id' => 'int',
            'mac' => 'string',
            'serial' => 'nullable|string',
            'company_id' => 'nullable|int',
            'vlan' => 'nullable|string',
            'new_ip' => 'nullable|string',
            // Suspensión a mano por algo que no es la mora: que no vuelva sola al quedar al día.
            'no_reactivar' => 'nullable|boolean',
            'motivo' => 'nullable|string|max:255',
        ];
    }

    /**
     * Get the relations of tags.
     *
     * @return array
     */
    public function attributes()
    {
        return [
            'det_id' => 'Precio det_id',
        ];
    }
}
