<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que OnePay necesita y las otras pasarelas no tienen.
 *
 * OnePay valida sus avisos con dos cosas distintas: una firma HMAC sobre el
 * cuerpo (eso cabe en pg_events_secret, como las demás) y además un token fijo
 * en la cabecera «x-webhook-token», que no tiene dónde ir. Y el cobro por
 * WhatsApp necesita saber con qué plantilla aprobada por Meta se manda.
 *
 * Se agregan columnas nuevas, no se toca ninguna existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            if (!Schema::hasColumn('companies', 'pg_webhook_token')) {
                $t->string('pg_webhook_token', 191)->nullable()->after('pg_events_secret');
            }

            if (!Schema::hasColumn('companies', 'pg_template_id')) {
                // La plantilla de WhatsApp con la que OnePay manda el cobro.
                // Vacío = que OnePay elija la que corresponda al canal.
                $t->unsignedInteger('pg_template_id')->nullable()->after('pg_webhook_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            foreach (['pg_webhook_token', 'pg_template_id'] as $c) {
                if (Schema::hasColumn('companies', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
