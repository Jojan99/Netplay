<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las empresas registradas desde el alta pública no tenían perfil USER, y el
 * alta de clientes caía en el primer perfil de la empresa (ADMIN). Crea el
 * perfil que falta y devuelve a USER a los clientes que quedaron de admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sinPerfil = DB::table('companies')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('profiles')
                ->whereColumn('profiles.company_id', 'companies.id')
                ->where('profiles.name', 'USER'))
            ->pluck('id');

        foreach ($sinPerfil as $companyId) {
            $userProfileId = DB::table('profiles')->insertGetId([
                'company_id' => $companyId,
                'name'       => 'USER',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Un cliente tiene cabecera de facturación; el administrador que se
            // crea al registrar la empresa no. En estas empresas sólo el alta de
            // clientes pudo dejar un ADMIN con facturación.
            $adminIds = DB::table('profiles')
                ->where('company_id', $companyId)
                ->where('name', 'ADMIN')
                ->pluck('id');

            $clientes = DB::table('users')
                ->where('company_id', $companyId)
                ->whereIn('profile_id', $adminIds)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('cab_facturations')
                    ->whereColumn('cab_facturations.user_id', 'users.id'))
                ->pluck('id');

            if ($clientes->isEmpty()) {
                continue;
            }

            DB::table('users')->whereIn('id', $clientes)->update(['profile_id' => $userProfileId]);
            DB::table('user_data')->whereIn('user_id', $clientes)->update(['role_id' => $userProfileId]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: volver a dejar clientes como administradores es el error que se corrige.
    }
};
