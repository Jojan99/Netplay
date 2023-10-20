<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class CreateUserDataRequest extends FormRequest
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
            'id' => 'int',
            'names' => 'required|string',
            'lastname' => 'required|string',
            'address' => 'required|string',
            'genderId' => 'required|int',
            'dniId' => 'required|int',
            'countryId' => 'required|int',
            'dni' => 'required|string',
            'username' => 'required|string',
            'email' => 'required|string',
            'phone' => 'required|string',
            'birthday' => 'required|string',
            'password' => 'required|string',
            'planInternet' => 'required|int',
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
            'names' => 'Nombres de usuario',
            'lastname' => 'Apellidos de usuario',
            'address' => 'Dreccion',
            'genderId' => 'Genero',
            'dniId' => 'Tipo Identificacion de usuario',
            'countryId' => 'Pais',
            'dni' => 'Identificacion de usuario',
            'username' => 'Username',
            'email' => 'Correo',
            'phone' => 'Telefono',
            'birthday' => 'Fecha nacimiento',
            'password' => 'Contraseña',
            'confirPassword' => 'Confirmar contraseña',
            'planInternet' => 'Plan internet',
        ];
    }
}
