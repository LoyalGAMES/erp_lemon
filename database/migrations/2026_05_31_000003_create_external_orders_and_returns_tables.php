<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('external_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('external_number')->nullable()->index();
            $table->string('status')->index();
            $table->string('currency', 3)->default('PLN');
            $table->decimal('total_gross', 18, 2)->default(0);
            $table->json('billing_data')->nullable();
            $table->json('shipping_data')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('external_created_at')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['sales_channel_id', 'external_id']);
        });

        Schema::create('external_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_line_id')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('name');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_net_price', 18, 4)->nullable();
            $table->decimal('unit_gross_price', 18, 4)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('return_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('external_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('target_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('warehouse_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('opened')->index();
            $table->string('reason')->nullable();
            $table->string('customer_email')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('return_case_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('external_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity_expected', 18, 4);
            $table->decimal('quantity_accepted', 18, 4)->default(0);
            $table->string('condition')->default('unchecked');
            $table->string('disposition')->default('restock');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_case_lines');
        Schema::dropIfExists('return_cases');
        Schema::dropIfExists('external_order_lines');
        Schema::dropIfExists('external_orders');
    }
};

