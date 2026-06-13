<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('integration_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wordpress_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16)->index();
            $table->string('operation')->index();
            $table->string('status', 24)->default('pending')->index();
            $table->string('external_resource')->nullable();
            $table->string('external_id')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_sync_queue_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->decimal('quantity_to_push', 18, 4)->nullable();
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('stock_sync_queue_items');
        Schema::dropIfExists('integration_sync_logs');
    }
};

