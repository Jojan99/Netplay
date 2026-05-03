<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olt_admins', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('name');
            $table->enum('brand', ['huawei', 'zte', 'cdata', 'fiberhome'])->default('huawei');

            // OLT connection
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username');
            $table->string('password');

            // Jump host (MikroTik)
            $table->enum('access_mode', ['direct', 'jump'])->default('jump');
            $table->string('jump_host')->nullable();
            $table->unsignedSmallInteger('jump_port')->default(22)->nullable();
            $table->string('jump_user')->nullable();
            $table->string('jump_pass')->nullable();

            // Huawei profiles
            $table->unsignedSmallInteger('ont_lineprofile_id')->default(10);
            $table->unsignedSmallInteger('ont_srvprofile_id')->default(10);
            $table->unsignedSmallInteger('default_vlan')->default(200);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_admins');
    }
};
