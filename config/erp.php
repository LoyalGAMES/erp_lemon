<?php

declare(strict_types=1);

return [
    'print_bridge_token' => env('PRINT_BRIDGE_TOKEN', ''),

    'invoices' => [
        'seller' => [
            'name' => env('INVOICE_SELLER_NAME', 'Sempre'),
            'tax_id' => env('INVOICE_SELLER_NIP', ''),
            'address_1' => env('INVOICE_SELLER_ADDRESS_1', ''),
            'address_2' => env('INVOICE_SELLER_ADDRESS_2', ''),
            'postcode' => env('INVOICE_SELLER_POSTCODE', ''),
            'city' => env('INVOICE_SELLER_CITY', ''),
            'country' => env('INVOICE_SELLER_COUNTRY', 'PL'),
            'email' => env('INVOICE_SELLER_EMAIL', ''),
            'phone' => env('INVOICE_SELLER_PHONE', ''),
            'bank_account' => env('INVOICE_SELLER_BANK_ACCOUNT', ''),
        ],
        'numbering' => [
            'sales_prefix' => env('INVOICE_SALES_PREFIX', 'FV'),
            'b2c_sales_prefix' => env('INVOICE_B2C_SALES_PREFIX', env('INVOICE_SALES_PREFIX', 'FV')),
            'b2b_sales_prefix' => env('INVOICE_B2B_SALES_PREFIX', 'FV/FIRMA'),
            'correction_prefix' => env('INVOICE_CORRECTION_PREFIX', 'FK'),
            'proforma_prefix' => env('INVOICE_PROFORMA_PREFIX', 'PRO'),
            'oss_sales_prefix' => env('INVOICE_OSS_SALES_PREFIX', 'FV/OSS'),
            'oss_correction_prefix' => env('INVOICE_OSS_CORRECTION_PREFIX', 'FVK/OSS'),
            'oss_pattern' => env('INVOICE_OSS_NUMBER_PATTERN', '{PREFIX}/{SEQ}/{MM}/{YYYY}'),
            'oss_padding' => (int) env('INVOICE_OSS_NUMBER_PADDING', 1),
            'pattern' => env('INVOICE_NUMBER_PATTERN', '{PREFIX}/{YYYY}/{SEQ}'),
            'padding' => (int) env('INVOICE_NUMBER_PADDING', 6),
            'payment_due_days' => (int) env('INVOICE_PAYMENT_DUE_DAYS', 0),
        ],
        'ksef' => [
            'default_send_policy' => env('INVOICE_KSEF_DEFAULT_SEND_POLICY', 'auto'),
        ],
    ],

    'warehouse_documents' => [
        'numbering' => [
            'pattern' => env('WAREHOUSE_DOCUMENT_NUMBER_PATTERN', '{TYPE}/{YYYY}/{SEQ}'),
            'padding' => (int) env('WAREHOUSE_DOCUMENT_NUMBER_PADDING', 6),
        ],
    ],
];
