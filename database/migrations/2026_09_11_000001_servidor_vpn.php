<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VPN propia para llegar a equipos que están en redes privadas.
 *
 * Hoy la plataforma alcanza las OLT abriendo una sesión SSH en el MikroTik del
 * cliente y tunelizando por ahí (el "jump host"): depende de que ese router
 * tenga SSH expuesto, con usuario y contraseña guardados, y cada consulta paga
 * el costo de abrir la sesión. Con un túnel WireGuard el router marca hacia
 * acá una sola vez y la OLT queda direccionable como si fuera local.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Un solo servidor por instalación, pero en tabla para poder rotar la
        // clave sin tocar configuración ni volver a desplegar.
        Schema::create('vpn_servidor', function (Blueprint $table) {
            $table->id();
            $table->string('interfaz', 32)->default('wg-netplay');
            $table->string('endpoint_host');            // a dónde marcan los routers
            $table->unsignedInteger('listen_port')->default(51820);
            $table->string('subred', 32)->default('10.200.200.0/24');
            $table->string('ip_servidor', 45);          // su IP dentro del túnel
            $table->text('clave_privada');              // cifrada
            $table->string('clave_publica');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('vpn_tuneles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('nombre');                   // el sitio: "Nodo Rebolo"
            $table->unsignedBigInteger('router_id')->nullable();  // conection_routers

            $table->text('clave_privada');              // cifrada; se muestra una sola vez
            $table->string('clave_publica');
            $table->text('clave_compartida')->nullable();  // preshared, cifrada

            $table->string('ip_tunel', 45)->unique();   // su IP dentro del túnel
            // Las redes que quedan alcanzables detrás de este router: la de la
            // OLT, la de administración, las que haga falta.
            $table->json('redes_remotas')->nullable();

            $table->unsignedInteger('puerto_router')->default(13231);
            $table->unsignedInteger('keepalive')->default(25);

            $table->boolean('activo')->default(true);
            $table->timestamp('ultimo_saludo')->nullable();   // last handshake
            $table->unsignedBigInteger('bytes_rx')->default(0);
            $table->unsignedBigInteger('bytes_tx')->default(0);
            $table->timestamp('aplicado_en')->nullable();     // cuándo entró al servidor
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_tuneles');
        Schema::dropIfExists('vpn_servidor');
    }
};
