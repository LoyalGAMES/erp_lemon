@extends('layouts.app', [
    'title' => ($isFavorites ?? false) ? 'Ulubione produkty' : 'Produkty',
    'subtitle' => ($isFavorites ?? false) ? 'Oznaczone produkty główne ERP wraz z ich wariantami.' : 'Polski katalog głównych produktów ERP z wariantami, ceną PLN i stanami.',
    'module' => ($isFavorites ?? false) ? 'product-favorites' : 'products',
])

@push('styles')
    <style>
        .products-table table { min-width: 1180px; }
        .product-filter-panel { margin-bottom: 14px; padding: 14px; display: grid; gap: 12px; }
        .product-filters { display: grid; grid-template-columns: minmax(260px, 2fr) repeat(6, minmax(130px, 1fr)) auto auto; gap: 10px; align-items: end; }
        .product-mobile-filter-toggle, .product-mobile-filter-trigger, .product-mobile-filter-backdrop, .product-filter-drawer-header { display: none; }
        .product-filters label { min-width: 0; }
        .product-filters .button { min-height: 40px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; white-space: nowrap; }
        .filter-result-line { color: var(--muted); font-size: 12px; }
        .product-import-issue { margin-bottom: 14px; border-color: rgba(207, 54, 54, .32); }
        .product-import-issue .panel-header { align-items: flex-start; }
        .product-import-issue-summary { display: grid; gap: 4px; }
        .product-import-issue-summary small { color: var(--muted); font-weight: 500; }
        .product-import-issue-items { display: grid; gap: 6px; min-width: 290px; }
        .product-import-issue-item { display: flex; gap: 7px; align-items: center; flex-wrap: wrap; }
        .product-import-issue-item small { color: var(--muted); }
        .product-import-issue-row { background: rgba(207, 54, 54, .055); box-shadow: inset 3px 0 0 rgba(207, 54, 54, .48); }
        .product-import-issue-badge { display: inline-flex; margin-left: 7px; border-radius: 999px; padding: 3px 7px; background: rgba(207, 54, 54, .1); color: var(--red); font-size: 10px; font-weight: 850; vertical-align: middle; }
        .product-storefront-badge, .product-stock-verification-badge { display: inline-flex; margin-left: 7px; border-radius: 999px; padding: 3px 7px; font-size: 10px; font-weight: 850; vertical-align: middle; }
        .product-storefront-badge { background: rgba(207, 54, 54, .1); color: var(--red); }
        .product-stock-verification-badge { background: #fff4e8; color: #8b5b34; }
        .product-cell { display: grid; grid-template-columns: 28px 34px 62px minmax(260px, 1fr); gap: 12px; align-items: center; white-space: normal; }
        .product-cell.variant { padding-left: 42px; grid-template-columns: 28px 34px 48px minmax(220px, 1fr); }
        .product-select-control, .product-select-spacer { width: 28px; min-height: 34px; display: grid; place-items: center; }
        .product-select-control { cursor: pointer; }
        .product-select-control input, .catalog-select-heading input { width: 18px; height: 18px; margin: 0; accent-color: var(--green-dark); cursor: pointer; }
        .catalog-select-heading { display: flex; align-items: center; gap: 10px; }
        .product-thumb-button { width: 58px; height: 72px; border: 1px solid var(--border); border-radius: 8px; padding: 0; overflow: hidden; background: #f4f1ef; cursor: pointer; display: grid; place-items: center; color: var(--muted); font-weight: 850; font-size: 11px; }
        .product-cell.variant .product-thumb-button { width: 44px; height: 56px; }
        .product-thumb-button img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .product-thumb-button:disabled { cursor: default; }
        .product-title { color: var(--text); text-decoration: none; font-size: 15px; font-weight: 850; }
        .product-title:hover { color: var(--green-dark); }
        .favorite-button { width: 34px; height: 34px; flex: 0 0 auto; display: inline-grid; place-items: center; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; color: var(--muted); cursor: pointer; font-size: 19px; line-height: 1; }
        .favorite-button.is-favorite { color: #a97955; background: #fff4e8; border-color: #d9ae81; }
        .product-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 5px; color: var(--muted); font-size: 12px; }
        .variant-toggle { margin-top: 8px; border: 1px solid rgba(134, 115, 100, .28); border-radius: 999px; padding: 5px 9px; background: var(--green-soft); color: var(--green-dark); font: inherit; font-size: 12px; font-weight: 850; cursor: pointer; }
        .variant-toggle:hover { border-color: rgba(134, 115, 100, .45); }
        .variant-row { background: rgba(134, 115, 100, .06); }
        .variant-row[hidden] { display: none; }
        .price-cell { display: grid; gap: 5px; min-width: 120px; }
        .price-cell strong { font-size: 15px; }
        .price-cell span { color: var(--muted); font-size: 12px; }
        .stock-summary { display: grid; gap: 7px; min-width: 290px; }
        .stock-pills { display: flex; gap: 7px; flex-wrap: wrap; }
        .stock-pill { border: 1px solid var(--border); border-radius: 8px; padding: 5px 8px; background: #fffdfb; color: var(--muted); font-size: 12px; }
        .stock-pill strong { color: var(--text); font-size: 14px; margin-left: 4px; }
        .stock-pill.available strong { color: var(--green-dark); }
        .warehouse-modal-trigger { width: max-content; border: 0; padding: 0; background: transparent; color: var(--green-dark); font: inherit; font-size: 12px; font-weight: 760; cursor: pointer; }
        .stock-modal { position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; padding: 24px; background: rgba(37, 31, 26, .62); }
        .stock-modal[hidden] { display: none; }
        html.stock-modal-open, html.stock-modal-open body { overflow: hidden; }
        .stock-modal-card { width: min(1060px, 96vw); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; border-radius: 9px; background: var(--surface); box-shadow: 0 24px 70px rgba(0, 0, 0, .32); white-space: normal; }
        .stock-modal-card.variant-stock-modal-card { width: min(1320px, 96vw); }
        .stock-modal-header { flex: 0 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
        .stock-modal-title { min-width: 0; display: grid; gap: 2px; }
        .stock-modal-title span { font-size: 16px; font-weight: 820; }
        .stock-modal-title small { overflow-wrap: anywhere; color: var(--muted); font-size: 12px; font-weight: 650; }
        .stock-modal-close { width: 34px; height: 34px; border: 1px solid var(--border); border-radius: 8px; background: #fff; color: var(--muted); font: inherit; font-size: 22px; cursor: pointer; }
        .stock-modal-body { min-height: 0; overflow: auto; overscroll-behavior: contain; padding: 16px; }
        .warehouse-tile-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 7px; margin-top: 8px; }
        .warehouse-tile { border: 1px solid var(--border); border-radius: 8px; padding: 8px; background: #fff; display: grid; gap: 4px; color: var(--muted); font-size: 12px; }
        .warehouse-tile strong { color: var(--text); font-size: 13px; }
        .warehouse-tile b { color: var(--green-dark); }
        .channel-badges { display: flex; gap: 6px; flex-wrap: wrap; max-width: 220px; }
        .products-table .inline-actions { align-items: stretch; }
        .products-table .inline-actions form { display: flex; }
        .products-table .inline-actions .button { min-height: 34px; min-width: 78px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; white-space: nowrap; }
        .pagination-wrapper { padding: 14px 16px; border-top: 1px solid var(--border); }
        .pagination-bar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .pagination-pages { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .pagination-page { min-width: 36px; min-height: 36px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; display: inline-flex; align-items: center; justify-content: center; color: var(--text); background: var(--surface); text-decoration: none; font-weight: 760; }
        .pagination-page.active { color: var(--green-dark); background: var(--green-soft); border-color: rgba(134, 115, 100, .34); }
        .pagination-page.disabled { opacity: .45; pointer-events: none; }
        .pagination-summary { color: var(--muted); font-size: 12px; }
        .product-bulk-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 16px; border-bottom: 1px solid var(--border); background: rgba(134, 115, 100, .055); }
        .product-bulk-selection, .product-bulk-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .product-bulk-count { min-width: 116px; color: var(--text); font-size: 13px; font-weight: 820; }
        .product-bulk-mode { color: var(--muted); font-size: 12px; }
        .product-bulk-toolbar .button { min-height: 36px; display: inline-flex; align-items: center; justify-content: center; }
        .product-bulk-modal { position: fixed; inset: 0; z-index: 120; display: grid; place-items: center; padding: 24px; background: rgba(37, 31, 26, .68); }
        .product-bulk-modal[hidden] { display: none; }
        html.product-bulk-modal-open, html.product-bulk-modal-open body { overflow: hidden; }
        .product-bulk-modal-card { width: min(1080px, 97vw); max-height: 94vh; overflow: hidden; border-radius: 11px; background: var(--surface); box-shadow: 0 28px 80px rgba(0, 0, 0, .35); }
        .product-bulk-modal-card form { max-height: 94vh; display: flex; flex-direction: column; }
        .product-bulk-modal-header, .product-bulk-modal-footer { flex: 0 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 14px 18px; }
        .product-bulk-modal-header { border-bottom: 1px solid var(--border); }
        .product-bulk-modal-header h2 { margin: 0; font-size: 19px; }
        .product-bulk-modal-header p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
        .product-bulk-modal-close { width: 38px; height: 38px; flex: 0 0 38px; border: 1px solid var(--border); border-radius: 8px; background: #fff; color: var(--muted); font: inherit; font-size: 24px; cursor: pointer; }
        .product-bulk-modal-body { min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 16px 18px 20px; }
        .product-bulk-help { margin-bottom: 14px; border-radius: 8px; padding: 10px 12px; background: var(--green-soft); color: var(--green-dark); font-size: 12px; line-height: 1.5; }
        .product-bulk-errors { margin-bottom: 14px; }
        .product-bulk-errors ul { margin: 7px 0 0; padding-left: 20px; }
        .product-bulk-section { display: grid; gap: 10px; }
        .product-bulk-section + .product-bulk-section { margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--border); }
        .product-bulk-section h3 { margin: 0; font-size: 15px; }
        .product-bulk-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .product-bulk-field { min-width: 0; display: grid; align-content: start; gap: 8px; border: 1px solid var(--border); border-radius: 9px; padding: 11px; background: #fffdfb; }
        .product-bulk-field.wide { grid-column: 1 / -1; }
        .product-bulk-field > label:not(.product-bulk-apply) { display: grid; gap: 5px; color: var(--muted); font-size: 12px; }
        .product-bulk-field input:not([type="checkbox"]), .product-bulk-field select { width: 100%; min-width: 0; }
        .product-bulk-field small { color: var(--muted); font-size: 11px; line-height: 1.4; }
        .product-bulk-apply { display: flex; align-items: center; gap: 7px; color: var(--text); font-size: 13px; font-weight: 820; cursor: pointer; }
        .product-bulk-apply input { width: 17px; height: 17px; margin: 0; accent-color: var(--green-dark); }
        .product-bulk-category-list { max-height: 190px; overflow-y: auto; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 5px; margin: 0; border: 0; padding: 0; }
        .product-bulk-category-list label { min-width: 0; display: flex; align-items: flex-start; gap: 8px; border-radius: 7px; padding: 7px 8px; background: var(--surface-soft); cursor: pointer; }
        .product-bulk-category-list label span { min-width: 0; display: grid; gap: 2px; }
        .product-bulk-category-list label strong { overflow-wrap: anywhere; font-size: 12px; }
        .product-bulk-category-list label small { overflow-wrap: anywhere; }
        .product-bulk-category-list:disabled, .product-bulk-field [data-product-bulk-value]:disabled { opacity: .55; cursor: not-allowed; }
        .product-bulk-modal-footer { border-top: 1px solid var(--border); background: var(--surface-soft); }
        .product-bulk-modal-footer > span { color: var(--muted); font-size: 11px; }
        .image-modal { position: fixed; inset: 0; z-index: 90; display: none; align-items: center; justify-content: center; padding: 24px; background: rgba(37, 31, 26, .72); }
        .image-modal.open { display: flex; }
        .image-modal-card { max-width: min(760px, 94vw); max-height: 92vh; background: var(--surface); border-radius: 8px; overflow: hidden; box-shadow: 0 24px 70px rgba(0, 0, 0, .32); }
        .image-modal-header { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 10px 12px; border-bottom: 1px solid var(--border); font-weight: 780; }
        .image-modal-close { border: 0; background: transparent; font: inherit; font-size: 22px; cursor: pointer; color: var(--muted); }
        .image-modal img { display: block; width: 100%; max-height: 78vh; object-fit: contain; background: #f4f1ef; }
        .product-drawer { width: min(780px, 96vw); }
        .product-create-form { padding: 0; }
        .drawer-step-nav { display: flex; gap: 8px; flex-wrap: wrap; padding: 14px 16px 0; }
        .drawer-step-nav button { border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; color: var(--text); background: var(--surface); font: inherit; font-weight: 760; cursor: pointer; }
        .drawer-step-nav button.active { color: var(--green-dark); background: var(--green-soft); border-color: rgba(134, 115, 100, .34); }
        .drawer-step[hidden] { display: none; }
        .drawer-step { padding: 16px; display: grid; gap: 16px; }
        .drawer-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .drawer-form-grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .drawer-form-grid .wide { grid-column: 1 / -1; }
        .toggle-row { display: flex; gap: 8px; align-items: center; color: var(--text); font-weight: 760; }
        .drawer-table input { min-width: 150px; }
        .drawer-step-actions { padding: 0 16px 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .drawer-step-actions .inline-actions { justify-content: flex-end; }
        .media-upload-panel { border: 1px dashed rgba(134, 115, 100, .34); border-radius: 8px; padding: 16px; background: #fffdfb; display: grid; gap: 12px; }
        .product-category-checklist { max-height: 224px; overflow: auto; display: grid; gap: 6px; padding: 8px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
        .product-category-check { display: flex; grid-template-columns: auto minmax(0, 1fr); align-items: start; gap: 9px; padding: 7px 8px; border-radius: 6px; color: var(--text); cursor: pointer; }
        .product-category-check:hover { background: var(--green-soft); }
        .product-category-check input { margin-top: 3px; }
        .product-category-check span { display: grid; gap: 1px; min-width: 0; }
        .product-category-check strong { font-size: 13px; }
        .product-category-check small { overflow: hidden; color: var(--muted); font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
        .product-category-check em { color: var(--green-dark); font-size: 11px; font-style: normal; }
        .product-category-help { color: var(--muted); font-size: 12px; font-weight: 500; }
        .pim-ready-help { color: var(--muted); font-size: 12px; font-weight: 500; }
        textarea.product-html { min-height: 150px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; line-height: 1.45; }
        .button[disabled] { opacity: .55; cursor: not-allowed; }
        @media (max-width: 1400px) {
            .product-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 900px) {
            .products-table { overflow: visible; }
            .products-table > .panel-header { min-height: 0; display: grid; gap: 2px; padding: 11px 14px; }
            .products-table > .panel-header span:first-child { color: var(--muted); font-size: 12px; }
            .products-table > .panel-header span:last-child { font-size: 14px; }
            .products-table > [data-product-list-scroll] { overflow: visible; }
            .products-table [data-product-list-table] { position: relative; width: 100%; min-width: 0; display: block; }
            .products-table [data-product-list-table] > thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }
            .products-table [data-product-list-table] > tbody { display: grid; gap: 12px; padding: 10px; background: var(--surface-soft); }
            .products-table [data-product-list-table] > tbody > tr { width: 100%; min-width: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-areas: "identity identity" "price channels" "stock stock" "actions actions"; border: 1px solid var(--border); border-radius: 11px; background: var(--surface); box-shadow: 0 5px 16px rgba(67, 53, 43, .07); }
            .products-table [data-product-list-table] > tbody > tr[hidden] { display: none !important; }
            .products-table [data-product-list-table] > tbody > tr.product-import-issue-row { border-color: rgba(207, 54, 54, .34); background: #fffafa; box-shadow: inset 3px 0 0 rgba(207, 54, 54, .48), 0 5px 16px rgba(67, 53, 43, .07); }
            .products-table [data-product-list-table] > tbody > tr.variant-row { width: calc(100% - 12px); margin-left: 12px; border-left: 3px solid rgba(134, 115, 100, .38); background: #fbf9f7; }
            .products-table [data-product-list-table] > tbody > tr > td { width: auto !important; min-width: 0; display: block; padding: 0; border: 0; white-space: normal; }
            .products-table [data-product-list-table] > tbody > tr > td[colspan] { grid-column: 1 / -1; padding: 14px; }
            .products-table [data-product-card-section="identity"] { grid-area: identity; padding: 12px !important; border-bottom: 1px solid var(--border) !important; }
            .products-table [data-product-card-section="price"] { grid-area: price; padding: 10px 12px !important; background: rgba(134, 115, 100, .055); border-right: 1px solid var(--border) !important; border-bottom: 1px solid var(--border) !important; }
            .products-table [data-product-card-section="channels"] { grid-area: channels; padding: 10px 12px !important; background: rgba(134, 115, 100, .055); border-bottom: 1px solid var(--border) !important; }
            .products-table [data-product-card-section="stock"] { grid-area: stock; padding: 12px !important; border-bottom: 1px solid var(--border) !important; }
            .products-table [data-product-card-section="actions"] { grid-area: actions; padding: 12px !important; }
            .products-table [data-product-card-section]:not([data-product-card-section="identity"])::before { display: block; margin-bottom: 6px; color: var(--muted); font-size: 10px; font-weight: 820; letter-spacing: .04em; text-transform: uppercase; }
            .products-table [data-product-card-section="price"]::before { content: "Cena / VAT"; }
            .products-table [data-product-card-section="channels"]::before { content: "Kanały"; }
            .products-table [data-product-card="parent"] [data-product-card-section="stock"]::before { content: "Stan rodziny"; }
            .products-table [data-product-card="variant"] [data-product-card-section="stock"]::before { content: "Stan wariantu"; }
            .products-table [data-product-card-section="actions"]::before { content: "Akcje"; }
            .products-table [data-product-card="variant"] [data-product-card-section="identity"]::before { content: "Wariant"; display: inline-flex; margin-bottom: 8px; border-radius: 999px; padding: 3px 7px; background: var(--green-soft); color: var(--green-dark); font-size: 10px; font-weight: 850; letter-spacing: .03em; text-transform: uppercase; }
            .products-table .product-cell, .products-table .product-cell.variant { grid-template-columns: 32px 44px 58px minmax(0, 1fr); gap: 8px; padding-left: 0; align-items: start; }
            .products-table .product-cell.variant { grid-template-columns: 32px 44px 48px minmax(0, 1fr); }
            .products-table .product-select-control, .products-table .product-select-spacer { width: 32px; min-height: 44px; }
            .products-table .product-cell > :last-child { min-width: 0; }
            .products-table .favorite-button { width: 44px; height: 44px; }
            .products-table .product-title { display: block; overflow-wrap: anywhere; line-height: 1.35; }
            .products-table .product-meta { min-width: 0; display: grid; grid-template-columns: minmax(0, 1fr); gap: 2px; }
            .products-table .product-meta span { min-width: 0; overflow-wrap: anywhere; white-space: normal; }
            .products-table .variant-toggle { min-height: 40px; }
            .products-table .price-cell { min-width: 0; gap: 1px; }
            .products-table .channel-badges { min-width: 0; max-width: none; align-content: start; }
            .products-table .channel-badges .status { white-space: normal; }
            .products-table [data-product-card-section="stock"] > .stock-summary { min-width: 0; gap: 9px; }
            .products-table [data-product-card-section="stock"] > .stock-summary > .stock-pills { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
            .products-table [data-product-card-section="stock"] > .stock-summary > .stock-pills > .stock-pill { min-width: 0; display: grid; align-content: center; gap: 2px; padding: 7px 4px; text-align: center; line-height: 1.2; }
            .products-table [data-product-card-section="stock"] > .stock-summary > .stock-pills > .stock-pill strong { margin-left: 0; font-size: 15px; }
            .products-table .warehouse-modal-trigger { width: 100%; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(134, 115, 100, .34); border-radius: 8px; padding: 8px 10px; background: var(--green-soft); }
            .products-table [data-product-card-section="actions"] .inline-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
            .products-table [data-product-card="variant"] [data-product-card-section="actions"] .inline-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .products-table [data-product-card-section="actions"] .inline-actions form { width: 100%; display: block; }
            .products-table [data-product-card-section="actions"] .inline-actions .button { width: 100%; min-width: 0; min-height: 44px; padding-inline: 6px; }
            .product-import-issue-items { min-width: 0; }
            .product-bulk-toolbar { align-items: stretch; flex-direction: column; padding: 10px; }
            .product-bulk-selection, .product-bulk-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .product-bulk-count, .product-bulk-mode { grid-column: 1 / -1; }
            .product-bulk-toolbar .button { width: 100%; min-height: 42px; white-space: normal; }
        }
        @media (max-width: 900px) {
            .product-mobile-filter-trigger { display: inline-flex; margin-bottom: 12px; }
            .product-mobile-filter-backdrop { position: fixed; inset: 0; z-index: 80; background: rgba(37, 31, 26, .42); }
            .product-filter-panel { padding: 0; border: 0; background: transparent; box-shadow: none; }
            .product-filter-drawer { position: fixed; top: 0; right: 0; z-index: 90; width: min(420px, 94vw); height: 100dvh; overflow-y: auto; padding: 14px; border-left: 1px solid var(--border); background: var(--surface); box-shadow: -24px 0 48px rgba(37, 31, 26, .18); transform: translateX(105%); transition: transform .18s ease; }
            .product-filter-drawer-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; font-weight: 850; }
            .product-filter-drawer-close { width: 34px; height: 34px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 8px; color: var(--muted); font-size: 22px; cursor: pointer; }
            .product-filters { grid-template-columns: 1fr; }
            #product-mobile-filter-toggle:checked ~ .product-mobile-filter-backdrop { display: block; }
            #product-mobile-filter-toggle:checked ~ .product-filter-panel .product-filter-drawer { transform: translateX(0); }
            .drawer-form-grid, .drawer-form-grid.three { grid-template-columns: 1fr; }
            .drawer-step-actions { align-items: stretch; flex-direction: column; }
            .stock-modal { align-items: end; padding: max(8px, env(safe-area-inset-top)) 0 0; }
            .stock-modal-card { width: 100%; max-height: calc(100dvh - 8px); border-radius: 14px 14px 0 0; }
            .stock-modal-header { align-items: flex-start; padding: 12px 12px 10px 14px; }
            .stock-modal-title span { font-size: 15px; }
            .stock-modal-title small { font-size: 13px; }
            .stock-modal-close { width: 44px; height: 44px; flex: 0 0 44px; margin-top: -2px; font-size: 25px; }
            .stock-modal-body { overflow-x: hidden; padding: 12px 12px max(16px, env(safe-area-inset-bottom)); }
            .stock-modal-body .toolbar-note { margin-bottom: 10px; }
            .stock-modal-body .stock-readonly-panel { gap: 10px; padding: 0; border: 0; background: transparent; }
            .stock-modal-body .stock-readonly-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
            .stock-modal-body .stock-readonly-summary .stock-pill { min-width: 0; display: grid; align-content: center; gap: 2px; padding: 7px 4px; text-align: center; line-height: 1.2; }
            .stock-modal-body .stock-readonly-summary .stock-pill strong { margin-left: 0; font-size: 16px; }
            .stock-modal-body .stock-readonly-note { padding: 9px 10px; border-radius: 8px; background: var(--green-soft); font-size: 12px; line-height: 1.45; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-table-scroll { width: 100%; overflow: visible; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-table { width: 100%; min-width: 0; display: block; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-table thead { display: none; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-table tbody { display: grid; gap: 10px; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-table tr { width: 100%; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px 8px; padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: #fffdfb; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-table td { min-width: 0; display: block; padding: 0; border: 0; text-align: left; white-space: normal; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-table td::before { content: attr(data-stock-label); display: block; margin-bottom: 3px; color: var(--muted); font-size: 11px; font-weight: 700; line-height: 1.2; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-table td[colspan] { grid-column: 1 / -1; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-warehouse { grid-column: 1 / -1; padding-bottom: 10px !important; border-bottom: 1px solid var(--border) !important; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-warehouse strong { display: block; margin-bottom: 2px; font-size: 14px; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-metric { font-size: 16px; font-weight: 820; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-field { grid-column: 1 / -1; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-field input { width: 100%; min-width: 0; min-height: 48px; font-size: 16px; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-field .stock-adjust-error { min-height: 0; margin-top: 4px; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-action { grid-column: 1 / -1; }
            .stock-modal-body .stock-readonly-panel .stock-adjust-action .button { width: 100%; min-height: 48px; font-size: 14px; }
            .product-bulk-modal { align-items: end; padding: max(8px, env(safe-area-inset-top)) 0 0; }
            .product-bulk-modal-card, .product-bulk-modal-card form { width: 100%; max-height: calc(100dvh - 8px); border-radius: 14px 14px 0 0; }
            .product-bulk-modal-header, .product-bulk-modal-footer { padding: 12px; }
            .product-bulk-modal-footer { align-items: stretch; flex-direction: column; padding-bottom: max(12px, env(safe-area-inset-bottom)); }
            .product-bulk-modal-footer .inline-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .product-bulk-modal-footer .button { width: 100%; min-height: 44px; }
            .product-bulk-modal-body { padding: 12px; }
            .product-bulk-grid, .product-bulk-category-list { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    @php
        $qty = function ($value, $product = null): string {
            $number = (float) $value;
            $precision = max(0, min(4, (int) ($product?->quantity_precision ?? 0)));

            if ($precision === 0 || abs($number - round($number)) < 0.00001) {
                return number_format($number, 0, ',', ' ');
            }

            return rtrim(rtrim(number_format($number, $precision, ',', ' '), '0'), ',');
        };
        $percent = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ',') . '%';
        $money = function ($value): string {
            if ($value === null || $value === '') {
                return '-';
            }

            return number_format((float) $value, 2, ',', ' ') . ' PLN';
        };
        $retailPrice = function ($product, $variants) {
            $ownPrice = data_get($product->masterData(), 'prices.retail_price_pln');

            if ($ownPrice !== null && $ownPrice !== '') {
                return $ownPrice;
            }

            return $variants
                ->map(fn ($variant) => data_get($variant->masterData(), 'prices.retail_price_pln'))
                ->first(fn ($price) => $price !== null && $price !== '');
        };
        $stockTotals = function ($products): array {
            $balances = collect($products)->flatMap(fn ($product) => $product->stockBalances);

            return [
                'on_hand' => $balances->sum(fn ($balance) => (float) $balance->quantity_on_hand),
                'reserved' => $balances->sum(fn ($balance) => (float) $balance->quantity_reserved),
                'available' => $balances->sum(fn ($balance) => (float) $balance->quantity_available),
            ];
        };
        $channels = function ($products) {
            return collect($products)
                ->flatMap(fn ($product) => $product->channelMappings)
                ->map(fn ($mapping) => $mapping->salesChannel?->code)
                ->filter()
                ->unique()
                ->values();
        };

        $createParameterRows = collect((array) old('parameters.name', []))
            ->map(fn ($name, $index): array => [
                'name' => (string) $name,
                'value' => (string) old("parameters.value.{$index}", ''),
                'variation' => filter_var(old("parameters.variation.{$index}", false), FILTER_VALIDATE_BOOLEAN),
            ])
            ->all();

        while (count($createParameterRows) < 4) {
            $createParameterRows[] = ['name' => '', 'value' => '', 'variation' => false];
        }

        $createSelectedCategoryIds = collect(old('category_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->all();
    @endphp

    @if ($importIssue)
        <article class="card product-import-issue">
            <div class="panel-header">
                <div class="product-import-issue-summary">
                    <strong>Produkty wymagające sprawdzenia po imporcie</strong>
                    <small>
                        Log #{{ $importIssue['log_id'] }}
                        @if ($importIssue['channel_code'])
                            · kanał {{ $importIssue['channel_code'] }}
                        @endif
                        @if ($importIssue['created_at'])
                            · {{ $importIssue['created_at']->format('Y-m-d H:i:s') }}
                        @endif
                    </small>
                </div>
                <a class="button secondary" href="{{ route('products.index') }}">Wróć do wszystkich produktów</a>
            </div>
            @if ($importIssue['uses_current_mappings'])
                <div class="toolbar-note">
                    Ten starszy log nie zawierał jeszcze listy pozycji. Poniżej pokazujemy aktualne duplikaty SKU odtworzone z mapowań kanału, osobno dla produktów i wariantów.
                </div>
            @endif
            @if ($importIssue['groups'] !== [])
                <div class="table-scroll">
                    <table class="dense-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Rodzaj</th>
                                <th>Wystąpienia</th>
                                <th>Pozycje WooCommerce</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($importIssue['groups'] as $group)
                                <tr>
                                    <td><strong>{{ $group['sku'] }}</strong></td>
                                    <td>{{ $group['entity_kind'] === 'variation' ? 'Wariant' : 'Produkt' }}</td>
                                    <td>{{ $group['occurrences'] }}</td>
                                    <td>
                                        <div class="product-import-issue-items">
                                            @forelse ($group['items'] as $item)
                                                <div class="product-import-issue-item">
                                                    <span>{{ $item['name'] ?: 'Pozycja WooCommerce' }}</span>
                                                    <small>
                                                        ID {{ $item['woo_product_id'] ?: '—' }}
                                                        @if ($item['woo_variation_id'])
                                                            / wariant {{ $item['woo_variation_id'] }}
                                                        @endif
                                                        @if ($item['language'])
                                                            · {{ mb_strtoupper($item['language']) }}
                                                        @endif
                                                    </small>
                                                    @if ($item['erp_product_id'])
                                                        <a href="{{ route('products.edit', $item['erp_product_id']) }}">Otwórz w ERP</a>
                                                    @endif
                                                    @if ($item['permalink'])
                                                        <a href="{{ $item['permalink'] }}" target="_blank" rel="noopener noreferrer">Otwórz w WooCommerce</a>
                                                    @endif
                                                </div>
                                            @empty
                                                <span class="muted">Brak danych pozycji w tym logu.</span>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="empty-state">
                    Nie znaleziono już aktualnych duplikatów w mapowaniach kanału. Uruchom ponownie import, aby zapisać nową listę diagnostyczną.
                </div>
            @endif
        </article>
    @endif

    <input id="product-drawer" class="drawer-toggle" type="checkbox" @checked($errors->any())>

    <div class="page-toolbar">
        <div class="toolbar-note">
            {{ $isFavorites ? 'Pokazujemy tylko oznaczone gwiazdką produkty i ich warianty.' : 'Pokazujemy wyłącznie polskie produkty główne. Warianty są pod przyciskiem „Warianty”.' }}
        </div>
        @if (! $isFavorites)
            <label class="button" for="product-drawer">Dodaj produkt</label>
        @endif
    </div>

    <input id="product-mobile-filter-toggle" class="product-mobile-filter-toggle" type="checkbox">
    <label class="button secondary product-mobile-filter-trigger" for="product-mobile-filter-toggle">Filtry i wyszukiwanie</label>
    <label class="product-mobile-filter-backdrop" for="product-mobile-filter-toggle"></label>

    <article class="card product-filter-panel">
        <div class="product-filter-drawer">
            <div class="product-filter-drawer-header">
                <span>Filtry produktów</span>
                <label class="product-filter-drawer-close" for="product-mobile-filter-toggle" aria-label="Zamknij filtry">×</label>
            </div>
            <form class="product-filters" method="GET" action="{{ route('products.index') }}" data-product-filters>
            @if ($importIssue)
                <input type="hidden" name="import_issue" value="{{ $importIssue['log_id'] }}">
            @endif
            <label>Szybkie wyszukiwanie
                <input
                    name="q"
                    type="search"
                    value="{{ $filters['q'] }}"
                    placeholder="Nazwa, SKU, EAN, parametr, opis..."
                    autocomplete="off"
                    data-product-search
                >
            </label>
            <label>Kanał
                <select name="channel" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    @foreach ($channelOptions as $channelOption)
                        <option value="{{ $channelOption }}" @selected($filters['channel'] === $channelOption)>{{ $channelOption }}</option>
                    @endforeach
                </select>
            </label>
            <label>Magazyn
                <select name="warehouse" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    @foreach ($warehouseOptions as $warehouseOption)
                        <option value="{{ $warehouseOption->id }}" @selected($filters['warehouse'] === (string) $warehouseOption->id)>{{ $warehouseOption->code }} - {{ $warehouseOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Stan
                <select name="stock" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    <option value="available" @selected($filters['stock'] === 'available')>Dostępne</option>
                    <option value="reserved" @selected($filters['stock'] === 'reserved')>Z rezerwacją</option>
                    <option value="out_of_stock" @selected($filters['stock'] === 'out_of_stock')>Brak na stanie</option>
                    <option value="no_stock" @selected($filters['stock'] === 'no_stock')>Bez ruchu/stanu</option>
                </select>
            </label>
            <label>Typ
                <select name="type" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    <option value="with_variants" @selected($filters['type'] === 'with_variants')>Z wariantami</option>
                    <option value="without_variants" @selected($filters['type'] === 'without_variants')>Bez wariantów</option>
                </select>
            </label>
            <label>Status
                <select name="status" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    <option value="active" @selected($filters['status'] === 'active')>Aktywne</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Nieaktywne</option>
                    <option value="publish" @selected($filters['status'] === 'publish')>Opublikowane Woo</option>
                    <option value="draft" @selected($filters['status'] === 'draft')>Szkice Woo</option>
                </select>
            </label>
            <label>Kategoria
                <input name="category" list="product-filter-category-options" value="{{ $filters['category'] }}" placeholder="Dowolna">
            </label>
            <button class="button secondary" type="submit">Filtruj</button>
            <a class="button secondary" href="{{ $isFavorites ? route('products.favorites') : route('products.index') }}">Wyczyść</a>
            </form>
        </div>
        <div class="filter-result-line">
            Wynik: {{ $productRows->total() }} {{ $importIssue ? 'rodzin produktów dotkniętych błędem importu' : ($isFavorites ? 'ulubionych produktów głównych' : 'polskich produktów głównych') }} po filtrach / {{ $productsCount }} SKU w systemie.
            Wyszukiwarka działa po nazwie, SKU, EAN, kategorii, parametrach i opisach.
        </div>
    </article>

    <label class="drawer-backdrop" for="product-drawer"></label>
    <aside class="drawer-panel product-drawer" aria-label="Dodaj produkt">
        <div class="drawer-header">
            <div class="drawer-title">Dodaj produkt</div>
            <label class="drawer-close" for="product-drawer">&times;</label>
        </div>
        <form class="product-create-form" method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" data-product-create-form>
            @csrf
            <input type="hidden" name="producer" value="SEMPRE">
            <nav class="drawer-step-nav" aria-label="Kroki dodawania produktu">
                <button class="active" type="button" data-create-tab="produkt" aria-selected="true">Produkt</button>
                <button type="button" data-create-tab="sprzedaz" aria-selected="false">Sprzedaż i magazyn</button>
                <button type="button" data-create-tab="informacje" aria-selected="false">Informacje</button>
                <button type="button" data-create-tab="media" aria-selected="false">Media</button>
            </nav>

            <section class="drawer-step" data-create-step="produkt">
                <div class="drawer-form-grid">
                    <label class="wide">Nazwa produktu (PL)
                        <input name="name" value="{{ old('name') }}" required>
                    </label>
                    <label>Katalog
                        <select name="catalog">
                            @foreach ($catalogOptions as $catalog)
                                <option value="{{ $catalog }}" @selected(old('catalog', 'Domyślny') === $catalog)>{{ $catalog }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Kategorie produktu
                        @include('products._category_checkbox_list', [
                            'categoryOptions' => $categoryOptions,
                            'selectedCategoryIds' => $createSelectedCategoryIds,
                        ])
                    </label>
                    <label>Tagi
                        <input name="tags" value="{{ old('tags') }}" placeholder="tag 1, tag 2">
                    </label>
                    <div class="toolbar-note wide">Producent jest ustawiany automatycznie jako SEMPRE.</div>
                </div>

                <div class="drawer-form-grid three">
                    <label>SKU
                        <input name="sku" value="{{ old('sku') }}" placeholder="Automatycznie po zapisie">
                    </label>
                    <label>EAN
                        <input name="ean" value="{{ old('ean') }}" placeholder="Automatycznie z GS1">
                    </label>
                    <label>ASIN
                        <input name="asin" value="{{ old('asin') }}">
                    </label>
                </div>

                <div class="drawer-form-grid three">
                    <label>Waga (kg)
                        <input name="weight_kg" type="number" step="0.0001" min="0" value="{{ old('weight_kg') }}">
                    </label>
                    <label>Wysokość (cm)
                        <input name="height_cm" type="number" step="0.01" min="0" value="{{ old('height_cm') }}">
                    </label>
                    <label>Szerokość (cm)
                        <input name="width_cm" type="number" step="0.01" min="0" value="{{ old('width_cm') }}">
                    </label>
                    <label>Długość (cm)
                        <input name="length_cm" type="number" step="0.01" min="0" value="{{ old('length_cm') }}">
                    </label>
                    <label>Jednostka
                        <input name="unit" value="{{ old('unit', 'szt') }}" required maxlength="16">
                    </label>
                    <label>Status
                        <input type="hidden" name="is_active" value="0">
                        <span class="toggle-row"><input name="is_active" type="checkbox" value="1" @checked(old('is_active', '1'))> Aktywny</span>
                    </label>
                    <label>Status publikacji w sklepie
                        <select name="publication_status">
                            @foreach (['publish' => 'Opublikowany', 'draft' => 'Szkic', 'pending' => 'Oczekujący', 'private' => 'Prywatny'] as $status => $label)
                                <option value="{{ $status }}" @selected(old('publication_status', 'publish') === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Data publikacji w sklepie
                        <input name="publication_date" type="datetime-local" value="{{ old('publication_date') }}">
                    </label>
                    <label>Widoczność w WooCommerce
                        <select name="catalog_visibility">
                            @foreach (['visible' => 'Widoczny w katalogu i wyszukiwarce', 'catalog' => 'Widoczny tylko w katalogu', 'search' => 'Widoczny tylko w wyszukiwarce', 'hidden' => 'Ukryty w sklepie'] as $visibility => $label)
                                <option value="{{ $visibility }}" @selected(old('catalog_visibility', 'visible') === $visibility)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Typ produktu
                        <select name="product_type">
                            @foreach (['simple' => 'Prosty', 'variable' => 'Wariantowy', 'variation' => 'Wariant'] as $type => $label)
                                <option value="{{ $type }}" @selected(old('product_type', 'simple') === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Atrybut wariantu
                        @include('products._variant_attribute_select', [
                            'parameterOptions' => $parameterOptions,
                            'value' => old('variant_attribute'),
                        ])
                    </label>
                    <div class="wide">
                        @include('products._new_variant_values_fields', [
                            'variantProduct' => null,
                            'selectedVariantAttribute' => old('variant_attribute'),
                        ])
                    </div>
                    <label>Kompletność danych PIM
                        <input type="hidden" name="developed" value="0">
                        <span class="toggle-row"><input name="developed" type="checkbox" value="1" @checked(old('developed'))> Dane gotowe do publikacji</span>
                        <small class="pim-ready-help">Flaga robocza: potwierdza sprawdzenie danych (nazwa, kategorie, ceny, opisy i zdjęcia). Sama nie publikuje produktu.</small>
                    </label>
                </div>
            </section>

            <section class="drawer-step" data-create-step="sprzedaz" hidden>
                <div class="drawer-form-grid three">
                    <label>Cena hurt (PLN)
                        <input name="wholesale_price_pln" type="number" step="0.01" min="0" value="{{ old('wholesale_price_pln') }}">
                    </label>
                    <label>Cena detal brutto (PLN)
                        <input name="retail_price_pln" type="number" step="0.01" min="0" value="{{ old('retail_price_pln') }}">
                    </label>
                    <label>Cena promocyjna brutto (PLN)
                        <input name="sale_price_pln" type="number" step="0.01" min="0" value="{{ old('sale_price_pln') }}">
                    </label>
                    <label>Promocja od
                        <input name="sale_price_starts_at" type="date" value="{{ old('sale_price_starts_at') }}">
                    </label>
                    <label>Promocja do
                        <input name="sale_price_ends_at" type="date" value="{{ old('sale_price_ends_at') }}">
                    </label>
                    <label>VAT %
                        <select name="vat_rate" required>
                            @foreach ([23, 8, 5, 0] as $rate)
                                <option value="{{ $rate }}" @selected((float) old('vat_rate', 23) === (float) $rate)>{{ $rate }}%</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Lokalizacja
                        <input name="warehouse_location" value="{{ old('warehouse_location') }}" placeholder="np. A-01-03">
                    </label>
                    <label>Cena zakupu (średnia)
                        <input name="purchase_price_pln" type="number" step="0.01" min="0" value="{{ old('purchase_price_pln') }}">
                    </label>
                    <label>Koszt dodatkowy (PLN)
                        <input name="extra_cost_pln" type="number" step="0.01" min="0" value="{{ old('extra_cost_pln') }}">
                    </label>
                    <label>Zamówienia oczekujące
                        <select name="backorders">
                            @foreach (['no' => 'Nie zezwalaj', 'notify' => 'Zezwalaj i informuj', 'yes' => 'Zezwalaj'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('backorders', 'no') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Niski próg stanu
                        <input name="low_stock_amount" type="number" step="1" min="0" value="{{ old('low_stock_amount') }}">
                    </label>
                    <label class="toggle-row"><input type="hidden" name="manage_stock" value="0"><input name="manage_stock" type="checkbox" value="1" @checked(old('manage_stock', true))> Zarządzaj stanem</label>
                    <label class="toggle-row"><input name="sold_individually" type="checkbox" value="1" @checked(old('sold_individually'))> Sprzedawany pojedynczo</label>
                    <div class="toolbar-note">Stany magazynowe produktu powstaną z dokumentów magazynowych po zapisaniu produktu.</div>
                </div>
                @include('products._supplier_fields', ['supplierMaster' => []])
            </section>

            <section class="drawer-step" data-create-step="informacje" hidden>
                <div class="drawer-form-grid">
                    <label>Nazwa produktu (EN)
                        <input name="name_en" value="{{ old('name_en') }}">
                    </label>
                    <label>Custom label (PL)
                        <input name="custom_label_pl" value="{{ old('custom_label_pl') }}">
                    </label>
                    <label>Custom label (EN)
                        <input name="custom_label_en" value="{{ old('custom_label_en') }}">
                    </label>
                    <label>Tło etykiety
                        <input name="custom_label_bg_color" type="color" value="{{ old('custom_label_bg_color', '#111111') }}">
                    </label>
                    <label>Tekst etykiety
                        <input name="custom_label_text_color" type="color" value="{{ old('custom_label_text_color', '#ffffff') }}">
                    </label>
                    <label>Dni kalendarzowe do wysyłki
                        <input name="lemon_shipping_days" type="number" step="1" min="0" value="{{ old('lemon_shipping_days') }}" placeholder="np. 11">
                    </label>
                    <label>Tekst terminu wysyłki (PL)
                        <input name="lemon_shipping_text" value="{{ old('lemon_shipping_text') }}" placeholder="Planowana wysyłka: {date}">
                        <small>Znaczniki: <code>{date}</code>, <code>{days}</code>.</small>
                    </label>
                    <label>Tekst terminu wysyłki (EN)
                        <input name="lemon_shipping_text_en" value="{{ old('lemon_shipping_text_en') }}" placeholder="Planned shipping: {date}">
                        <small>Znaczniki: <code>{date}</code>, <code>{days}</code>.</small>
                    </label>
                    <label class="toggle-row"><input type="hidden" name="lemon_preorder" value="0"><input name="lemon_preorder" type="checkbox" value="1" @checked(old('lemon_preorder'))> Przedsprzedaż</label>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Opis PL HTML</div>
                    <textarea class="product-html" name="description_pl" data-rich-product-editor>{{ old('description_pl') }}</textarea>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Opis EN HTML</div>
                    <textarea class="product-html" name="description_en" data-rich-product-editor>{{ old('description_en') }}</textarea>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Krótki opis PL HTML</div>
                    <textarea class="product-html" name="short_description_pl" data-rich-product-editor>{{ old('short_description_pl', old('additional_description_pl')) }}</textarea>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Krótki opis EN HTML</div>
                    <textarea class="product-html" name="short_description_en" data-rich-product-editor>{{ old('short_description_en') }}</textarea>
                </div>
                <div class="drawer-form-grid">
                    <label>Produkty sprzedaży dodatkowej (SKU)
                        <textarea name="related_upsell_skus" placeholder="Jedno SKU w wierszu">{{ old('related_upsell_skus', '') }}</textarea>
                    </label>
                    <label>Produkty sprzedaży krzyżowej (SKU)
                        <textarea name="related_cross_sell_skus" placeholder="Jedno SKU w wierszu">{{ old('related_cross_sell_skus', '') }}</textarea>
                    </label>
                </div>
                @include('products._relation_sku_pickers')

                <div class="table-scroll drawer-table">
                    <table class="dense-table">
                        <thead>
                            <tr>
                                <th>Nazwa parametru</th>
                                <th>Wartość</th>
                                <th>Wariant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($createParameterRows as $index => $row)
                                <tr>
                                    <td><input name="parameters[name][]" value="{{ $row['name'] }}" list="product-parameter-name-options" placeholder="np. Rozmiar"></td>
                                    <td><input name="parameters[value][]" value="{{ $row['value'] }}" list="product-parameter-value-options" placeholder="np. One size"></td>
                                    <td>
                                        <input type="hidden" name="parameters[variation][{{ $index }}]" value="0">
                                        <label class="toggle-row"><input name="parameters[variation][{{ $index }}]" type="checkbox" value="1" @checked($row['variation'])> Tak</label>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="drawer-step" data-create-step="media" hidden>
                <div class="media-upload-panel">
                    <label>Dodaj zdjęcia z komputera
                        <input name="new_media[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                    </label>
                    <label>Alt dla nowych zdjęć
                        <input name="new_media_alt" value="{{ old('new_media_alt') }}" placeholder="Nazwa produktu">
                    </label>
                    <div class="toolbar-note">Zdjęcia są zapisywane na serwerze ERP w katalogu produktu.</div>
                </div>
            </section>

            <div class="drawer-step-actions">
                <button class="button secondary" type="button" data-create-prev disabled>Wstecz</button>
                <div class="inline-actions">
                    <button class="button secondary" type="button" data-create-next>Dalej</button>
                    <button class="button" type="submit">Zapisz produkt</button>
                </div>
            </div>
        </form>
    </aside>

    <article class="card products-table" data-product-list>
        <div class="panel-header">
            <span>Produkty w systemie</span>
            <span>{{ $productRows->total() }} produktów głównych / {{ $productsCount }} SKU</span>
        </div>
        <div class="product-bulk-toolbar" data-product-bulk-toolbar>
            <div class="product-bulk-selection">
                <strong class="product-bulk-count" data-product-selected-count>Wybrano: 0</strong>
                <button class="button secondary" type="button" data-product-select-page>Zaznacz wszystkie na tej stronie</button>
                <button class="button secondary" type="button" data-product-select-all>Zaznacz wszystkie na wszystkich stronach ({{ $productRows->total() }})</button>
                <button class="button secondary" type="button" data-product-selection-clear disabled>Wyczyść zaznaczenie</button>
                <span class="product-bulk-mode" data-product-selection-mode>Wybierasz pojedyncze produkty.</span>
            </div>
            <div class="product-bulk-actions">
                <button class="button" type="button" data-product-bulk-open disabled>Edytuj wybrane</button>
            </div>
        </div>
        <div class="table-scroll" data-product-list-scroll>
            <table class="dense-table product-catalog-table" data-product-list-table>
                <thead>
                    <tr>
                        <th>
                            <label class="catalog-select-heading">
                                <input type="checkbox" data-product-page-toggle aria-label="Zaznacz wszystkie produkty na tej stronie">
                                <span>Towar</span>
                            </label>
                        </th>
                        <th>VAT / cena</th>
                        <th>Stan</th>
                        <th>Kanały</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($productRows as $row)
                        @php
                            $product = $row['product'];
                            $variants = $row['variants'];
                            $familyVariants = $row['family_variants'] ?? $variants;
                            $rowKey = 'product-' . $product->id;
                            $familyProducts = collect([$product])->merge($familyVariants);
                            $imageUrl = $product->imageUrl();
                            $thumbnailUrl = $product->thumbnailUrl();
                            $stock = $stockTotals($familyProducts);
                            $channelNames = $channels($familyProducts);
                            $price = $retailPrice($product, $familyVariants);
                            $externalId = $product->externalDisplayId();
                            $inheritedVariantThumbnailUrl = null;
                            $isImportIssueProduct = (bool) ($row['is_import_issue'] ?? false);
                            $isStorefrontHidden = $familyProducts->contains(fn ($member): bool => $member->isStorefrontHidden());
                            $stockVerificationRequired = $familyProducts->contains(fn ($member): bool => $member->requiresStockVerification());
                        @endphp
                        <tr @class(['parent-row', 'product-import-issue-row' => $isImportIssueProduct]) data-product-card="parent" data-product-id="{{ $product->id }}">
                            <td data-product-card-section="identity">
                                <div class="product-cell">
                                    <label class="product-select-control">
                                        <input type="checkbox" value="{{ $product->id }}" data-product-select aria-label="Zaznacz produkt {{ $product->name }}">
                                    </label>
                                    <form method="POST" action="{{ route('products.favorite.toggle', $product) }}">
                                        @csrf
                                        <button
                                            class="favorite-button {{ $product->is_favorite ? 'is-favorite' : '' }}"
                                            type="submit"
                                            aria-label="{{ $product->is_favorite ? 'Usuń z ulubionych' : 'Dodaj do ulubionych' }}: {{ $product->name }}"
                                            aria-pressed="{{ $product->is_favorite ? 'true' : 'false' }}"
                                            title="{{ $product->is_favorite ? 'Usuń z ulubionych' : 'Dodaj do ulubionych' }}"
                                        >★</button>
                                    </form>
                                    <button
                                        class="product-thumb-button"
                                        type="button"
                                        @disabled(! $imageUrl)
                                        data-image-preview="{{ $imageUrl }}"
                                        data-image-title="{{ $product->sku }} - {{ $product->name }}"
                                        aria-label="{{ $imageUrl ? 'Powiększ zdjęcie produktu ' . $product->sku : 'Brak zdjęcia produktu ' . $product->sku }}"
                                    >
                                        @if ($imageUrl)
                                            <img src="{{ $thumbnailUrl ?: $imageUrl }}" alt="{{ $product->name }}" width="58" height="72" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                        @else
                                            Brak
                                        @endif
                                    </button>
                                    <div>
                                        <a class="product-title" href="{{ route('products.edit', $product) }}">{{ $product->name }}</a>
                                        @if ($isImportIssueProduct)
                                            <span class="product-import-issue-badge">Błąd importu</span>
                                        @endif
                                        @if ($isStorefrontHidden)
                                            <span class="product-storefront-badge">Ukryty w sklepie</span>
                                        @elseif ($stockVerificationRequired)
                                            <span class="product-stock-verification-badge">Stan w sklepie: 0 — do weryfikacji</span>
                                        @endif
                                        <div class="product-meta">
                                            <span><strong>ID Woo:</strong> {{ $externalId ?: '—' }}</span>
                                            <span><strong>SKU:</strong> {{ $product->displaySku() ?: '—' }}</span>
                                            <span><strong>EAN:</strong> {{ $product->ean ?: '—' }}</span>
                                            <span><strong>JM:</strong> {{ $product->unit }}</span>
                                        </div>
                                        @if ($variants->isNotEmpty())
                                            <button class="variant-toggle" type="button" data-toggle-variants="{{ $rowKey }}" aria-expanded="{{ $importIssue ? 'true' : 'false' }}">{{ $importIssue ? 'Warianty dotknięte błędem' : 'Warianty' }}: {{ $variants->count() }}</button>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td data-product-card-section="price">
                                <div class="price-cell">
                                    <strong>{{ $money($price) }}</strong>
                                    <span>VAT {{ $percent($product->vat_rate) }}</span>
                                </div>
                            </td>
                            <td data-product-card-section="stock">
                                <div class="stock-summary">
                                    <div class="stock-pills">
                                        <span class="stock-pill">Ogółem <strong>{{ $qty($stock['on_hand'], $product) }}</strong></span>
                                        <span class="stock-pill">Rezerwacje <strong>{{ $qty($stock['reserved'], $product) }}</strong></span>
                                        <span class="stock-pill available">Dostępne <strong>{{ $qty($stock['available'], $product) }}</strong></span>
                                    </div>
                                    <button class="warehouse-modal-trigger" type="button" data-stock-modal-open="stock-modal-{{ $product->id }}" aria-haspopup="dialog" aria-controls="stock-modal-{{ $product->id }}" aria-expanded="false">Magazyny i korekta</button>
                                    <div class="stock-modal" id="stock-modal-{{ $product->id }}" data-stock-modal hidden aria-hidden="true">
                                        <div @class(['stock-modal-card', 'variant-stock-modal-card' => $familyVariants->isNotEmpty()]) data-stock-modal-card role="dialog" aria-modal="true" aria-labelledby="stock-modal-title-{{ $product->id }}">
                                            <div class="stock-modal-header">
                                                <div class="stock-modal-title" id="stock-modal-title-{{ $product->id }}">
                                                    <span>Magazyny i korekta</span>
                                                    <small>{{ $product->name }}</small>
                                                </div>
                                                <button class="stock-modal-close" type="button" data-stock-modal-close aria-label="Zamknij">&times;</button>
                                            </div>
                                            <div class="stock-modal-body" data-stock-modal-body>
                                        @include('products._stock_management_panels', [
                                            'stockOwner' => $product,
                                            'stockWarehouses' => $warehouseOptions,
                                        ])
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td data-product-card-section="channels">
                                <div class="channel-badges">
                                    @forelse ($channelNames as $channelName)
                                        <span class="status">{{ $channelName }}</span>
                                    @empty
                                        <span class="muted">-</span>
                                    @endforelse
                                </div>
                            </td>
                            <td data-product-card-section="actions">
                                <div class="inline-actions">
                                    <a class="button secondary" href="{{ route('products.edit', $product) }}">Edytuj</a>
                                    @include('products._storefront_actions', [
                                        'storefrontProduct' => $product,
                                        'storefrontHidden' => $isStorefrontHidden,
                                        'stockVerificationRequired' => $stockVerificationRequired,
                                    ])
                                    <form method="POST" action="{{ route('products.duplicate', $product) }}">
                                        @csrf
                                        <button class="button secondary" type="submit">Kopiuj</button>
                                    </form>
                                    <form method="POST" action="{{ route('products.destroy', $product) }}" onsubmit="return confirm('Usunąć produkt?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button" style="background: var(--red);" type="submit">Usuń</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        @foreach ($variants as $variant)
                            @php
                                $variantImage = $variant->imageUrl();
                                $variantMaster = $variant->masterData();
                                $inheritsParentImage = $variantImage === null
                                    && data_get($variantMaster, 'inheritance.mode') === 'parent'
                                    && (int) data_get($variantMaster, 'inheritance.parent_product_id') === (int) $product->id;

                                if ($inheritsParentImage) {
                                    // The parent is already loaded as the current family row,
                                    // so this fallback never queries variantParents per child.
                                    $variantImage = $imageUrl;
                                    $inheritedVariantThumbnailUrl ??= $product->thumbnailUrl(88, 112);
                                    $variantThumbnailUrl = $inheritedVariantThumbnailUrl;
                                } else {
                                    $variantThumbnailUrl = $variant->thumbnailUrl(88, 112);
                                }
                                $variantStock = $stockTotals(collect([$variant]));
                                $variantChannels = $channels(collect([$variant]));
                                $variantPrice = $retailPrice($variant, collect());
                                $variantExternalId = $variant->externalDisplayId();
                                $variation = collect($variant->wooVariationAttributes())
                                    ->map(function (array $attribute): string {
                                        $name = trim((string) ($attribute['name'] ?? ''));
                                        $option = trim((string) ($attribute['option'] ?? ''));

                                        if ($option === '') {
                                            return '';
                                        }

                                        return $name !== '' ? "{$name}: {$option}" : $option;
                                    })
                                    ->filter()
                                    ->implode(' | ');
                            @endphp
                            <tr @class(['variant-row', 'product-import-issue-row' => $importIssue]) data-variant-parent="{{ $rowKey }}" data-product-card="variant" data-product-id="{{ $variant->id }}" data-parent-product-id="{{ $product->id }}" @if (! $importIssue) hidden @endif>
                                <td data-product-card-section="identity">
                                    <div class="product-cell variant">
                                        <span class="product-select-spacer" aria-hidden="true"></span>
                                        <form method="POST" action="{{ route('products.favorite.toggle', $variant) }}">
                                            @csrf
                                            <button
                                                class="favorite-button {{ $variant->is_favorite ? 'is-favorite' : '' }}"
                                                type="submit"
                                                aria-label="{{ $variant->is_favorite ? 'Usuń z ulubionych' : 'Dodaj do ulubionych' }}: {{ $variant->name }}"
                                                aria-pressed="{{ $variant->is_favorite ? 'true' : 'false' }}"
                                                title="{{ $variant->is_favorite ? 'Usuń z ulubionych' : 'Dodaj do ulubionych' }}"
                                            >★</button>
                                        </form>
                                        <button
                                            class="product-thumb-button"
                                            type="button"
                                            @disabled(! $variantImage)
                                            data-image-preview="{{ $variantImage }}"
                                            data-image-title="{{ $variant->sku }} - {{ $variant->name }}"
                                        >
                                            @if ($variantImage)
                                                <img src="{{ $variantThumbnailUrl ?: $variantImage }}" alt="{{ $variant->name }}" width="44" height="56" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                            @else
                                                Brak
                                            @endif
                                        </button>
                                        <div>
                                            <a class="product-title" href="{{ route('products.edit', $variant) }}">{{ $variant->name }}</a>
                                            @if ($importIssue)
                                                <span class="product-import-issue-badge">Błąd importu</span>
                                            @endif
                                            @if ($isStorefrontHidden)
                                                <span class="product-storefront-badge">Ukryty w sklepie</span>
                                            @elseif ($stockVerificationRequired)
                                                <span class="product-stock-verification-badge">Stan w sklepie: 0 — do weryfikacji</span>
                                            @endif
                                            <div class="product-meta">
                                                <span><strong>ID Woo:</strong> {{ $variantExternalId ?: '—' }}</span>
                                                <span><strong>SKU:</strong> {{ $variant->displaySku() ?: '—' }}</span>
                                                <span><strong>EAN:</strong> {{ $variant->ean ?: '—' }}</span>
                                                @if ($variation)
                                                    <span>{{ $variation }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td data-product-card-section="price">
                                    <div class="price-cell">
                                        <strong>{{ $money($variantPrice) }}</strong>
                                        <span>VAT {{ $percent($variant->vat_rate) }}</span>
                                    </div>
                                </td>
                                <td data-product-card-section="stock">
                                    <div class="stock-summary">
                                        <div class="stock-pills">
                                            <span class="stock-pill">Ogółem <strong>{{ $qty($variantStock['on_hand'], $variant) }}</strong></span>
                                            <span class="stock-pill">Rezerwacje <strong>{{ $qty($variantStock['reserved'], $variant) }}</strong></span>
                                            <span class="stock-pill available">Dostępne <strong>{{ $qty($variantStock['available'], $variant) }}</strong></span>
                                        </div>
                                        <button class="warehouse-modal-trigger" type="button" data-stock-modal-open="stock-modal-{{ $variant->id }}" aria-haspopup="dialog" aria-controls="stock-modal-{{ $variant->id }}" aria-expanded="false">Magazyny i korekta</button>
                                        <div class="stock-modal" id="stock-modal-{{ $variant->id }}" data-stock-modal hidden aria-hidden="true">
                                            <div class="stock-modal-card" data-stock-modal-card role="dialog" aria-modal="true" aria-labelledby="stock-modal-title-{{ $variant->id }}">
                                                <div class="stock-modal-header">
                                                    <div class="stock-modal-title" id="stock-modal-title-{{ $variant->id }}">
                                                        <span>Magazyny i korekta</span>
                                                        <small>{{ $variant->name }}</small>
                                                    </div>
                                                    <button class="stock-modal-close" type="button" data-stock-modal-close aria-label="Zamknij">&times;</button>
                                                </div>
                                                <div class="stock-modal-body" data-stock-modal-body>
                                            @include('products._stock_readonly_panel', [
                                                'stockProduct' => $variant,
                                                'stockWarehouses' => $warehouseOptions,
                                            ])
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td data-product-card-section="channels">
                                    <div class="channel-badges">
                                        @forelse ($variantChannels as $channelName)
                                            <span class="status">{{ $channelName }}</span>
                                        @empty
                                            <span class="muted">-</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td data-product-card-section="actions">
                                    <div class="inline-actions">
                                        <a class="button secondary" href="{{ route('products.edit', $variant) }}">Edytuj</a>
                                        @include('products._storefront_actions', [
                                            'storefrontProduct' => $variant,
                                            'storefrontHidden' => $isStorefrontHidden,
                                            'stockVerificationRequired' => $stockVerificationRequired,
                                        ])
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr class="product-empty-row">
                            <td colspan="5">Brak produktów. Dodaj produkt ręcznie albo zaimportuj produkty z WooCommerce w module Integracje.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrapper">
            @if ($productRows->hasPages())
                @php
                    $currentPage = $productRows->currentPage();
                    $lastPage = $productRows->lastPage();
                    $firstPageLink = max(1, $currentPage - 2);
                    $lastPageLink = min($lastPage, $currentPage + 2);
                @endphp
                <nav class="pagination-bar" aria-label="Paginacja produktów">
                    <div class="pagination-summary">
                        Strona {{ $currentPage }} z {{ $lastPage }} | rekordy {{ $productRows->firstItem() }}-{{ $productRows->lastItem() }} z {{ $productRows->total() }}
                    </div>
                    <div class="pagination-pages">
                        <a @class(['pagination-page', 'disabled' => $productRows->onFirstPage()]) href="{{ $productRows->previousPageUrl() ?: '#' }}">Poprzednia</a>
                        @if ($firstPageLink > 1)
                            <a class="pagination-page" href="{{ $productRows->url(1) }}">1</a>
                            @if ($firstPageLink > 2)
                                <span class="pagination-page disabled">...</span>
                            @endif
                        @endif
                        @for ($page = $firstPageLink; $page <= $lastPageLink; $page++)
                            <a @class(['pagination-page', 'active' => $page === $currentPage]) href="{{ $productRows->url($page) }}">{{ $page }}</a>
                        @endfor
                        @if ($lastPageLink < $lastPage)
                            @if ($lastPageLink < $lastPage - 1)
                                <span class="pagination-page disabled">...</span>
                            @endif
                            <a class="pagination-page" href="{{ $productRows->url($lastPage) }}">{{ $lastPage }}</a>
                        @endif
                        <a @class(['pagination-page', 'disabled' => ! $productRows->hasMorePages()]) href="{{ $productRows->nextPageUrl() ?: '#' }}">Następna</a>
                    </div>
                </nav>
            @else
                <div class="pagination-summary">Wszystkie wyniki mieszczą się na jednej stronie.</div>
            @endif
        </div>
    </article>

    @include('products._bulk_edit_modal')

    <div class="image-modal" data-product-image-modal aria-hidden="true">
        <div class="image-modal-card">
            <div class="image-modal-header">
                <span data-product-image-title>Podgląd produktu</span>
                <button class="image-modal-close" type="button" data-product-image-close aria-label="Zamknij">&times;</button>
            </div>
            <img data-product-image-large alt="">
        </div>
    </div>

    <datalist id="product-category-options">
        @foreach ($categoryOptions as $category)
            <option value="{{ $category['path'] }}">{{ $category['sales_channel'] ? $category['sales_channel'] . ' · ' : '' }}{{ $category['name'] }}</option>
        @endforeach
    </datalist>

    <datalist id="product-filter-category-options">
        @foreach ($categoryOptions as $category)
            <option value="{{ $category['path'] }}">{{ $category['sales_channel'] ? $category['sales_channel'] . ' · ' : '' }}{{ $category['name'] }}</option>
        @endforeach
    </datalist>
    @include('products._parameter_datalists', ['parameterOptions' => $parameterOptions])
    <datalist id="product-lookup-options"></datalist>
    @include('products._rich_editor_assets')
@endsection

@push('scripts')
    <script>
        const productBulkModal = document.querySelector('[data-product-bulk-modal]');
        const productBulkForm = document.querySelector('[data-product-bulk-form]');
        const productBulkSelectionInputs = document.querySelector('[data-product-bulk-selection-inputs]');
        const productBulkCheckboxes = Array.from(document.querySelectorAll('[data-product-select]'));
        const productBulkPageToggle = document.querySelector('[data-product-page-toggle]');
        const productBulkSelectedCount = document.querySelector('[data-product-selected-count]');
        const productBulkModeDescription = document.querySelector('[data-product-selection-mode]');
        const productBulkOpenButton = document.querySelector('[data-product-bulk-open]');
        const productBulkClearButton = document.querySelector('[data-product-selection-clear]');
        const productBulkSelectPageButton = document.querySelector('[data-product-select-page]');
        const productBulkSelectAllButton = document.querySelector('[data-product-select-all]');
        const productBulkTotal = {{ (int) $productRows->total() }};
        let productBulkSelectionMode = @json(old('selection_mode', 'selected')) === 'all_filtered' ? 'all_filtered' : 'selected';
        const productBulkSelectedIds = new Set(@json(array_values((array) old('product_ids', []))).map((id) => String(id)));
        const productBulkExcludedIds = new Set(@json(array_values((array) old('excluded_ids', []))).map((id) => String(id)));
        let productBulkRestoreFocus = null;

        function productBulkSelectionCount() {
            return productBulkSelectionMode === 'all_filtered'
                ? Math.max(0, productBulkTotal - productBulkExcludedIds.size)
                : productBulkSelectedIds.size;
        }

        function productBulkProductLabel(count) {
            if (count === 1) return 'produkt';

            const lastTwoDigits = count % 100;
            const lastDigit = count % 10;

            return lastDigit >= 2 && lastDigit <= 4 && (lastTwoDigits < 12 || lastTwoDigits > 14)
                ? 'produkty'
                : 'produktów';
        }

        function renderProductBulkSelectionInputs() {
            if (!productBulkSelectionInputs) return;

            const fields = [];
            const mode = document.createElement('input');
            mode.type = 'hidden';
            mode.name = 'selection_mode';
            mode.value = productBulkSelectionMode;
            fields.push(mode);

            const ids = productBulkSelectionMode === 'all_filtered' ? productBulkExcludedIds : productBulkSelectedIds;
            const name = productBulkSelectionMode === 'all_filtered' ? 'excluded_ids[]' : 'product_ids[]';

            ids.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = id;
                fields.push(input);
            });

            productBulkSelectionInputs.replaceChildren(...fields);
        }

        function syncProductBulkSelection() {
            productBulkCheckboxes.forEach((checkbox) => {
                const id = String(checkbox.value);
                checkbox.checked = productBulkSelectionMode === 'all_filtered'
                    ? !productBulkExcludedIds.has(id)
                    : productBulkSelectedIds.has(id);
            });

            const checkedOnPage = productBulkCheckboxes.filter((checkbox) => checkbox.checked).length;
            const count = productBulkSelectionCount();

            if (productBulkPageToggle) {
                productBulkPageToggle.checked = productBulkCheckboxes.length > 0 && checkedOnPage === productBulkCheckboxes.length;
                productBulkPageToggle.indeterminate = checkedOnPage > 0 && checkedOnPage < productBulkCheckboxes.length;
            }

            if (productBulkSelectedCount) {
                productBulkSelectedCount.textContent = `Wybrano: ${count}`;
            }

            if (productBulkModeDescription) {
                productBulkModeDescription.textContent = productBulkSelectionMode === 'all_filtered'
                    ? `Wybrane są wszystkie produkty pasujące do bieżących filtrów${productBulkExcludedIds.size ? ` z wyjątkiem ${productBulkExcludedIds.size}` : ''}.`
                    : 'Wybierasz pojedyncze produkty z tej strony.';
            }

            if (productBulkOpenButton) productBulkOpenButton.disabled = count === 0;
            if (productBulkClearButton) productBulkClearButton.disabled = count === 0;
            if (productBulkSelectPageButton) productBulkSelectPageButton.disabled = productBulkCheckboxes.length === 0;
            if (productBulkSelectAllButton) productBulkSelectAllButton.disabled = productBulkTotal === 0;
            renderProductBulkSelectionInputs();
        }

        function setProductBulkCheckbox(checkbox, checked) {
            const id = String(checkbox.value);

            if (productBulkSelectionMode === 'all_filtered') {
                checked ? productBulkExcludedIds.delete(id) : productBulkExcludedIds.add(id);
            } else {
                checked ? productBulkSelectedIds.add(id) : productBulkSelectedIds.delete(id);
            }
        }

        function openProductBulkModal(trigger = null) {
            if (!productBulkModal || productBulkSelectionCount() === 0) return;

            productBulkRestoreFocus = trigger;
            renderProductBulkSelectionInputs();
            const countTarget = productBulkModal.querySelector('[data-product-bulk-modal-count]');
            const count = productBulkSelectionCount();
            if (countTarget) countTarget.textContent = `${count} ${productBulkProductLabel(count)}`;
            productBulkModal.hidden = false;
            productBulkModal.setAttribute('aria-hidden', 'false');
            document.documentElement.classList.add('product-bulk-modal-open');
            productBulkModal.querySelector('[data-product-bulk-close]')?.focus({ preventScroll: true });
        }

        function closeProductBulkModal(restoreFocus = true) {
            if (!productBulkModal || productBulkModal.hidden) return;

            productBulkModal.hidden = true;
            productBulkModal.setAttribute('aria-hidden', 'true');
            document.documentElement.classList.remove('product-bulk-modal-open');

            if (restoreFocus) productBulkRestoreFocus?.focus({ preventScroll: true });
        }

        productBulkCheckboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                setProductBulkCheckbox(checkbox, checkbox.checked);
                syncProductBulkSelection();
            });
        });

        productBulkPageToggle?.addEventListener('change', () => {
            productBulkCheckboxes.forEach((checkbox) => setProductBulkCheckbox(checkbox, productBulkPageToggle.checked));
            syncProductBulkSelection();
        });

        productBulkSelectPageButton?.addEventListener('click', () => {
            productBulkCheckboxes.forEach((checkbox) => setProductBulkCheckbox(checkbox, true));
            syncProductBulkSelection();
        });

        productBulkSelectAllButton?.addEventListener('click', () => {
            productBulkSelectionMode = 'all_filtered';
            productBulkSelectedIds.clear();
            productBulkExcludedIds.clear();
            syncProductBulkSelection();
        });

        productBulkClearButton?.addEventListener('click', () => {
            productBulkSelectionMode = 'selected';
            productBulkSelectedIds.clear();
            productBulkExcludedIds.clear();
            syncProductBulkSelection();
        });

        productBulkOpenButton?.addEventListener('click', () => openProductBulkModal(productBulkOpenButton));
        productBulkModal?.querySelectorAll('[data-product-bulk-close]').forEach((button) => {
            button.addEventListener('click', () => closeProductBulkModal());
        });
        productBulkModal?.addEventListener('click', (event) => {
            if (event.target === productBulkModal) closeProductBulkModal();
        });

        document.querySelectorAll('[data-product-bulk-apply]').forEach((checkbox) => {
            const field = checkbox.closest('[data-product-bulk-field]');
            const values = Array.from(field?.querySelectorAll('[data-product-bulk-value]') || []);
            const sync = () => values.forEach((value) => { value.disabled = !checkbox.checked; });
            checkbox.addEventListener('change', sync);
            sync();
        });

        productBulkForm?.addEventListener('submit', (event) => {
            renderProductBulkSelectionInputs();
            const count = productBulkSelectionCount();

            if (!window.confirm(`Zastosować wybrane zmiany do ${count} ${count === 1 ? 'produktu' : 'produktów'}?`)) {
                event.preventDefault();
            }
        });

        syncProductBulkSelection();

        if (productBulkModal?.dataset.openOnLoad === '1' && productBulkSelectionCount() > 0) {
            openProductBulkModal(productBulkOpenButton);
        }

        const productLookupUrl = @json($productLookupUrl);
        const productLookupDatalist = document.getElementById('product-lookup-options');
        let productLookupTimer = null;
        let productLookupRequest = null;

        function clearProductLookupOptions() {
            if (productLookupDatalist) {
                productLookupDatalist.replaceChildren();
            }
        }

        function searchRelatedProducts(query) {
            window.clearTimeout(productLookupTimer);

            if (query.length < 2) {
                productLookupRequest?.abort();
                clearProductLookupOptions();
                return;
            }

            productLookupTimer = window.setTimeout(async () => {
                productLookupRequest?.abort();
                productLookupRequest = new AbortController();

                try {
                    const response = await fetch(`${productLookupUrl}?q=${encodeURIComponent(query)}`, {
                        headers: { Accept: 'application/json' },
                        signal: productLookupRequest.signal,
                    });

                    if (!response.ok || !productLookupDatalist) {
                        return;
                    }

                    const products = await response.json();
                    productLookupDatalist.replaceChildren(...products.map((product) => {
                        const option = document.createElement('option');
                        option.value = product.label;
                        option.textContent = product.sku;
                        return option;
                    }));
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        clearProductLookupOptions();
                    }
                }
            }, 180);
        }

        document.querySelectorAll('[data-product-related-picker]').forEach((input) => {
            input.addEventListener('input', () => searchRelatedProducts(input.value.trim()));
        });

        const createTabs = Array.from(document.querySelectorAll('[data-create-tab]'));
        const createSteps = Array.from(document.querySelectorAll('[data-create-step]'));
        const createPreviousButton = document.querySelector('[data-create-prev]');
        const createNextButton = document.querySelector('[data-create-next]');
        let activeCreateStepIndex = 0;

        function showCreateStep(index) {
            if (createSteps.length === 0) {
                return;
            }

            activeCreateStepIndex = Math.max(0, Math.min(index, createSteps.length - 1));

            createSteps.forEach((step, stepIndex) => {
                step.hidden = stepIndex !== activeCreateStepIndex;
            });

            createTabs.forEach((tab, tabIndex) => {
                const active = tabIndex === activeCreateStepIndex;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            if (createPreviousButton) {
                createPreviousButton.disabled = activeCreateStepIndex === 0;
            }

            if (createNextButton) {
                createNextButton.hidden = activeCreateStepIndex === createSteps.length - 1;
            }
        }

        createTabs.forEach((tab, index) => {
            tab.addEventListener('click', () => showCreateStep(index));
        });

        createPreviousButton?.addEventListener('click', () => showCreateStep(activeCreateStepIndex - 1));
        createNextButton?.addEventListener('click', () => showCreateStep(activeCreateStepIndex + 1));
        showCreateStep(0);

        document.querySelectorAll('[data-toggle-variants]').forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.dataset.toggleVariants;
                const rows = Array.from(document.querySelectorAll(`[data-variant-parent="${key}"]`));
                const expanded = button.getAttribute('aria-expanded') === 'true';

                rows.forEach((row) => {
                    row.hidden = expanded;
                });

                button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            });
        });

        const stockModalFocusableSelector = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

        function syncStockModalLock() {
            document.documentElement.classList.toggle('stock-modal-open', Boolean(document.querySelector('[data-stock-modal]:not([hidden])')));
        }

        function closeStockModal(modal, restoreFocus = true) {
            if (!modal || modal.hidden) return;

            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            const trigger = modal._stockModalTrigger;
            trigger?.setAttribute('aria-expanded', 'false');
            syncStockModalLock();

            if (restoreFocus) {
                trigger?.focus({ preventScroll: true });
            }
        }

        document.querySelectorAll('[data-stock-modal-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const modal = document.getElementById(button.dataset.stockModalOpen || '');
                if (!modal) return;

                document.querySelectorAll('[data-stock-modal]:not([hidden])').forEach((openModal) => closeStockModal(openModal, false));
                modal._stockModalTrigger = button;
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
                button.setAttribute('aria-expanded', 'true');
                syncStockModalLock();
                modal.querySelector('[data-stock-modal-close]')?.focus({ preventScroll: true });
            });
        });

        document.querySelectorAll('[data-stock-modal-close]').forEach((button) => {
            button.addEventListener('click', () => closeStockModal(button.closest('[data-stock-modal]')));
        });

        document.querySelectorAll('[data-stock-modal]').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closeStockModal(modal);
            });
        });

        const productImageModal = document.querySelector('[data-product-image-modal]');
        const productImageLarge = document.querySelector('[data-product-image-large]');
        const productImageTitle = document.querySelector('[data-product-image-title]');
        const productImageClose = document.querySelector('[data-product-image-close]');

        document.querySelectorAll('[data-image-preview]').forEach((button) => {
            button.addEventListener('click', () => {
                const src = button.dataset.imagePreview || '';

                if (!src || !productImageModal || !productImageLarge || !productImageTitle) {
                    return;
                }

                productImageLarge.src = src;
                productImageLarge.alt = button.dataset.imageTitle || 'Podgląd produktu';
                productImageTitle.textContent = button.dataset.imageTitle || 'Podgląd produktu';
                productImageModal.classList.add('open');
                productImageModal.setAttribute('aria-hidden', 'false');
            });
        });

        function closeProductImageModal() {
            if (!productImageModal || !productImageLarge) {
                return;
            }

            productImageModal.classList.remove('open');
            productImageModal.setAttribute('aria-hidden', 'true');
            productImageLarge.removeAttribute('src');
        }

        productImageClose?.addEventListener('click', closeProductImageModal);
        productImageModal?.addEventListener('click', (event) => {
            if (event.target === productImageModal) {
                closeProductImageModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeProductBulkModal();
                const modal = document.querySelector('[data-stock-modal]:not([hidden])');
                closeStockModal(modal);
                closeProductImageModal();
                return;
            }

            if (event.key === 'Tab') {
                const modal = document.querySelector('[data-product-bulk-modal]:not([hidden])')
                    || document.querySelector('[data-stock-modal]:not([hidden])');

                if (!modal) return;

                const focusable = Array.from(modal.querySelectorAll(stockModalFocusableSelector))
                    .filter((element) => element.getClientRects().length > 0);

                if (focusable.length === 0) return;

                const first = focusable[0];
                const last = focusable[focusable.length - 1];

                if (!modal.contains(document.activeElement)) {
                    event.preventDefault();
                    first.focus();
                } else if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    </script>
@endpush
