<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué aviso automático de WhatsApp se le mandó a quién y cuándo.
 *
 * Hasta ahora sólo quedaba una marca en caché por el día, así que no se podía
 * saber si un recordatorio de pago sirvió. Con esto el tablero de cobranza
 * cruza cada aviso con los pagos de los días siguientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_avisos_enviados', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('evento', 40);
            // Deuda del cliente al momento del aviso, para medir lo recuperado.
            $t->decimal('deuda', 12, 2)->nullable();
            $t->timestamp('enviado_en')->useCurrent();
            $t->index(['company_id', 'evento', 'enviado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_avisos_enviados');
    }
};
