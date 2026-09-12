<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada empresa dice dónde está su servidor TR-069 y la plataforma se encarga
 * del resto: qué redes hacen falta, el túnel y el script del router.
 *
 * Antes esto era una sola instalación compartida y las redes se escribían a
 * mano en notación CIDR, que es justo lo que un operador no tiene por qué saber.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acs_servidores', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('company_id')->unique();

            // 'plataforma' usa el servidor que ya corre acá; 'propio', el de la empresa.
            $tabla->string('modo', 20)->default('plataforma');

            // Dónde lo alcanzan las ONT y por dónde lo alcanza la plataforma.
            $tabla->string('host', 120)->nullable();
            $tabla->unsignedSmallInteger('puerto_cwmp')->default(7547);
            $tabla->string('url_nbi', 160)->nullable();

            // 'publica': las ONT llegan solas por internet.
            // 'tunel':   el servidor está en una red privada y se llega por la VPN.
            $tabla->string('alcance', 20)->default('publica');
            $tabla->unsignedBigInteger('vpn_tunel_id')->nullable();
            $tabla->unsignedBigInteger('router_id')->nullable();

            // Las redes detectadas del router, con su nombre en castellano.
            $tabla->json('redes')->nullable();
            $tabla->timestamp('detectado_en')->nullable();
            $tabla->timestamp('aplicado_en')->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->text('notas')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acs_servidores');
    }
};
