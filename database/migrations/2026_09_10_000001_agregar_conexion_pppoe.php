<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que un cliente se conecte por PPPoE y no sólo con IP fija.
 *
 * Hasta ahora el sistema daba por sentado que todos los clientes tienen una IP
 * fija anotada en el ARP del router. Muchos ISP trabajan con PPPoE, donde el
 * cliente se autentica con usuario y contraseña y la IP se la da un pool, así
 * que el tipo de conexión pasa a ser un dato del cliente.
 *
 * Los que ya existen quedan como 'static', que es lo que son hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_data', function (Blueprint $tabla) {
            if (!Schema::hasColumn('user_data', 'connection_type')) {
                $tabla->enum('connection_type', ['static', 'pppoe'])
                    ->default('static')
                    ->after('ip_assignment_id');
            }

            if (!Schema::hasColumn('user_data', 'pppoe_user')) {
                $tabla->string('pppoe_user', 120)->nullable()->after('connection_type');
            }

            if (!Schema::hasColumn('user_data', 'pppoe_password')) {
                // Va cifrada: es la credencial con la que el cliente entra a la red.
                $tabla->text('pppoe_password')->nullable()->after('pppoe_user');
            }

            if (!Schema::hasColumn('user_data', 'pppoe_profile')) {
                $tabla->string('pppoe_profile', 120)->nullable()->after('pppoe_password');
            }
        });

        // Un mismo usuario PPPoE no puede estar en dos clientes de la empresa.
        Schema::table('user_data', function (Blueprint $tabla) {
            $indices = collect(\Illuminate\Support\Facades\DB::select('SHOW INDEX FROM user_data'))
                ->pluck('Key_name')->unique();

            if (!$indices->contains('user_data_pppoe_unico')) {
                $tabla->unique(['company_id', 'pppoe_user'], 'user_data_pppoe_unico');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_data', function (Blueprint $tabla) {
            $indices = collect(\Illuminate\Support\Facades\DB::select('SHOW INDEX FROM user_data'))
                ->pluck('Key_name')->unique();

            if ($indices->contains('user_data_pppoe_unico')) {
                $tabla->dropUnique('user_data_pppoe_unico');
            }

            foreach (['connection_type', 'pppoe_user', 'pppoe_password', 'pppoe_profile'] as $columna) {
                if (Schema::hasColumn('user_data', $columna)) {
                    $tabla->dropColumn($columna);
                }
            }
        });
    }
};
