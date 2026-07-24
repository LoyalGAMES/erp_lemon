@extends('layouts.app', [
    'title' => match ($packingView ?? 'home') {
        'collect' => 'Kompletacja',
        'waiting' => 'Oczekuje na kuriera',
        'shipped' => 'Wysłane',
        'problems' => 'Problemy',
        'history' => 'Historia pakowania',
        default => 'Pakowanie',
    },
    'module' => 'packing',
    'hideTopActions' => true,
    'compactHeader' => in_array(($packingView ?? 'home'), ['collect', 'pack', 'waiting', 'shipped', 'problems', 'history'], true),
    'headerBackUrl' => in_array(($packingView ?? 'home'), ['collect', 'pack', 'waiting', 'shipped', 'problems', 'history'], true) ? route('packing.index', ['view' => 'home']) : null,
])

@push('styles')
    <style>
        .packing-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; }
        .packing-toolbar-summary { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .packing-toolbar-chip { display: inline-flex; align-items: center; min-height: 38px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 12px; background: var(--surface); color: var(--green-dark); font-weight: 760; box-shadow: var(--shadow); }
        .packing-settings-trigger { min-height: 42px; white-space: nowrap; }
        .packing-settings-overlay[hidden] { display: none; }
        .packing-settings-overlay { position: fixed; inset: 0; z-index: 90; display: grid; grid-template-columns: minmax(0, 1fr) minmax(340px, 430px); }
        .packing-settings-backdrop { grid-column: 1 / -1; grid-row: 1; border: 0; background: rgba(33, 28, 24, .36); cursor: default; }
        .packing-settings-drawer { position: relative; z-index: 1; grid-column: 2; grid-row: 1; height: 100vh; overflow-y: auto; padding: 18px; background: var(--surface); border-left: 1px solid var(--border); box-shadow: -18px 0 38px rgba(33, 28, 24, .18); display: grid; gap: 14px; align-content: start; }
        .packing-drawer-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
        .packing-drawer-header h2 { margin: 0; font-size: 20px; line-height: 1.15; }
        .drawer-close { min-width: 42px; min-height: 42px; padding: 0; border-radius: 8px; }
        .packing-control-section { border: 1px solid var(--border); border-radius: 8px; padding: 14px; display: grid; gap: 12px; align-content: start; background: #fffdfb; }
        .packing-control-header { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
        .packing-control-header .button { min-height: 38px; white-space: nowrap; }
        .packing-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .packing-stat { padding: 14px 16px; }
        .packing-stat-link { color: var(--text); text-decoration: none; transition: transform .15s ease, border-color .15s ease; }
        .packing-stat-link:hover { transform: translateY(-2px); border-color: rgba(134, 115, 100, .48); }
        .packing-stat strong { display: block; font-size: 25px; line-height: 1; margin-top: 3px; }
        .packing-workflow-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .packing-workflow-tab { display: inline-flex; align-items: center; gap: 7px; min-height: 40px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; background: var(--surface); color: var(--muted); text-decoration: none; font-weight: 760; }
        .packing-workflow-tab.active { background: var(--green-soft); border-color: rgba(134, 115, 100, .42); color: var(--green-dark); }
        .packing-workflow-tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 21px; min-height: 21px; border-radius: 999px; padding: 0 6px; background: rgba(134, 115, 100, .14); font-size: 12px; }
        .packing-mobile-workflow-nav { display: none; }
        .workflow-picker { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .workflow-card { min-height: 148px; display: grid; align-content: center; gap: 7px; border: 1px solid var(--border); border-radius: 8px; padding: 22px; background: var(--surface); color: var(--text); text-decoration: none; box-shadow: var(--shadow); }
        .workflow-card span { color: var(--muted); font-weight: 780; text-transform: uppercase; letter-spacing: .04em; font-size: 12px; }
        .workflow-card strong { font-size: clamp(32px, 4vw, 50px); line-height: 1; letter-spacing: -.03em; }
        .workflow-card small { color: var(--muted); font-weight: 650; }
        .packing-home-links { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
        .packing-home-links .button { min-height: 46px; }
        .mode-copy { color: var(--muted); }
        .mode-copy strong { display: block; color: var(--text); font-size: 16px; margin-bottom: 3px; }
        .mode-actions { display: grid; gap: 8px; }
        .mode-button { width: 100%; min-height: 46px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 11px; background: #fff; color: var(--text); font: inherit; font-weight: 760; cursor: pointer; text-align: left; }
        .mode-button.active { background: var(--green); border-color: var(--green); color: #fff; }
        .collection-workspace { max-width: 1040px; margin: 0 auto; }
        .queue-list { display: grid; gap: 12px; }
        .pick-card, .order-card, .courier-card, .history-card { border: 1px solid var(--border); border-radius: 8px; background: var(--surface); box-shadow: var(--shadow); }
        .collect-card { padding: 14px; display: grid; gap: 11px; }
        .collect-order-card { padding: 16px; }
        .collect-order-customer { color: var(--muted); font-size: 13px; margin-top: 4px; }
        .collect-main { display: grid; grid-template-columns: 78px minmax(0, 1fr) auto; gap: 14px; align-items: center; }
        .product-thumb { width: 58px; height: 72px; border: 1px solid var(--border); border-radius: 7px; overflow: hidden; background: #f4f1ef; display: grid; place-items: center; color: var(--muted); font-size: 11px; font-weight: 780; }
        .product-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .product-thumb-button { padding: 0; appearance: none; font: inherit; cursor: zoom-in; }
        .product-thumb-button:focus-visible { outline: 3px solid rgba(134, 115, 100, .45); outline-offset: 2px; }
        .packing-image-modal[hidden] { display: none; }
        .packing-image-modal { position: fixed; inset: 0; z-index: 120; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(37, 31, 26, .72); }
        .packing-image-modal-card { width: min(900px, 94vw); max-height: 92dvh; overflow: hidden; border-radius: 8px; background: var(--surface); box-shadow: 0 24px 70px rgba(0, 0, 0, .32); }
        .packing-image-modal-header { min-height: 52px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border-bottom: 1px solid var(--border); font-weight: 780; }
        .packing-image-modal-title { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .packing-image-modal-close { min-width: 42px; min-height: 42px; border: 0; border-radius: 7px; background: transparent; color: var(--muted); font: inherit; font-size: 24px; cursor: pointer; }
        .packing-image-modal-close:hover, .packing-image-modal-close:focus-visible { background: var(--green-soft); color: var(--green-dark); }
        .packing-image-modal img { display: block; width: 100%; max-height: calc(92dvh - 52px); object-fit: contain; background: #f4f1ef; }
        .collect-card .product-thumb { width: 78px; height: 98px; }
        .pick-name { font-size: 17px; font-weight: 840; line-height: 1.25; }
        .pick-sku { color: var(--muted); font-size: 12px; font-weight: 760; letter-spacing: .02em; margin-top: 2px; }
        .collect-size { margin-top: 8px; display: inline-flex; align-items: baseline; gap: 8px; color: var(--muted); font-weight: 760; }
        .collect-size strong { color: var(--green-dark); font-size: clamp(38px, 8vw, 66px); line-height: .85; letter-spacing: -.04em; }
        .qty-pill { min-width: 82px; text-align: center; border-radius: 8px; padding: 10px 12px; background: var(--green-soft); color: var(--green-dark); font-weight: 850; font-size: 17px; }
        .pick-badges, .order-badges, .history-badges { display: flex; flex-wrap: wrap; gap: 6px; }
        .pick-badge { display: inline-flex; align-items: center; min-height: 26px; border-radius: 7px; padding: 2px 8px; background: rgba(134, 115, 100, .08); color: var(--muted); font-size: 12px; font-weight: 720; }
        .collect-note input { min-height: 48px; }
        .collect-actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .collect-actions form { min-width: 0; display: grid; gap: 8px; }
        .collect-actions input { min-height: 42px; }
        .collect-actions .button { width: 100%; min-height: 64px; font-size: 19px; border-radius: 8px; }
        .packing-split-modal { width: min(720px, calc(100vw - 28px)); max-height: min(88dvh, 820px); margin: auto; border: 0; border-radius: 10px; padding: 0; background: var(--surface); color: var(--text); box-shadow: 0 26px 80px rgba(33, 28, 24, .32); }
        .packing-split-modal::backdrop { background: rgba(37, 31, 26, .62); backdrop-filter: blur(2px); }
        .packing-split-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 17px 18px; border-bottom: 1px solid var(--border); }
        .packing-split-header h2 { margin: 0; font-size: 21px; line-height: 1.2; }
        .packing-split-header p { margin: 5px 0 0; color: var(--muted); }
        .packing-split-close { flex: 0 0 auto; width: 42px; height: 42px; border: 0; border-radius: 8px; background: var(--surface-soft); color: var(--muted); font: inherit; font-size: 25px; cursor: pointer; }
        .packing-split-body { padding: 18px; overflow-y: auto; }
        .packing-split-form { display: grid; gap: 14px; }
        .packing-split-lines { display: grid; gap: 9px; }
        .packing-split-line { display: grid; grid-template-columns: minmax(0, 1fr) 150px; gap: 14px; align-items: center; border: 1px solid var(--border); border-radius: 8px; padding: 11px 12px; background: #fffdfb; }
        .packing-split-line strong { display: block; }
        .packing-split-line span { display: block; margin-top: 3px; color: var(--muted); font-size: 12px; }
        .packing-split-line input { min-height: 44px; }
        .packing-split-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 9px; padding-top: 2px; }
        .packing-split-actions .button { min-height: 44px; }
        .button.danger { background: #ffecec; color: var(--red); border: 1px solid #f0c3c3; }
        .packing-empty { padding: 18px 16px; color: var(--muted); background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
        .segment-tabs { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 14px; }
        .segment-tab { display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 14px; font-weight: 760; color: var(--muted); text-decoration: none; background: var(--surface); }
        .segment-tab.active { border-color: var(--brand); color: var(--brand-dark); background: var(--brand-soft); }
        .segment-tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 21px; min-height: 21px; border-radius: 999px; padding: 0 6px; background: rgba(134, 115, 100, .16); font-size: 12px; }
        .station-chip { margin-left: auto; display: inline-flex; align-items: center; border: 1px solid var(--border); border-radius: 8px; padding: 8px 13px; background: #fffdfb; font-weight: 740; color: var(--green-dark); }
        .pick-badge.segment-footwear { background: #fff0e8; color: var(--orange); }
        .pick-badge.segment-clothing { background: var(--brand-soft); color: var(--brand-dark); }
        .label-account-form { display: grid; grid-template-columns: minmax(0, 1fr); gap: 6px; }
        .label-account-form select { min-height: 42px; }
        .label-size-actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
        .label-size-actions .button { min-height: 42px; padding: 7px 8px; font-size: 16px; }
        .label-size-actions .button small { display: block; margin-top: 2px; font-size: 10px; font-weight: 720; opacity: .82; }
        .shipment-label-panel { display: grid; gap: 7px; min-width: 0; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: var(--green-soft); }
        .shipment-label-number { color: var(--green-dark); font-weight: 850; overflow-wrap: anywhere; }
        .shipment-label-actions { display: flex; flex-wrap: wrap; gap: 7px; }
        .shipment-label-actions .button { min-height: 42px; width: auto; font-size: 14px; }
        @media (max-width: 760px) {
            .packing-toolbar { display: grid; }
            .packing-settings-overlay { grid-template-columns: 1fr; }
            .packing-settings-drawer { grid-column: 1; width: min(100vw, 430px); margin-left: auto; }
            .packing-control-header { display: grid; }
            .station-chip { margin-left: 0; }
        }
        .history-panel { margin-top: 16px; }
        .history-list { display: grid; gap: 8px; padding: 12px; }
        .history-card { padding: 10px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: none; }
        .pack-workspace { display: grid; gap: 16px; }
        .order-card { padding: 18px; display: grid; gap: 13px; }
        .order-card-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .order-title { font-size: 24px; line-height: 1.1; font-weight: 880; letter-spacing: -.02em; }
        .order-title a { color: inherit; text-decoration: none; }
        .order-title a:hover { text-decoration: underline; }
        .order-meta { color: var(--muted); margin-top: 4px; font-size: 15px; }
        .order-items { display: grid; gap: 8px; }
        .order-item { display: grid; grid-template-columns: 52px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 10px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
        .order-item .product-thumb { width: 52px; height: 64px; font-size: 10px; }
        .order-item-name { font-weight: 820; font-size: 16px; line-height: 1.25; }
        .order-item-meta { color: var(--muted); font-size: 13px; margin-top: 2px; }
        .order-details, .order-notes { color: var(--muted); }
        .order-details summary, .order-notes summary { cursor: pointer; color: var(--green-dark); font-weight: 760; }
        .order-details-grid { display: grid; gap: 5px; margin-top: 8px; }
        .order-details-grid strong { color: var(--text); }
        .order-details-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .order-details-actions .button { min-height: 40px; }
        .order-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; align-items: start; }
        .order-actions .button { min-height: 48px; width: 100%; font-size: 15px; border-radius: 8px; }
        .packing-choice-form, .order-problem-form { display: grid; gap: 8px; align-content: start; border: 1px solid var(--border); border-radius: 9px; padding: 11px; background: #fffdfb; }
        .packing-action-title { color: var(--text); font-size: 14px; font-weight: 820; }
        .packing-action-help { min-height: 34px; margin: 0; color: var(--muted); font-size: 12px; line-height: 1.35; }
        .manual-shipment-controls { display: grid; grid-template-columns: auto minmax(150px, 1fr) auto; gap: 7px; align-items: center; }
        .manual-shipment-controls input { min-height: 48px; }
        .manual-shipment-controls .button { width: auto; white-space: nowrap; }
        .order-problem-form { grid-column: 1 / -1; grid-template-columns: minmax(0, 1fr) auto; align-items: end; }
        .order-problem-form .packing-action-title { grid-column: 1 / -1; }
        .order-problem-form input { min-height: 48px; }
        .courier-panel { margin-top: 2px; }
        .courier-panel-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 8px; }
        .courier-panel-actions .button { min-height: 38px; }
        .courier-list { display: grid; gap: 10px; padding: 12px; }
        .courier-card { padding: 14px; display: grid; gap: 12px; }
        .courier-card-header { display: flex; justify-content: space-between; align-items: center; gap: 14px; }
        .courier-title { font-size: 18px; font-weight: 850; }
        .courier-meta { color: var(--muted); margin-top: 3px; }
        .courier-card .button { min-height: 52px; min-width: 140px; border-radius: 8px; font-size: 16px; }
        .courier-orders { display: grid; gap: 8px; }
        .courier-order-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; align-items: center; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fffdfb; }
        .courier-order-main { display: grid; gap: 5px; min-width: 0; }
        .courier-order-actions { display: flex; flex-wrap: wrap; gap: 7px; align-items: center; }
        .courier-order-actions .button { min-width: 0; min-height: 42px; font-size: 14px; }
        .tracking-state { font-size: 12px; color: var(--muted); overflow-wrap: anywhere; }
        .order-rollback-form { display: flex; gap: 8px; align-items: center; }
        .order-rollback-form input { min-height: 46px; min-width: 210px; }
        .order-rollback-form .button { min-height: 46px; min-width: 104px; font-size: 15px; }
        .history-toolbar { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; margin-bottom: 14px; }
        .history-toolbar label { display: grid; gap: 5px; font-weight: 780; color: var(--muted); }
        .history-toolbar input { min-height: 46px; }
        .packing-history-order .order-card-header { align-items: center; }
        .history-order-meta { color: var(--muted); margin-top: 5px; }
        .history-order-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
        .history-order-actions .order-rollback-form input { min-width: min(280px, 55vw); }
        .problem-panel { margin-top: 2px; }
        .problem-list { display: grid; gap: 10px; padding: 12px; }
        .problem-card { border: 1px solid #f0c3c3; border-radius: 8px; padding: 12px; background: #fffafa; display: grid; gap: 8px; }
        .problem-card-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .problem-reason { color: var(--red); font-weight: 780; }
        .problem-order-items { display: grid; gap: 6px; }
        .problem-order-item { border-color: #f0dada; background: #fff; }
        .packing-action-toast { position: fixed; z-index: 110; right: 18px; bottom: 18px; width: min(460px, calc(100vw - 36px)); border: 1px solid rgba(42, 111, 73, .35); border-radius: 8px; padding: 13px 15px; background: #eff9f2; color: var(--green-dark); font-weight: 760; box-shadow: 0 16px 40px rgba(33, 28, 24, .2); }
        .packing-action-toast.error { border-color: #efb9b9; background: #fff0f0; color: var(--red); }
        .packing-action-error { grid-column: 1 / -1; border: 1px solid #efb9b9; border-radius: 7px; padding: 9px 11px; background: #fff0f0; color: var(--red); font-weight: 720; }
        [data-packing-card][aria-busy="true"] { opacity: .66; pointer-events: none; }
        [data-packing-card].packing-card-removing { opacity: 0; transform: translateY(-4px); transition: opacity .16s ease, transform .16s ease; }
        @media (max-width: 1100px) {
            .packing-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .order-actions { grid-template-columns: 1fr; }
            .order-problem-form { grid-template-columns: 1fr; }
            .order-problem-form, .order-problem-form .packing-action-title { grid-column: auto; }
            .manual-shipment-controls { grid-template-columns: 1fr; }
            .manual-shipment-controls .button { width: 100%; }
        }
        @media (max-width: 760px) {
            .main { padding-bottom: calc(104px + env(safe-area-inset-bottom, 0px)); }
            .packing-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .workflow-picker { grid-template-columns: 1fr; }
            .workflow-card { min-height: 118px; padding: 18px; }
            .collect-main { grid-template-columns: 72px minmax(0, 1fr); }
            .collect-card .product-thumb { width: 72px; height: 92px; }
            .qty-pill { grid-column: 1 / -1; width: max-content; }
            .history-card, .courier-card-header, .order-card-header { display: grid; justify-content: stretch; }
            .courier-order-row { grid-template-columns: 1fr; }
            .order-rollback-form { display: grid; grid-template-columns: 1fr auto; }
            .order-rollback-form input { min-width: 0; }
            .order-title { font-size: 28px; }
            .order-item { grid-template-columns: 50px minmax(0, 1fr); }
            .order-item strong { grid-column: 2; }
            .shipment-label-actions, .courier-order-actions { display: grid; grid-template-columns: 1fr; }
            .shipment-label-actions .button, .courier-order-actions .button { width: 100%; }
            .packing-mobile-workflow-nav { position: fixed; z-index: 55; inset: auto 0 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; padding: 8px 10px calc(8px + env(safe-area-inset-bottom, 0px)); border-top: 1px solid var(--border); background: rgba(255, 254, 253, .96); box-shadow: 0 -12px 28px rgba(33, 28, 24, .14); backdrop-filter: blur(12px); }
            .packing-mobile-workflow-link { min-width: 0; min-height: 58px; display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 5px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 8px; background: var(--surface); color: var(--muted); text-decoration: none; font-size: 12px; font-weight: 780; line-height: 1.15; }
            .packing-mobile-workflow-link.active { border-color: rgba(134, 115, 100, .48); background: var(--green-soft); color: var(--green-dark); }
            .packing-mobile-workflow-label { min-width: 0; overflow-wrap: anywhere; }
            .packing-mobile-workflow-count { min-width: 23px; min-height: 23px; display: inline-grid; place-items: center; border-radius: 999px; padding: 0 5px; background: rgba(134, 115, 100, .14); font-size: 11px; }
            .packing-action-toast { bottom: calc(88px + env(safe-area-inset-bottom, 0px)); }
            .packing-image-modal { padding: 12px; }
            .packing-image-modal-card { width: 100%; max-height: 96dvh; }
            .packing-image-modal img { max-height: calc(96dvh - 52px); }
            .collect-actions { grid-template-columns: 1fr; }
            .packing-split-line { grid-template-columns: 1fr; }
            .packing-split-actions { display: grid; grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    @php
        $qty = fn ($value) => floor((float) $value) === (float) $value
            ? number_format((float) $value, 0, ',', ' ')
            : number_format((float) $value, 4, ',', ' ');
        $money = fn ($value, $currency = 'PLN') => number_format((float) $value, 2, ',', ' ') . ' ' . ($currency ?: 'PLN');
        $person = function (array $data): string {
            $name = trim(implode(' ', array_filter([
                $data['first_name'] ?? null,
                $data['last_name'] ?? null,
            ])));
            $company = trim((string) ($data['company'] ?? ''));

            return trim(implode(' / ', array_filter([$name, $company]))) ?: '-';
        };
        $address = function (array $data): string {
            $street = trim(implode(' ', array_filter([
                $data['address_1'] ?? null,
                $data['address_2'] ?? null,
            ])));
            $city = trim(implode(' ', array_filter([
                $data['postcode'] ?? null,
                $data['city'] ?? null,
            ])));

            return trim(implode(', ', array_filter([
                $street,
                $city,
                $data['country'] ?? null,
            ]))) ?: '-';
        };
        $modeLabels = [
            'manual' => 'Bez skanera',
            'hybrid' => 'Hybrydowy',
            'scanner' => 'Skaner',
        ];
        $historyStatusLabels = [
            'picked' => 'Zebrane',
            'packed' => 'Spakowane',
            'shipped' => 'Wysłane',
        ];
        $segmentLabels = [
            'all' => 'Wszystko',
            'clothing' => 'Odzież',
            'footwear' => 'Obuwie',
        ];
        $erpUser = request()->attributes->get('erp_user') ?: auth()->user();
        $canManagePackingSettings = ! is_object($erpUser)
            || ! method_exists($erpUser, 'canAccessArea')
            || $erpUser->canAccessArea('settings');
        $canViewOrders = ! is_object($erpUser)
            || ! method_exists($erpUser, 'canAccessArea')
            || $erpUser->canAccessArea('orders');
        $canEditOrders = ! is_object($erpUser)
            || ! method_exists($erpUser, 'canAccessArea')
            || $erpUser->canAccessArea('order_editing');
        $waitingCourierOrders = $waitingCourierGroups->sum('orders_count');
        $segmentQuery = fn (string $segment): array => array_filter([
            'view' => $packingView,
            'segment' => $segment,
        ]);
        $activeModeLabel = $modeLabels[$packingMode] ?? $packingMode;
        $activeStationLabel = $activeStation !== null
            ? $activeStation['name'] . ($activeStation['printer_name'] !== '' ? ' · ' . $activeStation['printer_name'] : '')
            : 'Bez stanowiska';
        $shippingProviderResolver = app(\App\Services\Shipping\ShippingProviderResolver::class);
        $problemOrders = $problemTasks->groupBy('external_order_id');
        $workflowTabs = [
            'collect' => ['label' => 'Kompletacja', 'count' => $collectOrdersCount],
            'pack' => ['label' => 'Pakowanie', 'count' => $readyOrders->count()],
            'waiting' => ['label' => 'Oczekuje na kuriera', 'count' => $waitingCourierOrders],
            'shipped' => ['label' => 'Wysłane', 'count' => $shippedOrdersCount],
            'problems' => ['label' => 'Problemy', 'count' => $problemOrders->count()],
        ];
    @endphp

    <div class="packing-action-toast" data-packing-action-toast role="status" aria-live="polite" hidden></div>

    @if ($packingView === 'home')
        <section class="packing-toolbar" aria-label="Ustawienia pracy pakowania">
            <div class="packing-toolbar-summary">
                <span class="packing-toolbar-chip">Tryb: {{ $activeModeLabel }}</span>
                <span class="packing-toolbar-chip">Stanowisko: {{ $activeStationLabel }}</span>
            </div>
            <button class="button secondary packing-settings-trigger" type="button" data-packing-settings-open>
                Ustawienia pracy
            </button>
        </section>

        <div class="packing-settings-overlay" data-packing-settings-overlay hidden>
            <button class="packing-settings-backdrop" type="button" aria-label="Zamknij ustawienia pracy" data-packing-settings-close></button>
            <aside class="packing-settings-drawer" role="dialog" aria-modal="true" aria-labelledby="packing-settings-title">
                <div class="packing-drawer-header">
                    <div>
                        <h2 id="packing-settings-title">Ustawienia pracy</h2>
                        <span class="muted">Tryb kompletacji, stanowisko i domyślna drukarka dla tej sesji.</span>
                    </div>
                    <button class="button secondary drawer-close" type="button" aria-label="Zamknij" data-packing-settings-close>&times;</button>
                </div>

                <article class="packing-control-section">
                    <div class="mode-copy">
                        <strong>Sposób pracy</strong>
                        Bez skanera system sortuje kompletację po lokalizacji magazynowej. Tryb skanera zostaje jako ustawienie procesu, kiedy magazyn będzie gotowy na skanowanie.
                    </div>
                    <div class="mode-actions" aria-label="Tryb pakowania">
                        @foreach ($modeLabels as $mode => $label)
                            <form method="POST" action="{{ route('packing.mode') }}">
                                @csrf
                                <input type="hidden" name="mode" value="{{ $mode }}">
                                <button @class(['mode-button', 'active' => $packingMode === $mode]) type="submit">{{ $label }}</button>
                            </form>
                        @endforeach
                    </div>
                </article>

                <article class="packing-control-section">
                    <div class="packing-control-header">
                        <div class="mode-copy">
                            <strong>Twoje stanowisko pakowania</strong>
                            Stanowisko ustawia domyślny widok kompletacji i pakowania oraz drukarkę etykiet.
                        </div>
                        @if ($canManagePackingSettings)
                            <a class="button secondary" href="{{ route('settings.packing') }}">Drukarki i stanowiska</a>
                        @endif
                    </div>
                    <div class="mode-actions" aria-label="Stanowisko pakowania">
                        @foreach ($packingStations as $stationOption)
                            <form method="POST" action="{{ route('packing.station') }}">
                                @csrf
                                <input type="hidden" name="station" value="{{ $stationOption['code'] }}">
                                <button @class(['mode-button', 'active' => ($activeStation['code'] ?? null) === $stationOption['code']]) type="submit">
                                    {{ $stationOption['name'] }} · {{ $segmentLabels[$stationOption['segment']] ?? $stationOption['segment'] }}
                                    @if ($stationOption['printer_name'] !== '')
                                        · {{ $stationOption['printer_name'] }}
                                    @endif
                                </button>
                            </form>
                        @endforeach
                        <form method="POST" action="{{ route('packing.station') }}">
                            @csrf
                            <button @class(['mode-button', 'active' => $activeStation === null]) type="submit">Bez stanowiska (wszystkie produkty)</button>
                        </form>
                    </div>
                </article>
            </aside>
        </div>

        <section class="packing-stats" aria-label="Status wysyłki">
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'collect']) }}">
                <span class="muted">Do zebrania</span>
                <strong>{{ $collectOrdersCount }}</strong>
            </a>
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'pack']) }}">
                <span class="muted">Do pakowania</span>
                <strong>{{ $readyOrders->count() }}</strong>
            </a>
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'waiting']) }}">
                <span class="muted">Oczekuje na kuriera</span>
                <strong>{{ $waitingCourierOrders }}</strong>
            </a>
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'shipped']) }}">
                <span class="muted">Wysłane</span>
                <strong>{{ $shippedOrdersCount }}</strong>
            </a>
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'problems']) }}">
                <span class="muted">Problemy</span>
                <strong>{{ $problemTasks->count() }}</strong>
            </a>
        </section>

    @endif

    @if ($packingView !== 'home' && $packingView !== 'history')
        <nav class="packing-workflow-tabs" aria-label="Etapy realizacji zamówień">
            @foreach ($workflowTabs as $view => $tab)
                <a @class(['packing-workflow-tab', 'active' => $packingView === $view]) href="{{ route('packing.index', ['view' => $view]) }}">
                    {{ $tab['label'] }}
                    <span class="packing-workflow-tab-count">{{ $tab['count'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    <nav class="packing-mobile-workflow-nav" data-packing-mobile-navigation aria-label="Szybkie etapy realizacji">
        @foreach (['collect', 'pack', 'waiting'] as $view)
            <a
                @class(['packing-mobile-workflow-link', 'active' => $packingView === $view])
                href="{{ route('packing.index', ['view' => $view]) }}"
                data-packing-mobile-view="{{ $view }}"
                @if ($packingView === $view) aria-current="page" @endif
            >
                <span class="packing-mobile-workflow-label">{{ $workflowTabs[$view]['label'] }}</span>
                <span class="packing-mobile-workflow-count">{{ $workflowTabs[$view]['count'] }}</span>
            </a>
        @endforeach
    </nav>

    @if (in_array($packingView, ['collect', 'pack'], true))
        <nav class="segment-tabs" aria-label="Podział asortymentu">
            @foreach ($segmentLabels as $segmentValue => $segmentLabel)
                <a @class(['segment-tab', 'active' => $activeSegment === $segmentValue]) href="{{ route('packing.index', $segmentQuery($segmentValue)) }}">
                    {{ $segmentLabel }}
                    @if ($packingView === 'collect')
                        <span class="segment-tab-count">{{ $segmentCounts[$segmentValue] ?? 0 }}</span>
                    @endif
                </a>
            @endforeach
            @if ($activeStation !== null)
                <span class="station-chip">{{ $activeStation['name'] }}@if ($activeStation['printer_name'] !== '') · {{ $activeStation['printer_name'] }}@endif</span>
            @endif
        </nav>
    @endif

    @if ($packingView === 'collect')
        <div class="collection-workspace">
            <div class="queue-list">
                @forelse ($collectOrders as $collectOrder)
                    @php
                        $problemFormId = 'problem-order-' . md5(implode('-', $collectOrder['task_ids']));
                        $splitModalId = 'split-order-' . $collectOrder['order_id'];
                    @endphp
                    <article class="order-card collect-order-card" data-packing-card>
                        <div class="order-card-header">
                            <div>
                                <div class="order-title">Zamówienie {{ $collectOrder['order_number'] }}</div>
                                <div class="collect-order-customer">Odbiorca: {{ $collectOrder['customer_name'] }}</div>
                                <div class="order-meta">
                                    {{ $collectOrder['courier'] }} · {{ $collectOrder['positions_count'] }} poz. · złożone {{ $collectOrder['order_date']?->format('Y-m-d H:i') ?? '-' }}
                                </div>
                            </div>
                            <div class="order-badges">
                                @foreach ($collectOrder['segments'] as $segment)
                                    <span class="pick-badge segment-{{ $segment }}">{{ $segmentLabels[$segment] ?? $segment }}</span>
                                @endforeach
                                <span class="qty-pill">{{ $qty($collectOrder['quantity']) }} szt.</span>
                            </div>
                        </div>

                        <div class="order-items">
                            @foreach ($collectOrder['tasks'] as $task)
                                @php
                                    $taskLocation = data_get($task->metadata, 'warehouse_location')
                                        ?: data_get($task->product?->attributes, 'master.stock.location')
                                        ?: data_get($task->product?->attributes, 'warehouse_location')
                                        ?: '-';
                                @endphp
                                <div class="order-item">
                                    @include('packing._product-thumbnail', [
                                        'imageUrl' => $task->imageUrl(),
                                        'thumbnailUrl' => $task->thumbnailUrl(),
                                        'imageTitle' => ($task->sku ?: 'brak SKU').' - '.$task->product_name,
                                        'imageAlt' => $task->product_name,
                                    ])
                                    <div>
                                        <div class="order-item-name">{{ $task->product_name }}</div>
                                        <div class="order-item-meta">{{ $task->sku ?: 'brak SKU' }} · Rozmiar <strong>{{ $task->size_label ?: '-' }}</strong> · Lok. {{ $taskLocation }}</div>
                                    </div>
                                    <strong>{{ $qty($task->remainingQuantity()) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>

                        <div class="collect-actions">
                            <form id="{{ $problemFormId }}" method="POST" action="{{ route('packing.groups.problem') }}" data-packing-ajax data-packing-problem>
                                @csrf
                                <input type="hidden" name="restore_stock" value="1">
                                @foreach ($collectOrder['task_ids'] as $taskId)
                                    <input type="hidden" name="task_ids[]" value="{{ $taskId }}">
                                @endforeach
                                <input name="reason" placeholder="Notatka problemu dla klienta" required maxlength="1000">
                                <button class="button danger" type="submit">Problem</button>
                            </form>
                            <form method="POST" action="{{ route('packing.groups.pick') }}" data-packing-ajax>
                                @csrf
                                @foreach ($collectOrder['task_ids'] as $taskId)
                                    <input type="hidden" name="task_ids[]" value="{{ $taskId }}">
                                @endforeach
                                <button class="button" type="submit">Zebrane</button>
                            </form>
                            <button
                                class="button secondary"
                                type="button"
                                data-packing-split-open="{{ $splitModalId }}"
                                data-packing-split-availability-url="{{ route('packing.orders.split.availability', $collectOrder['order_id']) }}"
                            >Podziel zamówienie</button>
                        </div>
                    </article>
                    <dialog class="packing-split-modal" id="{{ $splitModalId }}" aria-labelledby="{{ $splitModalId }}-title">
                        <div class="packing-split-header">
                            <div>
                                <h2 id="{{ $splitModalId }}-title">Podziel zamówienie {{ $collectOrder['order_number'] }}</h2>
                                <p>Wskaż produkty i ilości, które mają trafić do nowego zamówienia.</p>
                            </div>
                            <button class="packing-split-close" type="button" data-packing-split-close aria-label="Zamknij">&times;</button>
                        </div>
                        <div class="packing-split-body">
                            <div class="alert warning" style="margin: 0 0 14px;" data-packing-split-availability role="status">
                                Sprawdzam możliwość podziału…
                            </div>
                            <form class="packing-split-form" method="POST" action="{{ route('packing.orders.split', $collectOrder['order_id']) }}">
                                @csrf
                                <input type="hidden" name="split_request_uuid" value="{{ $collectOrder['split_request_uuid'] }}">
                                <input type="hidden" name="segment" value="{{ $activeSegment }}">
                                <div class="packing-split-lines">
                                    @foreach ($collectOrder['split_lines'] as $splitLine)
                                        <div class="packing-split-line">
                                            <div>
                                                <strong>{{ $splitLine['name'] }}</strong>
                                                <span>{{ $splitLine['sku'] ?: 'brak SKU' }} · w zamówieniu: {{ $qty($splitLine['quantity']) }} szt.</span>
                                            </div>
                                            <label>Ilość do wydzielenia
                                                <input
                                                    name="split_lines[{{ $splitLine['id'] }}][quantity]"
                                                    type="number"
                                                    min="0"
                                                    max="{{ $splitLine['quantity'] }}"
                                                    step="0.0001"
                                                    value="0"
                                                >
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                                <label>Notatka do nowego zamówienia
                                    <textarea name="note" rows="2" maxlength="1000" placeholder="np. osobna wysyłka lub późniejsza realizacja"></textarea>
                                </label>
                                <div class="packing-split-actions">
                                    <button class="button secondary" type="button" data-packing-split-close>Anuluj</button>
                                    <button class="button" type="submit" data-packing-split-submit disabled>Utwórz nowe zamówienie</button>
                                </div>
                            </form>
                        </div>
                    </dialog>
                @empty
                    <div class="packing-empty">Brak produktów do zebrania. Nie ma też zamówień oczekujących na kompletację.</div>
                @endforelse
            </div>

            <section class="card history-panel">
                <div class="panel-header">
                    <span>Historia kompletacji</span>
                    <span>{{ $recentPickedTasks->count() }} ostatnich pozycji</span>
                </div>
                <div class="history-list">
                    @forelse ($recentPickedTasks as $task)
                        <article class="history-card">
                            <div>
                                <strong>{{ $task->product_name }}</strong><br>
                                <span class="muted">{{ $task->sku ?: 'brak SKU' }} · rozmiar {{ $task->size_label ?: '-' }} · zam. {{ $task->order_number }}</span>
                            </div>
                            <div class="history-badges">
                                <span @class(['status', 'blue' => $task->status === 'picked', 'orange' => $task->status === 'packed'])>{{ $historyStatusLabels[$task->status] ?? $task->status }}</span>
                                <span class="pick-badge">{{ $task->picked_at?->format('Y-m-d H:i') ?? '-' }}</span>
                            </div>
                        </article>
                    @empty
                        <div class="packing-empty">Nie ma jeszcze historii kompletacji.</div>
                    @endforelse
                </div>
            </section>
        </div>
    @endif

    @if ($packingView === 'pack')
        <div class="pack-workspace">
            @if ($activeStation === null)
                <div class="alert error" role="alert">
                    Automatyczny wydruk jest wyłączony dla tej sesji. Wróć do pulpitu pakowania, otwórz „Ustawienia pracy” i wybierz stanowisko z drukarką.
                </div>
            @elseif (trim((string) ($activeStation['printer_name'] ?? '')) === '')
                <div class="alert error" role="alert">
                    Stanowisko „{{ $activeStation['name'] }}” nie ma przypisanej drukarki. Administrator może wybrać ją z listy Windows w konfiguracji stanowisk.
                </div>
            @endif
            <section class="queue-list" aria-label="Lista do pakowania">
                @forelse ($readyOrders as $order)
                    @php
                        $tasksForOrder = $order->packingTasks;
                        $firstTask = $tasksForOrder->first();
                        $shippingLabel = $order->shipmentLabels?->firstWhere('status', 'generated');
                        $shippingLabelDownloadAllowed = (bool) ($order->shipment_label_download_allowed ?? false);
                        $shippingTrackingUrl = $shippingLabel
                            ? $shippingProviderResolver->trackingUrl($shippingLabel)
                            : null;
                        $customerNote = trim((string) data_get($firstTask?->metadata, 'customer_note', ''));
                        $orderNotes = collect(data_get($firstTask?->metadata, 'order_notes', []))
                            ->pluck('note')
                            ->filter()
                            ->implode(' | ');
                        $notes = trim(implode(' | ', array_filter([$customerNote, $orderNotes])));
                        $shipping = (array) data_get($firstTask?->metadata, 'shipping', []);
                        $billing = (array) data_get($firstTask?->metadata, 'billing', []);
                        $phone = data_get($shipping, 'phone') ?: data_get($billing, 'phone') ?: '-';
                        $email = data_get($billing, 'email') ?: '-';
                        $payment = data_get($firstTask?->metadata, 'payment_method') ?: '-';
                    @endphp
                    <article class="order-card" data-packing-card>
                        <div class="order-card-header">
                            <div>
                                <div class="order-title">
                                    @if ($canViewOrders)
                                        <a href="{{ route('orders.show', $order) }}">Zamówienie {{ $order->external_number }}</a>
                                    @elseif ($canEditOrders)
                                        <a href="{{ route('orders.edit', ['order' => $order, 'return_to' => 'packing']) }}">Zamówienie {{ $order->external_number }}</a>
                                    @else
                                        Zamówienie {{ $order->external_number }}
                                    @endif
                                </div>
                                <div class="order-meta">{{ $order->salesChannel?->code ?? '-' }} · {{ $firstTask?->customer_name ?: '-' }}</div>
                            </div>
                            <div class="order-badges">
                                @php $orderSegments = $order->packing_segments ?? []; @endphp
                                @if (count($orderSegments) > 1)
                                    <span class="status orange">Mieszane</span>
                                @elseif ($orderSegments !== [])
                                    <span class="status">{{ $segmentLabels[$orderSegments[0]] ?? $orderSegments[0] }}</span>
                                @endif
                                <span class="status blue">{{ $firstTask?->courier ?: 'Kurier' }}</span>
                                <span class="status">{{ $tasksForOrder->count() }} poz.</span>
                            </div>
                        </div>

                        <div class="order-items">
                            @foreach ($tasksForOrder as $task)
                                @php
                                    $taskLocation = data_get($task->metadata, 'warehouse_location')
                                        ?: data_get($task->product?->attributes, 'master.stock.location')
                                        ?: data_get($task->product?->attributes, 'warehouse_location')
                                        ?: '-';
                                @endphp
                                <div class="order-item">
                                    @include('packing._product-thumbnail', [
                                        'imageUrl' => $task->imageUrl(),
                                        'thumbnailUrl' => $task->thumbnailUrl(),
                                        'imageTitle' => ($task->sku ?: 'brak SKU').' - '.$task->product_name,
                                        'imageAlt' => $task->product_name,
                                    ])
                                    <div>
                                        <div class="order-item-name">{{ $task->product_name }}</div>
                                        <div class="order-item-meta">{{ $task->sku ?: 'brak SKU' }} · rozmiar {{ $task->size_label ?: '-' }} · lok. {{ $taskLocation }}</div>
                                    </div>
                                    <strong>{{ $qty($task->quantity_required) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>

                        @if ($notes !== '')
                            <details class="order-notes">
                                <summary>Uwagi z WooCommerce</summary>
                                <div>{{ $notes }}</div>
                            </details>
                        @endif

                        <details class="order-details">
                            <summary>Dane wysyłki i płatności</summary>
                            <div class="order-details-grid">
                                <div><strong>Status Woo:</strong> {{ $order->status ?? '-' }}</div>
                                <div><strong>Wartość:</strong> {{ $money($order->total_gross ?? 0, $order->currency ?? 'PLN') }}</div>
                                <div><strong>Płatność:</strong> {{ $payment }}</div>
                                <div><strong>Kontakt:</strong> {{ $email }} · {{ $phone }}</div>
                                <div><strong>Wysyłka:</strong> {{ $person($shipping) }} · {{ $address($shipping) }}</div>
                                <div><strong>Billing:</strong> {{ $person($billing) }} · {{ $address($billing) }}</div>
                            </div>
                            @if ($canEditOrders)
                                <div class="order-details-actions">
                                    <a class="button" href="{{ route('orders.edit', ['order' => $order, 'return_to' => 'packing']) }}">Edytuj zamówienie</a>
                                </div>
                            @endif
                        </details>

                        <div class="order-actions">
                            @php $detectedShippingProvider = $order->detected_shipping_provider ?? null; @endphp
                            @if ($shippingLabel)
                                <div class="shipment-label-panel">
                                    <div class="shipment-label-number">
                                        Nr etykiety: {{ $shippingLabel->trackingIdentifier() ?: '#'.$shippingLabel->id }}
                                    </div>
                                    <div class="shipment-label-actions">
                                        @if ($shippingLabelDownloadAllowed)
                                            <a class="button secondary" href="{{ route('packing.labels.download', $shippingLabel) }}">Pobierz etykietę</a>
                                        @endif
                                        @if ($shippingTrackingUrl)
                                            <a class="button secondary" href="{{ $shippingTrackingUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Śledź przesyłkę {{ $shippingLabel->trackingIdentifier() }}">Śledź paczkę</a>
                                        @endif
                                    </div>
                                </div>
                            @else
                                @if ($detectedShippingProvider !== 'gls')
                                <form class="label-account-form" method="POST" action="{{ route('packing.orders.complete-with-label', $order) }}" data-packing-ajax>
                                    @csrf
                                    @if ($courierAccounts->isNotEmpty())
                                        <select name="courier_account_id" aria-label="Konto nadawcze InPost">
                                            <option value="">Etykieta ze sklepu</option>
                                            @foreach ($courierAccounts as $courierAccount)
                                                <option value="{{ $courierAccount->id }}" @selected($courierAccount->is_default)>InPost: {{ $courierAccount->name }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <div class="label-size-actions" role="group" aria-label="Wybierz gabaryt paczki">
                                        <button class="button secondary" type="submit" name="parcel_template" value="small" aria-label="Generuj etykietę, wydrukuj i spakuj, gabaryt A">
                                            A<small>mała</small>
                                        </button>
                                        <button class="button secondary" type="submit" name="parcel_template" value="medium" aria-label="Generuj etykietę, wydrukuj i spakuj, gabaryt B">
                                            B<small>średnia</small>
                                        </button>
                                        <button class="button secondary" type="submit" name="parcel_template" value="large" aria-label="Generuj etykietę, wydrukuj i spakuj, gabaryt C">
                                            C<small>duża</small>
                                        </button>
                                    </div>
                                </form>
                                @endif
                                <form class="packing-choice-form" method="POST" action="{{ route('packing.orders.pack-manual-shipment', $order) }}" data-packing-ajax>
                                    @csrf
                                    <div class="packing-action-title">Mam już numer przesyłki</div>
                                    <p class="packing-action-help">Zapisz numer od przewoźnika i zakończ pakowanie.</p>
                                    <div class="manual-shipment-controls">
                                        @if ($detectedShippingProvider)
                                            <input type="hidden" name="provider" value="{{ $detectedShippingProvider }}">
                                            <span class="status">{{ $detectedShippingProvider === 'gls' ? 'GLS' : 'InPost' }}</span>
                                        @else
                                        <select name="provider" required aria-label="Przewoźnik ręcznej przesyłki">
                                            <option value="inpost">InPost</option>
                                            <option value="gls">GLS</option>
                                        </select>
                                        @endif
                                        <input name="tracking_number" maxlength="40" required placeholder="Numer przesyłki">
                                        <button class="button secondary" type="submit">Zapisz i spakuj</button>
                                    </div>
                                </form>
                                <form class="packing-choice-form" method="POST" action="{{ route('packing.orders.pack', $order) }}" data-packing-ajax onsubmit="return confirm('Spakować zamówienie bez listu przewozowego?');">
                                    @csrf
                                    <div class="packing-action-title">Bez listu przewozowego</div>
                                    <p class="packing-action-help">Przejdź dalej bez numeru i bez śledzenia przesyłki.</p>
                                    <button class="button secondary" type="submit">Pomiń list i spakuj</button>
                                </form>
                            @endif
                            <form class="order-problem-form" method="POST" action="{{ route('packing.orders.problem', $order) }}" data-packing-ajax data-packing-problem>
                                @csrf
                                <input type="hidden" name="restore_stock" value="1">
                                <div class="packing-action-title">Problem z zamówieniem</div>
                                <input name="reason" placeholder="Notatka problemu dla klienta" required maxlength="1000">
                                <button class="button danger" type="submit">Problem</button>
                            </form>
                            @if ($shippingLabel)
                                <form method="POST" action="{{ route('packing.orders.pack', $order) }}" data-packing-ajax>
                                    @csrf
                                    <button class="button" type="submit">{{ in_array(data_get($shippingLabel->response_payload, 'source'), ['manual_tracking_number', 'manual_inpost_tracking_number'], true) ? 'Spakuj' : 'Spakuj i wydrukuj' }}</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="packing-empty">Brak zamówień gotowych do pakowania. Po kompletacji zamówienia pojawią się tutaj automatycznie.</div>
                @endforelse
            </section>
        </div>
    @endif

    @if ($packingView === 'waiting')
        <div class="pack-workspace">
            <section class="card courier-panel">
                <div class="panel-header">
                    <span>Oczekuje na kuriera</span>
                    <div class="courier-panel-actions">
                        <span>{{ $waitingCourierOrders }} paczek</span>
                        <form method="POST" action="{{ route('packing.couriers.check-pickups') }}">
                            @csrf
                            <button class="button secondary" type="submit">Sprawdź odbiory</button>
                        </form>
                    </div>
                </div>
                <div class="courier-list">
                    @forelse ($waitingCourierGroups as $group)
                        @php
                            $oldestPacked = $group['oldest_packed_at'] ? \Illuminate\Support\Carbon::parse($group['oldest_packed_at']) : null;
                        @endphp
                        <article class="courier-card">
                            <div class="courier-card-header">
                                <div>
                                    <div class="courier-title">{{ $group['courier'] }}</div>
                                    <div class="courier-meta">
                                        {{ $group['orders_count'] }} paczek, {{ $group['tasks_count'] }} pozycji
                                        @if ($oldestPacked)
                                            · najstarsze {{ $oldestPacked->format('Y-m-d H:i') }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="courier-orders">
                                @foreach ($group['orders'] as $queuedOrder)
                                    @php
                                        $queuedPackedAt = $queuedOrder['packed_at'] ? \Illuminate\Support\Carbon::parse($queuedOrder['packed_at']) : null;
                                    @endphp
                                    <div class="courier-order-row">
                                        <div class="courier-order-main">
                                            <div>
                                                <strong><a href="{{ route('orders.show', $queuedOrder['id']) }}">Zamówienie {{ $queuedOrder['external_number'] }}</a></strong><br>
                                            <span class="muted">
                                                {{ $queuedOrder['customer_name'] }} · {{ $queuedOrder['tasks_count'] }} poz.
                                                @if ($queuedPackedAt)
                                                    · spakowane {{ $queuedPackedAt->format('Y-m-d H:i') }}
                                                @endif
                                            </span>
                                            </div>
                                            <div class="order-items" aria-label="Produkty w zamówieniu {{ $queuedOrder['external_number'] }}">
                                                @foreach ($queuedOrder['items'] ?? [] as $item)
                                                    <div class="order-item">
                                                        @include('packing._product-thumbnail', [
                                                            'imageUrl' => $item['image_url'],
                                                            'thumbnailUrl' => $item['thumbnail_url'],
                                                            'imageTitle' => ($item['sku'] ?: 'brak SKU').' - '.$item['name'],
                                                            'imageAlt' => $item['name'],
                                                        ])
                                                        <div>
                                                            <div class="order-item-name">{{ $item['name'] }}</div>
                                                            <div class="order-item-meta">{{ $item['sku'] ?: 'brak SKU' }} · rozmiar {{ $item['size_label'] ?: '-' }}</div>
                                                        </div>
                                                        <strong>{{ $qty($item['quantity']) }} szt.</strong>
                                                    </div>
                                                @endforeach
                                            </div>
                                            <div class="tracking-state">
                                                @if ($queuedOrder['label_number'])
                                                    Etykieta: <strong>{{ $queuedOrder['label_number'] }}</strong>
                                                    @if ($queuedOrder['tracking_status'])
                                                        · status: {{ $queuedOrder['tracking_status'] }}
                                                    @endif
                                                    @if ($queuedOrder['tracking_checked_at'])
                                                        · sprawdzono {{ $queuedOrder['tracking_checked_at']->format('Y-m-d H:i') }}
                                                    @endif
                                                    @if ($queuedOrder['tracking_error'])
                                                        <br>Ostatni błąd: {{ $queuedOrder['tracking_error'] }}
                                                    @endif
                                                @else
                                                    Brak etykiety — cofnij pakowanie, wybierz gabaryt A/B/C i wygeneruj ją.
                                                    @if ($queuedOrder['label_error'])
                                                        <br>{{ $queuedOrder['label_error'] }}
                                                    @endif
                                                @endif
                                            </div>
                                            <div class="courier-order-actions">
                                                @if ($queuedOrder['label_id'])
                                                    <a class="button secondary" href="{{ route('packing.labels.download', $queuedOrder['label_id']) }}">Pobierz etykietę</a>
                                                    <form method="POST" action="{{ route('packing.labels.print', $queuedOrder['label_id']) }}">
                                                        @csrf
                                                        <input type="hidden" name="request_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                                        <button class="button secondary" type="submit">Drukuj</button>
                                                    </form>
                                                @endif
                                                @if ($queuedOrder['tracking_url'])
                                                    <a class="button secondary" href="{{ $queuedOrder['tracking_url'] }}" target="_blank" rel="noopener noreferrer">Śledź paczkę</a>
                                                @endif
                                                <form method="POST" action="{{ route('packing.orders.mark-shipped', $queuedOrder['id']) }}" onsubmit="return confirm('Oznaczyć to zamówienie jako wysłane?');">
                                                    @csrf
                                                    <button class="button" type="submit">Oznacz jako wysłane</button>
                                                </form>
                                            </div>
                                        </div>
                                        <form class="order-rollback-form" method="POST" action="{{ route('packing.orders.unpack', $queuedOrder['id']) }}">
                                            @csrf
                                            <input name="reason" placeholder="Powód cofnięcia">
                                            <button class="button secondary" type="submit">Cofnij pakowanie — do etapu pakowania</button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <div class="packing-empty">Nie ma paczek oczekujących na kuriera.</div>
                    @endforelse
                </div>
            </section>
        </div>
    @endif

    @if ($packingView === 'problems')
        <div class="pack-workspace">
                <section class="card problem-panel">
                    <div class="panel-header">
                        <span>Do wyjaśnienia</span>
                        <span>{{ $problemOrders->count() }} zam. · {{ $problemTasks->count() }} poz.</span>
                    </div>
                    <div class="problem-list">
                        @forelse ($problemOrders as $tasksForProblemOrder)
                            @php
                                $problemTask = $tasksForProblemOrder->first();
                                $problemOrder = $problemTask?->order;
                                $problemReason = data_get($problemTask?->metadata, 'packing_problem.reason', 'Do wyjaśnienia');
                                $problemAt = data_get($problemTask?->metadata, 'packing_problem.reported_at');
                                $problemMailStatus = data_get($problemTask?->metadata, 'packing_problem.customer_message_status');
                            @endphp
                            <article class="problem-card">
                                <div class="problem-card-header">
                                    <div>
                                        <strong>
                                            @if ($problemOrder)
                                                <a href="{{ route('orders.show', $problemOrder) }}">Zamówienie {{ $problemOrder->external_number }}</a>
                                            @else
                                                Zamówienie {{ $problemTask?->order_number }}
                                            @endif
                                        </strong><br>
                                        <span class="muted">{{ $problemTask?->customer_name ?: '-' }} · {{ $problemTask?->courier ?: 'Kurier' }} · {{ $tasksForProblemOrder->count() }} poz.</span>
                                    </div>
                                    <span class="status red">{{ $problemOrder?->status === 'cancelled' ? 'Anulowane' : 'Problem' }}</span>
                                </div>
                                <div class="problem-reason">Notatka: {{ $problemReason }}</div>
                                <div class="problem-order-items">
                                    @foreach ($tasksForProblemOrder as $task)
                                        @php
                                            $problemLocation = data_get($task->metadata, 'warehouse_location')
                                                ?: data_get($task->product?->attributes, 'master.stock.location')
                                                ?: data_get($task->product?->attributes, 'warehouse_location')
                                                ?: '-';
                                        @endphp
                                        <div class="order-item problem-order-item">
                                            @include('packing._product-thumbnail', [
                                                'imageUrl' => $task->imageUrl(),
                                                'thumbnailUrl' => $task->thumbnailUrl(),
                                                'imageTitle' => ($task->sku ?: 'brak SKU').' - '.$task->product_name,
                                                'imageAlt' => $task->product_name,
                                            ])
                                            <div>
                                                <div class="order-item-name">{{ $task->product_name }}</div>
                                                <div class="order-item-meta">{{ $task->sku ?: 'brak SKU' }} · lok. {{ $problemLocation }}</div>
                                            </div>
                                            <strong>{{ $qty($task->quantity_required) }} szt.</strong>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="muted">
                                    Zgłoszono: {{ $problemAt ? \Illuminate\Support\Carbon::parse($problemAt)->format('Y-m-d H:i') : $problemTask?->updated_at?->format('Y-m-d H:i') }}
                                    · Status WooCommerce: {{ $problemOrder?->status ?: '-' }}
                                    @if ($problemMailStatus)
                                        · E-mail: {{ $problemMailStatus }}
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="packing-empty">Nie ma pozycji wymagających wyjaśnienia.</div>
                        @endforelse
                    </div>
                </section>
        </div>
    @endif

    @if ($packingView === 'shipped')
        <div class="pack-workspace">
            <section class="queue-list" aria-label="Lista wysłanych zamówień">
                @forelse ($shippedOrders as $shippedOrder)
                    <article class="order-card packing-history-order">
                        <div class="order-card-header">
                            <div>
                                <div class="order-title">Zamówienie {{ $shippedOrder['order_number'] }}</div>
                                <div class="history-order-meta">
                                    {{ $shippedOrder['sales_channel'] }} · {{ $shippedOrder['customer_name'] }} · {{ $shippedOrder['courier'] }} · {{ $shippedOrder['tasks_count'] }} poz.
                                </div>
                            </div>
                            <div class="order-badges">
                                <span class="status green">Wysłane</span>
                                <span class="status">{{ $shippedOrder['pickup_at']?->format('Y-m-d H:i') ?? '-' }}</span>
                            </div>
                        </div>

                        <div class="history-order-meta">
                            Spakowane: {{ $shippedOrder['packed_at']?->format('Y-m-d H:i') ?? '-' }}
                            @if ($shippedOrder['pickup_at'])
                                · odebrane przez kuriera: {{ $shippedOrder['pickup_at']->format('Y-m-d H:i') }}
                            @endif
                        </div>

                        @if ($shippedOrder['label_number'])
                            <div class="shipment-label-panel">
                                <div class="shipment-label-number">Nr przesyłki: {{ $shippedOrder['label_number'] }}</div>
                                <div class="shipment-label-actions">
                                    @if ($shippedOrder['label_id'])
                                        <a class="button secondary" href="{{ route('packing.labels.download', $shippedOrder['label_id']) }}">Pobierz etykietę</a>
                                    @endif
                                    @if ($shippedOrder['tracking_url'])
                                        <a class="button secondary" href="{{ $shippedOrder['tracking_url'] }}" target="_blank" rel="noopener noreferrer">Śledź paczkę</a>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="order-items">
                            @foreach ($shippedOrder['items'] as $item)
                                <div class="order-item">
                                    @include('packing._product-thumbnail', [
                                        'imageUrl' => $item['image_url'],
                                        'thumbnailUrl' => $item['thumbnail_url'],
                                        'imageTitle' => ($item['sku'] ?: 'brak SKU').' - '.$item['name'],
                                        'imageAlt' => $item['name'],
                                    ])
                                    <div>
                                        <div class="order-item-name">{{ $item['name'] }}</div>
                                        <div class="order-item-meta">{{ $item['sku'] ?: 'brak SKU' }} · rozmiar {{ $item['size_label'] ?: '-' }}</div>
                                    </div>
                                    <strong>{{ $qty($item['quantity']) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <div class="packing-empty">Nie ma jeszcze wysłanych zamówień.</div>
                @endforelse
            </section>
        </div>
    @endif

    @if ($packingView === 'history')
        <div class="pack-workspace">
            <form class="history-toolbar" method="GET" action="{{ route('packing.index') }}">
                <input type="hidden" name="view" value="history">
                <label>
                    Data pakowania
                    <input type="date" name="date" value="{{ $packingHistoryDate }}">
                </label>
                <button class="button secondary" type="submit">Pokaż historię</button>
            </form>

            <section class="queue-list" aria-label="Historia pakowania według daty">
                @forelse ($packingHistoryOrders as $historyOrder)
                    <article class="order-card packing-history-order">
                        <div class="order-card-header">
                            <div>
                                <div class="order-title">Zamówienie {{ $historyOrder['order_number'] }}</div>
                                <div class="history-order-meta">
                                    {{ $historyOrder['sales_channel'] }} · {{ $historyOrder['customer_name'] }} · {{ $historyOrder['courier'] }} · {{ $historyOrder['tasks_count'] }} poz.
                                </div>
                            </div>
                            <div class="order-badges">
                                <span @class(['status', 'orange' => $historyOrder['status'] === 'packed', 'green' => $historyOrder['status'] === 'shipped'])>{{ $historyStatusLabels[$historyOrder['status']] ?? $historyOrder['status'] }}</span>
                                <span class="status">{{ $historyOrder['packed_at']?->format('H:i') ?? '-' }}</span>
                            </div>
                        </div>

                        <div class="history-order-meta">
                            Spakowane: {{ $historyOrder['packed_at']?->format('Y-m-d H:i') ?? '-' }}
                            @if ($historyOrder['pickup_at'])
                                · odebrane przez kuriera: {{ $historyOrder['pickup_at']->format('Y-m-d H:i') }}
                            @endif
                        </div>

                        <div class="order-items">
                            @foreach ($historyOrder['items'] as $item)
                                <div class="order-item">
                                    @include('packing._product-thumbnail', [
                                        'imageUrl' => $item['image_url'],
                                        'thumbnailUrl' => $item['thumbnail_url'],
                                        'imageTitle' => ($item['sku'] ?: 'brak SKU').' - '.$item['name'],
                                        'imageAlt' => $item['name'],
                                    ])
                                    <div>
                                        <div class="order-item-name">{{ $item['name'] }}</div>
                                        <div class="order-item-meta">{{ $item['sku'] ?: 'brak SKU' }} · rozmiar {{ $item['size_label'] ?: '-' }}</div>
                                    </div>
                                    <strong>{{ $qty($item['quantity']) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>

                        @if ($historyOrder['status'] === 'packed' && $historyOrder['order_id'])
                            <div class="history-order-actions">
                                <form class="order-rollback-form" method="POST" action="{{ route('packing.orders.unpack', $historyOrder['order_id']) }}">
                                    @csrf
                                    <input name="reason" placeholder="Powód cofnięcia">
                                    <button class="button secondary" type="submit">Cofnij pakowanie — do etapu pakowania</button>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="packing-empty">Brak historii pakowania dla wybranej daty.</div>
                @endforelse
            </section>
        </div>
    @endif

    <div class="packing-image-modal" data-packing-image-modal aria-hidden="true" hidden>
        <section class="packing-image-modal-card" role="dialog" aria-modal="true" aria-labelledby="packing-image-modal-title">
            <div class="packing-image-modal-header">
                <span class="packing-image-modal-title" id="packing-image-modal-title" data-packing-image-modal-title>Podgląd produktu</span>
                <button class="packing-image-modal-close" type="button" data-packing-image-modal-close aria-label="Zamknij podgląd zdjęcia">&times;</button>
            </div>
            <img data-packing-image-modal-image alt="" referrerpolicy="no-referrer">
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            document.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const openButton = target?.closest('[data-packing-split-open]');

                if (openButton) {
                    const modal = document.getElementById(openButton.dataset.packingSplitOpen || '');

                    if (modal instanceof HTMLDialogElement) {
                        const availability = modal.querySelector('[data-packing-split-availability]');
                        const submit = modal.querySelector('[data-packing-split-submit]');
                        const availabilityUrl = openButton.dataset.packingSplitAvailabilityUrl || '';

                        modal.showModal();
                        window.requestAnimationFrame(() => modal.querySelector('input[type="number"]')?.focus());

                        if (availability instanceof HTMLElement && submit instanceof HTMLButtonElement) {
                            availability.hidden = false;
                            availability.textContent = 'Sprawdzam możliwość podziału…';
                            submit.disabled = true;

                            fetch(availabilityUrl, {
                                headers: { Accept: 'application/json' },
                                credentials: 'same-origin',
                            })
                                .then((response) => {
                                    if (!response.ok) {
                                        throw new Error('Nie udało się sprawdzić blokad podziału.');
                                    }

                                    return response.json();
                                })
                                .then((result) => {
                                    if (result.available === true) {
                                        availability.hidden = true;
                                        submit.disabled = false;

                                        return;
                                    }

                                    const heading = document.createElement('strong');
                                    heading.textContent = 'Nie można teraz podzielić tego zamówienia.';
                                    const list = document.createElement('ul');
                                    list.style.marginBottom = '0';

                                    (Array.isArray(result.reasons) ? result.reasons : []).forEach((reason) => {
                                        const item = document.createElement('li');
                                        item.textContent = String(reason);
                                        list.appendChild(item);
                                    });
                                    availability.replaceChildren(heading, list);
                                })
                                .catch((error) => {
                                    availability.textContent = error instanceof Error
                                        ? error.message
                                        : 'Nie udało się sprawdzić blokad podziału.';
                                });
                        }
                    }

                    return;
                }

                const closeButton = target?.closest('[data-packing-split-close]');

                if (closeButton) {
                    closeButton.closest('dialog')?.close();
                }
            });

            document.querySelectorAll('.packing-split-modal').forEach((modal) => {
                modal.addEventListener('click', (event) => {
                    if (event.target === modal) {
                        modal.close();
                    }
                });
            });
        })();

        (() => {
            const modal = document.querySelector('[data-packing-image-modal]');
            const image = modal?.querySelector('[data-packing-image-modal-image]');
            const title = modal?.querySelector('[data-packing-image-modal-title]');
            const closeButton = modal?.querySelector('[data-packing-image-modal-close]');
            let activeTrigger = null;
            let previousBodyOverflow = '';

            if (!modal || !image || !title || !closeButton) {
                return;
            }

            const closeModal = () => {
                if (modal.hidden) {
                    return;
                }

                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                image.removeAttribute('src');
                image.alt = '';
                document.body.style.overflow = previousBodyOverflow;

                if (activeTrigger?.isConnected) {
                    activeTrigger.focus();
                }
                activeTrigger = null;
            };

            const openModal = (trigger) => {
                const src = trigger.dataset.packingImagePreview || '';
                const imageTitle = trigger.dataset.packingImageTitle || 'Podgląd produktu';

                if (!src) {
                    return;
                }

                activeTrigger = trigger;
                previousBodyOverflow = document.body.style.overflow;
                image.src = src;
                image.alt = imageTitle;
                title.textContent = imageTitle;
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                window.requestAnimationFrame(() => closeButton.focus());
            };

            document.addEventListener('click', (event) => {
                const trigger = event.target instanceof Element
                    ? event.target.closest('[data-packing-image-preview]')
                    : null;

                if (trigger) {
                    openModal(trigger);
                    return;
                }

                if (event.target === modal) {
                    closeModal();
                }
            });
            closeButton.addEventListener('click', closeModal);
            document.addEventListener('keydown', (event) => {
                if (modal.hidden) {
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeModal();
                } else if (event.key === 'Tab') {
                    event.preventDefault();
                    closeButton.focus();
                }
            });
        })();

        (() => {
            const overlay = document.querySelector('[data-packing-settings-overlay]');
            const openButton = document.querySelector('[data-packing-settings-open]');
            const closeButtons = document.querySelectorAll('[data-packing-settings-close]');

            if (!overlay || !openButton) {
                return;
            }

            const openDrawer = () => {
                overlay.hidden = false;
                document.body.style.overflow = 'hidden';
                overlay.querySelector('.drawer-close')?.focus();
            };

            const closeDrawer = () => {
                overlay.hidden = true;
                document.body.style.overflow = '';
                openButton.focus();
            };

            openButton.addEventListener('click', openDrawer);
            closeButtons.forEach((button) => button.addEventListener('click', closeDrawer));
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !overlay.hidden) {
                    closeDrawer();
                }
            });
        })();

        (() => {
            const forms = document.querySelectorAll('form[data-packing-ajax]');
            const toast = document.querySelector('[data-packing-action-toast]');
            let toastTimer = null;

            if (forms.length === 0) {
                return;
            }

            const showMessage = (message, isError = false) => {
                if (!toast) {
                    return;
                }

                window.clearTimeout(toastTimer);
                toast.textContent = message;
                toast.classList.toggle('error', isError);
                toast.setAttribute('role', isError ? 'alert' : 'status');
                toast.hidden = false;
                toastTimer = window.setTimeout(() => {
                    toast.hidden = true;
                }, 7000);
            };

            const errorMessage = (payload, fallback) => {
                const validationMessage = Object.values(payload?.errors ?? {})
                    .flat()
                    .find((message) => typeof message === 'string' && message !== '');

                return validationMessage || payload?.message || fallback;
            };

            const showCardError = (card, message) => {
                if (!card) {
                    return;
                }

                let box = card.querySelector('[data-packing-action-error]');
                if (!box) {
                    box = document.createElement('div');
                    box.className = 'packing-action-error';
                    box.dataset.packingActionError = '';
                    box.setAttribute('role', 'alert');
                    card.append(box);
                }
                box.textContent = message;
            };

            const updateCurrentCount = (container) => {
                const remaining = container?.querySelectorAll(':scope > [data-packing-card]').length ?? 0;
                const workflowCount = document.querySelector('.packing-workflow-tab.active .packing-workflow-tab-count');
                const mobileWorkflowCount = document.querySelector('.packing-mobile-workflow-link.active .packing-mobile-workflow-count');
                const segmentCount = document.querySelector('.segment-tab.active .segment-tab-count');

                if (workflowCount) {
                    workflowCount.textContent = String(remaining);
                }
                if (mobileWorkflowCount) {
                    mobileWorkflowCount.textContent = String(remaining);
                }
                if (segmentCount) {
                    segmentCount.textContent = String(remaining);
                }
            };

            forms.forEach((form) => form.addEventListener('submit', async (event) => {
                event.preventDefault();

                if (form.hasAttribute('data-packing-problem')) {
                    const restoreStock = window.confirm(
                        'Czy przywrócić towar z anulowanego zamówienia do sprzedaży?\n\nOK = Przywróć do sprzedaży\nAnuluj = Przejdź do potwierdzenia rozchodu bez przywracania'
                    );

                    if (!restoreStock && !window.confirm(
                        'Potwierdź decyzję: towar NIE wróci do dostępnego stanu i powstanie dokument RW.\n\nOK = Nie przywracaj\nAnuluj = Wróć bez anulowania zamówienia'
                    )) {
                        return;
                    }

                    form.querySelector('input[name="restore_stock"]').value = restoreStock ? '1' : '0';
                }

                if (form.dataset.packingSubmitting === 'true') {
                    return;
                }

                const card = form.closest('[data-packing-card]');
                const container = card?.parentElement ?? null;
                const scrollPosition = window.scrollY;
                const formData = new FormData(form);
                const submitter = event.submitter;

                if (submitter?.name) {
                    formData.set(submitter.name, submitter.value);
                }

                const controls = card
                    ? Array.from(card.querySelectorAll('button, input, select, textarea'))
                    : Array.from(form.querySelectorAll('button, input, select, textarea'));
                const disabledState = controls.map((control) => control.disabled);
                let removeCardAfterSuccess = false;

                form.dataset.packingSubmitting = 'true';
                card?.setAttribute('aria-busy', 'true');
                card?.querySelector('[data-packing-action-error]')?.remove();
                controls.forEach((control) => {
                    control.disabled = true;
                });

                try {
                    const response = await fetch(form.action, {
                        method: form.method || 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || payload.ok !== true) {
                        throw new Error(errorMessage(payload, `Nie udało się wykonać akcji (HTTP ${response.status}).`));
                    }

                    showMessage(payload.message || 'Akcja została wykonana.');

                    if (payload.ui?.remove_submitted_card && card) {
                        removeCardAfterSuccess = true;
                        card.classList.add('packing-card-removing');
                        window.setTimeout(() => {
                            card.remove();
                            updateCurrentCount(container);

                            if (container && !container.querySelector('[data-packing-card]')) {
                                const empty = document.createElement('div');
                                empty.className = 'packing-empty';
                                empty.textContent = 'Brak kolejnych zamówień na tej liście.';
                                container.append(empty);
                            }

                            const maxScroll = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
                            window.scrollTo({top: Math.min(scrollPosition, maxScroll), behavior: 'auto'});
                        }, 170);
                    }
                } catch (error) {
                    const message = error instanceof Error
                        ? error.message
                        : 'Nie udało się wykonać akcji. Sprawdź połączenie i spróbuj ponownie.';
                    showCardError(card, message);
                    showMessage(message, true);
                } finally {
                    form.dataset.packingSubmitting = 'false';
                    if (!removeCardAfterSuccess) {
                        card?.removeAttribute('aria-busy');
                        controls.forEach((control, index) => {
                            control.disabled = disabledState[index];
                        });
                    }
                }
            }));
        })();
    </script>
@endpush
