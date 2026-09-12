<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La velocidad de cada plan, en términos de negocio y no de router.
 *
 * El operador dice cuánto baja y cuánto sube el cliente, y si quiere ráfaga
 * —esos primeros segundos más rápidos que hacen que una página abra de
 * golpe—. La plataforma traduce eso a lo que el MikroTik entiende.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_plans', function (Blueprint $t) {
            $t->unsignedInteger('bajada_mbps')->nullable()->after('upload_speed');
            $t->unsignedInteger('subida_mbps')->nullable()->after('bajada_mbps');
            $t->boolean('rafaga')->default(false)->after('subida_mbps');
            $t->unsignedInteger('rafaga_bajada_mbps')->nullable()->after('rafaga');
            $t->unsignedInteger('rafaga_subida_mbps')->nullable()->after('rafaga_bajada_mbps');
            $t->unsignedSmallInteger('rafaga_segundos')->default(8)->after('rafaga_subida_mbps');
            $t->unsignedTinyInteger('prioridad')->default(8)->after('rafaga_segundos');
            $t->timestamp('control_aplicado_en')->nullable()->after('prioridad');
        });

        Schema::table('user_data', function (Blueprint $t) {
            // 'plan' sigue la velocidad de su plan; 'sin_limite' queda fuera
            // del control (un cliente corporativo, una antena, una prueba).
            $t->string('control_velocidad', 20)->default('plan')->after('pppoe_profile');
        });
    }

    public function down(): void
    {
        Schema::table('internet_plans', function (Blueprint $t) {
            $t->dropColumn([
                'bajada_mbps', 'subida_mbps', 'rafaga', 'rafaga_bajada_mbps',
                'rafaga_subida_mbps', 'rafaga_segundos', 'prioridad', 'control_aplicado_en',
            ]);
        });

        Schema::table('user_data', fn (Blueprint $t) => $t->dropColumn('control_velocidad'));
    }
};
