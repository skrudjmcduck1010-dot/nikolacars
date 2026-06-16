@extends('layouts.mobile', [
    'heading' => 'Новая запчасть',
    'subheading' => $donorCar->vin,
])

@section('content')
    <section class="panel">
        <div class="donor-card__top">
            <div>
                <div class="donor-card__vin">{{ $donorCar->vin }}</div>
                <div class="donor-card__meta">
                    {{ collect([$donorCar->brand, $donorCar->display_model, $donorCar->year])->filter()->join(' ') }}
                </div>
            </div>
            <span class="tag">{{ $nextPartCode }}</span>
        </div>
        <div class="help" style="margin-top:8px;">Уже добавлено: {{ $donorCar->products_count }} шт.</div>
    </section>

    <form method="POST" action="{{ route('admin.mobile.donor-cars.products.store', $donorCar) }}" enctype="multipart/form-data" data-mobile-part-form>
        @csrf

        <section class="panel form-grid">
            <div class="autocomplete" data-part-search-url="{{ route('admin.mobile.donor-cars.products.search', $donorCar) }}">
                <label for="part-name">Название запчасти</label>
                <input id="part-name" name="name" value="{{ old('name') }}" required autocomplete="off" autofocus data-part-name-input>
                <div class="suggestions" data-part-suggestions hidden></div>
            </div>

            <div>
                <label for="part-condition">Статус</label>
                <select id="part-condition" name="damage_note" required>
                    @foreach($damageOptions as $damageValue => $damageLabel)
                        <option value="{{ $damageValue }}" @selected(old('damage_note', "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}") === (string) $damageValue)>{{ $damageLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="part-color">Цвет</label>
                <input id="part-color" name="color" value="{{ old('color', $donorCar->color) }}" autocomplete="off">
            </div>

            <div>
                <label for="part-photos">Фото</label>
                <div class="photo-picker">
                    <input id="part-photos" type="file" name="photos[]" accept="image/*" multiple data-photo-field hidden>
                    <input id="part-camera" type="file" accept="image/*" capture="environment" data-photo-source hidden>
                    <input id="part-gallery" type="file" accept="image/*" multiple data-photo-source hidden>
                    <div class="photo-actions">
                        <button type="button" class="btn-block" data-open-camera>Сделать фото</button>
                        <button type="button" class="btn-secondary btn-block" data-open-gallery>Галерея</button>
                    </div>
                    <div class="help">Можно добавить до 5 фото. Нажимайте “Сделать фото” несколько раз, чтобы снять разные ракурсы.</div>
                    <div class="photo-preview-grid" data-photo-preview hidden></div>
                </div>
            </div>

            <div>
                <label for="part-price">Цена продажи, USD</label>
                <input id="part-price" type="number" step="0.01" min="0" name="selling_price" value="{{ old('selling_price') }}" inputmode="decimal">
            </div>

            <div>
                <label for="part-external-sku">Артикул</label>
                <input id="part-external-sku" name="external_sku" value="{{ old('external_sku') }}" autocomplete="off">
            </div>

            <div>
                <label for="part-description">Описание</label>
                <textarea id="part-description" name="description">{{ old('description') }}</textarea>
            </div>
        </section>

        <section class="panel form-grid">
            <div>
                <label for="warehouse-id">Склад</label>
                <select id="warehouse-id" name="warehouse_id" required data-warehouse-select data-selected-warehouse="{{ old('warehouse_id') }}">
                    <option value="">Выберите склад</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" data-floor-count="{{ $warehouse->floor_count }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>

            <div data-floor-wrap>
                <label for="part-floor">Этаж</label>
                <select id="part-floor" name="floor" data-floor-select data-selected-floor="{{ old('floor') }}">
                    @foreach(\App\Models\Location::floorsForCount(20) as $value => $label)
                        <option value="{{ $value }}" @selected(old('floor') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="location-cell">Ячейка</label>
                <input id="location-cell" name="location_cell" value="{{ old('location_cell') }}" placeholder="Например A-12" autocomplete="off">
            </div>

            <div>
                <label for="part-notes">Комментарий</label>
                <textarea id="part-notes" name="notes" placeholder="Нюансы, дефекты, что проверить">{{ old('notes') }}</textarea>
            </div>
        </section>

        <div class="sticky-actions">
            <button type="submit" class="btn-block">Сохранить запчасть</button>
        </div>
    </form>

    <section class="panel">
        <a class="btn btn-secondary btn-block" href="{{ route('admin.mobile.parts.index') }}">Выбрать другого донора</a>
    </section>

    <script>
        (() => {
            const photoField = document.querySelector('[data-photo-field]');
            const photoSources = document.querySelectorAll('[data-photo-source]');
            const openCameraButton = document.querySelector('[data-open-camera]');
            const openGalleryButton = document.querySelector('[data-open-gallery]');
            const cameraInput = document.querySelector('#part-camera');
            const galleryInput = document.querySelector('#part-gallery');
            const preview = document.querySelector('[data-photo-preview]');
            const warehouseSelect = document.querySelector('[data-warehouse-select]');
            const floorWrap = document.querySelector('[data-floor-wrap]');
            const floorSelect = document.querySelector('[data-floor-select]');
            const searchRoot = document.querySelector('[data-part-search-url]');
            const nameInput = searchRoot?.querySelector('[data-part-name-input]');
            const suggestions = searchRoot?.querySelector('[data-part-suggestions]');
            const savedWarehouseKey = 'mobilePartWarehouseId';
            let searchTimeout = null;
            let searchController = null;

            const hideSuggestions = () => {
                if (!suggestions) return;
                suggestions.hidden = true;
                suggestions.innerHTML = '';
            };

            const setField = (name, value, overwrite = false) => {
                if (value === null || value === undefined || value === '') return;
                const field = document.querySelector(`[name="${name}"]`);
                if (!field || (!overwrite && field.value)) return;
                field.value = value;
                field.dispatchEvent(new Event('change', { bubbles: true }));
            };

            const renderSuggestions = (items) => {
                if (!suggestions) return;

                suggestions.innerHTML = '';

                if (!items.length) {
                    const empty = document.createElement('div');
                    empty.className = 'suggestion-empty';
                    empty.textContent = 'Ничего не найдено';
                    suggestions.appendChild(empty);
                    suggestions.hidden = false;
                    return;
                }

                const selectSuggestion = (item, event) => {
                    event?.preventDefault();
                    event?.stopPropagation();

                    const selectedName = String(item.name || '').trim();

                    if (selectedName !== '') {
                        nameInput.value = selectedName;
                        nameInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    setField('external_sku', item.external_sku, true);
                    setField('damage_note', item.notes, true);
                    setField('color', item.color, true);
                    setField('description', item.description, true);
                    setField('selling_price', item.selling_price, true);
                    setField('notes', item.notes);
                    hideSuggestions();
                };

                items.forEach((item) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'suggestion';
                    button.innerHTML = `
                        <span class="suggestion__title"></span>
                        <span class="suggestion__meta"></span>
                    `;
                    button.querySelector('.suggestion__title').textContent = item.name;
                    button.querySelector('.suggestion__meta').textContent = item.meta || (item.type === 'donor' ? 'Уже был у донора' : 'Каталог');
                    button.addEventListener('pointerdown', (event) => selectSuggestion(item, event));
                    button.addEventListener('touchstart', (event) => selectSuggestion(item, event), { passive: false });
                    button.addEventListener('click', (event) => selectSuggestion(item, event));
                    suggestions.appendChild(button);
                });

                suggestions.hidden = false;
            };

            nameInput?.addEventListener('input', () => {
                const query = nameInput.value.trim();
                clearTimeout(searchTimeout);

                if (query.length < 2) {
                    hideSuggestions();
                    return;
                }

                searchTimeout = setTimeout(async () => {
                    if (searchController) searchController.abort();
                    searchController = new AbortController();

                    try {
                        const response = await fetch(`${searchRoot.dataset.partSearchUrl}?q=${encodeURIComponent(query)}`, {
                            headers: { Accept: 'application/json' },
                            signal: searchController.signal,
                        });

                        if (!response.ok) return;
                        renderSuggestions(await response.json());
                    } catch (error) {
                        if (error.name !== 'AbortError') hideSuggestions();
                    }
                }, 220);
            });

            document.addEventListener('click', (event) => {
                if (searchRoot && !searchRoot.contains(event.target)) hideSuggestions();
            });

            nameInput?.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') hideSuggestions();
            });

            const selectedPhotos = new DataTransfer();

            const syncPhotoField = () => {
                if (photoField) {
                    photoField.files = selectedPhotos.files;
                }
            };

            const removePhoto = (index) => {
                const nextPhotos = new DataTransfer();

                Array.from(selectedPhotos.files).forEach((file, fileIndex) => {
                    if (fileIndex !== index) {
                        nextPhotos.items.add(file);
                    }
                });

                selectedPhotos.items.clear();
                Array.from(nextPhotos.files).forEach((file) => selectedPhotos.items.add(file));
                syncPhotoField();
                renderPhotoPreview();
            };

            const renderPhotoPreview = () => {
                if (!preview) return;

                preview.innerHTML = '';
                Array.from(selectedPhotos.files).forEach((file, index) => {
                    const item = document.createElement('div');
                    const image = document.createElement('img');
                    const removeButton = document.createElement('button');

                    item.className = 'photo-preview';
                    image.src = URL.createObjectURL(file);
                    image.alt = file.name;
                    image.onload = () => URL.revokeObjectURL(image.src);
                    removeButton.type = 'button';
                    removeButton.textContent = '×';
                    removeButton.setAttribute('aria-label', 'Удалить фото');
                    removeButton.addEventListener('click', () => removePhoto(index));

                    item.appendChild(image);
                    item.appendChild(removeButton);
                    preview.appendChild(item);
                });

                preview.hidden = selectedPhotos.files.length === 0;
            };

            const addPhotos = (files) => {
                const imageFiles = Array.from(files || []).filter((file) => file.type.startsWith('image/'));
                const slotsLeft = 5 - selectedPhotos.files.length;

                if (imageFiles.length === 0) {
                    return;
                }

                if (slotsLeft <= 0) {
                    alert('Можно добавить не больше 5 фото.');
                    return;
                }

                imageFiles.slice(0, slotsLeft).forEach((file) => selectedPhotos.items.add(file));

                if (imageFiles.length > slotsLeft) {
                    alert('Добавлено только 5 фото. Лишние фото не прикреплены.');
                }

                syncPhotoField();
                renderPhotoPreview();
            };

            openCameraButton?.addEventListener('click', () => cameraInput?.click());
            openGalleryButton?.addEventListener('click', () => galleryInput?.click());
            photoSources.forEach((input) => {
                input.addEventListener('change', () => {
                    addPhotos(input.files);
                    input.value = '';
                });
            });

            const syncFloors = () => {
                if (!warehouseSelect || !floorWrap || !floorSelect) return;

                const selectedOption = warehouseSelect.options[warehouseSelect.selectedIndex];
                const floorCount = Math.max(1, Number(selectedOption?.dataset.floorCount || 1));
                const selectedFloor = floorSelect.dataset.selectedFloor || 'floor_1';

                floorWrap.hidden = floorCount === 1;
                floorSelect.disabled = floorCount === 1;

                Array.from(floorSelect.options).forEach((option) => {
                    const floorNumber = Number(option.value.replace('floor_', ''));
                    option.hidden = floorNumber > floorCount;
                    option.disabled = floorNumber > floorCount;
                });

                if (floorCount === 1 || Number(floorSelect.value.replace('floor_', '')) > floorCount) {
                    floorSelect.value = floorCount >= Number(selectedFloor.replace('floor_', '')) ? selectedFloor : 'floor_1';
                }
            };

            const restoreSavedWarehouse = () => {
                if (!warehouseSelect || warehouseSelect.value) return;

                const savedWarehouseId = localStorage.getItem(savedWarehouseKey);

                const hasSavedWarehouse = Array.from(warehouseSelect.options)
                    .some((option) => option.value === savedWarehouseId);

                if (savedWarehouseId && hasSavedWarehouse) {
                    warehouseSelect.value = savedWarehouseId;
                }
            };

            warehouseSelect?.addEventListener('change', () => {
                if (warehouseSelect.value) {
                    localStorage.setItem(savedWarehouseKey, warehouseSelect.value);
                } else {
                    localStorage.removeItem(savedWarehouseKey);
                }

                floorSelect.dataset.selectedFloor = '';
                syncFloors();
            });

            restoreSavedWarehouse();
            syncFloors();
        })();
    </script>
@endsection
