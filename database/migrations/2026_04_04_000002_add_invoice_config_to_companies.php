<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('invoice_business_name')->nullable()->after('logo');
            $table->string('invoice_nit')->nullable()->after('invoice_business_name');
            $table->string('invoice_phone')->nullable()->after('invoice_nit');
            $table->string('invoice_address')->nullable()->after('invoice_phone');
            $table->string('invoice_city')->nullable()->after('invoice_address');
            $table->string('invoice_country')->nullable()->default('COLOMBIA')->after('invoice_city');
            $table->string('invoice_iva_condition')->nullable()->default('No Aplica')->after('invoice_country');
            $table->string('invoice_economic_activity')->nullable()->after('invoice_iva_condition');
            $table->text('invoice_payment_info')->nullable()->after('invoice_economic_activity');
            $table->text('invoice_footer')->nullable()->after('invoice_payment_info');
            $table->string('invoice_logo_url')->nullable()->after('invoice_footer');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_business_name',
                'invoice_nit',
                'invoice_phone',
                'invoice_address',
                'invoice_city',
                'invoice_country',
                'invoice_iva_condition',
                'invoice_economic_activity',
                'invoice_payment_info',
                'invoice_footer',
                'invoice_logo_url',
            ]);
        });
    }
};
