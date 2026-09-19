<?php

namespace App\Services\Equipo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baja y alta de cuentas del equipo (ADMIN, TÉCNICO, CONTADOR).
 *
 * Borrar la fila del usuario sin más dejaría tickets, instalaciones, egresos y
 * movimientos de inventario apuntando a un id que ya no existe: la pantalla los
 * mostraría "sin responsable". Por eso la cuenta se borra de verdad sólo cuando
 * no tiene nada a su nombre; si ya trabajó, se desactiva y el historial queda
 * completo.
 */
class BajaDePersonal
{
    /** Perfiles que son del equipo y no clientes. */
    private const PERFILES = ['ADMIN', 'TECNICO', 'CONTADOR'];

    /** @return array{message:string, data:mixed, status:int} */
    public function eliminar(int $userId): array
    {
        $companyId = (int) getSessionCompanyId();
        $usuario   = $this->usuario($userId, $companyId);

        if (!$usuario) {
            return $this->error('Esa cuenta no existe en esta empresa.');
        }

        if (!in_array(strtoupper((string) $usuario->profile_name), self::PERFILES, true)) {
            return $this->error('Esa cuenta es de un cliente, no del equipo. Los clientes se eliminan desde la pantalla de clientes.');
        }

        if ($userId === (int) getSessionUserId()) {
            return $this->error('No podés eliminar tu propia cuenta. Pedile a otro administrador que lo haga.');
        }

        if ((int) $usuario->active === 0) {
            return $this->error('Esa cuenta ya estaba desactivada.');
        }

        if (strtoupper((string) $usuario->profile_name) === 'ADMIN' && $this->administradoresActivos($companyId) <= 1) {
            return $this->error('Es el único administrador activo de la empresa. Creá o activá otro administrador antes de eliminar este.');
        }

        $nombre = trim(($usuario->names ?? '') . ' ' . ($usuario->lastname ?? '')) ?: $usuario->username;
        $rastro = $this->rastro($userId);

        if ($rastro === []) {
            // Nada a su nombre: se borra de verdad y el usuario queda libre
            // para volver a usarse.
            DB::transaction(function () use ($userId) {
                DB::table('user_data')->where('user_id', $userId)->delete();
                DB::table('users')->where('id', $userId)->delete();
            });

            $this->anotar($userId, $companyId, 'eliminada', 'Cuenta de personal eliminada: ' . $nombre);

            return [
                'message' => 'Se eliminó la cuenta de ' . $nombre . '. No tenía trabajo registrado, así que el usuario «' . $usuario->username . '» queda libre.',
                'data'    => ['modo' => 'eliminada', 'usuario' => $usuario->username],
                'status'  => 0,
            ];
        }

        DB::transaction(function () use ($userId) {
            // status = 1 es la marca de "dada de baja": el login la distingue
            // de una cuenta nueva sin confirmar.
            DB::table('users')->where('id', $userId)->update(['active' => 0, 'status' => 1]);
            DB::table('user_data')->where('user_id', $userId)->update(['active' => 0]);
        });

        $this->anotar($userId, $companyId, 'desactivada', 'Cuenta de personal desactivada: ' . $nombre);

        return [
            'message' => 'La cuenta de ' . $nombre . ' quedó desactivada: ya no puede entrar al sistema. No se borró porque tiene trabajo registrado (' . $this->enPalabras($rastro) . ') y así los registros siguen mostrando quién los atendió.',
            'data'    => ['modo' => 'desactivada', 'usuario' => $usuario->username, 'rastro' => $rastro],
            'status'  => 0,
        ];
    }

    /** @return array{message:string, data:mixed, status:int} */
    public function reactivar(int $userId): array
    {
        $companyId = (int) getSessionCompanyId();
        $usuario   = $this->usuario($userId, $companyId);

        if (!$usuario) {
            return $this->error('Esa cuenta no existe en esta empresa.');
        }

        if ((int) $usuario->active === 1) {
            return $this->error('Esa cuenta ya está activa.');
        }

        DB::transaction(function () use ($userId) {
            DB::table('users')->where('id', $userId)->update(['active' => 1, 'status' => 0]);
            DB::table('user_data')->where('user_id', $userId)->update(['active' => 1]);
        });

        $nombre = trim(($usuario->names ?? '') . ' ' . ($usuario->lastname ?? '')) ?: $usuario->username;
        $this->anotar($userId, $companyId, 'reactivada', 'Cuenta de personal reactivada: ' . $nombre);

        return [
            'message' => 'La cuenta de ' . $nombre . ' volvió a quedar activa, con la misma contraseña de antes.',
            'data'    => ['usuario' => $usuario->username],
            'status'  => 0,
        ];
    }

    // ── Apoyo ─────────────────────────────────────────────────────────────────

    private function usuario(int $userId, int $companyId): ?object
    {
        return DB::table('users')
            ->join('profiles', 'profiles.id', 'users.profile_id')
            ->leftJoin('user_data', 'user_data.user_id', 'users.id')
            ->where('users.id', $userId)
            ->where('users.company_id', $companyId)
            ->first(['users.id', 'users.username', 'users.active', 'profiles.name as profile_name', 'user_data.names', 'user_data.lastname']);
    }

    private function administradoresActivos(int $companyId): int
    {
        return (int) DB::table('users')
            ->join('profiles', 'profiles.id', 'users.profile_id')
            ->where('users.company_id', $companyId)
            ->where('users.active', 1)
            ->where('profiles.name', 'ADMIN')
            ->count();
    }

    /**
     * Qué tiene a su nombre. Cada entrada es 'texto' => cantidad; si vuelve
     * vacío, la cuenta no dejó rastro y se puede borrar.
     *
     * @return array<string,int>
     */
    private function rastro(int $userId): array
    {
        $fuentes = [
            'tickets atendidos'          => ['tickets', 'technical_id'],
            'tickets creados'            => ['tickets', 'user_created_id'],
            'notas de tickets'           => ['ticket_notes', 'user_id'],
            'instalaciones creadas'      => ['installation_orders', 'created_by'],
            'instalaciones asignadas'    => ['installation_orders', 'assigned_by'],
            'movimientos de inventario'  => ['inventory_movements', 'user_id'],
            'egresos'                    => ['egresses', 'user_id'],
            'cambios en clientes'        => ['user_audit_logs', 'changed_by'],
            'acuerdos de pago'           => ['payment_commitments', 'created_by'],
            'importaciones'              => ['importaciones', 'user_id'],
            'ficha de empleado'          => ['employees', 'user_id'],
            'agente de WhatsApp'         => ['crm_agents', 'user_id'],
            'routers a su nombre'        => ['conection_routers', 'user_id'],
        ];

        $rastro = [];

        foreach ($fuentes as $texto => [$tabla, $columna]) {
            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, $columna)) {
                continue;
            }

            $n = (int) DB::table($tabla)->where($columna, $userId)->count();

            if ($n > 0) {
                $rastro[$texto] = $n;
            }
        }

        // Los técnicos de una instalación van en una lista JSON, no en una columna.
        if (Schema::hasTable('installation_orders') && Schema::hasColumn('installation_orders', 'technician_ids')) {
            $n = (int) DB::table('installation_orders')
                ->whereRaw('JSON_SEARCH(technician_ids, ?, ?) IS NOT NULL', ['one', (string) $userId])
                ->count();

            if ($n > 0) {
                $rastro['instalaciones como técnico'] = $n;
            }
        }

        return $rastro;
    }

    /** @param array<string,int> $rastro */
    private function enPalabras(array $rastro): string
    {
        $partes = [];

        foreach (array_slice($rastro, 0, 3) as $texto => $n) {
            $partes[] = $n . ' ' . $texto;
        }

        if (count($rastro) > 3) {
            $partes[] = 'y más';
        }

        return implode(', ', $partes);
    }

    private function anotar(int $userId, int $companyId, string $nuevo, string $descripcion): void
    {
        DB::table('user_audit_logs')->insert([
            'user_id'       => $userId,
            'changed_by'    => getSessionUserId(),
            'company_id'    => $companyId,
            'field_changed' => 'cuenta_personal',
            'old_value'     => 'activa',
            'new_value'     => $nuevo,
            'description'   => mb_substr($descripcion, 0, 255),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /** @return array{message:string, data:mixed, status:int} */
    private function error(string $mensaje): array
    {
        return ['message' => $mensaje, 'data' => null, 'status' => 1];
    }
}
