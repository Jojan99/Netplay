<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_send_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('det_facturation_id'); // factura enviada
            $table->unsignedBigInteger('user_id')->nullable(); // quien envió (admin) o cliente autenticado
            $table->string('channel', 20); // whatsapp|email|both
            $table->string('status', 20)->default('ok'); // ok|error|partial
            $table->text('message')->nullable(); // mensaje de respuesta
            $table->string('sent_to_phone')->nullable(); // teléfono destino
            $table->string('sent_to_email')->nullable(); // email destino
            $table->json('details')->nullable(); // resultado detallado por sub-canal
            $table->timestamps();

            $table->index('det_facturation_id');
            $table->index('user_id');
            $table->index('channel');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_send_logs');
    }
};
