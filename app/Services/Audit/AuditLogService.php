<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

final class AuditLogService
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $metadata
     */
    public function record(
        string $action,
        ?Model $auditable = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
    ): AuditLog {
        $request = $this->request();

        return AuditLog::query()->create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    private function request(): ?Request
    {
        try {
            return app()->bound('request') ? request() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
