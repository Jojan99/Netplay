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

    public function messages(): array
    {
        return [
            'nit.unique'             => 'Ya existe una empresa registrada con ese NIT.',
            'email.unique'           => 'Ya existe una empresa registrada con ese correo.',
            'email.email'            => 'Escribí un correo válido.',
            'admin_password.min'     => 'La contraseña debe tener al menos 6 caracteres.',
            'admin_username.unique'  => 'Ese usuario ya está tomado. Probá con otro.',
            'admin_username.regex'   => 'El usuario admite letras, números, punto, guion y guion bajo.',
        ];
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
            // Opcionales: mejoran la factura y la ficha del administrador desde el registro
            'city'            => 'nullable|string|max:120',
            'country'         => 'nullable|string|max:60',
            'invoice_prefix'  => 'nullable|string|max:10',
            'admin_username'  => 'nullable|string|max:60|regex:/^[A-Za-z0-9._-]+$/|unique:users,username',
            'admin_dni'       => 'nullable|string|max:30',
            'admin_phone'     => 'nullable|string|max:20',
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
