<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Última lectura por usuario de hilos compartidos del chat interno (canal general). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_team_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->string('thread', 40);
            $table->timestamp('read_at')->nullable();
            $table->primary(['user_id', 'thread']);
        });
    }
    public function down(): void { Schema::dropIfExists('crm_team_reads'); }
};
