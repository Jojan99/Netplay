<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los registros de envío de facturas no guardaban la empresa, así que la pantalla
 * de "Envíos" mostraba los de todas las empresas mezclados.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('invoice_send_logs', 'company_id')) {
            Schema::table('invoice_send_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id');
                $table->index(['company_id', 'created_at'], 'invoice_send_logs_company_created_index');
            });
        }

        // Rellenar con la empresa de la factura a la que pertenece cada envío
        DB::statement("
            UPDATE invoice_send_logs l
            JOIN det_facturations d ON d.id = l.det_facturation_id
            JOIN cab_facturations c ON c.id = d.cab_id
            SET l.company_id = c.company_id
            WHERE l.company_id IS NULL
        ");

        // Si quedó alguno sin factura, se usa la empresa del usuario que lo envió
        DB::statement("
            UPDATE invoice_send_logs l
            JOIN users u ON u.id = l.user_id
            SET l.company_id = u.company_id
            WHERE l.company_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('invoice_send_logs', function (Blueprint $table) {
            $table->dropIndex('invoice_send_logs_company_created_index');
            $table->dropColumn('company_id');
        });
    }
};
