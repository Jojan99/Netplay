<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quién le llegó cada envío, uno por uno.
 *
 * No es solo auditoría. Sin cola de trabajos, los envíos los procesa un
 * comando por tandas, y esta tabla es lo que le permite retomar donde quedó
 * sin volver a cobrarle a nadie el mismo mensaje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('user_id');
            $table->string('phone', 30);
            $table->string('name', 191)->nullable();

            // pending → sent | failed | skipped
            $table->string('status', 20)->default('pending');
            $table->string('error', 255)->nullable();
            $table->string('message_id', 128)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Un cliente no puede recibir dos veces el mismo envío.
            $table->unique(['campaign_id', 'user_id']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_campaign_recipients');
    }
};
