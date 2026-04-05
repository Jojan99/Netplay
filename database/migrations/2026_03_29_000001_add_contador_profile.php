<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('profiles')->insertOrIgnore([
            ['id' => 4, 'name' => 'CONTADOR', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        DB::table('profiles')->where('id', 4)->delete();
    }
};
