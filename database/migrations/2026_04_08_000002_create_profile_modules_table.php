<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Módulos por defecto para cada tipo de rol
    const DEFAULTS = [
        'ADMIN'    => [
            'usuario', 'finanzas', 'egresos', 'report-paid', 'history-facture',
            'created-ticket', 'view-ticket', 'olt-detail', 'olt-admin', 'router',
            'staff', 'billing-config', 'inventory', 'mikrotik', 'resumen', 'crm', 'whatsapp',
        ],
        'TECNICO'  => ['created-ticket', 'view-ticket', 'crm'],
        'CONTADOR' => ['finanzas', 'egresos', 'report-paid', 'history-facture', 'inventory', 'resumen'],
    ];

    public function up(): void
    {
        Schema::create('profile_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id')->index();
            $table->string('module');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->unique(['profile_id', 'module']);
        });

        // Sembrar módulos para los perfiles ya existentes
        $profiles = DB::table('profiles')->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR'])->get();
        $now      = now();
        $rows     = [];

        foreach ($profiles as $profile) {
            foreach (self::DEFAULTS[$profile->name] ?? [] as $module) {
                $rows[] = [
                    'profile_id' => $profile->id,
                    'module'     => $module,
                    'active'     => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows) {
            DB::table('profile_modules')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_modules');
    }
};
