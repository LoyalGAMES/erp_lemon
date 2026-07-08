<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\PackingTask;
use App\Models\Product;

/**
 * Klasyfikuje produkty do segmentów kompletacji: obuwie albo odzież.
 * Dopasowanie po kategoriach WooCommerce produktu i nazwie, według
 * słów kluczowych z ustawień pakowania.
 */
final class ProductSegmentService
{
    public const SEGMENT_CLOTHING = 'clothing';
    public const SEGMENT_FOOTWEAR = 'footwear';

    /** @var list<string>|null */
    private ?array $keywords = null;

    public function __construct(
        private readonly PackingSettingsService $settings,
    ) {
    }

    public function segmentForTask(PackingTask $task): string
    {
        return $this->segment($task->product, $task->product_name);
    }

    public function segmentForLine(ExternalOrderLine $line): string
    {
        return $this->segment($line->product, $line->name);
    }

    /**
     * @return list<string> unikalne segmenty pozycji zamówienia
     */
    public function segmentsForOrder(ExternalOrder $order): array
    {
        $order->loadMissing('lines.product');

        return $order->lines
            ->filter(fn (ExternalOrderLine $line): bool => (float) $line->quantity > 0)
            ->map(fn (ExternalOrderLine $line): string => $this->segmentForLine($line))
            ->unique()
            ->values()
            ->all();
    }

    public function isMixedOrder(ExternalOrder $order): bool
    {
        return count($this->segmentsForOrder($order)) > 1;
    }

    public static function label(string $segment): string
    {
        return $segment === self::SEGMENT_FOOTWEAR ? 'Obuwie' : 'Odzież';
    }

    private function segment(?Product $product, ?string $fallbackName): string
    {
        $haystacks = collect((array) data_get($product?->attributes, 'woocommerce_categories', []))
            ->map(fn (mixed $category): string => (string) $category)
            ->push((string) $product?->name)
            ->push((string) $fallbackName)
            ->map(fn (string $value): string => mb_strtolower($value))
            ->filter();

        foreach ($this->footwearKeywords() as $keyword) {
            foreach ($haystacks as $haystack) {
                if (str_contains($haystack, $keyword)) {
                    return self::SEGMENT_FOOTWEAR;
                }
            }
        }

        return self::SEGMENT_CLOTHING;
    }

    /**
     * @return list<string>
     */
    private function footwearKeywords(): array
    {
        return $this->keywords ??= $this->settings->data()['footwear_keywords'];
    }
}
