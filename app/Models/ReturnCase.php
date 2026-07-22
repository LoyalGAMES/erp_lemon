<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReturnCase extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'number',
        'store_return_reference',
        'external_order_id',
        'target_warehouse_id',
        'warehouse_document_id',
        'correction_invoice_id',
        'status',
        'reason',
        'customer_email',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnCaseLine::class);
    }

    public function externalOrder(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class)->withTrashed();
    }

    public function targetWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'target_warehouse_id');
    }

    public function warehouseDocument(): BelongsTo
    {
        return $this->belongsTo(WarehouseDocument::class);
    }

    public function warehouseDocuments(): HasManyThrough
    {
        return $this->hasManyThrough(
            WarehouseDocument::class,
            ReturnCaseLine::class,
            'return_case_id',
            'id',
            'id',
            'warehouse_document_id',
        );
    }

    public function correctionInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'correction_invoice_id');
    }

    public function shippingLabels(): HasMany
    {
        return $this->hasMany(ShippingLabel::class)->latest('generated_at');
    }

    public function customerMessages(): HasMany
    {
        return $this->hasMany(CustomerMessage::class)->latest();
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(InternalNote::class)->latest();
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class)->latest('booked_at')->latest();
    }
}
