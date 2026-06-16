@if($catalogItems)
    @php
        $nextCatalogItemsPriceSort = $catalogItemsPriceSort === 'asc' ? 'desc' : 'asc';
        $priceSortUrl = request()->fullUrlWithQuery([
            'show_catalog_items' => 1,
            'catalog_items_price_sort' => $nextCatalogItemsPriceSort,
            'catalog_items_page' => null,
        ]);
        $priceSortLabel = $catalogItemsPriceSort === 'asc'
            ? 'Цена ^'
            : ($catalogItemsPriceSort === 'desc' ? 'Цена v' : 'Цена');
    @endphp
    <div class="catalog-all-parts">
        <h2 class="section-title">Все запчасти Tesla</h2>

        <table style="margin-top:12px;">
            <thead>
            <tr>
                <th>Локализация</th>
                <th>Название</th>
                <th>Артикул</th>
                <th><a class="catalog-sort-link" href="{{ $priceSortUrl }}">{{ $priceSortLabel }}</a></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($catalogItems as $item)
                @php
                    $nameRuBadge = $catalogNameBadgeForItem($item, $item->name_ru);
                    $nameUaBadge = $catalogNameBadgeForItem($item, $item->name_ua);
                    $undeterminedNameBadge = $catalogNameBadgeForItem($item, $catalogTskUndeterminedName($item));
                    $localizedNameManualLocks = $catalogLocalizedNameManualLocks($item);
                    $nameRuManual = $localizedNameManualLocks['ru'];
                    $nameUaManual = $localizedNameManualLocks['ua'];
                    $englishName = $item->source === 'tesla_official'
                        ? $catalogEnglishName($item)
                        : trim((string) $item->name_en);
                    $primaryNameBadge = $catalogNameBadge($itemName($item));
                    $teslaStatusBadges = $catalogTeslaStatusBadges($item);
                    $catalogItemNameSource = $catalogNameSource($item);
                    $priceSummary = $catalogPriceSummary($item);
                @endphp
                <tr>
                    <td>
                        {{ $modelLabel($item->aggregated_compatibility_text ?: ($item->compatibility_text ?: ($item->model_label ?: $item->model_name))) ?: '—' }}
                    </td>
                    <td>
                        <a href="{{ $itemUrl($item) }}">
                            <strong>{{ $primaryNameBadge['text'] !== '' ? $primaryNameBadge['text'] : '—' }}</strong>
                        </a>
                        @foreach($teslaStatusBadges as $teslaStatusBadge)
                            <span class="catalog-badge">{{ $teslaStatusBadge }}</span>
                        @endforeach
                        @if(($nameSource ?? '') !== '')
                            <div class="help">
                                {{ $item->source }} ·
                                @if($catalogItemNameSource['url'])
                                    <a href="{{ $catalogItemNameSource['url'] }}" target="_blank" rel="noopener">{{ $catalogItemNameSource['site'] }}</a>
                                @else
                                    {{ $catalogItemNameSource['site'] }}
                                @endif
                            </div>
                        @endif
                        <div class="catalog-item-names">
                            <span><b>EN</b> {{ $englishName !== '' ? $englishName : '—' }}</span>
                            <span>
                                <b>RU</b> {{ $nameRuBadge['text'] !== '' ? $nameRuBadge['text'] : '—' }}
                                @if($nameRuManual)
                                    <span class="tag">Вручную</span>
                                @endif
                                @if($localizedNameConflictText($item, 'ru') !== '')
                                    <span class="tag tag-conflict">{{ $localizedNameConflictText($item, 'ru') }}</span>
                                @endif
                            </span>
                            <span>
                                <b>UA</b> {{ $nameUaBadge['text'] !== '' ? $nameUaBadge['text'] : '—' }}
                                @if($nameUaManual)
                                    <span class="tag">Вручную</span>
                                @endif
                                @if($localizedNameConflictText($item, 'ua') !== '')
                                    <span class="tag tag-conflict">{{ $localizedNameConflictText($item, 'ua') }}</span>
                                @endif
                            </span>
                            @if($undeterminedNameBadge['text'] !== '')
                                <span><b>Не определено</b> {{ $undeterminedNameBadge['text'] }}</span>
                            @endif
                        </div>
                    </td>
                    <td>
                        {{ $item->part_number ?: '—' }}
                        @if(mb_strlen(trim((string) $item->part_number)) > 12)
                            <span class="catalog-badge">Ошибка</span>
                        @endif
                    </td>
                    <td>
                        @if($priceSummary['has_price'])
                            {{ number_format($priceSummary['amount_usd'], 2, '.', ' ') }} USD
                            <div class="help">
                                цена из
                                @if($priceSummary['source_url'])
                                    <a href="{{ $priceSummary['source_url'] }}" target="_blank" rel="noopener">{{ $priceSummary['source_label'] }}</a>
                                @else
                                    {{ $priceSummary['source_label'] }}
                                @endif
                            </div>
                            @if($priceSummary['amount_uah'] !== null)
                                <div class="help">&asymp; {{ number_format($priceSummary['amount_uah'], 2, '.', ' ') }} UAH &middot; {{ $priceSummary['rate_label'] }}</div>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="actions">
                        <a class="btn btn-secondary" href="{{ $itemUrl($item) }}">Открыть</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="empty">Запчасти по выбранным фильтрам не найдены.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $catalogItems->links() }}
        </div>
    </div>
@endif
