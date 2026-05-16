<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_categories', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('name');
            $table->string('code')->nullable()->after('sku');
            $table->decimal('stock_min', 10, 2)->default(0)->after('quantity');
            $table->decimal('stock_max', 10, 2)->nullable()->after('stock_min');
            $table->string('location')->nullable()->after('unit');
            $table->decimal('average_cost', 12, 2)->default(0)->after('unit_price');
            $table->softDeletes()->after('updated_at');

            $table->index(['company_id', 'category_id'], 'idx_inv_company_category');
            $table->index(['company_id', 'sku'], 'idx_inv_company_sku');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('batch_number')->nullable()->after('reference');
            $table->date('expiry_date')->nullable()->after('batch_number');
            $table->decimal('balance_after', 10, 2)->default(0)->after('quantity');
            $table->decimal('cost_before', 12, 2)->default(0)->after('balance_after');
            $table->decimal('cost_after', 12, 2)->default(0)->after('cost_before');
            $table->softDeletes()->after('updated_at');

            $table->index(['inventory_id', 'created_at'], 'idx_mov_inv_date');
            $table->index(['company_id', 'type'], 'idx_mov_company_type');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn(['sku', 'code', 'stock_min', 'stock_max', 'location', 'average_cost']);
            $table->dropSoftDeletes();
            $table->dropIndex('idx_inv_company_category');
            $table->dropIndex('idx_inv_company_sku');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn(['batch_number', 'expiry_date', 'balance_after', 'cost_before', 'cost_after']);
            $table->dropSoftDeletes();
            $table->dropIndex('idx_mov_inv_date');
            $table->dropIndex('idx_mov_company_type');
        });
    }
};
