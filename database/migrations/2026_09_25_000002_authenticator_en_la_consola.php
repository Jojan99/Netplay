<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segundo factor para la consola de Netvula.
 *
 * Es la cuenta de más riesgo de todo el sistema: con ella se entra a la
 * administración de cualquier empresa. Hasta ahora alcanzaba con la
 * contraseña.
 *
 * El secreto va cifrado (cast 'encrypted' en el modelo): quien lea la base no
 * puede generar códigos. Los de recuperación van con hash, que es más fuerte
 * todavía — ni descifrándolos sirven, porque sólo se guarda su huella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plataforma_usuarios', function (Blueprint $t) {
            $t->text('totp_secreto')->nullable()->after('password');
            // Cuándo lo confirmó. Null es que todavía no lo tiene activo: un
            // secreto generado y sin confirmar no exige nada al entrar.
            $t->timestamp('totp_activo_en')->nullable()->after('totp_secreto');
            // Los de un solo uso que quedan sin usar, con hash.
            $t->text('totp_recuperacion')->nullable()->after('totp_activo_en');
        });

        // Los equipos donde ya se comprobó el código, para no pedirlo cada vez.
        // Sin esto, a la semana piden que se lo saquen.
        Schema::create('plataforma_equipos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usuario_id')->constrained('plataforma_usuarios')->cascadeOnDelete();
            // Sólo la huella: si alguien lee la base no puede hacerse pasar por
            // un equipo conocido.
            $t->string('token_hash', 64)->unique();
            $t->string('nombre', 120)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('expira_en');
            $t->timestamp('usado_en')->nullable();
            $t->timestamps();

            $t->index(['usuario_id', 'expira_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plataforma_equipos');

        Schema::table('plataforma_usuarios', function (Blueprint $t) {
            $t->dropColumn(['totp_secreto', 'totp_activo_en', 'totp_recuperacion']);
        });
    }
};
