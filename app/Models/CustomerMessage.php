<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Communication\EmailTemplateRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMessage extends Model
{
    protected $fillable = [
        'customer_id',
        'external_order_id',
        'return_case_id',
        'direction',
        'type',
        'trigger',
        'status',
        'recipient_email',
        'recipient_name',
        'subject',
        'body',
        'sent_at',
        'failed_at',
        'error_message',
        'metadata',
        'delivery_snapshot',
        'rendered_html_snapshot',
        'rendered_text_snapshot',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
        'delivery_snapshot' => 'array',
    ];

    public function externalOrder(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }

    public function renderedSubject(): string
    {
        return app(EmailTemplateRenderer::class)->render($this->subject, (array) $this->metadata);
    }

    public function renderedBody(): string
    {
        return app(EmailTemplateRenderer::class)->render($this->body, (array) $this->metadata);
    }
}
