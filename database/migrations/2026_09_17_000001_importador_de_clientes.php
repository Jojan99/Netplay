<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importador de clientes desde otras plataformas (WispHub, Mikrowisp).
 *
 * - importaciones: cada corrida, con su avance, lo que eligió el administrador
 *   y el resumen de la vista previa.
 * - importacion_filas: cada cliente leído del origen y qué pasó con él.
 * - clientes_externos: el id del cliente en la plataforma de origen, para que
 *   volver a importar no lo duplique.
 * - importacion_credenciales: la URL y el token de la API, si la empresa pidió
 *   guardarlos (el token va cifrado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('origen', 20);
            $table->string('metodo', 10);
            $table->string('estado', 20)->default('leyendo')->index();
            $table->string('api_url', 255)->nullable();
            $table->text('api_token')->nullable();
            $table->string('archivo', 255)->nullable();
            $table->string('nombre_archivo', 255)->nullable();
            $table->json('columnas')->nullable();
            $table->json('mapeo')->nullable();
            $table->json('opciones')->nullable();
            $table->json('analisis')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('procesadas')->default(0);
            $table->unsignedInteger('creados')->default(0);
            $table->unsignedInteger('actualizados')->default(0);
            $table->unsignedInteger('omitidos')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->string('detalle', 255)->nullable();
            $table->timestamp('iniciada_en')->nullable();
            $table->timestamp('terminada_en')->nullable();
            $table->timestamps();
        });

        Schema::create('importacion_filas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('importacion_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('fila');
            $table->string('external_id', 100)->nullable();
            $table->string('dni', 60)->nullable();
            $table->string('nombre', 255)->nullable();
            // Cifrado: trae la contraseña PPPoE del cliente.
            $table->longText('datos');
            $table->json('avisos')->nullable();
            $table->string('previo', 20)->nullable();
            $table->unsignedBigInteger('existente_user_id')->nullable();
            $table->string('resultado', 20)->nullable();
            $table->string('mensaje', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['importacion_id', 'fila']);
            $table->index(['importacion_id', 'resultado']);
            $table->foreign('importacion_id')->references('id')->on('importaciones')->cascadeOnDelete();
        });

        Schema::create('clientes_externos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('origen', 20);
            $table->string('external_id', 100);
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('importacion_id')->nullable();
            $table->decimal('saldo_origen', 14, 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'origen', 'external_id'], 'clientes_externos_unico');
        });

        Schema::create('importacion_credenciales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('origen', 20);
            $table->string('api_url', 255)->nullable();
            $table->text('api_token');
            $table->timestamps();

            $table->unique(['company_id', 'origen'], 'importacion_credenciales_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacion_credenciales');
        Schema::dropIfExists('clientes_externos');
        Schema::dropIfExists('importacion_filas');
        Schema::dropIfExists('importaciones');
    }
};
