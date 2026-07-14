<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\OrderCancellation;
use App\Models\PackingTask;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippingLabelDownloadGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_shipping_label_can_be_downloaded_and_is_linked_on_order_page(): void
    {
        Storage::fake('local');

        $order = $this->order();
        $label = $this->label($order);
        $url = route('packing.labels.download', $label);

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($url, false);
    }

    public function test_cancelled_shipping_label_cannot_be_downloaded_and_has_no_link(): void
    {
        Storage::fake('local');

        $order = $this->order();
        $label = $this->label($order, ['status' => 'cancelled']);
        $url = route('packing.labels.download', $label);

        $this->get($url)->assertNotFound();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee($url, false);
    }

    public function test_packing_history_does_not_link_a_cancelled_shipping_label(): void
    {
        Storage::fake('local');

        $order = $this->order();
        $label = $this->label($order, ['status' => 'cancelled']);
        $url = route('packing.labels.download', $label);
        PackingTask::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'order_number' => $order->external_number,
            'customer_name' => 'Anna Testowa',
            'sku' => 'SKU-LABEL-GUARD',
            'product_name' => 'Produkt testowy',
            'quantity_required' => 1,
            'quantity_picked' => 1,
            'status' => 'shipped',
            'courier' => 'Kurier',
            'order_date' => now(),
            'picked_at' => now(),
            'packed_at' => now(),
        ]);

        $this->get(route('packing.index', ['view' => 'shipped']))
            ->assertOk()
            ->assertDontSee($url, false);
    }

    public function test_cancelled_order_cannot_download_an_active_label_and_has_no_link(): void
    {
        Storage::fake('local');

        $order = $this->order(['status' => 'cancelled']);
        $label = $this->label($order);
        $url = route('packing.labels.download', $label);

        $this->get($url)->assertNotFound();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee($url, false);
    }

    public function test_family_cancellation_blocks_child_label_but_rejected_cancellation_does_not(): void
    {
        Storage::fake('local');

        $root = $this->order();
        $child = $this->order([
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
        ]);
        $label = $this->label($child);
        $url = route('packing.labels.download', $label);
        $cancellation = OrderCancellation::query()->create([
            'uuid' => (string) Str::uuid(),
            'external_order_id' => $root->id,
            'status' => 'rejected',
            'reason' => 'Odrzucona próba anulowania',
            'refund_status' => 'pending',
            'currency' => 'PLN',
        ]);

        $this->get($url)->assertOk();
        $this->get(route('orders.show', $child))
            ->assertOk()
            ->assertSee($url, false);

        $cancellation->update(['status' => 'requested']);

        $this->get($url)->assertNotFound();
        $this->get(route('orders.show', $child))
            ->assertOk()
            ->assertDontSee($url, false);

        $cancellation->update(['status' => 'completed']);

        $this->get($url)->assertNotFound();
    }

    /** @param array<string, mixed> $attributes */
    private function order(array $attributes = []): ExternalOrder
    {
        $channel = SalesChannel::query()->firstOrCreate(
            ['code' => 'LABEL-GUARD'],
            [
                'name' => 'Test blokady etykiet',
                'type' => 'woocommerce',
                'is_active' => true,
            ],
        );
        $sequence = ExternalOrder::query()->count() + 1;

        return ExternalOrder::query()->create(array_merge([
            'sales_channel_id' => $channel->id,
            'external_id' => 'label-guard-'.$sequence,
            'external_number' => 'LABEL-'.$sequence,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'external_created_at' => now(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function label(ExternalOrder $order, array $attributes = []): ShippingLabel
    {
        $path = 'shipping-labels/order-'.$order->id.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 guarded label');

        return ShippingLabel::query()->create(array_merge([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'purpose' => 'shipment',
            'status' => 'generated',
            'provider' => 'woocommerce',
            'label_number' => 'LABEL-'.$order->id,
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'generated_at' => now(),
        ], $attributes));
    }
}
