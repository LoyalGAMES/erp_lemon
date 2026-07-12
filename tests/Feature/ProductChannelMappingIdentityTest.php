<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductChannelMappingIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_two_simple_product_mappings_for_the_same_woo_identity(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'WooCommerce B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $firstProduct = $this->product('MAP-ONE');
        $secondProduct = $this->product('MAP-TWO');

        $first = ProductChannelMapping::query()->create([
            'product_id' => $firstProduct->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => ' 7001 ',
            'external_variation_id' => null,
        ]);

        $this->assertSame('7001', $first->external_product_id);
        $this->assertSame(
            ProductChannelMapping::externalIdentityKey('7001'),
            $first->external_identity_key,
        );

        try {
            ProductChannelMapping::query()->create([
                'product_id' => $secondProduct->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '7001',
                'external_variation_id' => null,
            ]);
            $this->fail('Baza zaakceptowała drugie mapowanie tego samego prostego produktu WooCommerce.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, ProductChannelMapping::query()->count());
        }
    }

    public function test_identity_key_is_refreshed_when_mapping_changes_from_parent_to_variation(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'WooCommerce B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $this->product('MAP-VARIANT')->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '7001',
            'external_variation_id' => null,
        ]);
        $parentKey = $mapping->external_identity_key;

        $mapping->update(['external_variation_id' => ' 888 ']);

        $this->assertSame('888', $mapping->external_variation_id);
        $this->assertNotSame($parentKey, $mapping->external_identity_key);
        $this->assertSame(
            ProductChannelMapping::externalIdentityKey('7001', '888'),
            $mapping->external_identity_key,
        );
    }

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
    }
}
