<?php

namespace App\Http\Requests\OltAdmin;

use App\Rules\ClaveWifi;
use App\Rules\NombreWifi;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\DefaultResponseTrait;

class OltOntRequest extends FormRequest
{
    use DefaultResponseTrait;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'fsp'          => 'required|string',   // "0/0/3"
            'serial'       => 'nullable|string',   // register
            'description'  => 'nullable|string',
            'ont_id'       => 'nullable|integer',  // delete/assign
            'service_port' => 'nullable|integer',  // delete/assign
            'vlan'         => 'nullable|integer',  // assign
            // A quién se le está poniendo esta ONT. El nombre igual viaja a la
            // OLT como descripción; esto guarda el vínculo del lado nuestro.
            // Pese al nombre, es el id de USUARIO (users.id): así lo guarda y
            // lo lee toda la plataforma (ver OltOnt::client). Validarlo contra
            // user_data rechazaba clientes cuyo id de usuario no coincide con
            // ningún id de ficha, y aceptaba ids que eran de otro cliente.
            'user_data_id'    => 'nullable|integer|exists:users,id',
            'line_profile_id' => 'nullable|integer',
            // ZTE: el tipo de ONU del alta (el modelo, p. ej. F680V6.0.06).
            'onu_type'        => ['nullable', 'string', 'max:40', 'regex:/^[\w.\-]+$/'],
            'srv_profile_id'  => 'nullable|integer',
            // Aprovisionamiento al autorizar: la red elegida y el WiFi escrito
            // en el alta (vacío = se genera uno).
            'aprovisionar'            => 'nullable|array',
            'aprovisionar.gateway'    => 'nullable|ipv4',
            'aprovisionar.mascara'    => 'nullable|string|max:3',
            'aprovisionar.wifi_ssid'  => ['nullable', 'string', 'max:32', new NombreWifi()],
            // Una eñe o una tilde hacen que el equipo conteste «Invalid
            // arguments» y nadie entienda por qué: la regla lo explica.
            'aprovisionar.wifi_clave' => ['nullable', 'string', new ClaveWifi()],
            // Si el equipo ya está en la casa con su red andando, se aprovisiona
            // sólo la conexión y no se le toca el WiFi.
            'aprovisionar.wifi'       => 'nullable|boolean',
        ];
    }
}
