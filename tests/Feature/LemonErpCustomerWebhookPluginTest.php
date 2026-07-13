<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Wordpress\LemonErpWooCommercePluginPackageService;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class LemonErpCustomerWebhookPluginTest extends TestCase
{
    public function test_customer_webhook_contract_harness_passes(): void
    {
        $process = new Process([PHP_BINARY, base_path('tools/test-lemon-erp-customer-webhook.php')]);
        $process->setTimeout(30);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            $process->getErrorOutput().$process->getOutput(),
        );
        $this->assertStringContainsString('customer webhook tests passed', $process->getOutput());
    }

    public function test_downloadable_plugin_contains_customer_webhook_module(): void
    {
        $packages = app(LemonErpWooCommercePluginPackageService::class);
        $package = $packages->build();
        $zip = new ZipArchive;

        $this->assertSame('0.4.0', $package['version']);
        $this->assertTrue($zip->open($package['path']) === true);
        $this->assertNotFalse(
            $zip->locateName('lemon-erp-woocommerce/includes/class-customer-webhook.php'),
        );
        $zip->close();
    }
}
