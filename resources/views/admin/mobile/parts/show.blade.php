@extends('layouts.mobile', [
    'heading' => $donorCar->display_vin,
    'subheading' => collect([$donorCar->brand, $donorCar->display_model, $donorCar->year])->filter()->join(' '),
    'desktopUrl' => route('admin.donor-cars.show', $donorCar),
])

@section('content')
    @php
        $donorPreview = collect($donorCar->photos ?? [])->first();
        $rawProducts = $donorCar->products;
        $productPaginator = $productPaginator ?? null;
        $mobilePartsSearch = $mobilePartsSearch ?? '';
        $activeStatus = $activeStatus ?? 'all';
        $sales = $donorCar->partSales;
        $soldCount = $soldPartsCount ?? $sales->count();
        $donorPartPresenter = app(\App\View\Admin\DonorCars\DonorPartDisplayPresenter::class);
        $photoUrl = fn (?string $path): ?string => \App\Support\PublicStorageUrl::url($path);
        $money = fn ($amount, ?string $currency = 'USD'): string => $donorPartPresenter->money($amount, $currency);
        $quantity = fn ($value): string => $donorPartPresenter->quantity($value);
        $damageNote = fn ($product): string => $donorPartPresenter->damageNote($product);
        $productStatus = fn ($product): array => $donorPartPresenter->mobileProductStatus($product);
        $soldProductIds = $sales
            ->flatMap(fn ($sale): array => $donorPartPresenter->saleProductIdCandidates($sale))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $soldCatalogItemIds = $sales
            ->pluck('part_catalog_item_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $isInactiveProduct = fn ($product): bool => in_array($product->storage_status, [
            \App\Models\Product::STORAGE_STATUS_SOLD,
            \App\Models\Product::STORAGE_STATUS_WRITTEN_OFF,
        ], true);
        $isSoldPartSaleProduct = fn ($product): bool => $soldProductIds->contains((int) $product->id)
            || ($product->source_part_catalog_item_id !== null
                && $soldCatalogItemIds->contains((int) $product->source_part_catalog_item_id));
        $smallPartNumbers = ($smallPartNumbers ?? collect())
            ->map(fn ($partNumber) => \App\Support\PartNumberNormalizer::normalize((string) $partNumber))
            ->filter()
            ->unique()
            ->values();
        $isSmallTeslaVinPart = fn ($product): bool => (bool) data_get($product->sourcePartCatalogItem?->raw_attributes, 'donor_vin_small_part', false)
            || $smallPartNumbers->contains(\App\Support\PartNumberNormalizer::normalize($product->external_sku ?: $product->sourcePartCatalogItem?->part_number));
        $products = $rawProducts
            ->reject($isInactiveProduct)
            ->reject($isSoldPartSaleProduct)
            ->reject($isSmallTeslaVinPart)
            ->values();
        $allCount = $allProductsCount ?? $products->count();
        $productStatuses = $products->mapWithKeys(fn ($product): array => [$product->id => $productStatus($product)]);
        $checkedCount = $checkedProductsCount ?? $productStatuses->filter(fn (array $status): bool => $status['key'] === 'checked')->count();
        $brokenCount = $brokenProductsCount ?? $productStatuses->filter(fn (array $status): bool => $status['key'] === 'broken')->count();
        $productName = fn ($product): string => trim((string) ($product->sourcePartCatalogItem?->name_ua ?: $product->sourcePartCatalogItem?->name_ru ?: $product->name));
        $readableCategoryPath = fn (?string $value, bool $stripNumericPrefixes = false): string => $donorPartPresenter->readableCategoryPath($value, $stripNumericPrefixes, true);
        $productCategoryMirrorItem = fn ($product) => ($nikolaCarsProductItemsByProductId ?? collect())->get((int) $product->id);
        $productCategoryOption = function ($product) use ($donorPartPresenter, $donorCar, $productCategoryMirrorItem): array {
            $mirrorItem = $productCategoryMirrorItem($product);

            if (! $mirrorItem) {
                return $donorPartPresenter->mobileProductCategoryOption($donorCar, $product);
            }

            $categoryPath = $donorPartPresenter->categoryPath(
                $donorPartPresenter->categoryForDonor($donorCar, $mirrorItem, false),
                'preferred',
                true,
            );
            $rawPath = $donorPartPresenter->catalogRawCategoryPath($mirrorItem, true);
            $label = collect([$categoryPath, $rawPath])
                ->map(fn (?string $path): string => trim((string) collect(preg_split('/\s*(?:\/|>|\\\\)\s*/u', (string) $path) ?: [])->filter()->first()))
                ->first(fn (string $part): bool => $part !== '') ?: '';
            $label = $donorPartPresenter->readableCategoryText($label, true, true);

            return [
                'key' => $label !== '' ? 'label:'.md5(mb_strtolower($label, 'UTF-8')) : '',
                'label' => $label,
            ];
        };
        $saleCategoryOption = fn ($sale): array => $donorPartPresenter->mobileSaleCategoryOption($donorCar, $sale);
        $productCategoryLabel = function ($product) use ($donorPartPresenter, $donorCar, $productCategoryMirrorItem): string {
            $mirrorItem = $productCategoryMirrorItem($product);

            if ($mirrorItem) {
                $categoryPath = $donorPartPresenter->categoryPath(
                    $donorPartPresenter->categoryForDonor($donorCar, $mirrorItem, false),
                    'preferred',
                    true,
                );

                if ($categoryPath !== '') {
                    return $categoryPath;
                }

                $rawPath = $donorPartPresenter->catalogRawCategoryPath($mirrorItem, true);
                if ($rawPath !== '') {
                    return $rawPath;
                }
            }

            return $donorPartPresenter->mobileProductCategoryLabel($donorCar, $product);
        };
        $saleCategoryLabel = fn ($sale): string => $donorPartPresenter->mobileSaleCategoryLabel($donorCar, $sale);
        $partCategoryOptions = \Illuminate\Support\Collection::make($products->all())
            ->map($productCategoryOption)
            ->merge(\Illuminate\Support\Collection::make($sales->all())->map($saleCategoryOption))
            ->filter(fn (array $option): bool => $option['key'] !== '' && $option['label'] !== '')
            ->unique('key')
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->pluck('label', 'key')
            ->all();
        $statusUrl = function (string $status) use ($donorCar, $mobilePartsSearch): string {
            return route('admin.mobile.donor-cars.parts.show', array_filter([
                'donorCar' => $donorCar,
                'q' => $mobilePartsSearch !== '' ? $mobilePartsSearch : null,
                'status' => $status !== 'all' ? $status : null,
            ], fn ($value): bool => $value !== null && $value !== ''));
        };
        $resetUrl = route('admin.mobile.donor-cars.parts.show', $donorCar);
        $pageProductCount = $products->count();
    @endphp

    <section class="panel">
        <div class="donor-card__top">
            <div>
                <div class="donor-card__vin">{{ $donorCar->display_vin }}</div>
                <div class="donor-card__meta">
                    {{ $donorCar->status_label }}
                    @if($donorCar->color)
                        · {{ $donorCar->color }}
                    @endif
                    @if($donorCar->mileage !== null)
                        · {{ number_format($donorCar->mileage, 0, ',', ' ') }} mi
                    @endif
                </div>
            </div>
            @if($donorPreview)
                <div class="donor-card__preview" style="grid-row:auto;width:88px;">
                    <img src="{{ $photoUrl($donorPreview) }}" alt="{{ $donorCar->display_vin }}" decoding="async">
                </div>
            @endif
        </div>

        <div class="mobile-stat-row">
            <div class="mobile-stat">
                <div class="mobile-stat__value">{{ $allCount }}</div>
                <div class="mobile-stat__label">Всего</div>
            </div>
            <div class="mobile-stat">
                <div class="mobile-stat__value">{{ $checkedCount }}</div>
                <div class="mobile-stat__label">Проверено</div>
            </div>
            <div class="mobile-stat">
                <div class="mobile-stat__value">{{ $soldCount }}</div>
                <div class="mobile-stat__label">Продано</div>
            </div>
        </div>
    </section>

    <section class="mobile-actions">
        <a class="btn btn-secondary" href="{{ route('admin.mobile.parts.index') }}">Назад</a>
        <a class="btn" href="{{ route('admin.mobile.donor-cars.products.create', $donorCar) }}">Добавить</a>
    </section>

    <section class="panel part-filter" data-mobile-parts>
        <form method="GET" action="{{ route('admin.mobile.donor-cars.parts.show', $donorCar) }}" class="search-row">
            @if($activeStatus !== 'all')
                <input type="hidden" name="status" value="{{ $activeStatus }}">
            @endif
            <input type="search" name="q" value="{{ $mobilePartsSearch }}" placeholder="Поиск по артикулу или названию" autocomplete="off" data-mobile-parts-search>
            <button type="submit">Найти</button>
        </form>
        @if($mobilePartsSearch !== '')
            <a class="help" href="{{ $resetUrl }}">Сбросить поиск</a>
        @endif
        <div class="part-filter__category" data-mobile-parts-category>
            <label id="mobile-parts-category-label">Категория</label>
            <button type="button" class="part-category-filter__toggle" aria-haspopup="true" aria-expanded="false" aria-labelledby="mobile-parts-category-label mobile-parts-category-summary" data-mobile-parts-category-toggle @disabled(empty($partCategoryOptions))>
                <span id="mobile-parts-category-summary" data-mobile-parts-category-summary>Все категории</span>
            </button>
            <div class="part-category-filter__menu" role="group" aria-labelledby="mobile-parts-category-label" data-mobile-parts-category-menu hidden>
                @foreach($partCategoryOptions as $categoryValue => $categoryLabel)
                    <label class="part-category-filter__option">
                        <input type="checkbox" value="{{ $categoryValue }}" data-category-label="{{ $categoryLabel }}" data-mobile-parts-category-option>
                        <span>{{ $categoryLabel }}</span>
                    </label>
                @endforeach
                @if(! empty($partCategoryOptions))
                    <button type="button" class="part-category-filter__reset" data-mobile-parts-category-reset>Сбросить</button>
                @endif
            </div>
        </div>
        <div class="part-filter__chips" aria-label="Фильтр запчастей">
            <a href="{{ $statusUrl('all') }}" @class(['part-filter__chip', 'is-active' => $activeStatus === 'all']) data-mobile-parts-filter="all">Все {{ $allCount }}</a>
            <a href="{{ $statusUrl('checked') }}" @class(['part-filter__chip', 'is-active' => $activeStatus === 'checked']) data-mobile-parts-filter="checked">Проверенные {{ $checkedCount }}</a>
            <a href="{{ $statusUrl('unchecked') }}" @class(['part-filter__chip', 'is-active' => $activeStatus === 'unchecked']) data-mobile-parts-filter="unchecked">Не проверены</a>
            <a href="{{ $statusUrl('broken') }}" @class(['part-filter__chip', 'is-active' => $activeStatus === 'broken']) data-mobile-parts-filter="broken">Непригодные {{ $brokenCount }}</a>
            <a href="{{ $statusUrl('sold') }}" @class(['part-filter__chip', 'is-active' => $activeStatus === 'sold']) data-mobile-parts-filter="sold">Проданные {{ $soldCount }}</a>
        </div>
        @if($productPaginator)
            <div class="help">
                @if($productPaginator->total() > 0)
                    Показано {{ $productPaginator->firstItem() }}-{{ $productPaginator->lastItem() }} из {{ $productPaginator->total() }}
                @else
                    По этому фильтру деталей нет
                @endif
            </div>
        @endif
    </section>

    <section class="part-list" data-mobile-parts-list>
        @foreach($products as $product)
            @php
                $status = $productStatuses[$product->id] ?? $productStatus($product);
                $stockItem = $product->stockItems
                    ->filter(fn ($item) => (float) $item->quantity > 0)
                    ->sortByDesc(fn ($item) => (float) $item->available_quantity)
                    ->first();
                if ($stockItem?->warehouse?->type === \App\Models\Warehouse::TYPE_DONOR) {
                    $stockItem->setRelation('location', null);
                }
                $workOrder = $product->stoWorkOrderParts->first()?->order;
                $currentDamageNote = $damageNote($product);
                $productPhotos = \App\Support\ProductPhotoNormalizer::productPhotos($product);
                $imageUrl = $photoUrl($productPhotos->first());
                $photoCount = $productPhotos->count();
                $categoryOption = $productCategoryOption($product);
                $categoryLabel = $productCategoryLabel($product);
                $categoryValue = $categoryOption['key'];
                $searchText = collect([
                    $product->sku,
                    $product->external_sku,
                    $product->name,
                    $product->sourcePartCatalogItem?->part_number,
                    $product->sourcePartCatalogItem?->name,
                    $product->sourcePartCatalogItem?->name_ru,
                    $product->sourcePartCatalogItem?->name_ua,
                    $categoryLabel,
                    $currentDamageNote,
                ])->filter()->implode(' ');
            @endphp
            <article @class([
                'part-card',
                'part-card--danger' => ($status['tone'] ?? '') === 'danger',
                'part-card--success' => ($status['tone'] ?? '') === 'success',
            ]) id="part-{{ $product->id }}" data-mobile-part-card data-part-status="{{ $status['key'] }}" data-part-category="{{ $categoryValue }}" data-part-search="{{ $searchText }}">
                <form method="POST" action="{{ route('admin.mobile.donor-cars.products.photos.store', [$donorCar, $product]) }}" enctype="multipart/form-data" class="part-card__photo-form" data-mobile-part-photo-form data-mobile-preserve-scroll>
                    @csrf
                    <input id="part-photo-{{ $product->id }}" class="part-card__photo-input" type="file" name="photo" accept="image/*" capture="environment" data-mobile-part-photo-input>
                    <label class="part-card__photo" for="part-photo-{{ $product->id }}">
                    @if($imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $productName($product) }}" loading="lazy" decoding="async">
                    @else
                        <span class="part-card__photo-empty">#</span>
                    @endif
                        <span class="part-card__photo-badge">{{ $photoCount }} фото</span>
                    </label>
                </form>
                <div class="part-card__body">
                    <div class="part-card__head">
                        <a class="part-card__title" href="{{ route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]) }}">{{ $productName($product) ?: 'Без названия' }}</a>
                        <span class="part-card__status" data-mobile-part-status-badge>
                            <span class="tag {{ $status['class'] }}">{{ $status['label'] }}</span>
                            @if(($status['key'] ?? null) === 'checked' && isset($status['origin']))
                                <span class="part-origin-badge" title="{{ $status['origin']['label'] }}" aria-label="{{ $status['origin']['label'] }}">{{ $status['origin']['letter'] }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="part-card__meta">
                        {{ collect([$product->external_sku ?: $product->sku, $categoryLabel])->filter()->join(' · ') }}
                    </div>
                    @if($currentDamageNote !== '')
                        <div class="part-card__meta" data-mobile-part-damage-note>Статус: {{ $damageNote($product) }}</div>
                    @else
                        <div class="part-card__meta" data-mobile-part-damage-note hidden></div>
                    @endif
                    <form method="POST" action="{{ route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]) }}" class="part-card__damage-form" data-mobile-part-damage-form data-mobile-preserve-scroll data-previous-damage-note="{{ $currentDamageNote }}">
                        @csrf
                        @method('PATCH')
                        <label for="part-damage-{{ $product->id }}">Статус</label>
                        <select id="part-damage-{{ $product->id }}" name="damage_note" data-mobile-part-damage-select data-mobile-preserve-on-change>
                            @foreach($damageOptions as $damageValue => $damageLabel)
                                <option value="{{ $damageValue }}" @selected($currentDamageNote === (string) $damageValue)>{{ $damageLabel }}</option>
                            @endforeach
                        </select>
                        <div class="part-card__placement" data-mobile-part-placement hidden>
                            <div>
                                <label for="part-warehouse-{{ $product->id }}">Склад</label>
                                <select id="part-warehouse-{{ $product->id }}" name="warehouse_id" data-mobile-part-warehouse-select data-mobile-preserve-on-change disabled>
                                    <option value="">Выберите склад</option>
                                    @foreach(($placementWarehouseOptions ?? []) as $warehouseOption)
                                        <option value="{{ $warehouseOption['id'] }}">{{ $warehouseOption['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div data-mobile-part-floor-wrap hidden>
                                <label for="part-floor-{{ $product->id }}">Этаж</label>
                                <select id="part-floor-{{ $product->id }}" name="floor" data-mobile-part-floor-select data-mobile-preserve-on-change disabled>
                                    <option value="">Выберите этаж</option>
                                </select>
                            </div>
                            <div data-mobile-part-location-wrap hidden>
                                <label for="part-location-{{ $product->id }}">Ячейка</label>
                                <select id="part-location-{{ $product->id }}" name="location_id" data-mobile-part-location-select data-mobile-preserve-on-change disabled>
                                    <option value="">Выберите ячейку</option>
                                </select>
                            </div>
                            <button type="submit" class="btn-block" data-mobile-part-placement-submit disabled>Сохранить</button>
                        </div>
                    </form>
                    <div class="part-card__foot">
                        <span class="tag tag-muted">{{ $money($product->selling_price, $product->currency) }}</span>
                        @if($workOrder)
                            <span class="part-card__meta" data-mobile-part-stock-label>ЗН {{ $workOrder->number }}</span>
                        @else
                            <span class="part-card__meta" data-mobile-part-stock-label>{{ collect([$stockItem?->warehouse?->name, $stockItem?->location?->cell ?: $stockItem?->location?->full_code])->filter()->join(' · ') ?: $product->storage_status_label }}</span>
                        @endif
                    </div>
                </div>
            </article>
        @endforeach

        @foreach($sales as $sale)
            @php
                $saleName = trim((string) ($sale->partCatalogItem?->name_ua ?: $sale->partCatalogItem?->name_ru ?: $sale->partCatalogItem?->name ?: $sale->name));
                $categoryOption = $saleCategoryOption($sale);
                $categoryLabel = $saleCategoryLabel($sale);
                $categoryValue = $categoryOption['key'];
                $searchText = collect([
                    $sale->code,
                    $sale->part_number,
                    $sale->name,
                    $saleName,
                    $sale->category_path,
                    $sale->document_number,
                    $sale->counterparty,
                ])->filter()->implode(' ');
            @endphp
            <article class="part-card part-card--sale" data-mobile-part-card data-part-status="sold" data-part-category="{{ $categoryValue }}" data-part-search="{{ $searchText }}" @if($activeStatus !== 'sold') hidden @endif>
                <div class="part-card__body">
                    <div class="part-card__head">
                        <div class="part-card__title">{{ $saleName ?: 'Проданная запчасть' }}</div>
                        <span class="tag tag-paid">Продана</span>
                    </div>
                    <div class="part-card__meta">
                        {{ collect([$sale->part_number ?: $sale->code, $sale->sold_at?->timezone('Europe/Kiev')->format('d.m.Y'), $sale->document_number])->filter()->join(' · ') }}
                    </div>
                    @if($categoryLabel)
                        <div class="part-card__meta">{{ $categoryLabel }}</div>
                    @endif
                    <div class="part-card__foot">
                        <span class="tag tag-muted">{{ $quantity($sale->quantity) }} шт.</span>
                        @if($sale->total_amount !== null)
                            <span class="tag tag-muted">{{ $money($sale->total_amount, $sale->currency) }}</span>
                        @endif
                        @if($sale->counterparty)
                            <span class="part-card__meta">{{ $sale->counterparty }}</span>
                        @endif
                    </div>
                </div>
            </article>
        @endforeach

        <div class="panel empty" data-mobile-parts-empty @if($pageProductCount > 0 || $sales->isNotEmpty()) hidden @endif>Запчастей по этому донору пока нет.</div>
    </section>

    <section class="panel" data-mobile-parts-pagination @if(! $productPaginator || ! $productPaginator->hasPages()) hidden @endif>
        @if($productPaginator && $productPaginator->hasMorePages())
            <button type="button" class="btn btn-block" data-mobile-parts-load-more data-next-url="{{ $productPaginator->nextPageUrl() }}">Показать ещё</button>
        @endif
        @if($productPaginator && $productPaginator->hasPages())
            {{ $productPaginator->links() }}
        @endif
    </section>

    <div class="sticky-actions">
        <a class="btn btn-block" href="{{ route('admin.mobile.donor-cars.products.create', $donorCar) }}">Добавить запчасть</a>
    </div>

    <script>
        (() => {
            const searchInput = document.querySelector('[data-mobile-parts-search]');
            const categoryRoot = document.querySelector('[data-mobile-parts-category]');
            const categoryToggle = categoryRoot?.querySelector('[data-mobile-parts-category-toggle]');
            const categoryMenu = categoryRoot?.querySelector('[data-mobile-parts-category-menu]');
            const categorySummary = categoryRoot?.querySelector('[data-mobile-parts-category-summary]');
            const categoryOptions = Array.from(categoryRoot?.querySelectorAll('[data-mobile-parts-category-option]') || []);
            const categoryReset = categoryRoot?.querySelector('[data-mobile-parts-category-reset]');
            const filterButtons = Array.from(document.querySelectorAll('[data-mobile-parts-filter]'));
            const cards = Array.from(document.querySelectorAll('[data-mobile-part-card]'));
            const empty = document.querySelector('[data-mobile-parts-empty]');
            const checkedDamageStatuses = new Set(@json($checkedDamageStatuses ?? []));
            const unknownDamageStatus = @json("\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}");
            const placementWarehouses = @json($placementWarehouseOptions ?? []);
            const placementLocations = @json($placementLocationOptions ?? []);
            const chooseFloorLabel = 'Выберите этаж';
            const chooseLocationLabel = 'Выберите ячейку';
            let activeFilter = @json($activeStatus);

            const normalize = (value) => String(value || '').toLocaleLowerCase('ru').trim();
            const isUnknownDamageStatus = (value) => {
                const normalized = String(value || '').trim();

                return normalized === '' || normalized === unknownDamageStatus;
            };
            const needsPlacement = (form) => {
                const select = form?.querySelector('[data-mobile-part-damage-select]');

                return Boolean(select)
                    && isUnknownDamageStatus(form.dataset.previousDamageNote)
                    && checkedDamageStatuses.has(select.value);
            };
            const fillSelect = (select, placeholder, options) => {
                if (! select) {
                    return;
                }

                const currentValue = select.value;
                select.replaceChildren(new Option(placeholder, ''));
                options.forEach((option) => select.add(new Option(option.label, option.value)));

                if (options.some((option) => String(option.value) === currentValue)) {
                    select.value = currentValue;
                }
            };
            const uniqueFloors = (locations) => {
                const floors = new Map();

                locations.forEach((location) => {
                    if (location.floor && ! floors.has(location.floor)) {
                        floors.set(location.floor, location.floor_label || location.floor);
                    }
                });

                return Array.from(floors, ([value, label]) => ({ value, label }));
            };
            const updatePlacementForm = (form) => {
                if (! form) {
                    return;
                }

                const shouldShow = needsPlacement(form);
                const placement = form.querySelector('[data-mobile-part-placement]');
                const warehouseSelect = form.querySelector('[data-mobile-part-warehouse-select]');
                const floorWrap = form.querySelector('[data-mobile-part-floor-wrap]');
                const floorSelect = form.querySelector('[data-mobile-part-floor-select]');
                const locationWrap = form.querySelector('[data-mobile-part-location-wrap]');
                const locationSelect = form.querySelector('[data-mobile-part-location-select]');
                const submitButton = form.querySelector('[data-mobile-part-placement-submit]');

                if (! placement || ! warehouseSelect || ! floorWrap || ! floorSelect || ! locationWrap || ! locationSelect || ! submitButton) {
                    return;
                }

                placement.hidden = ! shouldShow;
                warehouseSelect.disabled = ! shouldShow;
                warehouseSelect.required = shouldShow;

                if (! shouldShow) {
                    floorWrap.hidden = true;
                    floorSelect.disabled = true;
                    floorSelect.required = false;
                    locationWrap.hidden = true;
                    locationSelect.disabled = true;
                    locationSelect.required = false;
                    submitButton.disabled = true;
                    return;
                }

                const warehouseId = warehouseSelect.value;
                const warehouse = placementWarehouses.find((item) => String(item.id) === warehouseId);

                if (warehouse?.type === 'donor') {
                    floorWrap.hidden = true;
                    floorSelect.disabled = true;
                    floorSelect.required = false;
                    locationWrap.hidden = true;
                    locationSelect.disabled = true;
                    locationSelect.required = false;
                    submitButton.disabled = ! warehouseId;
                    return;
                }

                const warehouseLocations = placementLocations.filter((location) => String(location.warehouse_id) === warehouseId);
                const floors = Array.isArray(warehouse?.floors) && warehouse.floors.length > 0
                    ? warehouse.floors
                    : uniqueFloors(warehouseLocations);

                floorWrap.hidden = ! warehouseId || floors.length <= 1;
                floorSelect.disabled = floorWrap.hidden;
                floorSelect.required = ! floorWrap.hidden;
                fillSelect(floorSelect, chooseFloorLabel, floors);

                if (floors.length === 1) {
                    floorSelect.value = floors[0].value;
                }

                const selectedFloor = floors.length === 1 ? floors[0]?.value : floorSelect.value;
                const locationOptions = selectedFloor
                    ? warehouseLocations
                        .filter((location) => location.floor === selectedFloor)
                        .filter((location) => location.has_cell)
                        .map((location) => ({ value: location.id, label: location.label }))
                    : [];

                const needsLocation = locationOptions.length > 0;
                locationWrap.hidden = ! warehouseId || ! selectedFloor || ! needsLocation;
                locationSelect.disabled = locationWrap.hidden;
                locationSelect.required = ! locationWrap.hidden;
                fillSelect(locationSelect, chooseLocationLabel, locationOptions);
                submitButton.disabled = ! warehouseId
                    || (! floorWrap.hidden && ! floorSelect.value)
                    || (needsLocation && ! locationSelect.value);
            };
            const selectedCategories = () => categoryOptions
                .filter((option) => option.checked)
                .map((option) => option.value);
            const closeCategoryMenu = () => {
                if (! categoryMenu || ! categoryToggle) {
                    return;
                }

                categoryMenu.hidden = true;
                categoryToggle.setAttribute('aria-expanded', 'false');
            };
            const updateCategorySummary = () => {
                if (! categorySummary) {
                    return;
                }

                const selectedOptions = categoryOptions.filter((option) => option.checked);

                if (selectedOptions.length === 0) {
                    categorySummary.textContent = 'Все категории';
                    return;
                }

                categorySummary.textContent = selectedOptions.length === 1
                    ? (selectedOptions[0].dataset.categoryLabel || selectedOptions[0].closest('label')?.textContent?.trim() || 'Категория')
                    : `${selectedOptions.length} выбрано`;
            };

            const applyFilters = () => {
                const query = normalize(searchInput?.value);
                const categories = selectedCategories();
                let visible = 0;
                updateCategorySummary();

                cards.forEach((card) => {
                    const status = card.dataset.partStatus || '';
                    const cardCategory = card.dataset.partCategory || '';
                    const text = normalize(card.dataset.partSearch);
                    const matchesStatus = activeFilter === 'all'
                        ? status !== 'sold'
                        : status === activeFilter;
                    const matchesCategory = categories.length === 0 || categories.includes(cardCategory);
                    const matchesSearch = query === '' || text.includes(query);
                    const shouldShow = matchesStatus && matchesCategory && matchesSearch;

                    card.hidden = !shouldShow;
                    visible += shouldShow ? 1 : 0;
                });

                if (empty) {
                    empty.hidden = visible > 0;
                    empty.textContent = query === '' && activeFilter === 'all' && categories.length === 0
                        ? 'Запчастей по этому донору пока нет.'
                        : 'По этому фильтру запчасти не найдены.';
                }
            };

            filterButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    activeFilter = button.dataset.mobilePartsFilter || 'all';
                    filterButtons.forEach((item) => item.classList.toggle('is-active', item === button));
                    applyFilters();
                });
            });

            searchInput?.addEventListener('input', applyFilters);
            categoryOptions.forEach((option) => option.addEventListener('change', applyFilters));
            categoryToggle?.addEventListener('click', () => {
                if (! categoryMenu) {
                    return;
                }

                const shouldOpen = categoryMenu.hidden;
                categoryMenu.hidden = !shouldOpen;
                categoryToggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            });
            categoryReset?.addEventListener('click', () => {
                categoryOptions.forEach((option) => {
                    option.checked = false;
                });
                applyFilters();
            });
            document.addEventListener('click', (event) => {
                if (! categoryRoot?.contains(event.target)) {
                    closeCategoryMenu();
                }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeCategoryMenu();
                }
            });
            document.addEventListener('change', (event) => {
                const photoInput = event.target.closest('[data-mobile-part-photo-input]');

                if (photoInput?.form) {
                    if (photoInput.files?.length > 0) {
                        window.MobileScrollPreserver?.remember(photoInput);
                        photoInput.form.submit();
                    }

                    return;
                }

                const warehouseSelect = event.target.closest('[data-mobile-part-warehouse-select]');

                if (warehouseSelect?.form) {
                    const floorSelect = warehouseSelect.form.querySelector('[data-mobile-part-floor-select]');
                    const locationSelect = warehouseSelect.form.querySelector('[data-mobile-part-location-select]');

                    if (floorSelect) {
                        floorSelect.value = '';
                    }

                    if (locationSelect) {
                        locationSelect.value = '';
                    }

                    updatePlacementForm(warehouseSelect.form);
                    return;
                }

                const floorSelect = event.target.closest('[data-mobile-part-floor-select]');

                if (floorSelect?.form) {
                    const locationSelect = floorSelect.form.querySelector('[data-mobile-part-location-select]');

                    if (locationSelect) {
                        locationSelect.value = '';
                    }

                    updatePlacementForm(floorSelect.form);
                    return;
                }

                const locationSelect = event.target.closest('[data-mobile-part-location-select]');

                if (locationSelect?.form) {
                    updatePlacementForm(locationSelect.form);
                    return;
                }

                const select = event.target.closest('[data-mobile-part-damage-select]');

                if (! select?.form || select.value === '') {
                    return;
                }

                updatePlacementForm(select.form);

                if (needsPlacement(select.form)) {
                    return;
                }

                window.MobileScrollPreserver?.remember(select);

                if (typeof select.form.requestSubmit === 'function') {
                    select.form.requestSubmit();
                } else {
                    select.form.submit();
                }
            });
            document.querySelectorAll('[data-mobile-part-damage-form]').forEach((form) => updatePlacementForm(form));
            applyFilters();
        })();
    </script>
    <script>
        (() => {
            const baseUrl = @json(route('admin.mobile.donor-cars.parts.show', $donorCar));
            let activeStatus = @json($activeStatus);
            let searchTimer = null;
            let activeRequest = null;
            const checkedDamageStatuses = new Set(@json($checkedDamageStatuses ?? []));
            const unknownDamageStatus = @json("\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}");

            const qs = (selector, root = document) => root.querySelector(selector);
            const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));
            const targetElement = (event) => event.target instanceof Element ? event.target : null;
            const currentSearch = () => (qs('[data-mobile-parts-search]')?.value || '').trim();
            const selectedCategories = () => qsa('[data-mobile-parts-category-option]')
                .filter((option) => option.checked)
                .map((option) => option.value);
            const setLoading = (loading) => {
                qs('[data-mobile-parts-list]')?.setAttribute('aria-busy', loading ? 'true' : 'false');
                qsa('[data-mobile-parts-load-more]').forEach((button) => {
                    button.disabled = loading;
                    if (! button.dataset.originalLabel) {
                        button.dataset.originalLabel = button.textContent;
                    }
                    button.textContent = loading ? 'Загрузка...' : button.dataset.originalLabel;
                });
            };
            const buildUrl = ({ status = activeStatus, query = currentSearch(), page = null } = {}) => {
                const url = new URL(baseUrl, window.location.origin);

                if (status && status !== 'all') {
                    url.searchParams.set('status', status);
                }

                if (query) {
                    url.searchParams.set('q', query);
                }

                if (page) {
                    url.searchParams.set('page', page);
                }

                return url.toString();
            };
            const closeCategoryMenu = () => {
                const categoryRoot = qs('[data-mobile-parts-category]');
                const toggle = categoryRoot?.querySelector('[data-mobile-parts-category-toggle]');
                const menu = categoryRoot?.querySelector('[data-mobile-parts-category-menu]');

                if (! toggle || ! menu) {
                    return;
                }

                menu.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            };
            const updateCategorySummary = () => {
                const summary = qs('[data-mobile-parts-category-summary]');
                const options = qsa('[data-mobile-parts-category-option]');
                const checked = options.filter((option) => option.checked);

                if (! summary) {
                    return;
                }

                if (checked.length === 0) {
                    summary.textContent = 'Все категории';
                    return;
                }

                summary.textContent = checked.length === 1
                    ? (checked[0].dataset.categoryLabel || checked[0].closest('label')?.textContent?.trim() || 'Категория')
                    : `${checked.length} выбрано`;
            };
            const applyVisibleFilters = () => {
                const categories = selectedCategories();
                let visible = 0;

                updateCategorySummary();

                qsa('[data-mobile-part-card]').forEach((card) => {
                    const status = card.dataset.partStatus || '';
                    const category = card.dataset.partCategory || '';
                    const matchesStatus = activeStatus === 'all'
                        ? status !== 'sold'
                        : status === activeStatus;
                    const matchesCategory = categories.length === 0 || categories.includes(category);
                    const shouldShow = matchesStatus && matchesCategory;

                    card.hidden = ! shouldShow;
                    visible += shouldShow ? 1 : 0;
                });

                const empty = qs('[data-mobile-parts-empty]');

                if (empty) {
                    empty.hidden = visible > 0;
                    empty.textContent = currentSearch() === '' && activeStatus === 'all' && categories.length === 0
                        ? 'Запчастей по этому донору пока нет.'
                        : 'По этому фильтру запчасти не найдены.';
                }
            };
            const isUnknownDamageStatus = (value) => {
                const normalized = String(value || '').trim();

                return normalized === '' || normalized === unknownDamageStatus;
            };
            const damageFormNeedsPlacement = (form) => {
                const select = form?.querySelector('[data-mobile-part-damage-select]');

                return Boolean(select)
                    && isUnknownDamageStatus(form.dataset.previousDamageNote)
                    && checkedDamageStatuses.has(select.value);
            };
            const escapeHtml = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
            const renderStatusBadge = (status) => {
                const tag = `<span class="tag ${escapeHtml(status?.class || '')}">${escapeHtml(status?.label || '')}</span>`;

                if (status?.key !== 'checked' || ! status?.origin) {
                    return tag;
                }

                const originLabel = escapeHtml(status.origin.label || '');
                const originLetter = escapeHtml(status.origin.letter || '');

                return `${tag}<span class="part-origin-badge" title="${originLabel}" aria-label="${originLabel}">${originLetter}</span>`;
            };
            const setDamageFormSaving = (form, saving) => {
                form.classList.toggle('is-saving', saving);
                form.setAttribute('aria-busy', saving ? 'true' : 'false');

                qsa('input, select, button', form).forEach((control) => {
                    if (saving) {
                        control.dataset.wasDisabled = control.disabled ? 'true' : 'false';
                        control.disabled = true;
                        return;
                    }

                    if (control.dataset.wasDisabled !== undefined) {
                        control.disabled = control.dataset.wasDisabled === 'true';
                        delete control.dataset.wasDisabled;
                    }
                });
            };
            const damageFormError = (form) => {
                let error = form.querySelector('[data-mobile-part-damage-error]');

                if (! error) {
                    error = document.createElement('div');
                    error.className = 'help';
                    error.style.color = 'var(--danger)';
                    error.dataset.mobilePartDamageError = '';
                    form.appendChild(error);
                }

                return error;
            };
            const showDamageFormError = (form, message) => {
                const error = damageFormError(form);

                error.textContent = message || 'Не удалось сохранить статус.';
                error.hidden = false;
            };
            const clearDamageFormError = (form) => {
                const error = form.querySelector('[data-mobile-part-damage-error]');

                if (error) {
                    error.hidden = true;
                    error.textContent = '';
                }
            };
            const resetPlacementControls = (form) => {
                const placement = form.querySelector('[data-mobile-part-placement]');

                if (! placement) {
                    return;
                }

                placement.hidden = true;

                qsa('[data-mobile-part-warehouse-select], [data-mobile-part-floor-select], [data-mobile-part-location-select]', form).forEach((select) => {
                    select.value = '';
                    select.disabled = true;
                    select.required = false;
                });

                qsa('[data-mobile-part-floor-wrap], [data-mobile-part-location-wrap]', form).forEach((wrap) => {
                    wrap.hidden = true;
                });

                const submitButton = form.querySelector('[data-mobile-part-placement-submit]');

                if (submitButton) {
                    submitButton.disabled = true;
                }
            };
            const promoteRecentlyCheckedCard = (card, status) => {
                if (! card || activeStatus !== 'checked' || status?.key !== 'checked') {
                    return;
                }

                const list = qs('[data-mobile-parts-list]');

                if (! list) {
                    return;
                }

                card.style.order = '-2147483647';
                list.insertBefore(card, qsa('[data-mobile-part-card]', list)[0] || null);
            };
            const applyDamageStatusResult = (form, data) => {
                const card = form.closest('[data-mobile-part-card]');
                const status = data?.status || {};
                const damageNote = data?.damage_note || '';

                if (card) {
                    card.dataset.partStatus = status.key || '';
                    card.classList.toggle('part-card--danger', status.tone === 'danger');
                    card.classList.toggle('part-card--success', status.tone === 'success');
                }

                const badge = card?.querySelector('[data-mobile-part-status-badge]');

                if (badge) {
                    badge.innerHTML = renderStatusBadge(status);
                }

                const damageLine = card?.querySelector('[data-mobile-part-damage-note]');

                if (damageLine) {
                    damageLine.hidden = damageNote === '';
                    damageLine.textContent = damageNote === '' ? '' : `Статус: ${damageNote}`;
                }

                const stockLabel = card?.querySelector('[data-mobile-part-stock-label]');

                if (stockLabel && data?.stock_label) {
                    stockLabel.textContent = data.stock_label;
                }

                form.dataset.previousDamageNote = damageNote;
                resetPlacementControls(form);
                clearDamageFormError(form);
                promoteRecentlyCheckedCard(card, status);
                applyVisibleFilters();
            };
            const firstErrorMessage = (payload) => {
                const errors = payload?.errors || {};
                const firstKey = Object.keys(errors)[0];

                if (firstKey && Array.isArray(errors[firstKey]) && errors[firstKey][0]) {
                    return errors[firstKey][0];
                }

                return payload?.message || 'Не удалось сохранить статус.';
            };
            const submitDamageForm = async (form) => {
                if (form.dataset.mobilePartSaving === 'true') {
                    return;
                }

                const formData = new FormData(form);

                form.dataset.mobilePartSaving = 'true';
                setDamageFormSaving(form, true);
                clearDamageFormError(form);

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const payload = await response.json().catch(() => null);

                    if (! response.ok) {
                        showDamageFormError(form, firstErrorMessage(payload));
                        return;
                    }

                    if (! payload?.status) {
                        throw new Error('Unexpected response');
                    }

                    delete form.dataset.mobilePartSaving;
                    setDamageFormSaving(form, false);
                    applyDamageStatusResult(form, payload);
                } catch (error) {
                    showDamageFormError(form, 'Не удалось сохранить статус. Проверьте соединение.');
                } finally {
                    if (form.dataset.mobilePartSaving === 'true') {
                        delete form.dataset.mobilePartSaving;
                        setDamageFormSaving(form, false);
                    }
                }
            };
            const replaceNode = (selector, source) => {
                const current = qs(selector);
                const next = qs(selector, source);

                if (current && next) {
                    current.replaceWith(next);
                }
            };
            const captureSearchFocus = () => {
                const input = qs('[data-mobile-parts-search]');

                if (! input || document.activeElement !== input) {
                    return null;
                }

                return {
                    value: input.value,
                    selectionStart: input.selectionStart,
                    selectionEnd: input.selectionEnd,
                };
            };
            const restoreSearchFocus = (state) => {
                if (! state) {
                    return;
                }

                const input = qs('[data-mobile-parts-search]');

                if (! input) {
                    return;
                }

                input.value = state.value;
                input.focus({ preventScroll: true });

                if (typeof input.setSelectionRange === 'function'
                    && Number.isInteger(state.selectionStart)
                    && Number.isInteger(state.selectionEnd)) {
                    input.setSelectionRange(state.selectionStart, state.selectionEnd);
                }
            };
            const mergeDocument = (source, append) => {
                const searchFocus = captureSearchFocus();

                if (append) {
                    const list = qs('[data-mobile-parts-list]');
                    const sourceList = qs('[data-mobile-parts-list]', source);
                    const empty = qs('[data-mobile-parts-empty]', list || document);

                    if (list && sourceList) {
                        qsa('[data-mobile-part-card]', sourceList).forEach((card) => {
                            if (card.id && ! document.getElementById(card.id)) {
                                list.insertBefore(card, empty);
                            }
                        });
                    }

                    replaceNode('[data-mobile-parts-pagination]', source);
                } else {
                    replaceNode('[data-mobile-parts]', source);
                    replaceNode('[data-mobile-parts-list]', source);
                    replaceNode('[data-mobile-parts-pagination]', source);
                }

                activeStatus = qs('[data-mobile-parts-filter].is-active')?.dataset.mobilePartsFilter || activeStatus;
                restoreSearchFocus(searchFocus);
                applyVisibleFilters();
            };
            const fetchParts = async (url, { append = false, historyMode = 'push' } = {}) => {
                activeRequest?.abort();
                const controller = new AbortController();

                activeRequest = controller;
                setLoading(true);

                try {
                    const response = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        signal: controller.signal,
                    });

                    if (! response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const html = await response.text();
                    const source = new DOMParser().parseFromString(html, 'text/html');

                    mergeDocument(source, append);

                    if (historyMode === 'push') {
                        history.pushState({ mobileParts: true }, '', url);
                    } else if (historyMode === 'replace') {
                        history.replaceState({ mobileParts: true }, '', url);
                    }
                } catch (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }

                    window.location.href = url;
                } finally {
                    if (activeRequest === controller) {
                        activeRequest = null;
                        setLoading(false);
                    }
                }
            };
            const runSearch = () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    fetchParts(buildUrl({ page: null }), { historyMode: 'replace' });
                }, 250);
            };

            document.addEventListener('input', (event) => {
                const input = targetElement(event)?.closest('[data-mobile-parts-search]');

                if (! input) {
                    return;
                }

                event.stopImmediatePropagation();
                runSearch();
            }, true);

            document.addEventListener('change', (event) => {
                const select = targetElement(event)?.closest('[data-mobile-part-damage-select]');

                if (! select?.form) {
                    return;
                }

                if (damageFormNeedsPlacement(select.form)) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                submitDamageForm(select.form);
            }, true);

            document.addEventListener('submit', (event) => {
                const form = targetElement(event)?.closest('[data-mobile-part-damage-form]');

                if (! form || ! window.fetch) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                submitDamageForm(form);
            }, true);

            document.addEventListener('submit', (event) => {
                const form = targetElement(event)?.closest('[data-mobile-parts] form[method="GET"]');

                if (! form) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                clearTimeout(searchTimer);
                fetchParts(buildUrl({ page: null }), { historyMode: 'push' });
            }, true);

            document.addEventListener('click', (event) => {
                const target = targetElement(event);

                if (! target) {
                    return;
                }

                const loadMore = target.closest('[data-mobile-parts-load-more]');
                const filter = target.closest('[data-mobile-parts-filter]');
                const pager = target.closest('[data-mobile-parts-pagination] a[href]');
                const searchReset = target.closest('[data-mobile-parts] a.help[href]');
                const categoryToggle = target.closest('[data-mobile-parts-category-toggle]');
                const categoryReset = target.closest('[data-mobile-parts-category-reset]');

                if (loadMore) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    fetchParts(loadMore.dataset.nextUrl || loadMore.getAttribute('href'), { append: true, historyMode: 'none' });
                    return;
                }

                if (filter) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    activeStatus = filter.dataset.mobilePartsFilter || 'all';
                    fetchParts(buildUrl({ status: activeStatus, page: null }), { historyMode: 'push' });
                    return;
                }

                if (pager) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    fetchParts(pager.href, { historyMode: 'push' });
                    return;
                }

                if (searchReset) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    const input = qs('[data-mobile-parts-search]');

                    if (input) {
                        input.value = '';
                    }

                    fetchParts(buildUrl({ query: '', page: null }), { historyMode: 'push' });
                    return;
                }

                if (categoryToggle) {
                    event.preventDefault();
                    event.stopImmediatePropagation();

                    const root = categoryToggle.closest('[data-mobile-parts-category]');
                    const menu = root?.querySelector('[data-mobile-parts-category-menu]');
                    const nextState = Boolean(menu?.hidden);

                    closeCategoryMenu();

                    if (menu) {
                        menu.hidden = ! nextState;
                        categoryToggle.setAttribute('aria-expanded', nextState ? 'true' : 'false');
                    }

                    return;
                }

                if (categoryReset) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    qsa('[data-mobile-parts-category-option]').forEach((option) => {
                        option.checked = false;
                    });
                    applyVisibleFilters();
                    return;
                }

                if (! target.closest('[data-mobile-parts-category]')) {
                    closeCategoryMenu();
                }
            }, true);

            document.addEventListener('change', (event) => {
                const option = targetElement(event)?.closest('[data-mobile-parts-category-option]');

                if (option) {
                    applyVisibleFilters();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeCategoryMenu();
                }
            });

            window.addEventListener('popstate', () => {
                fetchParts(window.location.href, { historyMode: 'none' });
            });

            history.replaceState({ mobileParts: true }, '', window.location.href);
            applyVisibleFilters();
        })();
    </script>
@endsection
