@extends('layouts.mobile', [
    'heading' => $donorCar->display_vin,
    'subheading' => collect([$donorCar->brand, $donorCar->display_model, $donorCar->year])->filter()->join(' '),
    'desktopUrl' => route('admin.donor-cars.show', $donorCar),
])

@section('content')
    @php
        $donorPreview = collect($donorCar->photos ?? [])->first();
        $rawProducts = $donorCar->products;
        $sales = $donorCar->partSales;
        $soldCount = $sales->count();
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
        $allCount = $products->count();
        $productStatuses = $products->mapWithKeys(fn ($product): array => [$product->id => $productStatus($product)]);
        $checkedCount = $productStatuses->filter(fn (array $status): bool => $status['key'] === 'checked')->count();
        $brokenCount = $productStatuses->filter(fn (array $status): bool => $status['key'] === 'broken')->count();
        $productName = fn ($product): string => trim((string) ($product->sourcePartCatalogItem?->name_ua ?: $product->sourcePartCatalogItem?->name_ru ?: $product->name));
        $readableCategoryPath = fn (?string $value, bool $stripNumericPrefixes = false): string => $donorPartPresenter->readableCategoryPath($value, $stripNumericPrefixes, true);
        $productCategoryMirrorItem = fn ($product) => ($nikolaCarsProductItemsByProductId ?? collect())->get((int) $product->id);
        $productCategoryOption = function ($product) use ($donorPartPresenter, $donorCar, $productCategoryMirrorItem): array {
            $mirrorItem = $productCategoryMirrorItem($product);

            if (! $mirrorItem) {
                return $donorPartPresenter->mobileProductCategoryOption($donorCar, $product);
            }

            $categoryPath = $donorPartPresenter->categoryPath(
                $donorPartPresenter->categoryForDonor($donorCar, $mirrorItem),
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
                    $donorPartPresenter->categoryForDonor($donorCar, $mirrorItem),
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
        $partCategoryOptions = $products
            ->map($productCategoryOption)
            ->merge($sales->map($saleCategoryOption))
            ->filter(fn (array $option): bool => $option['key'] !== '' && $option['label'] !== '')
            ->unique('key')
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->pluck('label', 'key')
            ->all();
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
                    <img src="{{ $photoUrl($donorPreview) }}" alt="{{ $donorCar->display_vin }}">
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
        <input type="search" placeholder="Поиск по артикулу или названию" autocomplete="off" data-mobile-parts-search>
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
            <button type="button" class="part-filter__chip is-active" data-mobile-parts-filter="all">Все {{ $allCount }}</button>
            <button type="button" class="part-filter__chip" data-mobile-parts-filter="checked">Проверенные {{ $checkedCount }}</button>
            <button type="button" class="part-filter__chip" data-mobile-parts-filter="unchecked">Не проверены</button>
            <button type="button" class="part-filter__chip" data-mobile-parts-filter="broken">Непригодные {{ $brokenCount }}</button>
            <button type="button" class="part-filter__chip" data-mobile-parts-filter="sold">Проданные {{ $soldCount }}</button>
        </div>
    </section>

    <section class="part-list" data-mobile-parts-list>
        @foreach($products as $product)
            @php
                $status = $productStatuses[$product->id] ?? $productStatus($product);
                $stockItem = $product->stockItems->first();
                $workOrder = $product->stoWorkOrderParts->first()?->order;
                $productPhotos = \App\Support\ProductPhotoNormalizer::productPhotos($product);
                $imageUrl = $photoUrl($productPhotos->first());
                $photoCount = $productPhotos->count();
                $sortPrice = (float) $product->selling_price;
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
                    $damageNote($product),
                ])->filter()->implode(' ');
            @endphp
            <article @class([
                'part-card',
                'part-card--danger' => ($status['tone'] ?? '') === 'danger',
                'part-card--success' => ($status['tone'] ?? '') === 'success',
            ]) id="part-{{ $product->id }}" style="order: {{ -1 * (int) round($sortPrice * 100) }}" data-mobile-part-card data-part-status="{{ $status['key'] }}" data-part-category="{{ $categoryValue }}" data-part-search="{{ $searchText }}">
                <form method="POST" action="{{ route('admin.mobile.donor-cars.products.photos.store', [$donorCar, $product]) }}" enctype="multipart/form-data" class="part-card__photo-form" data-mobile-part-photo-form>
                    @csrf
                    <input id="part-photo-{{ $product->id }}" class="part-card__photo-input" type="file" name="photo" accept="image/*" capture="environment" data-mobile-part-photo-input>
                    <label class="part-card__photo" for="part-photo-{{ $product->id }}">
                    @if($imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $productName($product) }}">
                    @else
                        <span class="part-card__photo-empty">#</span>
                    @endif
                        <span class="part-card__photo-badge">{{ $photoCount }} фото</span>
                    </label>
                </form>
                <div class="part-card__body">
                    <div class="part-card__head">
                        <a class="part-card__title" href="{{ route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]) }}">{{ $productName($product) ?: 'Без названия' }}</a>
                        <span class="part-card__status">
                            <span class="tag {{ $status['class'] }}">{{ $status['label'] }}</span>
                            @if(($status['key'] ?? null) === 'checked' && isset($status['origin']))
                                <span class="part-origin-badge" title="{{ $status['origin']['label'] }}" aria-label="{{ $status['origin']['label'] }}">{{ $status['origin']['letter'] }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="part-card__meta">
                        {{ collect([$product->external_sku ?: $product->sku, $categoryLabel])->filter()->join(' · ') }}
                    </div>
                    @if($damageNote($product) !== '')
                        <div class="part-card__meta">Статус: {{ $damageNote($product) }}</div>
                    @endif
                    <form method="POST" action="{{ route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]) }}" class="part-card__damage-form">
                        @csrf
                        @method('PATCH')
                        <label for="part-damage-{{ $product->id }}">Статус</label>
                        <select id="part-damage-{{ $product->id }}" name="damage_note" data-mobile-part-damage-select>
                            @foreach($damageOptions as $damageValue => $damageLabel)
                                <option value="{{ $damageValue }}" @selected($damageNote($product) === (string) $damageValue)>{{ $damageLabel }}</option>
                            @endforeach
                        </select>
                    </form>
                    <div class="part-card__foot">
                        <span class="tag tag-muted">{{ $money($product->selling_price, $product->currency) }}</span>
                        @if($workOrder)
                            <span class="part-card__meta">ЗН {{ $workOrder->number }}</span>
                        @else
                            <span class="part-card__meta">{{ collect([$stockItem?->warehouse?->name, $stockItem?->location?->cell ?: $stockItem?->location?->full_code])->filter()->join(' · ') ?: $product->storage_status_label }}</span>
                        @endif
                    </div>
                </div>
            </article>
        @endforeach

        @foreach($sales as $sale)
            @php
                $saleName = trim((string) ($sale->partCatalogItem?->name_ua ?: $sale->partCatalogItem?->name_ru ?: $sale->partCatalogItem?->name ?: $sale->name));
                $sortPrice = (float) ($sale->total_amount ?? ((float) $sale->quantity * (float) ($sale->unit_price ?? 0)));
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
            <article class="part-card part-card--sale" style="order: {{ -1 * (int) round($sortPrice * 100) }}" data-mobile-part-card data-part-status="sold" data-part-category="{{ $categoryValue }}" data-part-search="{{ $searchText }}" hidden>
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

        <div class="panel empty" data-mobile-parts-empty @if($allCount > 0) hidden @endif>Запчастей по этому донору пока нет.</div>
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
            let activeFilter = 'all';

            const normalize = (value) => String(value || '').toLocaleLowerCase('ru').trim();
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
                        photoInput.form.submit();
                    }

                    return;
                }

                const select = event.target.closest('[data-mobile-part-damage-select]');

                if (! select?.form || select.value === '') {
                    return;
                }

                if (typeof select.form.requestSubmit === 'function') {
                    select.form.requestSubmit();
                } else {
                    select.form.submit();
                }
            });
            applyFilters();
        })();
    </script>
@endsection
