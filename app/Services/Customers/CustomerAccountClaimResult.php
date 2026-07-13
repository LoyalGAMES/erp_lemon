<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\ExternalOrder;

final readonly class CustomerAccountClaimResult
{
    public function __construct(
        public bool $createdAccount,
        public string $externalCustomerId,
        public Customer $customer,
        public ExternalOrder $order,
        public string $loginUrl,
    ) {}
}
