<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');            // cliente (users.id)

            $table->string('token', 64)->unique();            // va en la URL pública

            // 'all_pending' recalcula el saldo cada vez que se abre el link;
            // 'invoice' lo limita a las facturas fijadas al crearlo.
            $table->string('scope', 20)->default('all_pending');
            $table->json('invoice_ids')->nullable();

            $table->string('created_via', 20)->default('bot'); // bot | panel | invoice_send
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();   // null = sin tope
            $table->unsignedInteger('used_count')->default(0);

            $table->string('last_reference')->nullable();      // última transacción generada
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
