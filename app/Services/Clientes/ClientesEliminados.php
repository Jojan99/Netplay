<?php

namespace App\Services\Clientes;

use Illuminate\Support\Facades\DB;

/**
 * Clientes eliminados y su reinstalación.
 *
 * Eliminar un cliente en Netvula no borra nada: deja user_data.active = 0 y le
 * quita el servicio. Acá se listan esos clientes para poder volver a darles de
 * alta cuando piden reinstalación, con el aviso de lo que no vuelve solo (la
 * configuración del MikroTik y la IP, que puede haber quedado para otro).
 */
class ClientesEliminados
{
    /** @return array<string,mixed> */
    public function lista(array $filtros): array
    {
        $base = $this->base(trim((string) ($filtros['q'] ?? '')));

        $total     = (clone $base)->count();
        $porPagina = min(100, max(5, (int) ($filtros['per_page'] ?? 12)));
        $ultima    = max(1, (int) ceil($total / $porPagina));
        $pagina    = min($ultima, max(1, (int) ($filtros['page'] ?? 1)));

        $items = $base->select(
            'users.id',
            'users.username as alias',
            'user_data.names',
            'user_data.lastname',
            'user_data.dni',
            'user_data.phone',
            'user_data.email',
            'user_data.address',
            'user_data.connection_type',
            'user_data.pppoe_user',
            'user_data.router_id',
            'internet_plans.plan_name',
            'conection_routers.name as router_name',
            DB::raw("COALESCE(tabla_ips.ip, '') AS ip"),
            // Sin registro del borrado, la última modificación de la ficha es
            // lo más cercano a la fecha en que se eliminó.
            'user_data.updated_at as eliminado_en',
            DB::raw('(SELECT ual.created_at FROM user_audit_logs ual
                       WHERE ual.user_id = users.id AND ual.field_changed = \'cliente\' AND ual.new_value = \'eliminado\'
                       ORDER BY ual.id DESC LIMIT 1) AS eliminado_registrado_en'),
            DB::raw('(SELECT u2.username FROM user_audit_logs ual
                       JOIN users u2 ON u2.id = ual.changed_by
                       WHERE ual.user_id = users.id AND ual.field_changed = \'cliente\' AND ual.new_value = \'eliminado\'
                       ORDER BY ual.id DESC LIMIT 1) AS eliminado_por'),
            // La IP suele reutilizarse en otro cliente: hay que avisarlo antes
            // de reinstalar.
            DB::raw('EXISTS (SELECT 1 FROM user_data ud2
                       WHERE ud2.ip_assignment_id = user_data.ip_assignment_id
                         AND ud2.ip_assignment_id IS NOT NULL
                         AND ud2.active = 1
                         AND ud2.company_id = user_data.company_id) AS ip_ocupada')
        )
            ->orderByDesc('user_data.updated_at')
            ->orderByDesc('users.id')
            ->forPage($pagina, $porPagina)
            ->get();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $pagina,
            'per_page'  => $porPagina,
            'last_page' => $ultima,
        ];
    }

    /**
     * Vuelve a poner al cliente en la lista de activos.
     *
     * No toca el MikroTik: cuando se eliminó, sus credenciales se quitaron o se
     * suspendieron allá, y volver a escribirlas es la parte que hace la pantalla
     * del cliente ("Activar"), que ya sabe manejar PPPoE, ARP y listas.
     *
     * @return array{message:string, data:mixed, status:int}
     */
    /**
     * @param array{ip_accion?:string, ip?:string, interfaz?:string} $eleccion
     *   ip_accion: 'mantener' (la IP que tenía), 'cambiar' (ip + interfaz) o
     *   'ninguna' (queda sin IP, se elige después en la ficha). Sin ip_accion
     *   se hace lo de antes: si su IP es de otro, queda sin IP.
     */
    public function reinstalar(int $userId, array $eleccion = []): array
    {
        $companyId = (int) getSessionCompanyId();
        $cliente   = $this->cliente($userId, $companyId);

        if (!$cliente) {
            return ['message' => 'Ese cliente no existe en esta empresa.', 'data' => null, 'status' => 1];
        }

        if ((int) $cliente->active === 1) {
            return ['message' => 'Ese cliente ya está activo en el registro.', 'data' => null, 'status' => 1];
        }

        $avisos   = [];
        $cambios  = ['active' => 1, 'status' => 0, 'status_internet_id' => 1];
        $accion   = (string) ($eleccion['ip_accion'] ?? '');
        $ipNueva  = null;

        // La IP se decide antes de tocar nada: si lo elegido no se puede, no
        // se reinstala y se dice por qué.
        if ($accion === 'mantener') {
            if (!$cliente->ip) {
                return ['message' => 'No tenía IP para mantener: elegí una.', 'data' => null, 'status' => 1];
            }

            $r = $this->revisar($userId, $companyId, (string) $cliente->ip, null, (int) $cliente->router_id);

            if (!$r['libre']) {
                return ['message' => "No se puede mantener la IP {$cliente->ip}: {$r['motivo']}", 'data' => null, 'status' => 1];
            }

            // Si el registro de la IP lo comparte con otro, se le arma el suyo.
            $ipNueva = (string) $cliente->ip;
        } elseif ($accion === 'cambiar') {
            $ip = trim((string) ($eleccion['ip'] ?? ''));
            $interfaz = trim((string) ($eleccion['interfaz'] ?? ''));

            if ($ip === '' || $interfaz === '') {
                return ['message' => 'Para cambiarle la IP elegí la VLAN y la IP.', 'data' => null, 'status' => 1];
            }

            $r = $this->revisar($userId, $companyId, $ip, $interfaz, (int) $cliente->router_id);

            if (!$r['libre']) {
                return ['message' => "No se le puede dar la IP {$ip}: {$r['motivo']}", 'data' => null, 'status' => 1];
            }

            $ipNueva = $ip;
        } elseif ($accion === 'ninguna') {
            $cambios['ip_assignment_id'] = null;

            if (($cliente->connection_type ?? 'static') !== 'pppoe') {
                $avisos[] = 'Quedó sin IP: elegile una desde la ficha antes de activarlo.';
            }
        } elseif ($cliente->ip_assignment_id) {
            // Sin elegir (pantallas viejas): si la IP quedó para otro cliente, el
            // que vuelve se queda sin IP; dos con la misma rompen el router.
            $otro = DB::table('user_data')
                ->where('company_id', $companyId)
                ->where('ip_assignment_id', $cliente->ip_assignment_id)
                ->where('active', 1)
                ->where('user_id', '<>', $userId)
                ->first(['names', 'lastname', 'dni']);

            if ($otro) {
                $cambios['ip_assignment_id'] = null;
                $avisos[] = 'Su IP ' . ($cliente->ip ?: '') . ' ya es de ' . trim($otro->names . ' ' . $otro->lastname) . ', así que quedó sin IP: asignale una desde la ficha.';
            }
        } elseif (($cliente->connection_type ?? 'static') !== 'pppoe') {
            $avisos[] = 'No tiene IP asignada: elegile una desde la ficha antes de activarlo.';
        }

        if (($cliente->connection_type ?? 'static') === 'pppoe' && $cliente->pppoe_user) {
            $repetido = DB::table('user_data')
                ->where('company_id', $companyId)
                ->where('pppoe_user', $cliente->pppoe_user)
                ->where('active', 1)
                ->where('user_id', '<>', $userId)
                ->exists();

            if ($repetido) {
                $avisos[] = 'Su usuario PPPoE «' . $cliente->pppoe_user . '» ya lo usa otro cliente activo: cambiáselo antes de activarlo.';
            }
        }

        if (!$cliente->router_id) {
            $avisos[] = 'No tiene MikroTik asignado: elegilo en la ficha.';
        }

        if (!$cliente->internet_plans_id) {
            $avisos[] = 'No tiene plan de internet: asignale uno en la ficha.';
        }

        if (!DB::table('cab_facturations')->where('user_id', $userId)->exists()) {
            $avisos[] = 'No tiene grupo de facturación: revisá la ficha para que vuelva a facturarse.';
        }

        DB::table('user_data')->where('user_id', $userId)->where('company_id', $companyId)->update($cambios);

        if ($ipNueva !== null) {
            if ($cliente->ip_assignment_id) {
                \App\Services\Red\AsignacionDeIp::asignar($userId, $ipNueva, $companyId);
            } else {
                \App\Services\Red\AsignacionDeIp::fichaPropia($userId, $ipNueva, $companyId);
            }

            // Si el registro de la IP lo seguía compartiendo con otro cliente,
            // asignar() le deja el suyo; mantener la misma IP en un registro
            // compartido no se permite (revisar() ya lo frenó).
            $avisos[] = $accion === 'mantener' ? "Mantiene su IP {$ipNueva}." : "Queda con la IP nueva {$ipNueva}.";
        }

        DB::table('user_audit_logs')->insert([
            'user_id'       => $userId,
            'changed_by'    => getSessionUserId(),
            'company_id'    => $companyId,
            'field_changed' => 'cliente',
            'old_value'     => 'eliminado',
            'new_value'     => 'reinstalado',
            'description'   => 'Cliente reinstalado desde Clientes eliminados',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $nombre  = trim(($cliente->names ?? '') . ' ' . ($cliente->lastname ?? ''));
        $mensaje = $nombre . ' volvió al registro de clientes. Falta activarle el servicio en el MikroTik desde su ficha: al eliminarlo se le quitó o suspendió allá.';

        return [
            'message' => $mensaje,
            'data'    => ['avisos' => $avisos, 'user_id' => $userId],
            'status'  => 0,
        ];
    }

    /**
     * La IP que tenía el cliente antes de eliminarlo: ¿sigue libre?
     *
     * Se mira la plataforma (otro cliente con esa IP en su ficha) y el
     * MikroTik (otra entrada del ARP con esa IP a nombre de otro). No escribe
     * nada.
     *
     * @return array{message:string, data:mixed, status:int}
     */
    public function revisarIp(int $userId): array
    {
        $companyId = (int) getSessionCompanyId();
        $cliente   = $this->cliente($userId, $companyId);

        if (!$cliente) {
            return ['message' => 'Ese cliente no existe en esta empresa.', 'data' => null, 'status' => 1];
        }

        $tipo = ($cliente->connection_type ?? 'static') === 'pppoe' ? 'pppoe' : 'static';
        $data = ['tipo' => $tipo, 'ip' => $cliente->ip ?: null, 'router_id' => $cliente->router_id ? (int) $cliente->router_id : null,
            'libre' => false, 'motivo' => null, 'interfaz' => null, 'router_revisado' => false];

        if ($cliente->ip) {
            $data = array_merge($data, $this->revisar($userId, $companyId, (string) $cliente->ip, null, (int) $cliente->router_id));
        }

        return ['message' => 'OK', 'data' => $data, 'status' => 0];
    }

    /**
     * ¿Se le puede dar esa IP al cliente? Sin $interfaz se usa la red del
     * router que la contiene.
     *
     * @return array{libre:bool, motivo:?string, interfaz:?string, router_revisado:bool}
     */
    private function revisar(int $userId, int $companyId, string $ip, ?string $interfaz, int $routerId): array
    {
        $no = fn (string $m, bool $revisado = false, ?string $i = null) => ['libre' => false, 'motivo' => $m, 'interfaz' => $i, 'router_revisado' => $revisado];

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $no("«{$ip}» no es una IP válida.");
        }

        // Otro cliente con esa IP en la plataforma (activo o no: un retirado
        // que vuelva chocaría igual).
        if ($otro = \App\Services\Red\IpFijaEnElRouter::clienteConIp($companyId, $ip, $userId)) {
            return $no("la tiene {$otro['nombre']} en la plataforma.");
        }

        // El mismo registro de IP compartido con otro cliente: cambiarlo le
        // cambiaría la IP al otro.
        $compartida = DB::table('user_data as ud')
            ->join('user_data as yo', 'yo.ip_assignment_id', '=', 'ud.ip_assignment_id')
            ->where('yo.user_id', $userId)->where('ud.user_id', '<>', $userId)
            ->whereNotNull('ud.ip_assignment_id')
            ->first(['ud.names', 'ud.lastname', 'ud.active']);

        if ($compartida && (int) $compartida->active === 1) {
            return $no('la tiene ' . trim("{$compartida->names} {$compartida->lastname}") . ' en la plataforma.');
        }

        $token = DB::table('conection_routers')->where('company_id', $companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))->orderBy('id')->value('token');

        if (!$token) {
            return ['libre' => true, 'motivo' => 'No tiene MikroTik asignado: no se pudo revisar el router.', 'interfaz' => $interfaz, 'router_revisado' => false];
        }

        try {
            $api = app(\App\Managers\Interfaces\ConectionRouterManagerInterface::class)->conection($token);
            $interfaz ??= (new \App\Services\Red\AprovisionamientoDeOnt($companyId))->redEnElRouter($api, $ip)['interfaz'] ?? null;

            if (!$interfaz) {
                return $no('no es de ninguna red del MikroTik.', true);
            }

            $r = (new \App\Services\Red\IpFijaEnElRouter($api, $companyId))->revisar($ip, $interfaz, $userId);
        } catch (\Throwable $e) {
            return ['libre' => true, 'motivo' => 'No se pudo revisar el MikroTik (' . mb_substr($e->getMessage(), 0, 80) . '): en la plataforma está libre.',
                'interfaz' => $interfaz, 'router_revisado' => false];
        }

        if (!$r['ok']) {
            // El mensaje de revisar() viene como oración completa.
            return $no(lcfirst(preg_replace('/^La IP \S+ /', '', $r['mensaje'])), true, $interfaz);
        }

        return ['libre' => true, 'motivo' => $r['huerfana'] ? 'En el router está en el ARP sin cliente: se toma para él al activarlo.' : null,
            'interfaz' => $interfaz, 'router_revisado' => true];
    }

    // ── Apoyo ─────────────────────────────────────────────────────────────────

    private function base(string $q): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('users')
            ->join('user_data', 'users.id', 'user_data.user_id')
            ->join('profiles', 'profiles.id', 'users.profile_id')
            ->leftJoin('internet_plans', 'user_data.internet_plans_id', 'internet_plans.id')
            ->leftJoin('tabla_ips', 'user_data.ip_assignment_id', 'tabla_ips.id')
            ->leftJoin('conection_routers', 'user_data.router_id', 'conection_routers.id')
            ->where('user_data.active', 0)
            // Sólo clientes: las cuentas del equipo dadas de baja también
            // quedan con active = 0 y se manejan desde Equipo de trabajo.
            ->where('profiles.name', 'USER')
            ->where('users.company_id', getSessionCompanyId());

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('user_data.names', 'like', $like)
                  ->orWhere('user_data.lastname', 'like', $like)
                  ->orWhereRaw("CONCAT_WS(' ', user_data.names, user_data.lastname) LIKE ?", [$like])
                  ->orWhere('user_data.dni', 'like', $like)
                  ->orWhere('user_data.phone', 'like', $like)
                  ->orWhere('user_data.email', 'like', $like)
                  ->orWhere('tabla_ips.ip', 'like', $like)
                  ->orWhere('users.username', 'like', $like);
            });
        }

        return $query;
    }

    private function cliente(int $userId, int $companyId): ?object
    {
        return DB::table('user_data')
            ->join('users', 'users.id', 'user_data.user_id')
            ->join('profiles', 'profiles.id', 'users.profile_id')
            ->leftJoin('tabla_ips', 'user_data.ip_assignment_id', 'tabla_ips.id')
            ->where('user_data.user_id', $userId)
            ->where('user_data.company_id', $companyId)
            ->where('profiles.name', 'USER')
            ->first([
                'user_data.active', 'user_data.names', 'user_data.lastname', 'user_data.ip_assignment_id',
                'user_data.connection_type', 'user_data.pppoe_user', 'user_data.router_id',
                'user_data.internet_plans_id', 'tabla_ips.ip',
            ]);
    }
}
