<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('external_orders', function (Blueprint $table): void {
            $table->index(['external_created_at', 'id'], 'ext_orders_created_id_idx');
            $table->index(['sales_channel_id', 'status', 'external_created_at'], 'ext_orders_channel_status_created_idx');
        });

        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->index(['sales_channel_id', 'external_order_id', 'status'], 'stock_res_order_status_idx');
        });

        Schema::table('warehouse_documents', function (Blueprint $table): void {
            $table->index(['type', 'status', 'created_at'], 'warehouse_docs_type_status_created_idx');
        });

        Schema::table('packing_tasks', function (Blueprint $table): void {
            $table->index(['status', 'external_order_id'], 'packing_tasks_status_order_idx');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->index(['external_order_id', 'type', 'id'], 'invoices_order_type_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_order_type_id_idx');
        });

        Schema::table('warehouse_documents', function (Blueprint $table): void {
            $table->dropIndex('warehouse_docs_type_status_created_idx');
        });

        Schema::table('packing_tasks', function (Blueprint $table): void {
            $table->dropIndex('packing_tasks_status_order_idx');
        });

        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropIndex('stock_res_order_status_idx');
        });

        Schema::table('external_orders', function (Blueprint $table): void {
            $table->dropIndex('ext_orders_channel_status_created_idx');
            $table->dropIndex('ext_orders_created_id_idx');
        });
    }
};
