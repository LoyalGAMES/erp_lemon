<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_parameter_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('input_type')->default('text');
            $table->json('values')->nullable();
            $table->boolean('is_variant')->default(false);
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_variant', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_parameter_definitions');
    }
};
