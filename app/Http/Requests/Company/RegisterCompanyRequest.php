<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => 'required|string|max:255',
            'nit'            => 'required|string|max:50|unique:companies,nit',
            'email'          => 'required|email|unique:companies,email',
            'phone'          => 'required|string|max:20',
            'address'        => 'required|string|max:255',
            'admin_name'     => 'required|string|max:255',
            'admin_lastname' => 'required|string|max:255',
            'admin_password' => 'required|string|min:6',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'data'    => null,
            'error'   => 1,
        ], 422));
    }
}
