<?php

declare(strict_types=1);

namespace {
    if (! defined('ABSPATH')) {
        define('ABSPATH', __DIR__);
    }

    final class WP_Error
    {
        public function __construct(
            public readonly string $code = '',
            public readonly string $message = '',
        ) {}
    }

    final class LL_Returns_Settings
    {
        public function get($key, $default = null)
        {
            return $key === 'mark_order_refunded' ? 'no' : $default;
        }
    }

    final class LL_Returns_Return_Repository
    {
        public const META_PREFIX = '_ll_returns_';

        public array $payload = [];

        public int $createdRefundId = 0;

        public function get_payload($requestId)
        {
            return $this->payload;
        }

        public function record_refund_error($requestId, $error): void {}

        public function record_refund_created($requestId, $refundId): void
        {
            $this->createdRefundId = (int) $refundId;
        }
    }

    function absint($value): int
    {
        return abs((int) $value);
    }

    function get_post_meta($postId, $key, $single = false)
    {
        return 0;
    }

    function wc_get_order($orderId)
    {
        return $GLOBALS['ll_returns_test_order'] ?? false;
    }

    function wc_create_refund(array $args)
    {
        $GLOBALS['ll_returns_test_refund_args'] = $args;

        return new class
        {
            public function get_id(): int
            {
                return 987;
            }
        };
    }

    function wc_get_price_decimals(): int
    {
        return 2;
    }

    function wc_format_decimal($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    function is_wp_error($value): bool
    {
        return $value instanceof WP_Error;
    }

    function ll_returns_test_translate($value, $domain = null): string
    {
        return (string) $value;
    }

    $refundServiceSource = file_get_contents(dirname(__DIR__, 2).'/wordpress/lemon-woo-returns/includes/class-refund-service.php');
    $refundServiceSource = preg_replace('/\b__\s*\(/', 'll_returns_test_translate(', (string) $refundServiceSource);
    eval('?>'.$refundServiceSource);
}

namespace Tests\Unit {
    use LL_Returns_Refund_Service;
    use LL_Returns_Return_Repository;
    use LL_Returns_Settings;
    use Tests\TestCase;

    final class LemonWooReturnsRefundServiceTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['ll_returns_test_order'], $GLOBALS['ll_returns_test_refund_args']);

            parent::tearDown();
        }

        public function test_it_itemizes_shipping_net_and_tax_in_woocommerce_refund(): void
        {
            $productItem = new class
            {
                public function get_quantity(): int
                {
                    return 1;
                }

                public function get_total(): float
                {
                    return 100.0;
                }

                public function get_taxes(): array
                {
                    return ['total' => [19 => 19.0]];
                }
            };
            $shippingItem = new class
            {
                public function is_type($type): bool
                {
                    return $type === 'shipping';
                }

                public function get_total(): float
                {
                    return 12.0;
                }

                public function get_taxes(): array
                {
                    return ['total' => [19 => 2.28]];
                }
            };
            $order = new class($productItem, $shippingItem)
            {
                public array $notes = [];

                public function __construct(
                    private readonly object $productItem,
                    private readonly object $shippingItem,
                ) {}

                public function get_item($itemId): ?object
                {
                    return match ((int) $itemId) {
                        10 => $this->productItem,
                        20 => $this->shippingItem,
                        default => null,
                    };
                }

                public function get_items($type = 'line_item'): array
                {
                    return $type === 'shipping' ? [20 => $this->shippingItem] : [10 => $this->productItem];
                }

                public function get_qty_refunded_for_item($itemId): int
                {
                    return 0;
                }

                public function get_remaining_refund_amount(): float
                {
                    return 133.28;
                }

                public function get_total(): float
                {
                    return 133.28;
                }

                public function get_id(): int
                {
                    return 55;
                }

                public function get_currency(): string
                {
                    return 'PLN';
                }

                public function add_order_note($note): void
                {
                    $this->notes[] = (string) $note;
                }
            };

            $GLOBALS['ll_returns_test_order'] = $order;
            $repository = new LL_Returns_Return_Repository;
            $repository->payload = [
                'wc_order_id' => 55,
                'return_reference' => 'RET-WC-1',
                'items' => [
                    [
                        'wc_order_item_id' => 10,
                        'quantity' => 1,
                    ],
                ],
            ];
            $service = new LL_Returns_Refund_Service(new LL_Returns_Settings, $repository);

            $refundId = $service->create_refund_for_request(123, [
                'shipping_refund_amount' => 11.90,
                'shipping_refund' => [
                    'gross_amount' => 11.90,
                    'net_amount' => 10.00,
                    'tax_amount' => 1.90,
                    'vat_rate' => 19,
                    'currency' => 'PLN',
                    'wc_order_item_id' => 20,
                ],
            ]);

            $args = $GLOBALS['ll_returns_test_refund_args'];
            $this->assertSame(987, $refundId);
            $this->assertSame(987, $repository->createdRefundId);
            $this->assertSame('130.90', $args['amount']);
            $this->assertSame('100.00', $args['line_items'][10]['refund_total']);
            $this->assertSame([19 => 19.0], $args['line_items'][10]['refund_tax']);
            $this->assertSame(1, $args['line_items'][20]['qty']);
            $this->assertSame('10.00', $args['line_items'][20]['refund_total']);
            $this->assertSame([19 => 1.9], $args['line_items'][20]['refund_tax']);
            $this->assertFalse($args['refund_payment']);
        }
    }
}
