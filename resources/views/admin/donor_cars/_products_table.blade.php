<table @class(['donor-products-table', 'donor-products-table--official' => $showOfficialFields ?? false])>
    @php
        $showOfficialFields = (bool) ($showOfficialFields ?? false);
        $damageOptions = $damageOptions ?? [];
        $catalogNameSourcesByItemId = $catalogNameSourcesByItemId ?? collect();
        $donorProductReservations = $donorProductReservations ?? collect();
        $showProductAnchors = (bool) ($showProductAnchors ?? false);
        $donorPartPresenter = $donorPartPresenter ?? app(\App\View\Admin\DonorCars\DonorPartDisplayPresenter::class);
        $officialTeslaCatalogNamesByProductId = $officialTeslaCatalogNamesByProductId ?? collect();
        $productSort = $productSort ?? 'price';
        $productDirection = $productDirection ?? 'desc';
        $productSortUrl = $productSortUrl ?? fn (string $field): string => route('admin.donor-cars.show', [
            'donorCar' => $donorCar,
            'product_sort' => $field,
            'product_direction' => $productSort === $field && $productDirection === 'asc' ? 'desc' : 'asc',
        ]);
        $productSortMark = $productSortMark ?? fn (string $field): string => $productSort === $field ? ($productDirection === 'asc' ? ' ^' : ' v') : '';
        $nameWithAutoBadge = $nameWithAutoBadge ?? function (?string $value): array {
            return [
                'text' => trim((string) $value),
                'is_auto' => false,
            ];
        };
        $readableCategoryPath = $readableCategoryPath ?? fn (?string $value, bool $stripNumericPrefixes = false): string => $donorPartPresenter->readableCategoryPath($value, $stripNumericPrefixes);
        $catalogCategoryForDonor = $catalogCategoryForDonor ?? fn ($catalogItem = null): ?\App\Models\PartCatalogCategory => $donorPartPresenter->categoryForDonor($donorCar, $catalogItem);
        $catalogCategoryPath = $catalogCategoryPath ?? fn (?\App\Models\PartCatalogCategory $category, string $locale = 'preferred'): string => $donorPartPresenter->categoryPath($category, $locale, true);
        $donorProductCategoryOption = $donorProductCategoryOption ?? fn ($catalogItem = null, ?string $categoryPath = null, ?string $fallbackText = null): array => $donorPartPresenter->desktopCategoryOption($donorCar, $catalogItem, $categoryPath, $fallbackText);
        $donorProductCategoryKey = $donorProductCategoryKey ?? function ($catalogItem = null, ?string $productCategorySlug = null, ?string $categoryPath = null, ?string $fallbackText = null) use ($donorProductCategoryOption): string {
            return $donorProductCategoryOption($catalogItem, $categoryPath, $fallbackText)['key'];
        };
        $undefinedCategoryLabel = $undefinedCategoryLabel ?? $donorPartPresenter->undefinedCategoryLabel();
        $unknownDamageNote = $unknownDamageNote ?? "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";
        $damageNote = $damageNote ?? function ($product) use ($unknownDamageNote): string {
            $value = trim((string) ($product->notes ?? ''));

            return preg_match('/^\?+$/', $value) ? $unknownDamageNote : $value;
        };
        $brokenDamageNote = $brokenDamageNote ?? \App\Services\NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS;
        $nonLiquidDamageNote = $nonLiquidDamageNote ?? \App\Services\NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS;
        $isUnknownDamageNote = $isUnknownDamageNote ?? fn ($product): bool => in_array($damageNote($product), ['', $unknownDamageNote], true);
        $isBrokenDamageNote = $isBrokenDamageNote ?? fn ($product): bool => in_array($damageNote($product), [$brokenDamageNote, $nonLiquidDamageNote], true);
        $isCheckedDamageNote = $isCheckedDamageNote ?? fn ($product): bool => ! $isUnknownDamageNote($product) && ! $isBrokenDamageNote($product);
        $smallPartNumbers = ($smallPartNumbers ?? collect())
            ->map(fn ($partNumber) => \App\Support\PartNumberNormalizer::normalize((string) $partNumber))
            ->filter()
            ->unique()
            ->values();
        $usdRate = $usdRate ?? app(\App\Services\ExchangeRateService::class)->displayUsdRate();
        $exchangeRateService = app(\App\Services\ExchangeRateService::class);
        $reservedQuantityText = function (float $quantity): string {
            $formatted = rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');

            return $formatted !== '' ? $formatted.' шт' : '';
        };
        $formatQuantity = function (float $quantity): string {
            $quantity = round($quantity, 3);
            $formatted = rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');

            return $formatted !== '' ? $formatted : '0';
        };
        $productWarehouseQuantityText = fn ($product): string => (isset($isCheckedDamageNote) && $isCheckedDamageNote($product))
            ? $formatQuantity((float) $product->stockItems->sum('quantity'))
            : '-';
        $salePriceDisplay = function (mixed $price, ?string $currency = 'USD') use ($exchangeRateService, $usdRate): array {
            $price = $price !== null ? round((float) $price, 2) : null;

            if ($price === null) {
                return [
                    'usd_value' => '',
                    'usd_text' => '-',
                    'uah_text' => '-',
                ];
            }

            $uah = $exchangeRateService->productSellingPriceUahRoundedToTen($price, $currency ?: 'USD', $usdRate);

            return [
                'usd_value' => number_format($price, 2, '.', ''),
                'usd_text' => number_format($price, 2, '.', ' ').' USD',
                'uah_text' => number_format($uah, 0, '.', ' ').' грн',
            ];
        };
    @endphp
    <thead>
    <tr>
        <th><a href="{{ $productSortUrl('photo') }}">Фото{{ $productSortMark('photo') }}</a></th>
        <th><a href="{{ $productSortUrl('external_sku') }}">Артикул{{ $productSortMark('external_sku') }}</a></th>
        <th><a href="{{ $productSortUrl('name') }}">Название{{ $productSortMark('name') }}</a></th>
        <th><a href="{{ $productSortUrl('category') }}">Категория{{ $productSortMark('category') }}</a></th>
        <th><a href="{{ $productSortUrl('condition') }}">Состояние{{ $productSortMark('condition') }}</a></th>
        @if($showOfficialFields)
            <th><a href="{{ $productSortUrl('damage_note') }}">Статус{{ $productSortMark('damage_note') }}</a></th>
        @endif
        <th><a href="{{ $productSortUrl('tesla_price') }}"><span class="donor-products-price-heading">Цена <span>tesla.com{{ $productSortMark('tesla_price') }}</span></span></a></th>
        <th><a href="{{ $productSortUrl('price') }}"><span class="donor-products-price-heading">Цена продажи <span>USD{{ $productSortMark('price') }}</span></span></a></th>
        <th>{{ "\u{041E}\u{0441}\u{0442}\u{0430}\u{0442}\u{043E}\u{043A}" }}</th>
        <th><a href="{{ $productSortUrl('warehouse') }}">Склад{{ $productSortMark('warehouse') }}</a></th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    @if($products->isEmpty())
        <tr data-donor-products-static-empty><td colspan="{{ $showOfficialFields ? 11 : 10 }}" class="empty">{{ $emptyText }}</td></tr>
    @else
        @foreach($products as $product)
            @php
                $brokenDamageValues = [
                    \App\Services\NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS,
                    \App\Services\NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS,
                ];
                $stockItem = $product->stockItems
                    ->filter(fn ($item): bool => (float) $item->quantity > 0)
                    ->sortByDesc(fn ($item): float => (float) $item->available_quantity)
                    ->first();
                $workOrderPart = $product->stoWorkOrderParts->first();
                $workOrder = $workOrderPart?->order;
                $reservation = $donorProductReservations->get((int) $product->id);
                $reservationQuantity = is_array($reservation ?? null) ? (float) ($reservation['quantity'] ?? 0) : 0.0;
                $reservationOrders = is_array($reservation ?? null) ? ($reservation['orders'] ?? collect()) : collect();
                $reservedStoStatuses = [
                    \App\Models\StoWorkOrder::STATUS_IN_WORK,
                    \App\Models\StoWorkOrder::STATUS_COMPLETED,
                ];
                $reservedWorkOrderPart = $product->stoWorkOrderParts
                    ->first(fn ($part) => in_array($part->order?->status, $reservedStoStatuses, true));
                $workOrderPartStatus = match ($workOrder?->status) {
                    \App\Models\StoWorkOrder::STATUS_IN_WORK, \App\Models\StoWorkOrder::STATUS_COMPLETED => ['label' => 'В резерве', 'class' => 'tag-warning'],
                    \App\Models\StoWorkOrder::STATUS_PAID => ['label' => 'Продан', 'class' => 'tag-paid'],
                    default => null,
                };
                $isReservedForDonorActions = $reservationQuantity > 0
                    || $reservedWorkOrderPart !== null;
                $canChangeFromDonorCard = ! $isReservedForDonorActions;
                $conditionTypeLabels = \App\Models\Product::CONDITION_TYPE_LABELS;
                $conditionClass = isset($isBrokenDamageNote) && $isBrokenDamageNote($product) ? 'part-condition part-condition--damaged' : 'part-condition';
                $productName = $nameWithAutoBadge($product->name);
                $productDisplayName = $productName['text'] !== '' ? $productName['text'] : $product->name;
                $catalogNameRu = $nameWithAutoBadge($product->sourcePartCatalogItem?->name_ru);
                $catalogNameUa = $nameWithAutoBadge($product->sourcePartCatalogItem?->name_ua);
                $productCategoryMirrorItem = ($nikolaCarsProductItemsByProductId ?? collect())->get((int) $product->id);
                $productCategoryCatalogItem = $productCategoryMirrorItem ?: $product->sourcePartCatalogItem;
                $productCatalogCategory = isset($catalogCategoryForDonor)
                    ? $catalogCategoryForDonor($productCategoryCatalogItem)
                    : $productCategoryCatalogItem?->category;
                $catalogRawCategoryPath = $donorPartPresenter->catalogRawCategoryPath($productCategoryCatalogItem);
                $catalogCategoryPathRu = isset($catalogCategoryPath)
                    ? $catalogCategoryPath($productCatalogCategory, 'ru')
                    : '';
                $catalogCategoryPathUa = isset($catalogCategoryPath)
                    ? $catalogCategoryPath($productCatalogCategory, 'ua')
                    : '';
                $catalogCategoryPathPreferred = isset($catalogCategoryPath)
                    ? $catalogCategoryPath($productCatalogCategory, 'preferred')
                    : '';
                $catalogNameManualLocks = $donorPartPresenter->catalogNameManualLocks($product->sourcePartCatalogItem);
                $catalogNameRuManual = $catalogNameManualLocks['ru'];
                $catalogNameUaManual = $catalogNameManualLocks['ua'];
                $catalogNameSources = $product->sourcePartCatalogItem
                    ? ($catalogNameSourcesByItemId->get($product->sourcePartCatalogItem->id) ?? [])
                    : [];
                $originBadge = $donorPartPresenter->productOriginBadge($product);
                $isAutoGeneratedProduct = $product->isProtectedAutoGeneratedDonorProduct();
                $officialTeslaNameEn = trim((string) data_get($officialTeslaCatalogNamesByProductId->get((int) $product->id), 'name_en'));
                $productNameEn = $officialTeslaNameEn !== ''
                    ? $officialTeslaNameEn
                    : trim((string) ($product->sourcePartCatalogItem?->name_en ?: $productDisplayName));
                $productNameRows = [
                    ['label' => 'EN', 'type' => 'product', 'value' => $productNameEn, 'canEdit' => false, 'source' => null, 'manual' => false, 'origin_badge' => $originBadge],
                    ['label' => 'RU', 'type' => 'name_ru', 'value' => $catalogNameRu['text'], 'canEdit' => (bool) $product->sourcePartCatalogItem && $canChangeFromDonorCard, 'source' => $catalogNameSources['ru'] ?? null],
                    ['label' => 'UA', 'type' => 'name_ua', 'value' => $catalogNameUa['text'], 'canEdit' => (bool) $product->sourcePartCatalogItem && $canChangeFromDonorCard, 'source' => $catalogNameSources['ua'] ?? null],
                ];
                $productNameRows[1]['manual'] = $catalogNameRuManual;
                $productNameRows[2]['manual'] = $catalogNameUaManual;
                $productSearchText = collect([
                    $product->sku,
                    $product->external_sku,
                    $product->name,
                    $productDisplayName,
                    $productNameEn,
                    $product->sourcePartCatalogItem?->part_number,
                    $product->sourcePartCatalogItem?->name,
                    $product->sourcePartCatalogItem?->name_ru,
                    $product->sourcePartCatalogItem?->name_ua,
                    $catalogCategoryPathRu,
                    $catalogCategoryPathUa,
                ])->filter()->implode(' ');
                $productFilterCategory = isset($donorProductCategoryKey)
                    ? $donorProductCategoryKey(
                        $productCategoryCatalogItem,
                        collect([$product->category?->slug, $product->category?->name])->filter()->implode(' '),
                        $productCategoryMirrorItem ? null : $product->category?->name,
                        $product->name
                    )
                    : '';
                $productFilterCategoryLabel = ($donorProductCategoryOptions ?? [])[$productFilterCategory] ?? '';
                $productOriginalCategoryName = isset($readableCategoryPath)
                    ? $readableCategoryPath($product->category?->name)
                    : trim((string) ($product->category?->name ?? ''));
                $productCategoryDisplay = $catalogCategoryPathPreferred !== ''
                    ? $catalogCategoryPathPreferred
                    : ($catalogRawCategoryPath !== '' ? $catalogRawCategoryPath : ($productOriginalCategoryName !== '' ? $productOriginalCategoryName : ($undefinedCategoryLabel ?? '-')));
                $canEditDamageNote = (int) $product->donor_car_id === (int) $donorCar->id && $canChangeFromDonorCard;
                $teslaCatalogPriceRow = ($officialTeslaCatalogPricesByProductId ?? collect())->get((int) $product->id);
                $teslaCatalogPrice = $teslaCatalogPriceRow['price_amount'] ?? null;
                $teslaCatalogCurrency = $teslaCatalogPriceRow['currency'] ?? 'USD';
                $teslaCatalogPriceDisplay = $teslaCatalogPrice !== null
                    ? $salePriceDisplay($teslaCatalogPrice, $teslaCatalogCurrency)
                    : null;
                $salePrice = $salePriceDisplay($product->selling_price, $product->currency ?: 'USD');
                $isCheckedProduct = isset($isCheckedDamageNote) && $isCheckedDamageNote($product);
                $isSmallProduct = (bool) data_get($product->sourcePartCatalogItem?->raw_attributes, 'donor_vin_small_part', false)
                    || $smallPartNumbers->contains(\App\Support\PartNumberNormalizer::normalize($product->external_sku ?: $product->sourcePartCatalogItem?->part_number));
                $stockIsOnDonor = $stockItem?->warehouse?->type === \App\Models\Warehouse::TYPE_DONOR;
                $stockUsesStructuredLocations = (bool) ($stockItem?->warehouse?->usesStructuredLocations() ?? true);
                $stockLocation = $stockIsOnDonor ? null : $stockItem?->location;
                $stockFloor = $stockLocation && is_string($stockLocation->floor) && $stockLocation->floor !== ''
                    ? $stockLocation->floor
                    : 'floor_1';
                $stockFloorLabel = preg_match('/^floor_(\d+)$/', $stockFloor, $floorMatches)
                    ? "Этаж {$floorMatches[1]}"
                    : ($stockLocation?->floorLabel() ?? null);
                $stockLocationCode = trim((string) ($stockLocation?->cell ?: $stockLocation?->full_code));
                $stockLocationRows = $stockIsOnDonor || ! $stockUsesStructuredLocations ? collect([
                    (string) ($stockItem?->warehouse?->name ?? $product->storage_status_label),
                ]) : collect([
                    (string) ($stockItem?->warehouse?->name ?? $product->storage_status_label),
                    $stockLocation ? $stockFloorLabel : null,
                    $stockLocationCode !== '' ? $stockLocationCode : null,
                ]);
                $stockLocationRows = $stockLocationRows
                    ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
                    ->values();
                $stockLocationLabel = $stockIsOnDonor || ! $stockUsesStructuredLocations ? (string) ($stockItem?->warehouse?->name ?? $product->storage_status_label) : collect([
                    $stockItem?->warehouse?->name ?? $product->storage_status_label,
                    $stockLocation ? $stockFloorLabel : null,
                    $stockLocationCode !== '' ? $stockLocationCode : null,
                ])->filter()->join(' · ');
                $canEditPlacement = $canChangeFromDonorCard && $isCheckedProduct;
                $productPhotoPairs = \App\Support\ProductPhotoNormalizer::productPhotos($product)
                    ->map(fn (string $photo): array => [
                        'path' => $photo,
                        'url' => \App\Support\PublicStorageUrl::url($photo),
                    ])
                    ->filter(fn (array $photo): bool => trim((string) $photo['url']) !== '')
                    ->values();
                $productPhotoPaths = $productPhotoPairs->pluck('path')->values();
                $productPhotoUrls = $productPhotoPairs->pluck('url')->values();
                $productPreviewUrl = $productPhotoUrls->first();
                $productPreviewSrc = $productPreviewUrl
                    ? route('admin.donor-cars.products.photo-preview', [$donorCar, $product, 0])
                    : null;
            @endphp
            <tr
                @if($showProductAnchors) id="part-{{ $product->id }}" @endif
                @class([
                    'donor-product-row--checked' => isset($isCheckedDamageNote) && $isCheckedDamageNote($product),
                    'donor-product-row--broken' => isset($isBrokenDamageNote) && $isBrokenDamageNote($product),
                ])
                data-donor-product-row
                data-donor-product-id="{{ $product->id }}"
                data-donor-product-search="{{ $productSearchText }}"
                data-donor-product-category="{{ $productFilterCategory }}"
                data-donor-product-state="{{ $isCheckedProduct ? 'checked' : ((isset($isBrokenDamageNote) && $isBrokenDamageNote($product)) ? 'broken' : 'all') }}"
                data-donor-placement-editable="{{ $canChangeFromDonorCard ? '1' : '0' }}"
            >
                <td>
                    @if($productPreviewUrl)
                        <a
                            class="donor-product-photo-preview"
                            href="{{ $productPreviewUrl }}"
                            title="{{ $productDisplayName }}"
                            aria-label="Открыть фото {{ $productDisplayName }}"
                            data-product-photo-trigger
                            data-product-photo-index="0"
                            data-product-photo-urls='@json($productPhotoUrls)'
                            data-product-photo-paths='@json($productPhotoPaths)'
                            data-product-photo-rotate-url="{{ route('admin.donor-cars.products.photos.rotate', [$donorCar, $product]) }}"
                        >
                            <img src="{{ $productPreviewSrc }}" alt="{{ $productDisplayName }}" loading="lazy" decoding="async">
                        </a>
                    @else
                        <span class="donor-product-photo-preview donor-product-photo-preview--empty" title="Нет фото" aria-label="Нет фото">&mdash;</span>
                    @endif
                </td>
                <td>{{ $product->external_sku ?: '-' }}</td>
                <td>
                    <div class="donor-product-names">
                        @foreach($productNameRows as $nameRow)
                            <div class="donor-product-name-row" data-donor-product-name-row data-name-type="{{ $nameRow['type'] }}" @if($product->sourcePartCatalogItem) data-catalog-item-id="{{ $product->sourcePartCatalogItem->id }}" @endif>
                                <span class="donor-product-name-label">{{ $nameRow['label'] }}</span>
                                @if($nameRow['type'] === 'product')
                                    <a href="{{ route('admin.products.show', $product) }}" data-donor-product-name-label>{{ $nameRow['value'] }}</a>
                                @else
                                    <span data-donor-product-name-label>{{ $nameRow['value'] !== '' ? $nameRow['value'] : '-' }}</span>
                                @endif
                                @if($nameRow['origin_badge'] ?? null)
                                    <span class="auto-generated-badge" title="{{ $nameRow['origin_badge']['label'] }}" aria-label="{{ $nameRow['origin_badge']['label'] }}">{{ $nameRow['origin_badge']['letter'] }}</span>
                                @endif
                                @if($nameRow['canEdit'])
                                    <form method="POST" action="{{ route('admin.donor-cars.products.name.update', [$donorCar, $product]) }}" class="donor-product-name-form" data-donor-product-name-form data-current-name="{{ $nameRow['value'] }}" data-name-type-label="{{ $nameRow['label'] }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="name_type" value="{{ $nameRow['type'] }}">
                                        <input type="hidden" name="name" value="{{ $nameRow['value'] }}" data-donor-product-name-input>
                                        <button type="button" class="donor-product-name-edit donor-product-edit-icon" title="Редактировать {{ $nameRow['label'] }} название" aria-label="Редактировать {{ $nameRow['label'] }} название {{ $nameRow['value'] }}" data-donor-product-name-edit>&#9998;</button>
                                    </form>
                                @endif
                                @if($nameRow['manual'] ?? false)
                                    <span class="tag" data-donor-product-name-status>Вручную</span>
                                @elseif(is_array($nameRow['source'] ?? null) && ($nameRow['source']['site'] ?? null))
                                    @if($nameRow['source']['url'] ?? null)
                                        <a class="tag" href="{{ $nameRow['source']['url'] }}" target="_blank" rel="noopener" data-donor-product-name-status>{{ $nameRow['source']['site'] }}</a>
                                    @else
                                        <span class="tag" data-donor-product-name-status>{{ $nameRow['source']['site'] }}</span>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if($product->storage_status === \App\Models\Product::STORAGE_STATUS_ON_DONOR)
                        <div class="help">Не разобрано &middot; на доноре</div>
                    @endif
                </td>
                <td>
                    {{ $productCategoryDisplay }}
                </td>
                <td>
                    <span class="{{ $conditionClass }}">
                        {{ $conditionTypeLabels[$product->condition_type] ?? $product->condition_type ?? '-' }}
                    </span>
                </td>
                @if($showOfficialFields)
                    <td>
                        @if($canEditDamageNote)
                            <form method="POST" action="{{ route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]) }}" class="donor-product-inline-form" data-donor-damage-form>
                                @csrf
                                @method('PATCH')
                                <select
                                    name="damage_note"
                                    @class([
                                        'donor-product-inline-select',
                                        'donor-product-inline-select--checked' => isset($isCheckedDamageNote) && $isCheckedDamageNote($product),
                                        'donor-product-inline-select--broken' => isset($isBrokenDamageNote) && $isBrokenDamageNote($product),
                                    ])
                                    data-donor-damage-select
                                    data-previous-damage-note="{{ $damageNote($product) }}"
                                >
                                    @foreach($damageOptions as $value => $label)
                                        <option
                                            value="{{ $value }}"
                                            @class([
                                                'donor-product-damage-option--checked' => (string) $value !== '' && ! in_array((string) $value, $brokenDamageValues, true),
                                                'donor-product-damage-option--broken' => in_array((string) $value, $brokenDamageValues, true),
                                            ])
                                            @selected((isset($damageNote) ? $damageNote($product) : (string) ($product->notes ?? '')) === (string) $value)
                                        >{{ $label }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @else
                            {{ $product->notes ? \Illuminate\Support\Str::ucfirst($product->notes) : 'Неизвестно' }}
                        @endif
                    </td>
                @endif
                <td>
                    @if($teslaCatalogPriceDisplay !== null)
                        <span class="donor-product-catalog-price">
                            {{ $teslaCatalogPriceDisplay['uah_text'] }}
                            <small>{{ $teslaCatalogPriceDisplay['usd_text'] }}</small>
                        </span>
                    @else
                        -
                    @endif
                </td>
                <td class="donor-product-sale-price-cell" data-donor-price-cell>
                    @if($canEditDamageNote)
                        <div class="donor-product-price-display" data-donor-price-display>
                            <span data-donor-price-text>
                                {{ $salePrice['uah_text'] }}
                                <small>{{ $salePrice['usd_text'] }}</small>
                            </span>
                            <button
                                type="button"
                                class="donor-product-price-icon donor-product-edit-icon"
                                title="Редактировать цену продажи"
                                aria-label="Редактировать цену продажи"
                                data-donor-price-edit-toggle
                            >&#9998;</button>
                        </div>
                        <form method="POST" action="{{ route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]) }}" class="donor-product-price-editor" data-donor-price-editor hidden>
                            @csrf
                            @method('PATCH')
                            <input
                                type="number"
                                name="selling_price"
                                value="{{ $salePrice['usd_value'] !== '' ? $salePrice['usd_value'] : '0.00' }}"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                class="donor-product-price-input"
                                aria-label="Цена продажи"
                                data-donor-price-input
                            >
                            <button type="submit" class="donor-product-price-icon" title="Сохранить цену продажи" aria-label="Сохранить цену продажи">&#10003;</button>
                            <button type="button" class="donor-product-price-icon" title="Отменить" aria-label="Отменить" data-donor-price-edit-cancel>&#215;</button>
                        </form>
                    @else
                        <div class="donor-product-price-display">
                            <span>
                                {{ $salePrice['uah_text'] }}
                                <small>{{ $salePrice['usd_text'] }}</small>
                            </span>
                        </div>
                    @endif
                </td>
                <td>{{ $productWarehouseQuantityText($product) }}</td>
                <td data-donor-product-stock-label>
                    @if($workOrder && $workOrderPartStatus)
                        <span class="tag {{ $workOrderPartStatus['class'] }}">{{ $workOrderPartStatus['label'] }}</span>
                        <div class="help">
                            Заказ-наряд:
                            <a href="{{ route('admin.sto-work-orders.show', $workOrder) }}">{{ $workOrder->number }}</a>
                        </div>
                    @else
                        <div class="donor-product-stock-display">
                            <span class="donor-product-stock-text" data-donor-product-stock-text>
                                @foreach(($stockLocationRows->isNotEmpty() ? $stockLocationRows : collect([$stockItem?->warehouse?->name ?? $product->storage_status_label])) as $stockLocationRow)
                                    <span class="donor-product-stock-line">
                                        {{ $stockLocationRow }}
                                    </span>
                                @endforeach
                            </span>
                            @if($canEditPlacement)
                                <form method="POST" action="{{ route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]) }}" class="donor-product-placement-form" data-donor-placement-update-form>
                                    @csrf
                                    @method('PATCH')
                                    <button
                                        type="button"
                                        class="donor-product-price-icon donor-product-placement-edit donor-product-edit-icon"
                                        title="Изменить ячейку"
                                        aria-label="Изменить ячейку {{ $productDisplayName }}"
                                        data-donor-placement-edit
                                        data-current-warehouse-id="{{ $stockItem?->warehouse_id }}"
                                        data-current-floor="{{ $stockLocation ? $stockFloor : '' }}"
                                        data-current-location-id="{{ $stockItem?->location_id }}"
                                    >&#9998;</button>
                                </form>
                            @endif
                        </div>
                        @if($reservationQuantity > 0)
                            <div class="help">
                                <span class="tag tag-warning">в резерве</span>
                                {{ $reservedQuantityText($reservationQuantity) }}
                                @if($reservationOrders->count() === 1)
                                    @php($reservedOrder = $reservationOrders->first()['order'] ?? null)
                                    @if($reservedOrder instanceof \App\Models\CustomerOrder)
                                        &middot; <a href="{{ route('admin.customer-orders.show', $reservedOrder) }}">{{ $reservedOrder->number }}</a>
                                    @endif
                                @elseif($reservationOrders->isNotEmpty())
                                    &middot;
                                    @foreach($reservationOrders as $reservedOrder)
                                        @if(($reservedOrder['order'] ?? null) instanceof \App\Models\CustomerOrder)
                                            <a href="{{ route('admin.customer-orders.show', $reservedOrder['order']) }}">{{ $reservedOrder['order']->number }}</a>@if(! $loop->last), @endif
                                        @endif
                                    @endforeach
                                @endif
                            </div>
                        @endif
                    @endif
                </td>
                <td class="actions">
                    @if($canChangeFromDonorCard)
                    @if(! $isSmallProduct)
                        <form method="POST" action="{{ route('admin.donor-cars.products.small-part.store', [$donorCar, $product]) }}" class="inline-form" data-donor-small-part-form>
                            @csrf
                            <button type="submit" class="donor-product-small-part-icon" title="Добавить в мелочевку" aria-label="Добавить {{ $productDisplayName }} в мелочевку">+</button>
                        </form>
                    @else
                        <span class="tag">Мелочевка</span>
                    @endif
                    @if(! $isAutoGeneratedProduct && ! $workOrder)
                        <form method="POST" action="{{ route('admin.donor-cars.products.destroy', [$donorCar, $product]) }}" class="inline-form" onsubmit='return confirm(@json("Удалить запчасть {$product->name} с этого донора?"));'>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger">Удалить</button>
                        </form>
                    @endif
                    @else
                        <span class="tag tag-warning">В резерве</span>
                    @endif
                </td>
            </tr>
        @endforeach
    @endif
    <tr data-donor-products-empty hidden><td colspan="{{ $showOfficialFields ? 12 : 11 }}" class="empty">По этому поиску запчасти не найдены.</td></tr>
    </tbody>
</table>
