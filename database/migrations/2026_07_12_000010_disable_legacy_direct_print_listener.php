<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('print_jobs')) {
            DB::table('print_jobs')
                ->where('reserved_by', 'direct-listener')
                ->whereIn('status', ['pending', 'printing', 'failed'])
                ->update([
                    'status' => 'pending',
                    'reserved_by' => null,
                    'reserved_at' => null,
                    'next_attempt_at' => null,
                    'attempts' => 0,
                    'failed_at' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            if (Schema::hasColumn('print_jobs', 'listener_url')) {
                Schema::table('print_jobs', function (Blueprint $table): void {
                    $table->dropColumn('listener_url');
                });
            }
        }

        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $rawSettings = DB::table('app_settings')
            ->where('key', 'packing_settings')
            ->value('value');
        $settings = is_string($rawSettings) ? json_decode($rawSettings, true) : $rawSettings;

        if (! is_array($settings) || ! is_array($settings['stations'] ?? null)) {
            return;
        }

        $changed = false;
        foreach ($settings['stations'] as &$station) {
            if (is_array($station) && array_key_exists('listener_url', $station)) {
                unset($station['listener_url']);
                $changed = true;
            }
        }
        unset($station);

        if ($changed) {
            DB::table('app_settings')
                ->where('key', 'packing_settings')
                ->update([
                    'value' => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('print_jobs') && ! Schema::hasColumn('print_jobs', 'listener_url')) {
            Schema::table('print_jobs', function (Blueprint $table): void {
                $table->string('listener_url', 180)->nullable();
            });
        }

        // Removed URLs and direct reservations cannot be reconstructed.
    }
};
