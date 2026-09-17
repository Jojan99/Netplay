<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correo por empresa con su propia cuenta de Mailjet.
 *
 * Sin estos datos (o apagado) la empresa envía por la cuenta de la plataforma:
 * remitente no-reply@netvula.com, con el nombre de la empresa y las respuestas
 * al correo de la empresa. El secreto va cifrado (cast en el modelo).
 */
return new class extends Migration
{
    private array $columnas = [
        'mailjet_activo', 'mailjet_api_key', 'mailjet_api_secret',
        'mailjet_from_email', 'mailjet_from_name', 'mailjet_verificado_en',
    ];

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'mailjet_activo')) {
                $table->boolean('mailjet_activo')->default(false);
            }
            if (!Schema::hasColumn('companies', 'mailjet_api_key')) {
                $table->string('mailjet_api_key', 100)->nullable();
            }
            if (!Schema::hasColumn('companies', 'mailjet_api_secret')) {
                $table->text('mailjet_api_secret')->nullable();
            }
            if (!Schema::hasColumn('companies', 'mailjet_from_email')) {
                $table->string('mailjet_from_email', 191)->nullable();
            }
            if (!Schema::hasColumn('companies', 'mailjet_from_name')) {
                $table->string('mailjet_from_name', 191)->nullable();
            }
            if (!Schema::hasColumn('companies', 'mailjet_verificado_en')) {
                $table->timestamp('mailjet_verificado_en')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach ($this->columnas as $c) {
                if (Schema::hasColumn('companies', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
