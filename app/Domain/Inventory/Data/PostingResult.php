<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Data;

final readonly class PostingResult
{
    /**
     * @param list<StockLedgerEntry> $entries
     * @param array<string, int> $balances
     */
    public function __construct(
        public array $entries,
        public array $balances,
    ) {
    }
}

