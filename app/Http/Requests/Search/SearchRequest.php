<?php

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class SearchRequest extends FormRequest
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
            'value' => 'string',
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
            'value' => 'value',
        ];
    }
}
