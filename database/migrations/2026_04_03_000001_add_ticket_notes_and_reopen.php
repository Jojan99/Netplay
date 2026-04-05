<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('reopened_count')->default(0)->after('finished_at');
            $table->timestamp('closed_at')->nullable()->after('reopened_count');
        });

        Schema::create('ticket_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->text('note');
            $table->json('photos')->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamps();
            $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_notes');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['reopened_count', 'closed_at']);
        });
    }
};
