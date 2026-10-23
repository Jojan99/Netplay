<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fecha de instalación que lleva el contrato: desde ahí cuenta la vigencia y es la que va
 * en «FECHA DE INSTALACIÓN». Antes el contrato ponía el día en que el cliente lo abría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contracts', function (Blueprint $table) {
            $table->date('fecha_instalacion')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_contracts', function (Blueprint $table) {
            $table->dropColumn('fecha_instalacion');
        });
    }
};
