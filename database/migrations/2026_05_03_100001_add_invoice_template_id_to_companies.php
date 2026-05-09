<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_template_id')->nullable()->after('invoice_logo_base64');
            $table->foreign('invoice_template_id')
                ->references('id')
                ->on('invoice_templates')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['invoice_template_id']);
            $table->dropColumn('invoice_template_id');
        });
    }
};
