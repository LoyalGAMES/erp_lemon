# Sempre WMS/Accounting System - MVP Roadmap

## Phase 0 - Setup

- Create Laravel app.
- Configure PostgreSQL, Redis, queues and scheduler.
- Add auth, roles and basic audit logging.
- Add CI/build checks.
- Prepare staging deployment on the server.

## Phase 1 - Warehouse core

- Product catalog with SKU.
- Warehouse management.
- Manual stock documents: PZ, WZ, RW, PW, MM, correction.
- Draft/post/cancel workflow.
- Stock ledger and stock balances.
- Audit log for stock operations.

Acceptance:

- Operator can add products and warehouses.
- Operator can post PZ and see stock increase.
- Operator can post WZ/RW and see stock decrease.
- Operator can transfer stock between warehouses with MM.
- History cannot be edited silently after posting.

## Phase 2 - WooCommerce integration

- Admin panel for multiple WooCommerce integrations.
- API credential storage.
- Product mapping by SKU and WooCommerce product/variation IDs.
- Pull orders.
- Push stock to selected stores based on warehouse routing.
- Sync logs and retryable failed jobs.

Acceptance:

- M1 can push stock to B2C and B2B.
- M2 can push stock only to one store.
- M3 can remain internal and not push stock anywhere.
- Failed API calls are visible and retryable.

## Phase 3 - Orders, reservations and returns

- Import WooCommerce orders.
- Stock reservation policy.
- Create WZ from order.
- Return cases.
- Accept returned items into selected warehouse.
- Optional return reason and condition.

Acceptance:

- Paid order can reserve stock.
- Shipment can create WZ.
- Return can create a receipt into a chosen warehouse.

## Phase 4 - Invoices

- Invoice data model.
- Invoice numbering series.
- VAT rates and buyer/seller data.
- PDF generation.
- Editable visual templates.
- Attach invoice to WooCommerce order.
- Correction invoices.

Acceptance:

- Accountant can issue invoice from order.
- PDF is generated from selected template.
- PDF is attached/uploaded to WooCommerce order.
- Invoice data remains structured and auditable.

## Phase 5 - KSeF

- KSeF sandbox/demo configuration.
- FA(3) XML generator.
- Submission queue.
- Store response, KSeF number and status.
- Retry and error handling.

Acceptance:

- Test invoice can be generated as KSeF XML.
- Test invoice can be sent to KSeF test/demo environment.
- Status and response are visible in the invoice view.

## Phase 6 - Hardening

- Permissions review.
- Full audit trails.
- Backups.
- Import/export.
- Monitoring.
- Deployment runbook.
- Security review of API credentials and invoice data.

