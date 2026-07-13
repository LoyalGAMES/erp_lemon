(function () {
	'use strict';

	var config = window.llReturnsFormConfig || {};

	function onReady(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}

		callback();
	}

	function ajax(action, data) {
		var formData = new FormData();
		formData.append('action', action);
		formData.append('nonce', config.nonce || '');

		Object.keys(data || {}).forEach(function (key) {
			formData.append(key, data[key]);
		});

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		}).then(function (response) {
			return response.json().then(function (json) {
				if (!response.ok || !json || !json.success) {
					var message = json && json.data && json.data.message ? json.data.message : '';
					throw new Error(message || (config.i18n && config.i18n.submitError) || 'Nie udało się wykonać żądania.');
				}

				return json.data || {};
			});
		});
	}

	function text(value) {
		return value === null || typeof value === 'undefined' ? '' : String(value);
	}

	function mergeFormConfig(root) {
		var local = {};
		var merged;

		try {
			local = JSON.parse(root.getAttribute('data-ll-returns-config') || '{}') || {};
		} catch (error) {
			local = {};
		}

		merged = Object.assign({}, config, local);
		merged.i18n = Object.assign({}, config.i18n || {}, local.i18n || {});
		merged.reasons = local.reasons || config.reasons || {};

		return merged;
	}

	function t(cfg, key, fallback) {
		return (cfg.i18n && cfg.i18n[key]) || fallback || '';
	}

	function formatPrice(value, currency) {
		var amount = Number(value || 0);

		if (!currency || !amount) {
			return '';
		}

		try {
			return new Intl.NumberFormat(document.documentElement.lang || 'pl-PL', {
				style: 'currency',
				currency: currency
			}).format(amount);
		} catch (error) {
			return amount.toFixed(2) + ' ' + currency;
		}
	}

	function setLoading(button, isLoading, cfg) {
		if (!button) {
			return;
		}

		if (isLoading) {
			button.dataset.originalText = button.textContent;
			button.textContent = t(cfg || config, 'loading', 'Przetwarzanie...');
			button.disabled = true;
			return;
		}

		button.textContent = button.dataset.originalText || button.textContent;
		button.disabled = false;
	}

	function initForm(root) {
		var cfg = mergeFormConfig(root);
		var state = {
			order: null,
			lookupToken: '',
			orderReference: '',
			contact: '',
			selectedItems: []
		};

		var views = root.querySelectorAll('[data-ll-returns-view]');
		var indicators = root.querySelectorAll('[data-ll-step-indicator]');
		var notice = root.querySelector('[data-ll-returns-notice]');
		var lookupForm = root.querySelector('[data-ll-returns-lookup-form]');
		var itemsForm = root.querySelector('[data-ll-returns-items-form]');
		var shippingForm = root.querySelector('[data-ll-returns-shipping-form]');
		var itemsNode = root.querySelector('[data-ll-returns-items]');
		var orderSummary = root.querySelector('[data-ll-returns-order-summary]');
		var ownShippingCopy = root.querySelector('[data-ll-own-shipping-copy]');

		if (ownShippingCopy) {
			ownShippingCopy.textContent = cfg.ownShippingInstructions || '';
		}

		function showNotice(message, type) {
			if (!notice) {
				return;
			}

			notice.textContent = message || '';
			notice.className = 'll-returns__notice is-' + (type || 'error');
			notice.hidden = !message;
		}

		function setView(name) {
			views.forEach(function (view) {
				var isActive = view.getAttribute('data-ll-returns-view') === name;
				view.hidden = !isActive;
				view.classList.toggle('is-active', isActive);
			});

			indicators.forEach(function (indicator) {
				indicator.classList.toggle('is-active', indicator.getAttribute('data-ll-step-indicator') === name);
			});

			showNotice('');
		}

		function renderReasons(select) {
			var reasons = cfg.reasons || {};

			Object.keys(reasons).forEach(function (key) {
				var option = document.createElement('option');
				option.value = key;
				option.textContent = reasons[key];
				select.appendChild(option);
			});
		}

		function renderItems(order) {
			itemsNode.innerHTML = '';

			if (orderSummary) {
				orderSummary.textContent = t(cfg, 'orderSummaryPrefix', '#') + text(order.order_number || order.order_reference);
			}

			(order.items || []).forEach(function (item, index) {
				var card = document.createElement('article');
				card.className = 'll-returns__item';
				card.dataset.itemId = text(item.id);

				var checkboxId = 'll-return-item-' + index + '-' + Math.random().toString(36).slice(2);

				var selectLabel = document.createElement('label');
				selectLabel.className = 'll-returns__item-select';
				selectLabel.setAttribute('for', checkboxId);

				var checkbox = document.createElement('input');
				checkbox.type = 'checkbox';
				checkbox.id = checkboxId;
				checkbox.dataset.itemCheckbox = '1';

				var checkVisual = document.createElement('span');
				checkVisual.className = 'll-returns__check';
				checkVisual.setAttribute('aria-hidden', 'true');

				selectLabel.appendChild(checkbox);
				selectLabel.appendChild(checkVisual);

				var imageWrap = document.createElement('div');
				imageWrap.className = 'll-returns__item-image';

				if (item.image) {
					var image = document.createElement('img');
					image.src = item.image;
					image.alt = '';
					image.loading = 'lazy';
					imageWrap.appendChild(image);
				}

				var body = document.createElement('div');
				body.className = 'll-returns__item-body';

				var name = document.createElement('h4');
				name.textContent = text(item.name);
				body.appendChild(name);

				var meta = document.createElement('p');
				var metaParts = [];

				if (item.sku) {
					metaParts.push(t(cfg, 'sku', 'SKU') + ': ' + text(item.sku));
				}

				metaParts.push(t(cfg, 'qty', 'Szt.') + ': ' + text(item.quantity));

				var price = formatPrice(item.price, order.currency);
				if (price) {
					metaParts.push(price);
				}

				meta.textContent = metaParts.join(' / ');
				body.appendChild(meta);

				var controls = document.createElement('div');
				controls.className = 'll-returns__item-controls';

				var qtyLabel = document.createElement('label');
				qtyLabel.className = 'll-returns__compact-field';

				var qtyText = document.createElement('span');
				qtyText.textContent = t(cfg, 'qty', 'Szt.');

				var qtyInput = document.createElement('input');
				qtyInput.type = 'number';
				qtyInput.min = '1';
				qtyInput.max = text(item.quantity);
				qtyInput.value = '1';
				qtyInput.disabled = true;
				qtyInput.dataset.itemQty = '1';

				qtyLabel.appendChild(qtyText);
				qtyLabel.appendChild(qtyInput);

				var reasonLabel = document.createElement('label');
				reasonLabel.className = 'll-returns__compact-field ll-returns__compact-field--reason';

				var reasonText = document.createElement('span');
				reasonText.textContent = t(cfg, 'reason', 'Powód zwrotu');

				var reasonSelect = document.createElement('select');
				reasonSelect.disabled = true;
				reasonSelect.dataset.itemReason = '1';
				renderReasons(reasonSelect);

				reasonLabel.appendChild(reasonText);
				reasonLabel.appendChild(reasonSelect);
				controls.appendChild(qtyLabel);
				controls.appendChild(reasonLabel);
				body.appendChild(controls);

				checkbox.addEventListener('change', function () {
					qtyInput.disabled = !checkbox.checked;
					reasonSelect.disabled = !checkbox.checked;
					card.classList.toggle('is-selected', checkbox.checked);
				});

				card.appendChild(selectLabel);
				card.appendChild(imageWrap);
				card.appendChild(body);
				itemsNode.appendChild(card);
			});
		}

		function collectItems() {
			var selected = [];
			var cards = itemsNode.querySelectorAll('.ll-returns__item');

			cards.forEach(function (card) {
				var checkbox = card.querySelector('[data-item-checkbox]');

				if (!checkbox || !checkbox.checked) {
					return;
				}

				var qtyInput = card.querySelector('[data-item-qty]');
				var reasonSelect = card.querySelector('[data-item-reason]');
				var quantity = parseInt(qtyInput.value, 10);
				var max = parseInt(qtyInput.max, 10);

				if (!quantity || quantity < 1 || quantity > max) {
					selected.push({ invalid: true });
					return;
				}

				selected.push({
					id: card.dataset.itemId,
					quantity: quantity,
					reason: reasonSelect ? reasonSelect.value : 'other'
				});
			});

			return selected;
		}

		if (lookupForm) {
			lookupForm.addEventListener('submit', function (event) {
				event.preventDefault();

				var button = lookupForm.querySelector('button[type="submit"]');
				var formData = new FormData(lookupForm);
				var orderReference = text(formData.get('order_reference')).trim();
				var contact = text(formData.get('contact')).trim();

				setLoading(button, true, cfg);
				showNotice('');

				ajax('ll_returns_lookup_order', {
					order_reference: orderReference,
					contact: contact
				}).then(function (data) {
					state.order = data.order || null;
					state.lookupToken = data.lookup_token || '';
					state.orderReference = orderReference;
					state.contact = contact;
					state.selectedItems = [];

					renderItems(state.order);
					setView('items');
				}).catch(function (error) {
					showNotice(error.message || t(cfg, 'lookupError', 'Nie udało się odczytać zamówienia.'), 'error');
				}).finally(function () {
					setLoading(button, false, cfg);
				});
			});
		}

		if (itemsForm) {
			itemsForm.addEventListener('submit', function (event) {
				event.preventDefault();

				var selected = collectItems();

				if (!selected.length) {
					showNotice(t(cfg, 'selectProduct', 'Wybierz przynajmniej jeden produkt do zwrotu.'), 'error');
					return;
				}

				if (selected.some(function (item) { return item.invalid; })) {
					showNotice(t(cfg, 'quantityError', 'Sprawdź liczbę zwracanych sztuk.'), 'error');
					return;
				}

				state.selectedItems = selected;
				setView('shipping');
			});
		}

		if (shippingForm) {
			shippingForm.addEventListener('submit', function (event) {
				event.preventDefault();

				var button = shippingForm.querySelector('button[type="submit"]');
				var formData = new FormData(shippingForm);
				var payload = {
					order_reference: state.orderReference,
					contact: state.contact,
					lookup_token: state.lookupToken,
					return_method: text(formData.get('return_method') || 'own_shipping'),
					customer_note: text(formData.get('customer_note') || ''),
					items: state.selectedItems
				};

				setLoading(button, true, cfg);
				showNotice('');

				ajax('ll_returns_submit_return', {
					payload: JSON.stringify(payload)
				}).then(function (data) {
					renderSuccess(data || {});
					setView('success');
				}).catch(function (error) {
					showNotice(error.message || t(cfg, 'submitError', 'Nie udało się zgłosić zwrotu.'), 'error');
				}).finally(function () {
					setLoading(button, false, cfg);
				});
			});
		}

		root.querySelectorAll('[data-ll-returns-back]').forEach(function (button) {
			button.addEventListener('click', function () {
				setView(button.getAttribute('data-ll-returns-back'));
			});
		});

		function renderSuccess(data) {
			var message = root.querySelector('[data-ll-returns-success-message]');
			var number = root.querySelector('[data-ll-returns-return-number]');
			var actions = root.querySelector('[data-ll-returns-success-actions]');

			if (message) {
				message.textContent = data.success_message || cfg.successMessage || '';
			}

			if (number) {
				number.textContent = t(cfg, 'returnNumber', 'Numer zwrotu') + ': ' + text(data.return_number || '');
			}

			if (actions) {
				actions.innerHTML = '';

				if (data.wygodne_zwroty_url) {
					var link = document.createElement('a');
					link.className = 'll-returns__button ll-returns__button--primary';
					link.href = data.wygodne_zwroty_url;
					link.target = '_blank';
					link.rel = 'noopener noreferrer';
					link.textContent = t(cfg, 'wygodneZwrotyButton', 'Przejdź do Wygodnych Zwrotów');
					actions.appendChild(link);
				}
			}
		}
	}

	onReady(function () {
		document.querySelectorAll('[data-ll-returns-form]').forEach(initForm);
	});
}());
