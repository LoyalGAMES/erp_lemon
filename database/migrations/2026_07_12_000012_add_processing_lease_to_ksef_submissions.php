<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_submissions', function (Blueprint $table): void {
            $table->uuid('processing_token')->nullable()->unique()->after('accepted_at');
            $table->timestamp('processing_started_at')->nullable()->index()->after('processing_token');
            $table->timestamp('processing_finished_at')->nullable()->after('processing_started_at');
            $table->unsignedInteger('attempts')->default(0)->after('processing_finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('ksef_submissions', function (Blueprint $table): void {
            $table->dropUnique(['processing_token']);
            $table->dropIndex(['processing_started_at']);
            $table->dropColumn([
                'processing_token',
                'processing_started_at',
                'processing_finished_at',
                'attempts',
            ]);
        });
    }
};
