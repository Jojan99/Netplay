<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuración principal de la landing por empresa
        Schema::create('landing_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();

            // Branding
            $table->text('logo_url')->nullable();
            $table->string('primary_color', 7)->default('#2563EB');
            $table->string('secondary_color', 7)->default('#1E40AF');
            $table->string('accent_color', 7)->default('#10B981');

            // Hero principal
            $table->string('hero_title')->nullable();
            $table->text('hero_subtitle')->nullable();
            $table->string('hero_cta_text', 100)->default('Ver planes');
            $table->string('hero_cta_url', 255)->nullable();
            $table->text('hero_image_url')->nullable();
            $table->string('hero_bg_color', 7)->nullable();

            // Secciones activas y su orden (JSON array de slugs)
            $table->json('sections_order')->nullable();

            // Contacto / Footer
            $table->string('contact_whatsapp', 20)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_address')->nullable();

            // SEO
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();

            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });

        // Secciones dinámicas (tipo WordPress)
        Schema::create('landing_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            // Tipos: hero | plans | features | faq | testimonials | contact | custom_html
            $table->string('type', 50);
            $table->string('title')->nullable();
            $table->tinyInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            // Contenido flexible por tipo de sección
            $table->json('content')->nullable();
            // Overrides de estilos (colores, padding, etc.)
            $table->json('styles')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'sort_order']);
        });

        // Ítems del menú de navegación
        Schema::create('landing_nav_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('label', 100);
            $table->string('href', 255);
            $table->tinyInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->string('target', 10)->default('_self');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_nav_items');
        Schema::dropIfExists('landing_sections');
        Schema::dropIfExists('landing_configs');
    }
};
