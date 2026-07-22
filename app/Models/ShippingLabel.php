<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Shipping\CourierPickupEvidenceClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingLabel extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_channel_id',
        'external_order_id',
        'wordpress_integration_id',
        'courier_account_id',
        'return_case_id',
        'purpose',
        'idempotency_key',
        'status',
        'provider',
        'label_number',
        'tracking_number',
        'tracking_status',
        'tracking_checked_at',
        'next_tracking_check_at',
        'tracking_attempts',
        'tracking_last_error',
        'picked_up_at',
        'disk',
        'path',
        'mime_type',
        'size',
        'sha256',
        'source_url',
        'response_payload',
        'generated_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'generated_at' => 'datetime',
        'tracking_checked_at' => 'datetime',
        'next_tracking_check_at' => 'datetime',
        'tracking_attempts' => 'integer',
        'picked_up_at' => 'datetime',
    ];

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_id')->withTrashed();
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WordpressIntegration::class, 'wordpress_integration_id');
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }

    public function courierAccount(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function scopeShipments(Builder $query): Builder
    {
        return $query->where('purpose', 'shipment');
    }

    public function trackingIdentifier(): ?string
    {
        $number = trim((string) ($this->tracking_number ?: $this->label_number));

        return $number !== '' ? $number : null;
    }

    public function hasCourierPickupEvidence(): bool
    {
        if (in_array(mb_strtolower(trim((string) $this->status)), ['picked_up', 'delivered'], true)
            || $this->picked_up_at !== null
            || (bool) data_get($this->response_payload, 'tracking.picked_up', false)
            || (bool) data_get($this->response_payload, 'tracking.delivered', false)
            || filled(data_get($this->response_payload, 'tracking.picked_up_at'))
            || filled(data_get($this->response_payload, 'tracking.delivered_at'))) {
            return true;
        }

        $provider = mb_strtolower(trim((string) $this->provider));
        $identifier = trim((string) $this->trackingIdentifier());
        $isInPost = str_contains($provider, 'inpost')
            || ($provider === '' && preg_match('/^\d{20,26}$/', $identifier) === 1);
        $statuses = [
            trim((string) $this->tracking_status),
            trim((string) data_get($this->response_payload, 'tracking.status', '')),
            trim((string) data_get($this->response_payload, 'shipment.status', '')),
        ];

        // The InPost checkpoint contains a ShipX shipment object. BLPaczka's
        // checkpoint is a createOrderV2 response whose technical `status`, if
        // present, is not a tracking event and cannot prove physical pickup.
        if ($isInPost) {
            $statuses[] = trim((string) data_get(
                $this->response_payload,
                'generation.remote_checkpoint.response_payload.status',
                '',
            ));
        }

        $statuses = array_values(array_unique(array_filter(
            $statuses,
            fn (string $status): bool => $status !== '',
        )));

        foreach ($statuses as $status) {
            if (CourierPickupEvidenceClassifier::inPostStatusProvesPickup($status)
                || CourierPickupEvidenceClassifier::blpaczkaStatusProvesPickup($status)) {
                return true;
            }

            // A carrier can add a new status before the ERP is deployed. For
            // reversal safety every unknown non-empty status is shipment
            // evidence unless it is explicitly known to be pre-pickup.
            if (CourierPickupEvidenceClassifier::unknownStatusProvesPickup($status)) {
                return true;
            }
        }

        return false;
    }

    public function filename(): string
    {
        return basename($this->path);
    }
}
