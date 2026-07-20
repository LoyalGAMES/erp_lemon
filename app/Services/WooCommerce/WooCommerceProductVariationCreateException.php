<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use RuntimeException;

/**
 * A failed POST /products/{id}/variations. Carries WooCommerce's error code so
 * callers can react programmatically (e.g. resolve a duplicate-SKU conflict)
 * instead of string-matching a status-only message.
 */
final class WooCommerceProductVariationCreateException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $wooCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function indicatesDuplicateSku(): bool
    {
        return $this->status === 400 && $this->wooCode === 'product_invalid_sku';
    }
}
