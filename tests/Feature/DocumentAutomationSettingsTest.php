<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Automation\DocumentAutomationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentAutomationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_automation_settings_are_saved_as_event_action_rules(): void
    {
        $this->get(route('settings.documents'))
            ->assertOk()
            ->assertSee('Automatyczny obieg dokumentów')
            ->assertSee('Po dodaniu zwrotu')
            ->assertSee('Po spakowaniu zamówienia')
            ->assertSee('Wystaw i wyślij fakturę');

        $this->put(route('settings.document_automation.update'), [
            'automation' => [
                'return.created' => [
                    'return.rx.create' => '1',
                    'return.rx.post' => '1',
                ],
                'warehouse_document.rx.posted' => [
                    'return.correction.create' => '1',
                ],
                'packing.order.packed' => [
                    'order.wz.create_if_missing' => '1',
                    'order.wz.post' => '1',
                ],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $settings = app(DocumentAutomationSettingsService::class);

        $this->assertTrue($settings->actionEnabled('return.created', 'return.rx.create'));
        $this->assertTrue($settings->actionEnabled('return.created', 'return.rx.post'));
        $this->assertTrue($settings->actionEnabled('warehouse_document.rx.posted', 'return.correction.create'));
        $this->assertTrue($settings->actionEnabled('packing.order.packed', 'order.wz.create_if_missing'));
        $this->assertTrue($settings->actionEnabled('packing.order.packed', 'order.wz.post'));
        $this->assertFalse($settings->actionEnabled('packing.order.packed', 'order.invoice.create_upload'));
        $this->assertFalse($settings->actionEnabled('packing.courier.picked_up', 'order.invoice.create_upload'));

        $data = $settings->data();

        $this->assertTrue($data['return_create_rx_on_store']);
        $this->assertTrue($data['return_post_rx_on_store']);
        $this->assertTrue($data['return_issue_correction_after_rx_posted']);
        $this->assertTrue($data['packing_create_wz_if_missing_on_pack']);
        $this->assertTrue($data['packing_post_wz_on_pack']);
        $this->assertFalse($data['packing_issue_invoice_on_pack']);
        $this->assertFalse($data['courier_issue_invoice_on_pickup']);
    }
}
