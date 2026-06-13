<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('child_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('relation_type')->default('variant');
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['parent_product_id', 'child_product_id', 'relation_type'], 'product_relations_unique');
            $table->index(['child_product_id', 'relation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_relations');
    }
};
