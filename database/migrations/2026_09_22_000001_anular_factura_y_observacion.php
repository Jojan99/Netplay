<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anular una factura y dejar dicho por qué.
 *
 * Hasta ahora una factura mal hecha sólo se podía borrar, y con ella se iba el
 * consecutivo y el rastro de que existió. Anularla la deja a la vista, sin
 * cobrarse y sin contar en la cartera, con el motivo y quién la anuló.
 *
 * La observación es del pago: la referencia de la transferencia, el número de
 * recibo, o lo que quien cobró necesite dejar anotado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('det_facturations', function (Blueprint $t) {
            if (!Schema::hasColumn('det_facturations', 'anulada_en')) {
                $t->timestamp('anulada_en')->nullable()->after('paid_by_user_id');
                $t->unsignedBigInteger('anulada_por')->nullable()->after('anulada_en');
                $t->string('anulada_motivo', 255)->nullable()->after('anulada_por');
            }

            if (!Schema::hasColumn('det_facturations', 'observacion')) {
                $t->string('observacion', 500)->nullable()->after('anulada_motivo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('det_facturations', function (Blueprint $t) {
            foreach (['anulada_en', 'anulada_por', 'anulada_motivo', 'observacion'] as $c) {
                if (Schema::hasColumn('det_facturations', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
