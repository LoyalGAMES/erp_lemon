<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('packing_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_line_id')->nullable();
            $table->string('order_number')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('product_name');
            $table->decimal('quantity_required', 18, 4);
            $table->decimal('quantity_picked', 18, 4)->default(0);
            $table->string('status', 24)->default('open')->index();
            $table->string('courier')->nullable()->index();
            $table->string('size_label')->nullable()->index();
            $table->timestamp('order_date')->nullable()->index();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['external_order_id', 'external_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_tasks');
    }
};
