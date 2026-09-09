<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde vino cada comprobante.
 *
 * Hasta ahora la tabla no lo decía y todos los registros venían del bot de la
 * API de Meta, porque era el único código que los creaba. Ahora también entran
 * los que llegan por WhatsApp Web —que es donde los clientes efectivamente los
 * siguen mandando— y la auditoría necesita poder separarlos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payment_proofs', 'source')) {
            return;
        }

        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->string('source', 20)->default('whatsapp_web')->after('company_id');
            $table->index(['company_id', 'source']);
        });

        // Lo que ya estaba guardado vino del bot de Meta: era el único origen.
        DB::table('payment_proofs')->update(['source' => 'meta']);
    }

    public function down(): void
    {
        if (!Schema::hasColumn('payment_proofs', 'source')) {
            return;
        }

        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
