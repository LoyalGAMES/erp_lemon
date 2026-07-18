<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use RuntimeException;

final class ProductAttributeTermTranslationMismatchException extends RuntimeException
{
    /** @param array<string,mixed> $term */
    public function __construct(public readonly array $term, string $message)
    {
        parent::__construct($message);
    }
}
