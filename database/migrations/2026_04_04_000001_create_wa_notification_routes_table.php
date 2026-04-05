<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_notification_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('event_type', 50); // payment, new_user, ticket_support, ticket_install
            $table->string('destination', 150); // número o JID de grupo
            $table->string('label', 100)->nullable(); // nombre amigable
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_notification_routes');
    }
};
