<?php
/**
 * Plugin Name: Lemon ERP for WooCommerce
 * Description: Adds Lemon ERP checkout fields, catalog identity and invoice metadata endpoints for WooCommerce.
 * Version: 0.5.7
 * Author: Lemon ERP
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Update URI: false
 * Text Domain: lemon-erp-woocommerce
 */

declare(strict_types=1);
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__.'/includes/class-global-attribute-taxonomies.php';
Lemon_Erp_Global_Attribute_Taxonomies::register();

require_once __DIR__.'/includes/class-polylang-product-meta.php';
Lemon_Erp_Polylang_Product_Meta::register();

require_once __DIR__.'/includes/class-customer-webhook.php';
require_once __DIR__.'/includes/class-product-publication-date.php';
require_once __DIR__.'/includes/class-product-translation-linker.php';
require_once __DIR__.'/includes/class-storefront-size-cache-upgrade.php';

register_activation_hook(
    __FILE__,
    [Lemon_Erp_Storefront_Size_Cache_Upgrade::class, 'maybeUpgrade'],
);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

final class Lemon_Erp_WooCommerce
{
    private const VERSION = '0.5.7';

    private const CATALOG_CONTRACT = 1;

    private const CUSTOMER_TYPE_META = '_lemon_erp_customer_type';

    private const BILLING_NIP_META = '_lemon_erp_billing_nip';

    private const LEGACY_BILLING_NIP_META = '_billing_nip';

    private const BLOCK_CUSTOMER_TYPE_FIELD = 'lemon-erp/customer-type';

    private const BLOCK_COMPANY_FIELD = 'lemon-erp/company-name';

    private const BLOCK_NIP_FIELD = 'lemon-erp/nip';

    private const INVOICE_PREFIX = '_sempre_erp_invoice_';

    private const CORRECTION_PREFIX = '_sempre_erp_correction_invoice_';

    private const MAX_PDF_BYTES = 15728640;

    private static ?self $instance = null;

    /** @var array<string, array{language:?string,translations:array<string, int>,translation_group:string}> */
    private array $postCatalogIdentityCache = [];

    /** @var array<int, array{language:?string,translations:array<string, int>,translation_group:string}> */
    private array $termCatalogIdentityCache = [];

    /** @var array<int, list<WC_Product_Variation>> */
    private array $variationCandidatesByParent = [];

    public static function boot(): void
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }
    }

    private function __construct()
    {
        (new Lemon_Erp_Customer_Webhook)->hooks();
        (new Lemon_Erp_Product_Publication_Date)->hooks();
        (new Lemon_Erp_Product_Translation_Linker)->hooks();
        (new Lemon_Erp_Storefront_Size_Cache_Upgrade)->hooks();
        add_filter('woocommerce_checkout_fields', [$this, 'classicCheckoutFields']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueCheckoutUi']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateClassicCheckout'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'saveClassicCheckoutFields'], 20, 2);
        add_action('woocommerce_init', [$this, 'registerBlockCheckoutFields']);
        add_filter('woocommerce_get_default_value_for_'.self::BLOCK_CUSTOMER_TYPE_FIELD, [$this, 'defaultBlockCustomerType'], 10, 3);
        add_filter('woocommerce_get_default_value_for_'.self::BLOCK_COMPANY_FIELD, [$this, 'defaultBlockCompanyName'], 10, 3);
        add_filter('woocommerce_get_default_value_for_'.self::BLOCK_NIP_FIELD, [$this, 'defaultBlockNip'], 10, 3);
        add_action('woocommerce_blocks_validate_location_address_fields', [$this, 'validateBlockAddressFields'], 10, 3);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'normalizeBlockCheckoutFields'], 20, 2);
        add_action('add_meta_boxes', [$this, 'addOrderMetaBox'], 20, 2);
        add_action('woocommerce_process_shop_order_meta', [$this, 'saveAdminOrderFields'], 20, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'renderAdminBillingFields']);
        add_action('woocommerce_order_details_after_order_table', [$this, 'renderCustomerInvoiceLink']);
        add_action('woocommerce_email_after_order_table', [$this, 'renderEmailInvoiceLink'], 20, 4);
        add_filter('woocommerce_rest_prepare_product_object', [$this, 'appendProductCatalogContract'], 20, 3);
        add_filter('woocommerce_rest_prepare_product_variation_object', [$this, 'appendVariationCatalogContract'], 20, 3);
        add_filter('woocommerce_rest_prepare_product_cat', [$this, 'appendCategoryCatalogContract'], 20, 3);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function classicCheckoutFields(array $fields): array
    {
        $fields['billing']['billing_company'] = wp_parse_args(
            $fields['billing']['billing_company'] ?? [],
            [
                'type' => 'text',
                'label' => __('Nazwa firmy', 'lemon-erp-woocommerce'),
                'required' => false,
                'class' => ['form-row-wide'],
                'autocomplete' => 'organization',
            ],
        );
        $fields['billing']['billing_company']['label'] = __('Nazwa firmy', 'lemon-erp-woocommerce');
        $fields['billing']['billing_company']['required'] = false;
        $fields['billing']['billing_company']['class'] = ['form-row-wide'];
        $fields['billing']['billing_company']['priority'] = 2;

        $fields['billing']['billing_lemon_customer_type'] = [
            'type' => 'select',
            'label' => __('Kupuję jako', 'lemon-erp-woocommerce'),
            'required' => true,
            'class' => ['form-row-wide'],
            'priority' => 1,
            'default' => 'private',
            'options' => [
                'private' => __('Osoba prywatna', 'lemon-erp-woocommerce'),
                'company' => __('Firma', 'lemon-erp-woocommerce'),
            ],
        ];

        $fields['billing']['billing_nip'] = [
            'type' => 'text',
            'label' => __('NIP', 'lemon-erp-woocommerce'),
            'required' => false,
            'class' => ['form-row-wide'],
            'priority' => 3,
            'placeholder' => __('NIP dla faktury firmowej', 'lemon-erp-woocommerce'),
        ];

        return $fields;
    }

    public function enqueueCheckoutUi(): void
    {
        if (
            ! function_exists('is_checkout')
            || ! is_checkout()
            || (function_exists('is_order_received_page') && is_order_received_page())
        ) {
            return;
        }

        wp_register_script('lemon-erp-woocommerce-checkout', false, [], self::VERSION, true);
        wp_enqueue_script('lemon-erp-woocommerce-checkout');
        wp_add_inline_script('lemon-erp-woocommerce-checkout', $this->checkoutInlineScript());

        wp_register_style('lemon-erp-woocommerce-checkout', false, [], self::VERSION);
        wp_enqueue_style('lemon-erp-woocommerce-checkout');
        wp_add_inline_style('lemon-erp-woocommerce-checkout', $this->checkoutInlineStyle());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validateClassicCheckout(array $data, WP_Error $errors): void
    {
        $customerType = $this->sanitizeCustomerType($data['billing_lemon_customer_type'] ?? 'private');
        $nip = $this->sanitizeNip($data['billing_nip'] ?? '');

        if ($customerType === 'company') {
            if (trim((string) ($data['billing_company'] ?? '')) === '') {
                $errors->add('lemon_erp_company_required', __('Podaj nazwę firmy do faktury.', 'lemon-erp-woocommerce'));
            }

            if ($nip === '') {
                $errors->add('lemon_erp_nip_required', __('Podaj NIP do faktury firmowej.', 'lemon-erp-woocommerce'));
            }
        }

        if ($nip !== '' && ! preg_match('/^[0-9\-\sA-Za-z]{6,32}$/', $nip)) {
            $errors->add('lemon_erp_nip_invalid', __('NIP ma nieprawidłowy format.', 'lemon-erp-woocommerce'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveClassicCheckoutFields(WC_Order $order, array $data): void
    {
        $customerType = $this->sanitizeCustomerType($data['billing_lemon_customer_type'] ?? 'private');

        if ($customerType === 'private') {
            $order->set_billing_company('');
        }

        $this->saveBillingClassification(
            $order,
            $customerType,
            $customerType === 'company' ? $this->sanitizeNip($data['billing_nip'] ?? '') : '',
        );
    }

    public function registerBlockCheckoutFields(): void
    {
        if (! function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id' => self::BLOCK_CUSTOMER_TYPE_FIELD,
            'label' => __('Kupuję jako', 'lemon-erp-woocommerce'),
            'location' => 'address',
            'type' => 'select',
            'required' => true,
            'options' => [
                [
                    'value' => 'private',
                    'label' => __('Osoba prywatna', 'lemon-erp-woocommerce'),
                ],
                [
                    'value' => 'company',
                    'label' => __('Firma', 'lemon-erp-woocommerce'),
                ],
            ],
            'sanitize_callback' => fn ($value): string => $this->sanitizeCustomerType($value),
        ]);

        woocommerce_register_additional_checkout_field([
            'id' => self::BLOCK_COMPANY_FIELD,
            'label' => __('Nazwa firmy', 'lemon-erp-woocommerce'),
            'optionalLabel' => __('Nazwa firmy', 'lemon-erp-woocommerce'),
            'location' => 'address',
            'type' => 'text',
            'required' => $this->companyBlockCondition(),
            'hidden' => $this->privateBlockCondition(),
            'sanitize_callback' => fn ($value): string => sanitize_text_field((string) $value),
        ]);

        woocommerce_register_additional_checkout_field([
            'id' => self::BLOCK_NIP_FIELD,
            'label' => __('NIP', 'lemon-erp-woocommerce'),
            'optionalLabel' => __('NIP', 'lemon-erp-woocommerce'),
            'location' => 'address',
            'type' => 'text',
            'required' => $this->companyBlockCondition(),
            'hidden' => $this->privateBlockCondition(),
            'attributes' => [
                'autocomplete' => 'off',
                'pattern' => '[0-9\\-\\sA-Za-z]{6,32}',
                'title' => __('Podaj NIP do faktury firmowej.', 'lemon-erp-woocommerce'),
            ],
            'sanitize_callback' => fn ($value): string => $this->sanitizeNip($value),
        ]);
    }

    public function defaultBlockCustomerType(mixed $value, string $group, mixed $object): string
    {
        if (is_string($value) && $value !== '') {
            return $this->sanitizeCustomerType($value);
        }

        return $object instanceof WC_Order ? $this->customerType($object) : 'private';
    }

    public function defaultBlockCompanyName(mixed $value, string $group, mixed $object): string
    {
        if (is_string($value) && trim($value) !== '') {
            return sanitize_text_field($value);
        }

        if ($object instanceof WC_Order) {
            return $group === 'shipping' ? $object->get_shipping_company() : $object->get_billing_company();
        }

        return '';
    }

    public function defaultBlockNip(mixed $value, string $group, mixed $object): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $this->sanitizeNip($value);
        }

        return $object instanceof WC_Order ? $this->billingNip($object) : '';
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function validateBlockAddressFields(WP_Error $errors, array $fields, string $group): void
    {
        if (! in_array($group, ['billing', 'shipping'], true)) {
            return;
        }

        $customerType = $this->sanitizeCustomerType($fields[self::BLOCK_CUSTOMER_TYPE_FIELD] ?? 'private');
        $companyName = trim((string) ($fields[self::BLOCK_COMPANY_FIELD] ?? ''));
        $nip = $this->sanitizeNip($fields[self::BLOCK_NIP_FIELD] ?? '');

        if ($customerType !== 'company') {
            return;
        }

        if ($companyName === '') {
            $errors->add('lemon_erp_company_required_'.$group, __('Podaj nazwę firmy do faktury.', 'lemon-erp-woocommerce'));
        }

        if ($nip === '') {
            $errors->add('lemon_erp_nip_required_'.$group, __('Podaj NIP do faktury firmowej.', 'lemon-erp-woocommerce'));
        } elseif (! preg_match('/^[0-9\-\sA-Za-z]{6,32}$/', $nip)) {
            $errors->add('lemon_erp_nip_invalid_'.$group, __('NIP ma nieprawidłowy format.', 'lemon-erp-woocommerce'));
        }
    }

    public function normalizeBlockCheckoutFields(WC_Order $order, WP_REST_Request $request): void
    {
        $customerType = $this->addressCheckoutFieldValue($order, self::BLOCK_CUSTOMER_TYPE_FIELD);
        $companyName = $this->addressCheckoutFieldValue($order, self::BLOCK_COMPANY_FIELD);
        $nip = $this->addressCheckoutFieldValue($order, self::BLOCK_NIP_FIELD);

        $customerType = $this->sanitizeCustomerType($customerType ?: 'private');

        if ($customerType === 'company' && $companyName !== '') {
            $order->set_billing_company($companyName);

            if ($order->get_shipping_company() === '') {
                $order->set_shipping_company($companyName);
            }
        }

        if ($customerType === 'private') {
            $order->set_billing_company('');
            $nip = '';
        }

        if ($customerType !== '' || $nip !== '' || $companyName !== '') {
            $this->saveBillingClassification(
                $order,
                $customerType,
                $customerType === 'company' ? $this->sanitizeNip($nip) : '',
            );
        }
    }

    public function addOrderMetaBox(string $screen, mixed $object): void
    {
        if (! in_array($screen, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }

        add_meta_box(
            'lemon_erp_invoice_box',
            __('Lemon ERP', 'lemon-erp-woocommerce'),
            [$this, 'renderOrderMetaBox'],
            $screen,
            'side',
            'high',
        );
    }

    public function renderOrderMetaBox(mixed $object): void
    {
        $order = $this->orderFromAdminObject($object);

        if (! $order instanceof WC_Order) {
            echo esc_html__('Nie znaleziono zamówienia.', 'lemon-erp-woocommerce');

            return;
        }

        $customerType = $this->customerType($order);
        $nip = $this->billingNip($order);

        wp_nonce_field('lemon_erp_save_order_fields', 'lemon_erp_nonce');
        ?>
        <p>
            <label for="lemon_erp_customer_type"><strong><?php echo esc_html__('Typ klienta', 'lemon-erp-woocommerce'); ?></strong></label>
            <select id="lemon_erp_customer_type" name="lemon_erp_customer_type" style="width:100%;">
                <option value="private" <?php selected($customerType, 'private'); ?>><?php echo esc_html__('Osoba prywatna', 'lemon-erp-woocommerce'); ?></option>
                <option value="company" <?php selected($customerType, 'company'); ?>><?php echo esc_html__('Firma', 'lemon-erp-woocommerce'); ?></option>
            </select>
        </p>
        <p>
            <label for="lemon_erp_billing_nip"><strong><?php echo esc_html__('NIP', 'lemon-erp-woocommerce'); ?></strong></label>
            <input id="lemon_erp_billing_nip" name="lemon_erp_billing_nip" type="text" value="<?php echo esc_attr($nip); ?>" style="width:100%;">
        </p>
        <hr>
        <?php
        $this->renderInvoiceSummary($order, self::INVOICE_PREFIX, __('Faktura', 'lemon-erp-woocommerce'));
        $this->renderInvoiceSummary($order, self::CORRECTION_PREFIX, __('Faktura korygująca', 'lemon-erp-woocommerce'));
    }

    public function saveAdminOrderFields(int|string $orderId, mixed $postOrOrder = null): void
    {
        if (! isset($_POST['lemon_erp_nonce']) || ! wp_verify_nonce((string) $_POST['lemon_erp_nonce'], 'lemon_erp_save_order_fields')) {
            return;
        }

        $order = wc_get_order((int) $orderId);

        if (! $order instanceof WC_Order || ! current_user_can('edit_shop_order', $order->get_id())) {
            return;
        }

        $this->saveBillingClassification(
            $order,
            $this->sanitizeCustomerType(wp_unslash($_POST['lemon_erp_customer_type'] ?? 'private')),
            $this->sanitizeNip(wp_unslash($_POST['lemon_erp_billing_nip'] ?? '')),
        );
        $order->save();
    }

    public function renderAdminBillingFields(WC_Order $order): void
    {
        $customerType = $this->customerType($order);
        $nip = $this->billingNip($order);

        echo '<p><strong>'.esc_html__('Typ klienta', 'lemon-erp-woocommerce').':</strong> '.esc_html($this->customerTypeLabel($customerType)).'</p>';

        if ($customerType === 'company' && $nip !== '') {
            echo '<p><strong>'.esc_html__('NIP', 'lemon-erp-woocommerce').':</strong> '.esc_html($nip).'</p>';
        }
    }

    public function renderCustomerInvoiceLink(WC_Order $order): void
    {
        $links = $this->invoiceLinks($order);

        if ($links === []) {
            return;
        }

        echo '<section class="woocommerce-order-details lemon-erp-invoice">';
        echo '<h2>'.esc_html__('Faktury', 'lemon-erp-woocommerce').'</h2>';

        foreach ($links as $link) {
            echo '<p><a class="button" href="'.esc_url($link['url']).'" target="_blank" rel="noopener">';
            echo esc_html($link['label']);
            echo '</a></p>';
        }

        echo '</section>';
    }

    public function renderEmailInvoiceLink(WC_Order $order, bool $sentToAdmin, bool $plainText, WC_Email $email): void
    {
        $links = $this->invoiceLinks($order);

        if ($links === []) {
            return;
        }

        if ($plainText) {
            foreach ($links as $link) {
                echo "\n".$link['title'].($link['number'] !== '' ? ' '.$link['number'] : '').': '.$link['url']."\n";
            }

            return;
        }

        echo '<p><strong>'.esc_html__('Faktury', 'lemon-erp-woocommerce').':</strong></p>';
        echo '<ul>';

        foreach ($links as $link) {
            echo '<li><a href="'.esc_url($link['url']).'">'.esc_html($link['label']).'</a></li>';
        }

        echo '</ul>';
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('lemon-erp/v1', '/catalog/capabilities', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'restCatalogCapabilities'],
            'permission_callback' => [$this, 'canReadCatalogCapabilities'],
        ]);

        register_rest_route('lemon-erp/v1', '/catalog/categories/translations', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'restLinkCategoryTranslations'],
            'permission_callback' => [$this, 'canManageCatalogTranslations'],
            'args' => [
                'translations' => [
                    'required' => true,
                    'type' => 'object',
                ],
            ],
        ]);

        register_rest_route('lemon-erp/v1', '/orders/(?P<order_id>\d+)/invoice', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'restUpsertInvoice'],
            'permission_callback' => [$this, 'canManageOrderViaRest'],
            'args' => [
                'order_id' => [
                    'required' => true,
                    'validate_callback' => fn ($value): bool => is_numeric($value) && (int) $value > 0,
                ],
            ],
        ]);

        register_rest_route('lemon-erp/v1', '/orders/(?P<order_id>\d+)/invoice/download', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'restDownloadInvoice'],
            'permission_callback' => '__return_true',
            'args' => [
                'order_id' => [
                    'required' => true,
                    'validate_callback' => fn ($value): bool => is_numeric($value) && (int) $value > 0,
                ],
            ],
        ]);
    }

    public function canReadCatalogCapabilities(WP_REST_Request $request): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('edit_products');
    }

    public function canManageCatalogTranslations(WP_REST_Request $request): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_product_terms');
    }

    public function restLinkCategoryTranslations(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! $this->polylangCatalogAvailable()
            || ! function_exists('pll_set_term_language')
            || ! function_exists('pll_save_term_translations')
        ) {
            return new WP_Error(
                'lemon_erp_polylang_required',
                __('Powiązanie tłumaczeń kategorii wymaga aktywnego Polylang.', 'lemon-erp-woocommerce'),
                ['status' => 409],
            );
        }

        $translations = $this->translationMap((array) $request->get_param('translations'));

        if (count($translations) < 2) {
            return new WP_Error(
                'lemon_erp_category_translations_incomplete',
                __('Podaj co najmniej dwa języki kategorii.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        $activeLanguages = function_exists('pll_languages_list')
            ? array_values(array_filter(array_map(
                fn (mixed $language): ?string => $this->languageSlug($language),
                (array) pll_languages_list(['fields' => 'slug']),
            )))
            : [];

        foreach ($translations as $language => $termId) {
            $term = get_term($termId, 'product_cat');

            if (! $term instanceof WP_Term) {
                return new WP_Error(
                    'lemon_erp_category_not_found',
                    sprintf(__('Nie znaleziono kategorii WooCommerce ID %d.', 'lemon-erp-woocommerce'), $termId),
                    ['status' => 404],
                );
            }

            if ($activeLanguages !== [] && ! in_array($language, $activeLanguages, true)) {
                return new WP_Error(
                    'lemon_erp_category_language_invalid',
                    sprintf(__('Język %s nie jest aktywny w Polylang.', 'lemon-erp-woocommerce'), $language),
                    ['status' => 422],
                );
            }
        }

        // Mutate only after the whole request has passed validation. A bad
        // second term must not leave the first one assigned to a new language.
        foreach ($translations as $language => $termId) {
            pll_set_term_language($termId, $language);
        }

        pll_save_term_translations($translations);
        $this->termCatalogIdentityCache = [];
        $firstTermId = (int) reset($translations);
        $identity = $this->termCatalogIdentity($firstTermId);

        return new WP_REST_Response([
            'linked' => true,
            'translations' => $identity['translations'],
            'translation_group' => $identity['translation_group'],
        ], 200);
    }

    public function restCatalogCapabilities(WP_REST_Request $request): WP_REST_Response
    {
        $polylangActive = $this->polylangCatalogAvailable();
        $languages = [];
        $defaultLanguage = null;

        if ($polylangActive && function_exists('pll_languages_list')) {
            $languages = array_values(array_filter(array_map(
                fn (mixed $language): ?string => $this->languageSlug($language),
                (array) pll_languages_list(['fields' => 'slug']),
            )));
        }

        if ($polylangActive && function_exists('pll_default_language')) {
            $defaultLanguage = $this->languageSlug(pll_default_language('slug'));
        }

        return new WP_REST_Response([
            'catalog_contract' => self::CATALOG_CONTRACT,
            'plugin_version' => self::VERSION,
            'polylang_active' => $polylangActive,
            'default_language' => $defaultLanguage,
            'languages' => $languages,
            'resources' => ['product', 'variation', 'category'],
            'category_translation_link_endpoint' => '/wp-json/lemon-erp/v1/catalog/categories/translations',
            'product_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/translations',
            'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
            'fields' => [
                'contract' => 'lemon_erp_catalog_contract',
                'language' => 'lemon_erp_language',
                'translations' => 'lemon_erp_translations',
                'translation_group' => 'lemon_erp_translation_group',
            ],
            'variation_translation_fallbacks' => [
                'polylang_post_translations',
                'translated_parent_and_sku',
                'translated_parent_and_attributes',
            ],
        ], 200);
    }

    public function appendProductCatalogContract(mixed $response, mixed $object, mixed $request = null): mixed
    {
        if (! $response instanceof WP_REST_Response
            || ! $object instanceof WC_Product
            || $object instanceof WC_Product_Variation
        ) {
            return $response;
        }

        return $this->appendCatalogIdentity(
            $response,
            $this->postCatalogIdentity($object->get_id(), 'product'),
        );
    }

    public function appendVariationCatalogContract(mixed $response, mixed $object, mixed $request = null): mixed
    {
        if (! $response instanceof WP_REST_Response || ! $object instanceof WC_Product_Variation) {
            return $response;
        }

        $response = $this->appendCatalogIdentity(
            $response,
            $this->variationCatalogIdentity($object),
        );
        $data = $response->get_data();
        $parentIdentity = $this->postCatalogIdentity($object->get_parent_id(), 'product');
        $data['lemon_erp_parent_translations'] = $parentIdentity['translations'];
        $data['lemon_erp_parent_translation_group'] = $parentIdentity['translation_group'];
        $response->set_data($data);

        return $response;
    }

    public function appendCategoryCatalogContract(mixed $response, mixed $object, mixed $request = null): mixed
    {
        if (! $response instanceof WP_REST_Response) {
            return $response;
        }

        $termId = $object instanceof WP_Term
            ? (int) $object->term_id
            : (int) ($response->get_data()['id'] ?? 0);

        if ($termId <= 0) {
            return $response;
        }

        return $this->appendCatalogIdentity(
            $response,
            $this->termCatalogIdentity($termId),
        );
    }

    /**
     * @param  array{language:?string,translations:array<string, int>,translation_group:string}  $identity
     */
    private function appendCatalogIdentity(WP_REST_Response $response, array $identity): WP_REST_Response
    {
        $data = $response->get_data();
        $data['lemon_erp_catalog_contract'] = self::CATALOG_CONTRACT;
        $data['lemon_erp_language'] = $identity['language'];
        $data['lemon_erp_translations'] = $identity['translations'];
        $data['lemon_erp_translation_group'] = $identity['translation_group'];
        $response->set_data($data);

        return $response;
    }

    /**
     * @return array{language:?string,translations:array<string, int>,translation_group:string}
     */
    private function postCatalogIdentity(int $postId, string $resource): array
    {
        $cacheKey = $resource.':'.$postId;

        if (isset($this->postCatalogIdentityCache[$cacheKey])) {
            return $this->postCatalogIdentityCache[$cacheKey];
        }

        $language = null;
        $translations = [];

        if ($postId > 0 && function_exists('pll_get_post_language')) {
            $language = $this->languageSlug(pll_get_post_language($postId, 'slug'));
        }

        if ($postId > 0 && function_exists('pll_get_post_translations')) {
            $translations = $this->translationMap((array) pll_get_post_translations($postId));
            $expectedPostType = $resource === 'variation' ? 'product_variation' : 'product';
            $translations = array_filter(
                $translations,
                fn (int $translationId): bool => get_post_type($translationId) === $expectedPostType,
            );
        }

        if ($postId > 0) {
            if ($language === null) {
                $mappedLanguage = array_search($postId, $translations, true);
                $language = is_string($mappedLanguage) ? $mappedLanguage : null;
            }

            $translations[$language ?? 'default'] = $postId;
            ksort($translations);
        }

        return $this->postCatalogIdentityCache[$cacheKey] = [
            'language' => $language,
            'translations' => $translations,
            'translation_group' => $this->translationGroup($resource, $postId, $translations),
        ];
    }

    /**
     * @return array{language:?string,translations:array<string, int>,translation_group:string}
     */
    private function termCatalogIdentity(int $termId): array
    {
        if (isset($this->termCatalogIdentityCache[$termId])) {
            return $this->termCatalogIdentityCache[$termId];
        }

        $language = null;
        $translations = [];

        if ($termId > 0 && function_exists('pll_get_term_language')) {
            $language = $this->languageSlug(pll_get_term_language($termId, 'slug'));
        }

        if ($termId > 0 && function_exists('pll_get_term_translations')) {
            $translations = $this->translationMap((array) pll_get_term_translations($termId));
            $translations = array_filter(
                $translations,
                fn (int $translationId): bool => get_term($translationId, 'product_cat') instanceof WP_Term,
            );
        }

        if ($termId > 0) {
            if ($language === null) {
                $mappedLanguage = array_search($termId, $translations, true);
                $language = is_string($mappedLanguage) ? $mappedLanguage : null;
            }

            $translations[$language ?? 'default'] = $termId;
            ksort($translations);
        }

        return $this->termCatalogIdentityCache[$termId] = [
            'language' => $language,
            'translations' => $translations,
            'translation_group' => $this->translationGroup('category', $termId, $translations),
        ];
    }

    /**
     * @return array{language:?string,translations:array<string, int>,translation_group:string}
     */
    private function variationCatalogIdentity(WC_Product_Variation $variation): array
    {
        $variationId = $variation->get_id();
        $parentId = $variation->get_parent_id();
        $directIdentity = $this->postCatalogIdentity($variationId, 'variation');
        $parentIdentity = $this->postCatalogIdentity($parentId, 'product');
        $language = $directIdentity['language'] ?? $parentIdentity['language'];
        $translations = $directIdentity['translations'];

        if (count(array_unique($translations)) < 2 && $parentIdentity['translations'] !== []) {
            $translations = array_replace(
                $translations,
                $this->variationTranslationsFromParents(
                    $variation,
                    $parentIdentity['translations'],
                ),
            );
        }

        if ($language !== null) {
            unset($translations['default']);
            $translations[$language] = $variationId;
            ksort($translations);
        }

        return [
            'language' => $language,
            'translations' => $translations,
            'translation_group' => $this->translationGroup('variation', $variationId, $translations),
        ];
    }

    /**
     * @param  array<string, int>  $parentTranslations
     * @return array<string, int>
     */
    private function variationTranslationsFromParents(
        WC_Product_Variation $source,
        array $parentTranslations,
    ): array {
        $translations = [];
        $sourceParentId = $source->get_parent_id();

        foreach ($parentTranslations as $language => $translatedParentId) {
            if ($translatedParentId === $sourceParentId) {
                $translations[$language] = $source->get_id();

                continue;
            }

            $translatedVariationId = $this->matchingVariationId($source, $translatedParentId);

            if ($translatedVariationId !== null) {
                $translations[$language] = $translatedVariationId;
            }
        }

        ksort($translations);

        return $translations;
    }

    private function matchingVariationId(
        WC_Product_Variation $source,
        int $translatedParentId,
    ): ?int {
        $translatedParent = wc_get_product($translatedParentId);

        if (! $translatedParent instanceof WC_Product_Variable) {
            return null;
        }

        if (! isset($this->variationCandidatesByParent[$translatedParentId])) {
            $this->variationCandidatesByParent[$translatedParentId] = [];

            foreach ($translatedParent->get_children() as $childId) {
                $candidate = wc_get_product((int) $childId);

                if ($candidate instanceof WC_Product_Variation
                    && $candidate->get_parent_id() === $translatedParentId
                ) {
                    $this->variationCandidatesByParent[$translatedParentId][] = $candidate;
                }
            }
        }

        $candidates = $this->variationCandidatesByParent[$translatedParentId];

        $sourceSku = strtolower(trim((string) $source->get_sku('edit')));

        if ($sourceSku !== '') {
            $skuMatches = array_values(array_filter(
                $candidates,
                fn (WC_Product_Variation $candidate): bool => strtolower(trim((string) $candidate->get_sku('edit'))) === $sourceSku,
            ));

            if (count($skuMatches) === 1) {
                return $skuMatches[0]->get_id();
            }

            if ($skuMatches !== []) {
                $candidates = $skuMatches;
            }
        }

        $sourceAttributes = $this->variationAttributeSignature($source);

        if ($sourceAttributes === '') {
            return null;
        }

        $attributeMatches = array_values(array_filter(
            $candidates,
            fn (WC_Product_Variation $candidate): bool => $this->variationAttributeSignature($candidate) === $sourceAttributes,
        ));

        return count($attributeMatches) === 1 ? $attributeMatches[0]->get_id() : null;
    }

    private function variationAttributeSignature(WC_Product_Variation $variation): string
    {
        $attributes = [];

        foreach ($variation->get_variation_attributes(false) as $name => $value) {
            $name = sanitize_key((string) $name);
            $value = sanitize_title((string) $value);

            if ($name !== '' && $value !== '') {
                $attributes[$name] = $value;
            }
        }

        if ($attributes === []) {
            return '';
        }

        ksort($attributes);

        return hash('sha256', (string) wp_json_encode($attributes));
    }

    /**
     * @param  array<mixed>  $translations
     * @return array<string, int>
     */
    private function translationMap(array $translations): array
    {
        $map = [];

        foreach ($translations as $language => $id) {
            $language = $this->languageSlug($language);
            $id = absint($id);

            if ($language !== null && $id > 0) {
                $map[$language] = $id;
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  array<string, int>  $translations
     */
    private function translationGroup(string $resource, int $currentId, array $translations): string
    {
        $ids = array_values(array_unique(array_filter([
            $currentId,
            ...array_values($translations),
        ], fn (mixed $id): bool => (int) $id > 0)));
        $ids = array_map('intval', $ids);
        sort($ids, SORT_NUMERIC);

        return sanitize_key($resource).':'.implode('|', $ids);
    }

    private function languageSlug(mixed $language): ?string
    {
        $language = sanitize_key((string) $language);

        return $language !== '' ? $language : null;
    }

    private function polylangCatalogAvailable(): bool
    {
        return function_exists('pll_get_post_language')
            && function_exists('pll_get_post_translations')
            && function_exists('pll_get_term_language')
            && function_exists('pll_get_term_translations');
    }

    public function canManageOrderViaRest(WP_REST_Request $request): bool
    {
        $orderId = (int) $request['order_id'];

        return current_user_can('manage_woocommerce')
            || current_user_can('edit_shop_orders')
            || current_user_can('edit_shop_order', $orderId);
    }

    public function restUpsertInvoice(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $order = wc_get_order((int) $request['order_id']);

        if (! $order instanceof WC_Order) {
            return new WP_Error('lemon_erp_order_not_found', __('Nie znaleziono zamówienia.', 'lemon-erp-woocommerce'), ['status' => 404]);
        }

        $params = $request->get_json_params();

        if (! is_array($params)) {
            $params = $request->get_params();
        }

        $invoiceType = $this->sanitizeInvoiceType($params['invoice_type'] ?? 'vat');
        $prefix = $this->invoicePrefix($invoiceType);
        try {
            $storedFile = $this->storeInvoiceFileIfPresent($order, $prefix, $invoiceType, $params);
        } catch (RuntimeException $exception) {
            return new WP_Error('lemon_erp_invoice_file_error', $exception->getMessage(), ['status' => 422]);
        }
        $fileUrl = $storedFile['url'] ?? esc_url_raw((string) ($params['file_url'] ?? ''));

        $meta = [
            'number' => sanitize_text_field((string) ($params['invoice_number'] ?? '')),
            'id' => sanitize_text_field((string) ($params['invoice_id'] ?? '')),
            'status' => sanitize_text_field((string) ($params['invoice_status'] ?? '')),
            'type' => $invoiceType,
            'gross_total' => sanitize_text_field((string) ($params['gross_total'] ?? '')),
            'currency' => sanitize_text_field((string) ($params['currency'] ?? '')),
            'issued_at' => sanitize_text_field((string) ($params['issued_at'] ?? '')),
            'file_type' => sanitize_text_field((string) ($params['file_type'] ?? 'pdf')),
            'file_sha256' => sanitize_text_field((string) ($params['file_sha256'] ?? '')),
            'file_url' => $fileUrl,
            'media_id' => sanitize_text_field((string) ($params['media_id'] ?? '')),
            'ksef_number' => sanitize_text_field((string) ($params['ksef_number'] ?? '')),
            'ksef_reference_number' => sanitize_text_field((string) ($params['ksef_reference_number'] ?? '')),
            'ksef_accepted_at' => sanitize_text_field((string) ($params['ksef_accepted_at'] ?? '')),
            'synced_at' => gmdate('c'),
        ];

        foreach ($meta as $key => $value) {
            $order->update_meta_data($prefix.$key, $value);
        }

        if ($storedFile !== null) {
            $order->update_meta_data($prefix.'file_path', $storedFile['path']);
            $order->update_meta_data($prefix.'file_token', $storedFile['token']);
        }

        $noteId = null;

        if (($params['add_note'] ?? true) !== false && $meta['number'] !== '') {
            $note = sprintf(
                __('Lemon ERP: zapisano fakturę %1$s. Plik: %2$s', 'lemon-erp-woocommerce'),
                $meta['number'],
                $fileUrl !== '' ? $fileUrl : __('brak linku', 'lemon-erp-woocommerce'),
            );
            $noteId = $order->add_order_note($note, false, true);
        }

        $order->save();

        return new WP_REST_Response([
            'order_id' => $order->get_id(),
            'invoice_type' => $invoiceType,
            'invoice_number' => $meta['number'],
            'file_url' => $fileUrl,
            'stored_file' => $storedFile !== null,
            'note_id' => $noteId,
        ], 200);
    }

    public function restDownloadInvoice(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $order = wc_get_order((int) $request['order_id']);

        if (! $order instanceof WC_Order) {
            return new WP_Error('lemon_erp_order_not_found', __('Nie znaleziono zamówienia.', 'lemon-erp-woocommerce'), ['status' => 404]);
        }

        $invoiceType = $this->sanitizeInvoiceType($request->get_param('type') ?: 'vat');
        $prefix = $this->invoicePrefix($invoiceType);
        $path = (string) $order->get_meta($prefix.'file_path');
        $token = (string) $order->get_meta($prefix.'file_token');
        $requestToken = (string) $request->get_param('token');

        if (! $this->canDownloadInvoice($order, $token, $requestToken)) {
            return new WP_Error('lemon_erp_invoice_forbidden', __('Brak dostępu do faktury.', 'lemon-erp-woocommerce'), ['status' => 403]);
        }

        if (! $this->isSafeInvoicePath($path) || ! is_readable($path)) {
            return new WP_Error('lemon_erp_invoice_missing', __('Nie znaleziono pliku faktury.', 'lemon-erp-woocommerce'), ['status' => 404]);
        }

        $filename = basename($path) ?: 'faktura.pdf';

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.$filename.'"');
        header('Content-Length: '.(string) filesize($path));
        readfile($path);
        exit;
    }

    private function saveBillingClassification(WC_Order $order, string $customerType, string $nip): void
    {
        $order->update_meta_data(self::CUSTOMER_TYPE_META, $customerType);
        $order->update_meta_data(self::BILLING_NIP_META, $nip);
        $order->update_meta_data(self::LEGACY_BILLING_NIP_META, $nip);
    }

    private function renderInvoiceSummary(WC_Order $order, string $prefix, string $title): void
    {
        $number = (string) $order->get_meta($prefix.'number');
        $fileUrl = $this->invoiceFileUrl($order, $prefix);

        echo '<p><strong>'.esc_html($title).'</strong><br>';

        if ($number === '' && $fileUrl === '') {
            echo esc_html__('Brak danych.', 'lemon-erp-woocommerce').'</p>';

            return;
        }

        if ($number !== '') {
            echo esc_html($number).'<br>';
        }

        if ($fileUrl !== '') {
            echo '<a href="'.esc_url($fileUrl).'" target="_blank" rel="noopener">'.esc_html__('Otwórz PDF', 'lemon-erp-woocommerce').'</a>';
        }

        echo '</p>';
    }

    private function storeInvoiceFileIfPresent(WC_Order $order, string $prefix, string $invoiceType, array $params): ?array
    {
        $fileBase64 = trim((string) ($params['file_base64'] ?? ''));

        if ($fileBase64 === '') {
            return null;
        }

        $fileBase64 = preg_replace('#^data:application/pdf;base64,#', '', $fileBase64) ?? $fileBase64;
        $bytes = base64_decode($fileBase64, true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_PDF_BYTES) {
            throw new RuntimeException(__('Nieprawidłowy albo zbyt duży plik PDF faktury.', 'lemon-erp-woocommerce'));
        }

        if (strncmp($bytes, '%PDF-', 5) !== 0) {
            throw new RuntimeException(__('Plik faktury musi być poprawnym PDF.', 'lemon-erp-woocommerce'));
        }

        $upload = wp_upload_dir(null, false);

        if (! empty($upload['error'])) {
            throw new RuntimeException((string) $upload['error']);
        }

        $root = trailingslashit((string) $upload['basedir']).'lemon-erp-invoices';
        $dir = trailingslashit($root).gmdate('Y/m').'/order-'.$order->get_id();

        if (! wp_mkdir_p($dir)) {
            throw new RuntimeException(__('Nie można utworzyć katalogu faktur Lemon ERP.', 'lemon-erp-woocommerce'));
        }

        $this->hardenInvoiceDirectory($root);

        $filename = sanitize_file_name((string) ($params['filename'] ?? ''));

        if ($filename === '') {
            $filename = sanitize_file_name('invoice-'.$order->get_id().'-'.gmdate('Ymd-His').'.pdf');
        }

        if (! str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        $token = bin2hex(random_bytes(16));
        $path = trailingslashit($dir).$token.'-'.$filename;

        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException(__('Nie można zapisać pliku faktury Lemon ERP.', 'lemon-erp-woocommerce'));
        }

        $url = add_query_arg([
            'type' => $invoiceType,
            'token' => $token,
        ], rest_url('lemon-erp/v1/orders/'.$order->get_id().'/invoice/download'));

        return [
            'path' => $path,
            'url' => $url,
            'token' => $token,
        ];
    }

    private function hardenInvoiceDirectory(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $htaccess = trailingslashit($root).'.htaccess';

        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $index = trailingslashit($root).'index.html';

        if (! file_exists($index)) {
            file_put_contents($index, '');
        }
    }

    private function canDownloadInvoice(WC_Order $order, string $expectedToken, string $requestToken): bool
    {
        if (current_user_can('manage_woocommerce') || current_user_can('edit_shop_order', $order->get_id())) {
            return true;
        }

        if ($expectedToken !== '' && hash_equals($expectedToken, $requestToken)) {
            return true;
        }

        return is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
    }

    private function isSafeInvoicePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $upload = wp_upload_dir(null, false);
        $root = realpath(trailingslashit((string) $upload['basedir']).'lemon-erp-invoices');
        $real = realpath($path);

        return $root !== false && $real !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR);
    }

    private function orderFromAdminObject(mixed $object): ?WC_Order
    {
        if ($object instanceof WC_Order) {
            return $object;
        }

        if ($object instanceof WP_Post) {
            $order = wc_get_order($object->ID);

            return $order instanceof WC_Order ? $order : null;
        }

        $orderId = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if ($orderId > 0) {
            $order = wc_get_order($orderId);

            return $order instanceof WC_Order ? $order : null;
        }

        return null;
    }

    private function customerType(WC_Order $order): string
    {
        return $this->sanitizeCustomerType($order->get_meta(self::CUSTOMER_TYPE_META) ?: 'private');
    }

    private function customerTypeLabel(string $customerType): string
    {
        return $customerType === 'company'
            ? __('Firma', 'lemon-erp-woocommerce')
            : __('Osoba prywatna', 'lemon-erp-woocommerce');
    }

    private function billingNip(WC_Order $order): string
    {
        return $this->sanitizeNip(
            $order->get_meta(self::BILLING_NIP_META)
            ?: $order->get_meta(self::LEGACY_BILLING_NIP_META)
            ?: $order->get_meta('billing_nip')
        );
    }

    /**
     * JSON Schema condition for fields visible/required only for company checkout.
     *
     * @return array<string, mixed>
     */
    private function companyBlockCondition(): array
    {
        return $this->customerTypeBlockCondition('company');
    }

    /**
     * JSON Schema condition for fields hidden for private checkout.
     *
     * @return array<string, mixed>
     */
    private function privateBlockCondition(): array
    {
        return $this->customerTypeBlockCondition('private');
    }

    /**
     * @return array<string, mixed>
     */
    private function customerTypeBlockCondition(string $customerType): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer' => [
                    'type' => 'object',
                    'properties' => [
                        'address' => [
                            'type' => 'object',
                            'properties' => [
                                self::BLOCK_CUSTOMER_TYPE_FIELD => [
                                    'const' => $customerType,
                                ],
                            ],
                            'required' => [self::BLOCK_CUSTOMER_TYPE_FIELD],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function checkoutInlineStyle(): string
    {
        return <<<'CSS'
.lemon-erp-checkout-field-hidden {
    display: none !important;
}

.lemon-erp-checkout-field {
    min-width: 0;
}

.lemon-erp-checkout-field--customer-type,
.lemon-erp-checkout-field--company {
    margin-bottom: 0;
}

.lemon-erp-checkout-field--nip {
    margin-bottom: clamp(14px, 1.2vw, 20px);
}

.wc-block-components-address-form > .lemon-erp-checkout-field--customer-type,
.wc-block-components-address-form > .lemon-erp-checkout-field--company,
.wc-block-components-address-form > .lemon-erp-checkout-field--nip {
    align-self: stretch;
}

.wc-block-components-address-form > .lemon-erp-checkout-field--nip {
    grid-column: 1 / -1;
}

.wc-block-components-address-form > *:has(select[id$="lemon-erp-customer-type"]),
.wc-block-components-address-form > *:has(select[id$="lemon-erp/customer-type"]),
.wc-block-components-address-form > *:has(select[name*="lemon-erp/customer-type"]),
.wc-block-components-address-form > *:has(select[name*="lemon-erp_customer-type"]) {
    order: -30;
    grid-column: 1 / -1;
}

.wc-block-components-address-form > *:has(input[id$="lemon-erp-company-name"]),
.wc-block-components-address-form > *:has(input[id$="lemon-erp/company-name"]),
.wc-block-components-address-form > *:has(input[name*="lemon-erp/company-name"]),
.wc-block-components-address-form > *:has(input[name*="lemon-erp_company-name"]) {
    order: -29;
    grid-column: 1 / -1;
}

.wc-block-components-address-form > *:has(input[id$="lemon-erp-nip"]),
.wc-block-components-address-form > *:has(input[id$="lemon-erp/nip"]),
.wc-block-components-address-form > *:has(input[name*="lemon-erp/nip"]),
.wc-block-components-address-form > *:has(input[name*="lemon-erp_nip"]) {
    order: -28;
    grid-column: 1 / -1;
}

@media (max-width: 680px) {
    .wc-block-components-address-form > .lemon-erp-checkout-field--customer-type,
    .wc-block-components-address-form > .lemon-erp-checkout-field--company,
    .wc-block-components-address-form > .lemon-erp-checkout-field--nip {
        grid-column: 1 / -1;
        margin-bottom: 12px;
    }
}
CSS;
    }

    private function checkoutInlineScript(): string
    {
        return <<<'JS'
(function () {
    'use strict';

    var selectors = {
        customerType: [
            '#billing_lemon_customer_type',
            'select[name="billing_lemon_customer_type"]',
            'select[id$="lemon-erp-customer-type"]',
            'select[id$="lemon-erp/customer-type"]',
            'select[name*="lemon-erp/customer-type"]',
            'select[name*="lemon-erp_customer-type"]'
        ].join(','),
        company: [
            '#billing_company',
            'input[id$="lemon-erp-company-name"]',
            'input[id$="lemon-erp/company-name"]',
            'input[name*="lemon-erp/company-name"]',
            'input[name*="lemon-erp_company-name"]'
        ].join(','),
        nip: [
            '#billing_nip',
            'input[id$="lemon-erp-nip"]',
            'input[id$="lemon-erp/nip"]',
            'input[name*="lemon-erp/nip"]',
            'input[name*="lemon-erp_nip"]'
        ].join(',')
    };

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }

        callback();
    }

    function fieldWrap(field) {
        if (!field) {
            return null;
        }

        return field.closest('.form-row, .wc-block-components-text-input, .wc-block-components-combobox, .wc-block-components-select, .components-base-control, .wc-block-components-address-form > *') || field.parentElement;
    }

    function addressContainer(field) {
        if (!field) {
            return null;
        }

        return field.closest('.woocommerce-billing-fields__field-wrapper, .woocommerce-shipping-fields__field-wrapper, .wc-block-components-address-form, .wp-block-woocommerce-checkout-shipping-address-block .wc-block-components-address-form, .wp-block-woocommerce-checkout-billing-address-block .wc-block-components-address-form');
    }

    function topLevelFieldWrap(field, container) {
        var node = fieldWrap(field);

        if (!node || !container) {
            return node;
        }

        while (node.parentElement && node.parentElement !== container && container.contains(node.parentElement)) {
            node = node.parentElement;
        }

        return node.parentElement === container ? node : fieldWrap(field);
    }

    function markField(field, className) {
        var container = addressContainer(field);
        var wrap = topLevelFieldWrap(field, container) || fieldWrap(field);

        if (!wrap) {
            return null;
        }

        wrap.classList.add('lemon-erp-checkout-field', className);

        return wrap;
    }

    function markErpFields(select, fields) {
        markField(select, 'lemon-erp-checkout-field--customer-type');
        markField(fields.company, 'lemon-erp-checkout-field--company');
        markField(fields.nip, 'lemon-erp-checkout-field--nip');
    }

    function groupName(field) {
        var id = field && field.id ? field.id : '';
        var name = field && field.name ? field.name : '';
        var source = id || name;

        if (source.indexOf('shipping') === 0 || source.indexOf('shipping-') !== -1 || source.indexOf('shipping_') !== -1) {
            return 'shipping';
        }

        if (source.indexOf('billing') === 0 || source.indexOf('billing-') !== -1 || source.indexOf('billing_') !== -1) {
            return 'billing';
        }

        return 'billing';
    }

    function fieldsForGroup(group) {
        var allCompany = Array.prototype.slice.call(document.querySelectorAll(selectors.company));
        var allNip = Array.prototype.slice.call(document.querySelectorAll(selectors.nip));

        return {
            company: allCompany.find(function (field) { return groupName(field) === group; }) || allCompany[0] || null,
            nip: allNip.find(function (field) { return groupName(field) === group; }) || allNip[0] || null
        };
    }

    function setFieldVisible(field, visible, required) {
        var wrap = fieldWrap(field);

        if (!field || !wrap) {
            return;
        }

        if (wrap.classList.contains('lemon-erp-checkout-field-hidden') === visible) {
            wrap.classList.toggle('lemon-erp-checkout-field-hidden', !visible);
        }

        if (field.disabled === visible) {
            field.disabled = !visible;
        }

        if (field.required !== !!(visible && required)) {
            field.required = !!(visible && required);
        }

        if (field.getAttribute('aria-hidden') !== (visible ? 'false' : 'true')) {
            field.setAttribute('aria-hidden', visible ? 'false' : 'true');
        }

        if (!visible) {
            field.value = '';
        }
    }

    function moveFieldsToTop(select) {
        var group = groupName(select);
        var fields = fieldsForGroup(group);
        var container = addressContainer(select);
        var wrappers = [
            topLevelFieldWrap(select, container),
            topLevelFieldWrap(fields.company, container),
            topLevelFieldWrap(fields.nip, container)
        ].filter(Boolean);
        var alreadyAtTop;

        if (!container) {
            return;
        }

        alreadyAtTop = wrappers.every(function (wrap, index) {
            return container.children[index] === wrap;
        });

        if (alreadyAtTop) {
            return;
        }

        wrappers.slice().reverse().forEach(function (wrap) {
            if (wrap.parentElement === container) {
                container.insertBefore(wrap, container.firstElementChild);
            }
        });
    }

    function syncOne(select) {
        var fields = fieldsForGroup(groupName(select));

        if (!select.value) {
            select.value = 'private';
        }

        var isCompany = select.value === 'company';

        markErpFields(select, fields);
        setFieldVisible(fields.company, isCompany, true);
        setFieldVisible(fields.nip, isCompany, true);
        moveFieldsToTop(select);
    }

    function syncAll() {
        document.querySelectorAll(selectors.customerType).forEach(syncOne);
    }

    function scheduleSync() {
        window.setTimeout(syncAll, 80);
        window.setTimeout(syncAll, 250);
    }

    ready(function () {
        syncAll();
        window.setTimeout(syncAll, 300);
        window.setTimeout(syncAll, 1000);

        document.body.addEventListener('change', function (event) {
            if (event.target && event.target.matches(selectors.customerType)) {
                syncOne(event.target);
                scheduleSync();
            }
        });

        document.body.addEventListener('click', function (event) {
            if (event.target && event.target.closest(selectors.customerType)) {
                scheduleSync();
            }
        });
    });
}());
JS;
    }

    /**
     * @return list<array{title:string,number:string,label:string,url:string}>
     */
    private function invoiceLinks(WC_Order $order): array
    {
        $links = [];
        $invoice = $this->invoiceLink(
            $order,
            self::INVOICE_PREFIX,
            __('Faktura', 'lemon-erp-woocommerce'),
            __('Pobierz fakturę %s', 'lemon-erp-woocommerce'),
            __('Pobierz fakturę', 'lemon-erp-woocommerce'),
        );
        $correction = $this->invoiceLink(
            $order,
            self::CORRECTION_PREFIX,
            __('Faktura korygująca', 'lemon-erp-woocommerce'),
            __('Pobierz korektę %s', 'lemon-erp-woocommerce'),
            __('Pobierz korektę', 'lemon-erp-woocommerce'),
        );

        if ($invoice !== null) {
            $links[] = $invoice;
        }

        if ($correction !== null) {
            $links[] = $correction;
        }

        return $links;
    }

    /**
     * @return array{title:string,number:string,label:string,url:string}|null
     */
    private function invoiceLink(WC_Order $order, string $prefix, string $title, string $numberedLabel, string $emptyLabel): ?array
    {
        $url = $this->invoiceFileUrl($order, $prefix);

        if ($url === '') {
            return null;
        }

        $number = (string) $order->get_meta($prefix.'number');

        return [
            'title' => $title,
            'number' => $number,
            'label' => $number !== '' ? sprintf($numberedLabel, $number) : $emptyLabel,
            'url' => $url,
        ];
    }

    private function invoiceFileUrl(WC_Order $order, string $prefix): string
    {
        return esc_url_raw((string) $order->get_meta($prefix.'file_url'));
    }

    private function sanitizeCustomerType(mixed $value): string
    {
        return sanitize_key((string) $value) === 'company' ? 'company' : 'private';
    }

    private function sanitizeInvoiceType(mixed $value): string
    {
        return sanitize_key((string) $value) === 'correction' ? 'correction' : 'vat';
    }

    private function invoicePrefix(string $invoiceType): string
    {
        return $invoiceType === 'correction' ? self::CORRECTION_PREFIX : self::INVOICE_PREFIX;
    }

    private function sanitizeNip(mixed $value): string
    {
        return trim(sanitize_text_field((string) $value));
    }

    private function addressCheckoutFieldValue(WC_Order $order, string $fieldId): string
    {
        $billing = $this->additionalCheckoutFieldValue($order, $fieldId, 'billing');

        if ($billing !== '') {
            return $billing;
        }

        return $this->additionalCheckoutFieldValue($order, $fieldId, 'shipping');
    }

    private function additionalCheckoutFieldValue(WC_Order $order, string $fieldId, string $group = 'other'): string
    {
        if (
            class_exists(Package::class)
            && class_exists(CheckoutFields::class)
        ) {
            try {
                $checkoutFields = Package::container()
                    ->get(CheckoutFields::class);
                $value = $checkoutFields->get_field_from_object($fieldId, $order, $group);

                if ($value !== '') {
                    return (string) $value;
                }
            } catch (Throwable) {
                return '';
            }
        }

        $prefix = match ($group) {
            'billing' => '_wc_billing/',
            'shipping' => '_wc_shipping/',
            default => '_wc_other/',
        };
        $metaKeys = [
            $prefix.$fieldId,
            $fieldId,
            str_replace('/', '_', $fieldId),
        ];

        foreach ($metaKeys as $metaKey) {
            $value = $order->get_meta($metaKey);

            if ($value !== '') {
                return (string) $value;
            }
        }

        return '';
    }
}

add_action('plugins_loaded', static function (): void {
    if (class_exists('WooCommerce')) {
        Lemon_Erp_WooCommerce::boot();
    }
});
