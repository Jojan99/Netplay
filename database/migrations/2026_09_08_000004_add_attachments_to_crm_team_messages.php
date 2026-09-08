<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Adjuntos en el chat interno (audio, imagen, archivo) y llamadas grupales (call_id en señalización). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_team_messages', function (Blueprint $table) {
            $table->string('attachment_url', 500)->nullable()->after('content');
            $table->string('attachment_type', 20)->nullable()->after('attachment_url');   // image|audio|video|file
            $table->string('attachment_name', 255)->nullable()->after('attachment_type');
            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_name');
        });
    }
    public function down(): void
    {
        Schema::table('crm_team_messages', fn(Blueprint $t) => $t->dropColumn(['attachment_url', 'attachment_type', 'attachment_name', 'attachment_size']));
    }
};
