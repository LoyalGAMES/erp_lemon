<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('ean')->nullable()->index();
            $table->string('unit', 16)->default('szt');
            $table->unsignedTinyInteger('quantity_precision')->default(0);
            $table->decimal('vat_rate', 5, 2)->default(23);
            $table->decimal('weight_kg', 12, 4)->nullable();
            $table->json('attributes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->default('physical');
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->default('woocommerce');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('wordpress_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_channel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('base_url');
            $table->text('consumer_key_encrypted');
            $table->text('consumer_secret_encrypted');
            $table->boolean('order_import_enabled')->default(true);
            $table->boolean('stock_export_enabled')->default(true);
            $table->boolean('invoice_upload_enabled')->default(true);
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_channel_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_product_id');
            $table->string('external_variation_id')->nullable();
            $table->string('external_sku')->nullable();
            $table->boolean('stock_sync_enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['sales_channel_id', 'external_product_id', 'external_variation_id'], 'product_channel_external_unique');
            $table->unique(['product_id', 'sales_channel_id']);
        });

        Schema::create('warehouse_channel_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->constrained()->cascadeOnDelete();
            $table->boolean('push_stock')->default(true);
            $table->string('allocation_strategy')->default('warehouse_balance');
            $table->decimal('stock_buffer', 18, 4)->default(0);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'sales_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_channel_routes');
        Schema::dropIfExists('product_channel_mappings');
        Schema::dropIfExists('wordpress_integrations');
        Schema::dropIfExists('sales_channels');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('products');
    }
};

