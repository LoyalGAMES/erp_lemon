<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'number',
        'type',
        'status',
        'external_order_id',
        'invoice_template_id',
        'issue_date',
        'sale_date',
        'payment_due_date',
        'currency',
        'seller_data',
        'buyer_data',
        'net_total',
        'vat_total',
        'gross_total',
        'payment_method',
        'ksef_number',
        'issued_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'sale_date' => 'date',
        'payment_due_date' => 'date',
        'seller_data' => 'array',
        'buyer_data' => 'array',
        'net_total' => 'decimal:2',
        'vat_total' => 'decimal:2',
        'gross_total' => 'decimal:2',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(InvoiceFile::class);
    }

    public function ksefSubmissions(): HasMany
    {
        return $this->hasMany(KsefSubmission::class);
    }

    public function invoiceTemplate(): BelongsTo
    {
        return $this->belongsTo(InvoiceTemplate::class);
    }

    public function correctedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_invoice_id');
    }

    public function externalOrder(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class)->withTrashed();
    }

    protected function correctedInvoiceId(): Attribute
    {
        return Attribute::get(fn (): mixed => data_get($this->metadata, 'corrected_invoice_id'));
    }
}
