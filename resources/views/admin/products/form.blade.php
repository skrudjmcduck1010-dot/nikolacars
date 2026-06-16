@extends('layouts.admin', ['heading' => $product->exists ? ' ' : ' '])

@section('content')
    <form method="POST" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}" class="panel">
        @csrf
        @if($product->exists) @method('PUT') @endif
        <div class="form-grid">
            <div><label>Код</label><input value="{{ $product->sku ?: 'будет сгенерирован автоматически' }}" disabled></div>
            <div><label>Артикул</label><input name="external_sku" value="{{ old('external_sku', $product->external_sku) }}"></div>
            <div class="product-name-autocomplete" data-product-search-url="{{ route('admin.products.search') }}">
                <label>Название</label>
                <input name="name" value="{{ old('name', $product->name) }}" required autocomplete="off" data-product-name-input>
                <div class="product-suggestions" data-product-suggestions hidden></div>
            </div>
            <div><label>Slug</label><input name="slug" value="{{ old('slug', $product->slug) }}" required></div>
            <div>
                <label>Категория</label>
                <select name="category_id"><option value="">—</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id', $product->category_id) == $category->id)>{{ $category->name }}</option>@endforeach</select>
            </div>
            <div>
                <label>Бренд</label>
                <select name="brand_id"><option value="">—</option>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(old('brand_id', $product->brand_id) == $brand->id)>{{ $brand->name }}</option>@endforeach</select>
            </div>
            <div>
                <label>Донорский автомобиль</label>
                <select name="donor_car_id"><option value="">—</option>@foreach($donorCars as $donorCar)<option value="{{ $donorCar->id }}" @selected(old('donor_car_id', $product->donor_car_id) == $donorCar->id)>{{ $donorCar->vin }}</option>@endforeach</select>
            </div>
            <div>
                <label>Тип запчасти</label>
                @php($selectedPartOrigin = old('part_origin', $product->part_origin ?: ($product->donor_car_id ? \App\Models\Product::PART_ORIGIN_ORIGINAL : '')))
                <select name="part_origin">
                    <option value="">—</option>
                    @foreach($partOrigins as $value => $label)
                        <option value="{{ $value }}" @selected($selectedPartOrigin === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Модель</label>
                @php($selectedModel = old('model', $product->model))
                <select name="model">
                    <option value="">—</option>
                    @foreach($models as $model)
                        <option value="{{ $model }}" @selected($selectedModel === $model)>{{ $model }}</option>
                    @endforeach
                    @if($selectedModel && ! in_array($selectedModel, $models, true))
                        <option value="{{ $selectedModel }}" selected>{{ $selectedModel }}</option>
                    @endif
                </select>
            </div>
            <div><label>Цвет</label><input name="color" value="{{ old('color', $product->color) }}"></div>
            <div><label>Поколение</label><input name="generation" value="{{ old('generation', $product->generation) }}"></div>
            <div><label>Сторона</label><select name="side"><option value="">—</option>@foreach($sides as $side)<option value="{{ $side }}" @selected(old('side', $product->side) === $side)>{{ ['left' => 'Левая', 'right' => 'Правая', 'front' => 'Передняя', 'rear' => 'Задняя'][$side] }}</option>@endforeach</select></div>
            <div>
                <label>Состояние</label>
                @php($selectedConditionType = old('condition_type', $product->condition_type ?: 'used'))
                <select name="condition_type">
                    @foreach($conditionTypes as $value => $label)
                        <option value="{{ $value }}" @selected($selectedConditionType === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div><label>Статус проверки</label><select name="testing_status">@foreach($testingStatuses as $status)<option value="{{ $status }}" @selected(old('testing_status', $product->testing_status ?: 'not_tested') === $status)>{{ $status === 'tested' ? 'Проверен' : 'Не проверен' }}</option>@endforeach</select></div>
            <div><label>Единица</label><select name="unit">@foreach($units as $unit)<option value="{{ $unit }}" @selected(old('unit', $product->unit ?: 'pcs') === $unit)>{{ ['pcs' => 'шт', 'set' => 'комплект', 'pair' => 'пара'][$unit] }}</option>@endforeach</select></div>
            <div><label>Закупочная цена</label><input type="number" step="0.01" name="purchase_price" value="{{ old('purchase_price', $product->purchase_price) }}"></div>
            <div><label>Цена продажи</label><input type="number" step="0.01" name="selling_price" value="{{ old('selling_price', $product->selling_price) }}"></div>
            <div><label>Валюта</label><input name="currency" value="{{ old('currency', $product->currency ?: 'USD') }}" required></div>
            <div><label>Штрихкод</label><input name="barcode" value="{{ old('barcode', $product->barcode) }}"></div>
            <div><label>QR-код</label><input name="qr_code" value="{{ old('qr_code', $product->qr_code) }}"></div>
            <div><label>Путь к главному изображению</label><input name="main_image" value="{{ old('main_image', $product->main_image) }}"></div>
            <div><label>Вес</label><input type="number" step="0.001" name="weight" value="{{ old('weight', $product->weight) }}"></div>
            <div class="full"><label>Совместимость</label><textarea name="compatibility">{{ old('compatibility', $product->compatibility) }}</textarea></div>
            <div class="full"><label>Описание</label><textarea name="description">{{ old('description', $product->description) }}</textarea></div>
            <div class="full"><label>Дополнительные изображения (по одному пути на строку)</label><textarea name="images_json">{{ old('images_json', $product->images_json ? implode(PHP_EOL, (array) $product->images_json) : '') }}</textarea></div>
            <div class="full"><label>Примечания</label><textarea name="notes">{{ old('notes', $product->notes) }}</textarea></div>
            <div style="display:flex;align-items:end;"><label><input type="checkbox" name="is_active" value="1" {{ old('is_active', $product->is_active ?? true) ? 'checked' : '' }} style="width:auto;"> Активен</label></div>
        </div>
        <div class="actions" style="margin-top:20px;"><button type="submit">Сохранить</button></div>
    </form>

    <style>
        .product-name-autocomplete { position: relative; }
        .product-suggestions {
            position: absolute;
            z-index: 20;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            max-height: 280px;
            overflow-y: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: white;
            box-shadow: 0 12px 30px rgba(25, 32, 36, .14);
        }
        .product-suggestion {
            width: 100%;
            display: block;
            border: 0;
            border-radius: 0;
            padding: 10px 12px;
            background: white;
            color: var(--text);
            text-align: left;
            cursor: pointer;
        }
        .product-suggestion:hover,
        .product-suggestion:focus { background: var(--accent-soft); outline: none; }
        .product-suggestion-title { display: block; font-weight: 700; }
        .product-suggestion-meta { display: block; margin-top: 3px; color: var(--muted); font-size: 12px; line-height: 1.35; }
        .product-suggestion-empty { padding: 10px 12px; color: var(--muted); font-size: 13px; }
    </style>

    <script>
        (() => {
            const root = document.querySelector('[data-product-search-url]');
            if (!root) return;

            const input = root.querySelector('[data-product-name-input]');
            const suggestions = root.querySelector('[data-product-suggestions]');
            const searchUrl = root.dataset.productSearchUrl;
            let searchTimeout = null;
            let activeController = null;

            const hideSuggestions = () => {
                suggestions.hidden = true;
                suggestions.innerHTML = '';
            };

            const setField = (name, value, overwrite = false) => {
                if (value === null || value === undefined || value === '') return;
                const field = document.querySelector(`[name="${name}"]`);
                if (!field || (!overwrite && field.value)) return;

                if (field.tagName === 'SELECT' && ![...field.options].some((option) => option.value === String(value))) {
                    field.add(new Option(value, value));
                }

                field.value = value;
            };

            const renderSuggestions = (products) => {
                suggestions.innerHTML = '';

                if (!products.length) {
                    suggestions.innerHTML = '<div class="product-suggestion-empty">Ничего не найдено</div>';
                    suggestions.hidden = false;
                    return;
                }

                products.forEach((product) => {
                    const button = document.createElement('button');
                    const meta = [
                        product.type === 'catalog' ? 'Справочник TCARS' : null,
                        product.sku ? `SKU: ${product.sku}` : null,
                        product.external_sku ? `Парт №: ${product.external_sku}` : null,
                        product.category_name,
                        product.brand_name,
                        product.model,
                    ].filter(Boolean).join(' · ');

                    button.type = 'button';
                    button.className = 'product-suggestion';
                    button.innerHTML = `
                        <span class="product-suggestion-title"></span>
                        <span class="product-suggestion-meta"></span>
                    `;
                    button.querySelector('.product-suggestion-title').textContent = product.name;
                    button.querySelector('.product-suggestion-meta').textContent = meta || '\u00a0';
                    button.addEventListener('click', () => {
                        input.value = product.name;
                        setField('external_sku', product.external_sku);
                        setField('slug', product.slug, true);
                        setField('category_id', product.category_id, true);
                        setField('brand_id', product.brand_id, true);
                        setField('part_origin', product.part_origin, true);
                        setField('model', product.model, true);
                        setField('color', product.color, true);
                        setField('generation', product.generation, true);
                        setField('side', product.side, true);
                        setField('testing_status', product.testing_status, true);
                        setField('unit', product.unit, true);
                        setField('purchase_price', product.purchase_price, true);
                        setField('selling_price', product.selling_price, true);
                        setField('currency', product.currency, true);
                        setField('weight', product.weight, true);
                        setField('compatibility', product.compatibility, true);
                        setField('description', product.description, true);
                        hideSuggestions();
                    });
                    suggestions.appendChild(button);
                });

                suggestions.hidden = false;
            };

            input.addEventListener('input', () => {
                const query = input.value.trim();
                clearTimeout(searchTimeout);

                if (query.length < 2) {
                    hideSuggestions();
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
                        renderSuggestions(await response.json());
                    } catch (error) {
                        if (error.name !== 'AbortError') hideSuggestions();
                    }
                }, 220);
            });

            document.addEventListener('click', (event) => {
                if (!root.contains(event.target)) hideSuggestions();
            });

            input.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') hideSuggestions();
            });
        })();
    </script>
@endsection
