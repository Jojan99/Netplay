<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_meta_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('phone', 30)->nullable();
            $table->string('type', 30);
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('status', 30)->default('sent');
            $table->string('meta_msg_id', 80)->nullable();
            $table->text('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'phone']);
            $table->index(['meta_msg_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_meta_logs');
    }
};
