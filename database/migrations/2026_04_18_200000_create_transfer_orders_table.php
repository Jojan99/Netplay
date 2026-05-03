<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_data_id')->constrained('user_data')->onDelete('cascade');
            $table->foreignId('old_router_id')->nullable()->constrained('conection_routers')->onDelete('set null');
            $table->foreignId('new_router_id')->nullable()->constrained('conection_routers')->onDelete('set null');
            $table->string('old_address');
            $table->string('new_address');
            $table->string('new_neighborhood')->nullable();
            $table->string('old_ip')->nullable();
            $table->string('new_ip')->nullable();
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->enum('status', ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->enum('payment_status', ['not_required', 'pending', 'paid', 'verified', 'rejected'])->default('not_required');
            $table->decimal('transfer_cost', 12, 2)->default(0);
            $table->decimal('payment_amount', 12, 2)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_image_url')->nullable();
            $table->foreignId('technician_1_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('technician_2_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->text('observations')->nullable();
            $table->text('technical_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_orders');
    }
};