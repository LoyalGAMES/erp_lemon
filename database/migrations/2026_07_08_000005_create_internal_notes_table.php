<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('return_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name')->nullable();
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['external_order_id', 'created_at']);
            $table->index(['return_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notes');
    }
};
