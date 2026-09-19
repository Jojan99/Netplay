<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La consola de Netvula deja de colgar del panel de una empresa.
 *
 * Va aparte de 2026_09_18_000001 a propósito: esa ya puede estar corrida, y
 * una migración corrida no vuelve a ejecutarse. Con ésta, un servidor que ya
 * tenga las tablas de la consola queda al día corriendo sólo una cosa.
 *
 * Qué cambia:
 *
 *  - la consola pasa a tener sus propios usuarios y sus propias sesiones, sin
 *    company_id, sin perfil y fuera de la tabla `users` del panel;
 *  - se saca `users.es_plataforma`. Era una marca sobre un usuario de empresa,
 *    y eso obligaba a crear una cuenta dentro de alguna empresa para entrar a
 *    la consola. Ya no se usa para nada: dejarla prendida no da acceso.
 *
 * OJO: si esa marca se le puso a un usuario creado sólo para entrar a la
 * consola, ese usuario queda siendo un ADMIN más de su empresa. Revisalo:
 *   SELECT id, username, company_id FROM users WHERE username LIKE '%netvula%';
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gente de Netvula, no de una empresa. Por más ADMIN que sea alguien,
        // y por más que le toquen la base del panel, no consigue entrar acá.
        //
        // Nace VACÍA a propósito: el primer acceso se crea con
        //   php artisan consola:usuario
        if (!Schema::hasTable('plataforma_usuarios')) {
            Schema::create('plataforma_usuarios', function (Blueprint $t) {
                $t->id();
                $t->string('nombre', 120);
                $t->string('email', 191)->unique();
                $t->string('password');
                $t->boolean('activo')->default(true);
                $t->timestamp('ultimo_ingreso')->nullable();
                $t->timestamps();
            });
        }

        // El token de la consola es opaco y se guarda hasheado: no es un JWT,
        // así que un token del panel no puede valer acá ni por accidente, y
        // uno de la consola no es un JWT válido para el panel. Además, una
        // sesión se puede cortar desde la base.
        if (!Schema::hasTable('plataforma_sesiones')) {
            Schema::create('plataforma_sesiones', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('usuario_id')->index();
                $t->string('token_hash', 64)->unique();
                $t->dateTime('expira_en');
                $t->string('ip', 45)->nullable();
                $t->string('agente', 255)->nullable();
                $t->dateTime('ultimo_uso_en')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        // La marca vieja se va: el acceso a la consola ya no pasa por ahí, y
        // una marca sin uso en la tabla de usuarios del panel es justo la
        // clase de cosa que alguien vuelve a prender sin saber para qué era.
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'es_plataforma')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('es_plataforma'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plataforma_sesiones');
        Schema::dropIfExists('plataforma_usuarios');

        // La marca no se devuelve: nada la lee. Si hiciera falta volver atrás
        // del todo, se agrega a mano.
    }
};
