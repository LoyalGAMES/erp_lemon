<?php
/**
 * Plugin Name: Lemon ERP for WooCommerce
 * Description: Adds Lemon ERP checkout fields and invoice metadata endpoints for WooCommerce orders.
 * Version: 0.1.0
 * Author: Lemon ERP
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Update URI: false
 * Text Domain: lemon-erp-woocommerce
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

final class Lemon_Erp_WooCommerce
{
    private const CUSTOMER_TYPE_META = '_lemon_erp_customer_type';
    private const BILLING_NIP_META = '_lemon_erp_billing_nip';
    private const LEGACY_BILLING_NIP_META = '_billing_nip';
    private const INVOICE_PREFIX = '_sempre_erp_invoice_';
    private const CORRECTION_PREFIX = '_sempre_erp_correction_invoice_';
    private const MAX_PDF_BYTES = 15728640;

    private static ?self $instance = null;

    public static function boot(): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
    }

    private function __construct()
    {
        add_filter('woocommerce_checkout_fields', [$this, 'classicCheckoutFields']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateClassicCheckout'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'saveClassicCheckoutFields'], 20, 2);
        add_action('woocommerce_init', [$this, 'registerBlockCheckoutFields']);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'normalizeBlockCheckoutFields'], 20, 2);
        add_action('add_meta_boxes', [$this, 'addOrderMetaBox'], 20, 2);
        add_action('woocommerce_process_shop_order_meta', [$this, 'saveAdminOrderFields'], 20, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'renderAdminBillingFields']);
        add_action('woocommerce_order_details_after_order_table', [$this, 'renderCustomerInvoiceLink']);
        add_action('woocommerce_email_after_order_table', [$this, 'renderEmailInvoiceLink'], 20, 4);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function classicCheckoutFields(array $fields): array
    {
        $fields['billing']['billing_lemon_customer_type'] = [
            'type' => 'select',
            'label' => __('Kupuję jako', 'lemon-erp-woocommerce'),
            'required' => true,
            'class' => ['form-row-wide'],
            'priority' => 21,
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
            'priority' => 31,
            'placeholder' => __('NIP dla faktury firmowej', 'lemon-erp-woocommerce'),
        ];

        return $fields;
    }

    /**
     * @param array<string, mixed> $data
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
     * @param array<string, mixed> $data
     */
    public function saveClassicCheckoutFields(WC_Order $order, array $data): void
    {
        $this->saveBillingClassification(
            $order,
            $this->sanitizeCustomerType($data['billing_lemon_customer_type'] ?? 'private'),
            $this->sanitizeNip($data['billing_nip'] ?? ''),
        );
    }

    public function registerBlockCheckoutFields(): void
    {
        if (! function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id' => 'lemon-erp/customer-type',
            'label' => __('Kupuję jako', 'lemon-erp-woocommerce'),
            'location' => 'order',
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
            'id' => 'lemon-erp/nip',
            'label' => __('NIP', 'lemon-erp-woocommerce'),
            'location' => 'order',
            'type' => 'text',
            'required' => false,
            'sanitize_callback' => fn ($value): string => $this->sanitizeNip($value),
        ]);
    }

    public function normalizeBlockCheckoutFields(WC_Order $order, WP_REST_Request $request): void
    {
        $customerType = $this->additionalCheckoutFieldValue($order, 'lemon-erp/customer-type');
        $nip = $this->additionalCheckoutFieldValue($order, 'lemon-erp/nip');

        if ($customerType !== '' || $nip !== '') {
            $this->saveBillingClassification(
                $order,
                $this->sanitizeCustomerType($customerType ?: 'private'),
                $this->sanitizeNip($nip),
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

        if ($nip !== '') {
            echo '<p><strong>'.esc_html__('NIP', 'lemon-erp-woocommerce').':</strong> '.esc_html($nip).'</p>';
        }
    }

    public function renderCustomerInvoiceLink(WC_Order $order): void
    {
        $url = $this->invoiceFileUrl($order, self::INVOICE_PREFIX);
        $number = (string) $order->get_meta(self::INVOICE_PREFIX.'number');

        if ($url === '') {
            return;
        }

        echo '<section class="woocommerce-order-details lemon-erp-invoice">';
        echo '<h2>'.esc_html__('Faktura', 'lemon-erp-woocommerce').'</h2>';
        echo '<p><a class="button" href="'.esc_url($url).'" target="_blank" rel="noopener">';
        echo esc_html($number !== '' ? sprintf(__('Pobierz fakturę %s', 'lemon-erp-woocommerce'), $number) : __('Pobierz fakturę', 'lemon-erp-woocommerce'));
        echo '</a></p>';
        echo '</section>';
    }

    public function renderEmailInvoiceLink(WC_Order $order, bool $sentToAdmin, bool $plainText, WC_Email $email): void
    {
        $url = $this->invoiceFileUrl($order, self::INVOICE_PREFIX);
        $number = (string) $order->get_meta(self::INVOICE_PREFIX.'number');

        if ($url === '') {
            return;
        }

        if ($plainText) {
            echo "\n".sprintf(__('Faktura %s: %s', 'lemon-erp-woocommerce'), $number, $url)."\n";

            return;
        }

        echo '<p><strong>'.esc_html__('Faktura', 'lemon-erp-woocommerce').':</strong> <a href="'.esc_url($url).'">'.esc_html($number !== '' ? $number : __('Pobierz PDF', 'lemon-erp-woocommerce')).'</a></p>';
    }

    public function registerRestRoutes(): void
    {
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

    private function additionalCheckoutFieldValue(WC_Order $order, string $fieldId): string
    {
        $metaKeys = [
            '_wc_other/'.$fieldId,
            $fieldId,
            str_replace('/', '_', $fieldId),
        ];

        foreach ($metaKeys as $metaKey) {
            $value = $order->get_meta($metaKey);

            if ($value !== '') {
                return (string) $value;
            }
        }

        if (
            class_exists(\Automattic\WooCommerce\Blocks\Package::class)
            && class_exists(\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class)
        ) {
            try {
                $checkoutFields = \Automattic\WooCommerce\Blocks\Package::container()
                    ->get(\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class);
                $value = $checkoutFields->get_field_from_object($fieldId, $order, 'other');

                if ($value !== '') {
                    return (string) $value;
                }
            } catch (Throwable) {
                return '';
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
