<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Gs1\Gs1SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Gs1IntegrationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_store_gs1_configuration_with_encrypted_password(): void
    {
        $this->put(route('integrations.gs1.configuration.update'), [
            'base_url' => 'https://mojegs1.pl/api/v2/index.html',
            'username' => 'gs1-api-user',
            'password' => 'gs1-secret-password',
            'company_prefix' => '5901234',
            'next_item_reference' => '1',
            'default_gpc_code' => '10000002',
            'gpc_options' => "67060000 | Stroje kąpielowe\n10001350 | Żakiety/marynarki/kardigany/kamizelki\n10001352 | Koszule/bluzki/koszulki polo/T-shirt\n10005106 | Środowiskowe środki ochrony dróg oddechowych - bez zasilania\n10008067 | Strój kąpielowy - jednoczęściowy",
            'target_market' => 'PL',
            'register_products' => '1',
        ])->assertRedirect()
            ->assertSessionHas('status', 'Konfiguracja GS1 została zapisana.');

        $setting = AppSetting::query()->where('key', 'gs1_configuration')->firstOrFail();

        $this->assertSame('https://mojegs1.pl/api/v2', $setting->value['base_url']);
        $this->assertSame('gs1-api-user', $setting->value['username']);
        $this->assertSame('5901234', $setting->value['company_prefix']);
        $this->assertNotSame('gs1-secret-password', $setting->value['password_encrypted']);
        $this->assertSame('gs1-secret-password', Crypt::decryptString($setting->value['password_encrypted']));
        $this->assertSame('Żakiety/marynarki/kardigany/kamizelki', collect($setting->value['gpc_options'])->firstWhere('code', '10001350')['label']);
        $this->assertSame('Środowiskowe środki ochrony dróg oddechowych - bez zasilania', collect($setting->value['gpc_options'])->firstWhere('code', '10005106')['label']);
        $this->assertSame('Strój kąpielowy - jednoczęściowy', collect($setting->value['gpc_options'])->firstWhere('code', '10008067')['label']);

        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('Konto GS1')
            ->assertSee('Gotowe do generowania EAN')
            ->assertSee('Żakiety/marynarki/kardigany/kamizelki')
            ->assertSee('Środowiskowe środki ochrony dróg oddechowych - bez zasilania')
            ->assertSee('Zapisz konto GS1')
            ->assertDontSee('gs1-secret-password');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'gs1.configuration_updated',
        ]);
    }

    public function test_gs1_configuration_exposes_default_apparel_gpc_options_and_repairs_truncated_labels(): void
    {
        AppSetting::query()->create([
            'key' => 'gs1_configuration',
            'value' => [
                'base_url' => 'https://mojegs1.pl',
                'username' => 'gs1-api-user',
                'password_encrypted' => Crypt::encryptString('gs1-secret-password'),
                'company_prefix' => '5901234',
                'next_item_reference' => 1,
                'default_gpc_code' => null,
                'gpc_options' => [
                    [
                        'code' => '10001350',
                        'label' => 'Ż',
                        'description' => 'Opis użytkownika zostaje zachowany.',
                    ],
                    [
                        'code' => '99999999',
                        'label' => 'Własny kod testowy',
                        'description' => '',
                    ],
                ],
                'target_market' => 'PL',
                'register_products' => true,
            ],
        ]);

        $configuration = app(Gs1SettingsService::class)->publicConfiguration();
        $options = collect($configuration['gpc_options']);

        $this->assertSame('https://mojegs1.pl/api/v2', $configuration['base_url']);
        $this->assertSame('Żakiety/marynarki/kardigany/kamizelki', $options->firstWhere('code', '10001350')['label']);
        $this->assertSame('Opis użytkownika zostaje zachowany.', $options->firstWhere('code', '10001350')['description']);
        $this->assertSame('Koszule/bluzki/koszulki polo/T-shirt', $options->firstWhere('code', '10001352')['label']);
        $this->assertSame('Środowiskowe środki ochrony dróg oddechowych - bez zasilania', $options->firstWhere('code', '10005106')['label']);
        $this->assertSame('Własny kod testowy', $options->firstWhere('code', '99999999')['label']);
    }

    public function test_product_can_generate_gs1_ean_and_register_in_mojegs1(): void
    {
        AppSetting::query()->create([
            'key' => 'gs1_configuration',
            'value' => [
                'base_url' => 'https://mojegs1.pl',
                'username' => 'gs1-api-user',
                'password_encrypted' => Crypt::encryptString('gs1-secret-password'),
                'company_prefix' => '5901234',
                'next_item_reference' => 1,
                'default_gpc_code' => null,
                'gpc_options' => [
                    [
                        'code' => '10008067',
                        'label' => 'Strój kąpielowy - jednoczęściowy',
                        'description' => 'Jednoczęściowy strój kąpielowy.',
                    ],
                    [
                        'code' => '10008068',
                        'label' => 'Strój kąpielowy - dwuczęściowy',
                        'description' => 'Dwuczęściowy strój kąpielowy.',
                    ],
                ],
                'target_market' => 'PL',
                'register_products' => true,
            ],
        ]);

        Http::fake([
            'https://mojegs1.pl/api/v2/products/5901234000017' => Http::response([
                'result' => 'OK',
                'qualityDetails' => [
                    'suggestions' => [],
                ],
            ]),
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-GS1',
            'name' => 'Koszula GS1 Test',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'producer' => 'SEMPRE',
                    'content' => [
                        'pl' => [
                            'name' => 'Koszula GS1 Test',
                            'description' => '<p>Opis produktu dla GS1</p>',
                        ],
                    ],
                    'media' => [
                        [
                            'src' => '/uploads/products/1/gs1.jpg',
                            'alt' => 'Koszula GS1',
                            'name' => 'gs1.jpg',
                        ],
                    ],
                ],
            ],
        ]);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Wygeneruj EAN GS1')
            ->assertSee('Strój kąpielowy - jednoczęściowy');

        $this->post(route('products.gs1.ean.generate', $product), [
            'gpc_code' => '10008068',
            'gpc_label' => 'Strój kąpielowy - dwuczęściowy',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'EAN 5901234000017 został wygenerowany i zapisany w MojeGS1.');

        $product->refresh();
        $this->assertSame('5901234000017', $product->ean);
        $this->assertSame('10008068', data_get($product->masterData(), 'gs1.gpc_code'));
        $this->assertSame('Strój kąpielowy - dwuczęściowy', data_get($product->masterData(), 'gs1.gpc_label'));
        $this->assertSame(2, AppSetting::query()->where('key', 'gs1_configuration')->firstOrFail()->value['next_item_reference']);
        $this->assertSame(1, AuditLog::query()->where('action', 'product.gs1_ean_generated')->count());

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://mojegs1.pl/api/v2/products/5901234000017'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('gs1-api-user:gs1-secret-password'))
            && $request['data']['id'] === '5901234000017'
            && $request['data']['attributes']['brandName'] === 'SEMPRE'
            && $request['data']['attributes']['internalSymbol'] === 'SKU-GS1'
            && ! array_key_exists('productWebsite', $request['data']['attributes'])
            && $request['data']['attributes']['gpcCode'] === 10008068.0);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('5901234000017')
            ->assertDontSee('Wygeneruj EAN GS1');
    }

    public function test_gs1_authorization_failure_shows_actionable_message(): void
    {
        AppSetting::query()->create([
            'key' => 'gs1_configuration',
            'value' => [
                'base_url' => 'https://mojegs1.pl',
                'username' => 'wrong-user',
                'password_encrypted' => Crypt::encryptString('wrong-password'),
                'company_prefix' => '5901234',
                'next_item_reference' => 1,
                'default_gpc_code' => null,
                'target_market' => 'PL',
                'register_products' => true,
            ],
        ]);

        Http::fake([
            'https://mojegs1.pl/api/v2/products/5901234000017' => Http::response([
                'error' => [
                    'status' => 401,
                    'title' => 'Błąd autoryzacji',
                ],
            ], 401),
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-GS1-401',
            'name' => 'Produkt GS1 401',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->post(route('products.gs1.ean.generate', $product), [
            'gpc_code' => '10008068',
            'gpc_label' => 'Strój kąpielowy - dwuczęściowy',
        ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'MojeGS1 zwróciło 401')
                && str_contains($message, 'Zmień dane api'));

        $product->refresh();
        $this->assertNull($product->ean);
        $this->assertSame(1, AuditLog::query()->where('action', 'product.gs1_ean_failed')->count());
    }

    public function test_product_save_generates_sku_and_ean_from_category_gs1_mapping(): void
    {
        AppSetting::query()->create([
            'key' => 'gs1_configuration',
            'value' => [
                'company_prefix' => '5901234',
                'next_item_reference' => 1,
                'register_products' => false,
            ],
        ]);
        $category = ProductCategory::query()->create([
            'external_id' => 'ERP-KOSZULE',
            'name' => 'Koszule',
            'path' => 'Odzież > Koszule',
            'gs1_gpc_code' => '10001352',
            'gs1_gpc_label' => 'Koszule/bluzki/koszulki polo/T-shirt',
        ]);

        $this->post(route('products.store'), [
            'name' => 'Produkt bez identyfikatorów',
            'sku' => '',
            'ean' => '',
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'category_ids' => [$category->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product = Product::query()->where('name', 'Produkt bez identyfikatorów')->firstOrFail();
        $this->assertSame('SEM-'.str_pad((string) $product->id, 8, '0', STR_PAD_LEFT), $product->sku);
        $this->assertSame('5901234000017', $product->ean);
        $this->assertSame([$category->id], data_get($product->masterData(), 'category_ids'));
        $this->assertSame('10001352', data_get($product->masterData(), 'gs1.gpc_code'));
    }

    public function test_operator_can_test_gs1_connection_from_integrations(): void
    {
        AppSetting::query()->create([
            'key' => 'gs1_configuration',
            'value' => [
                'base_url' => 'https://mojegs1.pl/api/v2/index.html',
                'username' => 'gs1-api-user',
                'password_encrypted' => Crypt::encryptString('gs1-secret-password'),
                'company_prefix' => '5901234',
                'next_item_reference' => 1,
                'target_market' => 'PL',
                'register_products' => true,
            ],
        ]);

        Http::fake([
            'https://mojegs1.pl/api/v2/localizations*' => Http::response([
                'data' => [
                    'items' => [],
                    'total' => 0,
                ],
            ]),
        ]);

        $this->post(route('integrations.gs1.test'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Połączenie GS1 działa. Dane API są poprawne.');

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://mojegs1.pl/api/v2/localizations')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('gs1-api-user:gs1-secret-password')));

        $this->assertSame(1, AuditLog::query()->where('action', 'gs1.connection_succeeded')->count());
    }
}
