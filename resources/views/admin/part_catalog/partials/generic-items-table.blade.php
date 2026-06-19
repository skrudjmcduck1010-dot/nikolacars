<table style="margin-top:12px;">
    <thead>
    <tr>
        <th>Фото</th>
        <th>ID</th>
        <th>Артикул</th>
        <th>Название</th>
        <th>Модель</th>
        <th>Категория</th>
        <th>Цена</th>
        <th>Наличие</th>
        <th>Происхождение</th>
        <th>Состояние</th>
        <th>Первый раз найден</th>
        <th>Ссылка</th>
    </tr>
    </thead>
    <tbody>
    @forelse($items as $item)
        @php
            $sourceUrl = $sourceExternalUrl($item);
            $imageUrls = $catalogImageUrls($item);
            $imageUrl = $imageUrls->first();
            $localizedNameBadges = $catalogLocalizedNameBadges($item);
            $drivePartsIdentifiers = $catalogDrivePartsIdentifiers($item);
            $teslaStatusBadges = $catalogTeslaStatusBadges($item);
            $priceSummary = $catalogPriceSummary($item);
            $partOriginLabel = $catalogPartOriginLabel($item);
        @endphp
        <tr>
            <td>
                @if($imageUrl)
                    <button
                        type="button"
                        class="catalog-photo-preview"
                        data-catalog-photo-trigger
                        data-catalog-images='@json($imageUrls->all())'
                        data-catalog-photo-title="{{ $itemName($item) }}"
                    >
                        <img class="table-preview" src="{{ $imageUrl }}" alt="{{ $itemName($item) }}" loading="lazy" decoding="async">
                        @if($item->source === 'driveparts' && $isDrivePartsSharedPlaceholderImageUrl($imageUrl))
                            <span class="catalog-photo-preview__missing">без фото</span>
                        @endif
                        @if($imageUrls->count() > 1)
                            <span class="catalog-photo-preview__count">+{{ $imageUrls->count() - 1 }}</span>
                        @endif
                    </button>
                @else
                    <span class="preview-placeholder">нет фото</span>
                @endif
            </td>
            <td>{{ $item->id }}</td>
            <td>
                {{ $item->part_number ?: '—' }}
                @if(mb_strlen(trim((string) $item->part_number)) > 12)
                    <span class="catalog-badge">Ошибка</span>
                @endif
                @if($drivePartsIdentifiers['tesla_part_number'] !== '')
                    <div class="help">Tesla: {{ $drivePartsIdentifiers['tesla_part_number'] }}</div>
                @endif
                @if($drivePartsIdentifiers['sku'] !== '' && $drivePartsIdentifiers['sku'] !== $item->part_number)
                    <div class="help">DriveParts: {{ $drivePartsIdentifiers['sku'] }}</div>
                @endif
            </td>
            <td>
                <a href="{{ $itemUrl($item) }}">
                    <strong>{{ $itemName($item) }}</strong>
                </a>
                @foreach($teslaStatusBadges as $teslaStatusBadge)
                    <span class="catalog-badge">{{ $teslaStatusBadge }}</span>
                @endforeach
                @if($item->compatibility_text)
                    <div class="help">{{ $modelLabel($item->compatibility_text) }}</div>
                @else
                    <div class="help">{{ $modelLabel($item) ?: '—' }}</div>
                @endif
                <div class="catalog-item-names">
                    <span>
                        <b>RU</b> {{ $localizedNameBadges['ru']['text'] !== '' ? $localizedNameBadges['ru']['text'] : '—' }}
                        @if($localizedNameConflictText($item, 'ru') !== '')
                            <span class="tag tag-conflict">{{ $localizedNameConflictText($item, 'ru') }}</span>
                        @endif
                    </span>
                    <span>
                        <b>UA</b> {{ $localizedNameBadges['ua']['text'] !== '' ? $localizedNameBadges['ua']['text'] : '—' }}
                        @if($localizedNameConflictText($item, 'ua') !== '')
                            <span class="tag tag-conflict">{{ $localizedNameConflictText($item, 'ua') }}</span>
                        @endif
                    </span>
                    @if($localizedNameBadges['undetermined']['text'] !== '')
                        <span><b>Не определено</b> {{ $localizedNameBadges['undetermined']['text'] }}</span>
                    @endif
                </div>
            </td>
            <td>{{ $modelLabel($item->compatibility_text ?: $item) ?: '—' }}</td>
            <td>{{ collect([$item->main_category_name, $item->subcategory_name, $item->node_name])->filter()->implode(' / ') ?: '—' }}</td>
            <td>
                @if($priceSummary['has_price'])
                    {{ number_format($priceSummary['amount_usd'], 2, '.', ' ') }} USD
                    @if($priceSummary['amount_uah'] !== null)
                        <div class="help">≈ {{ number_format($priceSummary['amount_uah'], 2, '.', ' ') }} UAH · {{ $priceSummary['rate_label'] }}</div>
                    @endif
                @else
                    —
                @endif
            </td>
            <td>{{ $item->availability ?: '—' }}</td>
            <td>{{ $partOriginLabel !== '' ? $partOriginLabel : '—' }}</td>
            <td>{{ $itemCondition($item) ?: '—' }}</td>
            <td>{{ $item->created_at?->format('d.m.Y H:i') ?: '—' }}</td>
            <td>
                @if($sourceUrl)
                    <a href="{{ $sourceUrl }}" target="_blank" rel="noopener">Открыть</a>
                @else
                    —
                @endif
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="12" class="empty">{{ $catalog['source'] === 'tesla_official' ? 'В этой категории запчастей Tesla.com нет.' : ($selectedCategory ? 'В этой категории спарсенных товаров нет.' : 'Запчасти по поиску не найдены.') }}</td>
        </tr>
    @endforelse
    </tbody>
</table>
