<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->foreignId('source_sales_channel_id')
                ->nullable()
                ->after('quantity_available')
                ->constrained('sales_channels')
                ->nullOnDelete();
            $table->decimal('source_available_quantity', 18, 4)
                ->nullable()
                ->after('source_sales_channel_id');
            $table->timestamp('source_observed_at')
                ->nullable()
                ->after('source_available_quantity')
                ->index();
            $table->json('source_reflected_order_quantities')
                ->nullable()
                ->after('source_observed_at');
        });

        Schema::table('warehouse_documents', function (Blueprint $table): void {
            $table->string('order_fulfillment_key', 191)
                ->nullable()
                ->after('external_reference');
        });

        $seenFulfillmentKeys = [];

        DB::table('warehouse_documents')
            ->where('type', 'WZ')
            ->whereNull('deleted_at')
            ->orderByRaw("case when status = 'posted' then 0 else 1 end")
            ->orderByDesc('id')
            ->get(['id', 'source_warehouse_id', 'metadata'])
            ->each(function (object $document) use (&$seenFulfillmentKeys): void {
                $metadata = is_string($document->metadata)
                    ? json_decode($document->metadata, true)
                    : $document->metadata;
                $metadata = is_array($metadata) ? $metadata : [];
                $salesChannelId = (int) ($metadata['sales_channel_id'] ?? 0);
                $externalOrderId = trim((string) ($metadata['external_order_id'] ?? ''));
                $warehouseId = (int) ($document->source_warehouse_id ?? 0);

                if ($salesChannelId <= 0 || $externalOrderId === '' || $warehouseId <= 0) {
                    return;
                }

                $key = 'order-wz:'.hash(
                    'sha256',
                    implode('|', [$salesChannelId, $externalOrderId, $warehouseId]),
                );

                if (isset($seenFulfillmentKeys[$key])) {
                    return;
                }

                DB::table('warehouse_documents')
                    ->where('id', $document->id)
                    ->update(['order_fulfillment_key' => $key]);
                $seenFulfillmentKeys[$key] = true;
            });

        Schema::table('warehouse_documents', function (Blueprint $table): void {
            $table->unique('order_fulfillment_key', 'warehouse_documents_order_fulfillment_unique');
        });

        Schema::table('stock_sync_queue_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('version')->default(1)->after('sales_channel_id');
            $table->index(
                ['product_id', 'sales_channel_id', 'version'],
                'stock_sync_queue_product_channel_version_index',
            );
        });

        Schema::create('stock_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('desired_version')->default(0);
            $table->decimal('desired_quantity', 18, 4)->default(0);
            $table->unsignedBigInteger('exported_version')->default(0);
            $table->foreignId('queue_item_id')
                ->nullable()
                ->constrained('stock_sync_queue_items')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['product_id', 'sales_channel_id'],
                'stock_sync_states_product_channel_unique',
            );
        });

        $versions = [];

        DB::table('stock_sync_queue_items')
            ->whereNotNull('sales_channel_id')
            ->orderBy('id')
            ->get(['id', 'product_id', 'sales_channel_id', 'status', 'quantity_to_push'])
            ->each(function (object $item) use (&$versions): void {
                $key = $item->product_id.':'.$item->sales_channel_id;
                $version = ($versions[$key]['desired_version'] ?? 0) + 1;
                $versions[$key] = [
                    'product_id' => (int) $item->product_id,
                    'sales_channel_id' => (int) $item->sales_channel_id,
                    'desired_version' => $version,
                    'desired_quantity' => (float) ($item->quantity_to_push ?? 0),
                    'exported_version' => $item->status === 'success'
                        ? $version
                        : (int) ($versions[$key]['exported_version'] ?? 0),
                    'queue_item_id' => (int) $item->id,
                ];

                DB::table('stock_sync_queue_items')
                    ->where('id', $item->id)
                    ->update(['version' => $version]);
            });

        $now = now();

        foreach ($versions as $state) {
            DB::table('stock_sync_states')->insert($state + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_sync_states');

        Schema::table('stock_sync_queue_items', function (Blueprint $table): void {
            $table->dropIndex('stock_sync_queue_product_channel_version_index');
            $table->dropColumn('version');
        });

        Schema::table('warehouse_documents', function (Blueprint $table): void {
            $table->dropUnique('warehouse_documents_order_fulfillment_unique');
            $table->dropColumn('order_fulfillment_key');
        });

        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->dropForeign(['source_sales_channel_id']);
            $table->dropIndex(['source_observed_at']);
            $table->dropColumn([
                'source_sales_channel_id',
                'source_available_quantity',
                'source_observed_at',
                'source_reflected_order_quantities',
            ]);
        });
    }
};
