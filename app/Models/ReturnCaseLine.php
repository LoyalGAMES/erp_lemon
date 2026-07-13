<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnCaseLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_case_id',
        'product_id',
        'external_order_line_id',
        'canonical_external_line_id',
        'quantity_expected',
        'quantity_accepted',
        'condition',
        'disposition',
        'target_warehouse_id',
        'warehouse_document_id',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity_expected' => 'decimal:4',
        'quantity_accepted' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function targetWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'target_warehouse_id');
    }

    public function warehouseDocument(): BelongsTo
    {
        return $this->belongsTo(WarehouseDocument::class);
    }

    public function externalOrderLine(): BelongsTo
    {
        return $this->belongsTo(ExternalOrderLine::class);
    }
}
