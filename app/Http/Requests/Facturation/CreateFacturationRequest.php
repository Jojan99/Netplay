<?php

namespace App\Http\Requests\Facturation;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class CreateFacturationRequest extends FormRequest
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
            'cab_id' => 'int',
            'date_facturation' => 'string',
            'number_facture' => 'string',
            'date_create_facturation' => 'string',
            'price_total' => 'numeric',
            'price_abone' => 'numeric',
            'price_discount' => 'numeric',
            'days_facture' => 'numeric',
            'porcentage_discount' => 'numeric',
            'total' => 'bool',
            'discount' => 'bool'
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
