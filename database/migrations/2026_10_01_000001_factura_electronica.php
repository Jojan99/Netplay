<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación electrónica ante la DIAN, a través del proveedor que cada
 * empresa ya tiene (Siigo o Alegra).
 *
 *  - factura_electronica_configs: una fila por empresa. Con qué proveedor, sus
 *    credenciales (cifradas) y con qué numeración, impuesto, forma de pago y
 *    producto se arma cada factura. Los identificadores son los del proveedor,
 *    por eso van en un JSON: Siigo y Alegra no piden lo mismo.
 *  - facturas_electronicas: un renglón por documento enviado. Guarda lo que se
 *    mandó y lo que respondió el proveedor, para poder explicar un rechazo.
 *  - user_data: lo que la DIAN pide del adquiriente y la plataforma no tenía.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('factura_electronica_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->string('proveedor', 20);
            $table->boolean('activa')->default(false);
            // Emitir sola cuando una factura queda pagada. Apagado, sólo a mano.
            $table->boolean('automatica')->default(false);
            // Sólo se emite lo pagado desde esta fecha: al encenderlo no se
            // manda a la DIAN la historia entera.
            $table->date('emitir_desde')->nullable();
            $table->text('credenciales')->nullable();
            $table->json('ajustes')->nullable();
            $table->timestamp('verificada_en')->nullable();
            $table->timestamps();
        });

        Schema::create('facturas_electronicas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('det_facturation_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('tipo', 15)->default('factura');          // factura | nota_credito
            $table->unsignedBigInteger('origen_id')->nullable();     // la factura que corrige una nota crédito
            $table->string('proveedor', 20);
            $table->string('estado', 15)->default('pendiente');      // pendiente | emitida | rechazada | error
            $table->string('externo_id', 80)->nullable();
            $table->string('numero', 40)->nullable();
            $table->string('cufe', 120)->nullable();
            $table->string('estado_dian', 60)->nullable();
            $table->text('pdf_url')->nullable();
            $table->text('qr')->nullable();
            $table->decimal('base', 14, 2)->default(0);
            $table->decimal('impuesto', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->text('error')->nullable();
            $table->json('solicitud')->nullable();
            $table->json('respuesta')->nullable();
            $table->timestamp('emitida_en')->nullable();
            $table->unsignedBigInteger('creada_por')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'estado']);
            $table->index(['company_id', 'det_facturation_id', 'tipo']);
        });

        Schema::table('user_data', function (Blueprint $table) {
            // CC, NIT, CE, PP, TI, PPT. Vacío se toma como CC.
            $table->string('fiscal_tipo_documento', 5)->nullable();
            $table->string('fiscal_dv', 1)->nullable();
            // natural | juridica. Vacío: jurídica si el documento es NIT.
            $table->string('fiscal_tipo_persona', 10)->nullable();
            // Código DANE del municipio (5 dígitos). Vacío: el de la empresa.
            $table->string('fiscal_municipio', 5)->nullable();
            $table->unsignedTinyInteger('estrato')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas_electronicas');
        Schema::dropIfExists('factura_electronica_configs');
        Schema::table('user_data', function (Blueprint $table) {
            $table->dropColumn(['fiscal_tipo_documento', 'fiscal_dv', 'fiscal_tipo_persona', 'fiscal_municipio', 'estrato']);
        });
    }
};
