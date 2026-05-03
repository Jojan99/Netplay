<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olt_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained('olt_admins')->cascadeOnDelete();
            $table->enum('type', ['line', 'srv']);
            $table->unsignedInteger('profile_id');
            $table->string('profile_name')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['olt_id', 'type', 'profile_id']);
            $table->index(['olt_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_profiles');
    }
};
