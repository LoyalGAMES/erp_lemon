@php
    $bulkErrors = $errors->getBag('bulk');
    $bulkApplied = collect((array) old('apply', []));
    $bulkApplies = fn (string $field): bool => $bulkApplied->has($field);
    $bulkSelectedCategoryIds = collect((array) old('changes.category_ids', []))
        ->map(fn ($id): int => (int) $id)
        ->all();
@endphp

<div
    class="product-bulk-modal"
    data-product-bulk-modal
    data-open-on-load="{{ $bulkErrors->any() ? '1' : '0' }}"
    hidden
    aria-hidden="true"
>
    <div class="product-bulk-modal-card" role="dialog" aria-modal="true" aria-labelledby="product-bulk-modal-title">
        <form method="POST" action="{{ route('products.bulk.update') }}" data-product-bulk-form>
            @csrf
            <div data-product-bulk-selection-inputs></div>
            @foreach (['q', 'channel', 'warehouse', 'stock', 'type', 'category', 'status'] as $filter)
                <input type="hidden" name="filters[{{ $filter }}]" value="{{ $filters[$filter] }}">
            @endforeach
            <input type="hidden" name="filters[favorites]" value="{{ $isFavorites ? '1' : '0' }}">
            @if ($importIssue)
                <input type="hidden" name="filters[import_issue]" value="{{ $importIssue['log_id'] }}">
            @endif

            <div class="product-bulk-modal-header">
                <div>
                    <h2 id="product-bulk-modal-title">Edytuj wybrane produkty</h2>
                    <p>Zmiany obejmą <strong data-product-bulk-modal-count>0 produktów</strong>.</p>
                </div>
                <button class="product-bulk-modal-close" type="button" data-product-bulk-close aria-label="Zamknij">&times;</button>
            </div>

            <div class="product-bulk-modal-body">
                @if ($bulkErrors->any())
                    <div class="alert error product-bulk-errors">
                        <strong>Nie udało się wykonać edycji grupowej.</strong>
                        <ul>
                            @foreach ($bulkErrors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="product-bulk-help">
                    Zaznacz „Zmień” tylko przy polach, które mają zostać nadpisane. Niezaznaczone pola pozostaną bez zmian. Puste aktywne pole usuwa dotychczasową wartość.
                </div>

                <section class="product-bulk-section">
                    <h3>Kategorie i ceny</h3>
                    <div class="product-bulk-grid">
                        <div class="product-bulk-field wide" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[category_ids]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('category_ids'))> Zmień przypisane kategorie</label>
                            <fieldset class="product-bulk-category-list" data-product-bulk-value @disabled(! $bulkApplies('category_ids'))>
                                @foreach ($categoryOptions as $category)
                                    <label>
                                        <input name="changes[category_ids][]" type="checkbox" value="{{ $category['id'] }}" @checked(in_array((int) $category['id'], $bulkSelectedCategoryIds, true))>
                                        <span>
                                            <strong>{{ $category['path'] }}</strong>
                                            @if ($category['sales_channel'])<small>{{ $category['sales_channel'] }}</small>@endif
                                        </span>
                                    </label>
                                @endforeach
                            </fieldset>
                            <small>Brak zaznaczonych kategorii przy aktywnej zmianie usunie wszystkie przypisania.</small>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[retail_price_pln]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('retail_price_pln'))> Zmień cenę</label>
                            <label>Cena regularna brutto (PLN)
                                <input name="changes[retail_price_pln]" type="number" step="0.01" min="0" value="{{ old('changes.retail_price_pln') }}" data-product-bulk-value @disabled(! $bulkApplies('retail_price_pln'))>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[sale_price_pln]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('sale_price_pln'))> Zmień cenę promocyjną</label>
                            <label>Cena promocyjna brutto (PLN)
                                <input name="changes[sale_price_pln]" type="number" step="0.01" min="0" value="{{ old('changes.sale_price_pln') }}" data-product-bulk-value @disabled(! $bulkApplies('sale_price_pln'))>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[sale_price_starts_at]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('sale_price_starts_at'))> Zmień początek promocji</label>
                            <label>Promocja od
                                <input name="changes[sale_price_starts_at]" type="date" value="{{ old('changes.sale_price_starts_at') }}" data-product-bulk-value @disabled(! $bulkApplies('sale_price_starts_at'))>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[sale_price_ends_at]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('sale_price_ends_at'))> Zmień koniec promocji</label>
                            <label>Promocja do
                                <input name="changes[sale_price_ends_at]" type="date" value="{{ old('changes.sale_price_ends_at') }}" data-product-bulk-value @disabled(! $bulkApplies('sale_price_ends_at'))>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="product-bulk-section">
                    <h3>Publikacja i sprzedaż</h3>
                    <div class="product-bulk-grid">
                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[is_active]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('is_active'))> Zmień aktywność</label>
                            <label>Produkt aktywny
                                <select name="changes[is_active]" data-product-bulk-value @disabled(! $bulkApplies('is_active'))>
                                    <option value="1" @selected(old('changes.is_active', '1') === '1')>Tak</option>
                                    <option value="0" @selected(old('changes.is_active') === '0')>Nie</option>
                                </select>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[catalog_visibility]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('catalog_visibility'))> Zmień widoczność</label>
                            <label>Widoczność w WooCommerce
                                <select name="changes[catalog_visibility]" data-product-bulk-value @disabled(! $bulkApplies('catalog_visibility'))>
                                    @foreach (['visible' => 'Sklep i wyszukiwarka', 'catalog' => 'Tylko sklep', 'search' => 'Tylko wyszukiwarka', 'hidden' => 'Ukryty'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('changes.catalog_visibility', 'visible') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[publication_status]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('publication_status'))> Zmień status publikacji</label>
                            <label>Status publikacji
                                <select name="changes[publication_status]" data-product-bulk-value @disabled(! $bulkApplies('publication_status'))>
                                    @foreach (['publish' => 'Opublikowany', 'draft' => 'Szkic', 'pending' => 'Oczekujący', 'private' => 'Prywatny'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('changes.publication_status', 'publish') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[publication_date]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('publication_date'))> Zmień datę publikacji</label>
                            <label>Data publikacji
                                <input name="changes[publication_date]" type="datetime-local" value="{{ old('changes.publication_date') }}" data-product-bulk-value @disabled(! $bulkApplies('publication_date'))>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[backorders]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('backorders'))> Zmień zamówienia oczekujące</label>
                            <label>Zamówienia oczekujące
                                <select name="changes[backorders]" data-product-bulk-value @disabled(! $bulkApplies('backorders'))>
                                    @foreach (['no' => 'Nie zezwalaj', 'notify' => 'Zezwalaj i informuj', 'yes' => 'Zezwalaj'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('changes.backorders', 'no') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="product-bulk-section">
                    <h3>Label, termin wysyłki i przedsprzedaż</h3>
                    <div class="product-bulk-grid">
                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[custom_label_pl]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('custom_label_pl'))> Zmień Custom label PL</label>
                            <label>Custom label (PL)
                                <input name="changes[custom_label_pl]" value="{{ old('changes.custom_label_pl') }}" maxlength="120" data-product-bulk-value @disabled(! $bulkApplies('custom_label_pl'))>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[custom_label_en]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('custom_label_en'))> Zmień Custom label EN</label>
                            <label>Custom label (EN)
                                <input name="changes[custom_label_en]" value="{{ old('changes.custom_label_en') }}" maxlength="120" data-product-bulk-value @disabled(! $bulkApplies('custom_label_en'))>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[custom_label_bg_color]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('custom_label_bg_color'))> Zmień tło etykiety</label>
                            <label>Tło etykiety
                                <input name="changes[custom_label_bg_color]" type="color" value="{{ old('changes.custom_label_bg_color', '#111111') }}" data-product-bulk-value @disabled(! $bulkApplies('custom_label_bg_color'))>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[custom_label_text_color]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('custom_label_text_color'))> Zmień kolor tekstu</label>
                            <label>Kolor tekstu etykiety
                                <input name="changes[custom_label_text_color]" type="color" value="{{ old('changes.custom_label_text_color', '#ffffff') }}" data-product-bulk-value @disabled(! $bulkApplies('custom_label_text_color'))>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[lemon_shipping_days]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('lemon_shipping_days'))> Zmień dni do wysyłki</label>
                            <label>Dni kalendarzowe do wysyłki
                                <input name="changes[lemon_shipping_days]" type="number" step="1" min="0" value="{{ old('changes.lemon_shipping_days') }}" data-product-bulk-value @disabled(! $bulkApplies('lemon_shipping_days'))>
                            </label>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[lemon_shipping_text]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('lemon_shipping_text'))> Zmień tekst terminu wysyłki</label>
                            <label>Tekst terminu wysyłki
                                <input name="changes[lemon_shipping_text]" value="{{ old('changes.lemon_shipping_text') }}" maxlength="1000" placeholder="Planowana wysyłka: {date}" data-product-bulk-value @disabled(! $bulkApplies('lemon_shipping_text'))>
                            </label>
                            <small>Obsługiwane znaczniki: <code>{date}</code> i <code>{days}</code>.</small>
                        </div>

                        <div class="product-bulk-field" data-product-bulk-field>
                            <label class="product-bulk-apply"><input name="apply[lemon_preorder]" type="checkbox" value="1" data-product-bulk-apply @checked($bulkApplies('lemon_preorder'))> Zmień przedsprzedaż</label>
                            <label>Produkt dostępny w przedsprzedaży
                                <select name="changes[lemon_preorder]" data-product-bulk-value @disabled(! $bulkApplies('lemon_preorder'))>
                                    <option value="1" @selected(old('changes.lemon_preorder', '1') === '1')>Tak</option>
                                    <option value="0" @selected(old('changes.lemon_preorder') === '0')>Nie</option>
                                </select>
                            </label>
                        </div>
                    </div>
                </section>
            </div>

            <div class="product-bulk-modal-footer">
                <span>Po zapisie zmapowane produkty zostaną dodane do synchronizacji WooCommerce.</span>
                <div class="inline-actions">
                    <button class="button secondary" type="button" data-product-bulk-close>Anuluj</button>
                    <button class="button" type="submit">Zastosuj zmiany</button>
                </div>
            </div>
        </form>
    </div>
</div>
