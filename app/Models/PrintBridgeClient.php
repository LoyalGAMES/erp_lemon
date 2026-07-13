<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintBridgeClient extends Model
{
    use HasFactory;

    protected $fillable = [
        'station_code',
        'worker_name',
        'version',
        'printers',
        'printer_error',
        'last_seen_at',
    ];

    protected $casts = [
        'printers' => 'array',
        'last_seen_at' => 'datetime',
    ];
}
