@if($competitorCatalogItems)
    @php
        $competitorSortLink = function (string $sort, string $label) use ($competitorSort, $competitorSortDirection): string {
            $nextDirection = $competitorSort === $sort && $competitorSortDirection === 'asc' ? 'desc' : 'asc';
            $suffix = $competitorSort === $sort ? ($competitorSortDirection === 'asc' ? ' ↑' : ' ↓') : '';

            return '<a class="catalog-sort-link" href="'.e(request()->fullUrlWithQuery([
                'competitor_sort' => $sort,
                'competitor_direction' => $nextDirection,
                'competitor_items_page' => null,
            ])).'">'.e($label.$suffix).'</a>';
        };
        $catalogImageFilterUrl = fn (?string $filter): string => $filter === null
            ? request()->fullUrlWithoutQuery(['catalog_image_filter', 'competitor_items_page'])
            : request()->fullUrlWithQuery([
                'catalog_image_filter' => $filter,
                'competitor_items_page' => null,
            ]);
        $competitorNameFilterUrl = fn (?string $filter): string => $filter === null
            ? request()->fullUrlWithoutQuery(['competitor_name_filter', 'competitor_items_page'])
            : request()->fullUrlWithQuery([
                'competitor_name_filter' => $filter,
                'competitor_items_page' => null,
            ]);
        $teslaCheckFilterUrl = fn (?string $filter): string => $filter === null
            ? request()->fullUrlWithoutQuery(['tesla_check_filter', 'competitor_items_page'])
            : request()->fullUrlWithQuery([
                'tesla_check_filter' => $filter,
                'competitor_items_page' => null,
            ]);
        $teslaVisualFilterUrl = fn (?string $filter): string => $filter === null
            ? request()->fullUrlWithoutQuery(['tesla_visual_filter', 'competitor_items_page'])
            : request()->fullUrlWithQuery([
                'tesla_visual_filter' => $filter,
                'competitor_items_page' => null,
            ]);
        $catalogImageCount = fn (string $key): string => $competitorCatalogImageCounts[$key] !== null
            ? number_format((int) $competitorCatalogImageCounts[$key], 0, '.', ' ')
            : '';
        $catalogNameCount = fn (string $key): string => $competitorCatalogNameCounts[$key] !== null
            ? number_format((int) $competitorCatalogNameCounts[$key], 0, '.', ' ')
            : '';
        $teslaCheckCount = fn (string $key): string => ($teslaCheckCounts[$key] ?? null) !== null
            ? number_format((int) $teslaCheckCounts[$key], 0, '.', ' ')
            : '';
    @endphp
    <div class="catalog-competitor-products">
        <h2 class="section-title">
            {{ in_array($catalog['source'], ['all', 'tesla_official'], true) ? 'Все позиции Tesla.com' : 'Товары конкурента' }}
            @if($selectedCategory)
                <span class="catalog-section-context">
                    -&gt; {{ $selectedCategory->code ? $selectedCategory->code.' · ' : '' }}{{ $categoryName($selectedCategory) }}
                </span>
            @endif
            @if(method_exists($competitorCatalogItems, 'total'))
                <span class="catalog-count-badge">{{ $competitorCatalogItems->total() }}</span>
            @elseif($competitorTotalProductsCount !== null)
                <span class="catalog-count-badge">{{ $competitorTotalProductsCount }}</span>
            @endif
        </h2>

        <div class="catalog-table-filters" aria-label="Фильтр по фото">
            <span class="help">Фото</span>
            <a
                @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $catalogImageFilter === ''])
                href="{{ $catalogImageFilterUrl(null) }}"
            >Все <span class="catalog-filter-pill__count">({{ $catalogImageCount('total') }})</span></a>
            <a
                @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $catalogImageFilter === 'with'])
                href="{{ $catalogImageFilterUrl('with') }}"
            >С фото <span class="catalog-filter-pill__count">({{ $catalogImageCount('with') }})</span></a>
            <a
                @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $catalogImageFilter === 'without'])
                href="{{ $catalogImageFilterUrl('without') }}"
            >Без фото <span class="catalog-filter-pill__count">({{ $catalogImageCount('without') }})</span></a>
        </div>

        <div class="catalog-table-filters" aria-label="Фильтр по названию">
            <span class="help">Название</span>
            <a
                @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $competitorNameFilter === ''])
                href="{{ $competitorNameFilterUrl(null) }}"
            >Все</a>
            <a
                @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $competitorNameFilter === 'conflict'])
                href="{{ $competitorNameFilterUrl('conflict') }}"
            >Конфликт <span class="catalog-filter-pill__count">({{ $catalogNameCount('conflict') }})</span></a>
            <a
                @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $competitorNameFilter === 'missing_ru'])
                href="{{ $competitorNameFilterUrl('missing_ru') }}"
            >Без Ру <span class="catalog-filter-pill__count">({{ $catalogNameCount('missing_ru') }})</span></a>
            <a
                @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $competitorNameFilter === 'missing_ua'])
                href="{{ $competitorNameFilterUrl('missing_ua') }}"
            >Без Укр <span class="catalog-filter-pill__count">({{ $catalogNameCount('missing_ua') }})</span></a>
        </div>

        @if(in_array($catalog['source'], ['all', 'tesla_official'], true))
            <div class="catalog-table-filters" aria-label="Фильтр по типу изображения Tesla.com">
                <span class="help">Тип изображения</span>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaVisualFilter === ''])
                    href="{{ $teslaVisualFilterUrl(null) }}"
                >Все</a>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaVisualFilter === 'part_photo'])
                    href="{{ $teslaVisualFilterUrl('part_photo') }}"
                >Фото детали</a>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaVisualFilter === 'scheme'])
                    href="{{ $teslaVisualFilterUrl('scheme') }}"
                >Схема EPC</a>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaVisualFilter === 'part_photo_and_scheme'])
                    href="{{ $teslaVisualFilterUrl('part_photo_and_scheme') }}"
                >Фото + схема</a>
            </div>

            <div class="catalog-table-filters" aria-label="Фильтр Check by Tesla.com">
                <span class="help">Check by Tesla.com</span>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaCheckFilter === ''])
                    href="{{ $teslaCheckFilterUrl(null) }}"
                >Все <span class="catalog-filter-pill__count">({{ $teslaCheckCount('total') }})</span></a>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaCheckFilter === 'checked'])
                    href="{{ $teslaCheckFilterUrl('checked') }}"
                >Checked <span class="catalog-filter-pill__count">({{ $teslaCheckCount('checked') }})</span></a>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaCheckFilter === 'unchecked'])
                    href="{{ $teslaCheckFilterUrl('unchecked') }}"
                >Not checked <span class="catalog-filter-pill__count">({{ $teslaCheckCount('unchecked') }})</span></a>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaCheckFilter === 'exact'])
                    href="{{ $teslaCheckFilterUrl('exact') }}"
                >Exact <span class="catalog-filter-pill__count">({{ $teslaCheckCount('exact') }})</span></a>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaCheckFilter === 'similar'])
                    href="{{ $teslaCheckFilterUrl('similar') }}"
                >Similar <span class="catalog-filter-pill__count">({{ $teslaCheckCount('similar') }})</span></a>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaCheckFilter === 'not_found'])
                    href="{{ $teslaCheckFilterUrl('not_found') }}"
                >Not found <span class="catalog-filter-pill__count">({{ $teslaCheckCount('not_found') }})</span></a>
                <a
                    @class(['catalog-filter-pill', 'catalog-filter-pill--active' => $teslaCheckFilter === 'api_error'])
                    href="{{ $teslaCheckFilterUrl('api_error') }}"
                >API error <span class="catalog-filter-pill__count">({{ $teslaCheckCount('api_error') }})</span></a>
            </div>
        @endif

        <table style="margin-top:12px;">
            <thead>
            <tr>
                <th>Фото</th>
                <th>{!! $competitorSortLink('id', 'ID') !!}</th>
                <th>{!! $competitorSortLink('part_number', 'Артикул') !!}</th>
                <th>{!! $competitorSortLink('name', 'Название') !!}</th>
                <th>{!! $competitorSortLink('price', 'Цена') !!}</th>
                <th>{!! $competitorSortLink('availability', 'Наличие') !!}</th>
                <th>Происхождение</th>
                <th>Состояние</th>
                <th>{!! $competitorSortLink('created_at', 'Первый раз найден') !!}</th>
                <th>Ссылка</th>
            </tr>
            </thead>
            <tbody>
            @forelse($competitorCatalogItems as $competitorItem)
                @php
                    $sourceUrl = $sourceExternalUrl($competitorItem);
                    $imageUrls = $catalogImageUrls($competitorItem);
                    $imageUrl = $imageUrls->first();
                    $localizedNameBadges = $catalogLocalizedNameBadges($competitorItem);
                    $drivePartsIdentifiers = $catalogDrivePartsIdentifiers($competitorItem);
                    $teslaStatusBadges = $catalogTeslaStatusBadges($competitorItem);
                    $priceSummary = $catalogPriceSummary($competitorItem);
                    $partOriginLabel = $catalogPartOriginLabel($competitorItem);
                @endphp
                <tr>
                    <td>
                        @if($imageUrl)
                            <button
                                type="button"
                                class="catalog-photo-preview"
                                data-catalog-photo-trigger
                                data-catalog-images='@json($imageUrls->all())'
                                data-catalog-photo-title="{{ $itemName($competitorItem) }}"
                            >
                                <img class="table-preview" src="{{ $imageUrl }}" alt="{{ $itemName($competitorItem) }}" loading="lazy" decoding="async">
                                @if($competitorItem->source === 'driveparts' && $isDrivePartsSharedPlaceholderImageUrl($imageUrl))
                                    <span class="catalog-photo-preview__missing">&#1073;&#1077;&#1079; &#1092;&#1086;&#1090;&#1086;</span>
                                @endif
                                @if($imageUrls->count() > 1)
                                    <span class="catalog-photo-preview__count">+{{ $imageUrls->count() - 1 }}</span>
                                @endif
                            </button>
                        @else
                            <span class="preview-placeholder">нет фото</span>
                        @endif
                    </td>
                    <td>{{ $competitorItem->id }}</td>
                    <td>{{ $competitorItem->part_number ?: '—' }}</td>
                    <td>
                        @if($drivePartsIdentifiers['tesla_part_number'] !== '')
                            <div class="help">Tesla: {{ $drivePartsIdentifiers['tesla_part_number'] }}</div>
                        @endif
                        @if($drivePartsIdentifiers['sku'] !== '' && $drivePartsIdentifiers['sku'] !== $competitorItem->part_number)
                            <div class="help">DriveParts: {{ $drivePartsIdentifiers['sku'] }}</div>
                        @endif
                        <a href="{{ $itemUrl($competitorItem) }}">
                            <strong>{{ $itemName($competitorItem) }}</strong>
                        </a>
                        @if($sourceUrl)
                            <div class="help">
                                <a href="{{ $sourceUrl }}" target="_blank" rel="noopener">{{ $sourceUrl }}</a>
                            </div>
                        @endif
                        @foreach($teslaStatusBadges as $teslaStatusBadge)
                            <span class="catalog-badge">{{ $teslaStatusBadge }}</span>
                        @endforeach
                        <div class="catalog-item-names">
                            <span>
                                <b>RU</b> {{ $localizedNameBadges['ru']['text'] !== '' ? $localizedNameBadges['ru']['text'] : '—' }}
                                @if($localizedNameConflictText($competitorItem, 'ru') !== '')
                                    <span class="tag tag-conflict">{{ $localizedNameConflictText($competitorItem, 'ru') }}</span>
                                @endif
                            </span>
                            <span>
                                <b>UA</b> {{ $localizedNameBadges['ua']['text'] !== '' ? $localizedNameBadges['ua']['text'] : '—' }}
                                @if($localizedNameConflictText($competitorItem, 'ua') !== '')
                                    <span class="tag tag-conflict">{{ $localizedNameConflictText($competitorItem, 'ua') }}</span>
                                @endif
                            </span>
                            @if($localizedNameBadges['undetermined']['text'] !== '')
                                <span><b>Не определено</b> {{ $localizedNameBadges['undetermined']['text'] }}</span>
                            @endif
                        </div>
                        <div class="help">{{ $modelLabel($competitorItem) ?: '—' }}</div>
                    </td>
                    <td>
                        @if($priceSummary['has_price'])
                            {{ number_format($priceSummary['amount_usd'], 2, '.', ' ') }} USD
                            @if($priceSummary['amount_uah'] !== null)
                                <div class="help">&asymp; {{ number_format($priceSummary['amount_uah'], 2, '.', ' ') }} UAH &middot; {{ $priceSummary['rate_label'] }}</div>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        {{ $competitorItem->availability ?: '—' }}
                    </td>
                    <td>{{ $partOriginLabel !== '' ? $partOriginLabel : '—' }}</td>
                    <td>{{ $itemCondition($competitorItem) ?: '—' }}</td>
                    <td>{{ $competitorItem->created_at?->format('d.m.Y H:i') ?: '—' }}</td>
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
                    <td colspan="10" class="empty">{{ in_array($catalog['source'], ['all', 'tesla_official'], true) ? 'Позиции Tesla.com по выбранным фильтрам не найдены.' : 'Спарсенные запчасти этого конкурента еще не найдены.' }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $competitorCatalogItems->links() }}
        </div>
    </div>
@endif
