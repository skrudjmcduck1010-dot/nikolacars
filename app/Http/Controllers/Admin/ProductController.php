<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\DeletedPartArchiveService;
use App\Services\NikolaCarsOfficialPartEnrichmentService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Services\PartCatalogDeduplicator;
use App\Services\PartCatalogDisplayService;
use App\Services\PartCatalogManualNameService;
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
        ]);
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

    public function edit(Product $product): View
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
