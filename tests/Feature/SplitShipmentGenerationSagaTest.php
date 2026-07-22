<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SplitShipmentGenerationSagaTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_inpost_retry_fetches_recorded_shipment_instead_of_creating_second_cod(): void
    {
        Storage::fake('local');
        $postCount = 0;
        $labelFetchCount = 0;
        $postedCod = null;

        Http::fake(function ($request) use (&$postCount, &$labelFetchCount, &$postedCod) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && str_ends_with($path, '/v1/organizations/111/shipments')) {
                $postCount++;
                $postedCod = (float) data_get($request->data(), 'cod.amount');

                return Http::response(['id' => 'SHIP-SPLIT-1', 'status' => 'created'], 201);
            }

            if (str_ends_with($path, '/v1/shipments/SHIP-SPLIT-1/label')) {
                $labelFetchCount++;

                return $labelFetchCount === 1
                    ? Http::response(['message' => 'label temporarily unavailable'], 503)
                    : Http::response('^XA^FO20,20^FDSPLIT^FS^XZ', 200, ['Content-Type' => 'text/plain']);
            }

            if (str_ends_with($path, '/v1/shipments/SHIP-SPLIT-1')) {
                return Http::response([
                    'id' => 'SHIP-SPLIT-1',
                    'status' => 'confirmed',
                    'tracking_number' => '520000111111111111111111',
                ]);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $order = $this->createSplitOrder(75.25, 'InPost Paczkomat');
        $account = $this->createInPostAccount();

        $firstFailure = $this->captureRuntimeException(
            fn () => app(ShippingLabelService::class)->generateForOrder($order, $account),
        );

        $this->assertStringContainsString('Identyfikator przesyłki został bezpiecznie zapisany', $firstFailure->getMessage());

        $attempt = ShippingLabel::query()->firstOrFail();
        $this->assertSame('generating', $attempt->status);
        $this->assertSame('SHIP-SPLIT-1', $attempt->label_number);
        $this->assertSame('shipment:order:'.$order->id, $attempt->idempotency_key);
        $this->assertSame('remote_shipment_resolved', data_get($attempt->response_payload, 'generation.state'));
        $this->assertTrue((bool) data_get($attempt->response_payload, 'generation.remote_force_new'));
        $this->assertFalse((bool) data_get($attempt->response_payload, 'generation.local_force_new'));

        $label = app(ShippingLabelService::class)->generateForOrder($order->fresh(), $account);

        $this->assertSame($attempt->id, $label->id);
        $this->assertSame('generated', $label->status);
        $this->assertSame('SHIP-SPLIT-1', $label->label_number);
        $this->assertSame(75.25, $postedCod);
        $this->assertSame(75.25, (float) data_get($label->response_payload, 'financial.requested_cod_amount'));
        $this->assertSame('completed', data_get($label->response_payload, 'generation.state'));
        $this->assertSame(1, $postCount);
        $this->assertSame(2, $labelFetchCount);
        $this->assertSame(1, ShippingLabel::query()->count());
    }

    public function test_force_new_blpaczka_retry_resumes_recorded_shipment_before_starting_another_attempt(): void
    {
        Storage::fake('local');
        $valuationCount = 0;
        $createCount = 0;
        $labelFetchCount = 0;
        $postedCod = null;

        Http::fake(function ($request) use (&$valuationCount, &$createCount, &$labelFetchCount, &$postedCod) {
            if (str_contains($request->url(), 'getValuation.json')) {
                $valuationCount++;

                return Http::response([
                    'success' => true,
                    'data' => ['results' => [[
                        'Courier' => ['name' => 'Kurier DPD', 'courier_code' => 'dpd_classic'],
                        'Price' => ['value' => '14.50'],
                    ]]],
                ]);
            }

            if (str_contains($request->url(), 'createOrderV2.json')) {
                $createCount++;
                $postedCod = (float) data_get($request->data(), 'CourierSearch.uptake');

                return Http::response([
                    'success' => true,
                    'data' => ['blpaczka_order_id' => 778899],
                ]);
            }

            if (str_contains($request->url(), 'getWaybill.json')) {
                $labelFetchCount++;

                if ($labelFetchCount === 1) {
                    return Http::response(['success' => false, 'message' => 'Etykieta jeszcze niegotowa']);
                }

                return Http::response([
                    'success' => true,
                    'data' => [[
                        'filename' => 'dpd-split.pdf',
                        'mime' => 'application/pdf',
                        'content' => base64_encode('%PDF-1.4 split-label'),
                    ]],
                ]);
            }

            if (str_contains($request->url(), 'getOrderDetails.json')) {
                return Http::response([
                    'success' => true,
                    'data' => ['Order' => ['waybill_number' => '9988776655']],
                ]);
            }

            return Http::response(['success' => false, 'message' => 'unexpected request'], 500);
        });

        $order = $this->createSplitOrder(62.50, 'Kurier DPD (BLPaczka)');
        $account = $this->createBLPaczkaAccount();

        $firstFailure = $this->captureRuntimeException(
            fn () => app(ShippingLabelService::class)->generateForOrder($order, $account, forceNew: true),
        );

        $this->assertStringContainsString('Identyfikator przesyłki został bezpiecznie zapisany', $firstFailure->getMessage());

        $attempt = ShippingLabel::query()->firstOrFail();
        $this->assertSame('generating', $attempt->status);
        $this->assertSame('778899', $attempt->label_number);
        $this->assertStringStartsWith('shipment:order:'.$order->id.':', (string) $attempt->idempotency_key);

        $label = app(ShippingLabelService::class)->generateForOrder(
            $order->fresh(),
            $account,
            forceNew: true,
        );

        $this->assertSame($attempt->id, $label->id);
        $this->assertSame('generated', $label->status);
        $this->assertSame('778899', $label->label_number);
        $this->assertSame('dpd_classic', data_get($label->response_payload, 'blpaczka.courier_code'));
        $this->assertSame(62.5, $postedCod);
        $this->assertSame(1, $valuationCount);
        $this->assertSame(1, $createCount);
        $this->assertSame(2, $labelFetchCount);
        $this->assertSame(1, ShippingLabel::query()->count());
    }

    public function test_unknown_post_outcome_is_not_automatically_retried_even_with_force_new(): void
    {
        Storage::fake('local');
        $postCount = 0;

        Http::fake(function ($request) use (&$postCount) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && str_ends_with($path, '/v1/organizations/111/shipments')) {
                $postCount++;

                throw new ConnectionException('Połączenie zerwane po wysłaniu żądania');
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $order = $this->createSplitOrder(41.99, 'InPost Kurier');
        $account = $this->createInPostAccount();

        $firstFailure = $this->captureRuntimeException(
            fn () => app(ShippingLabelService::class)->generateForOrder($order, $account),
        );

        $this->assertStringContainsString('wynik jest nieznany', $firstFailure->getMessage());

        $attempt = ShippingLabel::query()->firstOrFail();
        $this->assertSame('generating', $attempt->status);
        $this->assertNull($attempt->label_number);
        $this->assertSame('outcome_unknown', data_get($attempt->response_payload, 'generation.state'));

        $secondFailure = $this->captureRuntimeException(
            fn () => app(ShippingLabelService::class)->generateForOrder(
                $order->fresh(),
                $account,
                forceNew: true,
            ),
        );

        $this->assertStringContainsString('ERP nie wyśle automatycznie drugiego COD', $secondFailure->getMessage());
        $this->assertSame(1, $postCount);
        $this->assertSame(1, ShippingLabel::query()->count());
    }

    public function test_inpost_server_error_after_creation_post_is_not_automatically_retried(): void
    {
        Storage::fake('local');
        $postCount = 0;

        Http::fake(function ($request) use (&$postCount) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && str_ends_with($path, '/v1/organizations/111/shipments')) {
                $postCount++;

                return Http::response(['message' => 'upstream timeout after submit'], 503);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $order = $this->createSplitOrder(44.90, 'InPost Kurier');
        $account = $this->createInPostAccount();

        $firstFailure = $this->captureRuntimeException(
            fn () => app(ShippingLabelService::class)->generateForOrder($order, $account),
        );
        $this->assertStringContainsString('wynik jest nieznany', $firstFailure->getMessage());
        $this->assertSame(
            'outcome_unknown',
            data_get(ShippingLabel::query()->firstOrFail()->response_payload, 'generation.state'),
        );

        $secondFailure = $this->captureRuntimeException(
            fn () => app(ShippingLabelService::class)->generateForOrder(
                $order->fresh(),
                $account,
                forceNew: true,
            ),
        );

        $this->assertStringContainsString('ERP nie wyśle automatycznie drugiego COD', $secondFailure->getMessage());
        $this->assertSame(1, $postCount);
        $this->assertSame(1, ShippingLabel::query()->count());
    }

    public function test_blpaczka_server_error_after_creation_post_is_not_automatically_retried(): void
    {
        Storage::fake('local');
        $valuationCount = 0;
        $createCount = 0;

        Http::fake(function ($request) use (&$valuationCount, &$createCount) {
            if (str_contains($request->url(), 'getValuation.json')) {
                $valuationCount++;

                return Http::response([
                    'success' => true,
                    'data' => ['results' => [[
                        'Courier' => ['name' => 'Kurier DPD', 'courier_code' => 'dpd_classic'],
                        'Price' => ['value' => '14.50'],
                    ]]],
                ]);
            }

            if (str_contains($request->url(), 'createOrderV2.json')) {
                $createCount++;

                return Http::response(['message' => 'upstream timeout after submit'], 503);
            }

            return Http::response(['success' => false, 'message' => 'unexpected request'], 500);
        });

        $order = $this->createSplitOrder(59.90, 'Kurier DPD (BLPaczka)');
        $account = $this->createBLPaczkaAccount();

        $firstFailure = $this->captureRuntimeException(
            fn () => app(ShippingLabelService::class)->generateForOrder(
                $order,
                $account,
                forceNew: true,
            ),
        );
        $this->assertStringContainsString('wynik jest nieznany', $firstFailure->getMessage());
        $this->assertSame(
            'outcome_unknown',
            data_get(ShippingLabel::query()->firstOrFail()->response_payload, 'generation.state'),
        );

        $secondFailure = $this->captureRuntimeException(
            fn () => app(ShippingLabelService::class)->generateForOrder(
                $order->fresh(),
                $account,
                forceNew: true,
            ),
        );

        $this->assertStringContainsString('ERP nie wyśle automatycznie drugiego COD', $secondFailure->getMessage());
        $this->assertSame(1, $valuationCount);
        $this->assertSame(1, $createCount);
        $this->assertSame(1, ShippingLabel::query()->count());
    }

    public function test_generation_is_blocked_while_split_reversal_saga_is_in_progress(): void
    {
        Http::fake();
        $order = $this->createSplitOrder(39.99, 'InPost Paczkomat');
        $root = $order->splitRoot()->firstOrFail();
        $payload = (array) $root->raw_payload;
        $payload['sempre_erp_split_reversal_operation'] = [
            'uuid' => 'reversal-in-progress-1',
            'state' => 'shipping_cancelled',
        ];
        $root->update(['raw_payload' => $payload]);

        $failure = $this->captureRuntimeException(
            fn () => app(ShippingLabelService::class)->generateForOrder(
                $order->fresh(),
                $this->createInPostAccount(),
                forceNew: true,
            ),
        );

        $this->assertStringContainsString('dokończone cofnięcie podziału', $failure->getMessage());
        $this->assertSame(0, ShippingLabel::query()->count());
        Http::assertNothingSent();
    }

    private function createSplitOrder(float $total, string $shippingMethod): ExternalOrder
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $root = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'ROOT-100',
            'external_number' => '100',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 200,
            'raw_payload' => ['sempre_erp_split_allocations' => [['order_id' => 'child']]],
        ]);

        return ExternalOrder::query()->create([
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
            'sales_channel_id' => $channel->id,
            'external_id' => 'SPLIT-101',
            'external_number' => '100-S1',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => $total,
            'billing_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Klient',
                'email' => 'jan@example.test',
                'phone' => '+48500600700',
            ],
            'shipping_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Klient',
                'address_1' => 'ul. Krzywa 2',
                'postcode' => '30-002',
                'city' => 'Kraków',
                'country' => 'PL',
                'phone' => '+48500600700',
            ],
            'raw_payload' => [
                'payment_method' => 'cod',
                'payment_method_title' => 'Płatność przy odbiorze',
                'shipping_lines' => [['method_title' => $shippingMethod]],
            ],
        ]);
    }

    private function createInPostAccount(): CourierAccount
    {
        $account = new CourierAccount([
            'provider' => 'inpost',
            'code' => 'inpost-main',
            'name' => 'InPost główny',
            'organization_id' => '111',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => true,
            'is_active' => true,
        ]);
        $account->setApiToken('inpost-token');
        $account->save();

        return $account;
    }

    private function createBLPaczkaAccount(): CourierAccount
    {
        $account = new CourierAccount([
            'provider' => 'blpaczka',
            'code' => 'blp-main',
            'name' => 'BLPaczka główna',
            'organization_id' => 'login@example.test',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => true,
            'is_active' => true,
            'metadata' => [
                'sender' => [
                    'name' => 'Sempre Sp. z o.o.',
                    'street' => 'Magazynowa',
                    'house_no' => '5',
                    'postal' => '30-001',
                    'city' => 'Kraków',
                    'phone' => '48123456789',
                    'email' => 'magazyn@example.test',
                ],
                'parcel' => ['weight' => 2, 'side_x' => 40, 'side_y' => 30, 'side_z' => 15],
                'payment' => 'bank',
            ],
        ]);
        $account->setApiToken('blp-token');
        $account->save();

        return $account;
    }

    private function captureRuntimeException(callable $operation): RuntimeException
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            return $exception;
        }

        $this->fail('Oczekiwano wyjątku RuntimeException.');
    }
}
