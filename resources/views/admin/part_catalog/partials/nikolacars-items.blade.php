@php
    $nikolaCarsPriceSortLabel = $nikolaCarsSort === 'price'
        ? 'Цена продажи '.($nikolaCarsSortDirection === 'asc' ? '&#8593;' : '&#8595;')
        : 'Цена продажи';
    $nikolaCarsColorSwatchHex = function (?string $color): ?string {
        $normalized = mb_strtolower(trim((string) $color));

        if ($normalized === '') {
            return null;
        }

        return match (true) {
            preg_match('/\b(white|pearl)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0431}\u{0435}\u{043B}") || str_contains($normalized, "\u{0431}\u{0456}\u{043B}") => '#f8fafc',
            preg_match('/\b(black|obsidian)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0447}\u{0435}\u{0440}\u{043D}") || str_contains($normalized, "\u{0447}\u{043E}\u{0440}\u{043D}") => '#111827',
            preg_match('/\b(red|ultra)\b/u', $normalized) === 1 || str_contains($normalized, "\u{043A}\u{0440}\u{0430}\u{0441}\u{043D}") || str_contains($normalized, "\u{0447}\u{0435}\u{0440}\u{0432}\u{043E}\u{043D}") => '#dc2626',
            preg_match('/\b(blue|navy)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0441}\u{0438}\u{043D}") => '#2563eb',
            preg_match('/\b(grey|gray|silver|quicksilver|midnight)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0441}\u{0435}\u{0440}") || str_contains($normalized, "\u{0441}\u{0440}\u{0456}\u{0431}") => '#94a3b8',
            preg_match('/\b(green)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0437}\u{0435}\u{043B}\u{0435}\u{043D}") || str_contains($normalized, "\u{0437}\u{0435}\u{043B}") => '#16a34a',
            preg_match('/\b(yellow|gold)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0436}\u{0435}\u{043B}\u{0442}") || str_contains($normalized, "\u{0436}\u{043E}\u{0432}\u{0442}") || str_contains($normalized, "\u{0437}\u{043E}\u{043B}\u{043E}\u{0442}") => '#facc15',
            preg_match('/\b(brown|bronze)\b/u', $normalized) === 1 || str_contains($normalized, "\u{043A}\u{043E}\u{0440}\u{0438}\u{0447}") || str_contains($normalized, "\u{0431}\u{0440}\u{043E}\u{043D}\u{0437}") => '#92400e',
            default => null,
        };
    };
    $nikolaCarsColorSwatchStyle = fn (?string $hex): string => 'display:inline-block;width:16px;height:16px;margin:0 2px;vertical-align:middle;border:1px solid rgba(17,24,39,.22);border-radius:999px;background-color: '.($hex ?: '#e5e7eb').';box-shadow:inset 0 0 0 1px rgba(255,255,255,.62);';
    $nikolaCarsUsesDonorColorCategory = fn (iterable $categories): bool => collect($categories)
        ->contains(function (string $category): bool {
            $normalizedCategory = \Illuminate\Support\Str::lower($category);

            return \Illuminate\Support\Str::contains($normalizedCategory, ["\u{043A}\u{0443}\u{0437}\u{043E}\u{0432}", 'body'])
                || (
                    \Illuminate\Support\Str::contains($normalizedCategory, ["\u{0434}\u{0430}\u{0442}\u{0447}\u{0438}\u{043A}", 'sensor'])
                    && \Illuminate\Support\Str::contains($normalizedCategory, ["\u{043F}\u{0430}\u{0440}\u{043A}\u{043E}\u{0432}", 'parking'])
                );
        });
    $nikolaCarsUsesDonorColorItem = function (\App\Models\PartCatalogItem $item) use ($nikolaCarsUsesDonorColorCategory): bool {
        return $nikolaCarsUsesDonorColorCategory([
            app(\App\Services\NikolaCarsInventoryService::class)->displayCategory($item),
            (string) data_get($item->raw_attributes, 'category_display', ''),
            (string) data_get($item->raw_attributes, 'category_path', ''),
            (string) $item->main_category_name,
        ]);
    };
@endphp
<table class="nikolacars-parts-table" style="margin-top:12px;">
    <thead>
    <tr>
        <th>Фото</th>
        <th>Код</th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('part_number') }}">Артикул</a></th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('name') }}">Название</a></th>
        <th>Цвет</th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('vin') }}">Донор</a></th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('category') }}">Категория</a></th>
        <th>Статус</th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('price') }}">{!! $nikolaCarsPriceSortLabel !!}</a></th>
        <th>Купить</th>
        <th>Склад</th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('stock') }}">Остаток</a></th>
        <th></th>
    </tr>
    </thead>
    <tbody data-nikolacars-items-body>
    @forelse($nikolaCarsItemGroups as $group)
        @include('admin.part_catalog.partials.nikolacars-item-row')
    @empty
        <tr data-nikolacars-empty-row>
            <td colspan="13" class="empty">{{ $showNikolaCarsSoldItems ? 'Проданные запчасти не найдены.' : 'Запчасти не найдены.' }}</td>
        </tr>
    @endforelse
    </tbody>
</table>
@include('admin.part_catalog.partials.nikolacars-create-dialog')
@include('admin.part_catalog.partials.nikolacars-cart')
<dialog class="nikolacars-placement-editor" data-nikolacars-placement-editor>
    <form method="POST" action="#" class="nikolacars-placement-editor__form" data-nikolacars-placement-form>
        @csrf
        @method('PATCH')
        <div class="nikolacars-placement-editor__header">
            <h2>Редактировать склад</h2>
            <button type="button" class="nikolacars-placement-editor__close" data-nikolacars-placement-edit-cancel aria-label="Закрыть">&times;</button>
        </div>
        <label>
            <span>Склад</span>
            <select name="warehouse_id" data-nikolacars-placement-warehouse>
                @foreach($nikolaCarsPlacementWarehouseOptions as $warehouseOption)
                    <option
                        value="{{ $warehouseOption['id'] }}"
                        data-warehouse-type="{{ $warehouseOption['type'] }}"
                        data-floor-count="{{ $warehouseOption['floor_count'] }}"
                        data-structured-locations="{{ $warehouseOption['uses_structured_locations'] ? '1' : '0' }}"
                    >{{ $warehouseOption['name'] }}</option>
                @endforeach
            </select>
        </label>
        <label data-nikolacars-placement-floor-wrap>
            <span>Этаж</span>
            <select name="floor" data-nikolacars-placement-floor>
                @foreach(\App\Models\Location::floorsForCount(20) as $floorValue => $floorLabel)
                    <option value="{{ $floorValue }}">{{ $floorLabel }}</option>
                @endforeach
            </select>
        </label>
        <label data-nikolacars-placement-location-wrap>
            <span>Ячейка</span>
            <select name="location_id" data-nikolacars-placement-location>
                <option value="">—</option>
                @foreach($nikolaCarsPlacementLocationOptions as $locationOption)
                    <option
                        value="{{ $locationOption['id'] }}"
                        data-warehouse-id="{{ $locationOption['warehouse_id'] }}"
                        data-floor="{{ $locationOption['floor'] }}"
                        data-has-cell="{{ $locationOption['has_cell'] ? '1' : '0' }}"
                    >{{ $locationOption['floor_label'] }} · {{ $locationOption['label'] }}</option>
                @endforeach
            </select>
        </label>
        <div class="nikolacars-placement-editor__actions">
            <button type="button" class="btn btn-small btn-secondary" data-nikolacars-placement-edit-cancel>Отмена</button>
            <button type="submit" class="btn btn-small">Сохранить</button>
        </div>
    </form>
</dialog>
<dialog class="catalog-photo-lightbox" data-catalog-photo-lightbox>
    <div class="catalog-photo-lightbox__toolbar">
        <span data-catalog-photo-counter></span>
        <button type="button" class="btn btn-secondary catalog-photo-lightbox__close" data-close-catalog-photo-lightbox aria-label="Закрыть">&times;</button>
    </div>
    <div class="catalog-photo-lightbox__stage">
        <button type="button" class="btn btn-secondary catalog-photo-lightbox__nav catalog-photo-lightbox__nav--prev" data-catalog-photo-prev aria-label="Предыдущее фото">&#8249;</button>
        <img src="" alt="" data-catalog-photo-lightbox-image>
        <button type="button" class="btn btn-secondary catalog-photo-lightbox__nav catalog-photo-lightbox__nav--next" data-catalog-photo-next aria-label="Следующее фото">&#8250;</button>
    </div>
</dialog>
