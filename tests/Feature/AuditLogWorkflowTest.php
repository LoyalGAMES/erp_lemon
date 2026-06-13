<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\WarehouseDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_page_lists_recent_events(): void
    {
        $document = WarehouseDocument::query()->create([
            'number' => 'PZ/2026/000001',
            'type' => 'PZ',
            'status' => 'posted',
            'document_date' => now(),
        ]);

        AuditLog::query()->create([
            'action' => 'warehouse_document.posted',
            'auditable_type' => WarehouseDocument::class,
            'auditable_id' => $document->id,
            'before' => ['status' => 'draft'],
            'after' => ['status' => 'posted'],
            'metadata' => ['ledger_entry_ids' => [1]],
            'ip_address' => '127.0.0.1',
        ]);

        $this->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Audyt operacji')
            ->assertSee('warehouse_document.posted')
            ->assertSee('WarehouseDocument')
            ->assertSee('127.0.0.1');
    }
}
