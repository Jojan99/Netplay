<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos que necesita la OLT cuando no es Huawei.
 *
 * Cada fabricante pide algo propio para autorizar una ONT: ZTE el tipo de ONU y
 * el perfil de tráfico, V-SOL el perfil de ONU. Y se guarda también la foto que
 * el operador suba de su equipo, junto con el modelo que el equipo declaró por
 * SNMP para poder mostrarlo sin volver a preguntárselo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olt_admins', function (Blueprint $table) {
            $table->string('zte_onu_type', 60)->nullable()->after('ont_srvprofile_id');
            $table->string('zte_dba_profile', 60)->nullable()->after('zte_onu_type');
            $table->string('vsol_onu_profile', 60)->nullable()->after('zte_dba_profile');
            $table->string('photo_path')->nullable()->after('vsol_onu_profile');
            $table->string('model', 80)->nullable()->after('brand');
        });
    }

    public function down(): void
    {
        Schema::table('olt_admins', function (Blueprint $table) {
            $table->dropColumn([
                'zte_onu_type', 'zte_dba_profile', 'vsol_onu_profile', 'photo_path', 'model',
            ]);
        });
    }
};
