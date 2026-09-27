<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Un lote se puede deshacer: queda dicho cuándo, quién, y por dónde se aplicó (web o consola). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conciliacion_pagos_lotes', function (Blueprint $t) {
            if (!Schema::hasColumn('conciliacion_pagos_lotes', 'revertido_en')) $t->timestamp('revertido_en')->nullable()->after('antes');
            if (!Schema::hasColumn('conciliacion_pagos_lotes', 'revertido_por')) $t->unsignedBigInteger('revertido_por')->nullable()->after('revertido_en');
            if (!Schema::hasColumn('conciliacion_pagos_lotes', 'origen')) $t->string('origen', 12)->default('web')->after('lote');
        });
    }

    public function down(): void
    {
        Schema::table('conciliacion_pagos_lotes', function (Blueprint $t) {
            foreach (['revertido_en', 'revertido_por', 'origen'] as $c) if (Schema::hasColumn('conciliacion_pagos_lotes', $c)) $t->dropColumn($c);
        });
    }
};
