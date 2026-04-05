<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Notas internas por conversación ──────────────────────────────────
        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('conversation_id');
            $table->bigInteger('user_id')->nullable();
            $table->text('content');
            $table->timestamps();

            $table->index('conversation_id');
        });

        // ── Etiquetas / categorías por empresa ───────────────────────────────
        Schema::create('crm_labels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name', 80);
            $table->string('color', 20)->default('#10b981'); // color hex
            $table->timestamps();
        });

        // ── Pivot: conversación ↔ etiqueta ───────────────────────────────────
        Schema::create('crm_conversation_labels', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('conversation_id');
            $table->bigInteger('label_id');
            $table->timestamps();

            $table->unique(['conversation_id', 'label_id']);
        });

        // ── Stickers guardados por empresa ───────────────────────────────────
        Schema::create('crm_stickers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('media_url', 500);
            $table->string('name', 100)->nullable();
            $table->timestamps();
        });

        // ── Broadcast log ────────────────────────────────────────────────────
        Schema::create('crm_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('message');
            $table->integer('recipients_count')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->timestamps();
        });

        // ── Columnas adicionales en crm_conversations ────────────────────────
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->timestamp('inactivity_notified_at')->nullable()->after('last_message_at');
            $table->integer('inactivity_minutes')->nullable()->after('inactivity_notified_at'); // minutos config
        });

        // ── Columnas adicionales en crm_messages ─────────────────────────────
        Schema::table('crm_messages', function (Blueprint $table) {
            $table->boolean('is_note')->default(false)->after('mime_type');          // nota interna
            $table->boolean('is_forwarded')->default(false)->after('is_note');       // reenviado
            $table->unsignedBigInteger('forwarded_from_id')->nullable()->after('is_forwarded');
            $table->string('agent_signature', 200)->nullable()->after('forwarded_from_id'); // firma agente
        });
    }

    public function down(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            $table->dropColumn(['is_note', 'is_forwarded', 'forwarded_from_id', 'agent_signature']);
        });
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->dropColumn(['inactivity_notified_at', 'inactivity_minutes']);
        });
        Schema::dropIfExists('crm_broadcasts');
        Schema::dropIfExists('crm_stickers');
        Schema::dropIfExists('crm_conversation_labels');
        Schema::dropIfExists('crm_labels');
        Schema::dropIfExists('crm_notes');
    }
};
