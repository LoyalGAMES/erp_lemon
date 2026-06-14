<?php

use App\Services\Ksef\KsefSubmissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ksef_submissions')) {
            return;
        }

        DB::table('ksef_submissions')
            ->where('last_error', KsefSubmissionService::LEGACY_GATEWAY_ERROR)
            ->update([
                'status' => 'failed',
                'last_error' => KsefSubmissionService::LEGACY_GATEWAY_CLEANUP_ERROR,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ksef_submissions')) {
            return;
        }

        DB::table('ksef_submissions')
            ->where('last_error', KsefSubmissionService::LEGACY_GATEWAY_CLEANUP_ERROR)
            ->update([
                'status' => 'requires_configuration',
                'last_error' => KsefSubmissionService::LEGACY_GATEWAY_ERROR,
                'updated_at' => now(),
            ]);
    }
};
