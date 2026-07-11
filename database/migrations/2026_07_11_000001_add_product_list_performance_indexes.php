<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'products_created_at_id_index');
        });

        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->index('product_id', 'stock_balances_product_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->dropIndex('stock_balances_product_id_index');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_created_at_id_index');
        });
    }
};
