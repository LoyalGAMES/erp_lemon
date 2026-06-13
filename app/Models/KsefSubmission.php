<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KsefSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'environment',
        'api_version',
        'status',
        'reference_number',
        'ksef_number',
        'xml_payload',
        'request_metadata',
        'response_metadata',
        'last_error',
        'submitted_at',
        'accepted_at',
    ];

    protected $casts = [
        'request_metadata' => 'array',
        'response_metadata' => 'array',
        'submitted_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
