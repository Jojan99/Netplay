<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'names'      => 'required|string|max:255',
            'lastname'   => 'required|string|max:255',
            'email'      => 'required|email|max:255',
            'username'   => 'required|string|max:100',
            // Cuentas de personal: tienen acceso al panel, así que la clave
            // pide largo y mezcla. Las cuentas de cliente del portal no pasan
            // por aquí y conservan sus credenciales actuales.
            'password'   => ['required', 'string', \Illuminate\Validation\Rules\Password::min(10)->mixedCase()->numbers()],
            'profile_id' => [
                'required', 'integer',
                Rule::exists('profiles', 'id')->where('company_id', getSessionCompanyId()),
            ],
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
