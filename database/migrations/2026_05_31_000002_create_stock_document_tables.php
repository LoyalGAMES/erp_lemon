<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouse_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->string('type', 16)->index();
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('source_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('document_date')->useCurrent();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('external_reference')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('warehouse_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_net_price', 18, 4)->nullable();
            $table->decimal('unit_gross_price', 18, 4)->nullable();
            $table->string('source_lot')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_document_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_change', 18, 4);
            $table->string('direction', 8);
            $table->timestamp('posted_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id', 'posted_at']);
        });

        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('quantity_reserved', 18, 4)->default(0);
            $table->decimal('quantity_available', 18, 4)->default(0);
            $table->timestamp('recalculated_at')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
        });

        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id')->nullable()->index();
            $table->decimal('quantity', 18, 4);
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_ledger_entries');
        Schema::dropIfExists('warehouse_document_lines');
        Schema::dropIfExists('warehouse_documents');
    }
};

