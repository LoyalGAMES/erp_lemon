<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('products')
            ->select(['id', 'sku', 'name', 'attributes'])
            ->whereNotNull('attributes')
            ->where(function ($query): void {
                $query->where('sku', 'like', '%-COPY%')
                    ->orWhere('name', 'like', '%(kopia)%');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('product_channel_mappings')
                    ->whereColumn('product_channel_mappings.product_id', 'products.id');
            })
            ->orderBy('id')
            ->chunkById(100, function ($products): void {
                foreach ($products as $product) {
                    $attributes = json_decode((string) $product->attributes, true);

                    if (! is_array($attributes)) {
                        continue;
                    }

                    $changed = false;

                    foreach (array_keys($attributes) as $key) {
                        if (str_starts_with((string) $key, 'woocommerce_')) {
                            unset($attributes[$key]);
                            $changed = true;
                        }
                    }

                    if (! $changed) {
                        continue;
                    }

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data cleanup only. Restoring stale WooCommerce metadata on copies would be unsafe.
    }
};
