# Sempre WMS/Accounting System - Architecture Draft

## Decision

Laravel is a good base for this project.

Reasons:

- The team is comfortable with PHP and JavaScript.
- Laravel gives us queues, scheduler, migrations, policies, mail, storage, API clients, validation and a clean service-layer structure.
- WooCommerce integrations, stock sync, invoice generation and KSeF submission are naturally queue-driven jobs.
- The system must be independent from WordPress, so Laravel should be a separate app with its own database, auth and admin panel.

Recommended stack:

- Backend: Laravel 12 or current stable Laravel LTS if deployment constraints require it.
- Frontend: Laravel Blade + Livewire/Filament for fast admin panels, or Inertia + Vue/React if the UI needs richer workflows.
- Database: PostgreSQL.
- Queue/cache: Redis.
- Files: local disk or S3-compatible storage for PDFs, XML, attachments and exports.
- Deployment: Docker Compose on the server, or classic Nginx + PHP-FPM + Supervisor + PostgreSQL + Redis.

## Core modules

1. Users and permissions

- Roles: admin, warehouse operator, accountant, integration manager, read-only/audit.
- Audit log for all stock, invoice and integration operations.

2. Product catalog

- Internal product master data independent from WooCommerce.
- SKU as the main cross-system identifier.
- Optional barcode, EAN, dimensions, weight, tax rate, unit, product groups.
- Mappings to WooCommerce product/variation IDs per integration.

3. Warehouses

- Multiple warehouses.
- Each warehouse can be connected to zero, one or many sales channels.
- Per-warehouse sync rules: which WooCommerce stores receive stock, whether stock is pushed automatically, stock buffer, stock reservation behavior.

4. Stock ledger

- Stock must be event/ledger based, not stored only as a mutable number.
- Every movement creates a stock ledger entry and a source document.
- Current stock is computed from posted ledger entries and cached in stock balances.

5. Warehouse documents

Initial supported document types:

- PZ: goods receipt from supplier.
- WZ: goods issue / external release.
- RW: internal issue.
- PW: internal receipt.
- MM: inter-warehouse transfer.
- ZW/return receipt: customer return accepted into a selected warehouse.
- KOR/adjustment: stock correction with mandatory reason.

Document states:

- draft
- posted
- cancelled

Only posted documents affect stock. Cancellation should create a reversing movement or document, not silently delete history.

6. WooCommerce integrations

- Add/remove integrations from the admin panel.
- Store API base URL, consumer key/secret, sync mode and stock routing.
- Pull orders from B2C/B2B stores.
- Push stock levels to selected stores based on warehouse routing.
- Upload invoice PDFs/XML metadata to WooCommerce orders.
- Store sync logs and failed job details.

7. Orders and reservations

- Imported WooCommerce orders create reservations or pending WZ documents.
- Configurable policy:
  - reserve stock when order is paid,
  - reserve stock when order is created,
  - reduce stock only after shipment/WZ.
- Avoid double reduction if WooCommerce also manages stock.

8. Invoices

- Invoice, correction invoice and advance/final invoice support should be designed from the start.
- Editable templates for PDF output.
- Structured invoice data should be separate from PDF templates.
- Generate a legal invoice data model first, then render PDF and KSeF XML from the same source.

9. KSeF

- KSeF integration should be a separate bounded module.
- Store outgoing XML, submission status, KSeF number, UPO/technical response and retry history.
- Use sandbox/demo first.
- Do not hard-code a single API version; isolate the API client and XML schema generator.

10. Returns

- Return case workflow:
  - imported or manually created return,
  - customer/order reference,
  - item inspection,
  - target warehouse selection,
  - stock receipt document,
  - optional correction invoice.

## Domain model sketch

Main tables:

- users
- roles / permissions
- products
- product_identifiers
- product_channel_mappings
- warehouses
- warehouse_channel_routes
- stock_balances
- stock_ledger_entries
- warehouse_documents
- warehouse_document_lines
- sales_channels
- woo_integrations
- external_orders
- external_order_lines
- stock_reservations
- invoices
- invoice_lines
- invoice_templates
- invoice_files
- ksef_submissions
- return_cases
- return_case_lines
- sync_jobs
- audit_logs

## Architecture rules

- WordPress/WooCommerce is an external channel, not the source of truth.
- The Laravel system is the source of truth for stock and invoices.
- Product identity is internal; WooCommerce IDs are mappings.
- Stock changes only through posted documents.
- Stock sync is asynchronous and retried through queues.
- Every external API operation is logged.
- Invoice PDF templates are editable, but legal invoice data and KSeF XML generation are controlled by code and validated.

## Risks

- KSeF and Polish invoice compliance are legal/accounting-sensitive. Requirements must be validated against current Ministry of Finance schemas and with the accountant.
- WooCommerce stock behavior must be configured carefully to avoid double stock reduction.
- Product matching by SKU must be cleaned before enabling automatic sync.
- Multi-warehouse stock routing must define whether each store receives stock from one warehouse, many warehouses summed together, or a custom allocation rule.

