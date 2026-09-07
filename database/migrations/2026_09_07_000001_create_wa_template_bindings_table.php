<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula un hecho del negocio ("el pago quedó aprobado") con la plantilla de
 * Meta que se le manda al cliente cuando la ventana de 24 h está cerrada.
 *
 * Se guarda por empresa para que cada una use sus propias plantillas, y con el
 * mapa de variables, porque el orden de los {{n}} lo decide quien redacta la
 * plantilla en Meta, no el código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_template_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('event', 40);
            $table->string('template_name', 120)->nullable();
            $table->string('language', 10)->default('es_CO');
            $table->boolean('enabled')->default(false);
            $table->json('params')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'event']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_template_bindings');
    }
};
