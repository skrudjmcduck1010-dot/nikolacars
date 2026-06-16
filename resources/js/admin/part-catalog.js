import '../../css/admin/part-catalog.css';

const config = window.partCatalogConfig || {};

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

const textCounterValue = (value) => Number(String(value ?? '').replace(/\s+/g, ''));

const decrementTextCounters = (nodes, amount) => {
    const decrement = Number(amount || 0);
    if (!Number.isFinite(decrement) || decrement <= 0) return;

    nodes.forEach((node) => {
        const current = textCounterValue(node.textContent);
        if (!Number.isFinite(current)) return;

        node.textContent = Math.max(0, current - decrement);
    });
};

const hidesNikolaCarsSoldItems = () => {
    const input = document.querySelector('input[type="checkbox"][name="hide_sold"]');

    return input ? input.checked : true;
};

(() => {
    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-nikolacars-group-toggle]')
            : null;

        if (!button) return;

        const groupId = button.dataset.nikolacarsGroupToggle;
        if (!groupId) return;

        const expanded = button.getAttribute('aria-expanded') === 'true';
        const nextExpanded = !expanded;

        button.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
        button.setAttribute(
            'title',
            nextExpanded
                ? '\u0421\u043a\u0440\u044b\u0442\u044c \u043f\u043e\u0437\u0438\u0446\u0438\u0438'
                : '\u041f\u043e\u043a\u0430\u0437\u0430\u0442\u044c \u043f\u043e\u0437\u0438\u0446\u0438\u0438',
        );
        button.setAttribute(
            'aria-label',
            nextExpanded
                ? '\u0421\u043a\u0440\u044b\u0442\u044c \u043f\u043e\u0437\u0438\u0446\u0438\u0438'
                : '\u041f\u043e\u043a\u0430\u0437\u0430\u0442\u044c \u043f\u043e\u0437\u0438\u0446\u0438\u0438',
        );

        document.querySelectorAll('[data-nikolacars-group-child]').forEach((row) => {
            if (row.dataset.nikolacarsGroupChild === groupId) {
                row.hidden = !nextExpanded;
            }
        });
    });
})();

(() => {
    const panel = document.querySelector('[data-tcars-refresh-panel]');
    const button = document.querySelector('[data-tcars-refresh-button]');
    if (!panel && !button) return;

    const statusUrl = panel?.dataset.statusUrl;
    const startUrl = button?.dataset.startUrl;
    const message = panel?.querySelector('[data-tcars-refresh-message]');
    const state = panel?.querySelector('[data-tcars-refresh-state]');
    const bar = panel?.querySelector('[data-tcars-refresh-bar]');
    const progressText = panel?.querySelector('[data-tcars-refresh-progress-text]');
    const finished = panel?.querySelector('[data-tcars-refresh-finished]');
    const pages = panel?.querySelector('[data-tcars-refresh-pages]');
    const cards = panel?.querySelector('[data-tcars-refresh-cards]');
    const currentModel = panel?.querySelector('[data-tcars-refresh-current-model]');
    const found = panel?.querySelector('[data-tcars-refresh-found]');
    const priceChanges = panel?.querySelector('[data-price-changes-count]');
    const crawlDuration = panel?.querySelector('[data-tcars-refresh-crawl-duration]');
    const drivepartsListingUpdated = panel?.querySelector('[data-driveparts-listing-updated]');
    const drivepartsDetailPages = panel?.querySelector('[data-driveparts-detail-pages]');
    const drivepartsDetailSkipped = panel?.querySelector('[data-driveparts-detail-skipped]');
    const itemsCount = document.querySelector('[data-catalog-items-count]');
    const totalProductsCount = document.querySelector('[data-catalog-total-products-count]');
    let timer = null;
    const text = {
        ready: '\u0413\u043e\u0442\u043e\u0432 \u043a \u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u044e \u043a\u0430\u0442\u0430\u043b\u043e\u0433\u0430 \u043a\u043e\u043d\u043a\u0443\u0440\u0435\u043d\u0442\u0430.',
        running: '\u0432 \u0440\u0430\u0431\u043e\u0442\u0435',
        done: '\u0433\u043e\u0442\u043e\u0432\u043e',
        failed: '\u043e\u0448\u0438\u0431\u043a\u0430',
        stopped: '\u043e\u0441\u0442\u0430\u043d\u043e\u0432\u043b\u0435\u043d',
        pending: '\u043e\u0436\u0438\u0434\u0430\u0435\u0442',
        updating: '\u041e\u0431\u043d\u043e\u0432\u043b\u044f\u0435\u0442\u0441\u044f...',
        continue: '\u041f\u0440\u043e\u0434\u043e\u043b\u0436\u0438\u0442\u044c \u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u0435',
        update: '\u041e\u0431\u043d\u043e\u0432\u0438\u0442\u044c \u0437\u0430\u043f\u0447\u0430\u0441\u0442\u0438 \u043a\u043e\u043d\u043a\u0443\u0440\u0435\u043d\u0442\u0430',
        starting: '\u0417\u0430\u043f\u0443\u0441\u043a\u0430\u044e...',
        startFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u043f\u0443\u0441\u0442\u0438\u0442\u044c \u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u0435.',
        dash: '\u2014',
    };

    const render = (payload) => {
        if (!payload) return;
        const percent = Number(payload.progress_percent || 0);

        if (message) message.textContent = payload.stopped_message || payload.message || text.ready;
        if (state) state.textContent = payload.is_running ? text.running : (payload.status === 'done' ? text.done : (payload.status === 'failed' ? text.failed : (payload.status === 'stopped' ? text.stopped : text.pending)));
        if (bar) bar.style.width = `${percent}%`;
        if (progressText) progressText.textContent = `${percent}%`;
        if (finished) finished.textContent = payload.finished_label || payload.finished_at || text.dash;
        if (pages) pages.textContent = payload.progress_pages_opened ?? text.dash;
        if (cards) cards.textContent = payload.progress_items_found ?? payload.products_found ?? payload.listing_products_seen ?? payload.site_products_found ?? payload.product_pages_seen ?? payload.products_seen ?? payload.items_seen ?? 0;
        if (currentModel) currentModel.textContent = payload.progress_current_model || text.dash;
        if (found) found.textContent = payload.catalog_products_created ?? 0;
        if (priceChanges) priceChanges.textContent = payload.prices_changed ?? 0;
        if (crawlDuration) crawlDuration.textContent = payload.crawl_duration_label || text.dash;
        if (drivepartsListingUpdated) drivepartsListingUpdated.textContent = payload.catalog_products_updated ?? payload.products_updated ?? 0;
        if (drivepartsDetailPages) drivepartsDetailPages.textContent = payload.product_compatibility_pages_fetched ?? 0;
        if (drivepartsDetailSkipped) drivepartsDetailSkipped.textContent = payload.product_detail_pages_skipped ?? 0;
        if (itemsCount && payload.items_count !== undefined) itemsCount.textContent = payload.items_count;
        if (totalProductsCount && payload.total_products_count !== undefined) totalProductsCount.textContent = payload.total_products_count;

        if (button) {
            button.disabled = !!payload.is_running;
            button.textContent = payload.is_running ? text.updating : (payload.status === 'stopped' ? text.continue : text.update);
        }

        if (payload.is_running || itemsCount) {
            schedulePoll(payload.is_running ? 2500 : 5000);
        } else if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const poll = async () => {
        if (!statusUrl) return;

        try {
            const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            if (response.ok) render(await response.json());
        } catch (error) {
            schedulePoll(5000);
        }
    };

    const schedulePoll = (delay = 2500) => {
        if (timer) return;
        timer = setTimeout(async () => {
            timer = null;
            await poll();
        }, delay);
    };

    button?.addEventListener('click', async () => {
        if (!startUrl || button.disabled) return;

        button.disabled = true;
        button.textContent = text.starting;

        try {
            const response = await fetch(startUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            render(await response.json());
        } catch (error) {
            button.disabled = false;
            button.textContent = text.update;
            if (message) message.textContent = text.startFailed;
        }
    });

    if (statusUrl) schedulePoll(button?.disabled ? 1000 : 5000);
})();

(() => {
    const roots = Array.from(document.querySelectorAll('[data-nikolacars-part-name-search-url]'));
    if (!roots.length) return;

    roots.forEach((root) => {
        const input = root.querySelector('[data-nikolacars-part-name-input]');
        const suggestions = root.querySelector('[data-nikolacars-part-name-suggestions]');
        const searchUrl = root.dataset.nikolacarsPartNameSearchUrl;
        const dialog = root.closest('[data-nikolacars-part-dialog]');
        let searchTimeout = null;
        let activeController = null;

    const field = (name) => dialog?.querySelector(`[name="${name}"]`);

    const cssPixels = (value) => Number.parseFloat(value) || 0;

    const updateSuggestionBounds = () => {
        if (!input || !suggestions) return;

        const inputRect = input.getBoundingClientRect();
        const form = root.closest('.nikolacars-part-dialog__form');
        const formRect = form?.getBoundingClientRect();
        const formStyle = form ? window.getComputedStyle(form) : null;
        const viewportPadding = 16;
        const leftLimit = formRect
            ? formRect.left + cssPixels(formStyle?.paddingLeft)
            : viewportPadding;
        const rightLimit = formRect
            ? formRect.right - cssPixels(formStyle?.paddingRight)
            : window.innerWidth - viewportPadding;
        const isRightAligned = root.classList.contains('nikolacars-part-name-autocomplete--right');
        const availableWidth = isRightAligned
            ? inputRect.right - Math.max(viewportPadding, leftLimit)
            : Math.min(window.innerWidth - viewportPadding, rightLimit) - inputRect.left;
        const maxWidth = Math.max(inputRect.width || 240, Math.min(820, Math.floor(availableWidth)));
        const dialogRect = dialog?.getBoundingClientRect();
        const bottomLimit = dialogRect
            ? Math.min(dialogRect.bottom, window.innerHeight - viewportPadding)
            : window.innerHeight - viewportPadding;
        const availableHeight = Math.floor(bottomLimit - inputRect.bottom - 8);
        const maxHeight = Math.max(1, Math.min(320, Number.isFinite(availableHeight) ? availableHeight : 320));

        suggestions.style.setProperty('--nikolacars-part-name-suggestions-max-width', `${maxWidth}px`);
        suggestions.style.setProperty('--nikolacars-part-name-suggestions-max-height', `${maxHeight}px`);
    };

    const show = () => {
        if (!suggestions) return;
        updateSuggestionBounds();
        suggestions.hidden = false;
    };

    const hide = () => {
        if (!suggestions) return;
        suggestions.hidden = true;
        suggestions.innerHTML = '';
    };

    const fillField = (name, value, overwrite = true) => {
        const target = field(name);
        if (!target || value === null || value === undefined || value === '') return;
        if (!overwrite && target.value.trim() !== '') return;
        target.value = value;
    };

    const selectSuggestion = (item) => {
        fillField('name_ua', item.name);
        fillField('name', item.name);
        fillField('part_number', item.part_number);
        fillField('selling_price', item.price_amount);
        fillField('description', item.description, false);
        hide();
    };

    const render = (items) => {
        if (!suggestions) return;

        suggestions.innerHTML = '';

        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'nikolacars-part-name-empty';
            empty.textContent = 'Ничего не найдено';
            suggestions.appendChild(empty);
            show();
            return;
        }

        items.forEach((item) => {
            const button = document.createElement('button');
            const source = document.createElement('span');
            const title = document.createElement('span');
            const meta = document.createElement('span');
            const sourceLabel = item.source_label || item.source || 'Источник';
            const titleParts = [
                item.name || item.part_number || 'Без названия',
            ].filter(Boolean);
            const metaParts = [
                item.part_number ? `Артикул: ${item.part_number}` : null,
                item.category,
                item.model,
                item.price_text,
            ].filter(Boolean);

            button.type = 'button';
            button.className = 'nikolacars-part-name-suggestion';
            source.className = `nikolacars-part-name-source nikolacars-part-name-source--${item.source_group || 'competitor'}`;
            title.className = 'nikolacars-part-name-title';
            meta.className = 'nikolacars-part-name-meta';
            source.textContent = sourceLabel.trim().charAt(0) || '?';
            source.title = sourceLabel;
            title.textContent = titleParts.join(' - ');
            meta.textContent = metaParts.join(' · ') || '\u00a0';

            button.append(source, title, meta);
            button.addEventListener('click', () => selectSuggestion(item));
            suggestions.appendChild(button);
        });

        show();
    };

    input?.addEventListener('input', () => {
        const query = input.value.trim();
        clearTimeout(searchTimeout);

        if (query.length < 2) {
            hide();
            return;
        }

        searchTimeout = setTimeout(async () => {
            if (activeController) activeController.abort();
            activeController = new AbortController();

            try {
                const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: activeController.signal,
                });

                if (!response.ok) return;
                render(await response.json());
            } catch (error) {
                if (error.name !== 'AbortError') hide();
            }
        }, 220);
    });

    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') hide();
    });

    input?.addEventListener('focus', () => {
        if (suggestions && !suggestions.hidden) updateSuggestionBounds();
    });

    window.addEventListener('resize', () => {
        if (suggestions && !suggestions.hidden) updateSuggestionBounds();
    });

    dialog?.addEventListener('scroll', () => {
        if (suggestions && !suggestions.hidden) updateSuggestionBounds();
    }, true);

        document.addEventListener('click', (event) => {
            if (!root.contains(event.target)) hide();
        });
    });
})();

(() => {
    const dialog = document.querySelector('[data-nikolacars-part-dialog]');
    if (!dialog) return;

    const openButton = document.querySelector('[data-open-nikolacars-part-dialog]');
    const closeButtons = dialog.querySelectorAll('[data-close-nikolacars-part-dialog]');
    const warehouseSelect = dialog.querySelector('[data-nikolacars-part-warehouse]');
    const floorWrap = dialog.querySelector('[data-nikolacars-part-floor-wrap]');
    const floorSelect = dialog.querySelector('[data-nikolacars-part-floor]');
    const cellWrap = dialog.querySelector('[data-nikolacars-part-cell-wrap]');
    const cellInput = dialog.querySelector('[data-nikolacars-part-cell]');
    const donorPicker = dialog.querySelector('[data-nikolacars-donor-picker]');
    const sourceTypeInput = dialog.querySelector('[data-nikolacars-source-type-input]');
    const donorInput = dialog.querySelector('[data-nikolacars-donor-input]');
    const donorToggle = dialog.querySelector('[data-nikolacars-donor-toggle]');
    const donorSelected = dialog.querySelector('[data-nikolacars-donor-selected]');
    const donorMenu = dialog.querySelector('[data-nikolacars-donor-menu]');
    const donorOptions = Array.from(dialog.querySelectorAll('[data-donor-id]'));
    const purchasePriceWrap = dialog.querySelector('[data-nikolacars-purchase-price-wrap]');
    const purchasePriceInput = dialog.querySelector('[data-nikolacars-purchase-price-input]');

    const openDialog = () => {
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
        }
    };

    const closeDialog = () => dialog.close();

    const renderFloors = () => {
        if (!warehouseSelect || !floorSelect || !floorWrap) return;

        const selected = warehouseSelect.selectedOptions[0];
        const isDonorWarehouse = selected?.dataset.warehouseType === 'donor';
        const floorCount = Math.max(1, Number(selected?.dataset.floorCount || 1));
        const selectedFloor = floorSelect.dataset.selectedFloor || floorSelect.value || 'floor_1';

        floorSelect.innerHTML = '';
        for (let index = 1; index <= floorCount; index += 1) {
            const value = `floor_${index}`;
            const option = document.createElement('option');
            option.value = value;
            option.textContent = `Этаж ${index}`;
            option.selected = selectedFloor === value;
            floorSelect.append(option);
        }

        floorWrap.hidden = isDonorWarehouse || floorCount <= 1;
        if (isDonorWarehouse || floorCount <= 1) {
            floorSelect.value = 'floor_1';
        }

        if (cellWrap) {
            cellWrap.hidden = isDonorWarehouse;
        }

        if (cellInput) {
            cellInput.disabled = isDonorWarehouse;
            if (isDonorWarehouse) {
                cellInput.value = '';
            }
        }
    };

    const renderSelectedDonor = (option) => {
        if (!donorSelected) return;

        const label = option.dataset.donorLabel || '';
        const meta = option.dataset.donorMeta || '';
        const previewUrl = option.dataset.donorPreviewUrl || option.querySelector('img')?.getAttribute('src') || '';
        const placeholderText = option.querySelector('.nikolacars-donor-option__placeholder')?.textContent?.trim() || 'NC';
        const preview = previewUrl ? document.createElement('img') : document.createElement('span');
        const text = document.createElement('span');
        const title = document.createElement('strong');

        preview.className = previewUrl
            ? 'nikolacars-donor-picker__selected-preview'
            : 'nikolacars-donor-picker__selected-placeholder';

        if (previewUrl) {
            preview.src = previewUrl;
            preview.alt = label;
            preview.loading = 'lazy';
            preview.decoding = 'async';
        } else {
            preview.textContent = placeholderText;
        }

        text.className = 'nikolacars-donor-picker__selected-text';
        title.textContent = label || placeholderText;
        text.append(title);

        if (meta) {
            const details = document.createElement('small');
            details.textContent = meta;
            text.append(details);
        }

        donorSelected.replaceChildren(preview, text);
    };

    const selectDonor = (option) => {
        if (!option || !donorInput || !donorSelected) return;

        donorInput.value = option.dataset.donorId || '';
        if (sourceTypeInput) {
            sourceTypeInput.value = option.dataset.sourceType || (option.dataset.donorId ? 'donor' : 'purchase');
        }
        renderSelectedDonor(option);
        donorOptions.forEach((item) => item.classList.toggle('is-selected', item === option));
        if (donorMenu) donorMenu.hidden = true;
        renderPurchasePrice();
    };

    const renderPurchasePrice = () => {
        const isPurchase = (sourceTypeInput?.value || 'purchase') === 'purchase';

        if (purchasePriceWrap) {
            purchasePriceWrap.hidden = !isPurchase;
        }

        if (purchasePriceInput) {
            purchasePriceInput.disabled = !isPurchase;
            if (!isPurchase) {
                purchasePriceInput.value = '';
            }
        }
    };

    openButton?.addEventListener('click', openDialog);
    closeButtons.forEach((button) => button.addEventListener('click', closeDialog));
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog();
    });

    warehouseSelect?.addEventListener('change', () => {
        if (floorSelect) floorSelect.dataset.selectedFloor = '';
        renderFloors();
    });

    donorToggle?.addEventListener('click', () => {
        if (donorMenu) donorMenu.hidden = !donorMenu.hidden;
    });

    donorOptions.forEach((option) => {
        option.addEventListener('click', () => selectDonor(option));
    });

    document.addEventListener('click', (event) => {
        if (!donorPicker?.contains(event.target)) {
            if (donorMenu) donorMenu.hidden = true;
        }
    });

    renderFloors();
    selectDonor(donorOptions.find((option) => option.dataset.donorId === (donorInput?.value || '')) || donorOptions[0]);
    renderPurchasePrice();

    if (dialog.dataset.openOnError === '1') {
        openDialog();
    }
})();

(() => {
    const lightbox = document.querySelector('[data-catalog-photo-lightbox]');
    if (!lightbox) return;

    const image = lightbox.querySelector('[data-catalog-photo-lightbox-image]');
    const counter = lightbox.querySelector('[data-catalog-photo-counter]');
    const closeButton = lightbox.querySelector('[data-close-catalog-photo-lightbox]');
    const prevButton = lightbox.querySelector('[data-catalog-photo-prev]');
    const nextButton = lightbox.querySelector('[data-catalog-photo-next]');
    let photoUrls = [];
    let currentIndex = 0;
    let currentTitle = '';
    let failedPhotoUrls = new Set();

    const showPhoto = (index, direction = 1) => {
        if (!image || photoUrls.length === 0) return;

        currentIndex = (index + photoUrls.length) % photoUrls.length;
        image.src = photoUrls[currentIndex];
        image.alt = currentTitle;

        if (counter) {
            counter.textContent = `${currentTitle ? `${currentTitle} - ` : ''}${currentIndex + 1} / ${photoUrls.length}`;
        }

        const hasMultiplePhotos = photoUrls.length > 1;
        if (prevButton) prevButton.hidden = !hasMultiplePhotos;
        if (nextButton) nextButton.hidden = !hasMultiplePhotos;
    };

    const showNextAvailablePhoto = (direction = 1) => {
        if (photoUrls.length < 2) return;

        const startIndex = currentIndex;
        let nextIndex = currentIndex;

        do {
            nextIndex = (nextIndex + direction + photoUrls.length) % photoUrls.length;
            if (!failedPhotoUrls.has(photoUrls[nextIndex])) {
                showPhoto(nextIndex, direction);
                return;
            }
        } while (nextIndex !== startIndex);
    };

    const openPhoto = (trigger) => {
        try {
            photoUrls = JSON.parse(trigger.dataset.catalogImages || '[]').filter(Boolean);
        } catch (error) {
            photoUrls = [];
        }

        if (photoUrls.length === 0) return;

        currentTitle = trigger.dataset.catalogPhotoTitle || '';
        failedPhotoUrls = new Set();
        showPhoto(0);
        lightbox.showModal();
    };

    document.querySelectorAll('[data-catalog-photo-trigger] img').forEach((previewImage) => {
        const trigger = previewImage.closest('[data-catalog-photo-trigger]');
        if (!trigger) return;

        let urls = [];
        try {
            urls = JSON.parse(trigger.dataset.catalogImages || '[]').filter(Boolean);
        } catch (error) {
            urls = [];
        }

        if (urls.length < 2) return;

        let previewIndex = Math.max(0, urls.indexOf(previewImage.getAttribute('src') || ''));
        const failedUrls = new Set();

        previewImage.addEventListener('error', () => {
            failedUrls.add(urls[previewIndex]);

            for (let step = 1; step < urls.length; step += 1) {
                const nextIndex = (previewIndex + step) % urls.length;
                if (!failedUrls.has(urls[nextIndex])) {
                    previewIndex = nextIndex;
                    previewImage.src = urls[nextIndex];
                    return;
                }
            }
        });
    });

    image?.addEventListener('error', () => {
        failedPhotoUrls.add(photoUrls[currentIndex]);
        showNextAvailablePhoto(1);
    });

    document.querySelectorAll('[data-catalog-photo-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', () => openPhoto(trigger));
    });

    closeButton?.addEventListener('click', () => lightbox.close());
    prevButton?.addEventListener('click', () => showPhoto(currentIndex - 1, -1));
    nextButton?.addEventListener('click', () => showPhoto(currentIndex + 1, 1));
    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox) lightbox.close();
    });
    lightbox.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') showPhoto(currentIndex - 1, -1);
        if (event.key === 'ArrowRight') showPhoto(currentIndex + 1, 1);
    });
})();

(() => {
    const addButtons = Array.from(document.querySelectorAll('[data-nikolacars-cart-add]'));
    const bar = document.querySelector('[data-nikolacars-cart-bar]');
    const countNode = document.querySelector('[data-nikolacars-cart-count]');
    const itemsNode = document.querySelector('[data-nikolacars-cart-items]');
    const totalNode = document.querySelector('[data-nikolacars-cart-total]');
    const clearButton = document.querySelector('[data-nikolacars-cart-clear]');
    const checkoutButton = document.querySelector('[data-nikolacars-cart-checkout]');
    const dialog = document.querySelector('[data-nikolacars-cart-dialog]');
    const closeButton = document.querySelector('[data-nikolacars-cart-close]');
    const dialogCountNode = document.querySelector('[data-nikolacars-cart-dialog-count]');
    const dialogTotalNode = document.querySelector('[data-nikolacars-cart-dialog-total]');
    const listNode = document.querySelector('[data-nikolacars-cart-list]');
    const createButton = document.querySelector('[data-nikolacars-cart-create]');
    const phoneInput = document.querySelector('[data-nikolacars-cart-phone]');
    const phoneField = phoneInput?.closest('.nikolacars-cart-phone-field');
    const phoneSuggestions = document.querySelector('[data-nikolacars-cart-phone-suggestions]');
    const firstNameInput = document.querySelector('[data-nikolacars-cart-first-name]');
    const lastNameInput = document.querySelector('[data-nikolacars-cart-last-name]');
    const deliveryMethodInput = document.querySelector('[data-nikolacars-cart-delivery-method]');
    const storageKey = 'nikolacarsCatalogCartUahV1';
    const clientSearchUrl = config.clientSearchUrl || '';
    const createOrderUrl = config.createOrderUrl || '';

    if (addButtons.length === 0 || !bar || !dialog || !listNode) return;

    const text = {
        inCart: '\u0412 \u043a\u043e\u0440\u0437\u0438\u043d\u0435',
        add: '\u0414\u043e\u0431\u0430\u0432\u0438\u0442\u044c \u0432 \u043a\u043e\u0440\u0437\u0438\u043d\u0443',
        reserved: '\u0412 \u0440\u0435\u0437\u0435\u0440\u0432\u0435',
        item: '\u0442\u043e\u0432\u0430\u0440',
        items2: '\u0442\u043e\u0432\u0430\u0440\u0430',
        items5: '\u0442\u043e\u0432\u0430\u0440\u043e\u0432',
        empty: '\u041a\u043e\u0440\u0437\u0438\u043d\u0430 \u043f\u0443\u0441\u0442\u0430.',
        total: '\u0418\u0442\u043e\u0433\u043e',
        creating: '\u0421\u043e\u0437\u0434\u0430\u0435\u043c...',
        create: '\u0421\u043e\u0437\u0434\u0430\u0442\u044c',
        createFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0437\u0434\u0430\u0442\u044c \u0437\u0430\u043a\u0430\u0437. \u041f\u0440\u043e\u0432\u0435\u0440\u044c\u0442\u0435 \u043f\u043e\u043b\u044f \u0438 \u043f\u043e\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0435\u0449\u0435 \u0440\u0430\u0437.',
        deliveryRequired: '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0441\u043f\u043e\u0441\u043e\u0431 \u043f\u043e\u043b\u0443\u0447\u0435\u043d\u0438\u044f.',
        invalidPhone: '\u0423\u043a\u0430\u0436\u0438\u0442\u0435 \u043a\u043e\u0440\u0440\u0435\u043a\u0442\u043d\u044b\u0439 \u0443\u043a\u0440\u0430\u0438\u043d\u0441\u043a\u0438\u0439 \u043c\u043e\u0431\u0438\u043b\u044c\u043d\u044b\u0439 \u0442\u0435\u043b\u0435\u0444\u043e\u043d: 0XXXXXXXXX \u0438\u043b\u0438 +380XXXXXXXXX.',
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const itemWord = (count) => {
        const mod10 = count % 10;
        const mod100 = count % 100;
        if (mod10 === 1 && mod100 !== 11) return text.item;
        if (mod10 >= 2 && mod10 <= 4 && ![12, 13, 14].includes(mod100)) return text.items2;
        return text.items5;
    };

    const readCart = () => {
        try {
            const value = JSON.parse(localStorage.getItem(storageKey) || '[]');
            return Array.isArray(value)
                ? value.filter((item) => item && item.id).map((item) => ({ ...item, quantity: 1 }))
                : [];
        } catch (error) {
            return [];
        }
    };

    const writeCart = (cart) => {
        localStorage.setItem(storageKey, JSON.stringify(cart));
    };

    const hasPositiveCartPrice = (button) => Number(button.dataset.cartPrice || 0) > 0;

    const money = (value, currency = 'UAH') => {
        const number = Number(value || 0);
        if (!Number.isFinite(number)) return '';

        return currency === 'UAH'
            ? `${number.toLocaleString('uk-UA', { maximumFractionDigits: 0 })} \u0433\u0440\u043d`
            : `${number.toFixed(2)} USD`;
    };

    const isValidMobilePhone = (phone) => {
        const digits = String(phone || '').replace(/\D+/g, '');
        const national = digits.startsWith('380') ? `0${digits.slice(3)}` : digits;
        const operatorCodes = new Set(['39', '50', '63', '66', '67', '68', '73', '77', '89', '91', '92', '93', '94', '95', '96', '97', '98', '99']);

        return /^0\d{9}$/.test(national) && operatorCodes.has(national.slice(1, 3));
    };

    const isStoDelivery = () => deliveryMethodInput?.value === 'sto';

    const customerHasAnyDetails = () => [phoneInput, firstNameInput, lastNameInput]
        .some((input) => input && input.value.trim() !== '');

    const syncCustomerNameRequirements = () => {
        const required = !isStoDelivery() && !selectedAnonymousClient && customerHasAnyDetails();

        [firstNameInput, lastNameInput].forEach((input) => {
            if (input) input.required = required;
        });
    };

    const syncCustomerFieldsForDeliveryMethod = () => {
        const disabled = isStoDelivery() || selectedAnonymousClient;
        [phoneInput, firstNameInput, lastNameInput].forEach((input) => {
            if (!input) return;

            if (disabled) {
                input.setCustomValidity('');
            }

            if (isStoDelivery()) {
                input.value = '';
            }

            input.disabled = disabled;
        });
        syncCustomerNameRequirements();
        phoneField?.classList.toggle('is-disabled', disabled);

        if (disabled) {
            hidePhoneSuggestions();
        }
    };

    const cartTotals = (cart) => {
        const quantity = cart.length;
        const total = cart.reduce((sum, item) => sum + Number(item.priceUah || item.price || 0), 0);

        return { quantity, total };
    };

    const orderableCart = () => {
        const cart = readCart();
        const filtered = cart.filter((item) => Number(item.priceUsd || 0) > 0);

        if (filtered.length !== cart.length) {
            writeCart(filtered);
        }

        return filtered;
    };

    const itemFromButton = (button) => ({
        id: String(button.dataset.cartId || ''),
        name: button.dataset.cartName || '',
        partNumber: button.dataset.cartPartNumber || '',
        code: button.dataset.cartCode || '',
        vin: button.dataset.cartVin || '',
        category: button.dataset.cartCategory || '',
        price: Number(button.dataset.cartPriceUah || 0) || 0,
        priceUah: Number(button.dataset.cartPriceUah || 0) || 0,
        priceUahText: button.dataset.cartPriceUahText || '',
        priceUsd: Number(button.dataset.cartPrice || 0) || 0,
        priceText: button.dataset.cartPriceText || '',
        stock: Number(button.dataset.cartStock || 0) || 0,
        stockText: button.dataset.cartStockText || '',
        url: button.dataset.cartUrl || '',
        image: button.dataset.cartImage || '',
        quantity: 1,
    });

    const syncCartButtonVisibility = (button) => {
        const hasPositivePrice = hasPositiveCartPrice(button);
        const placeholder = button.closest('.nikolacars-cart-cell')?.querySelector('[data-nikolacars-cart-placeholder]');

        button.hidden = !hasPositivePrice;

        if (placeholder) {
            placeholder.hidden = hasPositivePrice;
        }
    };

    const setButtonStates = (cart) => {
        const ids = new Set(cart.map((item) => String(item.id)));

        addButtons.forEach((button) => {
            if (button.disabled) {
                button.title = text.reserved;
                button.setAttribute('aria-label', text.reserved);

                return;
            }

            const isAdded = ids.has(String(button.dataset.cartId || ''));
            button.classList.toggle('is-added', isAdded);
            button.title = isAdded ? text.inCart : text.add;
            button.setAttribute('aria-label', isAdded ? text.inCart : text.add);
        });
    };

    const renderDialog = (cart) => {
        listNode.innerHTML = '';

        cart.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'nikolacars-cart-item';
            row.dataset.cartItemId = item.id;
            row.innerHTML = `
                ${item.image ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.name)}" loading="lazy">` : '<span class="nikolacars-cart-item__placeholder">no photo</span>'}
                <div>
                    <strong>${escapeHtml(item.name || '-')}</strong>
                    <div class="nikolacars-cart-item__meta">
                        ${[item.partNumber, item.code, item.vin].filter(Boolean).map(escapeHtml).join(' · ')}
                        ${item.category ? `<br>${escapeHtml(item.category)}` : ''}
                        <br>${escapeHtml((item.priceUah || item.price) ? money(item.priceUah || item.price, 'UAH') : (item.priceUahText || '-'))} · ${escapeHtml(item.stockText || '-')}
                        ${item.priceUsd ? `<div class="nikolacars-cart-item__price-hint">${escapeHtml(money(item.priceUsd, 'USD'))}</div>` : ''}
                    </div>
                </div>
                <input type="number" min="0" step="10" value="${escapeHtml(item.priceUah || item.price || 0)}" aria-label="Price UAH" data-nikolacars-cart-price>
                <button type="button" class="nikolacars-cart-item__remove" aria-label="Remove" data-nikolacars-cart-remove>&times;</button>
            `;
            listNode.appendChild(row);
        });
    };

    const render = () => {
        const cart = readCart();
        const totals = cartTotals(cart);
        const countText = `${cart.length} ${itemWord(cart.length)}`;

        bar.hidden = cart.length === 0;
        if (countNode) countNode.textContent = countText;
        if (itemsNode) {
            const names = cart
                .map((item) => {
                    const price = Number(item.priceUah || item.price || 0) > 0 ? ` - ${money(item.priceUah || item.price, 'UAH')}` : '';

                    return `${item.name || '-'}${price}`;
                })
                .slice(0, 4);
            const hiddenCount = Math.max(cart.length - names.length, 0);

            itemsNode.innerHTML = '';
            names.forEach((name) => {
                const row = document.createElement('span');
                row.className = 'nikolacars-cart-bar__item';
                row.textContent = name;
                itemsNode.appendChild(row);
            });

            if (hiddenCount > 0) {
                const row = document.createElement('span');
                row.className = 'nikolacars-cart-bar__item';
                row.textContent = `+${hiddenCount}`;
                itemsNode.appendChild(row);
            }
        }
        if (totalNode) totalNode.textContent = totals.total > 0 ? `\u0418\u0442\u043e\u0433\u043e: ${money(totals.total)}` : '';
        if (dialogCountNode) dialogCountNode.textContent = countText;
        if (dialogTotalNode) dialogTotalNode.textContent = `${text.total}: ${money(totals.total)}`;
        setButtonStates(cart);

        if (dialog.open) {
            renderDialog(cart);
        }
    };

    const addItem = (button) => {
        if (!hasPositiveCartPrice(button)) {
            syncCartButtonVisibility(button);

            return;
        }

        const item = itemFromButton(button);
        if (!item.id) return;

        const cart = readCart();
        const existing = cart.find((cartItem) => String(cartItem.id) === item.id);
        if (!existing) {
            cart.push(item);
        }

        writeCart(cart);
        render();
    };

    const updatePrice = (id, price) => {
        const cart = readCart()
            .map((item) => String(item.id) === String(id) ? { ...item, price: Math.max(0, Number(price || 0)), priceUah: Math.max(0, Number(price || 0)) } : item);

        writeCart(cart);
        render();
    };

    const removeItem = (id) => {
        writeCart(readCart().filter((item) => String(item.id) !== String(id)));
        render();
    };

    addButtons.forEach(syncCartButtonVisibility);

    addButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            if (!hasPositiveCartPrice(button)) {
                event.preventDefault();
                syncCartButtonVisibility(button);

                return;
            }

            addItem(button);
        });
    });

    clearButton?.addEventListener('click', () => {
        writeCart([]);
        dialog.close();
        render();
    });

    checkoutButton?.addEventListener('click', () => {
        const cart = orderableCart();
        if (cart.length === 0) return;

        renderDialog(cart);
        dialog.showModal();
    });

    closeButton?.addEventListener('click', () => dialog.close());

    listNode.addEventListener('change', (event) => {
        if (! (event.target instanceof HTMLInputElement)) return;

        const row = event.target.closest('[data-cart-item-id]');
        if (event.target.matches('[data-nikolacars-cart-price]')) {
            updatePrice(row?.dataset.cartItemId || '', Number(event.target.value || 0));
        }
    });

    listNode.addEventListener('click', (event) => {
        const button = event.target instanceof HTMLElement
            ? event.target.closest('[data-nikolacars-cart-remove]')
            : null;
        if (!button) return;

        const row = button.closest('[data-cart-item-id]');
        removeItem(row?.dataset.cartItemId || '');
    });

    localStorage.removeItem('nikolacarsCatalogCartNote');

    let clientSearchTimer = null;
    let selectedAnonymousClient = false;
    const hidePhoneSuggestions = () => {
        if (!phoneSuggestions) return;
        phoneSuggestions.hidden = true;
        phoneSuggestions.innerHTML = '';
    };
    const chooseClientSuggestion = (client) => {
        selectedAnonymousClient = client.is_anonymous === true;
        if (phoneInput) phoneInput.value = client.phone || '';
        if (firstNameInput) firstNameInput.value = client.first_name || '';
        if (lastNameInput) lastNameInput.value = client.last_name || '';
        if (selectedAnonymousClient && deliveryMethodInput && client.default_delivery_method) {
            deliveryMethodInput.value = client.default_delivery_method;
            deliveryMethodInput.setCustomValidity('');
            syncCustomerFieldsForDeliveryMethod();
        }
        syncCustomerNameRequirements();
        hidePhoneSuggestions();
    };
    const renderPhoneSuggestions = (clients) => {
        if (!phoneSuggestions) return;
        phoneSuggestions.innerHTML = '';

        clients.forEach((client) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'nikolacars-cart-phone-suggestion';
            button.innerHTML = '<strong></strong><span></span>';
            button.querySelector('strong').textContent = client.name || [client.first_name, client.last_name].filter(Boolean).join(' ') || client.phone || '';
            button.querySelector('span').textContent = client.phone || '';
            button.addEventListener('click', () => chooseClientSuggestion(client));
            phoneSuggestions.appendChild(button);
        });

        phoneSuggestions.hidden = clients.length === 0;
    };
    phoneInput?.addEventListener('input', () => {
        selectedAnonymousClient = false;
        syncCustomerNameRequirements();

        if (isStoDelivery()) {
            hidePhoneSuggestions();
            return;
        }

        phoneInput.setCustomValidity(phoneInput.value.trim() === '' || isValidMobilePhone(phoneInput.value) ? '' : text.invalidPhone);
        window.clearTimeout(clientSearchTimer);
        clientSearchTimer = window.setTimeout(async () => {
            const phone = phoneInput.value.trim();
            if (phone.length < 3) {
                hidePhoneSuggestions();
                return;
            }

            try {
                const url = new URL(clientSearchUrl, window.location.origin);
                url.searchParams.set('phone', phone);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;

                const clients = await response.json();
                renderPhoneSuggestions(Array.isArray(clients) ? clients : []);
            } catch (error) {
                console.error(error);
            }
        }, 350);
    });
    [firstNameInput, lastNameInput].forEach((input) => {
        input?.addEventListener('input', () => {
            selectedAnonymousClient = false;
            syncCustomerNameRequirements();
        });
    });
    document.addEventListener('click', (event) => {
        if (!phoneSuggestions || event.target instanceof Node && phoneSuggestions.contains(event.target)) return;
        if (phoneInput && event.target === phoneInput) return;
        hidePhoneSuggestions();
    });
    deliveryMethodInput?.addEventListener('change', syncCustomerFieldsForDeliveryMethod);
    syncCustomerFieldsForDeliveryMethod();

    createButton?.addEventListener('click', async () => {
        const cart = orderableCart();
        render();
        if (cart.length === 0 || !createButton) return;
        if (!deliveryMethodInput?.value) {
            deliveryMethodInput?.setCustomValidity(text.deliveryRequired);
            deliveryMethodInput?.reportValidity();
            deliveryMethodInput?.focus();
            return;
        }

        deliveryMethodInput.setCustomValidity('');
        syncCustomerNameRequirements();
        const anonymousClientSelected = selectedAnonymousClient && !isStoDelivery();
        if (!isStoDelivery() && firstNameInput && !firstNameInput.reportValidity()) {
            firstNameInput.focus();
            return;
        }

        if (!isStoDelivery() && lastNameInput && !lastNameInput.reportValidity()) {
            lastNameInput.focus();
            return;
        }

        if (!isStoDelivery() && !anonymousClientSelected && phoneInput && phoneInput.value.trim() !== '' && !isValidMobilePhone(phoneInput.value)) {
            phoneInput.setCustomValidity(text.invalidPhone);
            phoneInput.reportValidity();
            phoneInput.focus();
            return;
        }

        const originalText = createButton.textContent;
        createButton.disabled = true;
        createButton.textContent = text.creating;

        try {
            const response = await fetch(createOrderUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    client_phone: isStoDelivery() || anonymousClientSelected ? '' : (phoneInput?.value.trim() || ''),
                    client_first_name: isStoDelivery() || anonymousClientSelected ? '' : (firstNameInput?.value.trim() || ''),
                    client_last_name: isStoDelivery() || anonymousClientSelected ? '' : (lastNameInput?.value.trim() || ''),
                    delivery_method: deliveryMethodInput?.value || '',
                    items: cart.map((item) => ({
                        id: item.id,
                        name: item.name,
                        part_number: item.partNumber,
                        code: item.code,
                        vin: item.vin,
                        category: item.category,
                        quantity: 1,
                        price: item.priceUah || item.price,
                        price_usd_hint: item.priceUsd || null,
                        url: item.url,
                        image: item.image,
                    })),
                }),
            });

            if (!response.ok) {
                let message = text.createFailed;

                try {
                    const errorPayload = await response.json();
                    message = errorPayload.message
                        || Object.values(errorPayload.errors || {}).flat().filter(Boolean).join('\n')
                        || message;
                } catch (error) {
                    message = text.createFailed;
                }

                throw new Error(message);
            }

            const payload = await response.json();
            writeCart([]);
            render();
            window.location.href = payload.url;
        } catch (error) {
            alert(error instanceof Error && error.message ? error.message : text.createFailed);
            createButton.disabled = false;
            createButton.textContent = originalText || text.create;
        }
    });

    render();
})();

(() => {
    document.querySelectorAll('[data-nikolacars-update-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const button = event.submitter instanceof HTMLButtonElement
                ? event.submitter
                : form.querySelector('button[type="submit"]');
            const row = form.closest('[data-nikolacars-item-row], [data-nikolacars-group-child]');
            const partNumberInput = row?.querySelector('[data-nikolacars-part-number-input]');
            const partNumberText = row?.querySelector('[data-nikolacars-part-number-text]');
            const partNumberDisplay = row?.querySelector('[data-nikolacars-part-number-display]');
            const partNumberEditor = row?.querySelector('[data-nikolacars-part-number-editor]');
            const priceInput = row?.querySelector('[data-nikolacars-price-input]');
            const priceText = row?.querySelector('[data-nikolacars-price-text]');
            const priceDisplay = row?.querySelector('[data-nikolacars-price-display]');
            const priceEditor = row?.querySelector('[data-nikolacars-price-editor]');
            const availabilityCell = row?.querySelector('[data-nikolacars-availability]');
            const countNodes = document.querySelectorAll('[data-nikolacars-items-count]');
            const uniqueArticleCountNodes = document.querySelectorAll('[data-nikolacars-unique-articles-count]');
            const addedTodayCountNodes = document.querySelectorAll('[data-nikolacars-added-today-count]');
            const totalValueNodes = document.querySelectorAll('[data-nikolacars-total-value]');
            const oldText = button?.textContent;

            if (button) {
                button.disabled = true;
                button.textContent = '\u0421\u043e\u0445\u0440\u0430\u043d\u044f\u0435\u043c...';
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) throw new Error('update failed');

                const payload = await response.json();

                if (partNumberInput) {
                    partNumberInput.value = payload.part_number || '';
                    partNumberInput.defaultValue = payload.part_number || '';
                }

                if (partNumberText) {
                    partNumberText.textContent = payload.part_number || '-';
                }

                if (partNumberDisplay && partNumberEditor) {
                    partNumberEditor.hidden = true;
                    partNumberDisplay.hidden = false;
                }

                if (priceInput && payload.price_amount !== undefined) {
                    priceInput.value = payload.price_amount ?? '';
                    priceInput.defaultValue = payload.price_amount ?? '';
                }

                if (priceText && payload.price_amount !== undefined) {
                    const hasZeroPrice = payload.price_amount !== null
                        && payload.price_amount !== ''
                        && Number(payload.price_amount) === 0;
                    priceText.classList.toggle('nikolacars-zero-price', hasZeroPrice);
                    const priceUsdText = payload.price_amount === null || payload.price_amount === ''
                        ? ''
                        : `${Number(payload.price_amount).toFixed(2)} USD`;
                    priceText.innerHTML = payload.price_amount === null || payload.price_amount === ''
                        ? '-'
                        : (payload.price_amount_uah_text
                            ? `${escapeHtml(payload.price_amount_uah_text)}<small>${escapeHtml(priceUsdText)}</small>`
                            : escapeHtml(priceUsdText));
                }

                const cartButton = row?.querySelector('[data-nikolacars-cart-add]');
                if (cartButton && payload.price_amount !== undefined) {
                    const hasPositivePrice = payload.price_amount !== null
                        && payload.price_amount !== ''
                        && Number(payload.price_amount) > 0;
                    const cartPlaceholder = row?.querySelector('[data-nikolacars-cart-placeholder]');

                    cartButton.dataset.cartPrice = payload.price_amount ?? '';
                    cartButton.dataset.cartPriceText = payload.price_amount === null || payload.price_amount === ''
                        ? '-'
                        : `${Number(payload.price_amount).toFixed(2)} USD`;
                    cartButton.dataset.cartPriceUah = payload.price_amount_uah ?? '';
                    cartButton.dataset.cartPriceUahText = payload.price_amount_uah_text || '';
                    cartButton.hidden = !hasPositivePrice;

                    if (cartPlaceholder) {
                        cartPlaceholder.hidden = hasPositivePrice;
                    }
                }

                if (priceDisplay && priceEditor && payload.price_amount !== undefined) {
                    priceEditor.hidden = true;
                    priceDisplay.hidden = false;
                }

                if (availabilityCell) {
                    availabilityCell.textContent = payload.availability || '-';
                }

                if (payload.total_value_usd !== undefined) {
                    totalValueNodes.forEach((node) => {
                        node.textContent = payload.total_value_usd;
                    });
                }

                if (payload.items_count !== undefined) {
                    countNodes.forEach((count) => {
                        count.textContent = payload.items_count;
                    });
                }

                if (payload.unique_articles_count !== undefined) {
                    uniqueArticleCountNodes.forEach((count) => {
                        count.textContent = payload.unique_articles_count;
                    });
                }

                if (payload.added_today_count !== undefined) {
                    addedTodayCountNodes.forEach((count) => {
                        count.textContent = payload.added_today_count;
                    });
                }

                if (button) {
                    button.textContent = '\u0421\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u043e';
                    setTimeout(() => {
                        button.textContent = oldText || '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c';
                    }, 1200);
                }
            } catch (error) {
                alert('\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c. \u041e\u0431\u043d\u043e\u0432\u0438\u0442\u0435 \u0441\u0442\u0440\u0430\u043d\u0438\u0446\u0443 \u0438 \u043f\u043e\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0435\u0449\u0435 \u0440\u0430\u0437.');
                if (button) {
                    button.textContent = oldText || '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c';
                }
            } finally {
                if (button) {
                    button.disabled = false;
                }
            }
        });
    });
})();

(() => {
    document.querySelectorAll('[data-nikolacars-part-number-cell]').forEach((cell) => {
        const display = cell.querySelector('[data-nikolacars-part-number-display]');
        const editor = cell.querySelector('[data-nikolacars-part-number-editor]');
        const toggle = cell.querySelector('[data-nikolacars-part-number-edit-toggle]');
        const cancel = cell.querySelector('[data-nikolacars-part-number-edit-cancel]');
        const input = cell.querySelector('[data-nikolacars-part-number-input]');
        const originalValue = () => input?.defaultValue ?? '';

        toggle?.addEventListener('click', () => {
            if (!display || !editor) return;

            display.hidden = true;
            editor.hidden = false;
            input?.focus();
            input?.select();
        });

        cancel?.addEventListener('click', () => {
            if (!display || !editor) return;

            if (input) input.value = originalValue();
            editor.hidden = true;
            display.hidden = false;
        });

        input?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                cancel?.click();
            }
        });
    });

    document.querySelectorAll('[data-nikolacars-price-cell]').forEach((cell) => {
        const display = cell.querySelector('[data-nikolacars-price-display]');
        const editor = cell.querySelector('[data-nikolacars-price-editor]');
        const toggle = cell.querySelector('[data-nikolacars-price-edit-toggle]');
        const cancel = cell.querySelector('[data-nikolacars-price-edit-cancel]');
        const input = cell.querySelector('[data-nikolacars-price-input]');
        const originalValue = () => input?.defaultValue ?? '';

        toggle?.addEventListener('click', () => {
            if (!display || !editor) return;

            display.hidden = true;
            editor.hidden = false;
            input?.focus();
            input?.select();
        });

        cancel?.addEventListener('click', () => {
            if (!display || !editor) return;

            if (input) input.value = originalValue();
            editor.hidden = true;
            display.hidden = false;
        });

        input?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                cancel?.click();
            }
        });
    });
})();

(() => {
    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement
            ? event.target.closest('[data-nikolacars-delete-form]')
            : null;

        if (!form) return;

        event.preventDefault();
        event.stopPropagation();

        const message = form.dataset.confirm || '\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u043f\u043e\u0437\u0438\u0446\u0438\u044e?';
        if (!confirm(message)) return;

        const button = form.querySelector('button[type="submit"]');
        const row = form.closest('[data-nikolacars-item-row], [data-nikolacars-group-child]');
        const isGroupedChildRow = row?.matches('[data-nikolacars-group-child]') || false;
        const removedPartsCount = Number(row?.dataset.nikolacarsPartsCount || 1);
        const countNodes = document.querySelectorAll('[data-nikolacars-items-count]');
        const uniqueArticleCountNodes = document.querySelectorAll('[data-nikolacars-unique-articles-count]');
        const addedTodayCountNodes = document.querySelectorAll('[data-nikolacars-added-today-count]');
        const visibleRowsCountNodes = document.querySelectorAll('[data-nikolacars-visible-rows-count]');
        const totalValueNodes = document.querySelectorAll('[data-nikolacars-total-value]');
        const oldText = button?.textContent;
        const progressText = form.dataset.progress || '\u0423\u0434\u0430\u043b\u044f\u0435\u043c...';
        const errorText = form.dataset.error || '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0443\u0434\u0430\u043b\u0438\u0442\u044c \u043f\u043e\u0437\u0438\u0446\u0438\u044e. \u041e\u0431\u043d\u043e\u0432\u0438\u0442\u0435 \u0441\u0442\u0440\u0430\u043d\u0438\u0446\u0443 \u0438 \u043f\u043e\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0435\u0449\u0435 \u0440\u0430\u0437.';

        if (button) {
            button.disabled = true;
            button.textContent = progressText;
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) throw new Error('delete failed');

            const payload = await response.json();
            const scrollX = window.scrollX;
            const scrollY = window.scrollY;

            if (document.activeElement instanceof HTMLElement) {
                document.activeElement.blur();
            }

            if (isGroupedChildRow) {
                window.location.reload();
                return;
            }

            row?.remove();
            decrementTextCounters(visibleRowsCountNodes, removedPartsCount);
            requestAnimationFrame(() => {
                window.scrollTo(scrollX, scrollY);
            });

            const remainingRowNodes = Array.from(document.querySelectorAll('[data-nikolacars-item-row]'));
            const remainingRows = remainingRowNodes.length;

            if (remainingRows === 0) {
                const tableBody = document.querySelector('[data-nikolacars-items-body]');
                const emptyRow = document.querySelector('[data-nikolacars-empty-row]');

                if (tableBody && !emptyRow) {
                    tableBody.insertAdjacentHTML('beforeend', '<tr data-nikolacars-empty-row><td colspan="11" class="empty">\u0417\u0430\u043f\u0447\u0430\u0441\u0442\u0438 \u043d\u0435 \u043d\u0430\u0439\u0434\u0435\u043d\u044b.</td></tr>');
                }
            }

            if (payload.items_count !== undefined) {
                countNodes.forEach((count) => {
                    count.textContent = payload.items_count;
                });
            }

            if (payload.unique_articles_count !== undefined) {
                uniqueArticleCountNodes.forEach((count) => {
                    count.textContent = payload.unique_articles_count;
                });
            }

            if (payload.added_today_count !== undefined) {
                addedTodayCountNodes.forEach((count) => {
                    count.textContent = payload.added_today_count;
                });
            }

            if (payload.total_value_usd !== undefined) {
                totalValueNodes.forEach((node) => {
                    node.textContent = payload.total_value_usd;
                });
            }
        } catch (error) {
            alert(errorText);
            if (button) {
                button.disabled = false;
                button.textContent = oldText || '\u0423\u0434\u0430\u043b\u0438\u0442\u044c';
            }
        }
    }, true);
})();

(() => {
    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement
            ? event.target.closest('[data-nikolacars-manual-sold-form]')
            : null;

        if (!form) return;

        event.preventDefault();
        event.stopPropagation();

        const message = form.dataset.confirm || '\u041f\u043e\u043c\u0435\u0442\u0438\u0442\u044c \u043f\u043e\u0437\u0438\u0446\u0438\u044e \u043a\u0430\u043a \u043f\u0440\u043e\u0434\u0430\u043d\u043d\u0443\u044e?';
        if (!confirm(message)) return;

        const button = form.querySelector('button[type="submit"]');
        const row = form.closest('[data-nikolacars-item-row], [data-nikolacars-group-child]');
        const isGroupedChildRow = row?.matches('[data-nikolacars-group-child]') || false;
        const soldPartsCount = Number(row?.dataset.nikolacarsPartsCount || 1);
        const countNodes = document.querySelectorAll('[data-nikolacars-items-count]');
        const uniqueArticleCountNodes = document.querySelectorAll('[data-nikolacars-unique-articles-count]');
        const addedTodayCountNodes = document.querySelectorAll('[data-nikolacars-added-today-count]');
        const visibleRowsCountNodes = document.querySelectorAll('[data-nikolacars-visible-rows-count]');
        const totalValueNodes = document.querySelectorAll('[data-nikolacars-total-value]');
        const oldHtml = button?.innerHTML;
        const errorText = form.dataset.error || '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u043f\u043e\u043c\u0435\u0442\u0438\u0442\u044c \u043f\u043e\u0437\u0438\u0446\u0438\u044e \u043a\u0430\u043a \u043f\u0440\u043e\u0434\u0430\u043d\u043d\u0443\u044e. \u041e\u0431\u043d\u043e\u0432\u0438\u0442\u0435 \u0441\u0442\u0440\u0430\u043d\u0438\u0446\u0443 \u0438 \u043f\u043e\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0435\u0449\u0435 \u0440\u0430\u0437.';

        if (button) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) throw new Error('manual sold failed');

            const payload = await response.json();
            const scrollX = window.scrollX;
            const scrollY = window.scrollY;

            if (document.activeElement instanceof HTMLElement) {
                document.activeElement.blur();
            }

            if (isGroupedChildRow) {
                window.location.reload();
                return;
            }

            if (row) {
                row.classList.add('nikolacars-sold-row', 'nikolacars-zero-stock-row');
                row.querySelector('[data-nikolacars-availability]')?.replaceChildren(
                    document.createTextNode(payload.availability || '0 \u0448\u0442'),
                    Object.assign(document.createElement('div'), {
                        className: 'nikolacars-sold-note',
                        textContent: '\u041f\u0440\u043e\u0434\u0430\u043d\u043e \u0434\u043e 01.06.2026',
                    }),
                );
                row.querySelector('[data-nikolacars-cart-add]')?.replaceWith(Object.assign(document.createElement('span'), {
                    className: 'help',
                    textContent: '-',
                }));
                row.querySelector('[data-nikolacars-cart-placeholder]')?.remove();
                row.querySelectorAll([
                    '[data-nikolacars-update-form]',
                    '[data-nikolacars-delete-form]',
                    '[data-nikolacars-manual-sold-form]',
                    '[data-nikolacars-price-edit-toggle]',
                    '[data-nikolacars-price-editor]',
                    '[data-nikolacars-part-number-edit-toggle]',
                    '[data-nikolacars-part-number-editor]',
                    '[data-nikolacars-category-edit-toggle]',
                    '[data-nikolacars-category-editor]',
                ].join(',')).forEach((node) => node.remove());
                row.closest('tbody')?.appendChild(row);
            }

            requestAnimationFrame(() => {
                window.scrollTo(scrollX, scrollY);
            });
            if (hidesNikolaCarsSoldItems()) {
                decrementTextCounters(visibleRowsCountNodes, soldPartsCount);
            }

            if (payload.items_count !== undefined) {
                countNodes.forEach((count) => {
                    count.textContent = payload.items_count;
                });
            }

            if (payload.unique_articles_count !== undefined) {
                uniqueArticleCountNodes.forEach((count) => {
                    count.textContent = payload.unique_articles_count;
                });
            }

            if (payload.added_today_count !== undefined) {
                addedTodayCountNodes.forEach((count) => {
                    count.textContent = payload.added_today_count;
                });
            }

            if (payload.total_value_usd !== undefined) {
                totalValueNodes.forEach((node) => {
                    node.textContent = payload.total_value_usd;
                });
            }
        } catch (error) {
            alert(errorText);
            if (button) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                if (oldHtml !== undefined) {
                    button.innerHTML = oldHtml;
                }
            }
        }
    }, true);
})();

(() => {
    document.querySelectorAll('[data-nikolacars-category-editor]').forEach((editor) => {
        const cell = editor.closest('[data-nikolacars-category-cell]');
        const toggle = cell?.querySelector('[data-nikolacars-category-edit-toggle]');
        const display = cell?.querySelector('[data-nikolacars-category-display]');
        const input = editor.querySelector('[data-nikolacars-category-search-input]');
        const suggestions = editor.querySelector('[data-nikolacars-category-suggestions]');
        const searchUrl = editor.dataset.searchUrl;
        const updateUrl = editor.dataset.updateUrl;
        let timer = null;
        let controller = null;

        const hideSuggestions = () => {
            if (!suggestions) return;
            suggestions.hidden = true;
            suggestions.innerHTML = '';
        };

        const renderSuggestions = (items) => {
            if (!suggestions) return;

            suggestions.innerHTML = '';
            items.forEach((item) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'nikolacars-category-suggestion';
                button.innerHTML = '<strong></strong><span></span>';
                button.querySelector('strong').textContent = item.name || '';
                button.querySelector('span').textContent = item.model || '';
                button.addEventListener('click', () => assignCategory(item.id));
                suggestions.appendChild(button);
            });

            suggestions.hidden = items.length === 0;
        };

        const assignCategory = async (categoryId) => {
            if (!categoryId || !updateUrl || !input) return;

            input.disabled = true;
            input.value = '\u0421\u043e\u0445\u0440\u0430\u043d\u044f\u0435\u043c...';
            hideSuggestions();

            try {
                const body = new FormData();
                body.append('_method', 'PATCH');
                body.append('_token', config.csrfToken || '');
                body.append('category_id', categoryId);

                const response = await fetch(updateUrl, {
                    method: 'POST',
                    body,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) throw new Error('category update failed');

                const payload = await response.json();

                if (display) {
                    display.textContent = payload.category || '-';
                }

                if (toggle) {
                    toggle.remove();
                }

                editor.hidden = true;
            } catch (error) {
                alert('\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u043d\u0430\u0437\u043d\u0430\u0447\u0438\u0442\u044c \u043a\u0430\u0442\u0435\u0433\u043e\u0440\u0438\u044e. \u041e\u0431\u043d\u043e\u0432\u0438\u0442\u0435 \u0441\u0442\u0440\u0430\u043d\u0438\u0446\u0443 \u0438 \u043f\u043e\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0435\u0449\u0435 \u0440\u0430\u0437.');
            } finally {
                input.disabled = false;
                input.value = '';
            }
        };

        toggle?.addEventListener('click', () => {
            editor.hidden = !editor.hidden;
            if (!editor.hidden) input?.focus();
        });

        input?.addEventListener('input', () => {
            const query = input.value.trim();
            clearTimeout(timer);

            if (query.length < 1 || !searchUrl) {
                hideSuggestions();
                return;
            }

            timer = setTimeout(async () => {
                if (controller) controller.abort();
                controller = new AbortController();

                try {
                    const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    });

                    if (!response.ok) return;
                    renderSuggestions(await response.json());
                } catch (error) {
                    if (error.name !== 'AbortError') hideSuggestions();
                }
            }, 220);
        });

        document.addEventListener('click', (event) => {
            if (!editor.contains(event.target) && event.target !== toggle) {
                hideSuggestions();
            }
        });
    });
})();

(() => {
    const dropdowns = [...document.querySelectorAll('details[data-close-on-outside]')];
    if (dropdowns.length === 0) return;

    document.addEventListener('click', (event) => {
        dropdowns.forEach((dropdown) => {
            if (!dropdown.contains(event.target)) {
                dropdown.open = false;
            }
        });
    });
})();

(() => {
    const root = document.querySelector('[data-model-dropdown]');
    if (!root) return;

    const toggle = root.querySelector('[data-model-dropdown-toggle]');
    const menu = root.querySelector('[data-model-dropdown-menu]');
    const label = root.querySelector('[data-model-dropdown-label]');
    const checkboxes = [...root.querySelectorAll('[data-model-checkbox]')];

    const updateLabel = () => {
        const selected = checkboxes.filter((checkbox) => checkbox.checked);
        const cybertruckSelected = selected.some((checkbox) => checkbox.name === 'include_cybertruck');
        const selectedRegular = selected.filter((checkbox) => checkbox.name !== 'include_cybertruck').length;

        label.textContent = cybertruckSelected
            ? `Выбрано: ${selected.length}`
            : `Выбрано: ${selectedRegular} без Cybertruck`;
    };

    toggle.addEventListener('click', () => {
        menu.hidden = !menu.hidden;
    });

    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateLabel));

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) {
            menu.hidden = true;
        }
    });

    updateLabel();
})();

(() => {
    const root = document.querySelector('[data-catalog-search-url]');
    if (!root) return;

    const input = root.querySelector('[data-catalog-search-input]');
    const suggestions = root.querySelector('[data-catalog-search-suggestions]');
    const searchUrl = root.dataset.catalogSearchUrl;
    let timer = null;
    let controller = null;

    const hide = () => {
        suggestions.hidden = true;
        suggestions.innerHTML = '';
    };

    input.addEventListener('input', () => {
        const query = input.value.trim();
        clearTimeout(timer);

        if (query.length < 1) {
            hide();
            return;
        }

        timer = setTimeout(async () => {
            if (controller) controller.abort();
            controller = new AbortController();

            try {
                const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });

                if (!response.ok) return;
                const items = await response.json();
                suggestions.innerHTML = '';

                items.forEach((item) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'catalog-search-suggestion';
                    button.innerHTML = '<strong></strong><span></span>';
                    button.querySelector('strong').textContent = item.name;
                    button.querySelector('span').textContent = [
                        item.part_number ? `Артикул: ${item.part_number}` : null,
                        item.model,
                        item.category,
                    ].filter(Boolean).join(' · ');
                    button.addEventListener('click', () => {
                        input.value = item.part_number || item.name;
                        hide();
                        input.form?.requestSubmit();
                    });
                    suggestions.appendChild(button);
                });

                suggestions.hidden = items.length === 0;
            } catch (error) {
                if (error.name !== 'AbortError') hide();
            }
        }, 220);
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) hide();
    });
})();
