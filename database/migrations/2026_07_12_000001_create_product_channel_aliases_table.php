<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_channel_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('external_product_id');
            $table->string('external_variation_id')->nullable();
            $table->string('external_key');
            $table->string('external_sku')->nullable();
            $table->string('language', 16)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['sales_channel_id', 'external_key'], 'product_channel_alias_external_unique');
            $table->index(['product_id', 'sales_channel_id'], 'product_channel_alias_product_channel_index');
            $table->index(
                ['sales_channel_id', 'external_product_id', 'external_variation_id'],
                'product_channel_alias_external_lookup_index',
            );
            $table->index(['sales_channel_id', 'external_sku'], 'product_channel_alias_sku_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_aliases');
    }
};
