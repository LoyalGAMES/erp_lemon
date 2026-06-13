<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipping_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wordpress_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('generated')->index();
            $table->string('provider')->nullable()->index();
            $table->string('label_number')->nullable()->index();
            $table->string('tracking_number')->nullable()->index();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256')->nullable();
            $table->string('source_url')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('generated_at')->nullable()->index();
            $table->timestamps();

            $table->index(['external_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_labels');
    }
};
