<?php

namespace App\Http\Requests\OltAdmin;

use App\OltDrivers\FabricaDeDrivers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Http\Requests\Traits\DefaultResponseTrait;

class OltAdminRequest extends FormRequest
{
    use DefaultResponseTrait;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'               => 'required|string',
            // Las marcas válidas son las que tienen driver: si aquí se acepta una
            // que la fábrica no atiende, la OLT se guarda y después ninguna
            // consulta funciona.
            'brand'              => ['required', Rule::in(array_column(FabricaDeDrivers::marcas(), 'valor'))],
            'host'               => 'required|string',
            'port'               => 'nullable|integer',
            'username'           => 'required|string',
            // Al editar se manda vacía para dejar la que ya está guardada: si
            // aquí siguiera siendo obligatoria, ningún cambio se podría guardar
            // sin volver a escribir la contraseña de la OLT.
            'password'           => $this->creando() ? 'required|string' : 'nullable|string',
            'access_mode'        => 'required|in:direct,jump',
            'jump_host'          => 'nullable|string',
            'jump_port'          => 'nullable|integer',
            'jump_user'          => 'nullable|string',
            'jump_pass'          => 'nullable|string',
            'enable_password'    => 'nullable|string',
            'ont_lineprofile_id' => 'nullable|integer',
            'ont_srvprofile_id'  => 'nullable|integer',
            'default_vlan'       => 'nullable|integer',

            // Lectura por SNMP: sin esto la ficha del equipo y las potencias
            // ópticas no se pueden consultar.
            'snmp_community'     => 'nullable|string',
            'snmp_version'       => 'nullable|in:1,2c,3',
            'snmp_port'          => 'nullable|integer',
            'snmp_host'          => 'nullable|string',
            'snmp_jump_host'     => 'nullable|string',
            'snmp_jump_port'     => 'nullable|integer',
            'snmp_jump_user'     => 'nullable|string',
            'snmp_jump_pass'     => 'nullable|string',

            // Túnel de gestión creado junto con la OLT: es el caso normal
            // cuando el equipo está en una red privada detrás de un MikroTik.
            'crear_tunel_vpn' => 'sometimes|boolean',
            'tunel_nombre'    => 'nullable|string|max:120',
            'tunel_redes'     => 'nullable|string|max:255',
            // Si la OLT está detrás de un router que ya tiene túnel, su red se
            // suma a ése: un router lleva un solo túnel.
            'tunel_id'        => 'nullable|integer',

            // Lo propio de cada marca al autorizar una ONT.
            'zte_onu_type'       => 'nullable|string|max:60',
            'zte_dba_profile'    => 'nullable|string|max:60',
            'vsol_onu_profile'   => 'nullable|string|max:60',
        ];
    }

    /** ¿Es un alta o la edición de una OLT que ya existe? */
    private function creando(): bool
    {
        return $this->isMethod('post');
    }

    public function messages(): array
    {
        return [
            'brand.in' => 'Esa marca de OLT todavía no está soportada. Disponibles: '
                . implode(', ', array_column(FabricaDeDrivers::marcas(), 'nombre')) . '.',
        ];
    }
}
