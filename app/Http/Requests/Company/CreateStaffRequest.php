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
            'username'   => 'required|string|max:100|regex:/^[A-Za-z0-9._-]+$/',
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

    /**
     * El panel muestra tal cual el mensaje que devuelve el servidor, así que
     * cada uno dice qué campo está mal y cómo se arregla. Sin esto salían los
     * textos en inglés que Laravel trae de fábrica.
     */
    public function messages(): array
    {
        $clave = 'La contraseña debe tener al menos 10 caracteres e incluir mayúsculas, minúsculas y números. Podés usar el botón «Generar».';

        return [
            'names.required'      => 'Escribí el nombre del usuario.',
            'names.max'           => 'El nombre no puede pasar de 255 caracteres.',
            'lastname.required'   => 'Escribí el apellido del usuario.',
            'lastname.max'        => 'El apellido no puede pasar de 255 caracteres.',
            'email.required'      => 'Escribí el correo electrónico.',
            'email.email'         => 'El correo no es válido: revisá que tenga el formato usuario@empresa.com.',
            'email.max'           => 'El correo no puede pasar de 255 caracteres.',
            'username.required'   => 'Escribí el usuario con el que va a entrar al sistema.',
            'username.regex'      => 'El usuario sólo admite letras, números, punto, guion y guion bajo (sin espacios ni tildes).',
            'username.max'        => 'El usuario no puede pasar de 100 caracteres.',
            'password.required'   => 'Escribí una contraseña para la cuenta.',
            'password.min'        => $clave,
            'password.mixed'      => $clave,
            'password.numbers'    => $clave,
            'password.letters'    => $clave,
            'profile_id.required' => 'Elegí el rol del usuario (Administrador, Técnico o Contador).',
            'profile_id.integer'  => 'Elegí el rol del usuario (Administrador, Técnico o Contador).',
            'profile_id.exists'   => 'Ese rol no existe en esta empresa: elegí uno de la lista.',
        ];
    }

    public function attributes(): array
    {
        return [
            'names'      => 'nombre',
            'lastname'   => 'apellido',
            'email'      => 'correo electrónico',
            'username'   => 'usuario',
            'password'   => 'contraseña',
            'profile_id' => 'rol',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            // Con el campo, el panel marca en rojo el que falló.
            'data'    => ['campo' => array_key_first($validator->errors()->toArray())],
            'error'   => 1,
        ], 422));
    }
}
