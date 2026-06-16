@extends('layouts.admin', ['heading' => 'Сделать закупку', 'subheading' => 'Создает расход в Базе операций и сразу размещает запчасти на складе'])

@php($oldItems = old('items', [['quantity' => 1]]))

@section('content')
    <form method="POST" action="{{ route('admin.purchases.store') }}" class="panel" enctype="multipart/form-data">
        @csrf

        <div>
            <h2 style="margin:0 0 12px;">Позиции</h2>
            <div data-purchase-items style="display:grid;gap:14px;">
                @foreach ($oldItems as $index => $item)
                    @include('admin.purchases._item_row', ['index' => $index, 'item' => $item])
                @endforeach
            </div>
            <template data-purchase-item-template>
                @include('admin.purchases._item_row', ['index' => '__INDEX__', 'item' => ['quantity' => 1]])
            </template>
            <div class="actions" style="margin-top:14px;">
                <button type="button" class="btn btn-secondary" data-add-purchase-item>Добавить позицию</button>
            </div>
        </div>

        <div class="actions" style="margin-top:22px;">
            <button type="submit">Провести закупку</button>
            <a class="btn btn-secondary" href="{{ route('admin.purchases.index') }}">Назад</a>
        </div>
    </form>

    <style>
        .purchase-item {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            background: #fff;
        }

        .purchase-item-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .purchase-comment-field {
            min-height: 88px;
            resize: vertical;
        }

        .purchase-suggestions {
            position: absolute;
            z-index: 30;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            max-height: 240px;
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: white;
            box-shadow: 0 12px 30px rgba(25, 32, 36, .14);
        }

        .purchase-suggestion {
            display: block;
            width: 100%;
            border: 0;
            border-radius: 0;
            padding: 8px 10px;
            background: white;
            color: var(--text);
            text-align: left;
        }

        .purchase-suggestion:hover {
            background: var(--accent-soft);
        }

        .purchase-suggestion:focus {
            outline: 2px solid var(--accent);
            outline-offset: -2px;
            background: var(--accent-soft);
        }

        .purchase-suggestion-meta {
            display: block;
            margin-top: 2px;
        }

        @media (max-width: 980px) {
            .purchase-item-grid {
                grid-template-columns: 1fr;
            }

            .purchase-item-grid > * {
                grid-column: auto !important;
            }
        }
    </style>

    <script>
        (() => {
            const productSearchUrl = @json(route('admin.products.search'));
            const warehouses = @json($warehouseOptions);
            const locations = @json($locationOptions);
            const root = document.querySelector('[data-purchase-items]');
            const template = document.querySelector('[data-purchase-item-template]');
            const addButton = document.querySelector('[data-add-purchase-item]');

            const bindRow = (row) => {
                const nameInput = row.querySelector('[data-product-name]');
                const skuInput = row.querySelector('[data-product-external-sku]');
                const productIdInput = row.querySelector('[data-product-id]');
                const nameSuggestions = row.querySelector('[data-product-name-suggestions]');
                const skuSuggestions = row.querySelector('[data-product-sku-suggestions]');
                const warehouseSelect = row.querySelector('[data-warehouse-select]');
                const floorWrap = row.querySelector('[data-floor-wrap]');
                const floorSelect = row.querySelector('[data-floor-select]');
                const locationWrap = row.querySelector('[data-location-wrap]');
                const locationSelect = row.querySelector('[data-location-select]');
                const purchasePriceWrap = row.querySelector('[data-purchase-price-usd]')?.closest('div');
                let timer = null;
                let lastResults = [];
                const normalizeProductValue = (value) => String(value || '').trim().toLocaleLowerCase();

                const renderLocations = () => {
                    const warehouse = warehouses.find((item) => String(item.id) === warehouseSelect.value);
                    const warehouseLocations = locations.filter((item) => String(item.warehouse_id) === warehouseSelect.value);
                    const locationFloors = [...new Set(warehouseLocations.map((item) => item.floor).filter(Boolean))];
                    const floors = warehouse?.floors?.length ? warehouse.floors.map((floor) => floor.value) : locationFloors;
                    const hasLocations = warehouseLocations.length > 0;
                    const selectedLocation = warehouseLocations.find((item) => String(item.id) === String(locationSelect.dataset.selectedLocation || locationSelect.value));
                    const selectedFloor = floors.includes(floorSelect.value)
                        ? floorSelect.value
                        : selectedLocation?.floor || '';

                    floorWrap.hidden = !warehouse || Number(warehouse.floor_count || 1) <= 1 || floors.length <= 1;
                    floorSelect.innerHTML = '<option value="">Этаж</option>' + floors.map((floor) => `<option value="${floor}">${floor.replace('floor_', 'Этаж ')}</option>`).join('');
                    floorSelect.value = selectedFloor;
                    floorSelect.disabled = floorWrap.hidden;
                    floorSelect.required = !floorWrap.hidden;
                    locationWrap.hidden = Boolean(warehouse) && !hasLocations;
                    locationSelect.disabled = Boolean(warehouse) && !hasLocations;
                    locationSelect.required = !locationSelect.disabled;

                    const renderCells = () => {
                        const selectedLocation = locationSelect.dataset.selectedLocation || locationSelect.value;
                        const selectedFloor = floorWrap.hidden ? '' : floorSelect.value;
                        const filtered = warehouseLocations.filter((item) => !selectedFloor || item.floor === selectedFloor);
                        locationSelect.innerHTML = '<option value="">Выбрать</option>' + filtered.map((item) => `<option value="${item.id}" ${String(item.id) === String(selectedLocation) ? 'selected' : ''}>${item.full_code}</option>`).join('');
                        locationSelect.dataset.selectedLocation = '';
                    };

                    floorSelect.onchange = renderCells;
                    renderCells();
                };

                warehouseSelect?.addEventListener('change', renderLocations);
                renderLocations();
                if (purchasePriceWrap) purchasePriceWrap.style.gridColumn = '1';

                const applyProduct = (product) => {
                    productIdInput.value = product.type === 'product' ? (product.id || '') : '';
                    nameInput.value = product.name || '';
                    const modelSelect = row.querySelector('[data-model-select]');
                    const conditionTypeSelect = row.querySelector('[data-condition-type-select]');
                    const colorInput = row.querySelector('[data-product-color]');
                    const descriptionInput = row.querySelector('[name$="[description]"]');
                    const purchasePriceInput = row.querySelector('[data-purchase-price-usd]');
                    const sellingPriceInput = row.querySelector('[name$="[selling_price_usd]"]');

                    if (product.model && modelSelect) {
                        if (![...modelSelect.options].some((option) => option.value === String(product.model))) {
                            modelSelect.add(new Option(product.model, product.model));
                        }

                        modelSelect.value = product.model;
                    }
                    if (product.condition_type && conditionTypeSelect) conditionTypeSelect.value = product.condition_type;
                    if (colorInput) colorInput.value = product.color || '';
                    if (descriptionInput) descriptionInput.value = product.description || '';
                    if (skuInput) skuInput.value = product.external_sku || product.sku || '';
                    if (purchasePriceInput && product.purchase_price) purchasePriceInput.value = product.purchase_price;
                    if (sellingPriceInput && product.selling_price && (!product.currency || product.currency === 'USD')) sellingPriceInput.value = product.selling_price;
                    hideSuggestions();
                };

                const hideSuggestions = () => {
                    [nameSuggestions, skuSuggestions].forEach((container) => {
                        if (!container) return;

                        container.hidden = true;
                        container.innerHTML = '';
                    });
                };

                const renderSuggestions = (container, products) => {
                    if (!container) return;

                    container.innerHTML = '';

                    products.slice(0, 12).forEach((product) => {
                        if (!product.name) return;

                        const button = document.createElement('button');
                        const title = document.createElement('span');
                        const meta = document.createElement('span');
                        const source = product.type === 'catalog' ? 'Каталог TCARS' : 'Склад';
                        const article = product.external_sku || product.sku || '';

                        button.type = 'button';
                        button.className = 'purchase-suggestion';
                        title.textContent = product.name;
                        meta.className = 'purchase-suggestion-meta help';
                        meta.textContent = [source, article ? `Артикул: ${article}` : '', product.category_name || '', product.model || '']
                            .filter(Boolean)
                            .join(' · ');

                        button.append(title, meta);
                        button.addEventListener('mousedown', (event) => event.preventDefault());
                        button.addEventListener('click', () => applyProduct(product));
                        container.append(button);
                    });

                    container.hidden = !container.children.length;
                };

                const searchProducts = (query, container) => {
                    const normalizedQuery = normalizeProductValue(query);
                    clearTimeout(timer);

                    if (normalizedQuery.length < 2) {
                        hideSuggestions();
                        lastResults = [];
                        return;
                    }

                    const cachedProduct = lastResults.find((product) => [
                        product.name,
                        product.external_sku,
                        product.sku,
                    ].some((value) => normalizeProductValue(value) === normalizedQuery));

                    if (cachedProduct) {
                        applyProduct(cachedProduct);
                        return;
                    }

                    timer = setTimeout(async () => {
                        const activeInput = container === skuSuggestions ? skuInput : nameInput;
                        const requestedQuery = query;
                        const response = await fetch(`${productSearchUrl}?q=${encodeURIComponent(requestedQuery)}`, { headers: { Accept: 'application/json' } });
                        if (!response.ok) return;

                        const products = await response.json();

                        if (activeInput?.value.trim() !== requestedQuery) {
                            return;
                        }

                        lastResults = products;
                        renderSuggestions(container, products);

                        const exactProduct = products.find((product) => [
                            product.name,
                            product.external_sku,
                            product.sku,
                        ].some((value) => normalizeProductValue(value) === normalizeProductValue(activeInput?.value)));

                        if (exactProduct) {
                            applyProduct(exactProduct);
                        }
                    }, 220);
                };

                nameInput?.addEventListener('input', () => {
                    productIdInput.value = '';
                    searchProducts(nameInput.value.trim(), nameSuggestions);
                });

                skuInput?.addEventListener('input', () => {
                    productIdInput.value = '';
                    searchProducts(skuInput.value.trim(), skuSuggestions);
                });

                nameInput?.addEventListener('blur', () => setTimeout(hideSuggestions, 160));
                skuInput?.addEventListener('blur', () => setTimeout(hideSuggestions, 160));

                row.querySelector('[data-remove-purchase-item]')?.addEventListener('click', () => {
                    const rows = [...root.querySelectorAll('[data-purchase-item]')];
                    if (rows.length <= 1) return;

                    row.remove();
                });

            };

            root?.querySelectorAll('[data-purchase-item]').forEach(bindRow);

            addButton?.addEventListener('click', () => {
                const index = root.querySelectorAll('[data-purchase-item]').length;
                const wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(index)).trim();
                const row = wrapper.firstElementChild;
                root.appendChild(row);
                bindRow(row);
            });

        })();
    </script>
@endsection
