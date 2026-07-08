<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('return_case_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16)->default('incoming')->index();
            $table->string('method', 40)->default('other')->index();
            $table->string('status', 32)->default('booked')->index();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('PLN');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('booked_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['external_order_id', 'status']);
            $table->index(['return_case_id', 'status']);
            $table->index(['method', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');
    }
};
