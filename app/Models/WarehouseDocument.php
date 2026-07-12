<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarehouseDocument extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'number',
        'type',
        'status',
        'source_warehouse_id',
        'destination_warehouse_id',
        'created_by',
        'posted_by',
        'cancelled_by',
        'document_date',
        'posted_at',
        'cancelled_at',
        'external_reference',
        'order_fulfillment_key',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'document_date' => 'datetime',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseDocumentLine::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class);
    }
}
