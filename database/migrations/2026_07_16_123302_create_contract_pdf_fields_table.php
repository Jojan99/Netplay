<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_pdf_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('variable'); // ej: {{nombre}}, {{direccion}}
            $table->unsignedTinyInteger('page')->default(1);
            $table->decimal('x', 8, 2); // coordenada X en puntos PDF
            $table->decimal('y', 8, 2); // coordenada Y en puntos PDF
            $table->unsignedTinyInteger('font_size')->default(10);
            $table->string('color')->default('000000'); // hex sin #
            $table->unsignedSmallInteger('max_width')->default(200); // ancho máximo en puntos
            $table->timestamps();

            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_pdf_fields');
    }
};
