<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\PrintJob;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Services\Shipping\BLPaczkaShipmentService;
use App\Services\Shipping\InPostShipmentService;
use App\Services\Shipping\ShippingCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ShippingCancellationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inpost_cancellation_uses_single_delete_and_maps_invalid_action(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/shipments/SHIP-CANCEL')) {
                return Http::response('', 204);
            }

            return Http::response([
                'error' => 'invalid_action',
                'message' => 'Shipment cannot be cancelled in confirmed status.',
            ], 422);
        });

        $account = $this->createCourierAccount('inpost');
        $service = app(InPostShipmentService::class);

        $cancelled = $service->cancelShipment('SHIP-CANCEL', $account);
        $notCancellable = $service->cancelShipment('SHIP-CONFIRMED', $account);

        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame(204, $cancelled['http_status']);
        $this->assertSame('remote_not_cancellable', $notCancellable['status']);
        $this->assertSame(422, $notCancellable['http_status']);
        $this->assertStringContainsString('confirmed', (string) $notCancellable['message']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v1/shipments/SHIP-CANCEL'));
    }

    public function test_blpaczka_cancellation_posts_authenticated_order_id(): void
    {
        Http::fake([
            '*/api/cancelOrder.json' => Http::response([
                'success' => true,
                'message' => 'Przesyłka anulowana.',
            ], 200),
        ]);

        $account = $this->createCourierAccount('blpaczka');
        $result = app(BLPaczkaShipmentService::class)->cancelShipment('445566', $account);

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame('445566', $result['shipment_id']);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/cancelOrder.json')
                && data_get($request->data(), 'auth.login') === 'org-blpaczka'
                && data_get($request->data(), 'auth.api_key') === 'token-blpaczka'
                && data_get($request->data(), 'Order.id') === 445566;
        });
    }

    public function test_it_cancels_remote_and_local_shipping_for_entire_split_family_idempotently(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'DELETE') {
                return Http::response('', 204);
            }

            if (str_ends_with($request->url(), '/api/cancelOrder.json')) {
                return Http::response(['success' => true, 'message' => 'Anulowano'], 200);
            }

            return Http::response([], 500);
        });

        $channel = $this->createSalesChannel();
        $root = $this->createOrder($channel, '1000');
        $inPostChild = $this->createOrder($channel, '1000-SPLIT-1', $root);
        $unknownChild = $this->createOrder($channel, '1000-SPLIT-2', $root);
        $inPostAccount = $this->createCourierAccount('inpost');
        $blpaczkaAccount = $this->createCourierAccount('blpaczka');

        $inPostLabel = $this->createLabel($root, [
            'courier_account_id' => $inPostAccount->id,
            'provider' => 'inpost',
            'label_number' => 'SHIP-1000',
            'response_payload' => ['shipment' => ['status' => 'created']],
        ]);
        $blpaczkaLabel = $this->createLabel($inPostChild, [
            'courier_account_id' => $blpaczkaAccount->id,
            'provider' => 'blpaczka',
            'label_number' => '445566',
        ]);
        $unknownLabel = $this->createLabel($unknownChild, [
            'provider' => 'dpd',
            'label_number' => 'DPD-ABC',
        ]);
        $returnLabel = $this->createLabel($root, [
            'purpose' => 'return',
            'status' => 'picked_up',
            'provider' => 'inpost',
            'label_number' => 'RETURN-1',
            'idempotency_key' => null,
        ]);

        $pending = $this->createPrintJob($inPostLabel, 'pending');
        $reserved = $this->createPrintJob($inPostLabel, 'reserved', [
            'reserved_by' => 'worker-reserved',
            'reserved_station' => 'ST-1',
            'reserved_at' => now(),
            'lease_token' => str_repeat('a', 64),
        ]);
        $printing = $this->createPrintJob($inPostLabel, 'printing', [
            'reserved_by' => 'worker-printing',
            'reserved_station' => 'ST-1',
            'reserved_at' => now(),
            'lease_token' => str_repeat('b', 64),
        ]);
        $failed = $this->createPrintJob($inPostLabel, 'failed', [
            'failed_at' => now(),
            'last_error' => 'drukarka offline',
        ]);
        $printed = $this->createPrintJob($inPostLabel, 'printed', ['printed_at' => now()]);
        $alreadyCancelled = $this->createPrintJob($inPostLabel, 'cancelled');

        $result = app(ShippingCancellationService::class)->cancelForOrder(
            $inPostChild,
            'cancel-operation-123',
            'Klient zrezygnował',
        );

        $this->assertEqualsCanonicalizing(
            [$inPostLabel->id, $blpaczkaLabel->id, $unknownLabel->id],
            $result['cancelled_label_ids'],
        );
        $this->assertEqualsCanonicalizing(
            [$pending->id, $reserved->id, $printing->id, $failed->id],
            $result['cancelled_print_job_ids'],
        );
        $this->assertEqualsCanonicalizing(
            ['unsupported_provider', 'label_already_printed'],
            collect($result['manual_required'])->pluck('code')->all(),
        );

        foreach ([$inPostLabel, $blpaczkaLabel, $unknownLabel] as $label) {
            $this->assertSame('cancelled', $label->refresh()->status);
            $this->assertNull($label->next_tracking_check_at);
            $this->assertSame('cancel-operation-123', data_get($label->response_payload, 'cancellation.operation_uuid'));
            $this->assertSame('Klient zrezygnował', data_get($label->response_payload, 'cancellation.reason'));
        }

        $this->assertSame('cancelled', data_get($inPostLabel->response_payload, 'cancellation.remote.status'));
        $this->assertSame('cancelled', data_get($blpaczkaLabel->response_payload, 'cancellation.remote.status'));
        $this->assertSame('manual_required', data_get($unknownLabel->response_payload, 'cancellation.remote.status'));
        $this->assertSame('picked_up', $returnLabel->refresh()->status);

        foreach ([$pending, $reserved, $printing, $failed] as $job) {
            $job->refresh();
            $this->assertSame('cancelled', $job->status);
            $this->assertNull($job->lease_token);
            $this->assertNull($job->reserved_by);
            $this->assertNull($job->reserved_station);
            $this->assertNull($job->reserved_at);
        }

        $this->assertSame('printed', $printed->refresh()->status);
        $this->assertSame('printed', data_get($printed->metadata, 'shipping_label_cancellation.previous_status'));
        $this->assertSame('cancelled', $alreadyCancelled->refresh()->status);
        $this->assertNull(data_get($alreadyCancelled->metadata, 'shipping_label_cancellation'));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v1/shipments/SHIP-1000'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/cancelOrder.json')
            && data_get($request->data(), 'Order.id') === 445566);

        $firstPrintedAudit = $printed->metadata['shipping_label_cancellation'];
        $second = app(ShippingCancellationService::class)->cancelForOrder(
            $root->fresh(),
            'cancel-operation-123',
            'Klient zrezygnował',
        );

        $this->assertSame([], $second['cancelled_label_ids']);
        $this->assertSame([], $second['cancelled_print_job_ids']);
        $this->assertSame($firstPrintedAudit, $printed->refresh()->metadata['shipping_label_cancellation']);
        Http::assertSentCount(2);
    }

    public function test_confirmed_inpost_is_voided_locally_with_manual_warning_without_remote_request(): void
    {
        Http::preventStrayRequests();

        $channel = $this->createSalesChannel();
        $order = $this->createOrder($channel, '2000');
        $account = $this->createCourierAccount('inpost');
        $label = $this->createLabel($order, [
            'courier_account_id' => $account->id,
            'provider' => 'inpost',
            'label_number' => 'SHIP-CONFIRMED',
            'response_payload' => ['shipment' => ['status' => 'confirmed']],
            'next_tracking_check_at' => now()->addMinute(),
        ]);

        $service = app(ShippingCancellationService::class);
        $result = $service->cancelForOrder($order);

        $this->assertSame([$label->id], $result['cancelled_label_ids']);
        $this->assertSame('remote_not_cancellable', $result['manual_required'][0]['code']);
        $this->assertSame('cancelled', $label->refresh()->status);
        $this->assertNull($label->next_tracking_check_at);
        $this->assertSame('confirmed', data_get($label->response_payload, 'cancellation.remote.remote_status'));

        $retried = $service->cancelForOrder($order->fresh());

        $this->assertSame([], $retried['cancelled_label_ids']);
        $this->assertSame('remote_not_cancellable', $retried['manual_required'][0]['code']);
        $this->assertSame($label->id, $retried['manual_required'][0]['label_id']);
        $this->assertStringContainsString('nie może już zostać anulowana', $retried['manual_required'][0]['message']);
        Http::assertNothingSent();
    }

    public function test_ambiguous_provider_failure_requires_manual_confirmation_without_reposting(): void
    {
        Http::fake([
            '*/api/cancelOrder.json' => Http::response(['success' => false, 'message' => 'Spróbuj ponownie'], 503),
        ]);

        $channel = $this->createSalesChannel();
        $order = $this->createOrder($channel, '2500');
        $account = $this->createCourierAccount('blpaczka');
        $label = $this->createLabel($order, [
            'courier_account_id' => $account->id,
            'provider' => 'blpaczka',
            'label_number' => '778899',
        ]);
        $printJob = $this->createPrintJob($label, 'printing', [
            'reserved_by' => 'bridge-1',
            'reserved_station' => 'ST-1',
            'reserved_at' => now(),
            'lease_token' => str_repeat('c', 64),
        ]);

        $result = app(ShippingCancellationService::class)->cancelForOrder($order, 'retryable-operation');

        $this->assertSame('remote_outcome_unknown', $result['manual_required'][0]['code']);
        $this->assertSame('cancelled', $label->refresh()->status);
        $this->assertStringContainsString(
            'HTTP 503',
            (string) data_get($label->response_payload, 'cancellation.remote.remote_error'),
        );
        $this->assertSame('cancelled', $printJob->refresh()->status);
        $this->assertNull($printJob->lease_token);
        $this->assertSame(
            'retryable-operation',
            data_get($printJob->metadata, 'shipping_label_cancellation.operation_uuid'),
        );

        $second = app(ShippingCancellationService::class)->cancelForOrder($order, 'retryable-operation');

        $this->assertSame('remote_outcome_unknown', $second['manual_required'][0]['code']);
        Http::assertSentCount(1);
    }

    public function test_historical_locally_cancelled_label_without_remote_proof_requires_manual_confirmation(): void
    {
        Http::preventStrayRequests();

        $channel = $this->createSalesChannel();
        $order = $this->createOrder($channel, '2600');
        $label = $this->createLabel($order, [
            'status' => 'cancelled',
            'provider' => 'inpost',
            'label_number' => 'SHIP-UNVERIFIED',
            'response_payload' => [],
        ]);

        $result = app(ShippingCancellationService::class)->cancelForOrder($order);

        $this->assertSame([], $result['cancelled_label_ids']);
        $this->assertSame('remote_cancellation_unverified', $result['manual_required'][0]['code']);
        $this->assertSame($label->id, $result['manual_required'][0]['label_id']);
        Http::assertNothingSent();
    }

    public function test_historical_locally_cancelled_label_with_unknown_remote_status_requires_manual_confirmation(): void
    {
        Http::preventStrayRequests();

        $channel = $this->createSalesChannel();
        $order = $this->createOrder($channel, '2700');
        $label = $this->createLabel($order, [
            'status' => 'cancelled',
            'provider' => 'inpost',
            'label_number' => 'SHIP-UNKNOWN',
            'response_payload' => [
                'cancellation' => [
                    'remote' => ['status' => 'unknown'],
                ],
            ],
        ]);

        $result = app(ShippingCancellationService::class)->cancelForOrder($order);

        $this->assertSame('remote_cancellation_unverified', $result['manual_required'][0]['code']);
        $this->assertSame($label->id, $result['manual_required'][0]['label_id']);
        Http::assertNothingSent();
    }

    public function test_historical_locally_cancelled_label_with_remote_proof_is_safe(): void
    {
        Http::preventStrayRequests();

        $channel = $this->createSalesChannel();
        $order = $this->createOrder($channel, '2800');
        $this->createLabel($order, [
            'status' => 'cancelled',
            'provider' => 'inpost',
            'label_number' => 'SHIP-VERIFIED',
            'response_payload' => [
                'cancellation' => [
                    'remote' => ['status' => 'cancelled'],
                ],
            ],
        ]);

        $result = app(ShippingCancellationService::class)->cancelForOrder($order);

        $this->assertSame([], $result['cancelled_label_ids']);
        $this->assertSame([], $result['manual_required']);
        Http::assertNothingSent();
    }

    public function test_picked_up_label_in_any_family_member_blocks_every_mutation(): void
    {
        Http::preventStrayRequests();

        $channel = $this->createSalesChannel();
        $root = $this->createOrder($channel, '3000');
        $child = $this->createOrder($channel, '3000-SPLIT-1', $root);
        $rootLabel = $this->createLabel($root, [
            'provider' => 'dpd',
            'label_number' => 'DPD-3000',
        ]);
        $rootPrintJob = $this->createPrintJob($rootLabel, 'pending');
        $pickedUpLabel = $this->createLabel($child, [
            'status' => 'picked_up',
            'provider' => 'inpost',
            'label_number' => 'SHIP-PICKED-UP',
            'picked_up_at' => now(),
        ]);

        try {
            app(ShippingCancellationService::class)->cancelForOrder($root);
            $this->fail('Oczekiwano blokady anulowania odebranej przesyłki.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('odebrana', $exception->getMessage());
        }

        $this->assertSame('generated', $rootLabel->refresh()->status);
        $this->assertSame('pending', $rootPrintJob->refresh()->status);
        $this->assertSame('picked_up', $pickedUpLabel->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_carrier_pickup_tracking_evidence_blocks_cancellation_even_if_local_label_status_is_stale(): void
    {
        Http::preventStrayRequests();

        $channel = $this->createSalesChannel();
        $order = $this->createOrder($channel, '3100');
        $label = $this->createLabel($order, [
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'SHIP-STALE-LOCAL-STATUS',
            'tracking_status' => 'collected_from_sender',
            'picked_up_at' => null,
        ]);

        try {
            app(ShippingCancellationService::class)->cancelForOrder($order);
            $this->fail('Oczekiwano blokady po potwierdzonym odbiorze przewoźnika.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('odebrana', $exception->getMessage());
        }

        $this->assertSame('generated', $label->fresh()->status);
        Http::assertNothingSent();
    }

    private function createSalesChannel(): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => 'B2C-CANCEL-'.str()->lower(str()->random(6)),
            'name' => 'Sklep anulacje',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    private function createOrder(
        SalesChannel $channel,
        string $externalId,
        ?ExternalOrder $root = null,
    ): ExternalOrder {
        return ExternalOrder::query()->create([
            'split_parent_order_id' => $root?->id,
            'split_root_order_id' => $root?->id,
            'sales_channel_id' => $channel->id,
            'external_id' => $externalId,
            'external_number' => $externalId,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createLabel(ExternalOrder $order, array $attributes = []): ShippingLabel
    {
        return ShippingLabel::query()->create(array_merge([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:order:'.$order->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'SHIP-'.$order->id,
            'disk' => 'local',
            'path' => 'shipping-labels/test-'.$order->id.'.zpl',
            'mime_type' => 'application/zpl',
            'next_tracking_check_at' => now()->addMinutes(5),
            'generated_at' => now(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function createPrintJob(ShippingLabel $label, string $status, array $attributes = []): PrintJob
    {
        return PrintJob::query()->create(array_merge([
            'shipping_label_id' => $label->id,
            'deduplication_key' => hash('sha256', $label->id.'-'.$status.'-'.str()->random(8)),
            'status' => $status,
            'printer_name' => 'Zebra 1',
            'format' => 'zpl',
            'attempts' => 0,
        ], $attributes));
    }

    private function createCourierAccount(string $provider): CourierAccount
    {
        $account = new CourierAccount([
            'provider' => $provider,
            'code' => 'account-'.$provider,
            'name' => 'Konto '.$provider,
            'organization_id' => 'org-'.$provider,
            'is_default' => true,
            'is_active' => true,
        ]);
        $account->setApiToken('token-'.$provider);
        $account->save();

        return $account;
    }
}
