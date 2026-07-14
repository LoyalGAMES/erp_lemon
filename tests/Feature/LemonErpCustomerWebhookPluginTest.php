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

    public function test_product_translation_linker_contract_harness_passes(): void
    {
        $process = new Process([PHP_BINARY, base_path('tools/test-lemon-erp-product-translations.php')]);
        $process->setTimeout(30);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            $process->getErrorOutput().$process->getOutput(),
        );
        $this->assertStringContainsString('product translation linker tests passed', $process->getOutput());
    }

    public function test_product_publication_date_contract_harness_passes(): void
    {
        $process = new Process([PHP_BINARY, base_path('tools/test-lemon-erp-product-publication-date.php')]);
        $process->setTimeout(30);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            $process->getErrorOutput().$process->getOutput(),
        );
        $this->assertStringContainsString('product publication date tests passed', $process->getOutput());
    }

    public function test_downloadable_plugin_contains_integration_modules(): void
    {
        $packages = app(LemonErpWooCommercePluginPackageService::class);
        $package = $packages->build();
        $zip = new ZipArchive;

        $this->assertSame('0.5.0', $package['version']);
        $this->assertTrue($zip->open($package['path']) === true);
        $this->assertNotFalse(
            $zip->locateName('lemon-erp-woocommerce/includes/class-customer-webhook.php'),
        );
        $this->assertNotFalse(
            $zip->locateName('lemon-erp-woocommerce/includes/class-product-translation-linker.php'),
        );
        $this->assertNotFalse(
            $zip->locateName('lemon-erp-woocommerce/includes/class-product-publication-date.php'),
        );
        $mainFile = $zip->getFromName('lemon-erp-woocommerce/lemon-erp-woocommerce.php');
        $this->assertIsString($mainFile);
        $this->assertStringContainsString(
            "'product_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/translations'",
            $mainFile,
        );
        $readme = $zip->getFromName('lemon-erp-woocommerce/README.md');
        $this->assertIsString($readme);
        $this->assertStringContainsString(
            '/wp-json/wc-lemon-erp/v1/catalog/products/translations',
            $readme,
        );
        $zip->close();
    }
}
