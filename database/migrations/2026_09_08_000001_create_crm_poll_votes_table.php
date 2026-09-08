<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Votos de encuestas de WhatsApp, descifrados por el servicio Node. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');          // crm_messages.id de la encuesta
            $table->string('voter_key', 120);                  // jid del votante
            $table->string('voter_type', 12)->default('customer'); // customer|agent
            $table->string('voter_name', 120)->nullable();
            $table->json('options');                           // opciones elegidas ([] = quitó el voto)
            $table->timestamps();
            $table->unique(['message_id', 'voter_key'], 'crm_poll_votes_message_voter_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('crm_poll_votes'); }
};
