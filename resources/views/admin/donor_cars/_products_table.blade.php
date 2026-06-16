<table @class(['donor-products-table', 'donor-products-table--official' => $showOfficialFields ?? false])>
    @php
        $showOfficialFields = (bool) ($showOfficialFields ?? false);
        $damageOptions = $damageOptions ?? [];
        $catalogNameSourcesByItemId = $catalogNameSourcesByItemId ?? collect();
        $donorProductReservations = $donorProductReservations ?? collect();
        $showProductAnchors = (bool) ($showProductAnchors ?? false);
        $donorPartPresenter = $donorPartPresenter ?? app(\App\View\Admin\DonorCars\DonorPartDisplayPresenter::class);
        $officialTeslaCatalogNamesByProductId = $officialTeslaCatalogNamesByProductId ?? collect();
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
        $productQuantityText = function ($product): string {
            $quantity = round((float) $product->stockItems->sum('quantity'), 3);
            $formatted = rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');

            return $formatted !== '' ? $formatted : '0';
        };
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
        <th><a href="{{ $productSortUrl('photo') }}">&#1060;&#1086;&#1090;&#1086;{{ $productSortMark('photo') }}</a></th>
        <th><a href="{{ $productSortUrl('external_sku') }}">&#1040;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;{{ $productSortMark('external_sku') }}</a></th>
        <th><a href="{{ $productSortUrl('name') }}">&#1053;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1077;{{ $productSortMark('name') }}</a></th>
        <th><a href="{{ $productSortUrl('category') }}">&#1050;&#1072;&#1090;&#1077;&#1075;&#1086;&#1088;&#1080;&#1103;{{ $productSortMark('category') }}</a></th>
        <th><a href="{{ $productSortUrl('condition') }}">&#1057;&#1086;&#1089;&#1090;&#1086;&#1103;&#1085;&#1080;&#1077;{{ $productSortMark('condition') }}</a></th>
        @if($showOfficialFields)
            <th><a href="{{ $productSortUrl('damage_note') }}">Статус{{ $productSortMark('damage_note') }}</a></th>
        @endif
        <th><a href="{{ $productSortUrl('tesla_price') }}"><span class="donor-products-price-heading">Цена <span>tesla.com{{ $productSortMark('tesla_price') }}</span></span></a></th>
        <th><a href="{{ $productSortUrl('price') }}"><span class="donor-products-price-heading">Цена продажи <span>USD{{ $productSortMark('price') }}</span></span></a></th>
        <th><a href="{{ $productSortUrl('quantity') }}">Кол-во{{ $productSortMark('quantity') }}</a></th>
        <th><a href="{{ $productSortUrl('warehouse') }}">&#1057;&#1082;&#1083;&#1072;&#1076;{{ $productSortMark('warehouse') }}</a></th>
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
                $stockItem = $product->stockItems->first();
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
                $isAutoGeneratedProduct = (bool) $product->is_auto_generated || $product->generated_at !== null;
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
            >
                <td>
                    @if($product->main_image)
                        <span class="photo-presence--yes" title="&#1045;&#1089;&#1090;&#1100;" aria-label="&#1045;&#1089;&#1090;&#1100;">&#10003;</span>
                    @else
                        &mdash;
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
                                        <button type="button" class="donor-product-name-edit" title="&#1056;&#1077;&#1076;&#1072;&#1082;&#1090;&#1080;&#1088;&#1086;&#1074;&#1072;&#1090;&#1100; {{ $nameRow['label'] }} &#1085;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1077;" aria-label="&#1056;&#1077;&#1076;&#1072;&#1082;&#1090;&#1080;&#1088;&#1086;&#1074;&#1072;&#1090;&#1100; {{ $nameRow['label'] }} &#1085;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1077; {{ $nameRow['value'] }}" data-donor-product-name-edit>&#9998;</button>
                                    </form>
                                @endif
                                @if($nameRow['manual'] ?? false)
                                    <span class="tag" data-donor-product-name-status>&#1042;&#1088;&#1091;&#1095;&#1085;&#1091;&#1102;</span>
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
                        <div class="help">&#1053;&#1077; &#1088;&#1072;&#1079;&#1086;&#1073;&#1088;&#1072;&#1085;&#1086; &middot; &#1085;&#1072; &#1076;&#1086;&#1085;&#1086;&#1088;&#1077;</div>
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
                                class="donor-product-price-icon"
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
                <td>{{ $productQuantityText($product) }}</td>
                <td>
                    @if($workOrder && $workOrderPartStatus)
                        <span class="tag {{ $workOrderPartStatus['class'] }}">{{ $workOrderPartStatus['label'] }}</span>
                        <div class="help">
                            Заказ-наряд:
                            <a href="{{ route('admin.sto-work-orders.show', $workOrder) }}">{{ $workOrder->number }}</a>
                        </div>
                    @else
                        {{ $stockItem?->warehouse?->name ?? $product->storage_status_label }}
                        @if($reservationQuantity > 0)
                            <div class="help">
                                <span class="tag tag-warning">&#1074; &#1088;&#1077;&#1079;&#1077;&#1088;&#1074;&#1077;</span>
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
                    <a class="btn btn-secondary" href="{{ route('admin.products.edit', $product) }}">&#1048;&#1079;&#1084;&#1077;&#1085;&#1080;&#1090;&#1100;</a>
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
    <tr data-donor-products-empty hidden><td colspan="{{ $showOfficialFields ? 11 : 10 }}" class="empty">По этому поиску запчасти не найдены.</td></tr>
    </tbody>
</table>
