<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\DeletedPartArchiveService;
use App\Services\NikolaCarsOfficialPartEnrichmentService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Services\PartCatalogDeduplicator;
use App\Services\PartCatalogDisplayService;
use App\Services\PartCatalogManualNameService;
use App\Services\StockService;
use App\Services\TeslaCatalogDonorProductSync;
use App\Support\ProductPhotoNormalizer;
use Illuminate\Database\Eloquent\Builder;
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

class ProductController extends Controller
{
    protected ?PartCatalogDisplayService $catalogDisplayService = null;

    public function index(Request $request): View
    {
        $source = $request->string('source')->toString();
        $search = trim($request->string('search')->toString());

        $products = Product::query()
            ->with(['category', 'donorCar', 'sourcePartCatalogItem'])
            ->withExists('purchaseItems as has_purchase_items')
            ->withSum('stockItems as stock_quantity', 'quantity')
            ->when($source === 'donor', fn (Builder $query) => $query->whereNotNull('donor_car_id'))
            ->when($source === 'purchase', fn (Builder $query) => $query->whereHas('purchaseItems'))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $driver = DB::connection()->getDriverName();
                $operator = $driver === 'pgsql' ? 'ilike' : 'like';
                $likeQuery = '%'.$search.'%';

                if ($driver === 'sqlite') {
                    $query->where(function (Builder $builder) use ($search): void {
                        $builder
                            ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])
                            ->orWhereRaw('lower(external_sku) like ?', ['%'.mb_strtolower($search).'%']);
                    });

                    return;
                }

                $query->where(function (Builder $builder) use ($operator, $likeQuery): void {
                    $builder
                        ->where('name', $operator, $likeQuery)
                        ->orWhere('external_sku', $operator, $likeQuery);
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $productSuggestions = Product::query()
            ->latest()
            ->limit(200)
            ->get(['name', 'external_sku'])
            ->flatMap(fn (Product $product) => [
                ['value' => $product->name, 'label' => 'Товар'],
                ['value' => $product->external_sku, 'label' => 'Артикул товара'],
            ]);

        $catalogSuggestions = PartCatalogItem::query()
            ->whereIn('source', ['tesla_official', 'tcarservice', 'teslapartsukraine', 'tsk', 'driveparts', 'dkparts'])
            ->latest()
            ->limit(300)
            ->get(['name', 'part_number'])
            ->flatMap(fn (PartCatalogItem $item) => [
                ['value' => $item->name, 'label' => 'Общий каталог'],
                ['value' => $item->part_number, 'label' => 'Артикул общего каталога'],
            ]);

        $searchSuggestions = $productSuggestions
            ->concat($catalogSuggestions)
            ->filter(fn (array $suggestion): bool => trim((string) $suggestion['value']) !== '')
            ->unique(fn (array $suggestion): string => mb_strtolower(trim((string) $suggestion['value'])))
            ->take(500)
            ->values();

        return view('admin.products.index', [
            'products' => $products,
            'searchSuggestions' => $searchSuggestions,
            'filters' => [
                'search' => $search,
                'source' => $source,
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.products.form', [
            'product' => new Product,
            ...$this->formOptions(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $productsQuery = Product::query()
            ->with(['category:id,name', 'brand:id,name'])
            ->orderBy('name')
            ->select([
                'id',
                'sku',
                'external_sku',
                'name',
                'slug',
                'category_id',
                'brand_id',
                'part_origin',
                'description',
                'model',
                'color',
                'generation',
                'side',
                'condition_type',
                'testing_status',
                'unit',
                'purchase_price',
                'selling_price',
                'currency',
                'weight',
            ]);

        $driver = DB::connection()->getDriverName();
        $likeQuery = '%'.$query.'%';

        $products = match ($driver) {
            'sqlite' => $productsQuery
                ->get()
                ->filter(fn (Product $product) => collect([$product->name, $product->sku, $product->external_sku])
                    ->filter()
                    ->contains(fn (string $value) => mb_stripos($value, $query) !== false))
                ->take(10)
                ->values(),
            'pgsql' => $productsQuery
                ->where(fn (Builder $builder) => $builder
                    ->where('name', 'ilike', $likeQuery)
                    ->orWhere('sku', 'ilike', $likeQuery)
                    ->orWhere('external_sku', 'ilike', $likeQuery))
                ->limit(10)
                ->get(),
            default => $productsQuery
                ->where(fn (Builder $builder) => $builder
                    ->where('name', 'like', $likeQuery)
                    ->orWhere('sku', 'like', $likeQuery)
                    ->orWhere('external_sku', 'like', $likeQuery))
                ->limit(10)
                ->get(),
        };

        $catalogQuery = PartCatalogItem::query()
            ->orderBy('name')
            ->select([
                'id',
                'part_number',
                'name',
                'source_url',
                'model_label',
                'model_name',
                'year_from',
                'year_to',
                'main_category_name',
                'subcategory_name',
                'node_name',
                'compatibility_text',
                'condition',
                'quality',
                'availability',
            ]);

        $catalogItems = match ($driver) {
            'sqlite' => $catalogQuery
                ->get()
                ->filter(fn (PartCatalogItem $item) => collect([$item->name, $item->part_number, $item->model_label, $item->main_category_name, $item->subcategory_name, $item->node_name])
                    ->filter()
                    ->contains(fn (string $value) => mb_stripos($value, $query) !== false))
                ->pipe(fn ($items) => app(PartCatalogDeduplicator::class)->deduplicate($items))
                ->take(10)
                ->values(),
            'pgsql' => $catalogQuery
                ->where(fn (Builder $builder) => $builder
                    ->where('name', 'ilike', $likeQuery)
                    ->orWhere('part_number', 'ilike', $likeQuery)
                    ->orWhere('model_label', 'ilike', $likeQuery)
                    ->orWhere('main_category_name', 'ilike', $likeQuery)
                    ->orWhere('subcategory_name', 'ilike', $likeQuery)
                    ->orWhere('node_name', 'ilike', $likeQuery))
                ->limit(50)
                ->get()
                ->pipe(fn ($items) => app(PartCatalogDeduplicator::class)->deduplicate($items))
                ->take(10)
                ->values(),
            default => $catalogQuery
                ->where(fn (Builder $builder) => $builder
                    ->where('name', 'like', $likeQuery)
                    ->orWhere('part_number', 'like', $likeQuery)
                    ->orWhere('model_label', 'like', $likeQuery)
                    ->orWhere('main_category_name', 'like', $likeQuery)
                    ->orWhere('subcategory_name', 'like', $likeQuery)
                    ->orWhere('node_name', 'like', $likeQuery))
                ->limit(50)
                ->get()
                ->pipe(fn ($items) => app(PartCatalogDeduplicator::class)->deduplicate($items))
                ->take(10)
                ->values(),
        };

        $productSuggestions = $products->map(fn (Product $product) => [
            'type' => 'product',
            'id' => $product->id,
            'sku' => $product->sku,
            'external_sku' => $product->external_sku,
            'name' => $product->name,
            'slug' => $product->slug,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name,
            'brand_id' => $product->brand_id,
            'brand_name' => $product->brand?->name,
            'part_origin' => $product->part_origin,
            'description' => $product->description,
            'model' => $product->model,
            'color' => $product->color,
            'generation' => $product->generation,
            'side' => $product->side,
            'condition_type' => $product->condition_type,
            'testing_status' => $product->testing_status,
            'unit' => $product->unit,
            'purchase_price' => $product->purchase_price,
            'selling_price' => $product->selling_price,
            'currency' => $product->currency,
            'weight' => $product->weight,
        ]);

        $teslaBrand = Brand::query()->where('slug', 'tesla')->first(['id', 'name']);

        $catalogSuggestions = $catalogItems->map(function (PartCatalogItem $item) use ($teslaBrand): array {
            $slugBase = $item->part_number ?: $item->name;
            $categoryPath = collect([$item->main_category_name, $item->subcategory_name, $item->node_name])
                ->filter()
                ->implode(' / ');

            return [
                'type' => 'catalog',
                'id' => $item->id,
                'sku' => null,
                'external_sku' => $item->part_number,
                'name' => $item->name,
                'slug' => Str::slug($slugBase) ?: Str::slug('part-'.$item->id),
                'category_id' => null,
                'category_name' => $categoryPath ?: $item->main_category_name,
                'brand_id' => $teslaBrand?->id,
                'brand_name' => $teslaBrand?->name ?? 'Tesla',
                'part_origin' => Product::PART_ORIGIN_ORIGINAL,
                'model' => $item->model_label ?: $item->model_name,
                'color' => null,
                'generation' => $item->model_label,
                'side' => null,
                'condition_type' => $item->condition === 'нова' ? 'new' : null,
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'purchase_price' => null,
                'selling_price' => null,
                'currency' => 'USD',
                'weight' => null,
                'compatibility' => $item->compatibility_text,
                'description' => collect([
                    $categoryPath ? 'Категория TCARS: '.$categoryPath : null,
                    $item->quality ? 'Качество: '.$item->quality : null,
                    $item->availability ? 'Наличие: '.$item->availability : null,
                    'Источник: '.$item->source_url,
                ])->filter()->implode(PHP_EOL),
            ];
        });

        return response()->json($productSuggestions->concat($catalogSuggestions)->take(15)->values());
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        Product::query()->create($this->payload($request));

        return redirect()->route('admin.products.index')->with('status', 'Товар создан.');
    }

    public function show(Product $product): View
    {
        $product->load([
            'category',
            'brand',
            'donorCar',
            'sourcePartCatalogItem.category',
            'stockItems.warehouse',
            'stockItems.location',
            'movements.fromLocation',
            'movements.toLocation',
            'movements.counterparty',
            'purchaseItems.purchase.counterparty',
            'purchaseItems.warehouse',
            'purchaseItems.location',
            'stoWorkOrderParts.order',
        ]);

        $partNumber = trim((string) ($product->external_sku ?: $product->sourcePartCatalogItem?->part_number));
        $sameDonorProducts = collect();

        if ($partNumber !== '') {
            $sameDonorProducts = Product::query()
                ->with(['donorCar', 'sourcePartCatalogItem'])
                ->whereKeyNot($product->id)
                ->where(function (Builder $query) use ($partNumber): void {
                    $query
                        ->where('external_sku', $partNumber)
                        ->orWhereHas('sourcePartCatalogItem', fn (Builder $builder) => $builder->where('part_number', $partNumber));
                })
                ->whereNotNull('donor_car_id')
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('notes')
                        ->orWhereNotIn('notes', [
                            \App\Services\NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS,
                            \App\Services\NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS,
                        ]);
                })
                ->orderBy('donor_car_id')
                ->orderBy('id')
                ->get();
        }

        $nikolaCarsOfficialEnrichment = $partNumber !== ''
            ? app(NikolaCarsOfficialPartEnrichmentService::class)->enrich($partNumber)
            : null;
        $nikolaCarsOfficialEnrichment = $nikolaCarsOfficialEnrichment?->matched()
            ? $nikolaCarsOfficialEnrichment
            : null;
        $catalogItem = $this->catalogItemForProductNames(
            $product,
            $nikolaCarsOfficialEnrichment?->officialItem,
            $partNumber
        );
        $productHeading = trim((string) $product->name);
        $placementWarehouses = $this->activeProductPlacementWarehouses($product);
        $placementLocations = $this->activeProductPlacementLocations();

        return view('admin.products.show', [
            'product' => $product,
            'productHeading' => $productHeading !== '' ? $productHeading : $product->name,
            'sameDonorProducts' => $sameDonorProducts,
            'nikolaCarsOfficialEnrichment' => $nikolaCarsOfficialEnrichment,
            'catalogItem' => $catalogItem,
            'officialCatalogItem' => $nikolaCarsOfficialEnrichment?->officialItem,
            'catalogNameSources' => $catalogItem
                ? $this->localizedNameSources($catalogItem)
                : [
                    'ru' => ['site' => null, 'url' => null],
                    'ua' => ['site' => null, 'url' => null],
                ],
            'productPlacementWarehouseOptions' => $this->productPlacementWarehouseOptions($placementWarehouses),
            'productPlacementLocationOptions' => $this->productPlacementLocationOptions($placementLocations),
        ]);
    }

    public function updatePlacement(Request $request, Product $product): RedirectResponse
    {
        if ($this->isSoldProduct($product)) {
            throw ValidationException::withMessages([
                'warehouse_id' => "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}\u{043D}\u{0443}\u{044E} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{043F}\u{0435}\u{0440}\u{0435}\u{043C}\u{0435}\u{0449}\u{0430}\u{0442}\u{044C}.",
            ]);
        }

        $product->loadMissing(['donorCar', 'stockItems.location', 'stockItems.warehouse', 'sourcePartCatalogItem']);
        $damageNote = trim((string) $product->notes);

        if ($product->donorCar && in_array($damageNote, ['', "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}"], true)) {
            throw ValidationException::withMessages([
                'warehouse_id' => "\u{0421}\u{043D}\u{0430}\u{0447}\u{0430}\u{043B}\u{0430} \u{0443}\u{043A}\u{0430}\u{0436}\u{0438}\u{0442}\u{0435} \u{0441}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}.",
            ]);
        }

        $stockItems = $product->stockItems
            ->filter(fn ($stockItem): bool => (int) $stockItem->quantity > 0)
            ->values();

        if ($stockItems->isEmpty()) {
            throw ValidationException::withMessages([
                'warehouse_id' => "\u{0423} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{043D}\u{0435}\u{0442} \u{0430}\u{043A}\u{0442}\u{0438}\u{0432}\u{043D}\u{043E}\u{0433}\u{043E} \u{043E}\u{0441}\u{0442}\u{0430}\u{0442}\u{043A}\u{0430} \u{0434}\u{043B}\u{044F} \u{043F}\u{0435}\u{0440}\u{0435}\u{043C}\u{0435}\u{0449}\u{0435}\u{043D}\u{0438}\u{044F}.",
            ]);
        }

        if ($stockItems->sum(fn ($stockItem): int => (int) $stockItem->reserved_quantity) > 0) {
            throw ValidationException::withMessages([
                'warehouse_id' => "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{0432} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432}\u{0435}. \u{0421}\u{043D}\u{0430}\u{0447}\u{0430}\u{043B}\u{0430} \u{0441}\u{043D}\u{0438}\u{043C}\u{0438}\u{0442}\u{0435} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432} \u{0438}\u{043B}\u{0438} \u{0437}\u{0430}\u{0432}\u{0435}\u{0440}\u{0448}\u{0438}\u{0442}\u{0435} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}.",
            ]);
        }

        $location = $this->resolveProductPlacementLocation($request, $product);

        $this->placeProductInWarehouse($product, $location);
        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());

        return redirect()
            ->route('admin.products.show', $product)
            ->with('status', "\u{0421}\u{043A}\u{043B}\u{0430}\u{0434} \u{0434}\u{043B}\u{044F} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}.");
    }

    public function updateCatalogName(Request $request, Product $product): RedirectResponse
    {
        abort_if($this->isSoldProduct($product), 422, 'Проданную запчасть нельзя изменять.');

        $validated = $request->validate([
            'name_type' => ['required', Rule::in(['name_ru', 'name_ua'])],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $product->load('sourcePartCatalogItem');
        $partNumber = trim((string) ($product->external_sku ?: $product->sourcePartCatalogItem?->part_number));
        $nikolaCarsOfficialEnrichment = $partNumber !== ''
            ? app(NikolaCarsOfficialPartEnrichmentService::class)->enrich($partNumber)
            : null;
        $catalogItem = $this->catalogItemForProductNames(
            $product,
            $nikolaCarsOfficialEnrichment?->matched() ? $nikolaCarsOfficialEnrichment->officialItem : null,
            $partNumber
        );

        if (! $catalogItem) {
            throw ValidationException::withMessages([
                'name_type' => 'У этой запчасти нет связанной позиции каталога для RU/UA названия.',
            ]);
        }

        app(PartCatalogManualNameService::class)->lockAndPropagate($catalogItem, [
            $validated['name_type'] => trim((string) $validated['name']),
        ]);

        return redirect()
            ->route('admin.products.show', $product)
            ->with('status', 'Название из каталога обновлено вручную.');
    }

    protected function catalogItemForProductNames(Product $product, ?PartCatalogItem $officialItem, string $partNumber): ?PartCatalogItem
    {
        $sourceItem = $product->sourcePartCatalogItem;
        $normalizedPartNumber = $this->normalizedCatalogPartNumber($partNumber);

        if ($sourceItem instanceof PartCatalogItem) {
            $sourcePartNumber = $this->normalizedCatalogPartNumber($sourceItem->part_number);

            if ($normalizedPartNumber === '' || $sourcePartNumber === '' || $sourcePartNumber === $normalizedPartNumber) {
                if ($sourceItem->source === 'tesla_official' && $officialItem instanceof PartCatalogItem) {
                    return $officialItem;
                }

                if ($sourceItem->source !== 'nikolacars' && $officialItem instanceof PartCatalogItem) {
                    return $officialItem;
                }

                return $sourceItem;
            }
        }

        return $officialItem ?: $sourceItem;
    }

    protected function normalizedCatalogPartNumber(?string $partNumber): string
    {
        return Str::upper(trim((string) $partNumber));
    }

    protected function catalogDisplay(): PartCatalogDisplayService
    {
        return $this->catalogDisplayService ??= app(PartCatalogDisplayService::class);
    }

    protected function localizedNameSources(PartCatalogItem $item): array
    {
        return [
            'ru' => $this->localizedNameSource($item, 'ru'),
            'ua' => $this->localizedNameSource($item, 'ua'),
        ];
    }

    protected function localizedNameSource(PartCatalogItem $item, string $locale): array
    {
        $localizedName = $locale === 'ru' ? $item->name_ru : $item->name_ua;

        if (! filled($localizedName) || app(PartCatalogManualNameService::class)->isLocked($item, $locale === 'ru' ? 'name_ru' : 'name_ua')) {
            return [
                'site' => null,
                'url' => null,
            ];
        }

        $nikolaCarsUrl = $this->nikolaCarsNomenclatureNameSourceUrl($item, $locale);

        if ($nikolaCarsUrl !== null) {
            return [
                'site' => 'nikolacars',
                'url' => $nikolaCarsUrl,
            ];
        }

        $url = data_get($item->raw_attributes, 'name_source_url_'.$locale)
            ?: ($locale === 'ru' ? data_get($item->raw_attributes, 'name_source_url') : null);
        $site = data_get($item->raw_attributes, 'name_source_site_'.$locale)
            ?: ($locale === 'ru' ? data_get($item->raw_attributes, 'name_source_site') : null);
        $competitorUrl = $this->competitorLocalizedNameUrl($item, $locale);
        $referencedUrl = $this->localizedNameSourceUrlFromItemReference($item, $locale);

        if ($competitorUrl !== null) {
            $url = $competitorUrl;
            $site = $this->siteFromUrl($competitorUrl) ?: $site;
        }

        if ($referencedUrl !== null) {
            $url = $referencedUrl;
            $site = $this->siteFromUrl($referencedUrl) ?: $site;
        }

        if (! is_string($url) || ! Str::startsWith($url, ['http://', 'https://'])) {
            $url = $this->localizedNameSourceUrlFromItemReference($item, $locale);
        }

        if (! is_string($site) || $site === '') {
            $site = is_string($url) && $url !== ''
                ? $this->siteFromUrl($url)
                : '';
        }

        if ((! is_string($site) || $site === '') && filled($item->part_number)) {
            $sourceItem = $this->matchingLocalizedNameSourceItem($item, $locale, (string) $localizedName);

            if ($sourceItem instanceof PartCatalogItem) {
                $url = $this->displayableSourceUrl($sourceItem, $locale);
                $site = is_string($url) && $url !== ''
                    ? $this->siteFromUrl($url)
                    : $sourceItem->source;
            }
        }

        return [
            'site' => is_string($site) && $site !== '' ? $site : null,
            'url' => is_string($url) && $url !== '' ? $url : null,
        ];
    }

    protected function nikolaCarsNomenclatureNameSourceUrl(PartCatalogItem $item, string $locale): ?string
    {
        if ($item->source !== 'nikolacars') {
            return null;
        }

        if (trim((string) data_get($item->raw_attributes, 'code')) === '') {
            return null;
        }

        $url = data_get($item->raw_attributes, 'nikolacars_source_url_'.$locale)
            ?: data_get($item->raw_attributes, 'prom.url')
            ?: data_get($item->raw_attributes, 'product_url');

        return is_string($url) && Str::startsWith($url, ['http://', 'https://'])
            ? $url
            : null;
    }

    protected function matchingLocalizedNameSourceItem(PartCatalogItem $item, string $locale, string $localizedName): ?PartCatalogItem
    {
        return $this->catalogDisplay()->matchingLocalizedNameSourceItem($item, $locale, $localizedName);
    }

    protected function localizedNameSourceUrlFromItemReference(PartCatalogItem $item, string $locale): ?string
    {
        return $this->catalogDisplay()->inventoryLocalizedNameSourceUrlFromItemReference($item, $locale);
    }

    protected function displayableSourceUrl(PartCatalogItem $item, ?string $locale = null): ?string
    {
        return $this->catalogDisplay()->inventoryDisplayableSourceUrl($item, $locale);
    }

    protected function competitorLocalizedNameUrl(PartCatalogItem $item, string $locale): ?string
    {
        return $this->catalogDisplay()->inventoryCompetitorLocalizedNameUrl($item, $locale);
    }

    protected function withPathLocale(string $url, string $locale): string
    {
        return $this->catalogDisplay()->withPathLocale($url, $locale);
    }

    protected function withoutPathLocale(string $url, string $locale): string
    {
        return $this->catalogDisplay()->withoutPathLocale($url, $locale);
    }

    protected function siteFromUrl(string $url): ?string
    {
        return $this->catalogDisplay()->siteFromUrl($url);
    }

    public function storePhotos(Request $request, Product $product): RedirectResponse
    {
        abort_if($this->isSoldProduct($product), 422, 'Проданную запчасть нельзя изменять.');

        $request->validate([
            'photos' => ['required', 'array', 'max:20'],
            'photos.*' => ['image', 'max:10240'],
        ]);

        $photos = $this->productPhotoPaths($product);

        foreach ($request->file('photos', []) as $photo) {
            $photos->push($photo->store('product-photos', 'public'));
        }

        $photos = $photos->unique()->values();

        $product->forceFill(ProductPhotoNormalizer::persistencePayload($photos))->save();

        return redirect()
            ->route('admin.products.show', $product)
            ->with('status', 'Фото товара добавлены.');
    }

    public function destroyPhoto(Request $request, Product $product): RedirectResponse
    {
        abort_if($this->isSoldProduct($product), 422, 'Проданную запчасть нельзя изменять.');

        $validated = $request->validate([
            'photo' => ['required', 'string'],
        ]);
        $photo = trim($validated['photo']);

        if ($this->isProtectedProductPhoto($photo)) {
            throw ValidationException::withMessages([
                'photo' => "\u{0424}\u{043E}\u{0442}\u{043E} \u{0441} tesla.com \u{0443}\u{0434}\u{0430}\u{043B}\u{044F}\u{0442}\u{044C} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F}.",
            ]);
        }

        $currentPhotos = $this->productPhotoPaths($product);

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
            ->route('admin.products.show', $product)
            ->with('status', "\u{0424}\u{043E}\u{0442}\u{043E} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{043E}.");
    }

    public function updatePhotoOrder(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        abort_if($this->isSoldProduct($product), 422, 'Проданную запчасть нельзя изменять.');

        $validated = $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'string', 'distinct'],
        ]);
        $submittedPhotos = collect($validated['photos'])
            ->map(fn (string $path): string => trim($path))
            ->filter()
            ->values();
        $currentPhotos = $this->productPhotoPaths($product);

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
            ->route('admin.products.show', $product)
            ->with('status', "\u{041F}\u{043E}\u{0440}\u{044F}\u{0434}\u{043E}\u{043A} \u{0444}\u{043E}\u{0442}\u{043E} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}.");
    }

    public function rotatePhoto(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        abort_if($this->isSoldProduct($product), 422, 'Проданную запчасть нельзя изменять.');

        $validated = $request->validate([
            'photo' => ['required', 'string'],
            'degrees' => ['nullable', 'integer', Rule::in([90, 180, 270])],
        ]);
        $photo = trim($validated['photo']);
        $photoPath = $this->normalizedPublicPhotoPath($photo);
        $degrees = (int) ($validated['degrees'] ?? 90);

        if ($photoPath === null || $this->isProtectedProductPhoto($photoPath)) {
            throw ValidationException::withMessages([
                'photo' => "\u{042D}\u{0442}\u{043E} \u{0444}\u{043E}\u{0442}\u{043E} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{043F}\u{043E}\u{0432}\u{0435}\u{0440}\u{043D}\u{0443}\u{0442}\u{044C} \u{0438}\u{0437} \u{043A}\u{0430}\u{0440}\u{0442}\u{043E}\u{0447}\u{043A}\u{0438}.",
            ]);
        }

        $currentPhotos = $this->productPhotoPaths($product);

        if (! $currentPhotos->contains($photoPath)) {
            throw ValidationException::withMessages([
                'photo' => "\u{0424}\u{043E}\u{0442}\u{043E} \u{043D}\u{0435} \u{043D}\u{0430}\u{0439}\u{0434}\u{0435}\u{043D}\u{043E}.",
            ]);
        }

        $this->rotateStoredProductPhoto($photoPath, $degrees);

        $updatedAt = Storage::disk('public')->lastModified($photoPath);
        $url = Storage::disk('public')->url($photoPath).'?v='.$updatedAt;

        if ($request->expectsJson()) {
            return response()->json([
                'photo' => $photoPath,
                'url' => $url,
            ]);
        }

        return redirect()
            ->route('admin.products.show', $product)
            ->with('status', "\u{0424}\u{043E}\u{0442}\u{043E} \u{043F}\u{043E}\u{0432}\u{0435}\u{0440}\u{043D}\u{0443}\u{0442}\u{043E}.");
    }    public function edit(Product $product): View
    {
        abort_if($this->isSoldProduct($product), 422, 'Проданную запчасть нельзя изменять.');

        return view('admin.products.form', [
            'product' => $product,
            ...$this->formOptions(),
        ]);
    }

    public function update(
        ProductRequest $request,
        Product $product,
        NikolaCarsProductInventorySyncService $nikolaCarsProductInventorySync,
        TeslaCatalogDonorProductSync $teslaCatalogDonorProductSync,
    ): RedirectResponse {
        abort_if($this->isSoldProduct($product), 422, 'Проданную запчасть нельзя изменять.');

        $oldExternalSku = trim((string) $product->external_sku);
        $previousDamageNote = $product->notes;
        $payload = $this->payload($request, $product);

        if ((int) ($payload['donor_car_id'] ?? $product->donor_car_id) > 0 && array_key_exists('notes', $payload)) {
            $payload['donor_damage_status_changed_by'] = $nikolaCarsProductInventorySync->damageStatusChangedByForTransition(
                $previousDamageNote,
                $payload['notes'],
                $request->user()?->id,
                $product->donor_damage_status_changed_by
            );
        }

        $product->update($payload);
        $product->refresh();

        if (
            $product->donor_car_id !== null
            && trim((string) $product->external_sku) !== ''
            && trim((string) $product->external_sku) !== $oldExternalSku
        ) {
            $teslaCatalogDonorProductSync->syncProduct($product);
            $product->refresh();
        }

        $syncResult = $nikolaCarsProductInventorySync->syncProduct($product);
        $nikolaCarsProductInventorySync->markDonorDamageCheckedAt(
            $product->refresh(),
            $syncResult['item'] ?? null,
            $previousDamageNote,
            $payload['notes'] ?? $product->notes
        );
        $nikolaCarsProductInventorySync->syncDonorDamageStatusChanger(
            $product->refresh(),
            $syncResult['item'] ?? null,
            $previousDamageNote,
            $payload['notes'] ?? $product->notes,
            $product->donor_damage_status_changed_by
        );

        return redirect()->route('admin.products.show', $product)->with('status', 'Товар обновлен.');
    }

    public function destroy(Product $product, DeletedPartArchiveService $archive): RedirectResponse
    {
        $product->loadMissing('sourcePartCatalogItem');

        if ($product->isTeslaOfficialGenerated()) {
            throw ValidationException::withMessages([
                'product' => "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{0438}\u{0437} tesla.com \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0443}\u{0434}\u{0430}\u{043B}\u{044F}\u{0442}\u{044C}.",
            ]);
        }

        DB::transaction(function () use ($product, $archive): void {
            $archive->archiveProduct($product);

            PartCatalogItem::query()
                ->where('source', 'nikolacars')
                ->whereIn('source_url', [
                    'nikolacars://donor-product/'.$product->id,
                    'nikolacars://inventory-product/'.$product->id,
                ])
                ->delete();

            if ($product->sourcePartCatalogItem?->source === 'nikolacars') {
                $product->sourcePartCatalogItem->delete();
            }

            $product->delete();
        });

        return redirect()->route('admin.products.index')->with('status', 'Товар удален.');
    }

    protected function payload(ProductRequest $request, ?Product $product = null): array
    {
        $validated = $request->validated();
        $sku = $product?->sku ?? $this->nextSku();

        return [
            ...$validated,
            'sku' => $sku,
            'slug' => $validated['slug'] ?: Str::slug($validated['name']),
            'barcode' => ($validated['barcode'] ?? null) ?: $sku,
            'qr_code' => ($validated['qr_code'] ?? null) ?: $sku,
            'storage_status' => $validated['storage_status'] ?? $product?->storage_status ?? Product::STORAGE_STATUS_IN_STOCK,
            'part_origin' => ($validated['part_origin'] ?? null)
                ?: (($validated['donor_car_id'] ?? null)
                    ? Product::PART_ORIGIN_ORIGINAL
                    : $product?->part_origin),
            'is_auto_generated' => $request->has('is_auto_generated') ? $request->boolean('is_auto_generated') : (bool) $product?->is_auto_generated,
            'images_json' => ($validated['images_json'] ?? null)
                ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $validated['images_json']))))
                : null,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    protected function normalizedPublicPhotoPath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $urlPath = parse_url($path, PHP_URL_PATH);

        if (is_string($urlPath) && $urlPath !== '') {
            $path = rawurldecode($urlPath);
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#^.*?/public/storage/#', '', $path) ?? $path;
        $path = preg_replace('#^.*?/storage/app/public/#', '', $path) ?? $path;
        $path = ltrim($path, '/');

        foreach (['storage/', 'public/storage/'] as $prefix) {
            if (Str::startsWith($path, $prefix)) {
                $path = Str::after($path, $prefix);
            }
        }

        return $path !== '' ? $path : null;
    }
    protected function rotateStoredProductPhoto(string $path, int $degrees): void
    {
        $disk = Storage::disk('public');

        if (! function_exists('imagerotate')) {
            throw ValidationException::withMessages([
                'photo' => "\u{041D}\u{0430} \u{0441}\u{0435}\u{0440}\u{0432}\u{0435}\u{0440}\u{0435} \u{043D}\u{0435} \u{0432}\u{043A}\u{043B}\u{044E}\u{0447}\u{0435}\u{043D}\u{0430} \u{043E}\u{0431}\u{0440}\u{0430}\u{0431}\u{043E}\u{0442}\u{043A}\u{0430} \u{0438}\u{0437}\u{043E}\u{0431}\u{0440}\u{0430}\u{0436}\u{0435}\u{043D}\u{0438}\u{0439}.",
            ]);
        }

        if (! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'photo' => "\u{0424}\u{0430}\u{0439}\u{043B} \u{0444}\u{043E}\u{0442}\u{043E} \u{043D}\u{0435} \u{043D}\u{0430}\u{0439}\u{0434}\u{0435}\u{043D} \u{0432} \u{0445}\u{0440}\u{0430}\u{043D}\u{0438}\u{043B}\u{0438}\u{0449}\u{0435}.",
            ]);
        }

        $fullPath = $disk->path($path);
        $imageType = @exif_imagetype($fullPath);

        if (! in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([
                'photo' => "\u{042D}\u{0442}\u{043E}\u{0442} \u{0444}\u{043E}\u{0440}\u{043C}\u{0430}\u{0442} \u{0444}\u{043E}\u{0442}\u{043E} \u{043D}\u{0435} \u{043F}\u{043E}\u{0434}\u{0434}\u{0435}\u{0440}\u{0436}\u{0438}\u{0432}\u{0430}\u{0435}\u{0442} \u{043F}\u{043E}\u{0432}\u{043E}\u{0440}\u{043E}\u{0442}.",
            ]);
        }

        $source = match ($imageType) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($fullPath) : false,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($fullPath) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : false,
            default => false,
        };

        if (! $source) {
            throw ValidationException::withMessages([
                'photo' => "\u{0424}\u{043E}\u{0442}\u{043E} \u{043D}\u{0435} \u{0443}\u{0434}\u{0430}\u{043B}\u{043E}\u{0441}\u{044C} \u{043E}\u{0442}\u{043A}\u{0440}\u{044B}\u{0442}\u{044C} \u{0434}\u{043B}\u{044F} \u{043F}\u{043E}\u{0432}\u{043E}\u{0440}\u{043E}\u{0442}\u{0430}.",
            ]);
        }

        $background = $imageType === IMAGETYPE_JPEG
            ? imagecolorallocate($source, 255, 255, 255)
            : imagecolorallocatealpha($source, 0, 0, 0, 127);
        $rotated = imagerotate($source, -$degrees, $background);
        imagedestroy($source);

        if (! $rotated) {
            throw ValidationException::withMessages([
                'photo' => "\u{0424}\u{043E}\u{0442}\u{043E} \u{043D}\u{0435} \u{0443}\u{0434}\u{0430}\u{043B}\u{043E}\u{0441}\u{044C} \u{043F}\u{043E}\u{0432}\u{0435}\u{0440}\u{043D}\u{0443}\u{0442}\u{044C}.",
            ]);
        }

        if ($imageType !== IMAGETYPE_JPEG) {
            imagealphablending($rotated, false);
            imagesavealpha($rotated, true);
        }

        $saved = match ($imageType) {
            IMAGETYPE_JPEG => imagejpeg($rotated, $fullPath, 90),
            IMAGETYPE_PNG => imagepng($rotated, $fullPath),
            IMAGETYPE_WEBP => function_exists('imagewebp') && imagewebp($rotated, $fullPath, 90),
            default => false,
        };
        imagedestroy($rotated);

        if (! $saved) {
            throw ValidationException::withMessages([
                'photo' => "\u{0424}\u{043E}\u{0442}\u{043E} \u{043D}\u{0435} \u{0443}\u{0434}\u{0430}\u{043B}\u{043E}\u{0441}\u{044C} \u{0441}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0438}\u{0442}\u{044C}.",
            ]);
        }
    }

    protected function resolveProductPlacementLocation(Request $request, Product $product): Location
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'floor' => ['nullable', 'string'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $warehouse = Warehouse::query()
            ->whereKey($validated['warehouse_id'])
            ->where('is_active', true)
            ->first();

        if (! $warehouse instanceof Warehouse) {
            throw ValidationException::withMessages([
                'warehouse_id' => "\u{0412}\u{044B}\u{0431}\u{0435}\u{0440}\u{0438}\u{0442}\u{0435} \u{0441}\u{043A}\u{043B}\u{0430}\u{0434}.",
            ]);
        }

        if ($warehouse->type === Warehouse::TYPE_DONOR) {
            if (! $product->donorCar instanceof DonorCar) {
                throw ValidationException::withMessages([
                    'warehouse_id' => "\u{0421}\u{043A}\u{043B}\u{0430}\u{0434} \u{0434}\u{043E}\u{043D}\u{043E}\u{0440}\u{0430} \u{0434}\u{043E}\u{0441}\u{0442}\u{0443}\u{043F}\u{0435}\u{043D} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{0434}\u{043B}\u{044F} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0435}\u{0439}, \u{0441}\u{0432}\u{044F}\u{0437}\u{0430}\u{043D}\u{043D}\u{044B}\u{0445} \u{0441} \u{0434}\u{043E}\u{043D}\u{043E}\u{0440}\u{043E}\u{043C}.",
                ]);
            }

            return $this->donorStockLocation($product->donorCar);
        }

        $floor = $validated['floor'] ?? null;

        if ($warehouse->hasMultipleFloors()) {
            if (! is_string($floor) || ! array_key_exists($floor, $warehouse->availableFloors())) {
                throw ValidationException::withMessages([
                    'floor' => "\u{0412}\u{044B}\u{0431}\u{0435}\u{0440}\u{0438}\u{0442}\u{0435} \u{044D}\u{0442}\u{0430}\u{0436}.",
                ]);
            }
        } else {
            $floor = 'floor_1';
        }

        $floorForLocation = is_string($floor) && $floor !== '' ? $floor : 'floor_1';
        $warehouseLocations = $this->activePlacementLocationsForWarehouseFloor($warehouse, $floorForLocation);
        $hasCellLocations = $warehouse->usesStructuredLocations()
            && $warehouseLocations->contains(fn (Location $location): bool => trim((string) $location->cell) !== '');

        if (! $hasCellLocations) {
            return $this->noCellPlacementLocation($warehouse, $floorForLocation);
        }

        $location = Location::query()
            ->whereKey($validated['location_id'] ?? null)
            ->where('warehouse_id', $warehouse->id)
            ->where('is_active', true)
            ->first();

        if (! $location instanceof Location) {
            throw ValidationException::withMessages([
                'location_id' => "\u{0412}\u{044B}\u{0431}\u{0435}\u{0440}\u{0438}\u{0442}\u{0435} \u{044F}\u{0447}\u{0435}\u{0439}\u{043A}\u{0443}.",
            ]);
        }

        if ($warehouse->hasMultipleFloors() && $this->normalizedLocationFloor($location) !== $floorForLocation) {
            throw ValidationException::withMessages([
                'location_id' => "\u{042F}\u{0447}\u{0435}\u{0439}\u{043A}\u{0430} \u{043D}\u{0435} \u{043E}\u{0442}\u{043D}\u{043E}\u{0441}\u{0438}\u{0442}\u{0441}\u{044F} \u{043A} \u{0432}\u{044B}\u{0431}\u{0440}\u{0430}\u{043D}\u{043D}\u{043E}\u{043C}\u{0443} \u{044D}\u{0442}\u{0430}\u{0436}\u{0443}.",
            ]);
        }

        return $location;
    }

    protected function placeProductInWarehouse(Product $product, Location $location): void
    {
        DB::transaction(function () use ($product, $location): void {
            $location->loadMissing('warehouse');

            $product->forceFill([
                'storage_status' => $location->warehouse?->type === Warehouse::TYPE_DONOR
                    ? Product::STORAGE_STATUS_ON_DONOR
                    : Product::STORAGE_STATUS_IN_STOCK,
            ])->save();

            $product->load('stockItems.location');
            $stockItems = $product->stockItems
                ->filter(fn ($stockItem): bool => (int) $stockItem->quantity > 0)
                ->sortByDesc(fn ($stockItem): int => (int) $stockItem->available_quantity)
                ->values();

            if ($stockItems->isEmpty()) {
                app(StockService::class)->intake([
                    'product_id' => $product->id,
                    'warehouse_id' => $location->warehouse_id,
                    'location_id' => $location->id,
                    'quantity' => 1,
                    'comment' => 'Placement update from /admin/products.',
                ]);

                return;
            }

            foreach ($stockItems as $stockItem) {
                $quantity = (int) $stockItem->available_quantity;

                if ($quantity < 1 || (int) $stockItem->location_id === (int) $location->id) {
                    continue;
                }

                app(StockService::class)->move($stockItem, $quantity, (int) $location->id, [
                    'comment' => 'Placement update from /admin/products.',
                ]);
            }
        });
    }

    protected function donorStockLocation(DonorCar $donorCar): Location
    {
        $warehouse = Warehouse::query()
            ->where(fn (Builder $query) => $query
                ->where('type', Warehouse::TYPE_DONOR)
                ->orWhere('name', Warehouse::DONOR_WAREHOUSE_NAME))
            ->first();

        if (! $warehouse instanceof Warehouse) {
            $warehouse = Warehouse::query()->create([
                'name' => Warehouse::DONOR_WAREHOUSE_NAME,
                'type' => Warehouse::TYPE_DONOR,
                'floor_count' => 1,
                'is_active' => true,
            ]);
        }

        if ($warehouse->name !== Warehouse::DONOR_WAREHOUSE_NAME || $warehouse->type !== Warehouse::TYPE_DONOR || ! $warehouse->is_active) {
            $warehouse->forceFill([
                'name' => Warehouse::DONOR_WAREHOUSE_NAME,
                'type' => Warehouse::TYPE_DONOR,
                'floor_count' => max(1, (int) ($warehouse->floor_count ?: 1)),
                'is_active' => true,
            ])->save();
        }

        $fullCode = 'ON-DONOR-'.$donorCar->id;
        $location = Location::query()
            ->where('full_code', $fullCode)
            ->first();

        if ($location instanceof Location) {
            if (! $location->is_active) {
                $location->forceFill(['is_active' => true])->save();
            }

            return $location;
        }

        return Location::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouse->id,
                'full_code' => $fullCode,
            ],
            [
                'floor' => 'floor_1',
                'cell' => Str::limit($donorCar->vin ?: 'DONOR-'.$donorCar->id, 50, ''),
                'is_active' => true,
            ],
        );
    }

    protected function activePlacementLocationsForWarehouseFloor(Warehouse $warehouse, string $floor): Collection
    {
        return Location::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('is_active', true)
            ->get(['id', 'warehouse_id', 'floor', 'cell', 'full_code', 'is_active'])
            ->filter(fn (Location $location): bool => $this->normalizedLocationFloor($location) === $floor)
            ->values();
    }

    protected function noCellPlacementLocation(Warehouse $warehouse, string $floor): Location
    {
        $warehouseLocations = $this->activePlacementLocationsForWarehouseFloor($warehouse, $floor);
        $location = $warehouse->usesStructuredLocations()
            ? $warehouseLocations->first(fn (Location $location): bool => trim((string) $location->cell) === '')
            : $warehouseLocations->first();

        if ($location instanceof Location) {
            return $location;
        }

        return Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => $floor,
            'cell' => null,
            'full_code' => $this->uniqueNoCellLocationCode($warehouse, $floor),
            'is_active' => true,
        ]);
    }

    protected function uniqueNoCellLocationCode(Warehouse $warehouse, string $floor): string
    {
        $floorNumber = Str::after($floor, 'floor_') ?: '1';
        $base = "WH{$warehouse->id}-F{$floorNumber}-NO-CELL";
        $code = $base;
        $counter = 2;

        while (Location::query()->where('full_code', $code)->exists()) {
            $code = "{$base}-{$counter}";
            $counter++;
        }

        return $code;
    }

    protected function activeProductPlacementWarehouses(Product $product): Collection
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->when(! $product->donorCar, fn (Builder $query) => $query
                ->where(fn (Builder $typeQuery) => $typeQuery
                    ->whereNull('type')
                    ->orWhere('type', '!=', Warehouse::TYPE_DONOR)))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'floor_count', 'is_active']);
    }

    protected function activeProductPlacementLocations(): Collection
    {
        return Location::query()
            ->with('warehouse:id,name,type,floor_count,is_active')
            ->where('is_active', true)
            ->whereHas('warehouse', fn (Builder $query) => $query
                ->where('is_active', true)
                ->where(fn (Builder $typeQuery) => $typeQuery
                    ->whereNull('type')
                    ->orWhere('type', '!=', Warehouse::TYPE_DONOR)))
            ->orderBy('warehouse_id')
            ->orderBy('floor')
            ->orderBy('full_code')
            ->get(['id', 'warehouse_id', 'floor', 'cell', 'full_code']);
    }

    protected function productPlacementWarehouseOptions(Collection $warehouses): array
    {
        return $warehouses
            ->sortBy(fn (Warehouse $warehouse): string => ($warehouse->type === Warehouse::TYPE_DONOR ? '0' : '1').(string) $warehouse->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'type' => $warehouse->type,
                'floor_count' => $warehouse->floor_count,
                'uses_structured_locations' => $warehouse->usesStructuredLocations(),
            ])
            ->values()
            ->all();
    }

    protected function productPlacementLocationOptions(Collection $locations): array
    {
        return $locations
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'warehouse_id' => $location->warehouse_id,
                'floor' => $this->normalizedLocationFloor($location),
                'floor_label' => $this->locationFloorLabel($location),
                'label' => $this->locationDisplayCode($location, "\u{0411}\u{0435}\u{0437} \u{044F}\u{0447}\u{0435}\u{0439}\u{043A}\u{0438}"),
                'has_cell' => $location->warehouse?->usesStructuredLocations() && trim((string) $location->cell) !== '',
            ])
            ->values()
            ->all();
    }

    protected function normalizedLocationFloor(Location $location): string
    {
        return is_string($location->floor) && $location->floor !== '' ? $location->floor : 'floor_1';
    }

    protected function locationFloorLabel(Location $location): string
    {
        $floor = $this->normalizedLocationFloor($location);

        if (preg_match('/^floor_(\d+)$/', $floor, $matches)) {
            return "\u{042D}\u{0442}\u{0430}\u{0436} {$matches[1]}";
        }

        return $location->floorLabel();
    }

    protected function locationDisplayCode(Location $location, string $fallback = ''): string
    {
        return trim((string) ($location->cell ?: $location->full_code)) ?: $fallback;
    }

    protected function isSoldProduct(Product $product): bool
    {
        return $product->storage_status === Product::STORAGE_STATUS_SOLD;
    }

    protected function productPhotoPaths(Product $product): Collection
    {
        return ProductPhotoNormalizer::productPhotos($product);
    }

    protected function isProtectedProductPhoto(string $path): bool
    {
        return Str::contains($path, 'tesla-official/part-images/');
    }

    protected function nextSku(): string
    {
        $lastNumber = Product::query()
            ->where('sku', 'like', 'PRD-%')
            ->pluck('sku')
            ->map(function (string $sku): int {
                if (preg_match('/^PRD-(\d{6})$/', $sku, $matches) !== 1) {
                    return 0;
                }

                return (int) $matches[1];
            })
            ->max() ?? 0;

        do {
            $lastNumber++;
            $sku = 'PRD-'.str_pad((string) $lastNumber, 6, '0', STR_PAD_LEFT);
        } while (Product::query()->where('sku', $sku)->exists());

        return $sku;
    }

    protected function formOptions(): array
    {
        return [
            'categories' => Category::query()->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
            'donorCars' => DonorCar::query()->orderBy('vin')->get(),
            'sides' => Product::SIDES,
            'conditionTypes' => Product::CONDITION_TYPE_LABELS,
            'testingStatuses' => Product::TESTING_STATUSES,
            'units' => Product::UNITS,
            'partOrigins' => Product::PART_ORIGINS,
            'models' => PartCatalogCategory::modelOptions(),
        ];
    }
}
