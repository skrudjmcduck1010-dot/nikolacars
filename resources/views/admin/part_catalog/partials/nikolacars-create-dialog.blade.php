<dialog
    class="nikolacars-part-dialog"
    data-nikolacars-part-dialog
    data-open-on-error="{{ $errors->any() && old('create_nikolacars_part') ? '1' : '0' }}"
>
    <form method="POST" action="{{ route('admin.zapchasti.store') }}" class="nikolacars-part-dialog__form">
        @csrf
        <input type="hidden" name="create_nikolacars_part" value="1">
        <div class="nikolacars-part-dialog__header">
            <h2>Добавить запчасть</h2>
            <button type="button" class="btn btn-secondary nikolacars-part-dialog__close" data-close-nikolacars-part-dialog aria-label="Закрыть">&times;</button>
        </div>

        @if($errors->any() && old('create_nikolacars_part'))
            <div class="nikolacars-part-dialog__errors">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div class="nikolacars-part-form-grid">
            <div class="nikolacars-donor-picker" data-nikolacars-donor-picker>
                <label>Источник</label>
                <input type="hidden" name="source_type" value="{{ old('source_type', old('donor_car_id') ? 'donor' : 'purchase') }}" data-nikolacars-source-type-input>
                <input type="hidden" name="donor_car_id" value="{{ old('donor_car_id') }}" data-nikolacars-donor-input>
                <button type="button" class="nikolacars-donor-picker__button" data-nikolacars-donor-toggle>
                    <span class="nikolacars-donor-picker__selected" data-nikolacars-donor-selected>
                        <span class="nikolacars-donor-picker__selected-placeholder">NC</span>
                        <span class="nikolacars-donor-picker__selected-text">
                            <strong>Закупка</strong>
                        </span>
                    </span>
                </button>
                <div class="nikolacars-donor-picker__menu" data-nikolacars-donor-menu hidden>
                    <button type="button" class="nikolacars-donor-option" data-source-type="purchase" data-donor-id="" data-donor-label="Закупка" data-donor-preview-url="" data-donor-meta="Товар получен с закупок для продажи">
                        <span class="nikolacars-donor-option__placeholder">NC</span>
                        <span>
                            <strong>Закупка</strong>
                            <small>Товар получен с закупок для продажи</small>
                        </span>
                    </button>
                    @foreach($nikolaCarsCreateDonors as $donorOption)
                        <button
                            type="button"
                            class="nikolacars-donor-option"
                            data-source-type="donor"
                            data-donor-id="{{ $donorOption['id'] }}"
                            data-donor-label="{{ $donorOption['label'] }}"
                            data-donor-preview-url="{{ $donorOption['preview_url'] }}"
                            data-donor-meta="{{ $donorOption['meta'] }}"
                        >
                            @if($donorOption['preview_url'])
                                <img src="{{ $donorOption['preview_url'] }}" alt="{{ $donorOption['label'] }}" loading="lazy" decoding="async">
                            @else
                                <span class="nikolacars-donor-option__placeholder">Донор</span>
                            @endif
                            <span>
                                <strong>{{ $donorOption['label'] }}</strong>
                                @if($donorOption['meta'] !== '')
                                    <small>{{ $donorOption['meta'] }}</small>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>
            <div
                class="nikolacars-part-name-autocomplete full"
                data-nikolacars-part-name-search-url="{{ route('admin.zapchasti.items.name-suggestions') }}"
            >
                <label>Артикул</label>
                <input name="part_number" value="{{ old('part_number') }}" required autocomplete="off" data-nikolacars-part-name-input>
                <div class="nikolacars-part-name-suggestions" data-nikolacars-part-name-suggestions hidden></div>
            </div>
            <div
                class="nikolacars-part-name-autocomplete"
                data-nikolacars-part-name-search-url="{{ route('admin.zapchasti.items.name-suggestions') }}"
            >
                <label>Название запчасти УКР</label>
                <input name="name_ua" value="{{ old('name_ua', old('name')) }}" required autocomplete="off" data-nikolacars-part-name-input>
                <div class="nikolacars-part-name-suggestions" data-nikolacars-part-name-suggestions hidden></div>
            </div>
            <div>
                <label>Название запчасти РУ</label>
                <input name="name_ru" value="{{ old('name_ru') }}" autocomplete="off">
            </div>
            <div>
                <label>Повреждение</label>
                <select name="damage_note" required>
                    <option value="Без повреждений" @selected(old('damage_note', 'Без повреждений') === 'Без повреждений')>Без повреждений</option>
                    <option value="Легкие повреждения" @selected(old('damage_note') === 'Легкие повреждения')>Легкие повреждения</option>
                    <option value="Сильные повреждения" @selected(old('damage_note') === 'Сильные повреждения')>Сильные повреждения</option>
                </select>
            </div>
            <div>
                <label>Состояние</label>
                <select name="condition_type" required>
                    @foreach(\App\Models\Product::CONDITION_TYPE_LABELS as $conditionValue => $conditionLabel)
                        <option value="{{ $conditionValue }}" @selected(old('condition_type', 'used') === $conditionValue)>{{ $conditionLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Цена продажи (USD)</label>
                <input type="number" step="0.01" min="0" name="selling_price" value="{{ old('selling_price') }}">
            </div>
            <div data-nikolacars-purchase-price-wrap>
                <label>Цена закупки USD</label>
                <input type="number" step="0.01" min="0" name="purchase_price_usd" value="{{ old('purchase_price_usd') }}" data-nikolacars-purchase-price-input>
            </div>
            <div>
                <label>Склад</label>
                <select name="warehouse_id" required data-nikolacars-part-warehouse>
                    <option value="">—</option>
                    @foreach($nikolaCarsCreateWarehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" data-floor-count="{{ $warehouse->floor_count }}" data-warehouse-type="{{ $warehouse->type }}" data-structured-locations="{{ $warehouse->usesStructuredLocations() ? '1' : '0' }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
            <div data-nikolacars-part-floor-wrap>
                <label>Этаж</label>
                <select name="floor" data-nikolacars-part-floor data-selected-floor="{{ old('floor') }}">
                    @foreach(\App\Models\Location::floorsForCount(20) as $value => $label)
                        <option value="{{ $value }}" @selected(old('floor') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div data-nikolacars-part-cell-wrap>
                <label>Ячейка</label>
                <input name="location_cell" value="{{ old('location_cell') }}" data-nikolacars-part-cell>
            </div>
            <div class="full">
                <label>Описание</label>
                <textarea name="description">{{ old('description') }}</textarea>
            </div>
        </div>

        <div class="nikolacars-part-dialog__actions">
            <button type="submit" class="btn btn-small">Добавить</button>
            <button type="button" class="btn btn-small btn-secondary" data-close-nikolacars-part-dialog>Отмена</button>
        </div>
    </form>
</dialog>
