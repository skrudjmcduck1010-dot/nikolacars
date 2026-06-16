<div class="grid grid-2 {{ $catalog['source'] === 'nikolacars' ? 'catalog-stats--nikolacars' : '' }}" style="margin-bottom:18px;">
    @if(! $canExportCatalog && $catalog['source'] !== 'nikolacars')
        <div class="panel">
            <div class="help">{{ $catalog['category_count_label'] }}</div>
            <div class="stat">{{ $categoriesCount }}</div>
        </div>
    @endif
    <div class="panel">
        <div class="catalog-stat-heading">
            <div class="help">
                @if($catalog['source'] === 'nikolacars')
                    &#1047;&#1072;&#1087;&#1095;&#1072;&#1089;&#1090;&#1077;&#1081;
                @elseif($catalog['source'] === 'tesla_official')
                    Уникальных артикулов Tesla.com
                @elseif($canExportCatalog)
                    Уникальных артикулов в справочнике
                @else
                    Запчастей в справочнике
                @endif
            </div>
            @if($canExportCatalog || $catalog['source'] === 'tesla_official')
                <a
                    class="catalog-export-btn"
                    href="{{ route($catalog['route_prefix'].'.catalog-export') }}"
                    title="Скачать запчасти CSV"
                    aria-label="Скачать запчасти CSV"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <path d="M14 2v6h6"></path>
                        <path d="M8 13h8"></path>
                        <path d="M8 17h8"></path>
                        <path d="M10 9H8"></path>
                    </svg>
                </a>
            @endif
        </div>
        <div class="stat" data-catalog-items-count data-nikolacars-items-count>{{ $itemsCount }}</div>
        @if($catalog['source'] === 'nikolacars' && $nikolaCarsUniqueArticleCount !== null)
            <div class="help catalog-stat-subline">&#1059;&#1085;&#1080;&#1082;&#1072;&#1083;&#1100;&#1085;&#1099;&#1093; &#1072;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;&#1086;&#1074;: <span data-nikolacars-unique-articles-count>{{ $nikolaCarsUniqueArticleCount }}</span></div>
        @endif
        @if($catalog['source'] === 'nikolacars' && $nikolaCarsAddedTodayCount !== null)
            <div class="help catalog-stat-subline">&#1044;&#1086;&#1073;&#1072;&#1074;&#1083;&#1077;&#1085;&#1086; &#1089;&#1077;&#1075;&#1086;&#1076;&#1085;&#1103;: <span data-nikolacars-added-today-count>{{ $nikolaCarsAddedTodayCount }}</span></div>
        @endif
        @if($competitorCatalogItems && $competitorTotalProductsCount !== null)
            <div class="help catalog-stat-subline">Всего позиций: <span data-catalog-total-products-count>{{ $competitorTotalProductsCount }}</span></div>
        @endif
        @if($competitorRefresh !== null)
            <div class="help">
                {{ html_entity_decode('&#1055;&#1086;&#1089;&#1083;&#1077;&#1076;&#1085;&#1080;&#1081; &#1087;&#1072;&#1088;&#1089;&#1080;&#1085;&#1075;:') }} {{ $competitorRefresh['finished_label'] ?? $competitorRefresh['finished_at'] ?? '—' }}
                <br>
                {{ html_entity_decode('&#1044;&#1086;&#1073;&#1072;&#1074;&#1083;&#1077;&#1085;&#1086; &#1087;&#1086;&#1089;&#1083;&#1077; &#1087;&#1086;&#1089;&#1083;&#1077;&#1076;&#1085;&#1077;&#1075;&#1086;:') }} {{ (int) ($competitorRefresh['catalog_products_created'] ?? 0) }}
            </div>
        @endif
    </div>
    @if($catalog['source'] === 'nikolacars')
        <div class="panel">
            <div class="help">&#1057;&#1090;&#1086;&#1080;&#1084;&#1086;&#1089;&#1090;&#1100; &#1074;&#1089;&#1077;&#1093; &#1079;&#1072;&#1087;&#1095;&#1072;&#1089;&#1090;&#1077;&#1081;</div>
            <div class="stat" data-nikolacars-total-value>{{ number_format($nikolaCarsTotalValueUsd, 2, '.', ' ') }} USD</div>
        </div>
    @endif
</div>
