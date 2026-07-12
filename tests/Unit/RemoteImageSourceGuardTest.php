<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Products\RemoteImageSourceGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemoteImageSourceGuardTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function test_it_rejects_internal_or_unsupported_targets(string $url): void
    {
        $this->assertNull((new RemoteImageSourceGuard)->target($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'loopback v4' => ['http://127.0.0.1/image.jpg'];
        yield 'loopback v6' => ['http://[::1]/image.jpg'];
        yield 'private class a' => ['https://10.0.0.8/image.jpg'];
        yield 'private class b' => ['https://172.16.4.2/image.jpg'];
        yield 'private class c' => ['https://192.168.1.2/image.jpg'];
        yield 'carrier grade nat' => ['https://100.64.0.1/image.jpg'];
        yield 'link local metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'protocol assignments' => ['https://192.0.0.1/image.jpg'];
        yield 'documentation v4' => ['https://192.0.2.1/image.jpg'];
        yield 'benchmarking' => ['https://198.18.0.1/image.jpg'];
        yield 'multicast' => ['https://224.0.0.1/image.jpg'];
        yield 'documentation v6' => ['https://[2001:db8::1]/image.jpg'];
        yield 'nat64 private destination' => ['https://[64:ff9b::7f00:1]/image.jpg'];
        yield 'six to four private destination' => ['https://[2002:7f00:1::1]/image.jpg'];
        yield 'local file' => ['file:///etc/passwd'];
        yield 'credentials in URL' => ['https://user:password@1.1.1.1/image.jpg'];
        yield 'non-web port' => ['https://1.1.1.1:8443/image.jpg'];
    }

    public function test_it_accepts_and_normalizes_a_public_https_target(): void
    {
        $this->assertSame([
            'url' => 'https://1.1.1.1/image.jpg?size=200',
            'host' => '1.1.1.1',
            'port' => 443,
            'address' => '1.1.1.1',
        ], (new RemoteImageSourceGuard)->target('HTTPS://1.1.1.1/image.jpg?size=200#ignored'));
    }

    public function test_it_rejects_ipv4_mapped_loopback(): void
    {
        $this->assertFalse((new RemoteImageSourceGuard)->isPublicIp('::ffff:127.0.0.1'));
        $this->assertFalse((new RemoteImageSourceGuard)->isPublicIp('::ffff:7f00:1'));
        $this->assertFalse((new RemoteImageSourceGuard)->isPublicIp('::127.0.0.1'));
    }

    public function test_it_accepts_public_ipv4_and_ipv6_addresses(): void
    {
        $guard = new RemoteImageSourceGuard;

        $this->assertTrue($guard->isPublicIp('1.1.1.1'));
        $this->assertTrue($guard->isPublicIp('2001:4860:4860::8888'));
    }
}
