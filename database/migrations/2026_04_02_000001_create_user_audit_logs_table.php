<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');          // cliente afectado
            $table->unsignedBigInteger('changed_by');       // admin/técnico que hizo el cambio
            $table->unsignedBigInteger('company_id');
            $table->string('field_changed', 100);           // e.g. 'plan', 'ip', 'status', 'billing_group'
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_audit_logs');
    }
};
