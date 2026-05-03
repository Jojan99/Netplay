<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('pg_gateway', 20)->nullable()->after('whatsapp_enabled'); // wompi|epayco|zonapago
            $table->boolean('pg_sandbox')->default(true)->after('pg_gateway');
            $table->boolean('pg_active')->default(false)->after('pg_sandbox');
            $table->text('pg_public_key')->nullable()->after('pg_active');
            $table->text('pg_private_key')->nullable()->after('pg_public_key');
            $table->text('pg_events_secret')->nullable()->after('pg_private_key');    // Wompi
            $table->text('pg_integrity_secret')->nullable()->after('pg_events_secret'); // Wompi
            $table->text('pg_client_id')->nullable()->after('pg_integrity_secret');   // ePayco: p_cust_id_cliente
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'pg_gateway', 'pg_sandbox', 'pg_active',
                'pg_public_key', 'pg_private_key',
                'pg_events_secret', 'pg_integrity_secret', 'pg_client_id',
            ]);
        });
    }
};
