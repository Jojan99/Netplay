<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que necesita la suspensión automática para no sorprender a nadie.
 *
 *  - aviso_vencimiento_en: la primera vez que la revisión diaria vio la
 *    suscripción vencida. Los días de gracia se cuentan desde ahí, no desde la
 *    fecha de vencimiento: una empresa que venció hace meses también recibe su
 *    aviso completo antes de quedarse sin acceso.
 *  - suspendida_auto_en: cuándo la suspendió la revisión. Sólo esas se
 *    reactivan solas al registrarse el pago; las que suspendió una persona
 *    desde la consola las reactiva una persona.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plataforma_suscripciones', function (Blueprint $table) {
            $table->timestamp('aviso_vencimiento_en')->nullable();
            $table->timestamp('suspendida_auto_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plataforma_suscripciones', function (Blueprint $table) {
            $table->dropColumn(['aviso_vencimiento_en', 'suspendida_auto_en']);
        });
    }
};
