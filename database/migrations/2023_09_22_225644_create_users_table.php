<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->string('username')
                ->comment('Usuario para acceder al sistema');
            $table->string('email')
                ->comment('Correo de la persona que accede al sistema');
            $table->string('password')
                ->comment('Constraseña de acceso');
            $table->unsignedBigInteger('profile_id')
                ->index()
                ->nullable();
            $table->boolean('active')
                ->default(true)
                ->comment('campo encargado de mostrar si un usario se encuentra activo o no');
            $table->boolean('status')
                ->default(false)
                ->comment('campo encargado de mostrar si tiene un paquete activo');
            $table->timestamps();

            $table->foreign('profile_id')
            ->references('id')
            ->on('profiles');
        
        
        });


      
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
