<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use RuntimeException;

final class InvoiceValidationException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }
}
