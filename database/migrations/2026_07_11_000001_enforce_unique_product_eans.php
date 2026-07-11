<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->where('ean', '')->update(['ean' => null]);

        $duplicatedEans = DB::table('products')
            ->select('ean')
            ->whereNotNull('ean')
            ->where('ean', '<>', '')
            ->groupBy('ean')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ean');

        foreach ($duplicatedEans as $ean) {
            $duplicates = DB::table('products')
                ->where('ean', $ean)
                ->orderBy('id')
                ->get(['id', 'attributes']);

            foreach ($duplicates->skip(1) as $duplicate) {
                $attributes = json_decode((string) ($duplicate->attributes ?? ''), true);
                $attributes = is_array($attributes) ? $attributes : [];
                data_set($attributes, 'master.identifier_conflict', [
                    'type' => 'duplicated_ean',
                    'previous_ean' => (string) $ean,
                    'detected_at' => now()->toISOString(),
                    'resolution' => 'cleared_for_manual_review',
                ]);

                DB::table('products')->where('id', $duplicate->id)->update([
                    'ean' => null,
                    'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->unique('ean', 'products_ean_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_ean_unique');
        });
    }
};
