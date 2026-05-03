<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olt_admins', function (Blueprint $table) {
            $table->string('snmp_community')->default('public')->after('default_vlan');
            $table->enum('snmp_version', ['1', '2c', '3'])->default('2c')->after('snmp_community');
            $table->unsignedSmallInteger('snmp_port')->default(161)->after('snmp_version');
            // Optional override: if null, uses `host`
            $table->string('snmp_host')->nullable()->after('snmp_port');
        });
    }

    public function down(): void
    {
        Schema::table('olt_admins', function (Blueprint $table) {
            $table->dropColumn(['snmp_community', 'snmp_version', 'snmp_port', 'snmp_host']);
        });
    }
};
