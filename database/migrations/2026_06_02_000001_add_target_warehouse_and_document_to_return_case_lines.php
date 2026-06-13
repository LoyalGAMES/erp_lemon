<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('return_case_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('return_case_lines', 'target_warehouse_id')) {
                $table->foreignId('target_warehouse_id')
                    ->nullable()
                    ->after('disposition')
                    ->constrained('warehouses')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('return_case_lines', 'warehouse_document_id')) {
                $table->foreignId('warehouse_document_id')
                    ->nullable()
                    ->after('target_warehouse_id')
                    ->constrained('warehouse_documents')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('return_case_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('return_case_lines', 'warehouse_document_id')) {
                $table->dropConstrainedForeignId('warehouse_document_id');
            }

            if (Schema::hasColumn('return_case_lines', 'target_warehouse_id')) {
                $table->dropConstrainedForeignId('target_warehouse_id');
            }
        });
    }
};
