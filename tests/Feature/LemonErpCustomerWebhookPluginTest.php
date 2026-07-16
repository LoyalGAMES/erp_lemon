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

    public function test_historical_storefront_cache_purge_contract_harness_passes(): void
    {
        $process = new Process([PHP_BINARY, base_path('tools/test-lemon-erp-storefront-size-cache-upgrade.php')]);
        $process->setTimeout(30);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            $process->getErrorOutput().$process->getOutput(),
        );
        $this->assertStringContainsString('storefront size cache upgrade tests passed', $process->getOutput());
    }

    public function test_downloadable_plugin_contains_integration_modules(): void
    {
        $packages = app(LemonErpWooCommercePluginPackageService::class);
        $package = $packages->build();
        $zip = new ZipArchive;

        $this->assertSame('0.5.6', $package['version']);
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
        $this->assertNotFalse(
            $zip->locateName('lemon-erp-woocommerce/includes/class-global-attribute-taxonomies.php'),
        );
        $this->assertFalse(
            $zip->locateName('lemon-erp-woocommerce/includes/class-storefront-variation-attributes.php'),
        );
        $this->assertNotFalse(
            $zip->locateName('lemon-erp-woocommerce/includes/class-storefront-size-cache-upgrade.php'),
        );
        $cachePurgeModule = $zip->getFromName(
            'lemon-erp-woocommerce/includes/class-storefront-size-cache-upgrade.php',
        );
        $this->assertIsString($cachePurgeModule);
        $this->assertStringContainsString("add_action('admin_init'", $cachePurgeModule);
        $this->assertStringNotContainsString("add_action('init'", $cachePurgeModule);
        $taxonomyModule = $zip->getFromName(
            'lemon-erp-woocommerce/includes/class-global-attribute-taxonomies.php',
        );
        $this->assertIsString($taxonomyModule);
        $this->assertStringContainsString('TERM_LANGUAGE_BOOTSTRAP_REVISION', $taxonomyModule);
        $this->assertStringContainsString(
            "add_action('init', [self::class, 'bootstrapTermLanguages'], 100);",
            $taxonomyModule,
        );
        $this->assertStringContainsString("'lang' => ''", $taxonomyModule);
        $translationLinker = $zip->getFromName(
            'lemon-erp-woocommerce/includes/class-product-translation-linker.php',
        );
        $this->assertIsString($translationLinker);
        $this->assertStringContainsString(
            "'attribute_term_translation_bootstrap_completed'",
            $translationLinker,
        );
        $this->assertStringContainsString(
            "'attribute_term_translation_unassigned_terms_count'",
            $translationLinker,
        );
        $this->assertStringContainsString(
            '/catalog/products/variations/translations',
            $translationLinker,
        );
        $this->assertStringContainsString(
            "add_filter('wc_product_has_unique_sku'",
            $translationLinker,
        );
        $this->assertStringContainsString(
            "add_filter('wc_product_has_global_unique_id'",
            $translationLinker,
        );
        $mainFile = $zip->getFromName('lemon-erp-woocommerce/lemon-erp-woocommerce.php');
        $this->assertIsString($mainFile);
        $this->assertStringContainsString(
            'Lemon_Erp_Global_Attribute_Taxonomies::register();',
            $mainFile,
        );
        $this->assertStringNotContainsString('Lemon_Erp_Storefront_Variation_Attributes', $mainFile);
        $this->assertStringContainsString(
            '(new Lemon_Erp_Storefront_Size_Cache_Upgrade)->hooks();',
            $mainFile,
        );
        $taxonomyRegistration = strpos($mainFile, 'Lemon_Erp_Global_Attribute_Taxonomies::register();');
        $pluginsLoadedHook = strpos($mainFile, "add_action('plugins_loaded'");
        $this->assertIsInt($taxonomyRegistration);
        $this->assertIsInt($pluginsLoadedHook);
        $this->assertLessThan(
            $pluginsLoadedHook,
            $taxonomyRegistration,
            'The Polylang taxonomy filter must be registered before plugins_loaded.',
        );
        $this->assertStringContainsString(
            "'product_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/translations'",
            $mainFile,
        );
        $this->assertStringContainsString(
            "'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations'",
            $mainFile,
        );
        $readme = $zip->getFromName('lemon-erp-woocommerce/README.md');
        $this->assertIsString($readme);
        $this->assertStringContainsString(
            '/wp-json/wc-lemon-erp/v1/catalog/products/translations',
            $readme,
        );
        $this->assertStringContainsString('Wersja `0.5.6`', $readme);
        $zip->close();
    }
}
