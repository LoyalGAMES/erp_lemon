<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('return_cases', function (Blueprint $table): void {
            $table->foreignId('correction_invoice_id')
                ->nullable()
                ->after('warehouse_document_id')
                ->constrained('invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('correction_invoice_id');
        });
    }
};
