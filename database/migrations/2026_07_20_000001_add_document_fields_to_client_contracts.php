<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contracts', function (Blueprint $table) {
            $table->boolean('require_documents')->default(false)->after('token');
            $table->string('document_front_path')->nullable()->after('require_documents');
            $table->string('document_back_path')->nullable()->after('document_front_path');
            $table->string('document_number_front', 50)->nullable()->after('document_back_path');
            $table->string('document_number_back', 50)->nullable()->after('document_number_front');
        });
    }

    public function down(): void
    {
        Schema::table('client_contracts', function (Blueprint $table) {
            $table->dropColumn(['require_documents', 'document_front_path', 'document_back_path', 'document_number_front', 'document_number_back']);
        });
    }
};
