<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_orders', function (Blueprint $table): void {
            $table->string('fulfillment_status', 32)->nullable()->after('status')->index();
            $table->unsignedInteger('label_generation_attempts')->default(0)->after('fulfillment_status');
            $table->timestamp('label_generation_next_at')->nullable()->after('label_generation_attempts')->index();
            $table->text('label_generation_last_error')->nullable()->after('label_generation_next_at');
            $table->string('woo_shipped_sync_status', 24)->nullable()->after('label_generation_last_error')->index();
            $table->unsignedInteger('woo_shipped_sync_attempts')->default(0)->after('woo_shipped_sync_status');
            $table->timestamp('woo_shipped_sync_next_at')->nullable()->after('woo_shipped_sync_attempts')->index();
            $table->text('woo_shipped_sync_error')->nullable()->after('woo_shipped_sync_next_at');
        });

        DB::table('external_orders')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('packing_tasks')
                ->whereColumn('packing_tasks.external_order_id', 'external_orders.id')
                ->where('packing_tasks.status', 'shipped'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('packing_tasks')
                ->whereColumn('packing_tasks.external_order_id', 'external_orders.id')
                ->whereNotIn('packing_tasks.status', ['shipped', 'cancelled']))
            ->update(['fulfillment_status' => 'shipped']);

        DB::table('external_orders')
            ->whereNull('fulfillment_status')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('packing_tasks')
                ->whereColumn('packing_tasks.external_order_id', 'external_orders.id')
                ->where('packing_tasks.status', 'packed'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('packing_tasks')
                ->whereColumn('packing_tasks.external_order_id', 'external_orders.id')
                ->whereNotIn('packing_tasks.status', ['packed', 'cancelled']))
            ->update(['fulfillment_status' => 'awaiting_courier']);

        DB::table('external_orders')
            ->whereNull('fulfillment_status')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('packing_tasks')
                ->whereColumn('packing_tasks.external_order_id', 'external_orders.id')
                ->where('packing_tasks.status', 'picked'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('packing_tasks')
                ->whereColumn('packing_tasks.external_order_id', 'external_orders.id')
                ->whereNotIn('packing_tasks.status', ['picked', 'cancelled']))
            ->update(['fulfillment_status' => 'ready_to_pack']);

        DB::table('external_orders')
            ->whereNull('fulfillment_status')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('packing_tasks')
                ->whereColumn('packing_tasks.external_order_id', 'external_orders.id')
                ->where('packing_tasks.status', 'problem'))
            ->update(['fulfillment_status' => 'problem']);

        DB::table('external_orders')
            ->whereNull('fulfillment_status')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('packing_tasks')
                ->whereColumn('packing_tasks.external_order_id', 'external_orders.id')
                ->where('packing_tasks.status', 'open'))
            ->update(['fulfillment_status' => 'picking']);
    }

    public function down(): void
    {
        Schema::table('external_orders', function (Blueprint $table): void {
            $table->dropIndex(['fulfillment_status']);
            $table->dropIndex(['label_generation_next_at']);
            $table->dropIndex(['woo_shipped_sync_status']);
            $table->dropIndex(['woo_shipped_sync_next_at']);
        });

        Schema::table('external_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'fulfillment_status',
                'label_generation_attempts',
                'label_generation_next_at',
                'label_generation_last_error',
                'woo_shipped_sync_status',
                'woo_shipped_sync_attempts',
                'woo_shipped_sync_next_at',
                'woo_shipped_sync_error',
            ]);
        });
    }
};
