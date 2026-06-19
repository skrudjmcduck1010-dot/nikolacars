<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyNikolaCarsCatalogItemPhotoRequest;
use App\Http\Requests\StoreNikolaCarsCatalogItemPhotosRequest;
use App\Http\Requests\StoreNikolaCarsCatalogItemRequest;
use App\Http\Requests\UpdateNikolaCarsCatalogItemCategoryRequest;
use App\Http\Requests\UpdateNikolaCarsCatalogItemRequest;
use App\Models\CompetitorCatalogRun;
use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\NikolaCarsCatalogCategoryService;
use App\Services\NikolaCarsCatalogItemService;
use App\Services\NikolaCarsCatalogListService;
use App\Services\NikolaCarsCatalogNameSuggestionService;
use App\Services\NikolaCarsCatalogProductSyncService;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Services\NikolaCarsPromYmlFeed;
use App\Services\PartCatalogCategoryRouteService;
use App\Services\PartCatalogCategoryTreeService;
use App\Services\PartCatalogCompetitorRefreshPayload;
use App\Services\PartCatalogCompetitorRefreshService;
use App\Services\PartCatalogDisplayService;
use App\Services\PartCatalogFilterService;
use App\Services\PartCatalogIndexFilters;
use App\Services\PartCatalogItemOrderingService;
use App\Services\PartCatalogManualNameService;
use App\Services\PartCatalogMissingNamesService;
use App\Services\PartCatalogSearchService;
use App\Services\PartCatalogSourceQueryService;
use App\Services\PartCatalogSourceStatsService;
use App\Services\TskCatalogImporter;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PartCatalogController extends Controller
{
    protected const CYBERTRUCK_MODEL_LABEL = 'Cybertruck';

    protected const UNCATEGORIZED_MODEL_LABEL = "\u{041D}\u{043E}\u{0432}\u{0430}\u{044F} \u{041A}\u{0430}\u{0442}\u{0435}\u{0433}\u{043E}\u{0440}\u{0438}\u{044F}";

    protected const MODEL_LABELS = [
        'Tesla Model 3',
        'Tesla Model 3 highland',
        'Tesla Model S',
        'Tesla Model S Plaid',
        'Tesla Model S Restyle',
        'Tesla Model X',
        'Tesla Model X Plaid',
        'Tesla Model Y',
        'Model S',
        'Model S 02.2012-03.2016',
        'Model S2 04.2016-01.2021',
        'Model S Palladium 02.2021-05.2025',
        'Model S 06.2025-',
        'Model X 09.2015-02.2021',
        'Model X Palladium 03.2021-05.2025',
        'Model X 06.2025-',
        'Model S до 2016',
        'Model S после 2016',
        'Model S after 2016',
        'Model S до 2016',
        'Model S після 2016',
        'Model S Plaid',
        'Tesla Model X',
        'Model X Plaid',
        'Tesla Model 3',
        'TESLA MODEL Y',
        'Model X',
        'Model 3 06.2017 - 12.2023',
        'Model 3 Highland 01.2024 -',
        'Model 3',
        "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} 3",
        'Model Y 01.2020 - 01.2025',
        'Model Y Juniper 02.2025 -',
        'Model Y',
        "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} Y",
        "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} X",
        "MODEL S \u{0434}\u{043E} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
        "MODEL S \u{043F}\u{0456}\u{0441}\u{043B}\u{044F} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
        'Model S Palladium 02.2021-',
        'Model X Palladium 03.2021-',
        self::CYBERTRUCK_MODEL_LABEL,
        self::UNCATEGORIZED_MODEL_LABEL,
    ];

    protected const SOURCE_PRIORITY = [
        'tesla_official' => 1,
        'tcarservice' => 10,
        'teslapartsukraine' => 20,
        'tsk' => 25,
        'teslahelp' => 26,
        'stock-tesla' => 27,
        'driveparts' => 30,
        'dkparts' => 40,
        'erazborka' => 45,
        'toprazborka' => 46,
        'teslawestparts' => 47,
        'teslacompany' => 50,
        'nikolacars' => 60,
    ];

    protected array $modelOptionsCache = [];

    protected ?PartCatalogDisplayService $catalogDisplayService = null;

    public function index(Request $request, ?string $catalogPath = null): View
    {
        $source = $this->catalogSource($request);
        $filters = PartCatalogIndexFilters::fromRequest(
            $request,
            $this->allowedModelLabels($source),
        );
        $query = $filters->query;
        $selectedModels = $filters->selectedModels;
        $filterModels = $filters->filterModels;
        $filterModelLabels = $this->modelLabelQueryValues($filterModels);
        $urlModels = $filters->urlModels;
        $model = $filters->model;
        $includeCybertruck = $filters->includeCybertruck;
        $missingNames = $filters->missingNames;
        $productFilters = $filters->productFilters;
        $catalogItemsPriceSort = $filters->catalogItemsPriceSort;
        $competitorSort = $filters->competitorSort;
        $competitorSortDirection = $filters->competitorSortDirection;
        $catalogImageFilter = $filters->catalogImageFilter;
        $competitorNameFilter = $filters->competitorNameFilter;
        $teslaCheckFilter = $filters->teslaCheckFilter;
        $teslaVisualFilter = $filters->teslaVisualFilter;
        $nikolaCarsSort = $filters->nikolaCarsSort;
        $nikolaCarsSortDirection = $filters->nikolaCarsSortDirection;
        $nikolaCarsVin = $filters->nikolaCarsVin;
        $nikolaCarsVins = $filters->nikolaCarsVins;
        $nikolaCarsTopCategories = $filters->nikolaCarsTopCategories;
        $hideNikolaCarsSold = $filters->hideNikolaCarsSold;
        $nameSource = $filters->nameSource;
        $showCatalogItems = $filters->showCatalogItems;
        $driver = DB::connection()->getDriverName();
        $catalog = $this->catalogConfig($source);
        $catalog['site_url'] = $this->catalogSiteUrl($source);
        $catalog['parsing_logic'] = $this->catalogParsingLogic($source);

        $categoryId = (int) $request->query('category_id');
        $selectedCategory = $categoryId > 0
            ? PartCatalogCategory::query()
                ->tap(fn (Builder $builder) => $this->sourceFilteredQuery($builder, $source))
                ->whereKey($categoryId)
                ->select(['id', 'parent_id', 'source', 'name', 'name_en', 'name_ru', 'name_ua', 'code', 'source_url', 'depth', 'model_label'])
                ->firstOrFail()
            : ($catalogPath
                ? PartCatalogCategory::query()
                    ->tap(fn (Builder $builder) => $this->sourceFilteredQuery($builder, $source))
                    ->where(
                        $source === 'tcarservice' ? 'source_url' : 'id',
                        $source === 'tcarservice'
                            ? 'https://tcarservice.com/zapchasty/'.trim($catalogPath, '/')
                            : $this->categoryIdByCatalogPath($source, $catalogPath)
                    )
                    ->select(['id', 'parent_id', 'source', 'name', 'name_en', 'name_ru', 'name_ua', 'code', 'source_url', 'depth', 'model_label'])
                    ->firstOrFail()
                : null);
        $selectedCategoryHasChildren = $selectedCategory !== null
            && PartCatalogCategory::query()
                ->tap(fn (Builder $builder) => $this->sourceFilteredQuery($builder, $source))
                ->where('parent_id', $selectedCategory->id)
                ->exists();
        $showCategoryBlocks = $selectedCategory !== null
            && (int) $selectedCategory->depth === 0
            && $query === ''
            && $selectedCategoryHasChildren;

        $categoriesQuery = PartCatalogCategory::query()
            ->with('parent:id,name,code')
            ->withCount(['children', 'items'])
            ->tap(fn (Builder $builder) => $this->sourceFilteredQuery($builder, $source))
            ->when(
                $selectedCategory,
                fn (Builder $builder) => $builder->where('parent_id', $selectedCategory->id),
                fn (Builder $builder) => $builder
                    ->where('depth', 0)
                    ->whereNull('parent_id')
                    ->when(
                        $source !== 'nikolacars',
                        fn (Builder $modelBuilder) => $modelBuilder
                            ->whereNotNull('model_label')
                            ->whereIn('model_label', $this->modelLabelQueryValues(self::MODEL_LABELS))
                    )
            )
            ->when(
                $filterModelLabels !== [],
                fn (Builder $builder) => $builder->whereIn('model_label', $filterModelLabels)
            );

        if ($selectedCategory === null && $query === '') {
            $this->orderModelCategories($categoriesQuery);
        } else {
            $categoriesQuery
                ->orderBy('depth')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->orderBy('name');
        }

        if ($query !== '') {
            $likeQuery = '%'.$query.'%';

            if ($driver === 'sqlite') {
                $ids = PartCatalogCategory::query()
                    ->tap(fn (Builder $builder) => $this->sourceFilteredQuery($builder, $source))
                    ->when($filterModelLabels !== [], fn (Builder $builder) => $builder->whereIn('model_label', $filterModelLabels))
                    ->get(['id', 'name', 'name_en', 'name_ru', 'name_ua', 'code', 'model_label', 'model_name'])
                    ->filter(fn (PartCatalogCategory $category) => collect([
                        $category->name,
                        $category->name_en,
                        $category->name_ru,
                        $category->name_ua,
                        $category->code,
                        $category->model_label,
                        $category->model_name,
                    ])->filter()->contains(fn (string $value) => mb_stripos($value, $query) !== false))
                    ->pluck('id');

                $categoriesQuery->whereIn('id', $ids);
            } elseif ($driver === 'pgsql') {
                $categoriesQuery->where(fn (Builder $builder) => $builder
                    ->where('name', 'ilike', $likeQuery)
                    ->orWhere('name_en', 'ilike', $likeQuery)
                    ->orWhere('name_ru', 'ilike', $likeQuery)
                    ->orWhere('name_ua', 'ilike', $likeQuery)
                    ->orWhere('code', 'ilike', $likeQuery)
                    ->orWhere('model_label', 'ilike', $likeQuery)
                    ->orWhere('model_name', 'ilike', $likeQuery));
            } else {
                $categoriesQuery->where(fn (Builder $builder) => $builder
                    ->where('name', 'like', $likeQuery)
                    ->orWhere('name_en', 'like', $likeQuery)
                    ->orWhere('name_ru', 'like', $likeQuery)
                    ->orWhere('name_ua', 'like', $likeQuery)
                    ->orWhere('code', 'like', $likeQuery)
                    ->orWhere('model_label', 'like', $likeQuery)
                    ->orWhere('model_name', 'like', $likeQuery));
            }
        }

        $categories = $categoriesQuery->paginate(50)->withQueryString();

        if ($source !== 'nikolacars' && $selectedCategory === null && $query === '') {
            $categories->setCollection($this->deduplicateCategories($categories->getCollection()));
        }

        $categoryCollection = $categories instanceof Collection
            ? $categories
            : $categories->getCollection();

        if (! $showCategoryBlocks && ! $this->shouldSkipBranchItemCounts($source)) {
            $this->appendBranchItemCounts($categoryCollection, $source);
        }
        $this->appendPreviewFallbacks($categoryCollection);

        $items = null;
        $catalogItems = null;
        $competitorCatalogItems = null;
        $competitorCatalogImageCounts = ['total' => null, 'with' => null, 'without' => null];
        $competitorCatalogNameCounts = ['conflict' => null, 'missing_ru' => null, 'missing_ua' => null];
        $teslaCheckCounts = ['total' => null, 'checked' => null, 'unchecked' => null, 'exact' => null, 'similar' => null, 'not_found' => null, 'api_error' => null];
        $competitorTotalProductsCount = null;
        $canExportCatalog = in_array($source, [
            'tcarservice',
            'teslapartsukraine',
            'tsk',
            'stock-tesla',
            'teslahelp',
            'driveparts',
            'dkparts',
            'erazborka',
            'toprazborka',
            'teslawestparts',
            'teslacompany',
        ], true);
        $canShowSourceCatalogItems = $canExportCatalog || $source === 'tesla_official';
        $showUniquePartsCount = $canShowSourceCatalogItems;
        $nikolaCarsItemGroups = collect();
        $nikolaCarsChildItemGroupsById = collect();
        $nikolaCarsDonorCarsByVin = collect();
        $nikolaCarsDamageStatusUsersById = collect();
        $usdRate = app(ExchangeRateService::class)->displayUsdRate();
        $nikolaCarsCatalogItems = app(NikolaCarsCatalogItemService::class);
        $nikolaCarsCatalogList = app(NikolaCarsCatalogListService::class);
        $nikolaCarsSummaryItems = $source === 'nikolacars'
            ? $nikolaCarsCatalogList->activeSummaryItems()
            : null;

        $nikolaCarsVinOptions = $source === 'nikolacars'
            ? $nikolaCarsCatalogList->donorFilterOptions($nikolaCarsSummaryItems)
            : collect();
        $nikolaCarsDonorFilterCarsByVin = $source === 'nikolacars'
            ? $nikolaCarsCatalogList->donorCarsByVinOptions($nikolaCarsVinOptions)
            : collect();
        $nikolaCarsTopCategoryOptions = $source === 'nikolacars'
            ? $nikolaCarsCatalogList->topCategoryFilterOptions($nikolaCarsSummaryItems)
            : collect();
        $nikolaCarsTotalValueUsd = $source === 'nikolacars'
            ? $nikolaCarsCatalogList->inventoryTotalUsd(
                $nikolaCarsSummaryItems,
                $usdRate
            )
            : 0.0;
        $nikolaCarsCreateWarehouses = $source === 'nikolacars'
            ? $nikolaCarsCatalogItems->activeWarehousesForCreate()
            : collect();
        $nikolaCarsCreateDonors = $source === 'nikolacars'
            ? $nikolaCarsCatalogItems->donorOptionsForCreate()
            : collect();
        $totalItemsCount = $source === 'nikolacars'
            ? null
            : $this->cachedCatalogCount('items', $source, fn (): int => $this->sourceFilteredQuery(PartCatalogItem::query(), $source)->count());
        $nikolaCarsUniqueArticleCount = $source === 'nikolacars'
            ? $nikolaCarsCatalogList->uniqueArticleCount($nikolaCarsSummaryItems)
            : null;
        $nikolaCarsAddedTodayCount = $source === 'nikolacars'
            ? $nikolaCarsCatalogList->addedTodayCount($nikolaCarsSummaryItems)
            : null;
        $itemsCount = $source === 'nikolacars'
            ? $nikolaCarsCatalogList->itemsCount($nikolaCarsSummaryItems)
            : ($showUniquePartsCount ? $this->cachedUniquePartsCount($source) : $totalItemsCount);
        $competitorTotalProductsCount = $showUniquePartsCount && $selectedCategory === null ? $totalItemsCount : null;
        $showRootItemList = $source === 'nikolacars'
            && $selectedCategory === null;
        $hasSourceCatalogItemRequest = $filters->hasSourceCatalogItemRequest;
        $isTeslaOfficialRootCatalog = $source === 'tesla_official' && $selectedCategory === null;
        $showSourceCatalogItems = $canShowSourceCatalogItems
            && $query === ''
            && (! $showCategoryBlocks || $hasSourceCatalogItemRequest)
            && (
                ! $isTeslaOfficialRootCatalog
                || ! $request->has('category_id')
                || $hasSourceCatalogItemRequest
            );
        $sourceCatalogStats = app(PartCatalogSourceStatsService::class)->countsFor($source);
        if ($sourceCatalogStats !== null) {
            $competitorCatalogImageCounts = $sourceCatalogStats['image'];
            $competitorCatalogNameCounts = $sourceCatalogStats['name'];
        }
        $hasActiveSourceCatalogFilter = $catalogImageFilter !== ''
            || $competitorNameFilter !== ''
            || $teslaCheckFilter !== ''
            || $teslaVisualFilter !== '';
        $shouldLoadSourceCatalogFilterCounts = $sourceCatalogStats === null
            && ($selectedCategory === null || $hasActiveSourceCatalogFilter);

        if (! $showSourceCatalogItems && (($selectedCategory && ! $showCategoryBlocks) || $query !== '' || $showRootItemList)) {
            $itemsQuery = PartCatalogItem::query()
                ->tap(fn (Builder $builder) => $this->sourceFilteredQuery($builder, $source, $hideNikolaCarsSold))
                ->when($selectedCategory, function (Builder $builder) use ($selectedCategory, $source): void {
                    $builder->where(function (Builder $categoryBuilder) use ($selectedCategory, $source): void {
                        $categoryBuilder->where('part_catalog_category_id', $selectedCategory->id);

                        $categoryBuilder->orWhereHas('occurrences', function (Builder $occurrenceBuilder) use ($selectedCategory, $source): void {
                            $occurrenceBuilder
                                ->where('source', $source)
                                ->where('part_catalog_category_id', $selectedCategory->id);
                        });

                        if ($source === 'tesla_official') {
                            $categoryBuilder
                                ->orWhere('raw_attributes', 'like', '%"category_id":'.$selectedCategory->id.'%')
                                ->orWhere('raw_attributes', 'like', '%"category_id": '.$selectedCategory->id.'%');
                        }
                    });
                })
                ->when($filterModelLabels !== [], function (Builder $builder) use ($filterModelLabels, $source): void {
                    $builder->where(function (Builder $modelBuilder) use ($filterModelLabels, $source): void {
                        $modelBuilder->whereIn('model_label', $filterModelLabels);

                        if ($source === 'tesla_official') {
                            foreach ($filterModelLabels as $modelLabel) {
                                $modelBuilder->orWhere('compatibility_text', 'like', '%'.$modelLabel.'%');
                            }
                        }
                    });
                })
                ->when(
                    $source !== 'nikolacars',
                    fn (Builder $builder) => $builder
                        ->orderByRaw('scheme_number is null')
                        ->orderBy('scheme_number')
                        ->orderBy('name')
                );

            if ($query !== '') {
                $this->applyItemSearch($itemsQuery, $query, $driver, $source);
            }

            if ($source === 'nikolacars') {
                $nikolaCarsCatalogList->applyVinFilter($itemsQuery, $nikolaCarsVins, $driver);
                $nikolaCarsCatalogList->orderByAvailabilityBucket($itemsQuery, $driver);
                app(PartCatalogItemOrderingService::class)->orderNikolaCarsItems($itemsQuery, $nikolaCarsSort, $nikolaCarsSortDirection);

                if ($nikolaCarsTopCategories === []) {
                    $items = $itemsQuery
                        ->paginate(100, ['*'], 'items_page')
                        ->withQueryString();
                    $nikolaCarsItemGroups = $nikolaCarsCatalogList->itemGroups(
                        $items->getCollection(),
                        $usdRate,
                        fn (PartCatalogItem $item): string => $this->displayItemName($item),
                    );
                    $items->setCollection($nikolaCarsItemGroups);
                } else {
                    $items = $nikolaCarsCatalogList->filterItemsByTopCategory($itemsQuery->get(), $nikolaCarsTopCategories);
                    $nikolaCarsGroupsPaginator = $nikolaCarsCatalogList->paginateItemGroups(
                        $nikolaCarsCatalogList->itemGroups(
                            $items,
                            $usdRate,
                            fn (PartCatalogItem $item): string => $this->displayItemName($item),
                        ),
                        $request,
                    );
                    $nikolaCarsItemGroups = $nikolaCarsGroupsPaginator->getCollection();
                    $items = $nikolaCarsGroupsPaginator;
                }

                $nikolaCarsGroupedItemGroups = $nikolaCarsItemGroups;
                $nikolaCarsGroupItems = $nikolaCarsGroupedItemGroups
                    ->flatMap(fn (array $group): Collection => $group['items'])
                    ->values();
                $nikolaCarsIndividualGroupsById = $nikolaCarsCatalogList->itemGroupsForIndividualItems(
                    $nikolaCarsGroupItems,
                    $usdRate,
                    fn (PartCatalogItem $item): string => $this->displayItemName($item),
                );
                $nikolaCarsItemGroups = $nikolaCarsGroupedItemGroups
                    ->flatMap(function (array $group) use ($nikolaCarsIndividualGroupsById): Collection {
                        $isAdjacentDuplicate = (int) ($group['count'] ?? 0) > 1;

                        return $group['items']
                            ->map(function (PartCatalogItem $item) use ($group, $isAdjacentDuplicate, $nikolaCarsIndividualGroupsById): ?array {
                                $itemGroup = $nikolaCarsIndividualGroupsById->get($item->getKey());
                                if ($itemGroup === null) {
                                    return null;
                                }

                                $itemGroup['is_adjacent_duplicate'] = $isAdjacentDuplicate;
                                $itemGroup['adjacent_duplicate_count'] = (int) ($group['count'] ?? 1);

                                return $itemGroup;
                            })
                            ->filter()
                            ->values();
                    })
                    ->values();
                $items->setCollection($nikolaCarsItemGroups);
                $nikolaCarsDonorCarsByVin = $nikolaCarsCatalogList->donorCarsByVinFromItems($nikolaCarsGroupItems);
                $nikolaCarsDamageStatusUsersById = $this->nikolaCarsDamageStatusUsersById($nikolaCarsGroupItems);
            } else {
                $items = $itemsQuery->paginate(50, ['*'], 'items_page')->withQueryString();
            }

        }

        if ($showCatalogItems) {
            $catalogItemsQuery = PartCatalogItem::query()
                ->when(
                    $nameSource === '',
                    fn (Builder $builder) => $builder->where('source', 'tesla_official')
                )
                ->when(
                    $nameSource === 'aleto',
                    fn (Builder $builder) => $this->whereNameSource($builder->where('source', 'tesla_official'), 'aleto.ua')
                )
                ->when(
                    $nameSource === 'teslashop',
                    fn (Builder $builder) => $this->whereNameSource($builder->where('source', 'tesla_official'), 'teslashop.by')
                )
                ->when($filterModelLabels !== [], fn (Builder $builder) => $builder->whereIn('model_label', $filterModelLabels))
                ->when(
                    $filterModels === [] && ! $includeCybertruck && $nameSource === '',
                    fn (Builder $builder) => $this->withoutCybertruck($builder)
                )
                ->when(in_array('ru', $missingNames, true), fn (Builder $builder) => $this->whereBlank($builder, 'name_ru'))
                ->when(in_array('ua', $missingNames, true), fn (Builder $builder) => $this->whereBlank($builder, 'name_ua'))
                ->when(in_array('errors', $productFilters, true), fn (Builder $builder) => $this->whereLongPartNumber($builder, $driver));

            if ($query !== '') {
                $this->applyItemSearch($catalogItemsQuery, $query, $driver);
            }

            if ($catalogItemsPriceSort !== null) {
                $catalogItemsQuery
                    ->orderByRaw('price_amount is null')
                    ->orderBy('price_amount', $catalogItemsPriceSort)
                    ->orderBy('model_label')
                    ->orderBy('name')
                    ->orderBy('part_number');
            } elseif ($query === '') {
                $catalogItemsQuery
                    ->orderBy('model_label')
                    ->orderBy('name')
                    ->orderBy('part_number');
            }

            $catalogItemColumns = [
                'id',
                'source',
                'source_url',
                'part_number',
                'name',
                'name_en',
                'name_ru',
                'name_ua',
                'price_amount',
                'currency',
                'compatibility_text',
                'model_label',
                'model_name',
                'raw_attributes',
            ];

            $catalogItems = $this->canUseMissingNamesPaginator($query, $missingNames, $productFilters, $nameSource, $catalogItemsPriceSort)
                ? $this->missingNamesCatalogItemsPaginator($filterModels, $includeCybertruck, $catalogItemColumns)
                : $catalogItemsQuery
                    ->simplePaginate(100, $catalogItemColumns, 'catalog_items_page')
                    ->withQueryString();
        }

        if ($showSourceCatalogItems) {
            $competitorCatalogItemsQuery = PartCatalogItem::query()
                ->with('category:id,name,name_ru,name_ua')
                ->tap(fn (Builder $builder) => $this->sourceFilteredQuery($builder, $source));

            $partCatalogFilter = app(PartCatalogFilterService::class);

            if ($selectedCategory !== null) {
                $branchCategoryIds = $this->categoryBranchIds($selectedCategory);
                if ($selectedCategory->source === 'dkparts' && (int) $selectedCategory->depth === 0) {
                    $branchCategoryIds = [(int) $selectedCategory->id];
                }

                $this->whereInSelectedCatalogBranch($competitorCatalogItemsQuery, $selectedCategory, $branchCategoryIds);
            }

            if ($source === 'tesla_official') {
                $shouldLoadTeslaCheckCounts = ! $isTeslaOfficialRootCatalog
                    && ($teslaCheckFilter !== ''
                        || $teslaVisualFilter !== ''
                        || $catalogImageFilter !== ''
                        || $competitorNameFilter !== '');

                if ($shouldLoadTeslaCheckCounts) {
                    $teslaCheckCountsQuery = clone $competitorCatalogItemsQuery;
                    $partCatalogFilter->applyCompetitorNameFilter($teslaCheckCountsQuery, $competitorNameFilter);
                    $partCatalogFilter->applyCatalogImageFilter($teslaCheckCountsQuery, $catalogImageFilter);
                    $partCatalogFilter->applyTeslaVisualFilter($teslaCheckCountsQuery, $teslaVisualFilter);
                    $teslaCheckCounts = $partCatalogFilter->teslaCheckFilterCounts($teslaCheckCountsQuery);
                }

                $partCatalogFilter->applyTeslaCheckFilter($competitorCatalogItemsQuery, $teslaCheckFilter);
                $partCatalogFilter->applyTeslaVisualFilter($competitorCatalogItemsQuery, $teslaVisualFilter);
            }

            if ($shouldLoadSourceCatalogFilterCounts) {
                $competitorCatalogNameCountsQuery = clone $competitorCatalogItemsQuery;
                $partCatalogFilter->applyCatalogImageFilter($competitorCatalogNameCountsQuery, $catalogImageFilter);
                $competitorCatalogNameCounts = $partCatalogFilter->catalogNameFilterCounts($competitorCatalogNameCountsQuery);
            }

            $partCatalogFilter->applyCompetitorNameFilter($competitorCatalogItemsQuery, $competitorNameFilter);
            if (! $isTeslaOfficialRootCatalog && $shouldLoadSourceCatalogFilterCounts) {
                $competitorCatalogImageCounts = $partCatalogFilter->catalogImageFilterCounts($competitorCatalogItemsQuery);
            }
            $partCatalogFilter->applyCatalogImageFilter($competitorCatalogItemsQuery, $catalogImageFilter);

            if ($competitorSort !== null) {
                app(PartCatalogItemOrderingService::class)->orderCompetitorCatalogItems($competitorCatalogItemsQuery, $competitorSort, $competitorSortDirection);
            } elseif ($source === 'teslapartsukraine') {
                $competitorCatalogItemsQuery
                    ->orderByDesc('created_at')
                    ->orderByRaw("cast(json_unquote(json_extract(raw_attributes, '$.listing_model_sort_order')) as unsigned)")
                    ->orderByRaw("cast(json_unquote(json_extract(raw_attributes, '$.listing_sort_order')) as unsigned)")
                    ->orderBy('id');
            } elseif ($isTeslaOfficialRootCatalog) {
                $competitorCatalogItemsQuery
                    ->orderBy('source_url')
                    ->orderBy('id');
            } else {
                $competitorCatalogItemsQuery
                    ->orderByDesc('created_at')
                    ->orderByDesc('id');
            }

            $competitorCatalogColumns = [
                'id',
                'part_catalog_category_id',
                'source',
                'source_url',
                'part_number',
                'name',
                'name_en',
                'name_ru',
                'name_ua',
                'price_amount',
                'currency',
                'condition',
                'model_label',
                'model_name',
                'availability',
                'created_at',
                'source_updated_at',
                'raw_attributes',
            ];
            $shouldUseSimpleSourceCatalogPagination = $isTeslaOfficialRootCatalog
                || $this->shouldUseSimpleSourceCatalogPagination(
                    $source,
                    $catalogImageFilter,
                    $competitorNameFilter,
                    $teslaCheckFilter,
                    $teslaVisualFilter,
                    $competitorSort
                );
            $competitorCatalogItems = $shouldUseSimpleSourceCatalogPagination
                ? $competitorCatalogItemsQuery
                    ->simplePaginate(50, $competitorCatalogColumns, 'competitor_items_page')
                    ->withQueryString()
                : $competitorCatalogItemsQuery
                    ->paginate(50, $competitorCatalogColumns, 'competitor_items_page')
                    ->withQueryString();
        }

        return view('admin.part_catalog.index', [
            'categories' => $categories,
            'items' => $items,
            'catalogItems' => $catalogItems,
            'competitorCatalogItems' => $competitorCatalogItems,
            'competitorTotalProductsCount' => $competitorTotalProductsCount,
            'canExportCatalog' => $canExportCatalog,
            'showCatalogItems' => $showCatalogItems,
            'nikolaCarsItemGroups' => $nikolaCarsItemGroups,
            'nikolaCarsChildItemGroupsById' => $nikolaCarsChildItemGroupsById,
            'nikolaCarsDonorCarsByVin' => $nikolaCarsDonorCarsByVin,
            'nikolaCarsDamageStatusUsersById' => $nikolaCarsDamageStatusUsersById,
            'nikolaCarsDonorFilterCarsByVin' => $nikolaCarsDonorFilterCarsByVin,
            'nikolaCarsVin' => $nikolaCarsVin,
            'nikolaCarsVins' => $nikolaCarsVins,
            'nikolaCarsVinOptions' => $nikolaCarsVinOptions,
            'nikolaCarsTopCategories' => $nikolaCarsTopCategories,
            'nikolaCarsTopCategoryOptions' => $nikolaCarsTopCategoryOptions,
            'nikolaCarsTotalValueUsd' => $nikolaCarsTotalValueUsd,
            'nikolaCarsUniqueArticleCount' => $nikolaCarsUniqueArticleCount,
            'nikolaCarsAddedTodayCount' => $nikolaCarsAddedTodayCount,
            'nikolaCarsCreateWarehouses' => $nikolaCarsCreateWarehouses,
            'nikolaCarsCreateDonors' => $nikolaCarsCreateDonors,
            'hideNikolaCarsSold' => $hideNikolaCarsSold,
            'showNikolaCarsSoldItems' => $source === 'nikolacars' && ! $hideNikolaCarsSold,
            'query' => $query,
            'model' => $model,
            'selectedModels' => $selectedModels,
            'includeCybertruck' => $includeCybertruck,
            'missingNames' => $missingNames,
            'productFilters' => $productFilters,
            'nameSource' => $nameSource,
            'catalogItemsPriceSort' => $catalogItemsPriceSort,
            'competitorSort' => $competitorSort,
            'competitorSortDirection' => $competitorSortDirection,
            'catalogImageFilter' => $catalogImageFilter,
            'competitorNameFilter' => $competitorNameFilter,
            'teslaCheckFilter' => $teslaCheckFilter,
            'teslaVisualFilter' => $teslaVisualFilter,
            'competitorCatalogImageCounts' => $competitorCatalogImageCounts,
            'competitorCatalogNameCounts' => $competitorCatalogNameCounts,
            'teslaCheckCounts' => $teslaCheckCounts,
            'nikolaCarsSort' => $nikolaCarsSort,
            'nikolaCarsSortDirection' => $nikolaCarsSortDirection,
            'selectedCategory' => $selectedCategory,
            'isModelLevel' => $selectedCategory === null,
            'showRootItemList' => $showRootItemList,
            'showCategoryBlocks' => $showCategoryBlocks,
            'showSourceCatalogItems' => $showSourceCatalogItems,
            'categoryBlocks' => $showCategoryBlocks ? $this->modelCategoryBlocks($selectedCategory, $source) : collect(),
            'categoryTrail' => $this->categoryTrail($selectedCategory),
            'models' => $this->modelOptions($source, $selectedModels),
            'itemsCount' => $itemsCount,
            'categoriesCount' => $this->cachedCatalogCount('categories', $source, fn (): int => $this->sourceFilteredQuery(PartCatalogCategory::query(), $source)->count()),
            'competitorRefresh' => $this->competitorRefreshPayload($source),
            'categoryPath' => $selectedCategory ? $this->catalogPath($selectedCategory) : $catalogPath,
            'catalog' => $catalog,
            'searchUrl' => route($catalog['route_prefix'].'.search'),
            'itemUrl' => fn (PartCatalogItem $item): string => $this->itemUrl($item, $nameSource !== '' ? $this->routePrefixForItem($item) : $catalog['route_prefix']),
            'sourceExternalUrl' => fn (PartCatalogItem $item): ?string => $this->displayableSourceUrl($item),
            'categoryUrl' => fn (?PartCatalogCategory $category, bool $includeModel = true): string => $this->categoryUrl($category, $source, $includeModel ? $urlModels : [], $includeModel && $includeCybertruck),
            'categoryName' => fn (PartCatalogCategory $category): string => $this->displayCategoryName($category),
            'itemName' => fn (PartCatalogItem $item): string => $this->displayItemName($item),
            'itemCondition' => fn (PartCatalogItem $item): ?string => $this->displayItemCondition($item),
            'itemPartType' => fn (PartCatalogItem $item): ?string => $this->displayItemPartType($item),
            'modelLabel' => fn (mixed $value): string => $this->displayModelLabel($value),
            'priceSource' => fn (PartCatalogItem $item): array => [
                'label' => $this->catalogConfig($item->source)['source_label'] ?? $item->source,
                'url' => $item->source_url,
            ],
            'nikolaCarsSortUrl' => fn (string $sort): string => request()->fullUrlWithQuery([
                'sort' => $sort,
                'direction' => $nikolaCarsSort === $sort && $nikolaCarsSortDirection === 'asc' ? 'desc' : 'asc',
                'items_page' => null,
            ]),
            'usdRate' => $usdRate,
        ]);
    }

    public function show(Request $request, PartCatalogItem $partCatalogItem): View|RedirectResponse
    {
        $source = $this->catalogSource($request, $partCatalogItem->source);

        if ($partCatalogItem->source !== $source) {
            return redirect()->route($this->routePrefixForItem($partCatalogItem).'.show', $partCatalogItem);
        }

        if ($source === 'tesla_official' && ! $this->isRealTeslaOfficialItem($partCatalogItem)) {
            abort(404);
        }

        if ($source === 'nikolacars') {
            $product = $this->nikolaCarsProductForItem($partCatalogItem);

            if (! $product instanceof Product) {
                $syncResult = app(NikolaCarsCatalogProductSyncService::class)->syncItem($partCatalogItem);
                $product = $syncResult['product'] instanceof Product
                    ? $syncResult['product']
                    : $this->nikolaCarsProductForItem($partCatalogItem->fresh());
            }

            if ($product instanceof Product) {
                return redirect()->route('admin.products.show', $product);
            }
        }

        $this->refreshMissingTskPrice($partCatalogItem);
        $usdRate = app(ExchangeRateService::class)->displayUsdRate();
        $nikolaCarsRelatedItems = $this->nikolaCarsRelatedItems($partCatalogItem);
        $nikolaCarsDonorCarsByVin = $this->nikolaCarsDonorCarsByVin($partCatalogItem, $nikolaCarsRelatedItems);
        $catalogItemDonorCars = $this->catalogItemDonorCars($partCatalogItem, $nikolaCarsRelatedItems);
        $teslaRelatedFindPartResults = $this->relatedTeslaFindPartResults($partCatalogItem);
        $teslaFindPartItemIds = $this->teslaFindPartItemIds($partCatalogItem, $teslaRelatedFindPartResults);
        $priceHistory = ProductPriceHistory::query()
            ->where('part_catalog_item_id', $partCatalogItem->id)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('admin.part_catalog.show', [
            'item' => $partCatalogItem->load([
                'category.parent.parent.parent',
                'sales' => fn (HasMany $query) => $query->orderByDesc('sold_at')->orderByDesc('id'),
                'sales.donorCar:id,vin,model,year,color',
            ]),
            'heading' => $this->displayItemName($partCatalogItem),
            'localizedNameSources' => $this->localizedNameSources($partCatalogItem),
            'sourceUrl' => $this->displayableSourceUrl($partCatalogItem),
            'itemName' => fn (PartCatalogItem $item): string => $this->displayItemName($item),
            'itemCondition' => fn (PartCatalogItem $item): ?string => $this->displayItemCondition($item),
            'itemPartType' => fn (PartCatalogItem $item): ?string => $this->displayItemPartType($item),
            'sourceLabel' => fn (PartCatalogItem|string|null $source): string => $source instanceof PartCatalogItem
                ? $this->sourceLabel($source->source)
                : $this->sourceLabel((string) $source),
            'sourceExternalUrl' => fn (PartCatalogItem $item): ?string => $this->displayableSourceUrl($item),
            'modelLabel' => fn (mixed $value): string => $value instanceof PartCatalogItem
                ? (string) ($value->model_label ?: $value->model_name ?: '')
                : (string) $value,
            'priceSource' => fn (PartCatalogItem $item): array => [
                'label' => $this->catalogConfig($item->source)['source_label'] ?? $item->source,
                'url' => $item->source_url,
            ],
            'nikolaCarsDescription' => fn (PartCatalogItem $item, ?string $description): string => $this->displayNikolaCarsDescription($item, $description),
            'catalog' => $this->catalogConfig($source),
            'usdRate' => $usdRate,
            'partNumberDisplayName' => $this->teslaOfficialNameWithAnnotation(
                trim((string) ($partCatalogItem->name_en ?: $partCatalogItem->name)),
                $partCatalogItem
            ),
            'priceHistory' => $priceHistory,
            'catalogItemDonorCars' => $catalogItemDonorCars,
            'teslaOfficialOccurrenceCategories' => $this->teslaOfficialOccurrenceCategories($partCatalogItem),
            'teslaRelatedFindPartResults' => $teslaRelatedFindPartResults,
            'teslaPartSearchItemIds' => $teslaFindPartItemIds,
            'teslaFindPartRequestItemIds' => $teslaFindPartItemIds,
            'nikolaCarsRelatedItems' => $nikolaCarsRelatedItems,
            'nikolaCarsDonorCarsByVin' => $nikolaCarsDonorCarsByVin,
            'nikolaCarsRelatedPartNumberPrefix' => $this->nikolaCarsPartNumberPrefix((string) $partCatalogItem->part_number),
            'nikolaCarsRelatedTotalValueUsd' => app(NikolaCarsCatalogListService::class)->inventoryTotalUsd($nikolaCarsRelatedItems, $usdRate),
        ]);
    }

    public function storeNikolaCarsItem(
        StoreNikolaCarsCatalogItemRequest $request,
        NikolaCarsCatalogItemService $nikolaCarsCatalogItems
    ): RedirectResponse {
        $product = $nikolaCarsCatalogItems->createManualItem($request->validated());

        return redirect()
            ->route('admin.zapchasti.index')
            ->with('status', "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{0430}: ".$product->name);
    }

    public function searchNikolaCarsItemNameSuggestions(Request $request): JsonResponse
    {
        abort_unless($this->catalogSource($request) === 'nikolacars', 404);

        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        return response()->json(app(NikolaCarsCatalogNameSuggestionService::class)->suggestions(
            $query,
            fn (PartCatalogItem $item): string => $this->displayItemName($item),
            fn (PartCatalogItem $item, ?string $description): string => $this->displayNikolaCarsDescription($item, $description),
        ));
    }

    protected function itemUrl(PartCatalogItem $item, string $routePrefix): string
    {
        if ($item->source === 'nikolacars') {
            $product = $this->nikolaCarsProductForItem($item);

            if ($product instanceof Product) {
                return route('admin.products.show', $product);
            }
        }

        return route($routePrefix.'.show', $item);
    }

    protected function nikolaCarsProductForItem(PartCatalogItem $item): ?Product
    {
        if ($item->source !== 'nikolacars') {
            return null;
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);
        $productId = (int) data_get($rawAttributes, 'product_id');

        if ($productId > 0) {
            $product = Product::query()->find($productId);

            if ($product instanceof Product) {
                return $product;
            }
        }

        $product = Product::query()
            ->where('source_part_catalog_item_id', $item->id)
            ->orderBy('id')
            ->first();

        if ($product instanceof Product) {
            return $product;
        }

        return null;
    }

    protected function teslaFindPartItemIds(PartCatalogItem $item, Collection $relatedResults): array
    {
        if ($item->source !== 'tesla_official') {
            return [];
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);

        $partNumbers = collect((array) ($rawAttributes['tesla_part_search_results'] ?? []))
            ->merge($relatedResults)
            ->pluck('part_number')
            ->merge((array) ($rawAttributes['tesla_part_search_related_part_numbers'] ?? []))
            ->merge((array) ($rawAttributes['tesla_part_search_exact_part_numbers'] ?? []))
            ->merge((array) ($rawAttributes['tesla_part_search_similar_part_numbers'] ?? []))
            ->merge((array) ($rawAttributes['find_part_found_by_requested_part_numbers'] ?? []))
            ->merge([(string) ($rawAttributes['find_part_requested_part_number'] ?? '')])
            ->map(fn (mixed $partNumber): string => Str::upper(trim((string) $partNumber)))
            ->filter()
            ->unique()
            ->values();

        if ($partNumbers->isEmpty()) {
            return [];
        }

        return PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereIn('part_number', $partNumbers->all())
            ->orderByRaw("case when source_url like '%/find-part?%' then 0 else 1 end")
            ->orderByDesc('source_updated_at')
            ->orderBy('id')
            ->get(['id', 'part_number'])
            ->mapWithKeys(fn (PartCatalogItem $item): array => [
                Str::upper(trim((string) $item->part_number)) => $item->id,
            ])
            ->all();
    }

    protected function relatedTeslaFindPartResults(PartCatalogItem $item): Collection
    {
        if ($item->source !== 'tesla_official') {
            return collect();
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);
        $requestedPartNumbers = collect((array) ($rawAttributes['find_part_found_by_requested_part_numbers'] ?? []))
            ->merge([(string) ($rawAttributes['find_part_requested_part_number'] ?? '')])
            ->map(fn (mixed $partNumber): string => Str::upper(trim((string) $partNumber)))
            ->filter()
            ->unique()
            ->values();

        if ($requestedPartNumbers->isEmpty()) {
            return collect();
        }

        return PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereIn('part_number', $requestedPartNumbers->all())
            ->orderByRaw("case when source_url like '%/find-part?%' then 0 else 1 end")
            ->orderByDesc('source_updated_at')
            ->orderBy('id')
            ->get(['raw_attributes'])
            ->flatMap(function (PartCatalogItem $sourceItem): array {
                $rawAttributes = PartCatalogRawAttributes::from($sourceItem);

                return collect((array) ($rawAttributes['tesla_part_search_results'] ?? []))
                    ->filter(fn (mixed $result): bool => is_array($result))
                    ->all();
            })
            ->unique(fn (array $result): string => implode('|', [
                $result['part_number'] ?? '',
                $result['model'] ?? '',
                $result['category'] ?? '',
                $result['subcategory'] ?? '',
                $result['group'] ?? '',
            ]))
            ->values();
    }

    protected function teslaOfficialOccurrenceCategories(PartCatalogItem $item): Collection
    {
        if ($item->source !== 'tesla_official') {
            return collect();
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);
        $categoryIds = $item->occurrences()
            ->where('source', 'tesla_official')
            ->whereNotNull('part_catalog_category_id')
            ->pluck('part_catalog_category_id')
            ->merge(collect((array) ($rawAttributes['official_catalog_occurrences'] ?? []))
                ->pluck('category_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($categoryIds->isEmpty()) {
            return collect();
        }

        return PartCatalogCategory::query()
            ->whereIn('id', $categoryIds->all())
            ->get(['id', 'source', 'source_url', 'name', 'model_label', 'preview_image_url']);
    }

    protected function sourceLabel(string $source): string
    {
        return match ($source) {
            'tesla_official' => 'Tesla official',
            'teslapartsukraine' => 'TeslaPartsUkraine',
            'dkparts' => 'DK-Parts',
            'tsk' => 'TSK',
            'tcarservice' => 'TCARS',
            'stock-tesla' => 'Stock Tesla',
            'teslahelp' => 'TeslaHelp',
            'driveparts' => 'DriveParts',
            'erazborka' => 'Erazborka',
            'toprazborka' => 'TopRazborka',
            'teslawestparts' => 'TeslaWestParts',
            default => $this->catalogConfig($source)['source_label'] ?? $source,
        };
    }

    protected function catalogItemDonorCars(PartCatalogItem $item, Collection $relatedItems): Collection
    {
        $items = collect([$item])
            ->merge($relatedItems)
            ->unique('id')
            ->values();

        $donorCarIds = collect();
        $donorVins = collect();

        foreach ($items as $catalogItem) {
            $rawAttributes = PartCatalogRawAttributes::from($catalogItem);

            $donorCarIds = $donorCarIds
                ->push(data_get($rawAttributes, 'donor_car_id'))
                ->merge((array) data_get($rawAttributes, 'donor_car_ids', []))
                ->merge(collect((array) data_get($rawAttributes, 'official_catalog_occurrences', []))
                    ->pluck('donor_car_id'));

            $donorVins = $donorVins
                ->push(data_get($rawAttributes, 'donor_vin'))
                ->merge((array) data_get($rawAttributes, 'donor_vins', []))
                ->merge(collect((array) data_get($rawAttributes, 'official_catalog_occurrences', []))
                    ->pluck('donor_vin'));
        }

        $productDonorIds = DB::table('products')
            ->whereIn('source_part_catalog_item_id', $items->pluck('id')->all())
            ->whereNotNull('donor_car_id')
            ->pluck('donor_car_id');

        $donorCarIds = $donorCarIds
            ->merge($productDonorIds)
            ->filter(fn (mixed $value): bool => (int) $value > 0)
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values();

        $donorVins = $donorVins
            ->map(fn (mixed $value): string => Str::upper(trim((string) $value)))
            ->filter()
            ->unique()
            ->values();

        if ($donorCarIds->isEmpty() && $donorVins->isEmpty()) {
            return collect();
        }

        return DonorCar::query()
            ->where(function (Builder $query) use ($donorCarIds, $donorVins): void {
                if ($donorCarIds->isNotEmpty()) {
                    $query->whereIn('id', $donorCarIds->all());
                }

                if ($donorVins->isNotEmpty()) {
                    $method = $donorCarIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('vin', $donorVins->all());
                }
            })
            ->orderBy('id')
            ->get(['id', 'vin', 'model', 'year', 'color']);
    }

    public function updateTeslaCatalogItem(Request $request, PartCatalogItem $partCatalogItem)
    {
        abort_unless($partCatalogItem->source === 'tesla_official', 404);

        $validated = $request->validate([
            'name_ru' => ['nullable', 'string', 'max:255'],
            'name_ua' => ['nullable', 'string', 'max:255'],
        ]);

        $manualNameService = app(PartCatalogManualNameService::class);
        $counts = $manualNameService->lockAndPropagate($partCatalogItem, $validated);

        $updated = array_sum($counts);
        if ($updated > 0) {
            app(NikolaCarsProductInventorySyncService::class)
                ->syncProductsLinkedToSourceCatalogItem($partCatalogItem->fresh() ?? $partCatalogItem);
        }

        $message = $updated > 0
            ? 'Названия каталога обновлены.'
            : 'Нет данных для обновления.';

        if ($request->expectsJson()) {
            $freshItem = $partCatalogItem->fresh();

            return response()->json([
                'message' => $message,
                'item' => [
                    'id' => $freshItem->id,
                    'name_ru' => $freshItem->name_ru,
                    'name_ua' => $freshItem->name_ua,
                ],
                'updated' => $counts,
            ]);
        }

        return back()->with('status', $message);
    }

    public function startTcarserviceCompetitorRefresh(PartCatalogCompetitorRefreshService $refresh): JsonResponse
    {
        return $this->startCompetitorRefresh('tcarservice', $refresh);
    }

    public function tcarserviceCompetitorRefreshStatus(PartCatalogCompetitorRefreshService $refresh): JsonResponse
    {
        return $this->competitorRefreshStatus('tcarservice', $refresh);
    }

    public function startCompetitorRefresh(string $source, PartCatalogCompetitorRefreshService $refresh): JsonResponse
    {
        try {
            $result = $refresh->start(
                $source,
                fn (string $normalizedSource, ?CompetitorCatalogRun $run = null): ?array => $this->competitorRefreshPayload($normalizedSource, $run),
            );
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        return response()->json($result['payload'], $result['status']);
    }

    public function competitorRefreshStatus(string $source, PartCatalogCompetitorRefreshService $refresh): JsonResponse
    {
        try {
            $payload = $refresh->status(
                $source,
                fn (string $normalizedSource, ?CompetitorCatalogRun $run = null): ?array => $this->competitorRefreshPayload($normalizedSource, $run),
            );
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        return response()->json($payload);
    }

    protected function competitorRefreshPayload(string $source, ?CompetitorCatalogRun $run = null): ?array
    {
        $skipCatalogCounts = $run?->isRunning() ?? false;
        $itemsCount = $skipCatalogCounts ? null : $this->cachedUniquePartsCount($source);
        $totalProductsCount = $skipCatalogCounts ? null : $this->sourceFilteredQuery(PartCatalogItem::query(), $source)->count();

        return app(PartCatalogCompetitorRefreshPayload::class)->make(
            $source,
            $itemsCount,
            $totalProductsCount,
            fn (PartCatalogItem $item): string => $this->displayItemName($item),
            fn (string $payloadSource): string => $this->catalogConfig($payloadSource)['route_prefix'],
            $run,
        );
    }

    public function competitorCatalogExport(Request $request)
    {
        $source = $this->catalogSource($request);
        abort_unless(in_array($source, [
            'tcarservice',
            'teslapartsukraine',
            'tsk',
            'stock-tesla',
            'teslahelp',
            'driveparts',
            'dkparts',
            'erazborka',
            'toprazborka',
            'teslawestparts',
            'teslacompany',
            'tesla_official',
        ], true), 404);

        $filename = $source.'-parts-catalog-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($source): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Источник',
                'Артикул',
                'Название',
                'Название RU',
                'Название UA',
                'Модель',
                'Категория',
                'Подкатегория',
                'Узел',
                'Цена',
                'Валюта',
                'Наличие',
                'Состояние',
                'Качество',
                'Ссылка',
            ], ';');

            PartCatalogItem::query()
                ->with('category:id,name,name_ru,name_ua')
                ->tap(fn (Builder $builder) => $this->sourceFilteredQuery($builder, $source))
                ->orderBy('model_label')
                ->orderBy('part_number')
                ->orderBy('name')
                ->chunkById(500, function (Collection $items) use ($handle): void {
                    foreach ($items as $item) {
                        fputcsv($handle, [
                            $item->source,
                            $item->part_number,
                            $this->displayItemName($item),
                            $item->name_ru,
                            $item->name_ua,
                            $item->model_label ?: $item->model_name,
                            $item->main_category_name ?: ($item->category ? $this->displayCategoryName($item->category) : ''),
                            $item->subcategory_name,
                            $item->node_name,
                            $item->price_amount,
                            $item->currency,
                            $item->availability,
                            $this->displayItemCondition($item),
                            $item->quality,
                            $item->source_url,
                        ], ';');
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function nikolaCarsPromExport(NikolaCarsPromYmlFeed $feed): View
    {
        $usdRate = app(ExchangeRateService::class)->displayUsdRate();
        $groups = $feed->exportableGroups($usdRate);

        return view('admin.part_catalog.prom_export', [
            'groups' => $groups,
            'itemsCount' => $groups->count(),
            'totalQuantity' => round($groups->sum(fn (array $group): float => (float) $group['quantity']), 3),
            'totalValueUsd' => round($groups->sum(fn (array $group): float => (float) $group['total_value_usd']), 2),
            'feedUrl' => route('prom.nikolacars-products.feed', array_filter([
                'token' => config('prom.feed_token'),
            ])),
            'itemName' => fn (PartCatalogItem $item): string => $this->displayItemName($item),
            'nikolaCarsDescription' => fn (PartCatalogItem $item, ?string $description): string => $this->displayNikolaCarsDescription($item, $description),
            'itemUrl' => fn (PartCatalogItem $item): string => ($product = $this->nikolaCarsProductForItem($item)) instanceof Product
                ? route('admin.products.show', $product)
                : '#',
            'usdRate' => $usdRate,
        ]);
    }

    public function destroyNikolaCarsItem(
        Request $request,
        PartCatalogItem $partCatalogItem,
        NikolaCarsCatalogItemService $nikolaCarsCatalogItems
    ) {
        $name = $this->displayItemName($partCatalogItem);
        $result = $nikolaCarsCatalogItems->destroyItem($partCatalogItem);
        $message = "\u{041F}\u{043E}\u{0437}\u{0438}\u{0446}\u{0438}\u{044F} {$name} \u{043F}\u{0435}\u{0440}\u{0435}\u{043C}\u{0435}\u{0449}\u{0435}\u{043D}\u{0430} \u{0432} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{043D}\u{044B}\u{0435} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438}.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'deleted_item_id' => $result['deleted_item_id'],
                'items_count' => app(NikolaCarsCatalogListService::class)->itemsCount(),
                'unique_articles_count' => app(NikolaCarsCatalogListService::class)->uniqueArticleCount(),
                'added_today_count' => app(NikolaCarsCatalogListService::class)->addedTodayCount(),
                'total_value_usd' => app(NikolaCarsCatalogListService::class)->formattedInventoryTotalUsd(),
            ]);
        }

        return redirect()
            ->route('admin.zapchasti.index')
            ->with('status', $message);
    }

    public function markNikolaCarsItemSold(
        Request $request,
        PartCatalogItem $partCatalogItem,
        NikolaCarsCatalogItemService $nikolaCarsCatalogItems
    ) {
        $name = $this->displayItemName($partCatalogItem);
        $result = $nikolaCarsCatalogItems->markSoldBeforeJune($partCatalogItem, $name);

        $message = "\u{041F}\u{043E}\u{0437}\u{0438}\u{0446}\u{0438}\u{044F} {$name} \u{043F}\u{043E}\u{043C}\u{0435}\u{0447}\u{0435}\u{043D}\u{0430} \u{043A}\u{0430}\u{043A} \u{043F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}\u{043D}\u{0430}\u{044F} \u{0434}\u{043E} 01.06.2026.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'sold_item_id' => $result['sold_item_id'],
                'availability' => $result['availability'],
                'stock_quantity' => $result['stock_quantity'],
                'items_count' => app(NikolaCarsCatalogListService::class)->itemsCount(),
                'unique_articles_count' => app(NikolaCarsCatalogListService::class)->uniqueArticleCount(),
                'added_today_count' => app(NikolaCarsCatalogListService::class)->addedTodayCount(),
                'total_value_usd' => app(NikolaCarsCatalogListService::class)->formattedInventoryTotalUsd(),
            ]);
        }

        return redirect()
            ->route('admin.zapchasti.index')
            ->with('status', $message);
    }

    public function searchNikolaCarsCategories(
        Request $request,
        NikolaCarsCatalogCategoryService $nikolaCarsCategories
    ): JsonResponse {
        abort_unless($this->catalogSource($request) === 'nikolacars', 404);

        return response()->json($nikolaCarsCategories->search((string) $request->query('q', '')));
    }

    public function updateNikolaCarsItem(
        UpdateNikolaCarsCatalogItemRequest $request,
        PartCatalogItem $partCatalogItem,
        NikolaCarsCatalogItemService $nikolaCarsCatalogItems
    ): JsonResponse {
        return response()->json($nikolaCarsCatalogItems->updateItem(
            $partCatalogItem,
            $request->validated(),
            $request->boolean('apply_to_part_number')
        ));
    }

    public function updateNikolaCarsItemCategory(
        UpdateNikolaCarsCatalogItemCategoryRequest $request,
        PartCatalogItem $partCatalogItem,
        NikolaCarsCatalogItemService $nikolaCarsCatalogItems
    ): JsonResponse {
        return response()->json($nikolaCarsCatalogItems->updateCategory(
            $partCatalogItem,
            (int) $request->validated('category_id')
        ));
    }

    public function storeNikolaCarsItemPhotos(
        StoreNikolaCarsCatalogItemPhotosRequest $request,
        PartCatalogItem $partCatalogItem,
        NikolaCarsCatalogItemService $nikolaCarsCatalogItems
    ): RedirectResponse {
        $validated = $request->validated();

        $nikolaCarsCatalogItems->storePhotos($partCatalogItem, $validated['photos']);

        return back()->with('status', "\u{0424}\u{043E}\u{0442}\u{043E} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{044B}.");
    }

    public function destroyNikolaCarsItemPhoto(
        DestroyNikolaCarsCatalogItemPhotoRequest $request,
        PartCatalogItem $partCatalogItem,
        NikolaCarsCatalogItemService $nikolaCarsCatalogItems
    ): RedirectResponse {
        $nikolaCarsCatalogItems->destroyPhoto($partCatalogItem, (string) $request->validated('image_url'));

        return back()->with('status', "\u{0424}\u{043E}\u{0442}\u{043E} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{043E}.");
    }

    protected function refreshMissingTskPrice(PartCatalogItem $item): void
    {
        if ($item->source !== 'tsk' || $item->price_amount !== null) {
            return;
        }

        $productUrl = (string) data_get($item->raw_attributes, 'product_url', '');
        if ($productUrl === '' || ! str_starts_with($productUrl, 'https://tsk.ua/')) {
            return;
        }

        $details = app(TskCatalogImporter::class)->productDetails($productUrl);
        if (($details['price_amount'] ?? null) === null) {
            return;
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);
        if (($details['image_url'] ?? null) !== null) {
            $rawAttributes['image_url'] = $details['image_url'];
        }

        $item->forceFill([
            'price_amount' => $details['price_amount'],
            'currency' => $details['currency'] ?? 'USD',
            'availability' => $details['availability'] ?? $item->availability,
            'raw_attributes' => $rawAttributes,
        ])->save();
    }

    protected function nikolaCarsRelatedItems(PartCatalogItem $item): Collection
    {
        return app(NikolaCarsInventoryService::class)->relatedItems($item);
    }

    protected function nikolaCarsDonorCarsByVin(PartCatalogItem $item, Collection $relatedItems): Collection
    {
        if ($item->source !== 'nikolacars') {
            return collect();
        }

        $vins = collect([$item])
            ->merge($relatedItems)
            ->map(fn (PartCatalogItem $relatedItem): string => Str::upper(trim((string) data_get($relatedItem->raw_attributes, 'donor_vin', ''))))
            ->filter()
            ->unique()
            ->values();

        if ($vins->isEmpty()) {
            return collect();
        }

        return DonorCar::query()
            ->whereIn('vin', $vins->all())
            ->get(['id', 'vin', 'model', 'year', 'color', 'paint_code'])
            ->keyBy('vin');
    }

    protected function nikolaCarsDamageStatusUsersById(Collection $items): Collection
    {
        $userIds = $items
            ->map(fn (PartCatalogItem $item): int => (int) data_get($item->raw_attributes, 'donor_damage_status_changed_by'))
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds->all())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
    }

    protected function isNikolaCarsItemSold(PartCatalogItem $item): bool
    {
        if ($item->source !== 'nikolacars') {
            return false;
        }

        return app(NikolaCarsInventoryService::class)->isManuallySold($item)
            || data_get($item->raw_attributes, 'storage_status') === Product::STORAGE_STATUS_SOLD
            || $item->sales()
                ->where('source', NikolaCarsInventoryService::SOURCE)
                ->exists();
    }

    protected function nikolaCarsQuantityText(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.').' '."\u{0448}\u{0442}";
    }

    protected function nikolaCarsUnitPriceText(Collection $priceValues): string
    {
        if ($priceValues->isEmpty()) {
            return '-';
        }

        $min = round((float) $priceValues->min(), 2);
        $max = round((float) $priceValues->max(), 2);

        if ($min === $max) {
            return number_format($min, 2, '.', ' ').' USD';
        }

        return number_format($min, 2, '.', ' ').'-'.number_format($max, 2, '.', ' ').' USD';
    }

    protected function nikolaCarsUniqueAttributeValues(Collection $items, string $key): Collection
    {
        return $items
            ->map(fn (PartCatalogItem $item): string => (string) data_get($item->raw_attributes, $key, ''))
            ->filter()
            ->unique()
            ->values();
    }

    protected function normalizeNikolaCarsPartNumber(string $partNumber): string
    {
        $partNumber = Str::upper(str_replace(' ', '', trim($partNumber)));

        if (preg_match('/^(\d{7})([A-Z0-9]{2})([A-Z0-9])$/', $partNumber, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $partNumber;
    }

    protected function nikolaCarsPartNumberPrefix(string $partNumber): string
    {
        $partNumber = $this->normalizeNikolaCarsPartNumber($partNumber);

        return preg_match('/^(\d{7})/', $partNumber, $matches) === 1 ? $matches[1] : '';
    }

    protected function applyItemSearch(Builder $itemsQuery, string $query, string $driver, ?string $source = null): void
    {
        app(PartCatalogSearchService::class)->applyItemSearch(
            $itemsQuery,
            $query,
            $driver,
            $source !== 'nikolacars',
        );
    }

    protected function compactPartNumberSearch(string $value): string
    {
        return app(PartCatalogSearchService::class)->compactPartNumberSearch($value);
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $source = $this->catalogSource($request);
        $driver = DB::connection()->getDriverName();

        if (mb_strlen($query) < 1) {
            return response()->json([]);
        }

        $items = $this->suggestionItems($source, $query, $driver);

        return response()->json($items->map(fn (PartCatalogItem $item): array => [
            'id' => $item->id,
            'name' => $this->displayItemName($item),
            'part_number' => $item->part_number,
            'model' => $item->model_label,
            'category' => collect([$item->main_category_name, $item->subcategory_name, $item->node_name])->filter()->implode(' / '),
        ])->values());
    }

    protected function suggestionItems(string $source, string $query, string $driver): Collection
    {
        return app(PartCatalogSearchService::class)->suggestionItems(
            $source,
            $query,
            fn (Builder $builder, string $source): Builder => $this->sourceFilteredQuery($builder, $source),
            $driver,
        );
    }

    protected function categoryTrail(?PartCatalogCategory $category): Collection
    {
        return app(PartCatalogCategoryRouteService::class)->categoryTrail($category);
    }

    protected function categoryUrl(?PartCatalogCategory $category, string $source, array $models = [], bool $includeCybertruck = false): string
    {
        return app(PartCatalogCategoryRouteService::class)->categoryUrl(
            $category,
            $this->catalogConfig($source),
            $models,
            $includeCybertruck,
            false,
        );
    }

    protected function catalogPath(PartCatalogCategory $category): ?string
    {
        return app(PartCatalogCategoryRouteService::class)->catalogPath($category, false);
    }

    protected function categoryIdByCatalogPath(string $source, string $catalogPath): int
    {
        return app(PartCatalogCategoryRouteService::class)->categoryIdByCatalogPath($source, $catalogPath, false);
    }

    protected function matchingTcarsCategory(PartCatalogCategory $category): ?PartCatalogCategory
    {
        return app(PartCatalogCategoryRouteService::class)->matchingTcarsCategory($category);
    }

    protected function modelCategoryBlocks(PartCatalogCategory $modelCategory, string $source): Collection
    {
        return app(PartCatalogCategoryTreeService::class)->modelCategoryBlocks(
            $modelCategory,
            $source,
            fn (array $models): array => $this->modelLabelQueryValues($models),
        );
    }

    protected function appendPreviewFallbacks(Collection $categories): void
    {
        app(PartCatalogCategoryTreeService::class)->appendPreviewFallbacks($categories);
    }

    protected function appendBranchItemCounts(Collection $categories, ?string $source = null): void
    {
        app(PartCatalogCategoryTreeService::class)->appendBranchItemCounts(
            $categories,
            $source,
            fn (array $models): array => $this->modelLabelQueryValues($models),
        );
    }

    protected function whereInSelectedCatalogBranch(Builder $query, PartCatalogCategory $category, array $branchCategoryIds): void
    {
        app(PartCatalogCategoryTreeService::class)->whereInSelectedCatalogBranch($query, $category, $branchCategoryIds);
    }

    protected function categoryBranchIds(PartCatalogCategory $category): array
    {
        return app(PartCatalogCategoryTreeService::class)->categoryBranchIds($category);
    }

    protected function catalogSource(Request $request, ?string $fallback = null): string
    {
        $routeName = (string) $request->route()?->getName();

        if (str_starts_with($routeName, 'admin.teslapartsukraine-catalog.')) {
            return 'teslapartsukraine';
        }

        if (str_starts_with($routeName, 'admin.tsk-catalog.')) {
            return 'tsk';
        }

        if (str_starts_with($routeName, 'admin.stock-tesla-catalog.')) {
            return 'stock-tesla';
        }

        if (str_starts_with($routeName, 'admin.competitors-ru.')) {
            return 'teslahelp';
        }

        if (str_starts_with($routeName, 'admin.driveparts-catalog.')) {
            return 'driveparts';
        }

        if (str_starts_with($routeName, 'admin.dkparts-catalog.')) {
            return 'dkparts';
        }

        if (str_starts_with($routeName, 'admin.erazborka-catalog.')) {
            return 'erazborka';
        }

        if (str_starts_with($routeName, 'admin.toprazborka-catalog.')) {
            return 'toprazborka';
        }

        if (str_starts_with($routeName, 'admin.teslawestparts-catalog.')) {
            return 'teslawestparts';
        }

        if (str_starts_with($routeName, 'admin.teslacompany-catalog.')) {
            return 'teslacompany';
        }

        if (str_starts_with($routeName, 'admin.zapchasti.')) {
            return 'nikolacars';
        }

        if (str_starts_with($routeName, 'admin.tesla-official-catalog.')) {
            return 'tesla_official';
        }

        return $fallback ?: 'tcarservice';
    }

    protected function catalogConfig(string $source): array
    {
        return match ($source) {
            'teslapartsukraine' => [
                'source' => 'teslapartsukraine',
                'route_prefix' => 'admin.teslapartsukraine-catalog',
                'heading' => 'Каталог TeslaPartsUkraine',
                'category_count_label' => 'Категорий TeslaPartsUkraine',
                'source_label' => 'Источник TeslaPartsUkraine',
            ],
            'tesla_official' => [
                'source' => 'tesla_official',
                'route_prefix' => 'admin.tesla-official-catalog',
                'heading' => "\u{041A}\u{0430}\u{0442}\u{0430}\u{043B}\u{043E}\u{0433} Tesla.com",
                'category_count_label' => "\u{041A}\u{0430}\u{0442}\u{0435}\u{0433}\u{043E}\u{0440}\u{0438}\u{0439} Tesla.com",
                'source_label' => 'Tesla.com',
            ],
            'tsk' => [
                'source' => 'tsk',
                'route_prefix' => 'admin.tsk-catalog',
                'heading' => 'Каталог TSK',
                'category_count_label' => 'Категорий TSK',
                'source_label' => 'Источник TSK',
            ],
            'stock-tesla' => [
                'source' => 'stock-tesla',
                'route_prefix' => 'admin.stock-tesla-catalog',
                'heading' => 'Catalog Stock Tesla',
                'category_count_label' => 'Categories Stock Tesla',
                'source_label' => 'Source Stock Tesla',
            ],
            'teslahelp' => [
                'source' => 'teslahelp',
                'route_prefix' => 'admin.competitors-ru',
                'heading' => 'КонкурентыРУ',
                'category_count_label' => 'Категорий КонкурентыРУ',
                'source_label' => 'Источник TeslaHelp / TeslaShop',
            ],
            'driveparts' => [
                'source' => 'driveparts',
                'route_prefix' => 'admin.driveparts-catalog',
                'heading' => 'Каталог DriveParts',
                'category_count_label' => 'Категорий DriveParts',
                'source_label' => 'Источник DriveParts',
            ],
            'dkparts' => [
                'source' => 'dkparts',
                'route_prefix' => 'admin.dkparts-catalog',
                'heading' => 'Каталог DK-Parts',
                'category_count_label' => 'Категорий DK-Parts',
                'source_label' => 'Источник DK-Parts',
            ],
            'erazborka' => [
                'source' => 'erazborka',
                'route_prefix' => 'admin.erazborka-catalog',
                'heading' => 'Каталог Erazborka',
                'category_count_label' => 'Категорий Erazborka',
                'source_label' => 'Источник Erazborka',
            ],
            'toprazborka' => [
                'source' => 'toprazborka',
                'route_prefix' => 'admin.toprazborka-catalog',
                'heading' => 'Каталог TopRazborka',
                'category_count_label' => 'Категорий TopRazborka',
                'source_label' => 'Источник TopRazborka',
            ],
            'teslawestparts' => [
                'source' => 'teslawestparts',
                'route_prefix' => 'admin.teslawestparts-catalog',
                'heading' => 'Каталог Tesla West Parts',
                'category_count_label' => 'Категорий Tesla West Parts',
                'source_label' => 'Источник Tesla West Parts',
            ],
            'teslacompany' => [
                'source' => 'teslacompany',
                'route_prefix' => 'admin.teslacompany-catalog',
                'heading' => 'Каталог TeslaCompany',
                'category_count_label' => 'Категорий TeslaCompany',
                'source_label' => 'Источник TeslaCompany',
            ],
            'nikolacars' => [
                'source' => 'nikolacars',
                'route_prefix' => 'admin.zapchasti',
                'heading' => "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{041D}\u{0438}\u{043A}\u{043E}\u{043B}\u{0430}\u{041A}\u{0430}\u{0440}\u{0437}",
                'category_count_label' => "\u{0414}\u{043E}\u{043D}\u{043E}\u{0440}\u{043E}\u{0432} / \u{0440}\u{0430}\u{0437}\u{0434}\u{0435}\u{043B}\u{043E}\u{0432} \u{041D}\u{0438}\u{043A}\u{043E}\u{043B}\u{0430}\u{041A}\u{0430}\u{0440}\u{0437}",
                'source_label' => "\u{0421}\u{0441}\u{044B}\u{043B}\u{043A}\u{0430}",
            ],
            default => [
                'source' => 'tcarservice',
                'route_prefix' => 'admin.part-catalog',
                'heading' => 'Каталог TCARS',
                'category_count_label' => 'Категорий TCARS',
                'source_label' => 'Источник TCARS',
            ],
        };
    }

    protected function catalogSiteUrl(string $source): ?string
    {
        return [
            'tcarservice' => 'https://tcarservice.com/zapchasty',
            'teslapartsukraine' => 'https://teslapartsukraine.com.ua/tesla-model-3/?limit=10000',
            'tsk' => 'https://tsk.ua/katalog-zapchastey296/',
            'stock-tesla' => 'https://stock-tesla.com',
            'teslahelp' => 'https://teslahelp.ru',
            'driveparts' => 'https://drive-parts.com.ua/ru/kataloh/',
            'dkparts' => 'https://dk-parts.com.ua/ru',
            'erazborka' => 'https://erazborka.com.ua/catalog/',
            'toprazborka' => 'https://toprazborka.com.ua/',
            'teslawestparts' => 'https://teslawestparts.com.ua',
            'teslacompany' => 'https://teslacompany.com.ua/goods/',
        ][$source] ?? null;
    }

    protected function catalogParsingLogic(string $source): ?array
    {
        return [
            'tcarservice' => [
                'source' => 'tcarservice.com/zapchasty',
                'crawl' => 'обход листовых категорий TCARS',
                'detail' => 'карточки товаров пересканируются',
                'save' => 'позиции хранятся в каталоге TCARS по URL',
            ],
            'teslapartsukraine' => [
                'source' => 'teslapartsukraine.com.ua',
                'crawl' => 'обход листингов по моделям',
                'detail' => 'товары обновляются из листинга',
                'save' => 'каталог TeslaPartsUkraine обновляется по URL товара',
            ],
            'tsk' => [
                'source' => 'tsk.ua/katalog-zapchastey296',
                'crawl' => 'обход каталога TSK',
                'detail' => 'карточки товаров пересканируются',
                'save' => 'позиции TSK сохраняются по URL товара',
            ],
            'stock-tesla' => [
                'source' => 'stock-tesla.com',
                'crawl' => 'обход листингов Stock Tesla',
                'detail' => 'карточки пересканируются с русскими названиями',
                'save' => 'каталог Stock Tesla обновляется по URL',
            ],
            'teslahelp' => [
                'source' => 'teslahelp.ru + teslashop.ru',
                'crawl' => 'обход EPC-категорий TeslaHelp',
                'detail' => 'русские названия добираются из TeslaShop по базовому артикулу',
                'save' => 'позиции КонкурентыРУ сохраняются по схеме и артикулу',
            ],
            'driveparts' => [
                'source' => 'drive-parts.com.ua/ru/kataloh',
                'crawl' => 'обход всех товаров DriveParts',
                'detail' => 'карточки новых товаров добираются отдельно',
                'save' => 'листинг обновляет цены, карточка дополняет данные',
            ],
            'dkparts' => [
                'source' => 'dk-parts.com.ua/ru',
                'crawl' => 'обход категорий DK-Parts',
                'detail' => 'карточки товаров пересканируются',
                'save' => 'позиции DK-Parts сохраняются по URL товара',
            ],
            'erazborka' => [
                'source' => 'erazborka.com.ua/catalog',
                'crawl' => 'обход категорий Erazborka',
                'detail' => 'карточки товаров пересканируются',
                'save' => 'позиции Erazborka сохраняются по URL товара',
            ],
            'toprazborka' => [
                'source' => 'toprazborka.com.ua',
                'crawl' => 'обход каталога TopRazborka',
                'detail' => 'карточки товаров пересканируются',
                'save' => 'позиции TopRazborka сохраняются по URL товара',
            ],
            'teslawestparts' => [
                'source' => 'teslawestparts.com.ua',
                'crawl' => 'обход моделей Tesla West Parts',
                'detail' => 'товары обрабатываются из листинга',
                'save' => 'позиции Tesla West Parts обновляются по URL',
            ],
            'teslacompany' => [
                'source' => 'teslacompany.com.ua/goods',
                'crawl' => 'обход листингов TeslaCompany',
                'detail' => 'карточки товаров пересканируются',
                'save' => 'позиции TeslaCompany сохраняются по URL товара',
            ],
        ][$source] ?? null;
    }

    protected function modelOptions(string $source, array $selected = []): array
    {
        $models = collect($this->cachedModelOptions($source))
            ->merge($selected)
            ->map(fn (?string $model): string => trim((string) $model))
            ->filter()
            ->map(fn (string $model): string => $this->normalizedModelLabel($model))
            ->unique()
            ->values()
            ->all();

        return collect($this->allowedModelLabels($source))
            ->filter(fn (string $model): bool => in_array($model, $models, true))
            ->merge(collect($models)->reject(fn (string $model): bool => in_array($model, self::MODEL_LABELS, true))->sort()->values())
            ->unique()
            ->values()
            ->all();
    }

    protected function allowedModelLabels(string $source): array
    {
        return collect(self::MODEL_LABELS)
            ->merge($this->cachedModelOptions($source))
            ->map(fn (?string $model): string => trim((string) $model))
            ->filter()
            ->map(fn (string $model): string => $this->normalizedModelLabel($model))
            ->unique()
            ->values()
            ->all();
    }

    protected function modelLabelQueryValues(array $models): array
    {
        return collect($models)
            ->flatMap(fn (string $model): array => $this->modelLabelAliases($model))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function modelLabelAliases(string $model): array
    {
        $model = $this->normalizedModelLabel($model);
        $aliases = [$model, $this->legacyMojibakeLabel($model)];

        return $aliases;
    }

    protected function legacyMojibakeLabel(string $label): string
    {
        if (! preg_match('/[^\x00-\x7F]/u', $label)) {
            return $label;
        }

        return mb_convert_encoding($label, 'UTF-8', 'Windows-1251');
    }

    protected function normalizedModelLabel(string $label): string
    {
        foreach (self::MODEL_LABELS as $modelLabel) {
            if ($label === $this->legacyMojibakeLabel($modelLabel)) {
                return $modelLabel;
            }
        }

        return $label;
    }

    protected function cachedModelOptions(string $source): array
    {
        return $this->modelOptionsCache[$source] ??= $this->rememberCatalogCache(
            'part-catalog:model-options:'.$this->catalogSourceValue($source),
            fn (): array => collect()
                ->merge($this->sourceFilteredQuery(PartCatalogCategory::query(), $source)
                    ->whereNotNull('model_label')
                    ->where('model_label', '!=', '')
                    ->distinct()
                    ->pluck('model_label'))
                ->merge($this->sourceFilteredQuery(PartCatalogItem::query(), $source)
                    ->whereNotNull('model_label')
                    ->where('model_label', '!=', '')
                    ->distinct()
                    ->pluck('model_label'))
                ->values()
                ->all()
        );
    }

    protected function cachedCatalogCount(string $type, string $source, callable $callback): int
    {
        return (int) $this->rememberCatalogCache(
            'part-catalog:'.$type.'-count:v2:'.$this->catalogSourceValue($source),
            $callback
        );
    }

    protected function shouldUseSimpleSourceCatalogPagination(
        string $source,
        string $catalogImageFilter,
        string $competitorNameFilter,
        string $teslaCheckFilter,
        string $teslaVisualFilter,
        ?string $competitorSort
    ): bool {
        return in_array($source, ['driveparts', 'tesla_official'], true)
            && $catalogImageFilter === ''
            && $competitorNameFilter === ''
            && $teslaCheckFilter === ''
            && $teslaVisualFilter === ''
            && $competitorSort === null;
    }

    protected function shouldSkipBranchItemCounts(string $source): bool
    {
        return $this->catalogSourceValue($source) === 'driveparts';
    }

    protected function cachedUniquePartsCount(string $source): int
    {
        if ($this->catalogSourceValue($source) === 'stock-tesla') {
            return $this->uniquePartsCount($source);
        }

        return (int) $this->rememberCatalogCache(
            'part-catalog:unique-parts-count:v2:'.$this->catalogSourceValue($source),
            fn (): int => $this->uniquePartsCount($source)
        );
    }

    protected function uniquePartsCount(string $source): int
    {
        $partNumberColumn = Schema::hasColumn('part_catalog_items', 'part_number_compact')
            ? 'part_number_compact'
            : 'part_number';

        return (int) $this->sourceFilteredQuery(PartCatalogItem::query(), $source)
            ->selectRaw("count(distinct nullif({$partNumberColumn}, '')) + sum(case when {$partNumberColumn} is null or {$partNumberColumn} = '' then 1 else 0 end) as unique_parts_count")
            ->value('unique_parts_count');
    }

    protected function rememberCatalogCache(string $key, callable $callback): mixed
    {
        if (app()->runningUnitTests()) {
            return $callback();
        }

        return Cache::remember($key, now()->addMinutes(15), $callback);
    }

    protected function modelQuery(array $models, bool $includeCybertruck): array
    {
        $query = [];

        if ($models !== []) {
            $query['models'] = array_values($models);
        }

        if ($includeCybertruck) {
            $query['include_cybertruck'] = '1';
        }

        return $query;
    }

    protected function withoutCybertruck(Builder $query): Builder
    {
        return $query->where(fn (Builder $builder) => $builder
            ->whereNull('model_label')
            ->orWhere('model_label', 'not like', '%Cybertruck%'));
    }

    protected function deduplicateCategories(Collection $categories): Collection
    {
        return $categories
            ->groupBy(fn (PartCatalogCategory $category): string => $this->categoryUniqueKey($category))
            ->map(fn (Collection $group): PartCatalogCategory => $this->bestDeduplicatedCategory($group))
            ->values();
    }

    protected function bestDeduplicatedCategory(Collection $group): PartCatalogCategory
    {
        $category = $group
            ->sort(function (PartCatalogCategory $a, PartCatalogCategory $b): int {
                return [
                    self::SOURCE_PRIORITY[$a->source] ?? 999,
                    -1 * (int) ($a->children_count ?? 0),
                    $a->preview_image_url ? 0 : 1,
                    (int) $a->id,
                ] <=> [
                    self::SOURCE_PRIORITY[$b->source] ?? 999,
                    -1 * (int) ($b->children_count ?? 0),
                    $b->preview_image_url ? 0 : 1,
                    (int) $b->id,
                ];
            })
            ->first();

        if (! $category->preview_image_url) {
            $category->preview_image_url = $group->firstWhere('preview_image_url')?->preview_image_url;
        }

        return $category;
    }

    protected function categoryUniqueKey(PartCatalogCategory $category): string
    {
        if ((int) $category->depth === 0) {
            return 'model:'.Str::lower(trim((string) ($category->model_label ?: $category->name)));
        }

        return collect([
            'category',
            $category->depth,
            Str::lower(trim((string) $category->model_label)),
            Str::lower(trim((string) ($category->code ?: $category->name))),
        ])->implode(':');
    }

    protected function sourceFilteredQuery(Builder $query, ?string $source, bool $hideNikolaCarsSold = true): Builder
    {
        return app(PartCatalogSourceQueryService::class)->sourceFilteredQuery($query, $source, $hideNikolaCarsSold);
    }

    protected function whereTeslaPartsUkraineStoreItem(Builder $query): Builder
    {
        return app(PartCatalogSourceQueryService::class)->whereTeslaPartsUkraineStoreItem($query);
    }

    protected function whereRealTeslaOfficialItem(Builder $query): Builder
    {
        return app(PartCatalogSourceQueryService::class)->whereRealTeslaOfficialItem($query);
    }

    protected function isRealTeslaOfficialItem(PartCatalogItem $item): bool
    {
        return app(PartCatalogSourceQueryService::class)->isRealTeslaOfficialItem($item);
    }

    protected function catalogSourceValue(?string $source): string
    {
        return app(PartCatalogSourceQueryService::class)->catalogSourceValue($source);
    }

    protected function whereNameSource(Builder $query, string $site): Builder
    {
        return app(PartCatalogSourceQueryService::class)->whereNameSource($query, $site);
    }

    protected function routePrefixForItem(PartCatalogItem $item): string
    {
        if ($item->source === 'tesla_official') {
            return 'admin.tesla-official-catalog';
        }

        return $this->catalogConfig($item->source)['route_prefix'];
    }

    protected function catalogDisplay(): PartCatalogDisplayService
    {
        return $this->catalogDisplayService ??= app(PartCatalogDisplayService::class);
    }

    protected function displayCategoryName(PartCatalogCategory $category): string
    {
        return $this->catalogDisplay()->displayCategoryName($category);
    }

    protected function isCompetitorCatalogSource(string $source): bool
    {
        return $this->catalogDisplay()->isCompetitorCatalogSource($source);
    }

    protected function officialCategoryName(PartCatalogCategory $category): ?string
    {
        return $this->catalogDisplay()->officialCategoryName($category);
    }

    protected function displayItemName(PartCatalogItem $item): string
    {
        return $this->catalogDisplay()->displayItemName($item);
    }

    protected function teslaOfficialNameWithAnnotation(string $name, PartCatalogItem $item): string
    {
        return $this->catalogDisplay()->teslaOfficialNameWithAnnotation($name, $item);
    }

    protected function withoutTeslaPartsUkraineNameMarkers(string $name, PartCatalogItem $item): string
    {
        return $this->catalogDisplay()->withoutTeslaPartsUkraineNameMarkers($name, $item);
    }

    protected function displayModelLabel(mixed $value): string
    {
        return $this->catalogDisplay()->displayModelLabel($value);
    }

    protected function teslaPartsUkrainePartOriginLabel(PartCatalogItem $item): ?string
    {
        return $this->catalogDisplay()->teslaPartsUkrainePartOriginLabel($item);
    }

    protected function teslaPartsUkraineConditionLabel(PartCatalogItem $item): ?string
    {
        return $this->catalogDisplay()->teslaPartsUkraineConditionLabel($item);
    }

    protected function displayItemCondition(PartCatalogItem $item): ?string
    {
        return $this->catalogDisplay()->displayItemCondition($item);
    }

    protected function displayItemPartType(PartCatalogItem $item): ?string
    {
        return $this->catalogDisplay()->displayItemPartType($item);
    }

    protected function displayNikolaCarsDescription(PartCatalogItem $item, ?string $description): string
    {
        return $this->catalogDisplay()->displayNikolaCarsDescription($item, $description);
    }

    protected function withoutNikolaCarsPartNumber(string $name, string $partNumber): string
    {
        return $this->catalogDisplay()->withoutNikolaCarsPartNumber($name, $partNumber);
    }

    protected function localizedNameSources(PartCatalogItem $item): array
    {
        return $this->catalogDisplay()->localizedNameSources($item);
    }

    protected function localizedNameSource(PartCatalogItem $item, string $locale): array
    {
        return $this->catalogDisplay()->localizedNameSource($item, $locale);
    }

    protected function competitorLocalizedNameUrl(PartCatalogItem $item, string $locale): ?string
    {
        return $this->catalogDisplay()->competitorLocalizedNameUrl($item, $locale);
    }

    protected function withPathLocale(string $url, string $locale): string
    {
        return $this->catalogDisplay()->withPathLocale($url, $locale);
    }

    protected function withoutPathLocale(string $url, string $locale): string
    {
        return $this->catalogDisplay()->withoutPathLocale($url, $locale);
    }

    protected function competitorSourceLabel(string $source): string
    {
        return $this->catalogDisplay()->competitorSourceLabel($source);
    }

    protected function localizedNameSourceUrlFromItemReference(PartCatalogItem $item, string $locale): ?string
    {
        return $this->catalogDisplay()->localizedNameSourceUrlFromItemReference($item, $locale);
    }

    protected function localizedNameSourceItemFromReference(PartCatalogItem $item, string $locale): ?PartCatalogItem
    {
        return $this->catalogDisplay()->localizedNameSourceItemFromReference($item, $locale);
    }

    protected function isAutoTranslatedFromSource(
        PartCatalogItem $item,
        ?string $localizedName,
        ?PartCatalogItem $sourceItem
    ): bool {
        return $this->catalogDisplay()->isAutoTranslatedFromSource($item, $localizedName, $sourceItem);
    }

    protected function normalizeSourceNameForCompare(string $value): string
    {
        return $this->catalogDisplay()->normalizeSourceNameForCompare($value);
    }

    protected function displayableSourceUrl(PartCatalogItem $item, ?string $locale = null): ?string
    {
        return $this->catalogDisplay()->displayableSourceUrl($item, $locale);
    }

    protected function siteFromUrl(string $url): ?string
    {
        return $this->catalogDisplay()->siteFromUrl($url);
    }

    protected function teslaShopUrlFromPartNumber(PartCatalogItem $item): ?string
    {
        return $this->catalogDisplay()->teslaShopUrlFromPartNumber($item);
    }

    protected function whereBlank(Builder $query, string $column): Builder
    {
        return app(PartCatalogMissingNamesService::class)->whereBlank($query, $column);
    }

    protected function whereLongPartNumber(Builder $query, string $driver): Builder
    {
        return app(PartCatalogMissingNamesService::class)->whereLongPartNumber($query, $driver);
    }

    protected function canUseMissingNamesPaginator(string $query, array $missingNames, array $productFilters, string $nameSource, ?string $priceSort): bool
    {
        return app(PartCatalogMissingNamesService::class)->canUsePaginator(
            $query,
            $missingNames,
            $productFilters,
            $nameSource,
            $priceSort,
        );
    }

    protected function missingNamesCatalogItemsPaginator(array $filterModels, bool $includeCybertruck, array $columns): Paginator
    {
        return app(PartCatalogMissingNamesService::class)->paginator(
            $filterModels,
            $includeCybertruck,
            $columns,
            fn (string $source): array => $this->modelOptions($source),
        );
    }

    protected function orderModelCategories(Builder $query): void
    {
        $labels = [
            'Model S',
            'Model S 02.2012-03.2016',
            'Model S2 04.2016-01.2021',
            'Model S Palladium 02.2021-05.2025',
            'Model S до 2016',
            'Model S после 2016',
            'Model S after 2016',
            'Model S Plaid',
            'Model S до 2016',
            'Model S після 2016',
            'Model X 09.2015-02.2021',
            'Model X Palladium 03.2021-05.2025',
            'Tesla Model X',
            'Model X Plaid',
            'Model X',
            'Tesla Model 3',
            'Model 3 06.2017 - 12.2023',
            'Model 3 Highland 01.2024 -',
            'Model 3',
            "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} 3",
            'TESLA MODEL Y',
            'Model Y 01.2020 - 01.2025',
            'Model Y Juniper 02.2025 -',
            'Model Y',
            "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} Y",
            "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} X",
            "MODEL S \u{0434}\u{043E} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
            "MODEL S \u{043F}\u{0456}\u{0441}\u{043B}\u{044F} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
        ];

        $case = collect($labels)
            ->map(fn (string $label, int $index): string => 'when ? then '.($index + 1))
            ->implode(' ');

        $query
            ->orderByRaw("case model_label {$case} else 999 end", $labels)
            ->orderBy('model_label')
            ->orderByRaw('case when name = model_label then 0 else 1 end')
            ->orderBy('name');
    }
}
