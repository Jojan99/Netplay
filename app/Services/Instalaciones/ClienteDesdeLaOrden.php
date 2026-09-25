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
 * que entra por acá sea idéntico a uno cargado a mano. Duplicar ese camino
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
                'message' => 'A la orden le falta ' . implode(', ', $faltan) . '. Completala desde Instalaciones y volvé a intentar.',
                'user_id' => null,
                'nuevo' => false,
            ];
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
        $partes = preg_split('/\s+/', trim((string) $orden->client_name), 2);
        $nombres = $partes[0] ?? (string) $orden->client_name;
        $apellidos = $partes[1] ?? '';

        $user = User::create([
            'company_id' => $orden->company_id,
            'username'   => $orden->client_dni,
            'email'      => $orden->client_email ?: null,
            'password'   => Hash::make(bin2hex(random_bytes(8))),
            'profile_id' => self::perfilDeCliente((int) $orden->company_id),
        ]);

        DB::table('user_data')->insert([
            'company_id'        => $orden->company_id,
            'user_id'           => $user->id,
            'names'             => $nombres,
            'lastname'          => $apellidos,
            'dni'               => $orden->client_dni,
            'email'             => $orden->client_email,
            'phone'             => $orden->client_phone,
            'address'           => $orden->address,
            'internet_plans_id' => $orden->internet_plan_id,
            'connection_type'   => $orden->connection_type ?: 'static',
            'pppoe_user'        => $orden->pppoe_user,
            'pppoe_password'    => $orden->pppoe_password,
            'pppoe_profile'     => $orden->pppoe_profile,
            'router_id'         => $orden->router_id,
            'active'            => 1,
            'status'            => 1,
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
