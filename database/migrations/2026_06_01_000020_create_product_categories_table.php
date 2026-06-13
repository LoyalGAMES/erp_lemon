<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('parent_external_id')->nullable();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('path')->nullable();
            $table->unsignedInteger('count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['sales_channel_id', 'external_id']);
            $table->index(['sales_channel_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
