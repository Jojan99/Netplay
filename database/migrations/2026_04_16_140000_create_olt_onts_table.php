<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olt_onts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained('olt_admins')->cascadeOnDelete();
            $table->string('fsp', 20);           // "0/1/6"
            $table->unsignedSmallInteger('ont_id');
            $table->string('serial', 32)->nullable();
            $table->string('description')->nullable();
            $table->string('status', 16)->default('offline'); // online | offline
            $table->json('service_ports')->nullable();        // [{'index':100,'vlan':106}, ...]
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['olt_id', 'fsp', 'ont_id']);
            $table->index(['olt_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_onts');
    }
};
