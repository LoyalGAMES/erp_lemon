<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_labels', function (Blueprint $table): void {
            $table->string('idempotency_key', 191)->nullable()->after('purpose')->unique();
            $table->string('tracking_status', 100)->nullable()->after('tracking_number')->index();
            $table->timestamp('tracking_checked_at')->nullable()->after('tracking_status')->index();
            $table->timestamp('next_tracking_check_at')->nullable()->after('tracking_checked_at')->index();
            $table->unsignedInteger('tracking_attempts')->default(0)->after('next_tracking_check_at');
            $table->text('tracking_last_error')->nullable()->after('tracking_attempts');
            $table->timestamp('picked_up_at')->nullable()->after('tracking_last_error')->index();
        });

        $duplicates = DB::table('shipping_labels')
            ->select('external_order_id', DB::raw('MAX(id) AS keep_id'))
            ->where('purpose', 'shipment')
            ->where('status', 'generated')
            ->whereNotNull('external_order_id')
            ->groupBy('external_order_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('shipping_labels')
                ->where('external_order_id', $duplicate->external_order_id)
                ->where('purpose', 'shipment')
                ->where('status', 'generated')
                ->where('id', '!=', $duplicate->keep_id)
                ->update([
                    'status' => 'superseded',
                    'tracking_last_error' => 'Starsza, zduplikowana etykieta wysyłkowa została zastąpiona nowszą.',
                    'updated_at' => now(),
                ]);
        }

        $canonicalLabels = DB::table('shipping_labels')
            ->select(['id', 'external_order_id'])
            ->where('purpose', 'shipment')
            ->whereNotNull('external_order_id')
            ->orderByDesc('id')
            ->get()
            ->unique('external_order_id');

        foreach ($canonicalLabels as $label) {
            DB::table('shipping_labels')
                ->where('id', $label->id)
                ->update(['idempotency_key' => 'shipment:order:'.$label->external_order_id]);
        }
    }

    public function down(): void
    {
        DB::table('shipping_labels')
            ->where('status', 'superseded')
            ->where('tracking_last_error', 'Starsza, zduplikowana etykieta wysyłkowa została zastąpiona nowszą.')
            ->update(['status' => 'generated']);

        Schema::table('shipping_labels', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['tracking_status']);
            $table->dropIndex(['tracking_checked_at']);
            $table->dropIndex(['next_tracking_check_at']);
            $table->dropIndex(['picked_up_at']);
        });

        Schema::table('shipping_labels', function (Blueprint $table): void {
            $table->dropColumn([
                'idempotency_key',
                'tracking_status',
                'tracking_checked_at',
                'next_tracking_check_at',
                'tracking_attempts',
                'tracking_last_error',
                'picked_up_at',
            ]);
        });
    }
};
