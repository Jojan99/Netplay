<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envíos de una plantilla a muchos clientes.
 *
 * Cada mensaje de plantilla se le cobra a la empresa, así que un envío no es
 * una acción que se dispara y se olvida: queda registrado qué se mandó, a
 * cuántos, quién lo autorizó y si se probó antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();

            $table->string('name', 120)->nullable();
            $table->string('template_name', 120);
            $table->string('language', 10)->default('es_CO');

            // Qué va en cada {{n}}: una variable del sistema o un texto fijo.
            $table->json('params')->nullable();

            // A quién: los filtros elegidos y los clientes excluidos a mano.
            $table->json('audience')->nullable();
            $table->json('excluded_user_ids')->nullable();

            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            // draft → tested → sending → done | cancelled | failed
            $table->string('status', 20)->default('draft');

            // Sin una prueba vista no se habilita el envío real.
            $table->string('test_phone', 20)->nullable();
            $table->timestamp('test_sent_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_campaigns');
    }
};
