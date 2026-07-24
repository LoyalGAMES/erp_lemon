<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use App\Services\Products\ProductImageThumbnailService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Throwable;

final class ReturnOrderContextService
{
    public function __construct(
        private readonly ProductImageThumbnailService $thumbnails,
    ) {}

    /**
     * @return array{
     *     items:list<array<string, mixed>>,
     *     return_line_images:array<int, array{url:?string,thumbnail_url:?string,title:string}>,
     *     deadline:array<string, mixed>
     * }
     */
    public function build(ReturnCase $returnCase, int $windowDays): array
    {
        $order = $returnCase->externalOrder;

        if (! $order instanceof ExternalOrder) {
            return [
                'items' => [],
                'return_line_images' => $this->returnLineImages($returnCase),
                'deadline' => $this->deadlineSummary($returnCase, collect(), $windowDays),
            ];
        }

        $rootId = (int) ($order->split_root_order_id ?: $order->id);
        $orders = ExternalOrder::query()
            ->withTrashed()
            ->where(function ($query) use ($rootId): void {
                $query->whereKey($rootId)
                    ->orWhere('split_root_order_id', $rootId);
            })
            ->with([
                'lines.product',
                'shipmentLabels',
                'packingTasks',
            ])
            ->orderBy('id')
            ->get();

        return [
            'items' => $this->comparisonItems($returnCase, $orders),
            'return_line_images' => $this->returnLineImages($returnCase),
            'deadline' => $this->deadlineSummary($returnCase, $orders, $windowDays),
        ];
    }

    /**
     * @param  Collection<int, ExternalOrder>  $orders
     * @return list<array<string, mixed>>
     */
    private function comparisonItems(ReturnCase $returnCase, Collection $orders): array
    {
        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];
        /** @var array<int, list<string>> $keysByProduct */
        $keysByProduct = [];

        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                $key = $this->orderLineKey($line);
                $image = $this->imageData($line, $line->product);

                if (! isset($rows[$key])) {
                    $rows[$key] = [
                        'key' => $key,
                        'product' => $line->product,
                        'product_id' => $line->product_id,
                        'sku' => $line->product?->sku ?: ($line->sku ?: '-'),
                        'name' => $line->product?->name ?: ($line->name ?: '-'),
                        'ordered_quantity' => 0.0,
                        'returned_quantity' => 0.0,
                        'accepted_quantity' => 0.0,
                        'unit_gross_price' => $line->unit_gross_price !== null
                            ? (float) $line->unit_gross_price
                            : null,
                        'image_url' => $image['url'],
                        'thumbnail_url' => $image['thumbnail_url'],
                        'return_only' => false,
                    ];
                }

                $rows[$key]['ordered_quantity'] += (float) $line->quantity;

                if ($rows[$key]['image_url'] === null && $image['url'] !== null) {
                    $rows[$key]['image_url'] = $image['url'];
                    $rows[$key]['thumbnail_url'] = $image['thumbnail_url'];
                }

                if ($line->product_id !== null) {
                    $keysByProduct[(int) $line->product_id][] = $key;
                    $keysByProduct[(int) $line->product_id] = array_values(array_unique($keysByProduct[(int) $line->product_id]));
                }
            }
        }

        foreach ($returnCase->lines as $returnLine) {
            $key = $this->returnLineKey($returnLine);

            if (! isset($rows[$key]) && $returnLine->product_id !== null) {
                $productKeys = $keysByProduct[(int) $returnLine->product_id] ?? [];

                if (count($productKeys) === 1) {
                    $key = $productKeys[0];
                }
            }

            if (! isset($rows[$key])) {
                $image = $this->imageData($returnLine->externalOrderLine, $returnLine->product);
                $rows[$key] = [
                    'key' => $key,
                    'product' => $returnLine->product,
                    'product_id' => $returnLine->product_id,
                    'sku' => $returnLine->product?->sku ?: ($returnLine->externalOrderLine?->sku ?: '-'),
                    'name' => $returnLine->product?->name ?: ($returnLine->externalOrderLine?->name ?: '-'),
                    'ordered_quantity' => 0.0,
                    'returned_quantity' => 0.0,
                    'accepted_quantity' => 0.0,
                    'unit_gross_price' => $returnLine->externalOrderLine?->unit_gross_price !== null
                        ? (float) $returnLine->externalOrderLine->unit_gross_price
                        : null,
                    'image_url' => $image['url'],
                    'thumbnail_url' => $image['thumbnail_url'],
                    'return_only' => true,
                ];
            }

            $rows[$key]['returned_quantity'] += (float) $returnLine->quantity_expected;
            $rows[$key]['accepted_quantity'] += (float) $returnLine->quantity_accepted;
        }

        return array_values($rows);
    }

    /**
     * @return array<int, array{url:?string,thumbnail_url:?string,title:string}>
     */
    private function returnLineImages(ReturnCase $returnCase): array
    {
        $images = [];

        foreach ($returnCase->lines as $line) {
            $image = $this->imageData($line->externalOrderLine, $line->product);
            $sku = $line->product?->sku ?: ($line->externalOrderLine?->sku ?: 'Produkt');
            $name = $line->product?->name ?: ($line->externalOrderLine?->name ?: 'pozycja zwrotu');

            $images[(int) $line->id] = $image + [
                'title' => trim($sku.' — '.$name),
            ];
        }

        return $images;
    }

    /**
     * @param  Collection<int, ExternalOrder>  $orders
     * @return array<string, mixed>
     */
    private function deadlineSummary(ReturnCase $returnCase, Collection $orders, int $windowDays): array
    {
        $windowDays = max(1, min(365, $windowDays));
        $deliveredAt = null;
        $shippedAt = null;

        foreach ($orders as $order) {
            foreach ($order->shipmentLabels as $label) {
                if (in_array(mb_strtolower((string) $label->status), ['cancelled', 'canceled', 'voided', 'failed'], true)) {
                    continue;
                }

                $deliveredAt = $this->latestDate($deliveredAt, $this->firstDate([
                    data_get($label->response_payload, 'tracking.delivered_at'),
                    data_get($label->response_payload, 'shipment.delivered_at'),
                    data_get($label->response_payload, 'delivered_at'),
                ]));
                $shippedAt = $this->latestDate($shippedAt, $this->firstDate([
                    $label->picked_up_at,
                    data_get($label->response_payload, 'tracking.picked_up_at'),
                    data_get($label->response_payload, 'shipment.picked_up_at'),
                ]));
            }

            foreach ($order->packingTasks as $task) {
                $shippedAt = $this->latestDate($shippedAt, $this->firstDate([
                    data_get($task->metadata, 'courier_pickup.picked_up_at'),
                    data_get($task->metadata, 'picked_up_at'),
                ]));
            }

            if (in_array(mb_strtolower((string) $order->fulfillment_status), ['shipped', 'completed'], true)) {
                $shippedAt = $this->latestDate($shippedAt, $this->firstDate([
                    data_get($order->raw_payload, 'date_completed'),
                    data_get($order->raw_payload, 'date_completed_gmt'),
                ]));
            }
        }

        $basisAt = $deliveredAt ?? $shippedAt;
        $source = $deliveredAt !== null ? 'delivered' : ($shippedAt !== null ? 'shipped' : 'unknown');
        $submittedAt = $returnCase->created_at !== null
            ? CarbonImmutable::instance($returnCase->created_at)
            : null;
        $deadlineAt = $basisAt?->startOfDay()->addDays($windowDays)->endOfDay();
        $isLate = $deadlineAt !== null && $submittedAt !== null
            ? $submittedAt->greaterThan($deadlineAt)
            : null;
        $differenceDays = $deadlineAt !== null && $submittedAt !== null
            ? $deadlineAt->startOfDay()->diffInDays($submittedAt->startOfDay())
            : null;

        return [
            'window_days' => $windowDays,
            'delivered_at' => $deliveredAt,
            'shipped_at' => $shippedAt,
            'basis_at' => $basisAt,
            'source' => $source,
            'deadline_at' => $deadlineAt,
            'submitted_at' => $submittedAt,
            'is_late' => $isLate,
            'difference_days' => $differenceDays,
        ];
    }

    private function orderLineKey(ExternalOrderLine $line): string
    {
        $canonical = trim((string) (
            $line->canonical_external_line_id
            ?: data_get($line->raw_payload, 'sempre_erp_split.root_external_line_id')
            ?: data_get($line->raw_payload, 'id')
            ?: data_get($line->raw_payload, 'sempre_erp_split.source_external_line_id')
            ?: $line->external_line_id
        ));

        if ($canonical === '') {
            return 'line-'.$line->id;
        }

        do {
            $previous = $canonical;
            $canonical = (string) preg_replace('/-S\d+$/', '', $canonical);
        } while ($canonical !== $previous);

        return 'canonical-'.$canonical;
    }

    private function returnLineKey(ReturnCaseLine $line): string
    {
        $canonical = trim((string) (
            $line->canonical_external_line_id
            ?: data_get($line->metadata, 'canonical_external_line_id')
        ));

        if ($canonical !== '') {
            do {
                $previous = $canonical;
                $canonical = (string) preg_replace('/-S\d+$/', '', $canonical);
            } while ($canonical !== $previous);

            return 'canonical-'.$canonical;
        }

        if ($line->externalOrderLine instanceof ExternalOrderLine) {
            return $this->orderLineKey($line->externalOrderLine);
        }

        return $line->product_id !== null
            ? 'return-product-'.$line->product_id
            : 'return-line-'.$line->id;
    }

    /** @return array{url:?string,thumbnail_url:?string} */
    private function imageData(?ExternalOrderLine $line, ?Product $product): array
    {
        $raw = is_array($line?->raw_payload) ? $line->raw_payload : [];
        $source = null;

        foreach ([
            $product?->imageUrl(),
            data_get($raw, 'image.src'),
            data_get($raw, 'image.url'),
            data_get($raw, 'images.0.src'),
            data_get($raw, 'images.0.url'),
            data_get($raw, 'parent_image.src'),
            data_get($raw, 'parent_image.url'),
        ] as $candidate) {
            $source = $this->safeImageUrl($candidate);

            if ($source !== null) {
                break;
            }
        }

        return [
            'url' => $source,
            'thumbnail_url' => $this->thumbnails->thumbnailUrl($source, 58, 72),
        ];
    }

    private function safeImageUrl(mixed $candidate): ?string
    {
        if (! is_scalar($candidate)) {
            return null;
        }

        $url = trim((string) $candidate);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/')
            && ! str_starts_with($url, '//')
            && ! str_contains($url, '\\')) {
            return $url;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true)
                ? $url
                : null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstDate(array $values): ?CarbonImmutable
    {
        foreach ($values as $value) {
            $date = $this->date($value);

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function latestDate(?CarbonImmutable $current, ?CarbonImmutable $candidate): ?CarbonImmutable
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate->greaterThan($current) ? $candidate : $current;
    }
}
