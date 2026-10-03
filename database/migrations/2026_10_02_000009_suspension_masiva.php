<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspensión masiva: el operador elige un grupo de corte, quita a quien quiera, y ordena avisar,
 * suspender, o las dos cosas. Cada orden queda como un lote con el resultado cliente por cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspension_lotes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('creado_por')->nullable();
            // avisar | suspender | suspender_y_avisar
            $t->string('accion', 20);
            $t->string('grupo', 10)->nullable();
            // Con qué se avisa: suspension (la plantilla de la empresa) | informacion (texto libre) | null
            $t->string('aviso', 20)->nullable();
            $t->string('plantilla', 120)->nullable();
            $t->string('idioma', 10)->nullable();
            $t->json('variables')->nullable();
            $t->string('texto', 900)->nullable();
            $t->string('fecha_limite', 20)->nullable();
            $t->string('motivo', 250)->nullable();
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('suspendidos')->default(0);
            $t->unsignedInteger('avisados')->default(0);
            $t->unsignedInteger('fallidos')->default(0);
            // pendiente | en_curso | terminado | cancelado
            $t->string('estado', 12)->default('pendiente');
            $t->string('detalle', 300)->nullable();
            $t->dateTime('iniciado_en')->nullable();
            $t->dateTime('terminado_en')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'id']);
        });

        Schema::create('suspension_lote_clientes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('lote_id');
            $t->unsignedBigInteger('user_id');
            $t->string('nombre', 160)->nullable();
            $t->decimal('deuda', 14, 2)->default(0);
            $t->unsignedSmallInteger('facturas')->default(0);
            // pendiente | hecho | no_aplicado | error | omitido
            $t->string('suspension', 12)->nullable();
            // pendiente | enviado | fallido
            $t->string('aviso', 12)->nullable();
            $t->string('error', 300)->nullable();
            $t->timestamps();

            $t->unique(['lote_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspension_lote_clientes');
        Schema::dropIfExists('suspension_lotes');
    }
};
