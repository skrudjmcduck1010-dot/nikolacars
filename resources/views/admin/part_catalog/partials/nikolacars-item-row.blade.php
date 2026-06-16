@php
    $item = $group['item'];
    $imageUrls = $group['image_urls'];
    $imageUrl = $imageUrls->first();
    $stockValue = $group['quantity'];
    $stockText = $group['quantity_text'];
    $reservedText = $group['reserved_quantity_text'];
    $reservedOrders = $group['reserved_orders'];
    $isReserved = (bool) $group['is_reserved'];
    $isGrouped = $group['count'] > 1;
    $isManuallySold = $catalogIsManuallySold($item);
    $cartProductId = $catalogCartProductId($item);
    $isOutOfStock = (float) $stockValue <= 0.0;
    $canUseNikolaCarsActions = ! $isReserved && ! $isManuallySold && ! $isOutOfStock;
    $hasZeroSalePrice = $group['unit_price_value'] !== null && (float) $group['unit_price_value'] === 0.0;
    $canAddToNikolaCarsCart = $canUseNikolaCarsActions
        && $group['unit_price_value'] !== null
        && (float) $group['unit_price_value'] > 0.0;
    $damages = $group['damages'];
    $damageStatusUsers = collect($group['damage_status_changed_by_ids'] ?? [])
        ->map(fn (int $userId) => $nikolaCarsDamageStatusUsersById->get($userId))
        ->filter()
        ->map(fn ($user): string => trim((string) ($user->name ?: $user->email)))
        ->filter()
        ->unique()
        ->values();
    $canEditNikolaCarsCategory = ! $isReserved
        && ! $isGrouped
        && ! $isManuallySold
        && ! $isOutOfStock
        && (
            $group['categories']->isEmpty()
            || $group['categories']->contains(fn (string $category): bool => trim($category) === $nikolaCarsUndeterminedCategory)
        );
    $nameRuValues = $group['items']
        ->pluck('name_ru')
        ->map(fn (?string $name): string => trim((string) $name))
        ->filter()
        ->unique()
        ->values();
    $primaryName = trim((string) $item->name_ua) !== ''
        ? trim((string) $item->name_ua)
        : $itemName($item);
    $groupNameUaValues = $group['items']
        ->pluck('name_ua')
        ->map(fn (?string $name): string => trim((string) $name))
        ->filter()
        ->unique()
        ->values();
    $donorColorSwatches = collect();
    if ($group['items']->contains(fn (\App\Models\PartCatalogItem $groupItem): bool => $nikolaCarsUsesDonorColorItem($groupItem))) {
        $donorColorSwatches = $group['items']
            ->map(function (\App\Models\PartCatalogItem $groupItem) use ($nikolaCarsDonorCarsByVin): array {
                $vin = \Illuminate\Support\Str::upper(trim((string) data_get($groupItem->raw_attributes, 'donor_vin', '')));
                $donorCar = $vin !== '' ? $nikolaCarsDonorCarsByVin->get($vin) : null;

                return [
                    'color' => trim((string) $donorCar?->color),
                    'paint_code' => trim((string) $donorCar?->paint_code),
                ];
            })
            ->filter(fn (array $swatch): bool => $swatch['color'] !== '')
            ->unique(fn (array $swatch): string => \Illuminate\Support\Str::lower($swatch['color']).'|'.\Illuminate\Support\Str::lower($swatch['paint_code']))
            ->values();

        if ($donorColorSwatches->isEmpty()) {
            $donorColorSwatches = $group['vins']
                ->map(function (string $vin) use ($nikolaCarsDonorCarsByVin): array {
                    $donorCar = $nikolaCarsDonorCarsByVin->get(\Illuminate\Support\Str::upper(trim((string) $vin)));

                    return [
                        'color' => trim((string) $donorCar?->color),
                        'paint_code' => trim((string) $donorCar?->paint_code),
                    ];
                })
                ->filter(fn (array $swatch): bool => $swatch['color'] !== '')
                ->unique(fn (array $swatch): string => \Illuminate\Support\Str::lower($swatch['color']).'|'.\Illuminate\Support\Str::lower($swatch['paint_code']))
                ->values();
        }

        if ($donorColorSwatches->isEmpty()) {
            $donorColorSwatches = collect($group['donor_colors'] ?? [])
                ->map(fn (?string $color): string => trim((string) $color))
                ->filter()
                ->unique(fn (string $color): string => \Illuminate\Support\Str::lower($color))
                ->map(fn (string $color): array => ['color' => $color, 'paint_code' => ''])
                ->values();
        }
    }
    $groupRowId = 'nikolacars-group-'.$item->id;
@endphp
<tr data-nikolacars-item-row data-nikolacars-parts-count="{{ (int) $group['count'] }}" @if($isGrouped) data-nikolacars-group-parent="{{ $groupRowId }}" @endif @class(['nikolacars-grouped-row' => $isGrouped, 'nikolacars-reserved-row' => $isReserved, 'nikolacars-sold-row' => $isManuallySold, 'nikolacars-zero-stock-row' => $isOutOfStock])>
    <td>
        @if($imageUrl)
            <button
                type="button"
                class="catalog-photo-preview"
                data-catalog-photo-trigger
                data-catalog-images='@json($imageUrls->all())'
                data-catalog-photo-title="{{ $itemName($item) }}"
                aria-label="Открыть фото {{ $itemName($item) }}"
            >
                <img class="table-preview" src="{{ $imageUrl }}" alt="{{ $itemName($item) }}" loading="lazy" decoding="async">
                @if($imageUrls->count() > 1)
                    <span class="catalog-photo-preview__count">{{ $imageUrls->count() }}</span>
                @endif
            </button>
        @else
            <span class="preview-placeholder">&#1085;&#1077;&#1090; &#1092;&#1086;&#1090;&#1086;</span>
        @endif
    </td>
    <td class="nikolacars-code-cell">
        {{ $group['codes']->take(3)->implode(', ') ?: '-' }}
        @if($group['codes']->count() > 3)
            <div class="help">+{{ $group['codes']->count() - 3 }}</div>
        @endif
    </td>
    <td>
        @if($isGrouped)
            <strong @class(['nikolacars-invalid-part-number' => $isInvalidNikolaCarsPartNumber($group['part_number'])])>{{ $group['part_number'] ?: '-' }}</strong>
            <span class="catalog-badge nikolacars-merged-badge">&#1054;&#1073;&#1098;&#1077;&#1076;&#1080;&#1085;&#1077;&#1085;&#1086;</span>
            <div class="help">
                {{ $group['count'] }} &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1080;
                @if($group['part_numbers']->count() > 1)
                    &#183; {{ $group['part_numbers']->count() }} &#1072;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;&#1072;
                @endif
            </div>
        @else
            <div class="nikolacars-part-number-cell" data-nikolacars-part-number-cell>
                <div class="nikolacars-part-number-display" data-nikolacars-part-number-display>
                    <span
                        @class(['nikolacars-invalid-part-number' => $isInvalidNikolaCarsPartNumber($item->part_number)])
                        data-nikolacars-part-number-text
                    >{{ $item->part_number ?: '-' }}</span>
                    @if($canUseNikolaCarsActions)
                        <button
                            type="button"
                            class="nikolacars-icon-button"
                            title="&#1056;&#1077;&#1076;&#1072;&#1082;&#1090;&#1080;&#1088;&#1086;&#1074;&#1072;&#1090;&#1100; &#1072;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;"
                            aria-label="&#1056;&#1077;&#1076;&#1072;&#1082;&#1090;&#1080;&#1088;&#1086;&#1074;&#1072;&#1090;&#1100; &#1072;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;"
                            data-nikolacars-part-number-edit-toggle
                        >&#9998;</button>
                    @endif
                </div>
                @if($canUseNikolaCarsActions)
                    <div class="nikolacars-part-number-editor" data-nikolacars-part-number-editor hidden>
                        <input
                            class="nikolacars-inline-input"
                            form="nikolacars-update-{{ $item->id }}"
                            name="part_number"
                            value="{{ $item->part_number }}"
                            placeholder="-"
                            data-nikolacars-part-number-input
                        >
                        <button
                            type="submit"
                            class="nikolacars-icon-button"
                            form="nikolacars-update-{{ $item->id }}"
                            title="&#1057;&#1086;&#1093;&#1088;&#1072;&#1085;&#1080;&#1090;&#1100; &#1072;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;"
                            aria-label="&#1057;&#1086;&#1093;&#1088;&#1072;&#1085;&#1080;&#1090;&#1100; &#1072;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;"
                        >&#10003;</button>
                        <button
                            type="button"
                            class="nikolacars-icon-button"
                            title="&#1054;&#1090;&#1084;&#1077;&#1085;&#1080;&#1090;&#1100;"
                            aria-label="&#1054;&#1090;&#1084;&#1077;&#1085;&#1080;&#1090;&#1100;"
                            data-nikolacars-part-number-edit-cancel
                        >&#215;</button>
                    </div>
                @endif
            </div>
        @endif
    </td>
    <td>
        @if($isGrouped)
            <span class="nikolacars-part-name-link">{{ $primaryName }}</span>
        @else
            <a class="nikolacars-part-name-link" href="{{ $itemUrl($item) }}">{{ $primaryName }}</a>
        @endif
        @if($nameRuValues->isNotEmpty())
            <div class="help">{!! html_entity_decode('&#1053;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1077; &#1056;&#1059;:') !!} {{ $nameRuValues->take(3)->implode(' / ') }}</div>
        @endif
        @if($group['models']->isNotEmpty())
            <div class="help">{{ html_entity_decode('&#1052;&#1086;&#1076;&#1077;&#1083;&#1100;:') }} {{ $group['models']->take(2)->implode(' / ') }}</div>
        @endif
        @if($isGrouped && $group['names']->count() > 1)
            <div class="catalog-item-names">
                @foreach($groupNameUaValues->isNotEmpty() ? $groupNameUaValues->take(3) : $group['names']->take(3) as $name)
                    <span>{{ $name }}</span>
                @endforeach
            </div>
        @endif
    </td>
    <td class="nikolacars-donor-color-cell">
        @forelse($donorColorSwatches->take(3) as $donorColorSwatch)
            @php
                $donorColor = $donorColorSwatch['color'];
                $donorPaintCode = $donorColorSwatch['paint_code'];
                $donorColorHex = $nikolaCarsColorSwatchHex($donorColor);
            @endphp
            <span class="nikolacars-donor-color">
                <span
                    class="nikolacars-donor-color__swatch"
                    style="{{ $nikolaCarsColorSwatchStyle($donorColorHex) }}"
                    title="{{ $donorColor }}"
                    aria-label="{{ $donorColor }}"
                ></span>
                @if($donorPaintCode !== '')
                    <span class="nikolacars-donor-color__label">{{ $donorPaintCode }}</span>
                @endif
            </span>
        @empty
            -
        @endforelse
    </td>
    <td class="nikolacars-compact-text">
        @forelse($group['vins']->take(3) as $vin)
            @php
                $donorCar = $nikolaCarsDonorCarsByVin->get(\Illuminate\Support\Str::upper(trim((string) $vin)));
                $donorMeta = $donorCar
                    ? trim(collect([$donorCar->display_model, $donorCar->year])->filter()->implode(' / '))
                    : '';
            @endphp
            @if($donorCar)
                <a href="{{ route('admin.donor-cars.show', $donorCar) }}">{{ $vin }}</a>
            @else
                {{ $vin }}
            @endif
            @if($donorMeta !== '')
                <div class="help">{{ $donorMeta }}</div>
            @endif
            @if(! $loop->last)
                <span class="help"> · </span>
            @endif
        @empty
            -
        @endforelse
        @if($group['vins']->count() > 3)
            <div class="help">+{{ $group['vins']->count() - 3 }}</div>
        @endif
    </td>
    <td class="nikolacars-compact-text" data-nikolacars-category-cell>
        <div class="nikolacars-category-line">
            <span data-nikolacars-category-display>
        @forelse($group['categories']->take(3) as $category)
            {{ $category }}
            @if(! $loop->last)
                <span class="help"> · </span>
            @endif
        @empty
            -
        @endforelse
            </span>
            @if($canEditNikolaCarsCategory)
                <button
                    type="button"
                    class="nikolacars-category-edit-button"
                    title="&#1053;&#1072;&#1079;&#1085;&#1072;&#1095;&#1080;&#1090;&#1100; &#1082;&#1072;&#1090;&#1077;&#1075;&#1086;&#1088;&#1080;&#1102;"
                    aria-label="&#1053;&#1072;&#1079;&#1085;&#1072;&#1095;&#1080;&#1090;&#1100; &#1082;&#1072;&#1090;&#1077;&#1075;&#1086;&#1088;&#1080;&#1102;"
                    data-nikolacars-category-edit-toggle
                >&#9998;</button>
            @endif
        </div>
        @if($group['categories']->count() > 3)
            <div class="help">+{{ $group['categories']->count() - 3 }}</div>
        @endif
        @if($canEditNikolaCarsCategory)
            <div
                class="nikolacars-category-editor"
                data-nikolacars-category-editor
                data-search-url="{{ route('admin.zapchasti.categories.search') }}"
                data-update-url="{{ route('admin.zapchasti.category.update', $item) }}"
                hidden
            >
                <input type="search" class="nikolacars-category-search-input" placeholder="&#1055;&#1086;&#1080;&#1089;&#1082; &#1082;&#1072;&#1090;&#1077;&#1075;&#1086;&#1088;&#1080;&#1080;" autocomplete="off" data-nikolacars-category-search-input>
                <div class="nikolacars-category-suggestions" data-nikolacars-category-suggestions hidden></div>
            </div>
        @endif
    </td>
    <td class="nikolacars-damages-cell">
        @if($damages->isNotEmpty())
            <span class="nikolacars-damages-text">{{ $damages->take(3)->implode(' · ') }}</span>
            @if($damages->count() > 3)
                <div class="help">+{{ $damages->count() - 3 }}</div>
            @endif
        @else
            <span class="nikolacars-damages-text">Без повреждений</span>
        @endif
        @if($damageStatusUsers->isNotEmpty())
            <div class="nikolacars-damage-user">
                {{ $damageStatusUsers->take(3)->implode(' · ') }}
                @if($damageStatusUsers->count() > 3)
                    <span>+{{ $damageStatusUsers->count() - 3 }}</span>
                @endif
            </div>
        @endif
    </td>
    <td class="nikolacars-price-cell" data-nikolacars-price-cell>
        <div class="nikolacars-price-display" data-nikolacars-price-display>
            <span
                @class(['nikolacars-zero-price' => $hasZeroSalePrice])
                data-nikolacars-price-text
            >
                @if($group['unit_price_uah_text'] !== '-')
                    {{ $group['unit_price_uah_text'] }}
                    <small>{{ $group['unit_price_text'] }}</small>
                @else
                    {{ $group['unit_price_text'] }}
                @endif
            </span>
            @if($canUseNikolaCarsActions && ! $isGrouped)
            <button
                type="button"
                class="nikolacars-icon-button"
                title="Редактировать цену продажи"
                aria-label="Редактировать цену продажи"
                data-nikolacars-price-edit-toggle
            >&#9998;</button>
            @endif
        </div>
        @if($canUseNikolaCarsActions && ! $isGrouped)
        <div class="nikolacars-price-editor" data-nikolacars-price-editor hidden>
            <input
                class="nikolacars-inline-input nikolacars-inline-input--number"
                form="nikolacars-update-{{ $item->id }}"
                name="price_amount"
                type="number"
                min="0"
                step="0.01"
                value="{{ $group['unit_price_value'] }}"
                placeholder="{{ $group['unit_price_text'] }}"
                data-nikolacars-price-input
            >
            <button
                type="submit"
                class="nikolacars-icon-button"
                form="nikolacars-update-{{ $item->id }}"
                title="Сохранить цену продажи"
                aria-label="Сохранить цену продажи"
            >&#10003;</button>
            <button
                type="button"
                class="nikolacars-icon-button"
                title="Отменить"
                aria-label="Отменить"
                data-nikolacars-price-edit-cancel
            >&#215;</button>
        </div>
        @endif
    </td>
    <td class="nikolacars-cart-cell">
        @if($canUseNikolaCarsActions && ! $isGrouped)
        <button
            type="button"
            class="nikolacars-cart-add-button"
            @if(! $canAddToNikolaCarsCart) hidden @endif
            title="&#1044;&#1086;&#1073;&#1072;&#1074;&#1080;&#1090;&#1100; &#1074; &#1082;&#1086;&#1088;&#1079;&#1080;&#1085;&#1091;"
            aria-label="&#1044;&#1086;&#1073;&#1072;&#1074;&#1080;&#1090;&#1100; &#1074; &#1082;&#1086;&#1088;&#1079;&#1080;&#1085;&#1091;"
            data-nikolacars-cart-add
            data-cart-id="{{ $cartProductId }}"
            data-cart-name="{{ $itemName($item) }}"
            data-cart-part-number="{{ $group['part_number'] }}"
            data-cart-code="{{ $group['codes']->first() }}"
            data-cart-vin="{{ $group['vins']->first() }}"
            data-cart-category="{{ $group['categories']->first() }}"
            data-cart-price="{{ $group['unit_price_value'] }}"
            data-cart-price-text="{{ $group['unit_price_text'] }}"
            data-cart-price-uah="{{ $group['unit_price_uah_value'] }}"
            data-cart-price-uah-text="{{ $group['unit_price_uah_text'] }}"
            data-cart-stock="{{ $stockValue }}"
            data-cart-stock-text="{{ $stockText }}"
            data-cart-url="{{ $itemUrl($item) }}"
            data-cart-image="{{ $imageUrl }}"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="8" cy="21" r="1"></circle>
                <circle cx="19" cy="21" r="1"></circle>
                <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h8.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path>
            </svg>
        </button>
        <span class="help nikolacars-cart-placeholder" data-nikolacars-cart-placeholder @if($canAddToNikolaCarsCart) hidden @endif>-</span>
        @else
            <span class="help">-</span>
        @endif
    </td>
    <td data-nikolacars-availability>
        {{ $stockText }}
        @if($isManuallySold)
            <div class="nikolacars-sold-note">Продано до 01.06.2026</div>
        @endif
        @if($isReserved)
            @if($reservedOrders->count() === 1)
                @php
                    $reservedOrder = $reservedOrders->first()['order'];
                @endphp
                <a class="nikolacars-reserved-note" href="{{ route('admin.customer-orders.show', $reservedOrder) }}">&#1074; &#1088;&#1077;&#1079;&#1077;&#1088;&#1074;&#1077; {{ $reservedText }}</a>
            @elseif($reservedOrders->isNotEmpty())
                <div class="nikolacars-reserved-note">
                    <span>&#1074; &#1088;&#1077;&#1079;&#1077;&#1088;&#1074;&#1077; {{ $reservedText }}</span>
                    <span class="nikolacars-reserved-orders">
                        @foreach($reservedOrders as $reservedOrder)
                            <a href="{{ route('admin.customer-orders.show', $reservedOrder['order']) }}">{{ $reservedOrder['order']->number }}</a>@if(! $loop->last), @endif
                        @endforeach
                    </span>
                </div>
            @else
                <div class="nikolacars-reserved-note">&#1074; &#1088;&#1077;&#1079;&#1077;&#1088;&#1074;&#1077; {{ $reservedText }}</div>
            @endif
        @endif
    </td>
    <td class="actions">
        <div class="nikolacars-row-actions">
            @if($isGrouped)
                <button
                    type="button"
                    class="nikolacars-group-toggle"
                    title="&#1055;&#1086;&#1082;&#1072;&#1079;&#1072;&#1090;&#1100; &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1080;"
                    aria-label="&#1055;&#1086;&#1082;&#1072;&#1079;&#1072;&#1090;&#1100; &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1080;"
                    aria-expanded="false"
                    data-nikolacars-group-toggle="{{ $groupRowId }}"
                >
                    <span>{{ $group['count'] }} &#1089;&#1090;&#1088;&#1086;&#1082;</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m9 18 6-6-6-6"></path>
                    </svg>
                </button>
            @else
                @if($canUseNikolaCarsActions)
                    <form id="nikolacars-update-{{ $item->id }}" method="POST" action="{{ route('admin.zapchasti.update', $item) }}" class="inline-form" data-nikolacars-update-form>
                        @csrf
                        @method('PATCH')
                    </form>
                    <form method="POST" action="{{ route('admin.zapchasti.destroy', $item) }}" class="inline-form" data-nikolacars-delete-form data-confirm="&#1059;&#1076;&#1072;&#1083;&#1080;&#1090;&#1100; &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1102; &quot;{{ $itemName($item) }}&quot; &#1080;&#1079; &#1082;&#1072;&#1090;&#1072;&#1083;&#1086;&#1075;&#1072; &#1053;&#1080;&#1082;&#1086;&#1083;&#1072;&#1050;&#1072;&#1088;&#1079;?">
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="nikolacars-icon-button nikolacars-delete-button"
                            title="&#1059;&#1076;&#1072;&#1083;&#1080;&#1090;&#1100;"
                            aria-label="&#1059;&#1076;&#1072;&#1083;&#1080;&#1090;&#1100;"
                        >&#215;</button>
                    </form>
                    <form
                        method="POST"
                        action="{{ route('admin.zapchasti.sold', $item) }}"
                        class="inline-form"
                        data-nikolacars-manual-sold-form
                        data-confirm="&#1055;&#1086;&#1084;&#1077;&#1090;&#1080;&#1090;&#1100; &quot;{{ $itemName($item) }}&quot; &#1082;&#1072;&#1082; &#1087;&#1088;&#1086;&#1076;&#1072;&#1085;&#1085;&#1091;&#1102; &#1076;&#1086; 01.06.2026? &#1055;&#1086;&#1079;&#1080;&#1094;&#1080;&#1103; &#1080;&#1089;&#1095;&#1077;&#1079;&#1085;&#1077;&#1090; &#1080;&#1079; &#1085;&#1086;&#1074;&#1099;&#1093; &#1086;&#1090;&#1095;&#1077;&#1090;&#1086;&#1074;."
                        data-progress="&#1054;&#1090;&#1084;&#1077;&#1095;&#1072;&#1077;&#1084;..."
                        data-error="&#1053;&#1077; &#1091;&#1076;&#1072;&#1083;&#1086;&#1089;&#1100; &#1087;&#1086;&#1084;&#1077;&#1090;&#1080;&#1090;&#1100; &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1102; &#1082;&#1072;&#1082; &#1087;&#1088;&#1086;&#1076;&#1072;&#1085;&#1085;&#1091;&#1102;. &#1054;&#1073;&#1085;&#1086;&#1074;&#1080;&#1090;&#1077; &#1089;&#1090;&#1088;&#1072;&#1085;&#1080;&#1094;&#1091; &#1080; &#1087;&#1086;&#1087;&#1088;&#1086;&#1073;&#1091;&#1081;&#1090;&#1077; &#1077;&#1097;&#1077; &#1088;&#1072;&#1079;."
                    >
                        @csrf
                        @method('PATCH')
                        <button
                            type="submit"
                            class="nikolacars-icon-button nikolacars-sold-button"
                            title="&#1055;&#1088;&#1086;&#1076;&#1072;&#1085;&#1086; &#1076;&#1086; 01.06.2026"
                            aria-label="&#1055;&#1088;&#1086;&#1076;&#1072;&#1085;&#1086; &#1076;&#1086; 01.06.2026"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="nikolacars-sold-icon">
                                <path fill="#16a34a" d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"></path>
                                <circle cx="7.4" cy="7.4" r="1.45" fill="#ffffff"></circle>
                                <path d="m9 13 2.1 2.1L15.8 10.4" fill="none" stroke="#ffffff" stroke-width="2.15" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </button>
                    </form>
                @endif
                @if($isReserved && ! $isManuallySold)
                    <button
                        type="button"
                        class="nikolacars-icon-button nikolacars-sold-button"
                        title="&#1053;&#1077;&#1083;&#1100;&#1079;&#1103; &#1087;&#1088;&#1086;&#1076;&#1072;&#1090;&#1100;: &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1103; &#1074; &#1088;&#1077;&#1079;&#1077;&#1088;&#1074;&#1077;"
                        aria-label="&#1053;&#1077;&#1083;&#1100;&#1079;&#1103; &#1087;&#1088;&#1086;&#1076;&#1072;&#1090;&#1100;: &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1103; &#1074; &#1088;&#1077;&#1079;&#1077;&#1088;&#1074;&#1077;"
                        disabled
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3.5" y="4.5" width="17" height="17" rx="2.5"></rect>
                            <path d="M7.5 2.5v5"></path>
                            <path d="M16.5 2.5v5"></path>
                            <path d="M3.5 9h17"></path>
                            <path d="m8 15 2.3 2.3L16 12.7"></path>
                            <path d="M7.8 12h.01"></path>
                            <path d="M12 12h.01"></path>
                        </svg>
                    </button>
                @endif
            @endif
        </div>
    </td>
</tr>
@if($isGrouped)
    @include('admin.part_catalog.partials.nikolacars-group-child-rows', [
        'groupRowId' => $groupRowId,
        'group' => $group,
    ])
@endif
