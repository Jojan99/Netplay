<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Traceability fields on payments
        Schema::table('det_facturations', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('paid');
            $table->unsignedBigInteger('paid_by_user_id')->nullable()->after('paid_at');
        });

        // Category free-text on expenses
        Schema::table('egresses', function (Blueprint $table) {
            $table->string('category')->nullable()->default('General')->after('concept');
        });

        // Full payment audit log
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('det_facturation_id');
            $table->unsignedBigInteger('cab_id');
            $table->string('number_facture');
            $table->string('client_name');
            $table->unsignedBigInteger('recorded_by_user_id');  // admin/contador
            $table->decimal('amount', 12, 2);
            $table->enum('type', ['pago_completo', 'abono', 'descuento', 'ajuste']);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
        Schema::table('egresses', function (Blueprint $table) {
            $table->dropColumn('category');
        });
        Schema::table('det_facturations', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'paid_by_user_id']);
        });
    }
};
