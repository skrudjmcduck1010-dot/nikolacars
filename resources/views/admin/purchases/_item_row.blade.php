<div class="purchase-item" data-purchase-item>
    <div class="actions" style="justify-content:space-between;margin-bottom:12px;">
        <strong>Позиция</strong>
        <button type="button" class="btn btn-small btn-secondary" data-remove-purchase-item>Убрать</button>
    </div>

    <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $item['product_id'] ?? '' }}" data-product-id>

    <div class="purchase-item-grid">
        <div style="position:relative;">
            <label>Название</label>
            <input name="items[{{ $index }}][name]" value="{{ $item['name'] ?? '' }}" autocomplete="off" data-product-name required>
            <div class="purchase-suggestions" data-product-name-suggestions hidden></div>
            @error("items.{$index}.name")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div style="position:relative;">
            <label>Артикул</label>
            <input name="items[{{ $index }}][external_sku]" value="{{ $item['external_sku'] ?? '' }}" data-product-external-sku required>
            <div class="purchase-suggestions" data-product-sku-suggestions hidden></div>
            @error("items.{$index}.external_sku")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div>
            <label>Состояние</label>
            <select name="items[{{ $index }}][condition_type]" data-condition-type-select required>
                @foreach ($conditionTypes as $value => $label)
                    <option value="{{ $value }}" @selected(($item['condition_type'] ?? 'used') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error("items.{$index}.condition_type")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div>
            <label>Модель</label>
            @php($selectedModel = $item['model'] ?? null)
            <select name="items[{{ $index }}][model]" data-model-select required>
                <option value="">—</option>
                @foreach ($teslaModels as $model)
                    <option value="{{ $model }}" @selected($selectedModel === $model)>{{ $model }}</option>
                @endforeach
                @if($selectedModel && ! in_array($selectedModel, $teslaModels, true))
                    <option value="{{ $selectedModel }}" selected>{{ $selectedModel }}</option>
                @endif
            </select>
        </div>
        <div>
            <label>Цена закупки $</label>
            <input type="number" step="0.01" min="0.01" name="items[{{ $index }}][purchase_price_usd]" value="{{ $item['purchase_price_usd'] ?? '' }}" data-purchase-price-usd required>
            @error("items.{$index}.purchase_price_usd")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div>
            <label>Цвет</label>
            <input name="items[{{ $index }}][color]" value="{{ $item['color'] ?? '' }}" list="purchase-color-suggestions-{{ $index }}" autocomplete="off" data-product-color>
            <datalist id="purchase-color-suggestions-{{ $index }}" data-color-suggestions>
                @foreach ($colorOptions as $color)
                    <option value="{{ $color }}"></option>
                @endforeach
            </datalist>
            @error("items.{$index}.color")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div style="grid-column:1;">
            <label>Цена продажи $</label>
            <input type="number" step="0.01" min="0" name="items[{{ $index }}][selling_price_usd]" value="{{ $item['selling_price_usd'] ?? '' }}" required>
        </div>
        <div style="grid-column:1;">
            <label>Склад</label>
            <select name="items[{{ $index }}][warehouse_id]" data-warehouse-select required>
                <option value="">Выбрать</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(($item['warehouse_id'] ?? null) == $warehouse->id)>{{ $warehouse->name }}</option>
                @endforeach
            </select>
            @error("items.{$index}.warehouse_id")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div data-floor-wrap hidden style="grid-column:2;">
            <label>Этаж</label>
            <select data-floor-select>
                <option value="">Этаж</option>
            </select>
        </div>
        <div data-location-wrap style="grid-column:3;">
            <label>Ячейка</label>
            <select name="items[{{ $index }}][location_id]" data-location-select data-selected-location="{{ $item['location_id'] ?? '' }}" required>
                <option value="">Выбрать</option>
            </select>
            @error("items.{$index}.location_id")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div style="grid-column:1;">
            <label>Кол-во</label>
            <input type="number" min="1" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? 1 }}" required>
        </div>
        <div style="grid-column:1;">
            <label>Фото</label>
            <input type="file" name="items[{{ $index }}][photos][]" accept="image/*" multiple>
            <div class="help">До 5 фото</div>
            @error("items.{$index}.photos")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="full">
            <label>Описание</label>
            <textarea class="purchase-comment-field" name="items[{{ $index }}][description]">{{ $item['description'] ?? '' }}</textarea>
        </div>
        <div class="full">
            <label>Комментарий</label>
            <textarea class="purchase-comment-field" name="items[{{ $index }}][comment]">{{ $item['comment'] ?? '' }}</textarea>
        </div>
    </div>
</div>
