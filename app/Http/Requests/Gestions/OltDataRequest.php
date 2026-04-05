<?php

namespace App\Http\Requests\Gestions;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class OltDataRequest extends FormRequest
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
            'port_potition' => 'string',
            'serial' => 'string',
            'descripcion' => 'string',
            'ont' => 'int',
            'interface' => 'string'
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
