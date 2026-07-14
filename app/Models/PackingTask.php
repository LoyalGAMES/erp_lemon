<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Products\ProductImageThumbnailService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackingTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_channel_id',
        'external_order_id',
        'external_order_line_id',
        'product_id',
        'external_line_id',
        'order_number',
        'customer_name',
        'sku',
        'product_name',
        'quantity_required',
        'quantity_picked',
        'status',
        'courier',
        'size_label',
        'order_date',
        'picked_at',
        'packed_at',
        'metadata',
    ];

    protected $casts = [
        'quantity_required' => 'decimal:4',
        'quantity_picked' => 'decimal:4',
        'order_date' => 'datetime',
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(ExternalOrderLine::class, 'external_order_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function imageUrl(): ?string
    {
        $linePayload = (array) $this->orderLine?->raw_payload;

        foreach ([
            $this->product?->imageUrl(),
            data_get($linePayload, 'image.src'),
            data_get($linePayload, 'image.url'),
            data_get($linePayload, 'images.0.src'),
            data_get($linePayload, 'images.0.url'),
            data_get($linePayload, 'parent_image.src'),
            data_get($linePayload, 'parent_image.url'),
        ] as $candidate) {
            $url = $this->safeImageUrl($candidate);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public function thumbnailUrl(int $width = 52, int $height = 64): ?string
    {
        return app(ProductImageThumbnailService::class)->thumbnailUrl($this->imageUrl(), $width, $height);
    }

    public function remainingQuantity(): float
    {
        return max(0, (float) $this->quantity_required - (float) $this->quantity_picked);
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
}
