<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Products\ProductDescriptionSanitizer;
use PHPUnit\Framework\TestCase;

final class ProductDescriptionSanitizerTest extends TestCase
{
    private ProductDescriptionSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new ProductDescriptionSanitizer;
    }

    public function test_it_removes_executable_markup_and_event_handlers(): void
    {
        $result = $this->sanitizer->sanitize(
            '<p onclick="alert(1)">Opis <strong>produktu</strong></p>'
            .'<img src="https://cdn.example.test/image.jpg" onerror="alert(2)">'
            .'<script>alert(3)</script><svg onload="alert(4)"><circle /></svg>',
        );

        $this->assertSame(
            '<p>Opis <strong>produktu</strong></p><img src="https://cdn.example.test/image.jpg">',
            $result,
        );
    }

    public function test_it_rejects_dangerous_urls_and_secures_blank_targets(): void
    {
        $result = $this->sanitizer->sanitize(
            '<a href="jav&#x61;script:alert(1)">Zły</a>'
            .'<a href="https://example.test/path" target="_blank">Dobry</a>'
            .'<img src="data:image/svg+xml;base64,PHN2Zz4=">',
        );

        $this->assertSame(
            '<a>Zły</a><a href="https://example.test/path" target="_blank" rel="noopener noreferrer">Dobry</a><img>',
            $result,
        );
    }

    public function test_it_keeps_supported_product_formatting(): void
    {
        $result = $this->sanitizer->sanitize(
            '<h2 class="title">Opis</h2><table><tr><th scope="col">Rozmiar</th><td colspan="2">M</td></tr></table>',
        );

        $this->assertSame(
            '<h2 class="title">Opis</h2><table><tr><th scope="col">Rozmiar</th><td colspan="2">M</td></tr></table>',
            $result,
        );
    }

    public function test_it_neutralizes_parser_mutation_and_namespace_payloads_idempotently(): void
    {
        $payloads = [
            '<svg><g/onload=alert(1)//<p>safe</p></svg>',
            '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=alert(1)>">',
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            '</div><img src=x onerror=alert(1)><div>',
            '<xmp><img src=x onerror=alert(1)></xmp>',
            '<a href="jav&amp;#x61;script:alert(1)">link</a>',
        ];

        foreach ($payloads as $payload) {
            $result = $this->sanitizer->sanitize($payload);
            $normalized = strtolower((string) $result);

            $this->assertStringNotContainsString('<script', $normalized);
            $this->assertStringNotContainsString('<svg', $normalized);
            $this->assertStringNotContainsString('<math', $normalized);
            $this->assertStringNotContainsString('onerror=', $normalized);
            $this->assertStringNotContainsString('onload=', $normalized);
            $this->assertStringNotContainsString('javascript:', $normalized);
            $this->assertSame($result, $this->sanitizer->sanitize($result));
        }
    }
}
