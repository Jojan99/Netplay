<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redes de un túnel que chocan con las de otra empresa y se publican en la VPN
 * con otra dirección: [{real, virtual}]. El router las traduce con NETMAP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vpn_tuneles', 'traducciones')) {
            return;
        }

        Schema::table('vpn_tuneles', function (Blueprint $table) {
            $table->json('traducciones')->nullable()->after('redes_remotas');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('vpn_tuneles', 'traducciones')) {
            Schema::table('vpn_tuneles', fn (Blueprint $table) => $table->dropColumn('traducciones'));
        }
    }
};
