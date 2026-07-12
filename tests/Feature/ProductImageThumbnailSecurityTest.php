<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Products\ProductImageThumbnailService;
use Tests\TestCase;

final class ProductImageThumbnailSecurityTest extends TestCase
{
    protected bool $authenticateByDefault = false;

    public function test_browser_side_sources_cannot_bypass_the_remote_source_guard(): void
    {
        $thumbnails = app(ProductImageThumbnailService::class);

        $this->assertNull($thumbnails->thumbnailUrl('//127.0.0.1/private.jpg', 116, 144));
        $this->assertNull($thumbnails->thumbnailUrl('/\\127.0.0.1/private.jpg', 116, 144));
        $this->assertNull($thumbnails->thumbnailUrl('\\\\127.0.0.1/private.jpg', 116, 144));
        $this->assertNull($thumbnails->thumbnailUrl('javascript:alert(1)', 116, 144));
        $this->assertNull($thumbnails->thumbnailUrl('data:image/svg+xml,<svg onload=alert(1)>', 116, 144));
    }

    public function test_safe_local_upload_path_and_public_remote_url_remain_supported(): void
    {
        $thumbnails = app(ProductImageThumbnailService::class);

        $this->assertSame('/uploads/missing-but-safe.jpg', $thumbnails->thumbnailUrl('/uploads/missing-but-safe.jpg', 116, 144));
        $this->assertStringStartsWith(
            '/products/image-thumbnail?src=',
            (string) $thumbnails->thumbnailUrl('HTTPS://1.1.1.1/image.jpg', 116, 144),
        );
    }
}
