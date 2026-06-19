@extends('layouts.admin', [
    'heading' => $catalog['heading'],
    'subheading' => $catalog['source'] === 'nikolacars'
        ? html_entity_decode('Добавлено сегодня: ').number_format((int) ($nikolaCarsAddedTodayCount ?? 0), 0, '.', ' ')
        : 'Сначала выберите модель Tesla, затем категорию запчастей внутри нее',
])

@section('heading-actions')
    @php
        $competitorSiteUrl = $selectedCategory !== null && str_starts_with((string) $selectedCategory->source_url, 'http')
            ? $selectedCategory->source_url
            : ($catalog['site_url'] ?? null);
    @endphp
    @if($catalog['source'] === 'nikolacars')
        <span class="catalog-heading-stats">
            (Запчастей
            <span data-catalog-items-count data-nikolacars-items-count>{{ number_format((int) $itemsCount, 0, '.', ' ') }}</span>
            (<span data-nikolacars-unique-articles-count>{{ number_format((int) ($nikolaCarsUniqueArticleCount ?? 0), 0, '.', ' ') }}</span>
            уникальных артикулов),
            стоимость запчастей
            <span data-nikolacars-total-value>{{ number_format((float) $nikolaCarsTotalValueUsd, 2, '.', ' ') }} USD</span>)
        </span>
    @elseif(! empty($competitorSiteUrl))
        <a class="btn btn-small btn-secondary" href="{{ $competitorSiteUrl }}" target="_blank" rel="noopener">
            Сайт конкурента
        </a>
    @endif
@endsection

@section('topbar-actions')
    @if($catalog['source'] === 'nikolacars')
        <a class="btn btn-small" href="{{ route('admin.zapchasti.prom-export') }}">Выгрузка Prom</a>
        <a class="btn btn-small btn-secondary" href="{{ route('admin.deleted-parts.index') }}">Удаленные запчасти</a>
    @endif
@endsection

@section('content')
    @php
        $partCatalogPresenter = app(\App\View\Admin\PartCatalog\PartCatalogIndexPresenter::class);
        $refreshSourceLabel = $partCatalogPresenter->refreshSourceLabel($catalog);
        $canManageCompetitorRefresh = $partCatalogPresenter->canManageCompetitorRefresh();
        $competitorRefreshStartUrl = $partCatalogPresenter->competitorRefreshStartUrl($catalog);
        $partCatalogScriptConfig = $partCatalogPresenter->scriptConfig();
        $nikolaCarsUndeterminedCategory = $partCatalogPresenter->nikolaCarsUndeterminedCategory();
        $catalogImageUrls = fn (\App\Models\PartCatalogItem $item): \Illuminate\Support\Collection => $partCatalogPresenter->imageUrls($item);
        $isInvalidNikolaCarsPartNumber = fn (?string $partNumber): bool => $partCatalogPresenter->isInvalidNikolaCarsPartNumber($partNumber);
        $isDrivePartsSharedPlaceholderImageUrl = fn (string $url): bool => $partCatalogPresenter->isDrivePartsSharedPlaceholderImageUrl($url);
        $catalogNameBadge = fn (?string $value): array => $partCatalogPresenter->nameBadge($value);
        $catalogNameBadgeForItem = fn (\App\Models\PartCatalogItem $item, ?string $value): array => $partCatalogPresenter->nameBadgeForItem($item, $value);
        $catalogEnglishName = fn (\App\Models\PartCatalogItem $item): string => $partCatalogPresenter->englishName($item);
        $catalogTskUndeterminedName = fn (\App\Models\PartCatalogItem $item): string => $partCatalogPresenter->tskUndeterminedName($item);
        $catalogLocalizedNameBadges = fn (\App\Models\PartCatalogItem $item): array => $partCatalogPresenter->localizedNameBadges($item);
        $catalogLocalizedNameManualLocks = fn (\App\Models\PartCatalogItem $item): array => $partCatalogPresenter->localizedNameManualLocks($item);
        $catalogDrivePartsIdentifiers = fn (\App\Models\PartCatalogItem $item): array => $partCatalogPresenter->drivePartsIdentifiers($item);
        $catalogTeslaStatusBadges = fn (\App\Models\PartCatalogItem $item): array => $partCatalogPresenter->teslaStatusBadges($item);
        $catalogPriceSummary = fn (\App\Models\PartCatalogItem $item): array => $partCatalogPresenter->priceSummary($item, $usdRate, $priceSource ?? null);
        $catalogPartOriginLabel = fn (\App\Models\PartCatalogItem $item): string => $partCatalogPresenter->partOriginLabel($item);
        $catalogIsManuallySold = fn (\App\Models\PartCatalogItem $item): bool => $partCatalogPresenter->isManuallySold($item);
        $catalogCartProductId = fn (\App\Models\PartCatalogItem $item): int => $partCatalogPresenter->cartProductId($item);
        $catalogNameSource = fn (\App\Models\PartCatalogItem $item): array => $partCatalogPresenter->nameSource($item);
        $localizedNameConflictText = fn (\App\Models\PartCatalogItem $item, string $locale): string => $partCatalogPresenter->localizedNameConflictText($item, $locale);
        $nikolaCarsPartsCount = $items instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
            ? $items->total()
            : $partCatalogPresenter->nikolaCarsPartsCount($nikolaCarsItemGroups);
    @endphp

    @include('admin.part_catalog.partials.competitor-refresh-panel')
    @if($catalog['source'] !== 'nikolacars')
        @include('admin.part_catalog.partials.catalog-stats')
    @endif

    <div class="panel {{ $catalog['source'] === 'nikolacars' ? 'catalog-main-panel' : '' }}">
        @if($catalog['source'] === 'nikolacars' && ! $showNikolaCarsSoldItems)
            <button type="button" class="btn btn-small catalog-main-panel__action" data-open-nikolacars-part-dialog>Добавить Запчасть</button>
        @endif
        @include('admin.part_catalog.partials.catalog-filters')

        @include('admin.part_catalog.partials.category-browser')

        @if($items)
            <div style="margin-top:28px;">
                @if($catalog['source'] === 'nikolacars')
                    <div class="catalog-section-head">
                @endif
                <h2 class="section-title">
                    @if($catalog['source'] === 'nikolacars')
                        @if(! $showNikolaCarsSoldItems)
                            Все запчасти
                        @else
                        {{ $showNikolaCarsSoldItems ? 'Проданные запчасти НиколаКарз' : 'Запчасти НиколаКарз' }}
                        @endif
                        <span class="nikolacars-count-badge">
                            <span data-nikolacars-visible-rows-count>{{ $nikolaCarsPartsCount }}</span>
                        </span>
                    @else
                        {{ $catalog['source'] === 'tesla_official' ? 'Запчасти Tesla.com' : ($selectedCategory ? 'Товары в категории' : 'Найденные запчасти') }}
                    @endif
                </h2>
                @if($catalog['source'] === 'nikolacars')
                    </div>
                @endif

                @if($catalog['source'] === 'nikolacars')
                    @include('admin.part_catalog.partials.nikolacars-items')
                @else
                @include('admin.part_catalog.partials.generic-items-table')
                @endif

                @if($items instanceof \Illuminate\Contracts\Pagination\Paginator)
                    <div style="margin-top:16px;">
                        {{ $items->links() }}
                    </div>
                @endif
            </div>
        @endif

        @if($catalog['source'] === 'all' && ! $showCatalogItems)
            <div class="catalog-all-parts-prompt">
                <a class="btn btn-secondary" href="{{ request()->fullUrlWithQuery(['show_catalog_items' => 1, 'catalog_items_page' => null]) }}">
                    Показать все запчасти Tesla
                </a>
            </div>
        @endif

        @if($catalog['source'] !== 'nikolacars')
            <dialog class="catalog-photo-lightbox" data-catalog-photo-lightbox>
                <div class="catalog-photo-lightbox__toolbar">
                    <span data-catalog-photo-counter></span>
                    <button type="button" class="btn btn-secondary catalog-photo-lightbox__close" data-close-catalog-photo-lightbox aria-label="Закрыть">&times;</button>
                </div>
                <div class="catalog-photo-lightbox__stage">
                    <button type="button" class="btn btn-secondary catalog-photo-lightbox__nav catalog-photo-lightbox__nav--prev" data-catalog-photo-prev aria-label="Предыдущее фото">&#8249;</button>
                    <img src="" alt="" data-catalog-photo-lightbox-image>
                    <button type="button" class="btn btn-secondary catalog-photo-lightbox__nav catalog-photo-lightbox__nav--next" data-catalog-photo-next aria-label="Следующее фото">&#8250;</button>
                </div>
            </dialog>
        @endif

        @include('admin.part_catalog.partials.catalog-items-table')
        @include('admin.part_catalog.partials.competitor-catalog-items')
    </div>

    <script>
        window.partCatalogConfig = @json($partCatalogScriptConfig);
    </script>
    @vite('resources/js/admin/part-catalog.js')
@endsection
