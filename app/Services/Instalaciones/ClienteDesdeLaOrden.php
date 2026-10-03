<?php

namespace App\Services\Instalaciones;

use App\Models\InstallationOrder;
use App\Models\User;
use App\Models\UserData;
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
            ->value('u.id');

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
            if (!$orden->pppoe_password) $falta[] = 'la contraseña PPPoE';
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

        // El router antes que el cliente, a propósito —mismo criterio que CreateUserDataUseCase
        // (el alta de siempre)—: si lo rechaza, mejor que no quede un cliente en la plataforma
        // que no existe en la red. Esto es lo que faltaba de verdad: la ONT quedaba autorizada y
        // con la conexión programada por TR-069, pero como el router nunca tuvo la credencial
        // PPPoE (ni, con IP fija, la entrada en el ARP), el equipo pedía línea y nadie contestaba.
        $arp = null;

        if ($orden->connection_type === 'pppoe') {
            if ($error = self::altaPppoe($orden)) {
                throw new \RuntimeException($error);
            }
        } else {
            $arp = self::asegurarIpEnArp($orden);

            if (!($arp['ok'] ?? false)) {
                throw new \RuntimeException($arp['mensaje'] ?: 'No se pudo reservar la IP en el router.');
            }
        }

        $user = User::create([
            'company_id' => $orden->company_id,
            'username'   => $orden->client_dni,
            'email'      => $orden->client_email ?: null,
            'password'   => Hash::make(bin2hex(random_bytes(8))),
            'profile_id' => $perfilCliente,
        ]);

        // Eloquent, no el query builder: «pppoe_password» está marcado «encrypted» en UserData, y
        // sólo el modelo lo cifra al guardar. Insertarlo con DB::table() lo dejaba en texto plano
        // —tal como lo entrega $orden->pppoe_password, ya descifrado por el cast de InstallationOrder—
        // y cualquier pantalla que después leyera ese cliente reventaba al intentar descifrarlo
        // («The payload is invalid.»), tumbando la lista de instalaciones entera.
        // Con IP fija, la reserva en el ARP devuelve la fila de tabla_ips que hay que colgar del
        // cliente; con PPPoE no hay IP propia, la reparte el pool del perfil.
        $ipAssignmentId = $orden->connection_type !== 'pppoe'
            ? app(\App\Repositories\Interfaces\InternetInfoRepositoryInterface::class)
                ->AssignemetIpUser($orden->ip_asignada, $user->id, (string) ($arp['mac'] ?? ''))
            : null;

        $userData = UserData::create([
            'company_id'        => $orden->company_id,
            'user_id'           => $user->id,
            'ip_assignment_id'  => $ipAssignmentId,
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
        ]);
        $userDataId = $userData->id;

        self::abrirFacturacion((int) $user->id, (int) $orden->company_id, (int) $orden->grupo_facturacion);

        // Lo que identifica a un cliente en el resto del sistema (instalaciones, OLT, inventario) es
        // el id de «user_data», no el de «users» —son dos tablas con su propio contador—. Devolver
        // el de «users» hacía que la ONT, el inventario y la orden quedaran apuntando a una fila que
        // no existía en «user_data», y reventaba al final con una llave foránea.
        return (int) $userDataId;
    }

    /**
     * Le crea la credencial PPPoE en el router (o se la actualiza si ya existía). Sin esto la
     * ONT quedaba configurada por TR-069 para pedir esa línea, pero el router nunca la conocía:
     * el equipo marcaba y del otro lado no había nadie que le contestara.
     */
    private static function altaPppoe(InstallationOrder $orden): ?string
    {
        $usuario = trim((string) $orden->pppoe_user);
        $clave = (string) $orden->pppoe_password;
        // Sin perfil elegido se usa el del plan, que es el que fija la velocidad en PPPoE.
        $perfil = trim((string) $orden->pppoe_profile);

        if ($perfil === '') {
            $perfil = (string) DB::table('internet_plans')
                ->where('id', $orden->internet_plan_id)
                ->where('company_id', $orden->company_id)
                ->value('pppoe_profile');
        }

        $perfil = $perfil ?: 'default';

        if ($usuario === '' || $clave === '') {
            return 'Para una conexión PPPoE hacen falta el usuario y la contraseña.';
        }

        $token = DB::table('conection_routers')
            ->where('company_id', $orden->company_id)
            ->when($orden->router_id, fn ($q) => $q->where('id', $orden->router_id))
            ->value('token');

        if (!$token) {
            return 'No hay un router configurado para dar de alta la conexión PPPoE.';
        }

        try {
            (new \App\Services\Red\ServicioPppoe(
                app(\App\Managers\Interfaces\ConectionRouterManagerInterface::class),
                $token,
            ))->crear($usuario, $clave, $perfil, (string) $orden->client_dni);

            return null;
        } catch (\Throwable $e) {
            Log::error('[Instalación] No se pudo crear la credencial PPPoE', ['orden' => $orden->id, 'error' => $e->getMessage()]);

            return 'No se pudo crear el usuario PPPoE en el router: ' . $e->getMessage();
        }
    }

    /** Reserva la IP en el ARP del router del cliente, igual que el alta de siempre. */
    private static function asegurarIpEnArp(InstallationOrder $orden): array
    {
        return app(\App\UseCases\ManagementRouter\Interfaces\GetIpAvaliblesUseCaseInterface::class)->asegurarIpEnArp(
            ip: (string) $orden->ip_asignada,
            vlan: (string) ($orden->vlan ?: ''),
            documento: (string) $orden->client_dni,
            userId: null,
            routerId: $orden->router_id ? (int) $orden->router_id : null,
        );
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
