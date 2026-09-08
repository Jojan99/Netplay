<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Responder citando (quoted_message_id) y acks de entrega (status: sent|delivered|read|failed). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_messages', 'quoted_message_id')) {
                $table->unsignedBigInteger('quoted_message_id')->nullable()->after('content');
            }
            if (!Schema::hasColumn('crm_messages', 'status')) {
                $table->string('status', 20)->nullable()->after('external_id'); // pending|sent|delivered|read|failed
            }
            if (!Schema::hasColumn('crm_messages', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('external_id');
                $table->timestamp('read_at')->nullable()->after('delivered_at');
            }
            $table->index('external_id', 'crm_messages_external_id_index');
        });
        // El enum original sólo admitía text/image/video/audio/document
        DB::statement("ALTER TABLE crm_messages MODIFY message_type ENUM('text','image','video','audio','document','sticker','location','contact','reaction') NOT NULL DEFAULT 'text'");
    }

    public function down(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            $table->dropIndex('crm_messages_external_id_index');
            $table->dropColumn(['quoted_message_id', 'status', 'delivered_at', 'read_at']);
        });
    }
};
