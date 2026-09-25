<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passkeys para la consola de Netvula.
 *
 * Mejoran al código de seis dígitos en lo que de verdad importa: **no se
 * pueden robar por engaño**. Un código se puede escribir en una página falsa
 * que se haga pasar por Netvula; una passkey está atada al dominio y el
 * navegador se niega a usarla en otro sitio. No es difícil, es imposible.
 *
 * Y acá no queda ningún secreto: sólo la clave **pública** del dispositivo.
 * Si alguien se lleva la base, no tiene nada con qué entrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plataforma_passkeys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usuario_id')->constrained('plataforma_usuarios')->cascadeOnDelete();

            // El id que el navegador manda al entrar, para encontrar cuál es.
            $t->string('credential_id', 255)->unique();

            // La credencial entera como la deja la librería: clave pública,
            // contador de uso y de qué dispositivo vino. Nada de esto sirve
            // para hacerse pasar por el usuario.
            $t->json('datos');

            // Para reconocerla en la lista: alguien con tres dispositivos
            // tiene que poder saber cuál está borrando.
            $t->string('nombre', 120)->nullable();
            $t->timestamp('ultimo_uso_en')->nullable();
            $t->timestamps();

            $t->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plataforma_passkeys');
    }
};
