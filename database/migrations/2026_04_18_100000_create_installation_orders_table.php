<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_data_id')->nullable()->constrained('user_data')->onDelete('set null');
            $table->string('client_name');
            $table->string('client_dni');
            $table->string('client_phone');
            $table->string('client_email')->nullable();
            $table->string('address');
            $table->string('neighborhood')->nullable();
            $table->foreignId('internet_plan_id')->nullable()->constrained('internet_plans')->onDelete('set null');
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->enum('status', ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->enum('payment_status', ['pending', 'paid', 'verified', 'rejected'])->default('pending');
            $table->decimal('payment_amount', 12, 2)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_image_url')->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->onDelete('set null');
            $table->foreignId('technician_1_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('technician_2_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->decimal('installation_cost', 12, 2)->default(0);
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
        Schema::dropIfExists('installation_orders');
    }
};