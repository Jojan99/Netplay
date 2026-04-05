<?php

namespace App\Http\Requests\Egresos;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class CreateEgresosRequest extends FormRequest
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
            'id_category_egresses' => 'int',
            'concept' => 'string',
            'value' => 'numeric',
            'user_id' => 'int',
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
            'price_total' => 'Precio Total',
            'price_abone' => 'Precio Abono',
            'price_discount' => 'Pricio Descuento',
        ];
    }
}
