<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExternalOrder;
use App\Services\Packing\PackingLabelAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class GeneratePackingLabelJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(
        private readonly int $orderId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(PackingLabelAutomationService $automation): void
    {
        $order = ExternalOrder::query()->find($this->orderId);

        if ($order instanceof ExternalOrder) {
            $automation->ensureForOrder($order);
        }
    }
}
