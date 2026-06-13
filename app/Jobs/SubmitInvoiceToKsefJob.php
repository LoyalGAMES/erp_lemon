<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KsefSubmission;
use App\Services\Ksef\KsefSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SubmitInvoiceToKsefJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        private readonly int $ksefSubmissionId,
    ) {
    }

    public function handle(KsefSubmissionService $submissions): void
    {
        $submission = KsefSubmission::query()->findOrFail($this->ksefSubmissionId);

        $submissions->submit($submission);
    }

    public function failed(Throwable $exception): void
    {
        KsefSubmission::query()
            ->whereKey($this->ksefSubmissionId)
            ->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
                'response_metadata' => [
                    'handled_as' => 'queue_failed',
                    'message' => $exception->getMessage(),
                ],
            ]);
    }
}
