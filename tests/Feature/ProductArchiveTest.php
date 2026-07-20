<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\StockSyncQueueItem;
use App\Models\StockSyncState;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retiring a duplicate product must be possible without touching booked
 * warehouse documents: deletion is refused with a clear message (instead of a
 * raw FK 500) and archiving detaches the product from every channel identity.
 */
class ProductArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function channel(): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'physical',
            'is_active' => true,
        ]);
    }

    private function product(string $sku = 'SKU-ARCH'): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Produkt archiwalny',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
    }

    public function test_destroy_refuses_a_product_with_document_history_instead_of_500(): void
    {
        $warehouse = $this->warehouse();
        $product = $this->product();

        // Booking a correction creates a KOR document, its line and a ledger
        // entry — exactly the state after an operator zeroes stock by hand.
        $this->post(route('products.stock.adjust', $product), [
            'warehouse_id' => $warehouse->id,
            'new_quantity' => 5,
            'notes' => 'przyjęcie',
        ])->assertSessionHas('status');
        $this->post(route('products.stock.adjust', $product), [
            'warehouse_id' => $warehouse->id,
            'new_quantity' => 0,
            'notes' => 'zerowanie przed usunięciem',
        ])->assertSessionHas('status');

        $response = $this->delete(route('products.destroy', $product));

        $response->assertRedirect();
        $this->assertStringContainsString('Archiwizuj', (string) session('error'));
        $this->assertNotNull(Product::query()->find($product->id));
    }

    public function test_destroy_still_deletes_a_product_without_history(): void
    {
        $product = $this->product();

        $this->delete(route('products.destroy', $product))
            ->assertSessionHas('status');

        $this->assertNull(Product::query()->find($product->id));
    }

    public function test_archive_detaches_channels_and_hides_the_product(): void
    {
        $channel = $this->channel();
        $warehouse = $this->warehouse();
        $product = $this->product();
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700032',
            'external_variation_id' => '720055',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '750093',
            'external_variation_id' => '770149',
            'external_sku' => $product->sku,
            'language' => 'en',
        ]);
        StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'version' => 1,
            'status' => 'failed',
            'quantity_to_push' => 0,
        ]);
        StockSyncState::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'desired_version' => 1,
            'desired_quantity' => 0,
        ]);

        $this->post(route('products.archive', $product))
            ->assertSessionHas('status');

        $product->refresh();
        $this->assertTrue($product->isArchived());
        $this->assertFalse($product->is_active);
        $this->assertTrue($product->isStorefrontHidden());
        $this->assertSame(0, ProductChannelMapping::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, ProductChannelAlias::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, StockSyncQueueItem::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, StockSyncState::query()->where('product_id', $product->id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'product.archived')->exists());
    }

    public function test_archive_refuses_a_product_with_stock(): void
    {
        $warehouse = $this->warehouse();
        $product = $this->product();
        $this->post(route('products.stock.adjust', $product), [
            'warehouse_id' => $warehouse->id,
            'new_quantity' => 3,
            'notes' => 'przyjęcie',
        ])->assertSessionHas('status');

        $this->post(route('products.archive', $product));

        $this->assertStringContainsString('Wyzeruj stany', (string) session('error'));
        $this->assertFalse($product->fresh()->isArchived());
    }

    public function test_unarchive_restores_the_product_as_inactive(): void
    {
        $product = $this->product();
        $product->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        $this->post(route('products.unarchive', $product))
            ->assertSessionHas('status');

        $product->refresh();
        $this->assertFalse($product->isArchived());
        $this->assertFalse($product->is_active);
    }

    public function test_product_list_hides_archived_by_default_and_shows_them_under_the_filter(): void
    {
        $visible = $this->product('SKU-ARCH-VISIBLE');
        $archived = $this->product('SKU-ARCH-HIDDEN');
        $archived->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        $default = $this->get(route('products.index', ['q' => 'SKU-ARCH']));
        $default->assertSee('SKU-ARCH-VISIBLE');
        $default->assertDontSee('SKU-ARCH-HIDDEN');

        $archiveView = $this->get(route('products.index', ['q' => 'SKU-ARCH', 'status' => 'archived']));
        $archiveView->assertSee('SKU-ARCH-HIDDEN');
        $archiveView->assertDontSee('SKU-ARCH-VISIBLE');
    }

    public function test_hiding_an_orphaned_variant_no_longer_requires_a_woo_parent(): void
    {
        $channel = $this->channel();
        $orphan = $this->product('WC-B2C-VARIANT-720055');
        // Variation mapping without any local parent: the ERP parent row was
        // deleted and its parent mapping cascaded away.
        ProductChannelMapping::query()->create([
            'product_id' => $orphan->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700032',
            'external_variation_id' => '720055',
            'external_sku' => $orphan->sku,
            'stock_sync_enabled' => true,
        ]);

        $this->post(route('products.storefront.hide', $orphan))
            ->assertSessionHas('status');

        $this->assertTrue($orphan->fresh()->isStorefrontHidden());
    }
}
