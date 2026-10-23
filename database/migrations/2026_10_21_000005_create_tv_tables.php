<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Televisión por IPTV (21-10-2026): el panel XUI.one de la empresa, las líneas de los clientes
 * (usuario y clave con los que ven TV) y los canales que se vigilan para avisar cuando se caen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_paneles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->string('url', 200);
            $t->string('codigo_api', 60);
            $t->text('api_key');
            // Lo que va en la URL que recibe el cliente; si no hay, la del panel.
            $t->string('url_clientes', 200)->nullable();
            $t->json('bouquets')->nullable();
            $t->unsignedTinyInteger('pantallas')->default(2);
            // Suspender y reactivar la TV con la cartera. Apagado hasta que la empresa lo prenda.
            $t->boolean('sincronizar_cartera')->default(false);
            $t->timestamp('ultima_conexion_en')->nullable();
            $t->string('ultimo_error', 255)->nullable();
            $t->timestamps();
        });

        Schema::create('tv_lineas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->unsignedInteger('xui_id')->nullable();
            $t->string('usuario', 80);
            $t->text('clave');
            $t->json('bouquets')->nullable();
            $t->unsignedTinyInteger('pantallas')->default(1);
            $t->string('estado', 20)->default('pendiente');
            $t->string('origen', 20)->default('plataforma');
            // auto | manual | revisar: cómo quedó ligada a un cliente.
            $t->string('vinculo', 20)->nullable();
            $t->unsignedBigInteger('sugerido_user_id')->nullable();
            $t->string('sugerido_motivo', 120)->nullable();
            $t->timestamp('ultima_actividad_en')->nullable();
            $t->string('error', 255)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'xui_id']);
        });

        Schema::create('tv_canales', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedInteger('stream_id');
            $t->string('nombre', 150);
            $t->string('fuente', 255)->nullable();
            $t->boolean('vigilar')->default(true);
            // al_aire | caido | arrancando
            $t->string('estado', 20)->default('al_aire');
            $t->timestamp('estado_desde')->nullable();
            $t->unsignedInteger('bitrate')->nullable();
            $t->unsignedInteger('espectadores')->default(0);
            $t->timestamp('revisado_en')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'stream_id']);
        });

        Schema::table('internet_plans', function (Blueprint $t) {
            $t->boolean('tv_incluida')->default(false)->after('pppoe_profile');
            $t->json('tv_bouquets')->nullable()->after('tv_incluida');
            $t->unsignedTinyInteger('tv_pantallas')->nullable()->after('tv_bouquets');
        });
    }

    public function down(): void
    {
        Schema::table('internet_plans', fn (Blueprint $t) => $t->dropColumn(['tv_incluida', 'tv_bouquets', 'tv_pantallas']));
        Schema::dropIfExists('tv_canales');
        Schema::dropIfExists('tv_lineas');
        Schema::dropIfExists('tv_paneles');
    }
};
