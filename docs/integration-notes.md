# Integration Notes

## WooCommerce

Use WooCommerce REST API as an external sales channel.

Per integration store:

- name
- base_url
- consumer_key
- consumer_secret
- status
- sync_enabled
- order_import_enabled
- stock_export_enabled
- last_successful_sync_at

Required flows:

- import products or map products by SKU
- import orders
- export stock
- upload invoice PDF to order
- write order notes when invoice/KSeF status changes

Stock export strategy:

- Never push all stock blindly on every change.
- Create a stock sync queue item when a posted stock document changes a product balance.
- Resolve affected sales channels from warehouse routes.
- Push stock to WooCommerce products/variations mapped to the internal SKU.
- Log every response.

Open decisions:

- Should store stock equal one warehouse balance, sum of selected warehouses, or custom allocation?
- Should WooCommerce stock be reduced by WooCommerce checkout, by this system, or both with reconciliation?
- Should imported orders reserve stock immediately or only after payment?

## KSeF

Current official notes checked on 2026-05-31:

- The Ministry of Finance KSeF page states that mandatory KSeF applies from 2026-02-01 for taxpayers whose 2024 sales exceeded PLN 200M, and from 2026-04-01 for others, with a small monthly gross invoice value exception until end of 2026.
- Receiving invoices through KSeF is mandatory from 2026-02-01.
- KSeF assigns a unique identifying number to issued invoices.
- KSeF API was updated to version 2.1.1 in February 2026.

Implementation notes:

- Treat KSeF as asynchronous submission, not as inline invoice rendering.
- Store invoice data, XML, request metadata, response metadata, KSeF number, status and error details.
- Support online and offline modes if required by final accounting requirements.
- Validate against current FA schema before every production release.

## Invoice templates

Separate legal invoice data from visual PDF templates:

- Legal/structured data is stored in normalized tables.
- PDF templates control layout only.
- KSeF XML is generated from structured invoice data, not from the PDF.
- Template edits should not change historical invoice data.

