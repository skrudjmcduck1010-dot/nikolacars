@php
    $nikolaCarsInventory = app(\App\Services\NikolaCarsInventoryService::class);
    $nikolaCarsChildItemGroupsById = $nikolaCarsChildItemGroupsById ?? collect();
@endphp
@foreach($group['items'] as $childItem)
    @php
        $childGroup = $nikolaCarsChildItemGroupsById->get($childItem->id) ?? [];
        $childImageUrls = collect($childGroup['image_urls'] ?? []);
        $childGalleryImageUrls = collect($childGroup['gallery_image_urls'] ?? $childImageUrls);
        $childProductImageUrls = collect($childGroup['product_image_urls'] ?? $childImageUrls);
        $childTeslaImageUrls = collect($childGroup['tesla_image_urls'] ?? []);
        $childImageUrl = $childGalleryImageUrls->first();
        $childName = trim((string) $childItem->name_ua) !== ''
            ? trim((string) $childItem->name_ua)
            : $itemName($childItem);
        $childVin = \Illuminate\Support\Str::upper(trim((string) data_get($childItem->raw_attributes, 'donor_vin', '')));
        $childDonorCar = $childVin !== ''
            ? $nikolaCarsDonorCarsByVin->get($childVin)
            : null;
        $childDonorMeta = $childDonorCar
            ? trim(collect([$childDonorCar->display_model, $childDonorCar->year])->filter()->implode(' / '))
            : '';
        $childCategory = $nikolaCarsInventory->displayCategory($childItem);
        $childDamage = trim((string) $childItem->quality);
        $childDamageStatusUser = $nikolaCarsDamageStatusUsersById->get((int) data_get($childItem->raw_attributes, 'donor_damage_status_changed_by'));
        $childDamageStatusUserName = trim((string) ($childDamageStatusUser?->name ?: $childDamageStatusUser?->email));
        $childStockValue = (float) ($childGroup['quantity'] ?? 0.0);
        $childStockText = (string) ($childGroup['quantity_text'] ?? $nikolaCarsInventory->availability($childStockValue));
        $childPriceValue = $childGroup['unit_price_value'] ?? null;
        $childPriceText = (string) ($childGroup['unit_price_text'] ?? '-');
        $childPriceUah = $childGroup['unit_price_uah_value'] ?? null;
        $childPriceUahText = (string) ($childGroup['unit_price_uah_text'] ?? '-');
        $childHasZeroSalePrice = $childPriceValue !== null && (float) $childPriceValue === 0.0;
        $childIsReserved = (bool) ($childGroup['is_reserved'] ?? false);
        $childIsManuallySold = $catalogIsManuallySold($childItem);
        $childIsOutOfStock = $childStockValue <= 0.0;
        $childCanUseNikolaCarsActions = ! $childIsReserved && ! $childIsManuallySold && ! $childIsOutOfStock;
        $childCanAddToNikolaCarsCart = $childCanUseNikolaCarsActions
            && $childPriceValue !== null
            && (float) $childPriceValue > 0.0;
        $childCartProductId = $catalogCartProductId($childItem);
        $childDonorColor = $nikolaCarsUsesDonorColorItem($childItem)
            ? trim((string) (collect($childGroup['donor_colors'] ?? [])->first() ?: $childDonorCar?->color))
            : '';
        $childDonorPaintCode = trim((string) $childDonorCar?->paint_code);
        $childDonorColorHex = $nikolaCarsColorSwatchHex($childDonorColor);
    @endphp
    <tr @class(['nikolacars-group-child-row', 'nikolacars-group-child-row--odd' => $loop->odd, 'nikolacars-group-child-row--even' => $loop->even]) data-nikolacars-group-child="{{ $groupRowId }}" hidden>
        <td>
            @if($childImageUrl)
                <button
                    type="button"
                    class="catalog-photo-preview"
                    data-catalog-photo-trigger
                    data-catalog-images='@json($childGalleryImageUrls->all())'
                    data-catalog-photo-title="{{ $childName }}"
                    aria-label="Открыть фото {{ $childName }}"
                >
                    <img class="table-preview" src="{{ $childImageUrl }}" alt="{{ $childName }}" loading="lazy" decoding="async">
                    @if($childTeslaImageUrls->isNotEmpty())
                        <span class="catalog-photo-preview__count catalog-photo-preview__count--tesla" title="Tesla.com фото">{{ $childTeslaImageUrls->count() }}</span>
                    @endif
                    @if($childProductImageUrls->isNotEmpty())
                        <span class="catalog-photo-preview__count catalog-photo-preview__count--product" title="Наши фото">{{ $childProductImageUrls->count() }}</span>
                    @endif
                </button>
            @else
                <span class="preview-placeholder">нет фото</span>
            @endif
        </td>
        <td class="nikolacars-code-cell">
            {{ trim((string) data_get($childItem->raw_attributes, 'code')) ?: '-' }}
        </td>
        <td>
            <span @class(['nikolacars-invalid-part-number' => $isInvalidNikolaCarsPartNumber($childItem->part_number)])>{{ $childItem->part_number ?: '-' }}</span>
        </td>
        <td>
            <a class="nikolacars-part-name-link" href="{{ $itemUrl($childItem) }}">{{ $childName }}</a>
            @if(trim((string) $childItem->name_ru) !== '')
                <div class="help">{!! html_entity_decode('Название РУ:') !!} {{ $childItem->name_ru }}</div>
            @endif
        </td>
        <td class="nikolacars-donor-color-cell">
            @if($childDonorColor !== '')
                <span class="nikolacars-donor-color">
                    <span
                        class="nikolacars-donor-color__swatch"
                        style="{{ $nikolaCarsColorSwatchStyle($childDonorColorHex) }}"
                        title="{{ $childDonorColor }}"
                        aria-label="{{ $childDonorColor }}"
                    ></span>
                    @if($childDonorPaintCode !== '')
                        <span class="nikolacars-donor-color__label">{{ $childDonorPaintCode }}</span>
                    @endif
                </span>
            @else
                -
            @endif
        </td>
        <td class="nikolacars-compact-text">
            @if($childVin !== '')
                @if($childDonorCar)
                    <a href="{{ route('admin.donor-cars.show', $childDonorCar) }}">{{ $childVin }}</a>
                @else
                    {{ $childVin }}
                @endif
                @if($childDonorMeta !== '')
                    <div class="help">{{ $childDonorMeta }}</div>
                @endif
            @else
                -
            @endif
        </td>
        <td class="nikolacars-compact-text">
            {{ $childCategory !== '' ? $childCategory : '-' }}
        </td>
        <td class="nikolacars-damages-cell">
            <span class="nikolacars-damages-text">{{ $childDamage !== '' ? $childDamage : html_entity_decode('Без повреждений') }}</span>
            @if($childDamageStatusUserName !== '')
                <div class="nikolacars-damage-user">{{ $childDamageStatusUserName }}</div>
            @endif
        </td>
        <td class="nikolacars-price-cell" data-nikolacars-price-cell>
            <div class="nikolacars-price-display" data-nikolacars-price-display>
                <span
                    @class(['nikolacars-zero-price' => $childHasZeroSalePrice])
                    data-nikolacars-price-text
                >
                    @if($childPriceUahText !== '-')
                        {{ $childPriceUahText }}
                        <small>{{ $childPriceText }}</small>
                    @else
                        {{ $childPriceText }}
                    @endif
                </span>
                @if($childCanUseNikolaCarsActions)
                    <button
                        type="button"
                        class="nikolacars-icon-button"
                        title="Редактировать цену продажи"
                        aria-label="Редактировать цену продажи"
                        data-nikolacars-price-edit-toggle
                    >&#9998;</button>
                @endif
            </div>
            @if($childCanUseNikolaCarsActions)
                <div class="nikolacars-price-editor" data-nikolacars-price-editor hidden>
                    <input
                        class="nikolacars-inline-input nikolacars-inline-input--number"
                        form="nikolacars-update-{{ $childItem->id }}"
                        name="price_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        value="{{ $childPriceValue }}"
                        placeholder="{{ $childPriceText }}"
                        data-nikolacars-price-input
                    >
                    <button
                        type="submit"
                        class="nikolacars-icon-button"
                        form="nikolacars-update-{{ $childItem->id }}"
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
            @if($childCanUseNikolaCarsActions)
                <button
                    type="button"
                    class="nikolacars-cart-add-button"
                    @if(! $childCanAddToNikolaCarsCart) hidden @endif
                    title="Добавить в корзину"
                    aria-label="Добавить в корзину"
                    data-nikolacars-cart-add
                    data-cart-id="{{ $childCartProductId }}"
                    data-cart-name="{{ $childName }}"
                    data-cart-part-number="{{ $childItem->part_number }}"
                    data-cart-code="{{ trim((string) data_get($childItem->raw_attributes, 'code')) }}"
                    data-cart-vin="{{ $childVin }}"
                    data-cart-category="{{ $childCategory }}"
                    data-cart-price="{{ $childPriceValue }}"
                    data-cart-price-text="{{ $childPriceText }}"
                    data-cart-price-uah="{{ $childPriceUah }}"
                    data-cart-price-uah-text="{{ $childPriceUahText }}"
                    data-cart-stock="{{ $childStockValue }}"
                    data-cart-stock-text="{{ $childStockText }}"
                    data-cart-url="{{ $itemUrl($childItem) }}"
                    data-cart-image="{{ $childImageUrl }}"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="8" cy="21" r="1"></circle>
                        <circle cx="19" cy="21" r="1"></circle>
                        <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h8.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path>
                    </svg>
                </button>
                <span class="help nikolacars-cart-placeholder" data-nikolacars-cart-placeholder @if($childCanAddToNikolaCarsCart) hidden @endif>-</span>
            @else
                <span class="help">-</span>
            @endif
        </td>
        <td data-nikolacars-availability>{{ $childStockText }}</td>
        <td class="actions">
            <div class="nikolacars-row-actions">
                @if($childCanUseNikolaCarsActions)
                    <form id="nikolacars-update-{{ $childItem->id }}" method="POST" action="{{ route('admin.zapchasti.update', $childItem) }}" class="inline-form" data-nikolacars-update-form>
                        @csrf
                        @method('PATCH')
                    </form>
                    <form method="POST" action="{{ route('admin.zapchasti.destroy', $childItem) }}" class="inline-form" data-nikolacars-delete-form data-confirm="Удалить позицию &quot;{{ $childName }}&quot; из каталога НиколаКарз?">
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="nikolacars-icon-button nikolacars-delete-button"
                            title="Удалить"
                            aria-label="Удалить"
                        >&#215;</button>
                    </form>
                    <form
                        method="POST"
                        action="{{ route('admin.zapchasti.sold', $childItem) }}"
                        class="inline-form"
                        data-nikolacars-manual-sold-form
                        data-confirm="Пометить &quot;{{ $childName }}&quot; как проданную до 01.06.2026? Позиция исчезнет из новых отчетов."
                        data-progress="Отмечаем..."
                        data-error="Не удалось пометить позицию как проданную. Обновите страницу и попробуйте еще раз."
                    >
                        @csrf
                        @method('PATCH')
                        <button
                            type="submit"
                            class="nikolacars-icon-button nikolacars-sold-button"
                            title="Продано до 01.06.2026"
                            aria-label="Продано до 01.06.2026"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="nikolacars-sold-icon">
                                <path fill="#16a34a" d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"></path>
                                <circle cx="7.4" cy="7.4" r="1.45" fill="#ffffff"></circle>
                                <path d="m9 13 2.1 2.1L15.8 10.4" fill="none" stroke="#ffffff" stroke-width="2.15" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </button>
                    </form>
                @elseif($childIsReserved && ! $childIsManuallySold)
                    <button
                        type="button"
                        class="nikolacars-icon-button nikolacars-sold-button"
                        title="Нельзя продать: позиция в резерве"
                        aria-label="Нельзя продать: позиция в резерве"
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
                @else
                    <span class="help">-</span>
                @endif
            </div>
        </td>
    </tr>
@endforeach
