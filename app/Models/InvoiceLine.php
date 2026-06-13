<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'product_id',
        'name',
        'sku',
        'unit',
        'quantity',
        'unit_net_price',
        'net_total',
        'vat_rate',
        'vat_total',
        'gross_total',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_net_price' => 'decimal:4',
        'net_total' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_total' => 'decimal:2',
        'gross_total' => 'decimal:2',
        'metadata' => 'array',
    ];
}

