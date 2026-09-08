<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tipos de mensaje de WhatsApp que faltaban: encuesta y evento. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE crm_messages MODIFY message_type ENUM('text','image','video','audio','document','sticker','location','contact','reaction','poll','event') NOT NULL DEFAULT 'text'");
    }
    public function down(): void
    {
        DB::statement("ALTER TABLE crm_messages MODIFY message_type ENUM('text','image','video','audio','document','sticker','location','contact','reaction') NOT NULL DEFAULT 'text'");
    }
};
