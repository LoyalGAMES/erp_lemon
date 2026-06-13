<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('products')
            ->select(['id', 'attributes'])
            ->whereNotNull('attributes')
            ->orderBy('id')
            ->chunkById(100, function ($products): void {
                foreach ($products as $product) {
                    $attributes = json_decode((string) $product->attributes, true);

                    if (! is_array($attributes) || data_get($attributes, 'master.copy.created_from_product_id') === null) {
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
