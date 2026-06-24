@extends('layouts.admin', ['heading' => $donorCar->display_vin])

@section('content')
    @php
        $money = fn ($amount) => '$'.number_format((float) $amount, 2, '.', ' ');
        $donorPartPresenter = app(\App\View\Admin\DonorCars\DonorPartDisplayPresenter::class);
        $undefinedCategoryLabel = $donorPartPresenter->undefinedCategoryLabel();
        $donorDetails = collect([
            ['label' => 'VIN', 'value' => $donorCar->display_vin],
            ['label' => 'Статус', 'value' => $donorCar->status_label, 'statusClass' => $donorCar->status_class],
            ['label' => 'Марка', 'value' => $donorCar->brand],
            ['label' => 'Модель', 'value' => $donorCar->model],
            ['label' => 'Привод', 'value' => $donorCar->drive_type_label ?: '—'],
            ['label' => 'Батарея', 'value' => $donorCar->battery_type_label ?: '—'],
            ['label' => 'Performance', 'value' => $donorCar->performance_label ?: '—'],
            ['label' => 'Год', 'value' => $donorCar->year],
            ['label' => 'Цвет', 'value' => $donorCar->color],
            ['label' => 'Маркировка цвета', 'value' => $donorCar->paint_code ?: '-'],
            ['label' => 'Пробег', 'value' => $donorCar->mileage !== null ? number_format($donorCar->mileage, 0, ',', ' ').' mi' : null],
            ['label' => 'Дата покупки донора', 'value' => $donorCar->purchase_date?->format('d.m.Y')],
            ['label' => 'Дата прихода донора на СТО', 'value' => $donorCar->warehouse_arrival_date?->format('d.m.Y'), 'valueClass' => 'donor-details__value--regular'],
            ['label' => 'Цена покупки (со сборами)', 'value' => $donorCar->estimated_cost_usd !== null ? $money($donorCar->estimated_cost_usd) : null, 'valueClass' => 'donor-details__value--regular'],
            ['label' => 'Цена доставки США', 'value' => $donorCar->usa_delivery_price_usd !== null ? $money($donorCar->usa_delivery_price_usd) : null, 'valueClass' => 'donor-details__value--regular'],
            ['label' => 'Цена доставки Клайпеда-Украина', 'value' => $donorCar->klaipeda_ukraine_delivery_price_usd !== null ? $money($donorCar->klaipeda_ukraine_delivery_price_usd) : null, 'valueClass' => 'donor-details__value--regular'],
            ['label' => 'Растаможка', 'value' => $donorCar->customs_clearance_price_usd !== null ? $money($donorCar->customs_clearance_price_usd) : null, 'valueClass' => 'donor-details__value--regular'],
            ['label' => 'Полная стоимость', 'value' => $donorCar->total_cost_usd !== null ? $money($donorCar->total_cost_usd) : null],
            ['label' => 'Примечания', 'value' => $donorCar->notes],
        ])->filter(fn ($detail) => $detail['value'] !== null && $detail['value'] !== '');
        $soldPartsQuantity = $soldPartsQuantity ?? rtrim(rtrim(number_format((float) $donorCar->partSales->sum('quantity'), 3, '.', ''), '0'), '.');
        $soldPartsTotals = $soldPartsTotals ?? $donorCar->partSales
            ->filter(fn ($sale) => $sale->total_amount !== null)
            ->groupBy(fn ($sale) => $sale->currency ?: '')
            ->map(fn ($sales, $currency) => number_format((float) $sales->sum(fn ($sale) => $sale->total_amount), 2, '.', ' ').($currency ? ' '.$currency : ''))
            ->values()
            ->implode(' · ');
        $productSort = $productSort ?? 'price';
        $productDirection = $productDirection ?? 'desc';
        $saleSort = $saleSort ?? 'sold_at';
        $saleDirection = $saleDirection ?? 'desc';
        $productSortUrl = fn (string $field): string => route('admin.donor-cars.show', [
            'donorCar' => $donorCar,
            'product_sort' => $field,
            'product_direction' => $productSort === $field && $productDirection === 'asc' ? 'desc' : 'asc',
            'sale_sort' => $saleSort,
            'sale_direction' => $saleDirection,
        ]);
        $productSortMark = fn (string $field): string => $productSort === $field ? ($productDirection === 'asc' ? ' ^' : ' v') : '';
        $saleSortUrl = fn (string $field): string => route('admin.donor-cars.show', [
            'donorCar' => $donorCar,
            'product_sort' => $productSort,
            'product_direction' => $productDirection,
            'sale_sort' => $field,
            'sale_direction' => $saleSort === $field && $saleDirection === 'asc' ? 'desc' : 'asc',
        ]);
        $saleSortMark = fn (string $field): string => $saleSort === $field ? ($saleDirection === 'asc' ? ' ^' : ' v') : '';
        $nameWithAutoBadge = function (?string $value): array {
            $value = trim((string) $value);

            return [
                'text' => $value,
                'is_auto' => false,
            ];
        };
        $readableCategoryText = fn (string $value, bool $stripNumericPrefix = false): string => $donorPartPresenter->readableCategoryText($value, $stripNumericPrefix);
        $readableCategoryPath = fn (?string $value, bool $stripNumericPrefixes = false): string => $donorPartPresenter->readableCategoryPath($value, $stripNumericPrefixes);
        $catalogCategoryTrail = fn (?App\Models\PartCatalogCategory $category): \Illuminate\Support\Collection => $donorPartPresenter->categoryTrail($category);
        $catalogCategoryLabel = fn (?App\Models\PartCatalogCategory $category, string $locale = 'preferred'): string => $donorPartPresenter->categoryLabel($category, $locale, true);
        $catalogCategoryPath = fn (?App\Models\PartCatalogCategory $category, string $locale = 'name'): string => $donorPartPresenter->categoryPath($category, $locale, true);
        $catalogCategoryForDonor = fn ($catalogItem = null): ?App\Models\PartCatalogCategory => $donorPartPresenter->categoryForDonor($donorCar, $catalogItem);
        $catalogItemRawCategoryPath = fn ($catalogItem = null): string => $donorPartPresenter->catalogRawCategoryPath($catalogItem);
        $donorProductCategoryOption = fn ($catalogItem = null, ?string $categoryPath = null, ?string $fallbackText = null): array => $donorPartPresenter->desktopCategoryOption($donorCar, $catalogItem, $categoryPath, $fallbackText);
        $autoGeneratedTooltip = "\u{0410}\u{0432}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442}\u{0438}\u{0447}\u{0435}\u{0441}\u{043A}\u{0438} \u{0441}\u{0433}\u{0435}\u{043D}\u{0435}\u{0440}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{043D}\u{043E} \u{0438}\u{0437} \u{043A}\u{0430}\u{0442}\u{0430}\u{043B}\u{043E}\u{0433}\u{0430} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0435}\u{0439}.";
        $downloadedProductSources = $donorCar->products
            ->filter(fn ($product) => $product->is_auto_generated && $product->sourcePartCatalogItem)
            ->pluck('sourcePartCatalogItem.source')
            ->filter()
            ->unique()
            ->values();
        $downloadedSourceLabels = [
            'tesla_official' => 'официального сайта',
            'tcarservice' => 'TCARS',
            'teslapartsukraine' => 'TeslaPartsUkraine',
            'tsk' => 'TSK',
            'teslahelp' => 'TeslaHelp / TeslaShop',
            'stock-tesla' => 'Stock Tesla',
            'driveparts' => 'DriveParts',
            'dkparts' => 'DK-Parts',
            'erazborka' => 'Erazborka',
            'teslawestparts' => 'Tesla West Parts',
            'teslacompany' => 'TeslaCompany',
            'nikolacars' => 'NikolaCars',
        ];
        $downloadedSourcesText = $downloadedProductSources
            ->map(fn (string $source): string => $downloadedSourceLabels[$source] ?? $source)
            ->implode(', ');
        $hasOfficialDownloadedProducts = $downloadedProductSources->contains('tesla_official');
        $canDownloadOfficialProducts = (bool) ($donorCar->drive_type && $donorCar->battery_type && $donorCar->is_performance !== null);
        $unknownDamageNote = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";
        $damageNote = function ($product) use ($unknownDamageNote): string {
            $value = trim((string) ($product->notes ?? ''));

            return preg_match('/^\?+$/', $value) ? $unknownDamageNote : $value;
        };
        $brokenDamageNote = \App\Services\NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS;
        $nonLiquidDamageNote = \App\Services\NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS;
        $isUnknownDamageNote = fn ($product): bool => in_array($damageNote($product), ['', $unknownDamageNote], true);
        $isBrokenDamageNote = fn ($product): bool => in_array($damageNote($product), [$brokenDamageNote, $nonLiquidDamageNote], true);
        $isCheckedDamageNote = fn ($product): bool => ! $isUnknownDamageNote($product) && ! $isBrokenDamageNote($product);
        $soldCatalogItemIds = $donorCar->partSales
            ->pluck('part_catalog_item_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $soldProductIds = $donorCar->partSales
            ->flatMap(fn ($sale): array => $donorPartPresenter->saleProductIdCandidates($sale))
            ->filter()
            ->unique()
            ->values();
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
        $donorProducts = $donorCar->products
            ->reject($isInactiveProduct)
            ->reject($isSoldPartSaleProduct)
            ->values();
        $smallProductsCount = $smallProductsCountOverride ?? $donorProducts
            ->filter($isSmallTeslaVinPart)
            ->count();
        $displayProducts = $donorProducts
            ->reject($isSmallTeslaVinPart)
            ->values();
        $checkedProducts = $displayProducts
            ->filter($isCheckedDamageNote)
            ->values();
        $brokenProducts = $displayProducts
            ->filter($isBrokenDamageNote)
            ->values();
        $donorProductTableTotal = $donorProductTableTotal ?? $displayProducts->count();
        $donorProductCheckedTotal = $donorProductCheckedTotal ?? $checkedProducts->count();
        $donorProductBrokenTotal = $donorProductBrokenTotal ?? $brokenProducts->count();
        $donorProductsCurrentPage = $donorProductsCurrentPage ?? 1;
        $donorProductsPerPage = $donorProductsInitialLimit ?? 80;
        $donorProductsLastPage = max(1, (int) ceil($donorProductTableTotal / $donorProductsPerPage));
        $donorProductCategoryKey = function ($catalogItem = null, ?string $productCategorySlug = null, ?string $categoryPath = null, ?string $fallbackText = null) use ($donorProductCategoryOption): string {
            return $donorProductCategoryOption($catalogItem, $categoryPath, $fallbackText)['key'];
        };
        $donorProductCategoryOptions = $donorProducts
            ->map(function ($product) use ($donorProductCategoryOption, $nikolaCarsProductItemsByProductId): array {
                $mirrorItem = ($nikolaCarsProductItemsByProductId ?? collect())->get((int) $product->id);

                return $donorProductCategoryOption(
                    $mirrorItem ?: $product->sourcePartCatalogItem,
                    $mirrorItem ? null : $product->category?->name,
                    $product->name
                );
            })
            ->toBase()
            ->merge($donorCar->partSales->map(function ($sale) use ($donorPartPresenter, $saleProductsById, $saleProductsByCatalogItem, $donorProductCategoryOption): array {
                $saleProduct = $donorPartPresenter->resolveSaleProduct($sale, $saleProductsById, $saleProductsByCatalogItem);
                $saleCatalogItem = $saleProduct?->sourcePartCatalogItem ?: $sale->partCatalogItem;

                return $donorProductCategoryOption(
                    $saleCatalogItem,
                    $saleCatalogItem ? null : $sale->category_path,
                    $sale->name
                );
            })->toBase())
            ->filter(fn (array $option): bool => $option['key'] !== '' && $option['label'] !== '')
            ->unique('key')
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->pluck('label', 'key')
            ->all();
    @endphp
    <div class="grid" style="gap:18px;">
        <div class="panel">
            <div class="donor-summary-layout">
                <div class="donor-summary-info">
                    <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                        <div>
                            <div class="help">{{ $donorCar->brand }} {{ $donorCar->display_model }} {{ $donorCar->year }}</div>
                            <dl class="donor-details">
                                @foreach($donorDetails as $detail)
                                    <div class="donor-details__item">
                                        <dt>{!! $detail['label'] !!}</dt>
                                        <dd class="{{ $detail['valueClass'] ?? '' }}">
                                            @if(isset($detail['statusClass']))
                                                <span class="donor-status {{ $detail['statusClass'] }}">{{ $detail['value'] }}</span>
                                            @else
                                                {{ $detail['value'] }}
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                        <div class="actions">
                            <a class="btn btn-secondary" href="{{ route('admin.donor-cars.edit', $donorCar) }}">Редактировать</a>
                            <a class="btn btn-secondary" href="{{ route('admin.mobile.donor-cars.products.create', $donorCar) }}">Мобильное добавление</a>
                            @include('admin.donor_cars._official_download_button', ['donorCar' => $donorCar])
                        </div>
                    </div>
                </div>

                <div class="donor-photo-strip">
                @if($donorPhotoUrls->isNotEmpty())
                    <div class="photo-grid donor-photo-grid">
                        @foreach($donorPhotoItems ?? [] as $photoItem)
                            <div class="photo-item donor-photo-item">
                                <a class="donor-photo-item__link" href="{{ $photoItem['url'] }}" data-donor-photo-trigger data-photo-index="{{ $loop->index }}">
                                    <img src="{{ $photoItem['preview_url'] }}" alt="Фото {{ $donorCar->display_vin }}" loading="lazy" decoding="async">
                                </a>
                                <form method="POST" action="{{ route('admin.donor-cars.photos.destroy', $donorCar) }}" class="donor-photo-delete-form" data-donor-photo-delete-form>
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="photo" value="{{ $photoItem['path'] }}">
                                    <button type="submit" class="donor-photo-delete-button" aria-label="Удалить фото">
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v9h-2V9Zm4 0h2v9h-2V9ZM7 9h2v10h6V9h2v12H7V9Z" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="donor-photo-empty">Фото донора еще не добавлены.</div>
                @endif

                <form method="POST" action="{{ route('admin.donor-cars.photos.store', $donorCar) }}" enctype="multipart/form-data" class="donor-photo-upload" data-donor-photo-upload>
                    @csrf
                    <input id="donor-photo-upload" type="file" name="photos[]" accept="image/*" multiple data-donor-photos-input data-existing-photo-count="{{ $donorPhotoUrls->count() }}">
                    <label class="donor-photo-dropzone" for="donor-photo-upload" data-donor-photo-dropzone>
                        <span class="donor-photo-dropzone__title">Перетащите фото сюда</span>
                        <span class="donor-photo-dropzone__hint">или нажмите, чтобы выбрать файлы. До {{ \App\Models\DonorCar::PHOTO_LIMIT }} фото.</span>
                    </label>
                </form>
                </div>
            </div>

            @if($donorPhotoUrls->isNotEmpty())
                <dialog class="photo-lightbox" data-photo-lightbox>
                    <div class="photo-lightbox__toolbar">
                        <span data-photo-counter></span>
                        <button type="button" class="btn btn-secondary photo-lightbox__close" data-close-photo-lightbox aria-label="Close">&times;</button>
                    </div>
                    <div class="photo-lightbox__stage">
                        <button type="button" class="btn btn-secondary photo-lightbox__nav photo-lightbox__nav--prev" data-photo-prev aria-label="Previous photo">&#8249;</button>
                        <img src="" alt="{{ $donorCar->display_vin }}" data-photo-lightbox-image>
                        <button type="button" class="btn btn-secondary photo-lightbox__nav photo-lightbox__nav--next" data-photo-next aria-label="Next photo">&#8250;</button>
                    </div>
                </dialog>
            @endif

            <dialog class="product-photo-lightbox" data-donor-product-photo-lightbox>
                <button type="button" class="btn btn-secondary product-photo-lightbox__close" data-donor-product-photo-close aria-label="{{ "\u{0417}\u{0430}\u{043A}\u{0440}\u{044B}\u{0442}\u{044C}" }}">&times;</button>
                <button type="button" class="btn btn-secondary product-photo-lightbox__rotate product-photo-lightbox__rotate--counterclockwise" data-donor-product-photo-rotate data-donor-product-photo-rotate-degrees="270" aria-label="{{ "\u{041F}\u{043E}\u{0432}\u{0435}\u{0440}\u{043D}\u{0443}\u{0442}\u{044C} \u{0438}\u{0437}\u{043E}\u{0431}\u{0440}\u{0430}\u{0436}\u{0435}\u{043D}\u{0438}\u{0435} \u{043F}\u{0440}\u{043E}\u{0442}\u{0438}\u{0432} \u{0447}\u{0430}\u{0441}\u{043E}\u{0432}\u{043E}\u{0439} \u{0441}\u{0442}\u{0440}\u{0435}\u{043B}\u{043A}\u{0438}" }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                    </svg>
                </button>
                <button type="button" class="btn btn-secondary product-photo-lightbox__rotate product-photo-lightbox__rotate--clockwise" data-donor-product-photo-rotate data-donor-product-photo-rotate-degrees="90" aria-label="{{ "\u{041F}\u{043E}\u{0432}\u{0435}\u{0440}\u{043D}\u{0443}\u{0442}\u{044C} \u{0438}\u{0437}\u{043E}\u{0431}\u{0440}\u{0430}\u{0436}\u{0435}\u{043D}\u{0438}\u{0435} \u{043F}\u{043E} \u{0447}\u{0430}\u{0441}\u{043E}\u{0432}\u{043E}\u{0439} \u{0441}\u{0442}\u{0440}\u{0435}\u{043B}\u{043A}\u{0435}" }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M21 12a9 9 0 1 1-9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
                        <path d="M21 3v5h-5"/>
                    </svg>
                </button>
                <button type="button" class="btn btn-secondary product-photo-lightbox__nav product-photo-lightbox__nav--prev" data-donor-product-photo-prev aria-label="{{ "\u{041F}\u{0440}\u{0435}\u{0434}\u{044B}\u{0434}\u{0443}\u{0449}\u{0435}\u{0435} \u{0438}\u{0437}\u{043E}\u{0431}\u{0440}\u{0430}\u{0436}\u{0435}\u{043D}\u{0438}\u{0435}" }}">&#8249;</button>
                <div class="product-photo-lightbox__stage">
                    <img src="" alt="" data-donor-product-photo-lightbox-image>
                </div>
                <button type="button" class="btn btn-secondary product-photo-lightbox__nav product-photo-lightbox__nav--next" data-donor-product-photo-next aria-label="{{ "\u{0421}\u{043B}\u{0435}\u{0434}\u{0443}\u{044E}\u{0449}\u{0435}\u{0435} \u{0438}\u{0437}\u{043E}\u{0431}\u{0440}\u{0430}\u{0436}\u{0435}\u{043D}\u{0438}\u{0435}" }}">&#8250;</button>
                <div class="product-photo-lightbox__counter" data-donor-product-photo-counter></div>
            </dialog>
        </div>

        <div class="panel">
            <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;">
                    <h2 class="section-title" style="margin-top:0;">Запчасти с донора {{ $donorCar->display_vin }}</h2>
                </div>
                <div class="actions">
                    <a class="btn btn-secondary btn-small" href="{{ route('admin.donor-cars.small-parts.index', $donorCar) }}">Мелочевка: {{ $smallProductsCount }}</a>
                    <button type="button" class="btn-small" data-open-part-dialog>Добавить запчасть</button>
                </div>
            </div>

            <div class="donor-products-tabs" data-donor-products-tabs>
                <button type="button" class="donor-products-tab is-active" data-donor-products-tab="all">Все запчасти <span data-donor-products-tab-count="all">{{ $donorProductTableTotal }}</span></button>
                <button type="button" class="donor-products-tab" data-donor-products-tab="checked">Проверенные запчасти <span data-donor-products-tab-count="checked">{{ $donorProductCheckedTotal }}</span></button>
                <button type="button" class="donor-products-tab" data-donor-products-tab="broken">Непригодные запчасти <span data-donor-products-tab-count="broken">{{ $donorProductBrokenTotal }}</span></button>
                <button type="button" class="donor-products-tab" data-donor-products-tab="sold">Проданные запчасти <span>{{ $donorPartSalesTotal ?? $donorCar->partSales->count() }}</span></button>
            </div>

            <div class="donor-products-search">
                <label for="donor-products-search">Поиск запчастей</label>
                <input id="donor-products-search" type="search" placeholder="Артикул или название" autocomplete="off" data-donor-products-search data-donor-products-table-url="{{ route('admin.donor-cars.products.table', $donorCar) }}">
                <label id="donor-products-category-label">Категория</label>
                <div class="donor-category-filter" data-donor-products-category>
                    <button type="button" class="donor-category-filter__toggle" aria-haspopup="true" aria-expanded="false" aria-labelledby="donor-products-category-label donor-products-category-summary" data-donor-products-category-toggle @disabled(empty($donorProductCategoryOptions))>
                        <span id="donor-products-category-summary" data-donor-products-category-summary>Все категории</span>
                    </button>
                    <div class="donor-category-filter__menu" role="group" aria-labelledby="donor-products-category-label" data-donor-products-category-menu hidden>
                        @foreach($donorProductCategoryOptions as $categoryValue => $categoryLabel)
                            <label class="donor-category-filter__option">
                                <input type="checkbox" value="{{ $categoryValue }}" data-category-label="{{ $categoryLabel }}" data-donor-products-category-option>
                                <span>{{ $categoryLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div data-donor-products-panel="all">
                <div data-donor-products-table-wrap>
                    @include('admin.donor_cars._products_table', [
                        'products' => $displayProducts,
                        'emptyText' => 'Запчасти с этого донора еще не добавлены.',
                        'showOfficialFields' => true,
                        'donorProductReservations' => $donorProductReservations,
                        'officialTeslaCatalogPricesByProductId' => $officialTeslaCatalogPricesByProductId,
                        'officialTeslaCatalogNamesByProductId' => $officialTeslaCatalogNamesByProductId,
                        'smallPartNumbers' => $smallPartNumbers,
                        'showProductAnchors' => true,
                    ])
                </div>
                <div data-donor-products-pagination>
                    @include('admin.donor_cars._products_pagination', [
                        'currentPage' => $donorProductsCurrentPage,
                        'lastPage' => $donorProductsLastPage,
                        'total' => $donorProductTableTotal,
                        'perPage' => $donorProductsPerPage,
                    ])
                </div>
            </div>
            <div data-donor-products-panel="sold" hidden>
                <div data-donor-sales-table-wrap data-donor-sales-table-url="{{ route('admin.donor-cars.sales.table', ['donorCar' => $donorCar, 'sale_sort' => $saleSort, 'sale_direction' => $saleDirection]) }}">
                    <div class="help">
                        Кол-во: {{ $soldPartsQuantity ?: '0' }}
                        @if($soldPartsTotals)
                            · Сумма: {{ $soldPartsTotals }}
                        @endif
                    </div>
                    <div class="empty" data-donor-sales-placeholder>Проданные запчасти загружаются...</div>
                </div>
            </div>
        </div>
    </div>

    <dialog class="part-dialog generate-dialog" data-generate-dialog>
        <form method="POST" action="{{ route('admin.donor-cars.products.generate', $donorCar) }}" class="part-dialog__form" data-generate-form data-preview-url="{{ route('admin.donor-cars.products.generate.preview', $donorCar) }}">
            @csrf
            <div class="part-dialog__header">
                <h2>Сгенерировать товары</h2>
                <button type="button" class="btn btn-secondary" data-close-generate-dialog aria-label="Закрыть">&times;</button>
            </div>

            <div class="help" style="margin-bottom:14px;">
                Выберите зоны повреждений. Сначала покажем превью, потом можно будет создать товары.
            </div>

            <div class="damage-zone-grid">
                @foreach($damageZones as $value => $label)
                    <label class="damage-zone-option">
                        <input type="checkbox" name="damage_zones[]" value="{{ $value }}">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>

            <div class="generate-preview" data-generate-preview hidden>
                <div class="generate-preview__summary" data-generate-summary></div>
                <div class="generate-preview__actions">
                    <button type="button" class="btn btn-secondary" data-select-all-preview>Выбрать все</button>
                    <button type="button" class="btn btn-secondary" data-unselect-all-preview>Снять все</button>
                </div>
                <div class="generate-preview__table-wrap">
                    <table class="generate-preview__table">
                        <thead>
                        <tr>
                            <th></th>
                            <th>Товар</th>
                            <th>Артикул</th>
                            <th>Категория</th>
                            <th>
                                <button type="button" class="generate-preview__sort" data-sort-condition aria-label="Сортировать по состоянию">
                                    Состояние <span data-sort-condition-icon>-</span>
                                </button>
                            </th>
                        </tr>
                        </thead>
                        <tbody data-generate-preview-body></tbody>
                    </table>
                </div>
            </div>

            <div class="actions" style="margin-top:20px;">
                <button type="button" data-load-generate-preview>Показать превью</button>
                <button type="submit" data-submit-generated-products hidden>Создать выбранные</button>
                <button type="button" class="btn btn-secondary" data-close-generate-dialog>Отмена</button>
            </div>
        </form>
    </dialog>

    <dialog class="part-dialog" data-part-dialog>
        <form method="POST" action="{{ route('admin.donor-cars.products.store', $donorCar) }}" enctype="multipart/form-data" class="part-dialog__form">
            @csrf
            <div class="part-dialog__header">
                <h2>Добавить запчасть</h2>
                <button type="button" class="btn btn-secondary" data-close-part-dialog aria-label="Закрыть">&times;</button>
            </div>

            <div class="form-grid">
                <div class="product-name-autocomplete" data-product-search-url="{{ route('admin.products.search') }}">
                    <label>Название запчасти</label>
                    <input name="name" value="{{ old('name') }}" required autocomplete="off" data-product-name-input>
                    <div class="product-suggestions" data-product-suggestions hidden></div>
                </div>
                <div>
                    <label>Статус</label>
                    <select name="damage_note" required>
                        @foreach(($manualDamageOptions ?? []) as $damageValue => $damageLabel)
                            <option value="{{ $damageValue }}" @selected(old('damage_note', "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}") === (string) $damageValue)>{{ $damageLabel }}</option>
                        @endforeach
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
                    <label>Цвет</label>
                    <input name="color" value="{{ old('color', $donorCar->color) }}">
                </div>
                <div>
                    <label>Фото</label>
                    <input type="file" name="photos[]" accept="image/*" multiple data-part-photos>
                    <div class="help">До 5 фото.</div>
                </div>
                <div>
                    <label>Цена продажи (USD)</label>
                    <input type="number" step="0.01" min="0" name="selling_price" value="{{ old('selling_price') }}">
                </div>
                <div>
                    <label>Код</label>
                    <input value="{{ $nextPartCode }}" disabled>
                </div>
                <div>
                    <label>Артикул</label>
                    <input name="external_sku" value="{{ old('external_sku') }}">
                    <div class="help">Категория определится автоматически из каталога конкурентов.</div>
                </div>
                <div class="full">
                    <label>Описание</label>
                    <textarea name="description">{{ old('description') }}</textarea>
                </div>
                <div>
                    <label>Склад</label>
                    <select name="warehouse_id" required data-part-warehouse>
                        <option value="">—</option>
                        @foreach($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" data-floor-count="{{ $warehouse->floor_count }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div data-part-floor-wrap>
                    <label>Этаж</label>
                    <select name="floor" data-part-floor data-selected-floor="{{ old('floor') }}">
                        @foreach(\App\Models\Location::floorsForCount(20) as $value => $label)
                            <option value="{{ $value }}" @selected(old('floor') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Ячейка</label>
                    <input name="location_cell" value="{{ old('location_cell') }}" placeholder="Не обязательно">
                </div>
            </div>

            <div class="actions" style="margin-top:20px;">
                <button type="submit">Добавить</button>
                <button type="button" class="btn btn-secondary" data-close-part-dialog>Отмена</button>
            </div>
        </form>
    </dialog>

    <style>
        .part-dialog {
            width: min(760px, calc(100vw - 32px));
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 0;
            background: var(--panel);
            color: var(--text);
            box-shadow: 0 24px 70px rgba(25, 32, 36, .28);
        }

        .generate-dialog {
            width: min(1120px, calc(100vw - 32px));
        }

        .part-dialog::backdrop {
            background: rgba(29, 42, 49, .42);
        }

        .part-dialog__form {
            padding: 20px;
        }

        .part-dialog__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .part-dialog__header h2 {
            margin: 0;
            font-size: 22px;
        }

        .part-dialog__header .btn {
            width: 42px;
            height: 42px;
            padding: 0;
            font-size: 24px;
            line-height: 1;
        }

        .product-name-autocomplete { position: relative; }
        .product-suggestions {
            position: absolute;
            z-index: 30;
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

        .donor-toast {
            position: fixed;
            z-index: 1000;
            top: 18px;
            right: 18px;
            max-width: min(360px, calc(100vw - 36px));
            padding: 12px 16px;
            border: 1px solid rgba(38, 135, 99, .28);
            border-radius: 8px;
            background: #e5f2ed;
            color: var(--accent);
            box-shadow: 0 12px 30px rgba(25, 32, 36, .16);
            font-weight: 700;
            line-height: 1.35;
            opacity: 0;
            transform: translateY(-8px);
            transition: opacity .18s ease, transform .18s ease;
            pointer-events: none;
        }

        .donor-toast.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .grid > .panel {
            min-width: 0;
        }

        .donor-products-table {
            margin-top: 10px;
            font-size: 12px;
            table-layout: fixed;
            min-width: 1220px;
        }

        .donor-products-table--official {
            min-width: 1360px;
        }

        [data-donor-products-panel] {
            max-width: 100%;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-gutter: stable;
        }

        .donor-products-tabs {
            display: flex;
            gap: 8px;
            margin: 14px 0 10px;
            border-bottom: 1px solid var(--line);
        }

        .donor-products-tab {
            border: 1px solid transparent;
            border-bottom: 0;
            border-radius: 8px 8px 0 0;
            background: transparent;
            color: var(--muted);
            font-weight: 700;
        }

        .donor-products-tab.is-active {
            border-color: var(--line);
            background: white;
            color: var(--text);
        }

        .donor-products-tab span {
            margin-left: 4px;
            color: var(--muted);
            font-weight: 600;
        }

        .donor-products-search {
            display: grid;
            grid-template-columns: minmax(140px, 180px) minmax(220px, 420px) minmax(90px, 120px) minmax(220px, 320px);
            gap: 10px;
            align-items: center;
            margin: 12px 0 6px;
        }

        .donor-products-search label {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }

        .donor-products-search input,
        .donor-products-search select,
        .donor-category-filter {
            width: 100%;
        }

        .donor-category-filter {
            position: relative;
        }

        .donor-category-filter__toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
            min-height: 40px;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            font-size: 13px;
            font-weight: 700;
            line-height: 1.25;
            text-align: left;
        }

        .donor-category-filter__toggle::after {
            content: '⌄';
            flex: 0 0 auto;
            color: var(--muted);
            font-size: 14px;
            line-height: 1;
        }

        .donor-category-filter__toggle span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .donor-category-filter__toggle[aria-expanded="true"]::after {
            transform: rotate(180deg);
        }

        .donor-category-filter__toggle:disabled {
            cursor: not-allowed;
            opacity: .65;
        }

        .donor-category-filter__menu {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            z-index: 30;
            display: grid;
            gap: 2px;
            width: max(100%, 320px);
            max-height: 280px;
            overflow: auto;
            padding: 6px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .14);
        }

        .donor-category-filter__menu[hidden] {
            display: none;
        }

        .donor-category-filter__option {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin: 0;
            padding: 8px;
            border-radius: 6px;
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            line-height: 1.25;
            cursor: pointer;
        }

        .donor-category-filter__option:hover,
        .donor-category-filter__option:focus-within {
            background: var(--accent-soft);
        }

        .donor-category-filter__option input {
            flex: 0 0 auto;
            width: 16px;
            height: 16px;
            margin: 0;
        }

        .donor-products-table th,
        .donor-products-table td {
            padding: 6px 5px;
            line-height: 1.25;
        }

        .donor-products-table {
            table-layout: fixed;
            width: 100%;
        }

        .donor-products-table th {
            font-size: 11px;
            letter-spacing: 0;
            text-transform: none;
        }

        [data-donor-products-panel] tbody tr[data-donor-product-row] > td:nth-child(4) {
            color: var(--muted);
        }

        .donor-products-table tbody tr[data-donor-product-row].is-striped > td,
        [data-donor-products-panel] tbody tr[data-donor-product-row].is-striped > td {
            background: #f8fafc;
        }

        [data-donor-products-panel] tbody tr.donor-product-row--checked > td,
        [data-donor-products-panel] tbody tr.donor-product-row--checked.is-striped > td {
            background: #dcfce7;
        }

        [data-donor-products-panel] tbody tr.donor-product-row--broken > td,
        [data-donor-products-panel] tbody tr.donor-product-row--broken.is-striped > td {
            background: #fee2e2;
        }

        .donor-products-price-heading,
        .donor-products-price-heading span,
        .donor-products-quantity-heading,
        .donor-products-quantity-heading span {
            display: block;
        }

        .donor-products-table th:nth-child(1),
        .donor-products-table td:nth-child(1) {
            width: 84px;
            min-width: 84px;
            max-width: 84px;
            overflow: hidden;
            text-align: center;
            vertical-align: middle;
        }

        .donor-product-photo-preview {
            display: inline-grid;
            width: 72px;
            height: 54px;
            max-width: 72px;
            max-height: 54px;
            place-items: center;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--muted);
            text-decoration: none;
            vertical-align: middle;
        }

        .donor-product-photo-preview img {
            display: block;
            width: 72px;
            height: 54px;
            max-width: 72px;
            max-height: 54px;
            object-fit: cover;
        }

        .donor-product-photo-preview--empty {
            font-size: 12px;
        }

        .donor-products-table th:nth-child(2),
        .donor-products-table td:nth-child(2) {
            width: 112px;
        }

        .donor-products-table th:nth-child(3),
        .donor-products-table td:nth-child(3) {
            width: 300px;
        }

        .donor-products-table th:nth-child(4),
        .donor-products-table td:nth-child(4) {
            width: 230px;
        }

        .donor-products-table th:nth-child(5),
        .donor-products-table td:nth-child(5) {
            width: 96px;
        }

        .donor-products-table th:nth-child(6),
        .donor-products-table td:nth-child(6) {
            width: 112px;
            white-space: nowrap;
        }

        .donor-products-table th:nth-child(7),
        .donor-products-table td:nth-child(7) {
            width: 130px;
        }

        .donor-products-table th:nth-child(8),
        .donor-products-table td:nth-child(8) {
            width: 68px;
            text-align: center;
        }

        .donor-products-table th:nth-child(9),
        .donor-products-table td:nth-child(9) {
            width: 130px;
            white-space: nowrap;
        }

        .donor-products-table th:last-child,
        .donor-products-table td:last-child {
            width: 116px;
            white-space: nowrap;
        }

        .donor-products-table--official th:nth-child(6),
        .donor-products-table--official td:nth-child(6) {
            width: 130px;
            white-space: normal;
        }

        .donor-products-table--official th:nth-child(7),
        .donor-products-table--official td:nth-child(7) {
            width: 112px;
            white-space: nowrap;
        }

        .donor-products-table--official th:nth-child(8),
        .donor-products-table--official td:nth-child(8) {
            width: 130px;
            white-space: nowrap;
            text-align: left;
        }

        .donor-products-table--official th:nth-child(9),
        .donor-products-table--official td:nth-child(9) {
            width: 68px;
            text-align: center;
            white-space: nowrap;
        }

        .donor-products-table--official th:nth-child(10),
        .donor-products-table--official td:nth-child(10) {
            width: 130px;
            white-space: nowrap;
        }

        .donor-products-table--official td.actions {
            flex-wrap: nowrap;
        }

        .donor-products-table .photo-presence--yes {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            font-size: 15px;
            font-weight: 700;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .donor-products-table .help {
            margin-top: 2px;
            font-size: 11px;
            line-height: 1.25;
        }

        .donor-products-table .tag,
        .donor-products-table .auto-generated-badge,
        .donor-products-table .part-condition {
            min-height: 19px;
            padding: 2px 6px;
            font-size: 10px;
        }

        .donor-products-table .auto-generated-badge {
            width: 18px;
            min-height: 18px;
            height: 18px;
            margin-left: 1px;
            padding: 0;
            justify-content: center;
            font-size: 10px;
        }

        .donor-products-table .donor-product-source-tag {
            background: #111827;
            color: #fff;
        }

        .donor-product-names {
            display: grid;
            gap: 3px;
            max-width: 100%;
        }

        .donor-product-check-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 5px;
        }

        .donor-product-name-row {
            display: flex;
            align-items: baseline;
            gap: 5px;
            min-width: 0;
        }

        .donor-product-name-row a,
        .donor-product-name-row [data-donor-product-name-label] {
            flex: 0 1 auto;
            min-width: 0;
            overflow-wrap: normal;
            word-break: normal;
        }

        .donor-product-name-label {
            flex: 0 0 34px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            line-height: 1.2;
        }

        .donor-product-name-form {
            display: inline-flex;
            flex: 0 0 auto;
            margin: 0;
        }

        .donor-products-table .donor-product-name-edit {
            width: 24px;
            height: 24px;
            padding: 0;
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: var(--muted);
            font-size: 14px;
            line-height: 1;
        }

        .donor-products-table .donor-product-name-edit:hover,
        .donor-products-table .donor-product-name-edit:focus {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .donor-products-table .actions {
            gap: 5px;
        }

        .donor-products-table td.actions {
            display: table-cell;
            white-space: nowrap;
        }

        .donor-products-table td.actions .inline-form {
            display: inline-block;
            margin-left: 5px;
        }

        .donor-products-table .btn,
        .donor-products-table button {
            padding: 5px 8px;
            font-size: 11px;
            line-height: 1.15;
        }

        .donor-product-inline-form {
            margin: 0;
        }

        .donor-product-inline-select {
            width: 100%;
            min-width: 140px;
            max-width: 190px;
            padding: 5px 8px;
            background-color: #fff;
            font-size: 12px;
            line-height: 1.2;
        }

        .donor-product-inline-select--checked,
        .donor-product-inline-select--broken {
            font-weight: 700;
        }

        .donor-product-inline-select--checked {
            border-color: #86efac;
            background-color: #dcfce7;
            color: #14532d;
        }

        .donor-product-inline-select--broken {
            border-color: #fecaca;
            background-color: #fee2e2;
            color: #7f1d1d;
        }

        .donor-product-damage-option--checked {
            background-color: #fff;
            color: #14532d;
            font-weight: 700;
        }

        .donor-product-damage-option--broken {
            background-color: #fff;
            color: #7f1d1d;
            font-weight: 700;
        }

        .donor-product-sale-price-cell {
            min-width: 156px;
            font-size: 12px;
        }

        .donor-product-price-display,
        .donor-product-price-editor {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .donor-product-price-display[hidden],
        .donor-product-price-editor[hidden] {
            display: none;
        }

        .donor-product-price-display span {
            display: grid;
            gap: 2px;
            min-width: 66px;
            font-size: 12px;
            font-weight: 400;
        }

        .donor-product-price-display small {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.2;
            white-space: nowrap;
        }

        .donor-product-catalog-price {
            display: grid;
            gap: 2px;
            min-width: 66px;
            font-size: 12px;
            font-weight: 400;
        }

        .donor-product-catalog-price small {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.2;
            white-space: nowrap;
        }

        .donor-product-stock-display {
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }

        .donor-product-stock-text {
            display: grid;
            gap: 2px;
            min-width: 0;
        }

        .donor-product-stock-line {
            display: block;
            min-width: 0;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }

        .donor-product-price-editor {
            margin: 0;
        }

        .donor-product-price-input {
            width: 92px;
            min-width: 92px;
            padding: 5px 7px;
            font-size: 12px;
            line-height: 1.2;
        }

        .donor-products-table .donor-product-price-icon {
            width: 26px;
            height: 26px;
            padding: 0;
            border-radius: 6px;
            font-size: 12px;
            line-height: 1;
        }

        .donor-products-table .donor-product-small-part-icon {
            width: 28px;
            height: 28px;
            padding: 0;
            border-radius: 6px;
            background: #fef3c7;
            color: #92400e;
            font-size: 16px;
            font-weight: 800;
            line-height: 1;
        }

        .donor-products-table .donor-product-small-part-icon:hover,
        .donor-products-table .donor-product-small-part-icon:focus {
            background: #fde68a;
            color: #78350f;
        }

        @media (max-width: 700px) {
            .donor-products-search {
                grid-template-columns: 1fr;
            }

            .donor-category-filter__menu {
                position: static;
                width: 100%;
                margin-top: 6px;
            }
        }

        .auto-generated-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            min-width: 20px;
            height: 20px;
            margin-left: 6px;
            padding: 0;
            border-radius: 999px;
            background: #e0f2fe;
            color: #075985;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            vertical-align: middle;
        }

        .damage-zone-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }

        .damage-zone-option {
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 44px;
            margin: 0;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #f8fbfb;
            cursor: pointer;
        }

        .damage-zone-option input {
            width: 18px;
            height: 18px;
            margin: 0;
        }

        .damage-zone-option span {
            font-weight: 700;
            line-height: 1.25;
        }

        .generate-preview {
            margin-top: 18px;
            border-top: 1px solid var(--line);
            padding-top: 16px;
        }

        .generate-preview__summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .generate-preview__summary span,
        .generate-status {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 3px 9px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
        }

        .generate-status--creatable { background: #dcfce7; color: #166534; }
        .generate-status--existing { background: #fef3c7; color: #92400e; }
        .generate-status--damaged { background: #fee2e2; color: #991b1b; }

        .part-condition {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 3px 9px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
        }

        .part-condition--damaged {
            background: #fee2e2;
            color: #991b1b;
        }

        .generate-preview__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .generate-preview__table-wrap {
            max-height: min(460px, calc(100vh - 360px));
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        .generate-preview__table {
            margin: 0;
            min-width: 920px;
        }

        .generate-preview__table th,
        .generate-preview__table td {
            vertical-align: top;
        }

        .generate-preview__sort {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-height: 0;
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }

        .generate-preview__sort:hover { color: var(--accent); }

        .generate-preview__check {
            width: 18px;
            height: 18px;
            margin: 0;
        }

        .generate-preview__name {
            font-weight: 800;
            line-height: 1.25;
        }

        .generate-preview__meta {
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.35;
        }

        .donor-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 8px 14px;
            margin: 10px 0 0;
            font-size: 13px;
        }
        .donor-details__item {
            display: flex;
            align-items: baseline;
            gap: 6px;
            min-width: 0;
        }
        .donor-details dt {
            flex: 0 1 auto;
            margin: 0;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.2;
            letter-spacing: 0;
        }
        .donor-details dt::after {
            content: ':';
        }
        .donor-details dd {
            flex: 1 1 auto;
            min-width: 0;
            margin: 0;
            font-weight: 700;
            line-height: 1.2;
            overflow-wrap: anywhere;
        }
        .donor-details dd.donor-details__value--regular {
            font-weight: 400;
        }

        .donor-details .donor-status {
            min-height: 20px;
            padding: 2px 7px;
            font-size: 11px;
        }

        .donor-summary-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(260px, 420px);
            gap: 18px;
            align-items: start;
        }

        .donor-summary-info {
            min-width: 0;
        }

        .donor-photo-strip {
            display: grid;
            grid-template-columns: 1fr;
            align-items: start;
            gap: 8px;
            margin-top: 0;
            min-width: 0;
        }
        .donor-photo-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 6px;
            margin: 0;
        }

        .donor-photo-grid .photo-item {
            gap: 0;
        }

        .donor-photo-grid .photo-item img {
            border-radius: 6px;
        }

        .donor-photo-item {
            position: relative;
            display: block;
            cursor: default;
            overflow: hidden;
        }

        .donor-photo-item__link {
            display: block;
            width: 100%;
            aspect-ratio: 4 / 3;
            overflow: hidden;
            color: inherit;
            cursor: zoom-in;
        }

        .donor-photo-item__link img {
            display: block;
            width: 100%;
            height: 100%;
            max-width: 100%;
            object-fit: cover;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: white;
        }

        .donor-photo-delete-form {
            position: absolute;
            top: 6px;
            right: 6px;
            z-index: 4;
            margin: 0;
        }

        .donor-photo-delete-button {
            display: grid;
            place-items: center;
            width: 32px;
            min-width: 32px;
            height: 32px;
            min-height: 32px;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, .72);
            border-radius: 999px;
            background: rgba(159, 45, 45, .96);
            color: #fff;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(29, 42, 49, .22);
        }

        .donor-photo-delete-button:hover,
        .donor-photo-delete-button:focus-visible {
            background: #b91c1c;
            outline: 2px solid rgba(185, 28, 28, .28);
            outline-offset: 2px;
        }

        .donor-photo-delete-button svg {
            width: 19px;
            height: 19px;
            fill: currentColor;
        }

        .donor-photo-upload input[type="file"] {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
            clip-path: inset(50%);
        }
        .donor-photo-empty {
            display: grid;
            place-items: center;
            min-height: 96px;
            border: 1px dashed var(--line);
            border-radius: 8px;
            color: var(--muted);
            font-size: 12px;
            text-align: center;
        }
        .donor-photo-dropzone {
            display: grid;
            align-content: center;
            gap: 4px;
            min-width: 0;
            min-height: 74px;
            margin: 0;
            padding: 10px;
            border: 1px dashed var(--line);
            border-radius: 8px;
            background: #f8fbfb;
            color: var(--text);
            cursor: pointer;
            text-align: center;
            transition: border-color .16s ease, background-color .16s ease, color .16s ease;
        }
        .donor-photo-dropzone:hover,
        .donor-photo-dropzone.is-dragover {
            border-color: var(--accent);
            background: var(--accent-soft);
            color: var(--accent);
        }
        .donor-photo-dropzone__title {
            font-weight: 800;
            font-size: 12px;
            line-height: 1.2;
        }
        .donor-photo-dropzone__hint {
            color: var(--muted);
            font-size: 11px;
            font-weight: 400;
            line-height: 1.25;
        }
        .donor-photo-upload__button {
            min-height: 42px;
            white-space: nowrap;
            cursor: pointer;
        }
        .donor-photo-item__link { cursor: zoom-in; }
        .photo-lightbox {
            width: min(1120px, calc(100vw - 32px));
            max-height: calc(100vh - 32px);
            border: 0;
            border-radius: 18px;
            padding: 0;
            background: #0f171b;
            color: white;
            box-shadow: 0 24px 80px rgba(0, 0, 0, .42);
        }
        .photo-lightbox::backdrop { background: rgba(10, 17, 20, .72); }
        .photo-lightbox__toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
        }
        .photo-lightbox__toolbar span { font-size: 13px; color: rgba(255, 255, 255, .76); }
        .photo-lightbox__close,
        .photo-lightbox__nav {
            width: 42px;
            height: 42px;
            padding: 0;
            border-color: rgba(255, 255, 255, .18);
            background: rgba(255, 255, 255, .08);
            color: white;
        }
        .photo-lightbox__close { font-size: 26px; line-height: 1; }
        .photo-lightbox__stage {
            position: relative;
            display: grid;
            place-items: center;
            min-height: min(680px, calc(100vh - 114px));
            padding: 0 64px 20px;
        }
        .photo-lightbox__stage img {
            display: block;
            max-width: 100%;
            max-height: calc(100vh - 150px);
            object-fit: contain;
            border-radius: 10px;
            background: #0b1013;
        }
        .photo-lightbox__nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 34px;
            line-height: 1;
        }
        .photo-lightbox__nav--prev { left: 14px; }
        .photo-lightbox__nav--next { right: 14px; }
        .photo-lightbox__nav[hidden] { display: none; }
        .product-photo-lightbox {
            width: min(1120px, calc(100vw - 32px));
            height: min(820px, calc(100vh - 32px));
            max-height: calc(100vh - 32px);
            padding: 48px 62px 44px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: rgba(255, 255, 255, .98);
            box-sizing: border-box;
            box-shadow: 0 24px 70px rgba(25, 32, 36, .25);
        }
        .product-photo-lightbox::backdrop { background: rgba(29, 42, 49, .6); }
        .product-photo-lightbox__stage {
            display: grid;
            width: 100%;
            height: 100%;
            min-width: 0;
            min-height: 0;
            place-items: center;
            overflow: hidden;
            border-radius: 8px;
            background: #fff;
        }
        .product-photo-lightbox img {
            display: block;
            width: 100%;
            height: 100%;
            min-width: 0;
            min-height: 0;
            object-fit: contain;
            border-radius: 8px;
            background: #fff;
        }
        .product-photo-lightbox__close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 34px;
            height: 34px;
            padding: 0;
            font-size: 22px;
            line-height: 1;
        }
        .product-photo-lightbox__rotate {
            position: absolute;
            top: 12px;
            width: 34px;
            height: 34px;
            padding: 0;
        }
        .product-photo-lightbox__rotate--clockwise { right: 54px; }
        .product-photo-lightbox__rotate--counterclockwise { right: 96px; }
        .product-photo-lightbox__rotate svg {
            display: block;
            width: 19px;
            height: 19px;
            margin: auto;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .product-photo-lightbox__nav {
            position: absolute;
            top: 50%;
            width: 38px;
            height: 56px;
            padding: 0;
            transform: translateY(-50%);
            font-size: 34px;
            line-height: 1;
        }
        .product-photo-lightbox__nav--prev { left: 12px; }
        .product-photo-lightbox__nav--next { right: 12px; }
        .product-photo-lightbox__counter {
            position: absolute;
            left: 50%;
            bottom: 14px;
            transform: translateX(-50%);
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }
        .product-photo-lightbox__nav[hidden],
        .product-photo-lightbox__rotate[hidden] {
            display: none;
        }

        @media (max-width: 1100px) {
            .donor-summary-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {

            .donor-photo-strip {
                grid-template-columns: 1fr;
            }

            .donor-photo-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .donor-photo-upload__button {
                width: 100%;
                justify-content: center;
            }
            .donor-photo-dropzone {
                min-width: 0;
                width: 100%;
            }
            .photo-lightbox__stage {
                min-height: min(520px, calc(100vh - 104px));
                padding: 0 14px 76px;
            }
            .photo-lightbox__stage img { max-height: calc(100vh - 178px); }
            .photo-lightbox__nav {
                top: auto;
                bottom: 18px;
                transform: none;
            }
            .photo-lightbox__nav--prev { left: calc(50% - 54px); }
            .photo-lightbox__nav--next { right: calc(50% - 54px); }
            .product-photo-lightbox {
                padding: 48px 14px 76px;
            }
            .product-photo-lightbox__nav {
                top: auto;
                bottom: 18px;
                transform: none;
            }
            .product-photo-lightbox__nav--prev { left: calc(50% - 54px); }
            .product-photo-lightbox__nav--next { right: calc(50% - 54px); }
        }
    </style>

    <script>
        (() => {
            const dialog = document.querySelector('[data-part-dialog]');
            const openButton = document.querySelector('[data-open-part-dialog]');
            const closeButtons = document.querySelectorAll('[data-close-part-dialog]');
            const generateDialog = document.querySelector('[data-generate-dialog]');
            const openGenerateButton = document.querySelector('[data-open-generate-dialog]');
            const closeGenerateButtons = document.querySelectorAll('[data-close-generate-dialog]');
            const generateForm = document.querySelector('[data-generate-form]');
            const loadGeneratePreviewButton = document.querySelector('[data-load-generate-preview]');
            const submitGeneratedProductsButton = document.querySelector('[data-submit-generated-products]');
            const generatePreview = document.querySelector('[data-generate-preview]');
            const generateSummary = document.querySelector('[data-generate-summary]');
            const generatePreviewBody = document.querySelector('[data-generate-preview-body]');
            const selectAllPreviewButton = document.querySelector('[data-select-all-preview]');
            const unselectAllPreviewButton = document.querySelector('[data-unselect-all-preview]');
            const sortConditionButton = document.querySelector('[data-sort-condition]');
            const sortConditionIcon = document.querySelector('[data-sort-condition-icon]');
            const photos = document.querySelector('[data-part-photos]');
            const warehouseSelect = document.querySelector('[data-part-warehouse]');
            const floorWrap = document.querySelector('[data-part-floor-wrap]');
            const floorSelect = document.querySelector('[data-part-floor]');
            const productSearchRoot = document.querySelector('[data-product-search-url]');
            const productNameInput = productSearchRoot?.querySelector('[data-product-name-input]');
            const productSuggestions = productSearchRoot?.querySelector('[data-product-suggestions]');
            const photoLightbox = document.querySelector('[data-photo-lightbox]');
            const photoImage = photoLightbox?.querySelector('[data-photo-lightbox-image]');
            const photoCounter = photoLightbox?.querySelector('[data-photo-counter]');
            const photoPrev = photoLightbox?.querySelector('[data-photo-prev]');
            const photoNext = photoLightbox?.querySelector('[data-photo-next]');
            const photoClose = photoLightbox?.querySelector('[data-close-photo-lightbox]');
            const donorPhotosInput = document.querySelector('[data-donor-photos-input]');
            const donorPhotoDropzone = document.querySelector('[data-donor-photo-dropzone]');
            const productPhotoLightbox = document.querySelector('[data-donor-product-photo-lightbox]');
            const productPhotoImage = productPhotoLightbox?.querySelector('[data-donor-product-photo-lightbox-image]');
            const productPhotoCounter = productPhotoLightbox?.querySelector('[data-donor-product-photo-counter]');
            const productPhotoClose = productPhotoLightbox?.querySelector('[data-donor-product-photo-close]');
            const productPhotoPrev = productPhotoLightbox?.querySelector('[data-donor-product-photo-prev]');
            const productPhotoNext = productPhotoLightbox?.querySelector('[data-donor-product-photo-next]');
            const productPhotoRotateButtons = Array.from(productPhotoLightbox?.querySelectorAll('[data-donor-product-photo-rotate]') || []);
            const photoUrls = @json($donorPhotoUrls ?? []);
            const csrfToken = @json(csrf_token());
            const donorPhotoLimit = @json(\App\Models\DonorCar::PHOTO_LIMIT);
            let currentPhotoIndex = 0;
            let currentProductPhotoIndex = 0;
            let currentProductPhotoUrls = [];
            let currentProductPhotoPaths = [];
            let currentProductPhotoRotateUrl = '';
            let currentProductPhotoTrigger = null;
            let productSearchTimeout = null;
            let productSearchController = null;
            let generatePreviewLoaded = false;
            let generatePreviewSummary = {};
            let generatePreviewItems = [];
            let conditionSortDirection = null;
            let activeDonorProductsTab = 'all';
            let donorProductsServerTab = 'all';
            let donorSalesLoaded = false;
            let donorSalesController = null;
            let donorToastTimeout = null;

            document.querySelectorAll('[data-donor-products-tab]').forEach((button) => {
                button.addEventListener('click', () => {
                    const tab = button.dataset.donorProductsTab;
                    activeDonorProductsTab = tab || 'all';

                    document.querySelectorAll('[data-donor-products-tab]').forEach((tabButton) => {
                        tabButton.classList.toggle('is-active', tabButton === button);
                    });

                    document.querySelectorAll('[data-donor-products-panel]').forEach((panel) => {
                        const directPanelTabs = ['small', 'sold'];
                        panel.hidden = directPanelTabs.includes(activeDonorProductsTab)
                            ? panel.dataset.donorProductsPanel !== activeDonorProductsTab
                            : panel.dataset.donorProductsPanel !== 'all';
                    });

                    if (activeDonorProductsTab === 'sold') {
                        loadDonorSales();
                    } else {
                        refreshDonorProductsTable(1);
                    }
                });
            });

            const donorProductsSearch = document.querySelector('[data-donor-products-search]');
            const donorProductsCategory = document.querySelector('[data-donor-products-category]');
            const donorProductsCategoryToggle = donorProductsCategory?.querySelector('[data-donor-products-category-toggle]');
            const donorProductsCategoryMenu = donorProductsCategory?.querySelector('[data-donor-products-category-menu]');
            const donorProductsCategorySummary = donorProductsCategory?.querySelector('[data-donor-products-category-summary]');
            const donorProductsCategoryOptions = Array.from(donorProductsCategory?.querySelectorAll('[data-donor-products-category-option]') || []);
            const normalizeSearch = (value) => (value || '').toString().trim().toLocaleLowerCase();
            const selectedDonorProductCategories = () => donorProductsCategoryOptions
                .filter((option) => option.checked)
                .map((option) => option.value);
            const closeDonorProductsCategoryMenu = () => {
                if (!donorProductsCategoryMenu || !donorProductsCategoryToggle) {
                    return;
                }

                donorProductsCategoryMenu.hidden = true;
                donorProductsCategoryToggle.setAttribute('aria-expanded', 'false');
            };
            const updateDonorProductsCategorySummary = () => {
                if (!donorProductsCategorySummary) {
                    return;
                }

                const selectedOptions = donorProductsCategoryOptions.filter((option) => option.checked);

                if (selectedOptions.length === 0) {
                    donorProductsCategorySummary.textContent = 'Все категории';
                    return;
                }

                donorProductsCategorySummary.textContent = selectedOptions.length === 1
                    ? (selectedOptions[0].dataset.categoryLabel || selectedOptions[0].closest('label')?.textContent?.trim() || 'Категория')
                    : `${selectedOptions.length} выбрано`;
            };
            const refreshDonorProductRowStripes = (panel) => {
                let visibleIndex = 0;

                panel.querySelectorAll('[data-donor-product-row]').forEach((row) => {
                    if (row.hidden) {
                        row.classList.remove('is-striped');
                        return;
                    }

                    visibleIndex += 1;
                    row.classList.toggle('is-striped', visibleIndex % 2 === 0);
                });
            };
            const refreshDonorProductsPanel = (panel, query, categories) => {
                const rows = Array.from(panel.querySelectorAll('[data-donor-product-row]'));
                let visibleCount = 0;
                const panelName = panel.dataset.donorProductsPanel || '';

                rows.forEach((row) => {
                    const haystack = normalizeSearch(row.dataset.donorProductSearch);
                    const rowCategory = row.dataset.donorProductCategory || '';
                    const rowState = row.dataset.donorProductState || 'all';
                    const matchesQuery = query === '' || haystack.includes(query);
                    const matchesCategory = categories.length === 0 || categories.includes(rowCategory);
                    const matchesTab = panelName !== 'all'
                        || activeDonorProductsTab === 'all'
                        || rowState === activeDonorProductsTab;
                    const isVisible = matchesQuery && matchesCategory && matchesTab;
                    row.hidden = !isVisible;

                    if (isVisible) {
                        visibleCount += 1;
                    }
                });


                const hasRows = rows.length > 0;
                const emptyRow = panel.querySelector('[data-donor-products-empty]');
                if (emptyRow) {
                    emptyRow.hidden = (query === '' && categories.length === 0) || visibleCount > 0;
                }

                panel.querySelectorAll('[data-donor-products-static-empty]').forEach((row) => {
                    row.hidden = query !== '' || categories.length > 0 || hasRows;
                });

                refreshDonorProductRowStripes(panel);
            };
            const updateDonorProductsTabCounts = () => {
                const query = normalizeSearch(donorProductsSearch?.value);
                const categories = selectedDonorProductCategories();
                const rowsForTab = (tab) => {
                    const panel = tab === 'small'
                        ? document.querySelector('[data-donor-products-panel="small"]')
                        : document.querySelector('[data-donor-products-panel="all"]');

                    return Array.from(panel?.querySelectorAll('[data-donor-product-row]') || []);
                };

                ['all', 'checked', 'broken', 'small'].forEach((tab) => {
                    const count = rowsForTab(tab)
                        .filter((row) => {
                            const haystack = normalizeSearch(row.dataset.donorProductSearch);
                            const rowCategory = row.dataset.donorProductCategory || '';
                            const rowState = row.dataset.donorProductState || 'all';

                            return (tab === 'all' || tab === 'small' || rowState === tab)
                                && (query === '' || haystack.includes(query))
                                && (categories.length === 0 || categories.includes(rowCategory));
                        })
                        .length;
                    const badge = document.querySelector(`[data-donor-products-tab-count="${tab}"]`);

                    if (badge) {
                        badge.textContent = count.toString();
                    }
                });
            };
            const applyDonorProductsSearch = () => {
                const query = normalizeSearch(donorProductsSearch?.value);
                const categories = selectedDonorProductCategories();
                updateDonorProductsCategorySummary();

                document.querySelectorAll('[data-donor-products-panel]').forEach((panel) => {
                    refreshDonorProductsPanel(panel, query, categories);
                });

            };

            const updateDonorProductsBadgesFromPayload = (payload) => {
                const totals = {
                    all: payload.total,
                    checked: payload.checked_total,
                    broken: payload.broken_total,
                };

                Object.entries(totals).forEach(([tab, value]) => {
                    const badge = document.querySelector(`[data-donor-products-tab-count="${tab}"]`);

                    if (badge && value !== undefined && value !== null) {
                        badge.textContent = value.toString();
                    }
                });
            };

            const bindDonorProductsTableInteractions = () => {
                document.querySelectorAll('[data-donor-damage-select]').forEach((select) => bindDonorDamageSelect(select));
                bindSmallPartForms();
                bindDonorPriceEditors();
            };

            const refreshDonorProductsTable = async (page = 1) => {
                if (activeDonorProductsTab === 'sold') {
                    return;
                }

                const url = donorProductsSearch?.dataset.donorProductsTableUrl;
                const tableWrap = document.querySelector('[data-donor-products-table-wrap]');
                const paginationWrap = document.querySelector('[data-donor-products-pagination]');

                if (!url || !tableWrap) {
                    applyDonorProductsSearch();
                    return;
                }

                if (productSearchController) {
                    productSearchController.abort();
                }

                productSearchController = new AbortController();
                tableWrap.setAttribute('aria-busy', 'true');

                try {
                    const requestUrl = new URL(url, window.location.origin);
                    requestUrl.searchParams.set('q', donorProductsSearch?.value || '');
                    requestUrl.searchParams.set('page', page.toString());
                    requestUrl.searchParams.set('tab', activeDonorProductsTab);

                    const response = await fetch(requestUrl.toString(), {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: productSearchController.signal,
                    });
                    const payload = await response.json();

                    if (!response.ok || typeof payload.html !== 'string') {
                        throw new Error(payload.message || 'Не удалось загрузить таблицу запчастей.');
                    }

                    tableWrap.innerHTML = payload.html;
                    if (paginationWrap) {
                        paginationWrap.innerHTML = payload.pagination_html || '';
                    }

                    donorProductsServerTab = payload.tab || activeDonorProductsTab;
                    updateDonorProductsBadgesFromPayload(payload);
                    bindDonorProductsTableInteractions();
                    applyDonorProductsSearch();
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        showDonorToast(error.message || 'Не удалось загрузить таблицу запчастей.');
                    }
                } finally {
                    tableWrap.removeAttribute('aria-busy');
                }
            };

            const loadDonorSales = async () => {
                const wrap = document.querySelector('[data-donor-sales-table-wrap]');
                const url = wrap?.dataset.donorSalesTableUrl;

                if (!wrap || !url || donorSalesLoaded) {
                    applyDonorProductsSearch();
                    return;
                }

                if (donorSalesController) {
                    donorSalesController.abort();
                }

                donorSalesController = new AbortController();
                wrap.setAttribute('aria-busy', 'true');

                try {
                    const response = await fetch(url, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: donorSalesController.signal,
                    });
                    const payload = await response.json();

                    if (!response.ok || typeof payload.html !== 'string') {
                        throw new Error(payload.message || 'Не удалось загрузить проданные запчасти.');
                    }

                    wrap.innerHTML = payload.html;
                    donorSalesLoaded = true;
                    applyDonorProductsSearch();
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        showDonorToast(error.message || 'Не удалось загрузить проданные запчасти.');
                    }
                } finally {
                    wrap.removeAttribute('aria-busy');
                }
            };

            donorProductsSearch?.addEventListener('input', () => {
                window.clearTimeout(productSearchTimeout);
                productSearchTimeout = window.setTimeout(() => refreshDonorProductsTable(1), 250);
            });
            donorProductsCategoryOptions.forEach((option) => option.addEventListener('change', applyDonorProductsSearch));
            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-donor-products-page]');

                if (!button) {
                    return;
                }

                event.preventDefault();
                refreshDonorProductsTable(Number(button.dataset.donorProductsPage || 1));
            });
            donorProductsCategoryToggle?.addEventListener('click', () => {
                if (!donorProductsCategoryMenu) {
                    return;
                }

                const shouldOpen = donorProductsCategoryMenu.hidden;
                donorProductsCategoryMenu.hidden = !shouldOpen;
                donorProductsCategoryToggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            });
            document.addEventListener('click', (event) => {
                if (!donorProductsCategory?.contains(event.target)) {
                    closeDonorProductsCategoryMenu();
                }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeDonorProductsCategoryMenu();
                }
            });
            updateDonorProductsCategorySummary();
            document.querySelectorAll('[data-donor-products-panel]').forEach((panel) => refreshDonorProductRowStripes(panel));
            if (window.location.hash.startsWith('#sold-part-')) {
                document.querySelector('[data-donor-products-tab="sold"]')?.click();
                window.requestAnimationFrame(() => {
                    document.querySelector(window.location.hash)?.scrollIntoView({ block: 'center' });
                });
            }

            const donorProductsTabForDamage = (value) => {
                const damage = normalizeSearch(value);

                if (damage === '' || damage === normalizeSearch('Неизвестно')) {
                    return 'all';
                }

                return [normalizeSearch('Разбит'), normalizeSearch('Неликвид')].includes(damage) ? 'broken' : 'checked';
            };
            const donorProductRowSelector = (productId) => `[data-donor-product-row][data-donor-product-id="${CSS.escape(productId)}"]`;
            const donorProductRowsFor = (row) => {
                const productId = row?.dataset.donorProductId || '';

                return productId ? Array.from(document.querySelectorAll(donorProductRowSelector(productId))) : [row].filter(Boolean);
            };
            const showDonorToast = (message) => {
                let toast = document.querySelector('[data-donor-toast]');

                if (!toast) {
                    toast = document.createElement('div');
                    toast.className = 'donor-toast';
                    toast.dataset.donorToast = '1';
                    toast.setAttribute('role', 'status');
                    toast.setAttribute('aria-live', 'polite');
                    document.body.appendChild(toast);
                }

                toast.textContent = message;
                window.clearTimeout(donorToastTimeout);
                requestAnimationFrame(() => toast.classList.add('is-visible'));
                donorToastTimeout = window.setTimeout(() => {
                    toast.classList.remove('is-visible');
                }, 2000);
            };
            const adjustDonorSmallPartsCount = (delta) => {
                const link = document.querySelector('a[href*="/small-parts"]');

                if (!link) {
                    return;
                }

                const currentMatch = link.textContent.match(/(\d+)\s*$/);
                const current = currentMatch ? Number.parseInt(currentMatch[1], 10) : 0;
                const next = Math.max(0, (Number.isFinite(current) ? current : 0) + delta);
                link.textContent = link.textContent.replace(/\d+\s*$/, next.toString());
            };
            const bindSmallPartForms = () => {
                document.querySelectorAll('[data-donor-small-part-form]').forEach((form) => {
                    if (form.dataset.boundSmallPartAjax === '1') {
                        return;
                    }

                    form.dataset.boundSmallPartAjax = '1';
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        const button = form.querySelector('button[type="submit"]');
                        const row = form.closest('[data-donor-product-row]');
                        button?.setAttribute('disabled', 'disabled');

                        try {
                            const response = await fetch(form.action, {
                                method: form.method || 'POST',
                                body: new FormData(form),
                                headers: {
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });
                            const payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                throw new Error(payload.message || 'Не удалось перенести запчасть в Мелочевку.');
                            }

                            const productIds = Array.isArray(payload.affected_product_ids) && payload.affected_product_ids.length
                                ? payload.affected_product_ids.map((id) => id.toString())
                                : [row?.dataset.donorProductId].filter(Boolean);
                            let removedCount = 0;

                            productIds.forEach((productId) => {
                                document.querySelectorAll(donorProductRowSelector(productId)).forEach((productRow) => {
                                    if (productRow.closest('[data-donor-products-panel="all"]')) {
                                        productRow.remove();
                                        removedCount += 1;
                                    }
                                });
                            });

                            adjustDonorSmallPartsCount(removedCount || 1);
                            applyDonorProductsSearch();
                            showDonorToast(payload.message || 'Запчасть перенеслась в Мелочевку.');
                        } catch (error) {
                            showDonorToast(error.message || 'Не удалось перенести запчасть в Мелочевку.');
                            button?.removeAttribute('disabled');
                        }
                    });
                });
            };
            const updateDonorProductDamageRows = (sourceRow, damageValue, destination) => {
                const productId = sourceRow?.dataset.donorProductId || '';
                const updateDamageSelectState = (select) => {
                    select.classList.toggle('donor-product-inline-select--checked', destination === 'checked');
                    select.classList.toggle('donor-product-inline-select--broken', destination === 'broken');
                };

                if (!productId) {
                    sourceRow?.classList.toggle('donor-product-row--checked', destination === 'checked');
                    sourceRow?.classList.toggle('donor-product-row--broken', destination === 'broken');
                    if (sourceRow) {
                        sourceRow.dataset.donorProductState = destination;
                        const sourceSelect = sourceRow.querySelector('[data-donor-damage-select]');
                        if (sourceSelect) {
                            updateDamageSelectState(sourceSelect);
                        }
                    }
                    return;
                }

                donorProductRowsFor(sourceRow).forEach((row) => {
                    row.classList.toggle('donor-product-row--checked', destination === 'checked');
                    row.classList.toggle('donor-product-row--broken', destination === 'broken');
                    row.dataset.donorProductState = destination;

                    const rowSelect = row.querySelector('[data-donor-damage-select]');
                    if (rowSelect) {
                        rowSelect.value = damageValue;
                        rowSelect.dataset.previousValue = damageValue;
                        rowSelect.disabled = false;
                        updateDamageSelectState(rowSelect);
                    }
                });
            };

            const bindDonorDamageSelect = (select) => {
                if (!select || select.dataset.donorDamageBound === '1') {
                    return;
                }

                select.dataset.donorDamageBound = '1';
                select.dataset.previousValue = select.value;

                select.addEventListener('change', async () => {
                    const form = select.closest('[data-donor-damage-form]');
                    const row = select.closest('[data-donor-product-row]');

                    if (!form || !row) {
                        form?.submit();
                        return;
                    }

                    const formData = new FormData(form);
                    select.disabled = true;

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: formData,
                        });

                        if (!response.ok) {
                            throw new Error('Не удалось сохранить статус.');
                        }

                        const responseText = await response.text();
                        let payload = {};

                        try {
                            payload = responseText ? JSON.parse(responseText) : {};
                        } catch (error) {
                            payload = {};
                        }

                        const destination = payload.destination || donorProductsTabForDamage(select.value);
                        updateDonorProductDamageRows(row, select.value, destination);
                        updateDonorProductsTabCounts();
                        applyDonorProductsSearch();
                    } catch (error) {
                        select.value = select.dataset.previousValue || '';
                        alert(error.message || 'Не удалось сохранить статус.');
                    } finally {
                        select.disabled = false;
                    }
                });
            };

            document.querySelectorAll('[data-donor-damage-select]').forEach((select) => bindDonorDamageSelect(select));
            bindSmallPartForms();

            const bindDonorPriceEditors = () => {
                document.querySelectorAll('[data-donor-price-cell]').forEach((cell) => {
                    if (cell.dataset.donorPriceBound === '1') {
                        return;
                    }

                    cell.dataset.donorPriceBound = '1';

                    const display = cell.querySelector('[data-donor-price-display]');
                    const editor = cell.querySelector('[data-donor-price-editor]');
                    const toggle = cell.querySelector('[data-donor-price-edit-toggle]');
                    const cancel = cell.querySelector('[data-donor-price-edit-cancel]');
                    const input = cell.querySelector('[data-donor-price-input]');
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
            };

            bindDonorPriceEditors();

            const markDonorProductNameManual = (nameRow) => {
                if (!nameRow) {
                    return;
                }

                nameRow.querySelectorAll('.tag').forEach((status) => status.remove());

                const manualStatus = document.createElement('span');
                manualStatus.className = 'tag';
                manualStatus.dataset.donorProductNameStatus = '';
                manualStatus.textContent = '\u0412\u0440\u0443\u0447\u043d\u0443\u044e';
                nameRow.appendChild(manualStatus);
            };

            const updateDonorProductNameRows = (payload, fallbackRow) => {
                const nameType = payload.name_type || fallbackRow?.dataset.nameType || '';
                const catalogItemId = String(payload.catalog_item_id || fallbackRow?.dataset.catalogItemId || '');
                const displayName = payload.display_name || payload.name || '';
                const selector = catalogItemId
                    ? `[data-donor-product-name-row][data-name-type="${CSS.escape(nameType)}"][data-catalog-item-id="${CSS.escape(catalogItemId)}"]`
                    : '';
                const rows = selector ? document.querySelectorAll(selector) : [fallbackRow].filter(Boolean);

                rows.forEach((nameRow) => {
                    const label = nameRow.querySelector('[data-donor-product-name-label]');
                    const form = nameRow.querySelector('[data-donor-product-name-form]');
                    const input = form?.querySelector('[data-donor-product-name-input]');

                    if (label) {
                        label.textContent = displayName || '\u2014';
                    }

                    if (input) {
                        input.value = displayName;
                    }

                    if (form) {
                        form.dataset.currentName = displayName;
                    }

                    const productRow = nameRow.closest('[data-donor-product-row]');

                    const currentSearch = productRow?.dataset.donorProductSearch || '';

                    if (productRow && displayName && !currentSearch.includes(displayName)) {
                        productRow.dataset.donorProductSearch = `${currentSearch} ${displayName}`;
                    }

                    if (payload.manual) {
                        markDonorProductNameManual(nameRow);
                    }
                });

                applyDonorProductsSearch();
            };

            document.querySelectorAll('[data-donor-product-name-edit]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const form = button.closest('[data-donor-product-name-form]');
                    const input = form?.querySelector('[data-donor-product-name-input]');
                    const currentName = form?.dataset.currentName || input?.value || '';
                    const nameTypeLabel = form?.dataset.nameTypeLabel || '';
                    const nameRow = form?.closest('[data-donor-product-name-row]');

                    if (!form || !input) {
                        return;
                    }

                    const nextName = prompt(`\u041d\u043e\u0432\u043e\u0435 ${nameTypeLabel ? nameTypeLabel + ' ' : ''}\u043d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u0437\u0430\u043f\u0447\u0430\u0441\u0442\u0438`, currentName);

                    if (nextName === null) {
                        return;
                    }

                    const trimmedName = nextName.trim();

                    if (trimmedName === '') {
                        alert('\u041d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u043d\u0435 \u043c\u043e\u0436\u0435\u0442 \u0431\u044b\u0442\u044c \u043f\u0443\u0441\u0442\u044b\u043c.');
                        return;
                    }

                    if (trimmedName === currentName.trim()) {
                        return;
                    }

                    input.value = trimmedName;
                    button.disabled = true;

                    try {
                        const formData = new FormData(form);
                        const response = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: formData,
                        });
                        const responseText = await response.text();
                        let payload = {};

                        try {
                            payload = responseText ? JSON.parse(responseText) : {};
                        } catch (error) {
                            payload = {};
                        }

                        if (!response.ok) {
                            const message = payload.message
                                || Object.values(payload.errors || {}).flat().filter(Boolean)[0]
                                || '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u043d\u0430\u0437\u0432\u0430\u043d\u0438\u0435.';
                            throw new Error(message);
                        }

                        updateDonorProductNameRows(payload, nameRow);
                    } catch (error) {
                        input.value = currentName;
                        alert(error.message || '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u043d\u0430\u0437\u0432\u0430\u043d\u0438\u0435.');
                    } finally {
                        button.disabled = false;
                    }
                });
            });

            const showPhoto = (index) => {
                if (!photoImage || !photoCounter || photoUrls.length === 0) {
                    return;
                }

                currentPhotoIndex = (index + photoUrls.length) % photoUrls.length;
                photoImage.src = photoUrls[currentPhotoIndex];
                photoCounter.textContent = `${currentPhotoIndex + 1} / ${photoUrls.length}`;

                const hasMultiplePhotos = photoUrls.length > 1;
                if (photoPrev) photoPrev.hidden = !hasMultiplePhotos;
                if (photoNext) photoNext.hidden = !hasMultiplePhotos;
            };

            const openPhoto = (index) => {
                if (!photoLightbox) {
                    return;
                }

                showPhoto(index);
                photoLightbox.showModal();
            };

            const parseJsonArray = (value) => {
                try {
                    const parsed = JSON.parse(value || '[]');

                    return Array.isArray(parsed) ? parsed : [];
                } catch (error) {
                    return [];
                }
            };

            const cacheBustedUrl = (url) => {
                if (!url) {
                    return '';
                }

                return `${url}${url.includes('?') ? '&' : '?'}v=${Date.now()}`;
            };

            const showProductPhoto = (index) => {
                if (!productPhotoImage || !productPhotoCounter || currentProductPhotoUrls.length === 0) {
                    return;
                }

                currentProductPhotoIndex = (index + currentProductPhotoUrls.length) % currentProductPhotoUrls.length;
                productPhotoImage.src = currentProductPhotoUrls[currentProductPhotoIndex];
                productPhotoCounter.textContent = `${currentProductPhotoIndex + 1} / ${currentProductPhotoUrls.length}`;

                const hasMultiplePhotos = currentProductPhotoUrls.length > 1;
                if (productPhotoPrev) productPhotoPrev.hidden = !hasMultiplePhotos;
                if (productPhotoNext) productPhotoNext.hidden = !hasMultiplePhotos;
                productPhotoRotateButtons.forEach((button) => {
                    button.hidden = !currentProductPhotoPaths[currentProductPhotoIndex] || !currentProductPhotoRotateUrl;
                });
            };

            const openProductPhoto = (trigger) => {
                if (!productPhotoLightbox) {
                    return;
                }

                currentProductPhotoUrls = parseJsonArray(trigger.dataset.productPhotoUrls);
                currentProductPhotoPaths = parseJsonArray(trigger.dataset.productPhotoPaths);
                currentProductPhotoRotateUrl = trigger.dataset.productPhotoRotateUrl || '';
                currentProductPhotoTrigger = trigger;

                if (currentProductPhotoUrls.length === 0) {
                    return;
                }

                showProductPhoto(Number(trigger.dataset.productPhotoIndex || 0));
                productPhotoLightbox.showModal();
            };

            const rotateCurrentProductPhoto = async (degrees) => {
                const photoPath = currentProductPhotoPaths[currentProductPhotoIndex] || '';

                if (!productPhotoImage || !photoPath || !currentProductPhotoRotateUrl) {
                    return;
                }

                productPhotoRotateButtons.forEach((button) => {
                    button.disabled = true;
                });

                try {
                    const response = await fetch(currentProductPhotoRotateUrl, {
                        method: 'PATCH',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ photo: photoPath, degrees }),
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        const message = payload?.message || payload?.errors?.photo?.[0] || '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u043f\u043e\u0432\u0435\u0440\u043d\u0443\u0442\u044c \u0444\u043e\u0442\u043e.';
                        throw new Error(message);
                    }

                    const updatedUrl = cacheBustedUrl(payload.url || productPhotoImage.src);
                    currentProductPhotoUrls[currentProductPhotoIndex] = updatedUrl;
                    productPhotoImage.src = updatedUrl;

                    if (currentProductPhotoTrigger) {
                        currentProductPhotoTrigger.dataset.productPhotoUrls = JSON.stringify(currentProductPhotoUrls);

                        if (currentProductPhotoIndex === Number(currentProductPhotoTrigger.dataset.productPhotoIndex || 0)) {
                            const previewImage = currentProductPhotoTrigger.querySelector('img');

                            if (previewImage) {
                                previewImage.src = cacheBustedUrl(previewImage.src);
                            }
                        }
                    }
                } catch (error) {
                    alert(error?.message || '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u043f\u043e\u0432\u0435\u0440\u043d\u0443\u0442\u044c \u0444\u043e\u0442\u043e.');
                } finally {
                    productPhotoRotateButtons.forEach((button) => {
                        button.disabled = false;
                    });
                }
            };

            const submitDonorPhotos = (files) => {
                if (!donorPhotosInput) {
                    return;
                }

                const imageFiles = Array.from(files || []).filter((file) => file.type.startsWith('image/'));
                const existingPhotoCount = Number(donorPhotosInput.dataset.existingPhotoCount || 0);
                const remainingPhotoCount = Math.max(0, donorPhotoLimit - existingPhotoCount);

                if (imageFiles.length === 0) {
                    donorPhotosInput.value = '';
                    alert('\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0444\u043e\u0442\u043e \u0432 \u0444\u043e\u0440\u043c\u0430\u0442\u0435 \u0438\u0437\u043e\u0431\u0440\u0430\u0436\u0435\u043d\u0438\u044f.');
                    return;
                }

                if (imageFiles.length > remainingPhotoCount) {
                    donorPhotosInput.value = '';
                    alert(`\u041c\u043e\u0436\u043d\u043e \u0434\u043e\u0431\u0430\u0432\u0438\u0442\u044c \u043d\u0435 \u0431\u043e\u043b\u044c\u0448\u0435 ${donorPhotoLimit} \u0444\u043e\u0442\u043e\u0433\u0440\u0430\u0444\u0438\u0439 \u043a \u043e\u0434\u043d\u043e\u043c\u0443 \u0434\u043e\u043d\u043e\u0440\u0443.`);
                    return;
                }

                if (imageFiles.length !== donorPhotosInput.files.length) {
                    const transfer = new DataTransfer();
                    imageFiles.forEach((file) => transfer.items.add(file));
                    donorPhotosInput.files = transfer.files;
                }

                donorPhotosInput.form.submit();
            };

            const syncFloorOptions = () => {
                const selectedOption = warehouseSelect?.options[warehouseSelect.selectedIndex];
                const floorCount = Math.max(1, Number(selectedOption?.dataset.floorCount || 1));
                const selectedFloor = floorSelect?.dataset.selectedFloor || 'floor_1';

                if (!floorWrap || !floorSelect) {
                    return;
                }

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

            const hideProductSuggestions = () => {
                if (!productSuggestions) return;
                productSuggestions.hidden = true;
                productSuggestions.innerHTML = '';
            };

            const selectedDamageZones = () => Array.from(generateForm?.querySelectorAll('[name="damage_zones[]"]:checked') || [])
                .map((input) => input.value);

            const statusLabel = (status) => ({
                creatable: 'Целый',
                existing: 'Уже есть',
                damaged: 'Разбит',
            })[status] || status;

            const previewCheckboxes = () => Array.from(generatePreviewBody?.querySelectorAll('.generate-preview__check:not(:disabled)') || []);

            const conditionSortRank = (item) => ({
                damaged: 1,
                creatable: 2,
                existing: 3,
            })[item.status] || 4;

            const sortedGeneratePreviewItems = () => {
                const items = [...generatePreviewItems];

                if (!conditionSortDirection) {
                    return items;
                }

                return items.sort((left, right) => {
                    const direction = conditionSortDirection === 'asc' ? 1 : -1;
                    const rankDiff = conditionSortRank(left) - conditionSortRank(right);

                    if (rankDiff !== 0) {
                        return rankDiff * direction;
                    }

                    return String(left.name || '').localeCompare(String(right.name || ''), 'ru') * direction;
                });
            };

            const renderGeneratePreviewRows = () => {
                if (!generatePreviewBody) {
                    return;
                }

                const checkedById = new Map(
                    Array.from(generatePreviewBody.querySelectorAll('.generate-preview__check'))
                        .map((checkbox) => [checkbox.value, checkbox.checked]),
                );

                generatePreviewBody.innerHTML = '';
                sortedGeneratePreviewItems().forEach((item) => {
                    const row = document.createElement('tr');
                    const canCreate = (item.status === 'creatable' || item.status === 'damaged') && !item.already_generated;
                    const status = document.createElement('span');
                    status.className = `generate-status generate-status--${item.status}`;
                    status.textContent = `${item.condition_label || statusLabel(item.status)}${item.already_generated ? ' · уже есть' : ''}`;

                    row.innerHTML = `
                        <td></td>
                        <td>
                            <div class="generate-preview__name"></div>
                            <div class="generate-preview__meta"></div>
                        </td>
                        <td></td>
                        <td></td>
                        <td></td>
                    `;

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'catalog_item_ids[]';
                    checkbox.value = item.id;
                    checkbox.className = 'generate-preview__check';
                    checkbox.checked = checkedById.has(String(item.id)) ? checkedById.get(String(item.id)) : canCreate;
                    checkbox.disabled = !canCreate;
                    row.children[0].appendChild(checkbox);
                    row.querySelector('.generate-preview__name').textContent = item.name || '—';
                    row.querySelector('.generate-preview__meta').textContent = [item.source, item.model, item.reason].filter(Boolean).join(' · ');
                    row.children[2].textContent = item.part_number || '—';
                    row.children[3].textContent = item.category || '—';
                    row.children[4].appendChild(status);
                    generatePreviewBody.appendChild(row);
                });

                if (sortConditionIcon) {
                    sortConditionIcon.textContent = conditionSortDirection === 'asc' ? '^' : (conditionSortDirection === 'desc' ? 'v' : '-');
                }
            };

            const renderGeneratePreview = (payload) => {
                if (!generatePreview || !generateSummary || !generatePreviewBody) {
                    return;
                }

                const summary = payload.summary || {};
                generatePreviewSummary = summary;
                generateSummary.innerHTML = '';
                [
                    `Целых: ${summary.creatable || 0}`,
                    `Разбитых: ${summary.damaged || 0}`,
                    `Уже есть: ${summary.existing || 0}`,
                    `Всего найдено: ${summary.total || 0}`,
                ].forEach((text) => {
                    const badge = document.createElement('span');
                    badge.textContent = text;
                    generateSummary.appendChild(badge);
                });

                generatePreviewItems = payload.items || [];
                renderGeneratePreviewRows();

                generatePreview.hidden = false;
                generatePreviewLoaded = true;
                if (submitGeneratedProductsButton) {
                    submitGeneratedProductsButton.hidden = (summary.selectable || 0) === 0 && (summary.updatable || 0) === 0;
                }
            };

            const loadGeneratePreview = async () => {
                if (!generateForm || !loadGeneratePreviewButton) {
                    return;
                }

                const originalText = loadGeneratePreviewButton.textContent;
                loadGeneratePreviewButton.disabled = true;
                loadGeneratePreviewButton.textContent = 'Загружаю...';

                try {
                    const formData = new FormData();
                    formData.append('_token', generateForm.querySelector('[name="_token"]').value);
                    selectedDamageZones().forEach((zone) => formData.append('damage_zones[]', zone));

                    const response = await fetch(generateForm.dataset.previewUrl, {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: formData,
                    });

                    if (!response.ok) {
                        throw new Error('preview_failed');
                    }

                    renderGeneratePreview(await response.json());
                } catch (error) {
                    alert('Не удалось загрузить превью генерации.');
                } finally {
                    loadGeneratePreviewButton.disabled = false;
                    loadGeneratePreviewButton.textContent = originalText;
                }
            };

            const setProductField = (name, value, overwrite = false) => {
                if (value === null || value === undefined || value === '') return;
                const field = dialog.querySelector(`[name="${name}"]`);
                if (!field || (!overwrite && field.value)) return;
                field.value = value;
                field.dispatchEvent(new Event('change', { bubbles: true }));
            };

            const renderProductSuggestions = (products) => {
                if (!productSuggestions) return;
                productSuggestions.innerHTML = '';

                if (!products.length) {
                    const empty = document.createElement('div');
                    empty.className = 'product-suggestion-empty';
                    empty.textContent = '\u041d\u0456\u0447\u043e\u0433\u043e \u043d\u0435 \u0437\u043d\u0430\u0439\u0434\u0435\u043d\u043e';
                    productSuggestions.appendChild(empty);
                    productSuggestions.hidden = false;
                    return;
                }

                products.forEach((product) => {
                    const button = document.createElement('button');
                    const meta = [
                        product.sku ? `SKU: ${product.sku}` : null,
                        product.category_name,
                        product.brand_name,
                        product.model,
                    ].filter(Boolean).join(' \u00b7 ');

                    button.type = 'button';
                    button.className = 'product-suggestion';
                    button.innerHTML = `
                        <span class="product-suggestion-title"></span>
                        <span class="product-suggestion-meta"></span>
                    `;
                    button.querySelector('.product-suggestion-title').textContent = product.name;
                    button.querySelector('.product-suggestion-meta').textContent = meta || '\u00a0';
                    button.addEventListener('click', () => {
                        productNameInput.value = product.name;
                        setProductField('external_sku', product.external_sku);
                        setProductField('damage_note', product.notes, true);
                        setProductField('color', product.color, true);
                        setProductField('description', product.description, true);
                        setProductField('selling_price', product.selling_price, true);
                        hideProductSuggestions();
                    });
                    productSuggestions.appendChild(button);
                });

                productSuggestions.hidden = false;
            };

            productNameInput?.addEventListener('input', () => {
                const query = productNameInput.value.trim();
                clearTimeout(productSearchTimeout);

                if (query.length < 2) {
                    hideProductSuggestions();
                    return;
                }

                productSearchTimeout = setTimeout(async () => {
                    if (productSearchController) productSearchController.abort();
                    productSearchController = new AbortController();

                    try {
                        const response = await fetch(`${productSearchRoot.dataset.productSearchUrl}?q=${encodeURIComponent(query)}`, {
                            headers: { Accept: 'application/json' },
                            signal: productSearchController.signal,
                        });

                        if (!response.ok) return;
                        renderProductSuggestions(await response.json());
                    } catch (error) {
                        if (error.name !== 'AbortError') hideProductSuggestions();
                    }
                }, 220);
            });

            openButton?.addEventListener('click', () => dialog.showModal());
            closeButtons.forEach((button) => button.addEventListener('click', () => dialog.close()));
            openGenerateButton?.addEventListener('click', () => generateDialog.showModal());
            closeGenerateButtons.forEach((button) => button.addEventListener('click', () => generateDialog.close()));
            loadGeneratePreviewButton?.addEventListener('click', loadGeneratePreview);
            sortConditionButton?.addEventListener('click', () => {
                conditionSortDirection = conditionSortDirection === 'asc' ? 'desc' : 'asc';
                renderGeneratePreviewRows();
            });
            generateForm?.querySelectorAll('[name="damage_zones[]"]').forEach((input) => {
                input.addEventListener('change', () => {
                    generatePreviewLoaded = false;
                    if (generatePreview) generatePreview.hidden = true;
                    if (submitGeneratedProductsButton) submitGeneratedProductsButton.hidden = true;
                    if (generatePreviewBody) generatePreviewBody.innerHTML = '';
                    generatePreviewSummary = {};
                    generatePreviewItems = [];
                });
            });
            selectAllPreviewButton?.addEventListener('click', () => {
                previewCheckboxes().forEach((checkbox) => {
                    checkbox.checked = true;
                });
            });
            unselectAllPreviewButton?.addEventListener('click', () => {
                previewCheckboxes().forEach((checkbox) => {
                    checkbox.checked = false;
                });
            });
            generateForm?.addEventListener('submit', (event) => {
                if (!generatePreviewLoaded) {
                    event.preventDefault();
                    loadGeneratePreview();
                    return;
                }

                if (!previewCheckboxes().some((checkbox) => checkbox.checked) && (generatePreviewSummary.updatable || 0) === 0) {
                    event.preventDefault();
                    alert('Выберите хотя бы один товар для создания.');
                }
            });
            document.querySelectorAll('[data-donor-photo-trigger]').forEach((photoLink) => {
                photoLink.addEventListener('click', (event) => {
                    event.preventDefault();
                    openPhoto(Number(photoLink.dataset.photoIndex || 0));
                });
            });
            document.querySelectorAll('[data-donor-photo-delete-form]').forEach((form) => {
                form.addEventListener('submit', (event) => {
                    if (! window.confirm('\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u044d\u0442\u043e \u0444\u043e\u0442\u043e?')) {
                        event.preventDefault();
                    }
                });
            });
            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-product-photo-trigger]');

                if (!trigger) {
                    return;
                }

                event.preventDefault();
                openProductPhoto(trigger);
            });
            photoClose?.addEventListener('click', () => photoLightbox.close());
            photoPrev?.addEventListener('click', () => showPhoto(currentPhotoIndex - 1));
            photoNext?.addEventListener('click', () => showPhoto(currentPhotoIndex + 1));
            photoLightbox?.addEventListener('click', (event) => {
                if (event.target === photoLightbox) photoLightbox.close();
            });
            photoLightbox?.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft') showPhoto(currentPhotoIndex - 1);
                if (event.key === 'ArrowRight') showPhoto(currentPhotoIndex + 1);
            });
            productPhotoClose?.addEventListener('click', () => productPhotoLightbox.close());
            productPhotoPrev?.addEventListener('click', () => showProductPhoto(currentProductPhotoIndex - 1));
            productPhotoNext?.addEventListener('click', () => showProductPhoto(currentProductPhotoIndex + 1));
            productPhotoRotateButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    rotateCurrentProductPhoto(Number(button.dataset.donorProductPhotoRotateDegrees || 90));
                });
            });
            productPhotoLightbox?.addEventListener('click', (event) => {
                if (event.target === productPhotoLightbox) productPhotoLightbox.close();
            });
            productPhotoLightbox?.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft') showProductPhoto(currentProductPhotoIndex - 1);
                if (event.key === 'ArrowRight') showProductPhoto(currentProductPhotoIndex + 1);
            });
            donorPhotosInput?.addEventListener('change', () => {
                if (donorPhotosInput.files.length === 0) {
                    return;
                }

                submitDonorPhotos(donorPhotosInput.files);
            });
            donorPhotoDropzone?.addEventListener('dragenter', (event) => {
                event.preventDefault();
                donorPhotoDropzone.classList.add('is-dragover');
            });
            donorPhotoDropzone?.addEventListener('dragover', (event) => {
                event.preventDefault();
                donorPhotoDropzone.classList.add('is-dragover');
            });
            donorPhotoDropzone?.addEventListener('dragleave', (event) => {
                if (!donorPhotoDropzone.contains(event.relatedTarget)) {
                    donorPhotoDropzone.classList.remove('is-dragover');
                }
            });
            donorPhotoDropzone?.addEventListener('drop', (event) => {
                event.preventDefault();
                donorPhotoDropzone.classList.remove('is-dragover');

                const transfer = new DataTransfer();
                Array.from(event.dataTransfer?.files || [])
                    .filter((file) => file.type.startsWith('image/'))
                    .forEach((file) => transfer.items.add(file));

                donorPhotosInput.files = transfer.files;
                submitDonorPhotos(transfer.files);
            });
            warehouseSelect?.addEventListener('change', () => {
                floorSelect.dataset.selectedFloor = '';
                syncFloorOptions();
            });
            photos?.addEventListener('change', () => {
                if (photos.files.length > 5) {
                    photos.value = '';
                    alert('\u041c\u043e\u0436\u043d\u043e \u0434\u043e\u0431\u0430\u0432\u0438\u0442\u044c \u043d\u0435 \u0431\u043e\u043b\u044c\u0448\u0435 5 \u0444\u043e\u0442\u043e.');
                }
            });

            document.addEventListener('click', (event) => {
                if (productSearchRoot && !productSearchRoot.contains(event.target)) hideProductSuggestions();
            });
            productNameInput?.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') hideProductSuggestions();
            });

            syncFloorOptions();

            @if($errors->any() && old('name'))
                dialog.showModal();
            @endif
        })();
    </script>
@endsection
