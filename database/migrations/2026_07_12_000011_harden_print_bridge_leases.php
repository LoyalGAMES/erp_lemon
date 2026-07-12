<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->string('deduplication_key', 64)->nullable()->after('shipping_label_id');
            $table->string('lease_token', 64)->nullable()->after('reserved_at');
            $table->string('reserved_station', 40)->nullable()->after('reserved_by');
        });

        $groups = [];
        $legacyInFlight = [];
        foreach (DB::table('print_jobs')->get(['id', 'shipping_label_id', 'printer_name', 'station_code', 'status']) as $job) {
            $natural = hash('sha256', implode("\0", [
                (string) $job->shipping_label_id,
                trim((string) ($job->station_code ?? '')),
                trim((string) $job->printer_name),
            ]));
            if ((string) $job->status === 'printing') {
                // The old protocol had no lease or durable acknowledgement, so
                // an in-flight row is ambiguous during rollout. Quarantine it
                // instead of risking a second physical print.
                $legacyInFlight[(int) $job->id] = true;
                $job->status = 'failed';
                DB::table('print_jobs')->where('id', $job->id)->update([
                    'status' => 'failed',
                    'reserved_by' => null,
                    'reserved_at' => null,
                    'next_attempt_at' => null,
                    'failed_at' => now(),
                    'last_error' => 'Wydruk był w toku podczas migracji protokołu i wymaga ręcznej weryfikacji przed ponowieniem.',
                ]);
            }
            $groups[$natural][] = $job;
        }

        $priority = ['printed' => 0, 'printing' => 1, 'pending' => 2, 'failed' => 3];
        foreach ($groups as $natural => $jobs) {
            usort($jobs, static function (object $left, object $right) use ($legacyInFlight, $priority): int {
                $leftPriority = (string) $left->status === 'printed'
                    ? 0
                    : (isset($legacyInFlight[(int) $left->id]) ? 1 : (($priority[(string) $left->status] ?? 4) + 1));
                $rightPriority = (string) $right->status === 'printed'
                    ? 0
                    : (isset($legacyInFlight[(int) $right->id]) ? 1 : (($priority[(string) $right->status] ?? 4) + 1));
                $statusOrder = $leftPriority <=> $rightPriority;

                return $statusOrder !== 0 ? $statusOrder : ((int) $right->id <=> (int) $left->id);
            });

            foreach ($jobs as $index => $job) {
                $updates = [
                    'deduplication_key' => $index === 0
                        ? $natural
                        : hash('sha256', $natural.':legacy:'.$job->id),
                ];
                if ($index > 0 && in_array((string) $job->status, ['pending', 'printing'], true)) {
                    $updates = array_merge($updates, [
                        'status' => 'failed',
                        'reserved_by' => null,
                        'reserved_station' => null,
                        'reserved_at' => null,
                        'lease_token' => null,
                        'next_attempt_at' => null,
                        'failed_at' => now(),
                        'last_error' => 'Starszy zduplikowany wpis kolejki został bezpiecznie wyłączony podczas migracji.',
                    ]);
                }
                DB::table('print_jobs')->where('id', $job->id)->update($updates);
            }
        }

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->unique('deduplication_key');
            $table->unique('lease_token');
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropUnique(['deduplication_key']);
            $table->dropUnique(['lease_token']);
            $table->dropColumn(['deduplication_key', 'lease_token', 'reserved_station']);
        });
    }
};
