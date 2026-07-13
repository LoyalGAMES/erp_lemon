<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ReturnCase;
use App\Services\Communication\CustomerCommunicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendReturnWaitingForPackageMailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(
        private readonly int $returnCaseId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->returnCaseId;
    }

    public function handle(CustomerCommunicationService $communication): void
    {
        $returnCase = ReturnCase::query()->find($this->returnCaseId);

        if (! $returnCase instanceof ReturnCase) {
            return;
        }

        $communication->sendReturnStatus($returnCase, 'return_waiting_for_package');
    }
}
