<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_labels', function (Blueprint $table): void {
            $table->unsignedBigInteger('sales_channel_id')->nullable()->change();
            $table->unsignedBigInteger('external_order_id')->nullable()->change();
            $table->foreignId('return_case_id')
                ->nullable()
                ->constrained('return_cases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipping_labels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_case_id');
        });
    }
};
