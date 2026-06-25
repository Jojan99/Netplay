<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('det_facturations', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('paid_by_user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('det_facturations', function (Blueprint $table) {
            $table->dropColumn('email_sent_at');
        });
    }
};
