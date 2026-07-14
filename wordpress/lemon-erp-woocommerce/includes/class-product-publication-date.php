<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Makes the ERP publication date writable for products and variations.
 *
 * WooCommerce accepts date_created for products, while the variations REST
 * schema exposes it as read-only. Both requests already carry the canonical
 * ERP date in meta_data, so applying it before the object is first saved keeps
 * the parent, its translations and every variation consistent.
 */
final class Lemon_Erp_Product_Publication_Date
{
    private const META_KEY = '_sempre_erp_publication_date';

    public function hooks(): void
    {
        add_filter('woocommerce_rest_pre_insert_product_object', [$this, 'apply'], 20, 3);
        add_filter('woocommerce_rest_pre_insert_product_variation_object', [$this, 'apply'], 20, 3);
    }

    public function apply(mixed $object, WP_REST_Request $request, bool $creating): mixed
    {
        $publicationDate = $this->publicationDate($request->get_param('meta_data'));

        if ($publicationDate instanceof WP_Error || $publicationDate === null) {
            return $publicationDate ?? $object;
        }

        if (! is_object($object) || ! method_exists($object, 'set_date_created')) {
            return new WP_Error(
                'lemon_erp_product_publication_object_invalid',
                __('WooCommerce nie udostępnił produktu do ustawienia daty publikacji.', 'lemon-erp-woocommerce'),
                ['status' => 500],
            );
        }

        $object->set_date_created($publicationDate->getTimestamp());

        return $object;
    }

    private function publicationDate(mixed $metadata): DateTimeImmutable|WP_Error|null
    {
        if (! is_array($metadata)) {
            return null;
        }

        $values = [];

        foreach ($metadata as $row) {
            if (! is_array($row) || (string) ($row['key'] ?? '') !== self::META_KEY) {
                continue;
            }

            $value = trim((string) ($row['value'] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));

        if ($values === []) {
            return null;
        }

        if (count($values) !== 1) {
            return new WP_Error(
                'lemon_erp_product_publication_date_conflict',
                __('ERP przesłał więcej niż jedną datę publikacji produktu.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        $value = $values[0];

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return $this->invalidDate();
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, wp_timezone());
        $errors = DateTimeImmutable::getLastErrors();

        if (! $date instanceof DateTimeImmutable
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d\TH:i:s') !== $value
        ) {
            return $this->invalidDate();
        }

        return $date;
    }

    private function invalidDate(): WP_Error
    {
        return new WP_Error(
            'lemon_erp_product_publication_date_invalid',
            __('Data publikacji produktu z ERP ma niepoprawny format.', 'lemon-erp-woocommerce'),
            ['status' => 422],
        );
    }
}
