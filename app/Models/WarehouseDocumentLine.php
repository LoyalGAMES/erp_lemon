<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseDocumentLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_document_id',
        'product_id',
        'quantity',
        'unit_net_price',
        'unit_gross_price',
        'source_lot',
        'expiry_date',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_net_price' => 'decimal:4',
        'unit_gross_price' => 'decimal:4',
        'expiry_date' => 'date',
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(WarehouseDocument::class, 'warehouse_document_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class);
    }
}
