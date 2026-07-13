<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('storefront_hidden_at')->nullable()->after('is_translation');
            $table->string('storefront_restore_visibility', 16)->nullable()->after('storefront_hidden_at');
            $table->timestamp('stock_verification_required_at')->nullable()->after('storefront_restore_visibility');
            $table->index(
                ['storefront_hidden_at', 'stock_verification_required_at'],
                'products_storefront_state_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_storefront_state_index');
            $table->dropColumn([
                'storefront_hidden_at',
                'storefront_restore_visibility',
                'stock_verification_required_at',
            ]);
        });
    }
};
