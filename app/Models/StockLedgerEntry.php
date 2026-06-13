<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_document_id',
        'warehouse_document_line_id',
        'warehouse_id',
        'product_id',
        'quantity_change',
        'direction',
        'posted_at',
        'metadata',
    ];

    protected $casts = [
        'quantity_change' => 'decimal:4',
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(WarehouseDocument::class, 'warehouse_document_id');
    }

    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(WarehouseDocumentLine::class, 'warehouse_document_line_id');
    }
}
