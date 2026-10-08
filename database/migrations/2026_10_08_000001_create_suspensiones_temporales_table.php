<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspensiones que pide el cliente por un tiempo (viaje, temporada, lo que sea).
 *
 * Al empezar se le cobran los días usados desde su último corte; mientras dura no
 * se le factura; al terminar vuelve solo si no debe, y si debe se avisa para que
 * alguien lo atienda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspensiones_temporales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->date('desde');
            $table->date('hasta');
            $table->string('motivo', 255);
            // programada → activa → terminada | requiere_atencion (debía al volver) | cancelada
            $table->string('estado', 20)->default('programada')->index();
            $table->unsignedBigInteger('factura_id')->nullable();
            $table->decimal('monto_prorrateo', 12, 2)->nullable();
            $table->unsignedInteger('dias_cobrados')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->dateTime('suspendida_en')->nullable();
            $table->dateTime('reactivada_en')->nullable();
            $table->text('nota')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspensiones_temporales');
    }
};
