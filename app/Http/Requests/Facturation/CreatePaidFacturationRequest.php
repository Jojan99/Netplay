<?php

namespace App\Http\Requests\Facturation;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class CreatePaidFacturationRequest extends FormRequest
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
            'det_id' => 'int',
            'discount' => 'int',
            'porcentage_discount' => 'int',
            'paid' => 'int',
            'id_user' => 'int',
            'price_total' => 'numeric',
            'price_abone' => 'numeric',
            'log_id' => 'numeric',
            'number_facture' => 'string',
            'abone' => 'int',
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
