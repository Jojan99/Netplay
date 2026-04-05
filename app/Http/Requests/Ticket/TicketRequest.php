<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class TicketRequest extends FormRequest
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
            'status' => 'int',
            'finished_at' => 'date',
            'started_at' => 'date',
            'names_client' => 'string',
            'tech_names' => 'string',


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
            // 'names' => 'Nombres de usuario',
            // 'lastname' => 'Apellidos de usuario',
            // 'address' => 'Dreccion',
            // 'genderId' => 'Genero',
            // 'dniId' => 'Tipo Identificacion de usuario',
            // 'countryId' => 'Pais',
            // 'dni' => 'Identificacion de usuario',
            // 'email' => 'Correo',
            // 'phone' => 'Telefono',
            // 'birthday' => 'Fecha nacimiento',
            // 'planInternet' => 'Plan internet',
        ];
    }
}
