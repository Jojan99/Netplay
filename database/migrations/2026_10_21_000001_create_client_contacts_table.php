<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contactos adicionales del cliente: quién paga si no es el titular, un teléfono de respaldo,
 * alguien autorizado para pedir cambios. Antes vivían en notas sueltas de los tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('nombre', 120);
            $table->string('relacion', 60)->nullable();   // hijo, mamá, arrendador…
            $table->string('telefono', 30)->nullable();
            $table->string('rol', 20)->default('respaldo'); // paga | respaldo | autorizado
            $table->string('nota', 255)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
    }
};
