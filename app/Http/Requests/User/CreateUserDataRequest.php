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
            'id' => 'nullable|int',
            'id_user' => 'nullable|int',
            'names' => 'required|string',
            'lastname' => 'required|string',
            'address' => 'required|string',
            'countryId' => 'string',
            'vlan' => 'nullable|string',
            'dni' => 'required|string',
            'email' => 'required|string',
            'phone' => 'required|string',
            'planInternet' => 'required|int',
            'ip_assignment_id' => 'nullable|string',
            'group' => 'required|int',
            'router_id' => 'nullable|int',
            // Con PPPoE no hay IP que asignar: el cliente entra con usuario y
            // contraseña y la IP se la da el pool del router.
            'connection_type' => 'nullable|in:static,pppoe',
            'pppoe_user' => 'nullable|string|max:120|required_if:connection_type,pppoe',
            'pppoe_password' => 'nullable|string|max:120|required_if:connection_type,pppoe',
            'pppoe_profile' => 'nullable|string|max:120',
              // 'genderId' => 'required|int',
            // 'dniId' => 'required|int',
            // 'birthday' => 'required|string',

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
            'email' => 'Correo',
            'phone' => 'Telefono',
            'birthday' => 'Fecha nacimiento',
            'planInternet' => 'Plan internet',
        ];
    }
}
