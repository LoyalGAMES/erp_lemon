<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(): View
    {
        return view('audit.index', [
            'logs' => AuditLog::query()
                ->latest()
                ->limit(250)
                ->get(),
        ]);
    }
}
