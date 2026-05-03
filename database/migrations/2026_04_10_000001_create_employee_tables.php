<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('dni', 30);
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->date('start_date')->nullable();
            $table->date('birthday')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'dni']);
            $table->index('company_id');
        });

        Schema::create('employee_labor_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id');
            $table->enum('type', ['indefinido', 'fijo', 'obra_labor', 'prestacion_servicios']);
            $table->unsignedTinyInteger('duration_months')->nullable();
            $table->decimal('salary', 14, 2)->default(0);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('employee_affiliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('arl', 150)->nullable();
            $table->string('eps', 150)->nullable();
            $table->string('pension_fund', 150)->nullable();
            $table->string('compensation_fund', 150)->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->unique(['company_id', 'employee_id']);
        });

        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('bank_name', 150);
            $table->enum('account_type', ['ahorros', 'corriente']);
            $table->string('account_number', 50);
            $table->timestamps();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('employee_equipment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('serial', 100)->nullable();
            $table->enum('condition', ['nuevo', 'bueno', 'regular', 'malo'])->default('bueno');
            $table->date('assigned_at');
            $table->date('returned_at')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('employee_disciplinary_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id');
            $table->date('incident_date');
            $table->string('reason', 255);
            $table->text('description')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('employee_payrolls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('period', 7); // YYYY-MM
            $table->decimal('base_salary', 14, 2)->default(0);
            $table->decimal('bonuses', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('total_paid', 14, 2)->default(0);
            $table->date('payment_date')->nullable();
            $table->enum('payment_method', ['transferencia', 'efectivo', 'cheque'])->default('transferencia');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->unique(['company_id', 'employee_id', 'period']);
            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payrolls');
        Schema::dropIfExists('employee_disciplinary_records');
        Schema::dropIfExists('employee_equipment');
        Schema::dropIfExists('employee_bank_accounts');
        Schema::dropIfExists('employee_affiliations');
        Schema::dropIfExists('employee_labor_contracts');
        Schema::dropIfExists('employees');
    }
};
