<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DonorCarRequest;
use App\Models\Category;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\StoWorkOrder;
use App\Models\StoWorkOrderPart;
use App\Models\Warehouse;
use App\Services\DeletedPartArchiveService;
use App\Services\DonorProductGenerationService;
use App\Services\DonorProductLocalizedNameAutofillService;
use App\Services\DonorProductSkuService;
use App\Services\ExchangeRateService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Services\OfficialCatalogDownloadStatus;
use App\Services\PartCatalogDeduplicator;
use App\Services\PartCatalogDisplayService;
use App\Services\PartCatalogManualNameService;
use App\Services\StockService;
use App\Services\TeslaCatalogDonorProductSync;
use App\Support\PartCatalogRawAttributes;
use App\Support\PartNumberNormalizer;
use App\Support\ProductPhotoNormalizer;
use App\Support\PublicStorageUrl;
use App\View\Admin\DonorCars\DonorPartDisplayPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DonorCarController extends Controller
{
    protected ?PartCatalogDisplayService $catalogDisplayService = null;

    public function index(Request $request): View
    {
        $sortableColumns = [
            'vin',
            'status',
            'purchase_date',
            'warehouse_arrival_date',
            'model',
            'year',
            'mileage',
            'estimated_cost_usd',
            'products_count',
            'part_sales_count',
            'sold_parts_amount',
            'total_cost_usd',
        ];
        $sort = in_array($request->query('sort'), $sortableColumns, true)
            ? $request->query('sort')
            : 'purchase_date';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        return view('admin.donor_cars.index', [
            'donorCars' => DonorCar::query()
                ->withCount(['products', 'partSales'])
                ->withExists([
                    'products as has_official_downloaded_products' => fn (Builder $query) => $query
                        ->where('is_auto_generated', true)
                        ->whereHas(
                            'sourcePartCatalogItem',
                            fn (Builder $query) => $query->where('source', 'tesla_official')
                        ),
                ])
                ->withSum('partSales as sold_parts_quantity', 'quantity')
                ->selectSub(
                    PartSale::query()
                        ->selectRaw('COALESCE(SUM(quantity * COALESCE(unit_price, 0)), 0)')
                        ->whereColumn('part_sales.donor_car_id', 'donor_cars.id'),
                    'sold_parts_amount'
                )
                ->when(
                    $sort === 'total_cost_usd',
                    fn ($query) => $query->orderByRaw(
                        '(COALESCE(estimated_cost_usd, 0) + COALESCE(usa_delivery_price_usd, 0) + COALESCE(klaipeda_ukraine_delivery_price_usd, 0) + COALESCE(customs_clearance_price_usd, 0)) '.$direction
                    ),
                    fn ($query) => $query->orderBy($sort, $direction),
                )
                ->orderBy('id', $direction)
                ->paginate(30)
                ->withQueryString(),
            'donorStats' => [
                'count' => DonorCar::query()->count(),
                'totalCostUsd' => (float) DonorCar::query()
                    ->selectRaw(
                        'SUM(COALESCE(estimated_cost_usd, 0) + COALESCE(usa_delivery_price_usd, 0) + COALESCE(klaipeda_ukraine_delivery_price_usd, 0) + COALESCE(customs_clearance_price_usd, 0)) as donor_total_cost_usd'
                    )
                    ->first()
                    ?->donor_total_cost_usd,
                'soldPartsQuantity' => (float) DonorCar::query()
                    ->join('part_sales', 'part_sales.donor_car_id', '=', 'donor_cars.id')
                    ->where('part_sales.source', 'nikolacars')
                    ->sum('part_sales.quantity'),
                'soldPartsAmount' => (float) DonorCar::query()
                    ->join('part_sales', 'part_sales.donor_car_id', '=', 'donor_cars.id')
                    ->where('part_sales.source', 'nikolacars')
                    ->selectRaw('COALESCE(SUM(part_sales.quantity * COALESCE(part_sales.unit_price, 0)), 0) as sold_parts_amount')
                    ->value('sold_parts_amount'),
            ],
            'statuses' => DonorCar::STATUSES,
            'sort' => $sort,
            'direction' => $direction,
            'paintCodeSuggestions' => DonorCar::query()
                ->whereNotNull('paint_code')
                ->where('paint_code', '!=', '')
                ->distinct()
                ->orderBy('paint_code')
                ->pluck('paint_code'),
        ]);
    }

    public function create(): View
    {
        return view('admin.donor_cars.form', [
            'donorCar' => new DonorCar,
            ...$this->formOptions(),
        ]);
    }

    public function store(DonorCarRequest $request): RedirectResponse
    {
        $donorCar = DonorCar::query()->create($this->payload($request));

        return redirect()->route('admin.donor-cars.show', $donorCar)->with('status', 'Донорский автомобиль создан.');
    }

    public function show(Request $request, DonorCar $donorCar): View
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(60);

        $productSort = in_array($request->query('product_sort'), [
            'photo',
            'sku',
            'external_sku',
            'name',
            'category',
            'condition',
            'damage_note',
            'tesla_price',
            'price',
            'quantity',
            'warehouse',
            'location',
        ], true) ? (string) $request->query('product_sort') : 'price';
        $defaultProductDirection = $productSort === 'price' ? 'desc' : 'asc';
        $productDirection = in_array($request->query('product_direction'), ['asc', 'desc'], true)
            ? (string) $request->query('product_direction')
            : $defaultProductDirection;
        $saleSort = in_array($request->query('sale_sort'), [
            'sold_at',
            'part_number',
            'name',
            'category',
            'quantity',
            'unit_price',
            'total_amount',
            'document_number',
            'counterparty',
        ], true) ? (string) $request->query('sale_sort') : 'sold_at';
        $defaultSaleDirection = $saleSort === 'sold_at' ? 'desc' : 'asc';
        $saleDirection = in_array($request->query('sale_direction'), ['asc', 'desc'], true)
            ? (string) $request->query('sale_direction')
            : $defaultSaleDirection;

        $donorCar->load([
            'products' => fn ($query) => $this->sortDonorProducts($query, $productSort, $productDirection),
            'products.category',
            'products.sourcePartCatalogItem.category.parent.parent.parent.parent',
            'products.sourcePartCatalogItem.occurrences.category.parent.parent.parent.parent',
            'products.stockItems.warehouse',
            'products.stockItems.location',
            'products.stoWorkOrderParts.order',
            'partSales' => fn ($query) => $this->sortDonorPartSales(
                $query->with([
                    'partCatalogItem.category.parent.parent.parent.parent',
                    'partCatalogItem.occurrences.category.parent.parent.parent.parent',
                ]),
                $saleSort,
                $saleDirection
            ),
        ]);
        $donorCar->setRelation('products', $this->withRecoveredProductCatalogItems($donorCar->products));
        $donorCar->setRelation('partSales', $this->deduplicatedDonorPartSales(
            $this->withRecoveredPartSaleCatalogItems($donorCar->partSales)
        ));
        $donorPartPresenter = app(DonorPartDisplayPresenter::class);

        $catalogNameSourcesByItemId = $this->localizedNameSourcesForItems($donorCar->products
            ->pluck('sourcePartCatalogItem')
            ->filter()
            ->unique('id')
            ->values());
        $saleProductIds = $donorCar->partSales
            ->flatMap(fn (PartSale $sale): array => $donorPartPresenter->saleProductIdCandidates($sale))
            ->filter()
            ->unique()
            ->values();
        $saleCatalogItemIds = $donorCar->partSales
            ->pluck('part_catalog_item_id')
            ->filter()
            ->unique()
            ->values();
        $saleProducts = $saleProductIds->isEmpty() && $saleCatalogItemIds->isEmpty()
            ? collect()
            : Product::query()
                ->with([
                    'sourcePartCatalogItem.category.parent.parent.parent.parent',
                    'sourcePartCatalogItem.occurrences.category.parent.parent.parent.parent',
                ])
                ->select(['id', 'sku', 'external_sku', 'name', 'source_part_catalog_item_id'])
                ->where(function (Builder $query) use ($saleProductIds, $saleCatalogItemIds): void {
                    if ($saleProductIds->isNotEmpty()) {
                        $query->whereIn('id', $saleProductIds);
                    }

                    if ($saleCatalogItemIds->isNotEmpty()) {
                        $method = $saleProductIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('source_part_catalog_item_id', $saleCatalogItemIds);
                    }
                })
                ->get();
        $donorProductReservations = $this->donorProductReservations($donorCar->products);
        $officialTeslaCatalogPricesByProductId = $this->officialTeslaCatalogPricesByProduct($donorCar->products);
        $officialTeslaCatalogNamesByProductId = $this->officialTeslaCatalogNamesByProduct($donorCar->products);

        if ($productSort === 'tesla_price') {
            $sortedProducts = $donorCar->products->sortBy(
                fn (Product $product): float => (float) ($officialTeslaCatalogPricesByProductId->get((int) $product->id)['price_amount'] ?? 0),
                SORT_REGULAR,
                $productDirection === 'desc'
            )->values();
            $donorCar->setRelation('products', $sortedProducts);
        }

        $nikolaCarsProductItemsByProductId = $this->nikolaCarsProductMirrorItemsByProductId($donorCar->products);

        return view('admin.donor_cars.show', [
            'donorCar' => $donorCar,
            'catalogNameSourcesByItemId' => $catalogNameSourcesByItemId,
            'nikolaCarsProductItemsByProductId' => $nikolaCarsProductItemsByProductId,
            'saleProductsById' => $saleProducts->keyBy('id'),
            'saleProductsByCatalogItem' => $saleProducts
                ->whereNotNull('source_part_catalog_item_id')
                ->keyBy('source_part_catalog_item_id'),
            'warehouses' => $this->activeWarehouses(),
            'donorPhotoUrls' => collect($donorCar->photos ?? [])
                ->map(fn (string $photo): string => PublicStorageUrl::url($photo) ?? $photo)
                ->values(),
            'nextPartCode' => $this->nextPartCode($donorCar),
            'damageZones' => DonorProductGenerationService::DAMAGE_ZONES,
            'damageOptions' => $this->donorProductDamageOptions(),
            'manualDamageOptions' => $this->manualDonorProductDamageOptions(),
            'productSort' => $productSort,
            'productDirection' => $productDirection,
            'saleSort' => $saleSort,
            'saleDirection' => $saleDirection,
            'donorProductReservations' => $donorProductReservations,
            'officialTeslaCatalogPricesByProductId' => $officialTeslaCatalogPricesByProductId,
            'officialTeslaCatalogNamesByProductId' => $officialTeslaCatalogNamesByProductId,
            'smallPartNumbers' => $this->smallPartNumbers(),
            'usdRate' => app(ExchangeRateService::class)->displayUsdRate(),
        ]);
    }

    public function smallParts(Request $request, DonorCar $donorCar): View
    {
        $sort = in_array($request->query('sort'), [
            'external_sku',
            'name',
            'category',
            'tesla_price',
            'price',
            'quantity',
            'warehouse',
        ], true) ? (string) $request->query('sort') : 'external_sku';
        $direction = in_array($request->query('direction'), ['asc', 'desc'], true)
            ? (string) $request->query('direction')
            : 'asc';

        $smallPartNumbers = $this->smallPartNumbers();

        $products = Product::query()
            ->with([
                'donorCar',
                'category',
                'sourcePartCatalogItem.category.parent.parent.parent.parent',
                'stockItems.warehouse',
                'stockItems.location',
            ])
            ->where('donor_car_id', $donorCar->id)
            ->where(function (Builder $query) use ($smallPartNumbers): void {
                $query->whereHas('sourcePartCatalogItem', fn (Builder $query) => $query
                    ->where('raw_attributes->donor_vin_small_part', true));

                if ($smallPartNumbers->isNotEmpty()) {
                    $query
                        ->orWhereIn('external_sku', $smallPartNumbers->all())
                        ->orWhereHas('sourcePartCatalogItem', fn (Builder $query) => $query
                            ->whereIn('part_number', $smallPartNumbers->all()));
                }
            })
            ->get();
        $officialTeslaCatalogPricesByProductId = $this->officialTeslaCatalogPricesByProduct($products);
        $donorPartPresenter = app(DonorPartDisplayPresenter::class);
        $products = $this->sortSmallParts($products, $donorCar, $donorPartPresenter, $officialTeslaCatalogPricesByProductId, $sort, $direction);

        return view('admin.donor_cars.small_parts', [
            'donorCar' => $donorCar,
            'products' => $products,
            'donorPartPresenter' => $donorPartPresenter,
            'officialTeslaCatalogPricesByProductId' => $officialTeslaCatalogPricesByProductId,
            'usdRate' => app(ExchangeRateService::class)->displayUsdRate(),
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    protected function smallPartNumbers(): Collection
    {
        return PartCatalogItem::query()
            ->where('raw_attributes->donor_vin_small_part', true)
            ->get(['part_number', 'raw_attributes'])
            ->map(fn (PartCatalogItem $item): ?string => PartNumberNormalizer::normalize(
                (string) ($item->part_number ?: data_get($item->raw_attributes, 'donor_vin_small_part_part_number'))
            ))
            ->filter()
            ->unique()
            ->values();
    }

    protected function sortSmallParts(
        Collection $products,
        DonorCar $donorCar,
        DonorPartDisplayPresenter $donorPartPresenter,
        Collection $officialTeslaCatalogPricesByProductId,
        string $sort,
        string $direction
    ): Collection {
        $directionFactor = $direction === 'desc' ? -1 : 1;

        return $products
            ->sort(function (Product $left, Product $right) use ($donorCar, $donorPartPresenter, $officialTeslaCatalogPricesByProductId, $sort, $directionFactor): int {
                $leftValue = $this->smallPartSortValue($left, $donorCar, $donorPartPresenter, $officialTeslaCatalogPricesByProductId, $sort);
                $rightValue = $this->smallPartSortValue($right, $donorCar, $donorPartPresenter, $officialTeslaCatalogPricesByProductId, $sort);
                $leftMissing = $leftValue === null || $leftValue === '';
                $rightMissing = $rightValue === null || $rightValue === '';

                if ($leftMissing !== $rightMissing) {
                    return $leftMissing ? 1 : -1;
                }

                if (is_numeric($leftValue) && is_numeric($rightValue)) {
                    $result = (float) $leftValue <=> (float) $rightValue;
                } else {
                    $result = strnatcasecmp((string) $leftValue, (string) $rightValue);
                }

                if ($result !== 0) {
                    return $result * $directionFactor;
                }

                return strnatcasecmp((string) $left->external_sku, (string) $right->external_sku)
                    ?: ((int) $left->id <=> (int) $right->id);
            })
            ->values();
    }

    protected function smallPartSortValue(
        Product $product,
        DonorCar $donorCar,
        DonorPartDisplayPresenter $donorPartPresenter,
        Collection $officialTeslaCatalogPricesByProductId,
        string $sort
    ): mixed {
        return match ($sort) {
            'name' => $product->sourcePartCatalogItem?->name_ua
                ?: $product->sourcePartCatalogItem?->name_ru
                ?: $product->sourcePartCatalogItem?->name_en
                ?: $product->name,
            'category' => $this->smallPartCategorySortValue($product, $donorCar, $donorPartPresenter),
            'tesla_price' => data_get($officialTeslaCatalogPricesByProductId->get((int) $product->id), 'price_amount'),
            'price' => $product->selling_price,
            'quantity' => (float) $product->stockItems->sum('quantity'),
            'warehouse' => $product->stockItems->first()?->warehouse?->name ?? $product->storage_status_label,
            default => $product->external_sku,
        };
    }

    protected function smallPartCategorySortValue(
        Product $product,
        DonorCar $donorCar,
        DonorPartDisplayPresenter $donorPartPresenter
    ): string {
        $catalogCategory = $donorPartPresenter->categoryForDonor($donorCar, $product->sourcePartCatalogItem);
        $catalogPath = $donorPartPresenter->categoryPath($catalogCategory, 'preferred', true);
        $rawPath = $donorPartPresenter->catalogRawCategoryPath($product->sourcePartCatalogItem);

        return $catalogPath !== ''
            ? $catalogPath
            : ($rawPath !== '' ? $rawPath : (string) ($product->category?->name ?: ''));
    }

    protected function officialTeslaCatalogNamesByProduct(Collection $products): Collection
    {
        $products = $products
            ->filter(fn (Product $product): bool => trim((string) ($product->external_sku ?: $product->sourcePartCatalogItem?->part_number)) !== '')
            ->values();

        if ($products->isEmpty()) {
            return collect();
        }

        $sourceCatalogItemIds = $products
            ->map(fn (Product $product): int => (int) data_get($this->rawAttributesArray($product->sourcePartCatalogItem), 'source_catalog_item_id'))
            ->filter()
            ->unique()
            ->values();
        $officialItemsById = $sourceCatalogItemIds->isEmpty()
            ? collect()
            : PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->whereIn('id', $sourceCatalogItemIds->all())
                ->get(['id', 'source', 'name', 'name_en'])
                ->keyBy('id');

        $partNumbers = $products
            ->map(fn (Product $product): string => trim((string) ($product->external_sku ?: $product->sourcePartCatalogItem?->part_number)))
            ->filter()
            ->unique()
            ->values();
        $officialItemsByPartNumber = $partNumbers->isEmpty()
            ? collect()
            : PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->whereIn('part_number', $partNumbers->all())
                ->orderByRaw("case when source_url like 'https://parts.tesla.com/%' then 0 else 1 end")
                ->orderBy('id')
                ->get(['id', 'source', 'part_number', 'name', 'name_en'])
                ->groupBy('part_number')
                ->map(fn (Collection $items): PartCatalogItem => $items->first());

        return $products
            ->mapWithKeys(function (Product $product) use ($officialItemsById, $officialItemsByPartNumber): array {
                $sourceItem = $product->sourcePartCatalogItem;
                $officialItem = $sourceItem?->source === 'tesla_official'
                    ? $sourceItem
                    : null;

                $sourceCatalogItemId = (int) data_get($this->rawAttributesArray($sourceItem), 'source_catalog_item_id');
                if (! $officialItem instanceof PartCatalogItem && $sourceCatalogItemId > 0) {
                    $officialItem = $officialItemsById->get($sourceCatalogItemId);
                }

                $partNumber = trim((string) ($product->external_sku ?: $sourceItem?->part_number));
                if (! $officialItem instanceof PartCatalogItem && $partNumber !== '') {
                    $officialItem = $officialItemsByPartNumber->get($partNumber);
                }

                $name = trim((string) ($officialItem?->name_en ?: $officialItem?->name));

                return $name !== ''
                    ? [(int) $product->id => ['name_en' => $name]]
                    : [];
            });
    }

    protected function officialTeslaCatalogPricesByProduct(Collection $products): Collection
    {
        $products = $products
            ->filter(fn (Product $product): bool => $this->isOfficialGeneratedProduct($product)
                || $product->sourcePartCatalogItem?->source === 'tesla_official')
            ->values();

        if ($products->isEmpty()) {
            return collect();
        }

        $sourceCatalogItemIds = $products
            ->map(fn (Product $product): int => (int) data_get($this->rawAttributesArray($product->sourcePartCatalogItem), 'source_catalog_item_id'))
            ->filter()
            ->unique()
            ->values();
        $officialItemsById = $sourceCatalogItemIds->isEmpty()
            ? collect()
            : PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->whereIn('id', $sourceCatalogItemIds->all())
                ->get(['id', 'source', 'price_amount', 'currency', 'raw_attributes'])
                ->keyBy('id');

        $partNumbers = $products
            ->map(fn (Product $product): string => trim((string) ($product->external_sku ?: $product->sourcePartCatalogItem?->part_number)))
            ->filter()
            ->unique()
            ->values();
        $officialItemsByPartNumber = $partNumbers->isEmpty()
            ? collect()
            : PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->whereIn('part_number', $partNumbers->all())
                ->orderByRaw("case when source_url like 'https://parts.tesla.com/%' then 0 else 1 end")
                ->orderBy('id')
                ->get(['id', 'source', 'part_number', 'price_amount', 'currency', 'raw_attributes'])
                ->groupBy('part_number')
                ->map(fn (Collection $items): PartCatalogItem => $items->first());

        return $products
            ->mapWithKeys(function (Product $product) use ($officialItemsById, $officialItemsByPartNumber): array {
                $sourceItem = $product->sourcePartCatalogItem;
                $officialItem = null;

                if ($sourceItem?->source === 'tesla_official') {
                    $officialItem = $sourceItem;
                }

                $sourceCatalogItemId = (int) data_get($this->rawAttributesArray($sourceItem), 'source_catalog_item_id');
                if (! $officialItem instanceof PartCatalogItem && $sourceCatalogItemId > 0) {
                    $officialItem = $officialItemsById->get($sourceCatalogItemId);
                }

                $partNumber = trim((string) ($product->external_sku ?: $sourceItem?->part_number));
                if (! $officialItem instanceof PartCatalogItem && $partNumber !== '') {
                    $officialItem = $officialItemsByPartNumber->get($partNumber);
                }

                $officialPrice = $this->officialTeslaCatalogPricePayload($officialItem);
                if ($officialPrice !== null) {
                    return [
                        (int) $product->id => $officialPrice,
                    ];
                }

                $sourceItemRawAttributes = $this->rawAttributesArray($sourceItem);
                $sourceCatalogPriceAmount = data_get($sourceItemRawAttributes, 'source_catalog_price_amount');
                if ($this->isOfficialGeneratedProduct($product) && is_numeric($sourceCatalogPriceAmount)) {
                    return [
                        (int) $product->id => [
                            'price_amount' => (float) $sourceCatalogPriceAmount,
                            'currency' => data_get($sourceItemRawAttributes, 'source_catalog_currency') ?: 'USD',
                        ],
                    ];
                }

                return [];
            });
    }

    protected function officialTeslaCatalogPricePayload(?PartCatalogItem $officialItem): ?array
    {
        if (! $officialItem instanceof PartCatalogItem || $officialItem->source !== 'tesla_official') {
            return null;
        }

        if ($officialItem->price_amount !== null) {
            return [
                'price_amount' => (float) $officialItem->price_amount,
                'currency' => $officialItem->currency ?: 'USD',
            ];
        }

        $contexts = collect((array) data_get($this->rawAttributesArray($officialItem), 'tesla_scheme_annotation_contexts', []))
            ->filter(fn (mixed $context): bool => is_array($context) && is_numeric($context['price'] ?? null));

        if ($contexts->isEmpty()) {
            return null;
        }

        $context = $contexts
            ->first(fn (array $context): bool => strtoupper((string) ($context['currency'] ?? 'USD')) === 'USD')
            ?: $contexts->first();

        return [
            'price_amount' => (float) $context['price'],
            'currency' => strtoupper((string) ($context['currency'] ?? 'USD')) ?: 'USD',
        ];
    }

    protected function isOfficialGeneratedProduct(Product $product): bool
    {
        return $product->isTeslaOfficialGenerated()
            || ($product->generated_at !== null
                && preg_match('/^DON\d+-/i', (string) $product->sku) === 1);
    }

    protected function donorProductReservations(Collection $products): Collection
    {
        $productsByCatalogItem = $products
            ->filter(fn (Product $product): bool => (int) $product->source_part_catalog_item_id > 0)
            ->groupBy(fn (Product $product): int => (int) $product->source_part_catalog_item_id);

        if ($productsByCatalogItem->isEmpty()) {
            return collect();
        }

        $reservations = CustomerOrderItem::query()
            ->with('order')
            ->whereIn('part_catalog_item_id', $productsByCatalogItem->keys()->all())
            ->whereHas('order', fn (Builder $query) => $query->reservable())
            ->get()
            ->groupBy('part_catalog_item_id')
            ->mapWithKeys(function (Collection $orderItems, int|string $catalogItemId) use ($productsByCatalogItem): array {
                $catalogProducts = $productsByCatalogItem->get((int) $catalogItemId, collect());

                if ($catalogProducts->isEmpty()) {
                    return [];
                }

                $orders = $orderItems
                    ->groupBy('customer_order_id')
                    ->map(function (Collection $items): array {
                        /** @var CustomerOrderItem $first */
                        $first = $items->first();

                        return [
                            'order' => $first->order,
                            'quantity' => round((float) $items->sum(fn (CustomerOrderItem $item): float => (float) $item->quantity), 3),
                        ];
                    })
                    ->filter(fn (array $reservation): bool => $reservation['order'] instanceof CustomerOrder)
                    ->values();

                return $catalogProducts
                    ->mapWithKeys(fn (Product $product): array => [(int) $product->id => [
                        'quantity' => round((float) $orderItems->sum(fn (CustomerOrderItem $item): float => (float) $item->quantity), 3),
                        'orders' => $orders,
                    ]])
                    ->all();
            });

        $productsByCatalogItem
            ->each(function (Collection $catalogProducts) use ($reservations): void {
                /** @var Product|null $product */
                $product = $catalogProducts->first();
                $catalogItem = $product->sourcePartCatalogItem;
                if (! $catalogItem instanceof PartCatalogItem) {
                    return;
                }

                $quantity = data_get($catalogItem->raw_attributes, 'reserved_quantity');
                $quantity = $quantity !== null && $quantity !== '' ? round((float) $quantity, 3) : 0.0;
                if ($quantity <= 0) {
                    return;
                }

                $orderNumbers = collect((array) data_get($catalogItem->raw_attributes, 'reserved_orders', []))
                    ->map(fn (mixed $number): string => trim((string) $number))
                    ->filter()
                    ->unique()
                    ->values();
                $orders = $orderNumbers->isEmpty()
                    ? collect()
                    : CustomerOrder::query()
                        ->whereIn('number', $orderNumbers->all())
                        ->reservable()
                        ->orderBy('number')
                        ->get()
                        ->map(fn (CustomerOrder $order): array => [
                            'order' => $order,
                            'quantity' => null,
                        ])
                        ->values();

                $catalogProducts->each(function (Product $product) use ($reservations, $quantity, $orders): void {
                    if ($reservations->has((int) $product->id)) {
                        return;
                    }

                    $reservations->put((int) $product->id, [
                        'quantity' => $quantity,
                        'orders' => $orders,
                    ]);
                });
            });

        return $reservations;
    }

    protected function withRecoveredProductCatalogItems(Collection $products): Collection
    {
        $productIds = $products
            ->filter(fn (Product $product): bool => (int) $product->source_part_catalog_item_id > 0
                && ! ($product->sourcePartCatalogItem instanceof PartCatalogItem))
            ->pluck('id')
            ->map(fn (int $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return $products;
        }

        $itemsByProductId = PartCatalogItem::query()
            ->with(['category.parent.parent.parent.parent', 'occurrences.category.parent.parent.parent.parent'])
            ->where('source', 'nikolacars')
            ->whereIn('source_url', $productIds
                ->map(fn (int $productId): string => 'nikolacars://donor-product/'.$productId)
                ->all())
            ->get()
            ->keyBy(fn (PartCatalogItem $item): int => (int) data_get($item->raw_attributes, 'product_id'));

        if ($itemsByProductId->isEmpty()) {
            return $products;
        }

        return $products
            ->map(function (Product $product) use ($itemsByProductId): Product {
                if ($product->sourcePartCatalogItem instanceof PartCatalogItem) {
                    return $product;
                }

                $item = $itemsByProductId->get((int) $product->id);

                if ($item instanceof PartCatalogItem) {
                    $product->setRelation('sourcePartCatalogItem', $item);
                }

                return $product;
            })
            ->values();
    }

    protected function nikolaCarsProductMirrorItemsByProductId(Collection $products): Collection
    {
        $productIds = $products
            ->pluck('id')
            ->map(fn (int $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        return PartCatalogItem::query()
            ->with(['category.parent.parent.parent.parent', 'occurrences.category.parent.parent.parent.parent'])
            ->where('source', 'nikolacars')
            ->whereIn('source_url', $productIds
                ->map(fn (int $productId): string => 'nikolacars://donor-product/'.$productId)
                ->all())
            ->get()
            ->keyBy(fn (PartCatalogItem $item): int => (int) data_get($item->raw_attributes, 'product_id'));
    }

    protected function deduplicatedDonorPartSales(Collection $sales): Collection
    {
        $duplicateManualProductSaleIds = $sales
            ->map(function (PartSale $sale): array {
                if ($sale->source !== 'nikolacars'
                    || $sale->document_number !== 'manual-sold-before-june-2026'
                    || $sale->source_file !== 'manual-zapchasti-cleanup') {
                    return ['sale' => $sale, 'product_id' => 0, 'key' => ''];
                }

                $productId = (int) (
                    $sale->product_id
                    ?: data_get($sale->raw_attributes, 'product_id')
                    ?: data_get($sale->partCatalogItem?->raw_attributes, 'product_id')
                );

                return [
                    'sale' => $sale,
                    'product_id' => $productId,
                    'key' => implode('|', [
                        $productId,
                        $sale->sold_at?->toDateString() ?: '',
                        trim((string) $sale->code),
                        trim((string) $sale->part_number),
                        (string) $sale->quantity,
                        (string) $sale->unit_price,
                    ]),
                ];
            })
            ->filter(fn (array $row): bool => $row['product_id'] > 0)
            ->groupBy('key')
            ->flatMap(function (Collection $rows): Collection {
                $hasLinkedSale = $rows->contains(fn (array $row): bool => $row['sale']->part_catalog_item_id !== null);

                if (! $hasLinkedSale) {
                    return collect();
                }

                return $rows
                    ->filter(fn (array $row): bool => $row['sale']->part_catalog_item_id === null
                        && str_starts_with((string) $row['sale']->source_row_hash, 'manual-sold-before-june-2026-product-'))
                    ->map(fn (array $row): int => (int) $row['sale']->id);
            })
            ->values();

        if ($duplicateManualProductSaleIds->isEmpty()) {
            return $sales;
        }

        return $sales
            ->reject(fn (PartSale $sale): bool => $duplicateManualProductSaleIds->contains((int) $sale->id))
            ->values();
    }

    protected function withRecoveredPartSaleCatalogItems(Collection $sales): Collection
    {
        $productIds = $sales
            ->filter(fn (PartSale $sale): bool => $sale->part_catalog_item_id === null)
            ->map(fn (PartSale $sale): int => (int) ($sale->product_id ?: data_get($sale->raw_attributes, 'product_id')))
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return $sales;
        }

        $itemsByProductId = PartCatalogItem::query()
            ->with(['category.parent.parent.parent.parent', 'occurrences.category.parent.parent.parent.parent'])
            ->where('source', 'nikolacars')
            ->whereIn('source_url', $productIds
                ->map(fn (int $productId): string => 'nikolacars://donor-product/'.$productId)
                ->all())
            ->get()
            ->keyBy(fn (PartCatalogItem $item): int => (int) data_get($item->raw_attributes, 'product_id'));

        if ($itemsByProductId->isEmpty()) {
            return $sales;
        }

        return $sales
            ->map(function (PartSale $sale) use ($itemsByProductId): PartSale {
                if ($sale->part_catalog_item_id !== null || $sale->partCatalogItem instanceof PartCatalogItem) {
                    return $sale;
                }

                $productId = (int) ($sale->product_id ?: data_get($sale->raw_attributes, 'product_id'));
                $item = $itemsByProductId->get($productId);

                if ($item instanceof PartCatalogItem) {
                    $sale->setRelation('partCatalogItem', $item);
                }

                return $sale;
            })
            ->values();
    }

    protected function catalogDisplay(): PartCatalogDisplayService
    {
        return $this->catalogDisplayService ??= app(PartCatalogDisplayService::class);
    }

    protected function localizedNameSourcesForItems(Collection $items): Collection
    {
        return $this->catalogDisplay()->inventoryLocalizedNameSourcesForItems($items);
    }

    protected function rawAttributesArray(?PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    public function mobileParts(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $smallPartNumbers = $this->smallPartNumbers();

        return view('admin.mobile.parts.index', [
            'query' => $query,
            'donorCars' => DonorCar::query()
                ->withCount([
                    'products as checked_products_count' => fn (Builder $query) => $this->visibleMobileDonorProductsQuery($query, $smallPartNumbers)
                        ->whereIn('notes', NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES),
                    'partSales as sold_parts_count',
                ])
                ->where('status', '!=', DonorCar::STATUS_IN_TRANSIT)
                ->when($query !== '', fn ($builder) => $builder
                    ->where(fn ($builder) => $builder
                        ->where('vin', 'like', '%'.$query.'%')
                        ->orWhere('model', 'like', '%'.$query.'%')
                        ->orWhere('year', 'like', '%'.$query.'%')))
                ->latest('purchase_date')
                ->latest('id')
                ->get(),
        ]);
    }

    protected function visibleMobileDonorProductsQuery(Builder $query, Collection $smallPartNumbers): Builder
    {
        return $query
            ->whereNotIn('storage_status', [
                Product::STORAGE_STATUS_SOLD,
                Product::STORAGE_STATUS_WRITTEN_OFF,
            ])
            ->where(function (Builder $builder) use ($smallPartNumbers): void {
                $builder
                    ->whereDoesntHave('sourcePartCatalogItem', function (Builder $itemQuery): void {
                        $itemQuery->where(function (Builder $raw): void {
                            $raw
                                ->where('raw_attributes', 'like', '%"donor_vin_small_part":true%')
                                ->orWhere('raw_attributes', 'like', '%"donor_vin_small_part": true%');
                        });
                    });

                if ($smallPartNumbers->isNotEmpty()) {
                    $builder->whereNotIn('external_sku', $smallPartNumbers->all());
                }
            });
    }

    public function mobileDonorParts(DonorCar $donorCar): View
    {
        abort_if($donorCar->status === DonorCar::STATUS_IN_TRANSIT, 404);

        $donorCar->loadCount([
            'products as checked_products_count' => fn (Builder $query) => $query
                ->whereIn('notes', NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES),
            'partSales as sold_parts_count',
        ]);
        $donorCar->load([
            'products' => fn (HasMany $query) => $query
                ->with([
                    'category:id,name',
                    'sourcePartCatalogItem:id,part_catalog_category_id,part_number,name,name_en,name_ru,name_ua,source,model_label,model_name,year_from,year_to,main_category_name,subcategory_name,node_name,raw_attributes',
                    'sourcePartCatalogItem.category.parent.parent.parent.parent',
                    'sourcePartCatalogItem.occurrences.category.parent.parent.parent.parent',
                    'stockItems.warehouse:id,name',
                    'stockItems.location:id,full_code,cell',
                    'stoWorkOrderParts.order:id,number,status',
                ])
                ->orderByDesc('selling_price')
                ->orderBy('sku'),
            'partSales' => fn (HasMany $query) => $query
                ->with([
                    'partCatalogItem:id,part_catalog_category_id,part_number,name,name_en,name_ru,name_ua,source,model_label,model_name,year_from,year_to,main_category_name,subcategory_name,node_name,raw_attributes',
                    'partCatalogItem.category.parent.parent.parent.parent',
                    'partCatalogItem.occurrences.category.parent.parent.parent.parent',
                ])
                ->orderByRaw('(quantity * COALESCE(unit_price, 0)) desc')
                ->orderByDesc('sold_at')
                ->orderByDesc('id'),
        ]);

        return view('admin.mobile.parts.show', [
            'donorCar' => $donorCar,
            'damageOptions' => $this->mobilePartDamageOptions(),
            'smallPartNumbers' => $this->smallPartNumbers(),
        ]);
    }

    public function mobileUpdateProductDamageStatus(Request $request, DonorCar $donorCar, Product $product): RedirectResponse
    {
        abort_if($donorCar->status === DonorCar::STATUS_IN_TRANSIT, 404);
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $previousDamageNote = $product->notes;
        $damageOptions = $this->mobilePartDamageOptions();
        $validated = $request->validate([
            'damage_note' => ['required', 'string', Rule::in(array_keys($damageOptions))],
        ]);

        $damageNote = $validated['damage_note'];
        $damageStatusChangedBy = $product->donor_damage_status_changed_by;

        if ($this->isUnknownDonorDamageStatus($damageNote)) {
            $damageStatusChangedBy = null;
        } elseif ($this->isUnknownDonorDamageStatus($previousDamageNote)) {
            $damageStatusChangedBy = $request->user()?->id;
        }

        $product->forceFill([
            'condition_type' => 'used',
            'notes' => $damageNote,
            'donor_damage_status_changed_by' => $damageStatusChangedBy,
        ])->save();

        $product->stockItems()->update([
            'testing_status' => $product->testing_status ?: 'not_tested',
        ]);
        $inventorySync = app(NikolaCarsProductInventorySyncService::class);
        $syncResult = $inventorySync->syncProduct($product->refresh());
        $inventorySync->markDonorDamageCheckedAt(
            $product->refresh(),
            $syncResult['item'] ?? null,
            $previousDamageNote,
            $damageNote
        );
        $inventorySync->syncDonorDamageStatusChanger(
            $product->refresh(),
            $syncResult['item'] ?? null,
            $previousDamageNote,
            $damageNote,
            $damageStatusChangedBy
        );
        app(DonorProductLocalizedNameAutofillService::class)->fillOnKnownDamageStatus(
            $product->refresh(),
            $previousDamageNote,
            $damageNote
        );

        return redirect()
            ->to(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id)
            ->with('status', "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}.");
    }

    public function mobileMissingProduct(DonorCar $donorCar, Product $product): never
    {
        abort(404);
    }

    public function mobileEditProduct(DonorCar $donorCar, Product $product): View
    {
        abort_if($donorCar->status === DonorCar::STATUS_IN_TRANSIT, 404);
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $product->load([
            'sourcePartCatalogItem.category',
            'donorCar',
        ]);

        return view('admin.mobile.parts.edit', [
            'donorCar' => $donorCar,
            'product' => $product,
            'damageOptions' => $this->mobilePartDamageOptions(),
        ]);
    }

    public function mobileUpdateProduct(Request $request, DonorCar $donorCar, Product $product): RedirectResponse
    {
        abort_if($donorCar->status === DonorCar::STATUS_IN_TRANSIT, 404);
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $damageOptions = $this->mobilePartDamageOptions();
        $validated = $request->validate([
            'name_ru' => ['nullable', 'string', 'max:255'],
            'name_ua' => ['nullable', 'string', 'max:255'],
            'external_sku' => ['nullable', 'string', 'max:255'],
            'damage_note' => ['required', 'string', Rule::in(array_keys($damageOptions))],
        ]);

        $previousDamageNote = $product->notes;
        $product->load('sourcePartCatalogItem');
        $catalogItem = $product->sourcePartCatalogItem;
        $nameUpdates = collect([
            'name_ru' => trim((string) ($validated['name_ru'] ?? '')),
            'name_ua' => trim((string) ($validated['name_ua'] ?? '')),
        ])->filter(fn (string $value): bool => $value !== '')->all();

        if ($catalogItem && $nameUpdates !== []) {
            app(PartCatalogManualNameService::class)->lockAndPropagate($catalogItem, $nameUpdates);
        }

        $damageNote = $validated['damage_note'];
        $externalSku = trim((string) ($validated['external_sku'] ?? ''));
        $fallbackName = $nameUpdates['name_ua'] ?? $nameUpdates['name_ru'] ?? null;
        $inventorySync = app(NikolaCarsProductInventorySyncService::class);
        $damageStatusChangedBy = $inventorySync->damageStatusChangedByForTransition(
            $previousDamageNote,
            $damageNote,
            $request->user()?->id,
            $product->donor_damage_status_changed_by
        );

        $product->forceFill([
            'external_sku' => $externalSku !== '' ? $externalSku : null,
            'category_id' => $this->categoryFromCatalogSku($externalSku !== '' ? $externalSku : null, $donorCar)?->id ?? $product->category_id,
            'name' => $catalogItem ? $product->name : ($fallbackName ?: $product->name),
            'condition_type' => 'used',
            'notes' => $damageNote,
            'donor_damage_status_changed_by' => $damageStatusChangedBy,
        ])->save();

        $product->stockItems()->update([
            'testing_status' => $product->testing_status ?: 'not_tested',
        ]);
        $syncResult = $inventorySync->syncProduct($product->refresh());
        $inventorySync->markDonorDamageCheckedAt(
            $product->refresh(),
            $syncResult['item'] ?? null,
            $previousDamageNote,
            $damageNote
        );
        $inventorySync->syncDonorDamageStatusChanger(
            $product->refresh(),
            $syncResult['item'] ?? null,
            $previousDamageNote,
            $damageNote,
            $damageStatusChangedBy
        );
        app(DonorProductLocalizedNameAutofillService::class)->fillOnKnownDamageStatus(
            $product->refresh(),
            $previousDamageNote,
            $damageNote
        );

        return redirect()
            ->route('admin.mobile.donor-cars.products.edit', [$donorCar, $product])
            ->with('status', "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}\u{0430}.");
    }

    public function mobileStoreProductPhoto(Request $request, DonorCar $donorCar, Product $product): RedirectResponse
    {
        abort_if($donorCar->status === DonorCar::STATUS_IN_TRANSIT, 404);
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $validated = $request->validate([
            'photo' => ['required', 'image', 'max:10240'],
            'return_to' => ['nullable', Rule::in(['edit'])],
        ]);

        $path = $validated['photo']->store('product-photos', 'public');
        $photos = collect([$path])
            ->merge(ProductPhotoNormalizer::productPhotos($product))
            ->filter()
            ->unique(fn (string $photo): string => ProductPhotoNormalizer::imageKey($photo))
            ->values();

        $product->forceFill(ProductPhotoNormalizer::persistencePayload($photos))->save();

        $redirectUrl = ($validated['return_to'] ?? null) === 'edit'
            ? route('admin.mobile.donor-cars.products.edit', [$donorCar, $product])
            : route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id;

        return redirect()
            ->to($redirectUrl)
            ->with('status', "\u{0424}\u{043E}\u{0442}\u{043E} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043E}.");
    }

    public function mobileDestroyProductPhoto(Request $request, DonorCar $donorCar, Product $product): RedirectResponse
    {
        abort_if($donorCar->status === DonorCar::STATUS_IN_TRANSIT, 404);
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $validated = $request->validate([
            'photo' => ['required', 'string'],
        ]);
        $photo = trim($validated['photo']);

        if (Str::contains($photo, 'tesla-official/part-images/')) {
            throw ValidationException::withMessages([
                'photo' => "\u{0424}\u{043E}\u{0442}\u{043E} \u{0441} tesla.com \u{0443}\u{0434}\u{0430}\u{043B}\u{044F}\u{0442}\u{044C} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F}.",
            ]);
        }

        $currentPhotos = ProductPhotoNormalizer::productPhotos($product);

        if (! $currentPhotos->contains($photo)) {
            throw ValidationException::withMessages([
                'photo' => "\u{0424}\u{043E}\u{0442}\u{043E} \u{043D}\u{0435} \u{043D}\u{0430}\u{0439}\u{0434}\u{0435}\u{043D}\u{043E}.",
            ]);
        }

        $remainingPhotos = $currentPhotos
            ->reject(fn (string $path): bool => $path === $photo)
            ->values();

        $product->forceFill(ProductPhotoNormalizer::persistencePayload($remainingPhotos))->save();

        if (! Str::startsWith($photo, ['http://', 'https://', '/'])) {
            Storage::disk('public')->delete($photo);
        }

        return redirect()
            ->route('admin.mobile.donor-cars.products.edit', [$donorCar, $product])
            ->with('status', "\u{0424}\u{043E}\u{0442}\u{043E} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{043E}.");
    }

    public function mobileUpdateProductPhotoOrder(Request $request, DonorCar $donorCar, Product $product): RedirectResponse|JsonResponse
    {
        abort_if($donorCar->status === DonorCar::STATUS_IN_TRANSIT, 404);
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $validated = $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'string', 'distinct'],
        ]);
        $submittedPhotos = collect($validated['photos'])
            ->map(fn (string $path): string => trim($path))
            ->filter()
            ->values();
        $currentPhotos = ProductPhotoNormalizer::productPhotos($product);

        if (
            $submittedPhotos->count() !== $currentPhotos->count()
            || $submittedPhotos->sort()->values()->all() !== $currentPhotos->sort()->values()->all()
        ) {
            throw ValidationException::withMessages([
                'photos' => "\u{041F}\u{043E}\u{0440}\u{044F}\u{0434}\u{043E}\u{043A} \u{0444}\u{043E}\u{0442}\u{043E} \u{0443}\u{0441}\u{0442}\u{0430}\u{0440}\u{0435}\u{043B}. \u{041E}\u{0431}\u{043D}\u{043E}\u{0432}\u{0438}\u{0442}\u{0435} \u{0441}\u{0442}\u{0440}\u{0430}\u{043D}\u{0438}\u{0446}\u{0443}.",
            ]);
        }

        $product->forceFill(ProductPhotoNormalizer::persistencePayload($submittedPhotos))->save();

        if ($request->expectsJson()) {
            return response()->json([
                'main_image' => $submittedPhotos->first(),
                'photos' => $submittedPhotos->all(),
            ]);
        }

        return redirect()
            ->route('admin.mobile.donor-cars.products.edit', [$donorCar, $product])
            ->with('status', "\u{041F}\u{043E}\u{0440}\u{044F}\u{0434}\u{043E}\u{043A} \u{0444}\u{043E}\u{0442}\u{043E} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}.");
    }

    public function mobileCreateProduct(DonorCar $donorCar): View
    {
        return view('admin.mobile.parts.create', [
            'donorCar' => $donorCar->loadCount('products'),
            'warehouses' => $this->activeWarehouses(),
            'nextPartCode' => $this->nextPartCode($donorCar),
            'damageOptions' => $this->manualDonorProductDamageOptions(),
        ]);
    }

    protected function sortDonorProducts(Builder|HasMany $query, string $sort, string $direction): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'photo' => $query
                ->orderByRaw("CASE WHEN NULLIF(main_image, '') IS NULL THEN 0 ELSE 1 END {$direction}")
                ->orderBy('sku'),
            'external_sku' => $query
                ->orderByRaw("COALESCE(external_sku, '') {$direction}")
                ->orderBy('sku'),
            'name' => $query
                ->orderByRaw("COALESCE(name, '') {$direction}")
                ->orderBy('sku'),
            'category' => $query
                ->leftJoin('categories as product_sort_categories', 'products.category_id', '=', 'product_sort_categories.id')
                ->orderByRaw("COALESCE(product_sort_categories.name, '') {$direction}")
                ->orderBy('products.sku')
                ->select('products.*'),
            'condition' => $query
                ->orderByRaw("COALESCE(condition_type, '') {$direction}")
                ->orderBy('sku'),
            'damage_note' => $query
                ->orderByRaw("COALESCE(notes, '') {$direction}")
                ->orderBy('sku'),
            'tesla_price' => $query
                ->leftJoin('part_catalog_items as product_sort_source_items', 'products.source_part_catalog_item_id', '=', 'product_sort_source_items.id')
                ->orderByRaw("COALESCE(product_sort_source_items.price_amount, 0) {$direction}")
                ->orderBy('products.sku')
                ->select('products.*'),
            'price' => $query
                ->orderBy('selling_price', $direction)
                ->orderBy('sku'),
            'quantity' => $query
                ->orderByRaw("(select coalesce(sum(quantity), 0) from stock_items where stock_items.product_id = products.id) {$direction}")
                ->orderBy('products.sku'),
            'warehouse' => $query
                ->leftJoin('stock_items as product_sort_stock_items', 'products.id', '=', 'product_sort_stock_items.product_id')
                ->leftJoin('warehouses as product_sort_warehouses', 'product_sort_stock_items.warehouse_id', '=', 'product_sort_warehouses.id')
                ->orderByRaw("COALESCE(product_sort_warehouses.name, products.storage_status, '') {$direction}")
                ->orderBy('products.sku')
                ->select('products.*'),
            'location' => $query
                ->leftJoin('stock_items as product_sort_location_stock_items', 'products.id', '=', 'product_sort_location_stock_items.product_id')
                ->leftJoin('locations as product_sort_locations', 'product_sort_location_stock_items.location_id', '=', 'product_sort_locations.id')
                ->orderByRaw("COALESCE(product_sort_locations.cell, product_sort_locations.full_code, '') {$direction}")
                ->orderBy('products.sku')
                ->select('products.*'),
            default => $query->orderBy('sku', $direction)->orderBy('id'),
        };
    }

    protected function sortDonorPartSales(Builder|HasMany $query, string $sort, string $direction): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'part_number' => $query
                ->orderByRaw("COALESCE(part_number, code, '') {$direction}")
                ->orderBy('id', $direction),
            'name' => $query
                ->orderByRaw("COALESCE(name, '') {$direction}")
                ->orderBy('id', $direction),
            'category' => $query
                ->orderByRaw("COALESCE(category_path, '') {$direction}")
                ->orderBy('id', $direction),
            'quantity' => $query
                ->orderBy('quantity', $direction)
                ->orderBy('id', $direction),
            'unit_price' => $query
                ->orderBy('unit_price', $direction)
                ->orderBy('id', $direction),
            'total_amount' => $query
                ->orderBy('total_amount', $direction)
                ->orderBy('id', $direction),
            'document_number' => $query
                ->orderByRaw("COALESCE(document_number, '') {$direction}")
                ->orderBy('id', $direction),
            'counterparty' => $query
                ->orderByRaw("COALESCE(counterparty, '') {$direction}")
                ->orderBy('id', $direction),
            default => $query
                ->orderBy('sold_at', $direction)
                ->orderBy('id', $direction),
        };
    }

    public function mobileProductSuggestions(Request $request, DonorCar $donorCar): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $likeQuery = '%'.$query.'%';
        $driver = DB::connection()->getDriverName();
        $donorCatalogModel = Str::lower(trim($donorCar->display_model ?: $donorCar->model));
        $donorCatalogYear = $donorCar->year ? (int) $donorCar->year : null;

        $donorProductsQuery = $donorCar->products()
            ->with('category:id,name')
            ->latest()
            ->select([
                'id',
                'sku',
                'external_sku',
                'name',
                'category_id',
                'description',
                'color',
                'selling_price',
                'notes',
            ]);

        $donorProducts = match ($driver) {
            'sqlite' => $donorProductsQuery
                ->get()
                ->filter(fn (Product $product) => collect([$product->name, $product->sku, $product->external_sku])
                    ->filter()
                    ->contains(fn (string $value) => mb_stripos($value, $query) !== false))
                ->take(8)
                ->values(),
            'pgsql' => $donorProductsQuery
                ->where(fn (Builder $builder) => $builder
                    ->where('name', 'ilike', $likeQuery)
                    ->orWhere('sku', 'ilike', $likeQuery)
                    ->orWhere('external_sku', 'ilike', $likeQuery))
                ->limit(8)
                ->get(),
            default => $donorProductsQuery
                ->where(fn (Builder $builder) => $builder
                    ->where('name', 'like', $likeQuery)
                    ->orWhere('sku', 'like', $likeQuery)
                    ->orWhere('external_sku', 'like', $likeQuery))
                ->limit(8)
                ->get(),
        };

        $catalogQuery = PartCatalogItem::query()
            ->orderBy('name')
            ->select([
                'id',
                'part_number',
                'name',
                'model_label',
                'model_name',
                'year_from',
                'year_to',
                'main_category_name',
                'subcategory_name',
                'node_name',
            ]);

        $catalogItems = match ($driver) {
            'sqlite' => $catalogQuery
                ->get()
                ->filter(fn (PartCatalogItem $item) => trim((string) $item->name) !== '')
                ->filter(fn (PartCatalogItem $item): bool => $this->catalogItemMatchesDonor($item, $donorCatalogModel, $donorCatalogYear))
                ->filter(fn (PartCatalogItem $item) => collect([$item->name, $item->part_number])
                    ->filter()
                    ->contains(fn (string $value) => mb_stripos($value, $query) !== false))
                ->pipe(fn ($items) => app(PartCatalogDeduplicator::class)->deduplicate($items))
                ->take(10)
                ->values(),
            'pgsql' => $catalogQuery
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->where(fn (Builder $builder) => $builder
                    ->where('name', 'ilike', $likeQuery)
                    ->orWhere('part_number', 'ilike', $likeQuery))
                ->when($donorCatalogModel !== '', fn (Builder $query) => $this->applyCatalogModelFilter($query, $donorCatalogModel))
                ->when($donorCatalogYear, fn (Builder $query) => $this->applyCatalogYearFilter($query, $donorCatalogYear))
                ->limit(50)
                ->get()
                ->pipe(fn ($items) => app(PartCatalogDeduplicator::class)->deduplicate($items))
                ->take(10)
                ->values(),
            default => $catalogQuery
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->where(fn (Builder $builder) => $builder
                    ->where('name', 'like', $likeQuery)
                    ->orWhere('part_number', 'like', $likeQuery))
                ->when($donorCatalogModel !== '', fn (Builder $query) => $this->applyCatalogModelFilter($query, $donorCatalogModel))
                ->when($donorCatalogYear, fn (Builder $query) => $this->applyCatalogYearFilter($query, $donorCatalogYear))
                ->limit(50)
                ->get()
                ->pipe(fn ($items) => app(PartCatalogDeduplicator::class)->deduplicate($items))
                ->take(10)
                ->values(),
        };

        $donorSuggestions = $donorProducts->map(fn (Product $product): array => [
            'type' => 'donor',
            'name' => $product->name,
            'external_sku' => $product->external_sku,
            'description' => $product->description,
            'color' => $product->color,
            'selling_price' => $product->selling_price,
            'notes' => $product->notes,
            'meta' => collect([
                'Уже был у донора',
                $product->sku,
                $product->category?->name,
            ])->filter()->join(' · '),
        ]);

        $catalogSuggestions = $catalogItems->map(function (PartCatalogItem $item): array {
            $categoryPath = collect([$item->main_category_name, $item->subcategory_name, $item->node_name])
                ->filter()
                ->implode(' / ');

            return [
                'type' => 'catalog',
                'name' => $item->name,
                'external_sku' => $item->part_number,
                'description' => $categoryPath ?: null,
                'color' => null,
                'selling_price' => null,
                'notes' => null,
                'meta' => collect([
                    'Каталог',
                    $item->part_number ? '№ '.$item->part_number : null,
                    $categoryPath,
                    $item->model_label ?: $item->model_name,
                ])->filter()->join(' · '),
            ];
        });

        return response()->json($donorSuggestions->concat($catalogSuggestions)->take(15)->values());
    }

    protected function applyCatalogModelFilter(Builder $query, string $model): void
    {
        $query->where(function (Builder $builder) use ($model): void {
            $builder
                ->whereRaw('LOWER(COALESCE(model_label, ?)) = ?', ['', $model])
                ->orWhereRaw('LOWER(COALESCE(model_name, ?)) = ?', ['', $model])
                ->orWhereRaw('LOWER(COALESCE(model_label, ?)) like ?', ['', '%'.$model.'%'])
                ->orWhereRaw('LOWER(COALESCE(model_name, ?)) like ?', ['', '%'.$model.'%']);
        });
    }

    protected function applyCatalogYearFilter(Builder $query, int $year): void
    {
        $query
            ->where(function (Builder $builder) use ($year): void {
                $builder
                    ->whereNull('year_from')
                    ->orWhere('year_from', '<=', $year);
            })
            ->where(function (Builder $builder) use ($year): void {
                $builder
                    ->whereNull('year_to')
                    ->orWhere('year_to', '>=', $year);
            });
    }

    protected function catalogItemMatchesDonor(PartCatalogItem $item, string $model, ?int $year): bool
    {
        if ($model !== '') {
            $itemModels = collect([$item->model_label, $item->model_name])
                ->map(fn (?string $value): string => Str::lower(trim((string) $value)))
                ->filter();

            if (! $itemModels->contains(fn (string $value): bool => $value === $model || str_contains($value, $model))) {
                return false;
            }
        }

        if ($year !== null) {
            if ($item->year_from !== null && (int) $item->year_from > $year) {
                return false;
            }

            if ($item->year_to !== null && (int) $item->year_to < $year) {
                return false;
            }
        }

        return true;
    }

    public function mobileStoreProduct(Request $request, DonorCar $donorCar): RedirectResponse
    {
        $this->createDonorProduct($request, $donorCar);

        return redirect()
            ->route('admin.mobile.donor-cars.products.create', $donorCar)
            ->with('status', 'Запчасть добавлена. Можно добавлять следующую по этому донору.');
    }

    public function generateProducts(Request $request, DonorCar $donorCar, DonorProductGenerationService $generator): RedirectResponse
    {
        $validated = $request->validate([
            'damage_zones' => ['nullable', 'array'],
            'damage_zones.*' => ['string', Rule::in(array_keys(DonorProductGenerationService::DAMAGE_ZONES))],
            'catalog_item_ids' => ['nullable', 'array'],
            'catalog_item_ids.*' => ['integer', 'exists:part_catalog_items,id'],
        ]);

        $stats = $generator->generate($donorCar, $validated['damage_zones'] ?? [], $validated['catalog_item_ids'] ?? []);

        return redirect()
            ->route('admin.donor-cars.show', $donorCar)
            ->with('status', "Автогенерация завершена: создано {$stats['created']} (целых {$stats['created_whole']}, разбитых {$stats['created_damaged']}), обновлено {$stats['updated_existing']}, уже были {$stats['skipped_existing']}.");
    }

    public function downloadOfficialProducts(Request $request, DonorCar $donorCar, OfficialCatalogDownloadStatus $statuses): RedirectResponse|JsonResponse
    {
        if ($statuses->isRunning((int) $donorCar->id)) {
            $status = $statuses->forDonor((int) $donorCar->id);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'running',
                    'message' => $status['message'] ?? 'Выкачка официального каталога уже выполняется.',
                    'download' => $status,
                ], 409);
            }

            return redirect()
                ->route('admin.donor-cars.show', $donorCar)
                ->with('status', 'Выкачка официального каталога уже выполняется.');
        }

        if ($runningDownload = $statuses->running()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'running',
                    'message' => 'Уже идет выкачка официального каталога для другого донора.',
                    'download' => $runningDownload,
                ], 409);
            }

            return redirect()
                ->route('admin.donor-cars.show', $donorCar)
                ->with('status', 'Уже идет выкачка официального каталога для другого донора.');
        }

        $requirementUpdates = [];

        if ($request->hasAny(['drive_type', 'battery_type', 'is_performance'])) {
            $validated = $request->validate([
                'drive_type' => ['nullable', Rule::in(array_keys(DonorCar::DRIVE_TYPES))],
                'battery_type' => ['nullable', Rule::in(array_keys(DonorCar::BATTERY_TYPES))],
                'is_performance' => ['nullable', 'boolean'],
            ]);

            if (! $donorCar->drive_type && ! empty($validated['drive_type'])) {
                $requirementUpdates['drive_type'] = $validated['drive_type'];
            }

            if (! $donorCar->battery_type && ! empty($validated['battery_type'])) {
                $requirementUpdates['battery_type'] = $validated['battery_type'];
            }

            if ($donorCar->is_performance === null && array_key_exists('is_performance', $validated) && $validated['is_performance'] !== null && $validated['is_performance'] !== '') {
                $requirementUpdates['is_performance'] = (bool) $validated['is_performance'];
            }

            if ($requirementUpdates !== []) {
                $donorCar->forceFill($requirementUpdates)->save();
                $donorCar->refresh();
            }
        }

        $downloadRequirements = [];

        if (! $donorCar->drive_type) {
            $downloadRequirements['drive_type'] = 'Перед выкачкой запчастей с официального каталога выберите привод донора.';
        }

        if (! $donorCar->battery_type) {
            $downloadRequirements['battery_type'] = 'Перед выкачкой запчастей с официального каталога выберите батарею донора.';
        }

        if ($donorCar->is_performance === null) {
            $downloadRequirements['is_performance'] = 'Перед выкачкой запчастей с официального каталога укажите, является ли донор Performance.';
        }

        if ($downloadRequirements !== []) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'validation_error',
                    'message' => 'Перед выкачкой запчастей с официального каталога заполните привод, батарею и Performance.',
                    'errors' => $downloadRequirements,
                ], 422);
            }

            return redirect()
                ->route('admin.donor-cars.show', $donorCar)
                ->withErrors($downloadRequirements)
                ->with('status', 'Перед выкачкой запчастей с официального каталога заполните привод, батарею и Performance.');
        }

        $token = (string) Str::uuid();
        $status = $statuses->tryStart($donorCar, $token);

        if ($status === null) {
            $runningDownload = $statuses->running();

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'running',
                    'message' => 'Уже идет выкачка официального каталога.',
                    'download' => $runningDownload,
                ], 409);
            }

            return redirect()
                ->route('admin.donor-cars.show', $donorCar)
                ->with('status', 'Уже идет выкачка официального каталога.');
        }

        $this->startOfficialDownloadProcess($donorCar, $token);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'started',
                'message' => 'Выкачка официального каталога запущена в фоне.',
                'download' => $status,
            ], 202);
        }

        return redirect()
            ->route('admin.donor-cars.show', $donorCar)
            ->with('status', 'Выкачка официального каталога запущена в фоне.');
    }

    public function officialDownloadStatuses(OfficialCatalogDownloadStatus $statuses): JsonResponse
    {
        return response()->json([
            'downloads' => $statuses->all(),
        ]);
    }

    public function previewGeneratedProducts(Request $request, DonorCar $donorCar, DonorProductGenerationService $generator): JsonResponse
    {
        $validated = $request->validate([
            'damage_zones' => ['nullable', 'array'],
            'damage_zones.*' => ['string', Rule::in(array_keys(DonorProductGenerationService::DAMAGE_ZONES))],
        ]);

        return response()->json($generator->preview($donorCar, $validated['damage_zones'] ?? []));
    }

    public function storeProduct(Request $request, DonorCar $donorCar): RedirectResponse
    {
        $warehouse = Warehouse::query()->find($request->input('warehouse_id'));
        $floorRules = [$warehouse?->hasMultipleFloors() ? 'required' : 'nullable', 'string'];

        if ($warehouse) {
            $floorRules[] = Rule::in(array_keys($warehouse->availableFloors()));
        }

        $damageOptions = $this->manualDonorProductDamageOptions();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'damage_note' => ['required', 'string', Rule::in(array_keys($damageOptions))],
            'condition_type' => ['nullable', Rule::in(Product::CONDITION_TYPES)],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:255'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'max:10240'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'external_sku' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'floor' => $floorRules,
            'location_cell' => ['nullable', 'string', 'max:50'],
        ]);

        $sku = $this->uniquePartCode($donorCar);
        $category = $this->categoryFromCatalogSku($validated['external_sku'] ?? null, $donorCar);
        $conditionType = $validated['condition_type'] ?? 'used';

        $photos = [];
        foreach ($request->file('photos', []) as $photo) {
            $photos[] = $photo->store('product-photos', 'public');
        }

        DB::transaction(function () use ($validated, $donorCar, $sku, $photos, $category, $conditionType): void {
            $product = Product::query()->create([
                'sku' => $sku,
                'external_sku' => $validated['external_sku'] ?? null,
                'name' => $validated['name'],
                'slug' => $this->uniqueProductSlug($validated['name'], $sku),
                'category_id' => $category?->id,
                'donor_car_id' => $donorCar->id,
                'part_origin' => Product::PART_ORIGIN_ORIGINAL,
                'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
                'model' => $donorCar->model,
                'color' => $validated['color'] ?? null,
                'description' => $validated['description'] ?? null,
                'condition_type' => $conditionType,
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'purchase_price' => 0,
                'selling_price' => $validated['selling_price'] ?? 0,
                'currency' => 'USD',
                'barcode' => $sku,
                'qr_code' => $sku,
                'main_image' => $photos[0] ?? null,
                'images_json' => $photos ?: null,
                'notes' => $validated['damage_note'],
                'is_active' => true,
            ]);

            app(TeslaCatalogDonorProductSync::class)->syncProduct($product);

            $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
            $location = $this->resolveInitialLocation($warehouse, $validated['floor'] ?? null, $validated['location_cell'] ?? null);

            app(StockService::class)->intake([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'quantity' => 1,
                'comment' => 'Первичное размещение при добавлении запчасти.',
            ]);
        });

        return redirect()
            ->route('admin.donor-cars.show', $donorCar)
            ->with('status', 'Запчастину додано.');
    }

    public function destroyProduct(DonorCar $donorCar, Product $product, DeletedPartArchiveService $archive): RedirectResponse
    {
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $product->loadMissing('sourcePartCatalogItem');

        if ((bool) $product->is_auto_generated || $product->generated_at !== null) {
            throw ValidationException::withMessages([
                'product' => "\u{0410}\u{0432}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442}\u{0438}\u{0447}\u{0435}\u{0441}\u{043A}\u{0438} \u{0441}\u{0433}\u{0435}\u{043D}\u{0435}\u{0440}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{043D}\u{043D}\u{0443}\u{044E} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0443}\u{0434}\u{0430}\u{043B}\u{044F}\u{0442}\u{044C}.",
            ]);
        }

        $this->ensureDonorProductCanBeChanged($product);

        if (StoWorkOrderPart::query()->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages([
                'product' => 'Нельзя удалить запчасть, которая уже использована в заказ-наряде.',
            ]);
        }

        DB::transaction(function () use ($product, $archive): void {
            $product->loadMissing('sourcePartCatalogItem');
            $archive->archiveProduct($product, 'donor');

            PartCatalogItem::query()
                ->where('source', 'nikolacars')
                ->where('source_url', 'nikolacars://donor-product/'.$product->id)
                ->delete();

            if ($product->sourcePartCatalogItem?->source === 'nikolacars') {
                $product->sourcePartCatalogItem->delete();
            }

            $product->delete();
        });

        return redirect()
            ->route('admin.donor-cars.show', $donorCar)
            ->with('status', 'Запчасть удалена с донора.');
    }

    public function updateProductName(Request $request, DonorCar $donorCar, Product $product): RedirectResponse|JsonResponse
    {
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $this->ensureDonorProductCanBeChanged($product);

        $validated = $request->validate([
            'name_type' => ['required', Rule::in(['name_ru', 'name_ua'])],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim((string) $validated['name']);

        $catalogItem = $product->sourcePartCatalogItem;

        if (! $catalogItem) {
            throw ValidationException::withMessages([
                'name_type' => 'У этой запчасти нет связанной позиции каталога для RU/UA названия.',
            ]);
        }

        app(PartCatalogManualNameService::class)->lockAndPropagate($catalogItem, [
            $validated['name_type'] => $name,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'catalog_item_id' => $catalogItem->id,
                'name_type' => $validated['name_type'],
                'name' => $name,
                'display_name' => $name,
                'manual' => true,
            ]);
        }

        return redirect()
            ->route('admin.donor-cars.show', $donorCar)
            ->with('status', "\u{041D}\u{0430}\u{0437}\u{0432}\u{0430}\u{043D}\u{0438}\u{0435} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}\u{043E}.");
    }

    public function updateOfficialProductFields(Request $request, DonorCar $donorCar, Product $product): RedirectResponse|JsonResponse
    {
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $this->ensureDonorProductCanBeChanged($product);

        $damageOptions = $this->donorProductDamageOptions();
        $validated = $request->validate([
            'damage_note' => ['nullable', 'string', Rule::in(array_keys($damageOptions))],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $damageNote = $validated['damage_note'] ?? null;
        $unknownDamageNote = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";
        $brokenDamageNote = NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS;
        $payload = [];
        $previousDamageNote = $product->notes;

        if (array_key_exists('damage_note', $validated)) {
            $damageNote = (string) ($damageNote ?? '');
            $inventorySync = app(NikolaCarsProductInventorySyncService::class);
            $payload = [
                'condition_type' => 'used',
                'notes' => $damageNote !== '' ? $damageNote : null,
                'donor_damage_status_changed_by' => $inventorySync->damageStatusChangedByForTransition(
                    $previousDamageNote,
                    $damageNote,
                    $request->user()?->id,
                    $product->donor_damage_status_changed_by
                ),
            ];
        }

        if (array_key_exists('selling_price', $validated)) {
            $payload['selling_price'] = $validated['selling_price'] !== null
                ? round((float) $validated['selling_price'], 2)
                : 0.0;
            $payload['currency'] = 'USD';
        }

        if ($payload !== []) {
            $syncResult = DB::transaction(function () use ($product, $payload): array {
                $product->forceFill($payload)->save();

                if (array_key_exists('selling_price', $payload)) {
                    $this->syncDonorProductSellingPriceToNikolaCarsItems($product, (float) $payload['selling_price']);
                }

                return app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
            });

            if (array_key_exists('damage_note', $validated)) {
                $inventorySync = app(NikolaCarsProductInventorySyncService::class);
                $inventorySync->markDonorDamageCheckedAt(
                    $product->refresh(),
                    $syncResult['item'] ?? null,
                    $previousDamageNote,
                    $damageNote
                );
                $inventorySync->syncDonorDamageStatusChanger(
                    $product->refresh(),
                    $syncResult['item'] ?? null,
                    $previousDamageNote,
                    $damageNote,
                    $product->donor_damage_status_changed_by
                );
                app(DonorProductLocalizedNameAutofillService::class)->fillOnKnownDamageStatus(
                    $product->refresh(),
                    $previousDamageNote,
                    $damageNote
                );
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '???? ??????????? ???????? ?????????.',
                'damage_note' => $damageNote ?? $product->notes,
                'destination' => match ($damageNote ?? $product->notes ?? '') {
                    $brokenDamageNote, NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS => 'broken',
                    '', $unknownDamageNote => 'all',
                    default => 'checked',
                },
                'selling_price' => $product->refresh()->selling_price !== null ? (float) $product->selling_price : null,
            ]);
        }

        return back()->with('status', '???? ??????????? ???????? ?????????.');
    }

    public function markProductAsSmallPart(Request $request, DonorCar $donorCar, Product $product): RedirectResponse|JsonResponse
    {
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $product->loadMissing('sourcePartCatalogItem');
        $catalogItem = $product->sourcePartCatalogItem;

        if (! $catalogItem instanceof PartCatalogItem) {
            $syncResult = app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
            $catalogItem = $syncResult['item'] ?? null;
            $product->refresh()->loadMissing('sourcePartCatalogItem');
            $catalogItem = $catalogItem instanceof PartCatalogItem
                ? $catalogItem
                : $product->sourcePartCatalogItem;
        }

        $partNumber = $this->smallPartNumberForProduct($product);

        if ($partNumber === null) {
            throw ValidationException::withMessages([
                'product' => "\u{0423} \u{044D}\u{0442}\u{043E}\u{0439} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{043D}\u{0435}\u{0442} \u{0430}\u{0440}\u{0442}\u{0438}\u{043A}\u{0443}\u{043B}\u{0430} \u{0434}\u{043B}\u{044F} \u{043C}\u{0435}\u{043B}\u{043E}\u{0447}\u{0435}\u{0432}\u{043A}\u{0438}.",
            ]);
        }

        $affectedProductIds = $this->syncSmallPartFlagForPartNumber($partNumber, true);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{043D}\u{0435}\u{0441}\u{0435}\u{043D}\u{0430} \u{0432} \u{041C}\u{0435}\u{043B}\u{043E}\u{0447}\u{0435}\u{0432}\u{043A}\u{0443}.",
                'part_number' => $partNumber,
                'affected_product_ids' => $affectedProductIds->all(),
            ]);
        }

        return redirect()
            ->route('admin.donor-cars.show', $donorCar)
            ->with('status', "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{0430} \u{0432} \u{043C}\u{0435}\u{043B}\u{043E}\u{0447}\u{0435}\u{0432}\u{043A}\u{0443}.");
    }

    public function unmarkProductAsSmallPart(DonorCar $donorCar, Product $product): RedirectResponse
    {
        abort_unless((int) $product->donor_car_id === (int) $donorCar->id, 404);

        $product->loadMissing('sourcePartCatalogItem');
        $catalogItem = $product->sourcePartCatalogItem;

        $partNumber = $this->smallPartNumberForProduct($product);

        if ($partNumber === null) {
            throw ValidationException::withMessages([
                'product' => "\u{0423} \u{044D}\u{0442}\u{043E}\u{0439} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{043D}\u{0435}\u{0442} \u{0430}\u{0440}\u{0442}\u{0438}\u{043A}\u{0443}\u{043B}\u{0430}.",
            ]);
        }

        $this->syncSmallPartFlagForPartNumber($partNumber, false);

        return redirect()
            ->route('admin.donor-cars.small-parts.index', $donorCar)
            ->with('status', "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{0443}\u{0431}\u{0440}\u{0430}\u{043D}\u{0430} \u{0438}\u{0437} \u{043C}\u{0435}\u{043B}\u{043E}\u{0447}\u{0435}\u{0432}\u{043A}\u{0438}.");
    }

    protected function smallPartNumberForProduct(Product $product): ?string
    {
        return PartNumberNormalizer::normalize($product->external_sku ?: $product->sourcePartCatalogItem?->part_number);
    }

    protected function syncSmallPartFlagForPartNumber(string $partNumber, bool $isSmallPart): Collection
    {
        $partNumber = PartNumberNormalizer::normalize($partNumber);

        if ($partNumber === null) {
            return collect();
        }

        $products = Product::query()
            ->with('sourcePartCatalogItem')
            ->whereNotNull('donor_car_id')
            ->where(function (Builder $query) use ($partNumber): void {
                $query
                    ->where('external_sku', $partNumber)
                    ->orWhereHas('sourcePartCatalogItem', fn (Builder $query) => $query
                        ->where('part_number', $partNumber));
            })
            ->get();

        $catalogItems = PartCatalogItem::query()
            ->where('part_number', $partNumber)
            ->get()
            ->merge($products->pluck('sourcePartCatalogItem')->filter())
            ->unique('id')
            ->values();

        foreach ($products as $product) {
            if ($product->sourcePartCatalogItem instanceof PartCatalogItem || ! $isSmallPart) {
                continue;
            }

            $syncResult = app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
            $syncedItem = $syncResult['item'] ?? null;

            if ($syncedItem instanceof PartCatalogItem) {
                $catalogItems->push($syncedItem);
            }
        }

        $catalogItems
            ->unique('id')
            ->each(function (PartCatalogItem $catalogItem) use ($isSmallPart, $partNumber): void {
                $rawAttributes = PartCatalogRawAttributes::from($catalogItem);

                if ($isSmallPart) {
                    $rawAttributes['donor_vin_small_part'] = true;
                    $rawAttributes['donor_vin_small_part_part_number'] = $partNumber;
                    $rawAttributes['donor_vin_small_part_reason'] = 'manual';
                    $rawAttributes['donor_vin_small_part_marked_at'] = now()->toIso8601String();
                } else {
                    unset(
                        $rawAttributes['donor_vin_small_part'],
                        $rawAttributes['donor_vin_small_part_part_number'],
                        $rawAttributes['donor_vin_small_part_reason'],
                        $rawAttributes['donor_vin_small_part_marked_at']
                    );
                }

                $catalogItem->forceFill(['raw_attributes' => $rawAttributes])->save();
            });

        return $products
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    protected function syncDonorProductSellingPriceToNikolaCarsItems(Product $product, float $sellingPrice): void
    {
        $product->loadMissing('sourcePartCatalogItem');

        PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where(function (Builder $query) use ($product): void {
                $query->where('source_url', 'nikolacars://donor-product/'.$product->id)
                    ->orWhere('raw_attributes->product_id', $product->id);

                if ($product->sourcePartCatalogItem?->source === 'nikolacars') {
                    $query->orWhere('id', $product->sourcePartCatalogItem->id);
                }
            })
            ->update([
                'price_amount' => $sellingPrice,
                'currency' => 'USD',
                'updated_at' => now(),
            ]);
    }

    protected function ensureDonorProductCanBeChanged(Product $product): void
    {
        if (! $this->isDonorProductReserved($product)) {
            return;
        }

        throw ValidationException::withMessages([
            'product' => "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{0432} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432}\u{0435}. \u{0421}\u{043D}\u{0438}\u{043C}\u{0438}\u{0442}\u{0435} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}\u{0438}\u{0435}\u{043C} \u{0438}\u{043B}\u{0438} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{0438}\u{0435}\u{043C}.",
        ]);
    }

    protected function isDonorProductReserved(Product $product): bool
    {
        if (StoWorkOrderPart::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn (Builder $query) => $query
                ->whereIn('status', [
                    StoWorkOrder::STATUS_IN_WORK,
                    StoWorkOrder::STATUS_COMPLETED,
                ]))
            ->exists()) {
            return true;
        }

        $product->loadMissing('sourcePartCatalogItem');
        $catalogItem = $product->sourcePartCatalogItem;

        if (! $catalogItem instanceof PartCatalogItem) {
            return false;
        }

        if ((float) data_get($catalogItem->raw_attributes, 'reserved_quantity', 0) > 0) {
            return true;
        }

        return CustomerOrderItem::query()
            ->where('part_catalog_item_id', $catalogItem->id)
            ->whereHas('order', fn (Builder $query) => $query->reservable())
            ->exists();
    }

    public function edit(DonorCar $donorCar): View
    {
        return view('admin.donor_cars.form', [
            'donorCar' => $donorCar,
            ...$this->formOptions(),
        ]);
    }

    public function update(DonorCarRequest $request, DonorCar $donorCar): RedirectResponse
    {
        $donorCar->update($this->payload($request, $donorCar));

        return redirect()->route('admin.donor-cars.show', $donorCar)->with('status', 'Донорский автомобиль обновлен.');
    }

    public function updateStatus(Request $request, DonorCar $donorCar): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in($donorCar->allowedStatusValues())],
            'drive_type' => ['nullable', Rule::in(array_keys(DonorCar::DRIVE_TYPES))],
        ]);

        if (! $donorCar->canTransitionTo($validated['status'])) {
            throw ValidationException::withMessages([
                'status' => '    " "      "".',
            ]);
        }

        $payload = [
            'status' => $validated['status'],
        ];
        if ($donorCar->status === DonorCar::STATUS_IN_TRANSIT
            && $validated['status'] === DonorCar::STATUS_DISMANTLING
            && ! $donorCar->drive_type
            && empty($validated['drive_type'])) {
            throw ValidationException::withMessages([
                'drive_type' => 'Выберите привод донора: задний или полный.',
            ]);
        }

        if (! empty($validated['drive_type'])) {
            $payload['drive_type'] = $validated['drive_type'];
        }
        $this->fillWarehouseArrivalDateOnArrival($payload, $donorCar);

        $donorCar->update($payload);

        return response()->json([
            'status' => $donorCar->status,
            'status_label' => $donorCar->status_label,
            'status_class' => $donorCar->status_class,
            'drive_type' => $donorCar->drive_type,
            'drive_type_label' => $donorCar->drive_type_label,
            'warehouse_arrival_date' => $donorCar->warehouse_arrival_date?->format('Y-m-d'),
            'warehouse_arrival_date_label' => $donorCar->warehouse_arrival_date?->format('d.m.Y'),
        ]);
    }

    public function updatePaintCode(Request $request, DonorCar $donorCar): JsonResponse
    {
        $validated = $request->validate([
            'paint_code' => ['nullable', 'string', 'max:50'],
        ]);

        $paintCode = trim((string) ($validated['paint_code'] ?? ''));
        $donorCar->update([
            'paint_code' => $paintCode !== '' ? $paintCode : null,
        ]);

        return response()->json([
            'paint_code' => $donorCar->paint_code,
        ]);
    }

    public function storePhotos(Request $request, DonorCar $donorCar): RedirectResponse
    {
        $validated = $request->validate([
            'photos' => ['required', 'array', 'max:'.DonorCar::PHOTO_LIMIT],
            'photos.*' => ['image', 'max:10240'],
        ]);

        $photos = $donorCar->photos ?? [];

        if (count($photos) + count($validated['photos']) > DonorCar::PHOTO_LIMIT) {
            return back()
                ->withErrors(['photos' => 'Можно добавить не больше '.DonorCar::PHOTO_LIMIT.' фотографий к одному донору.'])
                ->withInput();
        }

        foreach ($request->file('photos', []) as $photo) {
            $photos[] = $photo->store('donor-cars', 'public');
        }

        $donorCar->update([
            'photos' => $photos ?: null,
        ]);

        return back()->with('status', 'Фотографии добавлены.');
    }

    public function destroyPhoto(Request $request, DonorCar $donorCar): RedirectResponse
    {
        $validated = $request->validate([
            'photo' => ['required', 'string'],
        ]);
        $photo = trim($validated['photo']);
        $photos = collect((array) ($donorCar->photos ?? []))
            ->filter()
            ->unique()
            ->values();

        if (! $photos->contains($photo)) {
            throw ValidationException::withMessages([
                'photo' => "\u{0424}\u{043E}\u{0442}\u{043E} \u{043D}\u{0435} \u{043D}\u{0430}\u{0439}\u{0434}\u{0435}\u{043D}\u{043E}.",
            ]);
        }

        $remainingPhotos = $photos
            ->reject(fn (string $path): bool => $path === $photo)
            ->values();

        $donorCar->update([
            'photos' => $remainingPhotos->isNotEmpty() ? $remainingPhotos->all() : null,
        ]);

        if (! Str::startsWith($photo, ['http://', 'https://', '/'])) {
            Storage::disk('public')->delete($photo);
        }

        return redirect()
            ->route('admin.donor-cars.show', $donorCar)
            ->with('status', "\u{0424}\u{043E}\u{0442}\u{043E} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{043E}.");
    }

    public function destroy(DonorCar $donorCar): RedirectResponse
    {
        if (! $donorCar->canBeDeleted()) {
            return redirect()
                ->route('admin.donor-cars.index')
                ->with('status', html_entity_decode('&#1044;&#1086;&#1085;&#1086;&#1088;&#1072; &#1089;&#1090;&#1072;&#1088;&#1096;&#1077; 24 &#1095;&#1072;&#1089;&#1086;&#1074; &#1085;&#1077;&#1083;&#1100;&#1079;&#1103; &#1091;&#1076;&#1072;&#1083;&#1080;&#1090;&#1100;.'));
        }

        foreach ($donorCar->photos ?? [] as $photo) {
            Storage::disk('public')->delete($photo);
        }

        $donorCar->delete();

        return redirect()->route('admin.donor-cars.index')->with('status', 'Донорский автомобиль удален.');
    }

    protected function payload(DonorCarRequest $request, ?DonorCar $donorCar = null): array
    {
        $validated = $request->validated();
        $photos = $donorCar?->photos ?? [];
        $removePhotos = array_values(array_intersect($validated['remove_photos'] ?? [], $photos));

        if ($removePhotos) {
            $photos = array_values(array_diff($photos, $removePhotos));

            foreach ($removePhotos as $photo) {
                Storage::disk('public')->delete($photo);
            }
        }

        foreach ($request->file('photos', []) as $photo) {
            $photos[] = $photo->store('donor-cars', 'public');
        }

        unset($validated['photos'], $validated['remove_photos']);

        if ($donorCar) {
            foreach (DonorCar::DONOR_EXPENSE_FIELDS as $field) {
                if ($donorCar->isDonorExpenseFieldLocked($field)) {
                    unset($validated[$field]);
                }
            }
        }

        $payload = [
            ...$validated,
            'photos' => $photos ?: null,
        ];
        $this->fillWarehouseArrivalDateOnArrival($payload, $donorCar);

        return $payload;
    }

    protected function fillWarehouseArrivalDateOnArrival(array &$payload, ?DonorCar $donorCar): void
    {
        if (! $donorCar || $donorCar->status !== DonorCar::STATUS_IN_TRANSIT) {
            return;
        }

        $nextStatus = $payload['status'] ?? $donorCar->status;

        if (! in_array($nextStatus, DonorCar::ARRIVED_STATUSES, true)) {
            return;
        }

        if ($donorCar->warehouse_arrival_date !== null || ! empty($payload['warehouse_arrival_date'])) {
            return;
        }

        $payload['warehouse_arrival_date'] = today()->toDateString();
    }

    protected function formOptions(): array
    {
        return [
            'brands' => DonorCar::BRANDS,
            'statuses' => DonorCar::STATUSES,
            'models' => PartCatalogCategory::modelOptions(),
        ];
    }

    protected function donorProductDamageOptions(): array
    {
        return [
            '' => "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}",
            "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}" => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            "\u{041B}\u{0435}\u{0433}\u{043A}\u{0438}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}" => "\u{041B}\u{0435}\u{0433}\u{043A}\u{0438}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
            "\u{0421}\u{0438}\u{043B}\u{044C}\u{043D}\u{044B}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}" => "\u{0421}\u{0438}\u{043B}\u{044C}\u{043D}\u{044B}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
            NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS => NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS,
            NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS => NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS,
        ];

        return [
            '' => 'Неизвестно',
            'Без повреждений' => 'Без повреждений',
            'Легкие повреждения' => 'Легкие повреждения',
            'Сильные повреждения' => 'Сильные повреждения',
            'Разбит' => 'Разбит',
        ];
    }

    protected function mobilePartDamageOptions(): array
    {
        $options = $this->donorProductDamageOptions();
        unset($options['']);
        $options = [
            "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}" => "\u{0412}\u{044B}\u{0431}\u{0440}\u{0430}\u{0442}\u{044C} \u{0441}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441}",
        ] + $options;

        return $options;
    }

    protected function manualDonorProductDamageOptions(): array
    {
        $options = $this->donorProductDamageOptions();
        unset($options['']);

        return $options;
    }

    protected function isUnknownDonorDamageStatus(mixed $status): bool
    {
        $status = trim((string) $status);

        return $status === '' || $status === "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";
    }

    protected function activeWarehouses()
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->select(['id', 'name', 'floor_count'])
            ->orderBy('name')
            ->get();
    }

    protected function createDonorProduct(Request $request, DonorCar $donorCar): Product
    {
        $warehouse = Warehouse::query()->find($request->input('warehouse_id'));
        $floorRules = [$warehouse?->hasMultipleFloors() ? 'required' : 'nullable', 'string'];

        if ($warehouse) {
            $floorRules[] = Rule::in(array_keys($warehouse->availableFloors()));
        }

        $damageOptions = $this->manualDonorProductDamageOptions();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'damage_note' => ['required', 'string', Rule::in(array_keys($damageOptions))],
            'condition_type' => ['nullable', Rule::in(Product::CONDITION_TYPES)],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:255'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'max:10240'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'external_sku' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'floor' => $floorRules,
            'location_cell' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $sku = $this->uniquePartCode($donorCar);
        $category = $this->categoryFromCatalogSku($validated['external_sku'] ?? null, $donorCar);
        $conditionType = $validated['condition_type'] ?? 'used';

        $photos = [];
        foreach ($request->file('photos', []) as $photo) {
            $photos[] = $photo->store('product-photos', 'public');
        }

        return DB::transaction(function () use ($validated, $donorCar, $sku, $photos, $category, $conditionType): Product {
            $product = Product::query()->create([
                'sku' => $sku,
                'external_sku' => $validated['external_sku'] ?? null,
                'name' => $validated['name'],
                'slug' => $this->uniqueProductSlug($validated['name'], $sku),
                'category_id' => $category?->id,
                'donor_car_id' => $donorCar->id,
                'part_origin' => Product::PART_ORIGIN_ORIGINAL,
                'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
                'model' => $donorCar->model,
                'color' => $validated['color'] ?? null,
                'description' => $validated['description'] ?? null,
                'condition_type' => $conditionType,
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'purchase_price' => 0,
                'selling_price' => $validated['selling_price'] ?? 0,
                'currency' => 'USD',
                'barcode' => $sku,
                'qr_code' => $sku,
                'main_image' => $photos[0] ?? null,
                'images_json' => $photos ?: null,
                'notes' => $validated['damage_note'],
                'is_active' => true,
            ]);

            app(TeslaCatalogDonorProductSync::class)->syncProduct($product);

            $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
            $location = $this->resolveInitialLocation($warehouse, $validated['floor'] ?? null, $validated['location_cell'] ?? null);

            app(StockService::class)->intake([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'quantity' => 1,
                'comment' => 'Primary placement when adding a donor part.',
            ]);

            return $product;
        });
    }

    protected function nextPartCode(DonorCar $donorCar): string
    {
        return app(DonorProductSkuService::class)->nextManualCode($donorCar);
    }

    protected function uniquePartCode(DonorCar $donorCar, ?string $preferredCode = null): string
    {
        if ($preferredCode !== null && trim($preferredCode) !== '') {
            $sku = trim($preferredCode);

            if (! Product::query()->where('sku', $sku)->exists()) {
                return $sku;
            }
        }

        return app(DonorProductSkuService::class)->uniqueManualCode($donorCar);
    }

    protected function categoryFromCatalogSku(?string $externalSku, DonorCar $donorCar): ?Category
    {
        $externalSku = trim((string) $externalSku);

        if ($externalSku === '') {
            return null;
        }

        $catalogItems = PartCatalogItem::query()
            ->with('category.parent.parent.parent')
            ->where('part_number', $externalSku)
            ->get();

        if ($catalogItems->isEmpty()) {
            return null;
        }

        $donorModel = Str::lower(trim($donorCar->model));
        $catalogItem = $catalogItems->first(fn (PartCatalogItem $item): bool => collect([$item->model_label, $item->model_name])
            ->map(fn (?string $model): string => Str::lower(trim((string) $model)))
            ->contains($donorModel)) ?? $catalogItems->first();

        $categoryName = $this->catalogCategoryName($catalogItem);

        if ($categoryName === null) {
            return null;
        }

        $slug = Str::limit('tcars-'.(Str::slug($categoryName) ?: 'category'), 255, '');

        return Category::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => Str::limit($categoryName, 255, ''),
                'description' => null,
                'is_active' => true,
                'sort_order' => (int) (Category::query()->max('sort_order') ?? 0) + 1,
            ],
        );
    }

    protected function catalogCategoryName(PartCatalogItem $catalogItem): ?string
    {
        if ($catalogItem->category) {
            $trail = collect();
            $category = $catalogItem->category;

            while ($category && (int) $category->depth > 0) {
                $trail->prepend(trim(collect([$category->code, $category->name])->filter()->join(' - ')));
                $category = $category->parent;
            }

            $name = $trail->filter()->implode(' / ');

            if ($name !== '') {
                return $name;
            }
        }

        $name = collect([
            $catalogItem->main_category_name,
            $catalogItem->subcategory_name,
            $catalogItem->node_name,
        ])->filter()->implode(' / ');

        return $name !== '' ? $name : null;
    }

    protected function uniqueProductSlug(string $name, string $sku): string
    {
        $base = Str::slug($name) ?: Str::slug($sku);
        $slug = $base;
        $counter = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    protected function resolveInitialLocation(Warehouse $warehouse, ?string $floor, ?string $cell): Location
    {
        $floor = $warehouse->hasMultipleFloors() ? $floor : 'floor_1';
        $cell = trim((string) $cell) ?: null;

        $query = Location::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('floor', $floor);

        $cell === null ? $query->whereNull('cell') : $query->where('cell', $cell);

        if ($location = $query->first()) {
            return $location;
        }

        return Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => $floor,
            'cell' => $cell,
            'full_code' => $this->uniqueLocationCode($warehouse, $floor, $cell),
            'is_active' => true,
        ]);
    }

    protected function uniqueLocationCode(Warehouse $warehouse, string $floor, ?string $cell): string
    {
        $floorNumber = Str::after($floor, 'floor_') ?: '1';
        $cellCode = $cell ? Str::upper(Str::slug($cell) ?: 'CELL') : 'NO-CELL';
        $base = "WH{$warehouse->id}-F{$floorNumber}-{$cellCode}";
        $code = $base;
        $counter = 2;

        while (Location::query()->where('full_code', $code)->exists()) {
            $code = "{$base}-{$counter}";
            $counter++;
        }

        return $code;
    }

    protected function startOfficialDownloadProcess(DonorCar $donorCar, string $token): void
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $log = storage_path('logs/official-catalog-download-'.$donorCar->id.'.log');

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start /B "" '
                .escapeshellarg($php).' '
                .escapeshellarg($artisan).' donor-cars:download-official '
                .(int) $donorCar->id.' --token='.escapeshellarg($token)
                .' > '.escapeshellarg($log).' 2>&1';

            pclose(popen('cmd /c '.$command, 'r'));

            return;
        }

        $command = escapeshellarg($php).' '.escapeshellarg($artisan).' donor-cars:download-official '
            .(int) $donorCar->id.' --token='.escapeshellarg($token)
            .' > '.escapeshellarg($log).' 2>&1 &';

        exec($command);
    }
}
