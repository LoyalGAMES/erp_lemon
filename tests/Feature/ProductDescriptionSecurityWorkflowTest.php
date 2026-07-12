<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductDescriptionSecurityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_write_boundary_sanitizes_rich_html_before_it_reaches_storage_or_the_editor(): void
    {
        $this->post(route('products.store'), [
            'sku' => 'SECURITY-XSS-1',
            'name' => 'Bezpieczny opis',
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => '1',
            'description_pl' => '<p onclick="alert(1)">Opis <strong>produktu</strong></p>'
                .'<img src=x onerror="alert(2)"><a href="jav&#x61;script:alert(3)">link</a>'
                .'<script>alert(4)</script><svg onload="alert(5)"><circle /></svg>',
            'description_en' => '<math><mtext><table><mglyph><style><!--</style>'
                .'<img title="--><img src=x onerror=alert(6)>">',
        ])->assertRedirect();

        $product = Product::query()->where('sku', 'SECURITY-XSS-1')->firstOrFail();

        $this->assertSame(
            '<p>Opis <strong>produktu</strong></p><img><a>link</a>',
            data_get($product->attributes, 'master.content.pl.description'),
        );
        $this->assertNull(data_get($product->attributes, 'master.content.en.description'));

        $response = $this->get(route('products.edit', $product))->assertOk();
        $response->assertDontSee('onclick="alert(1)"', false);
        $response->assertDontSee('onerror="alert(2)"', false);
        $response->assertDontSee('javascript:alert(3)', false);
        $response->assertDontSee('alert(4)', false);
        $response->assertDontSee('alert(5)', false);
        $response->assertDontSee('alert(6)', false);
    }
}
