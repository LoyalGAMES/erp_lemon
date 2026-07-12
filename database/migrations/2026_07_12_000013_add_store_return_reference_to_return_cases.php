<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_cases', function (Blueprint $table): void {
            $table->string('store_return_reference', 80)->nullable()->after('number');
        });

        DB::table('return_cases')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata)
                        ? json_decode($row->metadata, true)
                        : (array) $row->metadata;
                    $reference = trim((string) data_get($metadata, 'return_reference', ''));

                    if ($reference === '') {
                        continue;
                    }

                    $reference = mb_substr($reference, 0, 80);

                    // Let the database collation decide equality. MySQL's
                    // default application collation is case/accent
                    // insensitive, while a PHP array comparison is not.
                    if (DB::table('return_cases')
                        ->where('store_return_reference', $reference)
                        ->exists()
                    ) {
                        continue;
                    }

                    DB::table('return_cases')->where('id', $row->id)->update([
                        'store_return_reference' => $reference,
                    ]);
                }
            });

        Schema::table('return_cases', function (Blueprint $table): void {
            $table->unique('store_return_reference');
        });
    }

    public function down(): void
    {
        Schema::table('return_cases', function (Blueprint $table): void {
            $table->dropUnique(['store_return_reference']);
            $table->dropColumn('store_return_reference');
        });
    }
};
