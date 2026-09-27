<?php

namespace App\Services\Instalaciones;

use App\Models\InstallationOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Da de alta al cliente con lo que se acordó al tomar el pedido.
 *
 * Se crea recién cuando el técnico termina la instalación, no antes: hasta ese
 * momento no se sabe si se va a poder hacer, y un cliente creado para una
 * instalación que se cae queda facturando de más.
 *
 * Reusa el alta de siempre —la que habla con el MikroTik, crea la credencial
 * PPPoE o reserva la IP en el ARP, y abre la facturación— para que un cliente
 * que entra por aquí sea idéntico a uno cargado a mano. Duplicar ese camino
 * sería garantizar que con el tiempo se comporten distinto.
 */
class ClienteDesdeLaOrden
{
    /**
     * @return array{ok: bool, message: string, user_id: ?int, nuevo: bool}
     */
    public static function crearOEncontrar(InstallationOrder $orden): array
    {
        $dni = trim((string) $orden->client_dni);

        if ($dni === '') {
            return ['ok' => false, 'message' => 'La orden no tiene la cédula del cliente.', 'user_id' => null, 'nuevo' => false];
        }

        // ¿Ya existe? Puede haberlo creado la oficina antes de que el técnico
        // llegara, y entonces no hay nada que hacer.
        $ya = DB::table('user_data as u')
            ->join('users as us', 'us.id', '=', 'u.user_id')
            ->where('us.company_id', $orden->company_id)
            ->where('u.dni', $dni)
            ->value('u.user_id');

        if ($ya) {
            return ['ok' => true, 'message' => 'El cliente ya existía.', 'user_id' => (int) $ya, 'nuevo' => false];
        }

        $faltan = self::loQueFalta($orden);

        if ($faltan) {
            return [
                'ok' => false,
                'message' => 'A la orden le falta ' . implode(', ', $faltan) . '. Completala desde Instalaciones y vuelva a intentar.',
                'user_id' => null,
                'nuevo' => false,
            ];
        }

        // Su lugar ya estaba reservado al agendar la orden (por eso se excluye a sí misma). Falla acá
        // sólo si el plan bajó o alguien llenó el cupo por otro lado, y falla ANTES de autorizar la
        // ONT: no queda un equipo dado de alta para un cliente que no se pudo crear.
        if ($motivo = \App\Services\Plataforma\LimiteDeClientes::motivoDeBloqueo((int) $orden->company_id, 1, (int) $orden->id)) {
            return ['ok' => false, 'message' => $motivo, 'user_id' => null, 'nuevo' => false];
        }

        try {
            $userId = DB::transaction(fn () => self::alta($orden));
        } catch (\Throwable $e) {
            Log::error('[Instalación] Falló el alta del cliente', ['orden' => $orden->id, 'error' => $e->getMessage()]);

            throw $e;
        }

        return ['ok' => true, 'message' => 'Cliente creado desde la orden.', 'user_id' => $userId, 'nuevo' => true];
    }

    /** @return list<string> */
    private static function loQueFalta(InstallationOrder $orden): array
    {
        $falta = [];

        if (!$orden->client_name) $falta[] = 'el nombre';
        if (!$orden->internet_plan_id) $falta[] = 'el plan';
        if (!$orden->grupo_facturacion) $falta[] = 'el día de corte';

        if ($orden->connection_type === 'pppoe') {
            if (!$orden->pppoe_user) $falta[] = 'el usuario PPPoE';
        } elseif (!$orden->ip_asignada) {
            $falta[] = 'la IP';
        }

        return $falta;
    }

    /** Crea el usuario, su ficha y la cabecera de facturación. */
    private static function alta(InstallationOrder $orden): int
    {
        // Si la orden los trae separados, se usan tal cual. Partir el nombre
        // completo adivinando dónde terminaba el nombre salía mal con dos
        // nombres y dos apellidos, que es lo normal aquí.
        $nombres = trim((string) $orden->client_firstname);
        $apellidos = trim((string) $orden->client_lastname);

        if ($nombres === '') {
            $partes = preg_split('/\s+/', trim((string) $orden->client_name), 2);
            $nombres = $partes[0] ?? (string) $orden->client_name;
            $apellidos = $apellidos !== '' ? $apellidos : ($partes[1] ?? '');
        }

        $perfilCliente = self::perfilDeCliente((int) $orden->company_id);

        $user = User::create([
            'company_id' => $orden->company_id,
            'username'   => $orden->client_dni,
            'email'      => $orden->client_email ?: null,
            'password'   => Hash::make(bin2hex(random_bytes(8))),
            'profile_id' => $perfilCliente,
        ]);

        DB::table('user_data')->insert([
            'company_id'        => $orden->company_id,
            'user_id'           => $user->id,
            // Sin esto, MySQL usa el default de la columna (0) en vez de dejarla en null, y no hay
            // perfil con id 0: la llave foránea a «profiles» revienta. Mismo criterio que el
            // importador (EjecutarImportacion::perfilDeCliente).
            'role_id'           => $perfilCliente,
            'names'             => $nombres,
            'lastname'          => $apellidos,
            'dni'               => $orden->client_dni,
            'email'             => $orden->client_email,
            'phone'             => $orden->client_phone,
            'address'           => $orden->address,
            // La orden no le pregunta la fecha de nacimiento al cliente: la ficha lo acepta vacío, igual
            // que al registrar una empresa (RegisterCompanyUseCase). Se completa después si hace falta.
            'birthday'          => '',
            'internet_plans_id' => $orden->internet_plan_id,
            'connection_type'   => $orden->connection_type ?: 'static',
            'pppoe_user'        => $orden->pppoe_user,
            'pppoe_password'    => $orden->pppoe_password,
            'pppoe_profile'     => $orden->pppoe_profile,
            'router_id'         => $orden->router_id,
            'active'            => 1,
            'status'            => 1,
            // Sin esto queda en null: no cuenta como activo NI como suspendido en «N activos + M
            // suspendidos» del panel (status_internet_id <> 1), y el cliente recién instalado
            // desaparece de ambos conteos. 1 = ACTIVE (ver tabla internet_status).
            'status_internet_id' => 1,
            'whatsapp_enabled'  => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        self::abrirFacturacion((int) $user->id, (int) $orden->company_id, (int) $orden->grupo_facturacion);

        return (int) $user->id;
    }

    /** El perfil de cliente de esa empresa: el que no es admin, técnico ni contador. */
    private static function perfilDeCliente(int $companyId): ?int
    {
        return DB::table('profiles')
            ->where('company_id', $companyId)
            ->whereNotIn('name', ['ADMIN', 'TECNICO', 'CONTADOR'])
            ->value('id');
    }

    /**
     * Abre la facturación con el día de corte elegido.
     *
     * El grupo 3 son los dos cortes del mes: se le abren las dos cabeceras,
     * igual que en el alta de siempre.
     */
    private static function abrirFacturacion(int $userId, int $companyId, int $grupo): void
    {
        $repo = app(\App\Repositories\Interfaces\FacturationRepositoryInterface::class);
        $hoy = now();

        $fecha = fn (int $dia) => $hoy->copy()->day(min($dia, $hoy->daysInMonth))->format('Y-m-d');

        if ($grupo === 3) {
            $repo->createCabFacturation($userId, 1, $fecha(15));
            $repo->createCabFacturation($userId, 2, $fecha(30));

            return;
        }

        $repo->createCabFacturation($userId, $grupo, $fecha($grupo === 1 ? 15 : 30));
    }
}
