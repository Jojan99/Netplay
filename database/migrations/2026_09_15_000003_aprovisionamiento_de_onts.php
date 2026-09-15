<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprovisionamiento automático de las ONT al autorizarlas: los ajustes van con
 * el acceso remoto de la empresa y cada equipo programado queda en su tabla,
 * con los pasos que se le aplicaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gestion_remota', function (Blueprint $table) {
            $table->boolean('aprovisionar')->default(false)->after('perfiles_acs');
            $table->boolean('aprov_wan')->default(true)->after('aprovisionar');
            $table->boolean('aprov_wifi')->default(true)->after('aprov_wan');
            $table->boolean('aprov_admin')->default(false)->after('aprov_wifi');
            $table->string('wifi_prefijo', 20)->nullable()->after('aprov_admin');
            $table->string('onu_admin_usuario', 32)->nullable()->after('wifi_prefijo');
            $table->text('onu_admin_clave')->nullable()->after('onu_admin_usuario');
        });

        Schema::create('aprovisionamientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('olt_id');
            $table->string('fsp', 20);
            $table->unsignedInteger('ont_id');
            $table->string('serial', 40)->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('datos');
            $table->text('wifi_clave')->nullable();
            $table->string('estado', 20)->default('esperando')->index();
            $table->string('acs_id')->nullable();
            $table->json('pasos')->nullable();
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->string('detalle', 255)->nullable();
            $table->timestamp('listo_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aprovisionamientos');

        Schema::table('gestion_remota', function (Blueprint $table) {
            $table->dropColumn(['aprovisionar', 'aprov_wan', 'aprov_wifi', 'aprov_admin', 'wifi_prefijo', 'onu_admin_usuario', 'onu_admin_clave']);
        });
    }
};
