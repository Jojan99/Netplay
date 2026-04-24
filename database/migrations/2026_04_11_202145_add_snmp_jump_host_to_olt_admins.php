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
        Schema::table('olt_admins', function (Blueprint $table) {
            $table->string('snmp_jump_host')->nullable()->after('snmp_host');
            $table->unsignedSmallInteger('snmp_jump_port')->default(22)->after('snmp_jump_host');
            $table->string('snmp_jump_user')->nullable()->after('snmp_jump_port');
            $table->string('snmp_jump_pass')->nullable()->after('snmp_jump_user');
        });
    }

    public function down(): void
    {
        Schema::table('olt_admins', function (Blueprint $table) {
            $table->dropColumn(['snmp_jump_host', 'snmp_jump_port', 'snmp_jump_user', 'snmp_jump_pass']);
        });
    }
};
