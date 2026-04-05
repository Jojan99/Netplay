<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_billing_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedTinyInteger('grupo')->comment('Número de grupo (1-4), coincide con cab_facturations.group');
            $table->unsignedTinyInteger('billing_day')->comment('Día del mes en que se genera la factura (1-28)');
            $table->unsignedTinyInteger('billing_hour')->default(1)->comment('Hora del día en que se ejecuta el proceso (0-23)');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unique(['company_id', 'grupo'], 'uq_company_grupo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_billing_schedules');
    }
};
