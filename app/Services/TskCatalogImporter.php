<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Services\Concerns\DetectsPartCatalogLocalizedNames;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class TskCatalogImporter
{
    use DetectsPartCatalogLocalizedNames;

    private const LOCALIZED_LANGUAGE_STOP_WORDS = [
        'оригінальний',
        'оригинальный',
        'оригінал',
        'оригинал',
        'б/в',
        'б/у',
        'бв',
        'бу',
    ];

    protected string $source = 'tsk';

    protected array $localizedNameMarkersCache = [];

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://tsk.ua'), '/');
        $startUrl = $this->absoluteUrl((string) ($options['start_url'] ?? '/katalog-zapchastey296/'), $baseUrl);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $rescan = (bool) ($options['rescan'] ?? false);
        $maxCategories = max(0, (int) ($options['max_categories'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 150));

        $stats = [
            'source_pages_fetched' => 0,
            'category_links_found' => 0,
            'categories_seen' => 0,
            'categories_saved' => 0,
            'products_found' => 0,
            'products_saved' => 0,
        ];

        $html = $this->fetch($startUrl);
        if ($html === null) {
            return $stats;
        }

        $stats['source_pages_fetched']++;
        $rootPage = $this->page($html);
        $queue = collect($this->categoryLinks($rootPage, $baseUrl, $startUrl))
            ->map(fn (string $url): array => ['url' => $url, 'parent_id' => null])
            ->values()
            ->all();
        $queued = collect($queue)->pluck('url')->flip()->all();
        $seen = [$startUrl => true];
        $stats['category_links_found'] = count($queue);

        while ($queue !== []) {
            if ($maxCategories > 0 && $stats['categories_seen'] >= $maxCategories) {
                break;
            }

            $queuedCategory = array_shift($queue);
            $categoryUrl = $queuedCategory['url'];
            $parentId = $queuedCategory['parent_id'];
            unset($queued[$categoryUrl]);

            if (isset($seen[$categoryUrl])) {
                continue;
            }

            $seen[$categoryUrl] = true;
            $stats['categories_seen']++;
            $this->progress($progress, $verbose, "Category #{$stats['categories_seen']}: {$categoryUrl}");

            $alreadyScanned = ! $rescan && PartCatalogCategory::query()
                ->where('source', $this->source)
                ->where('source_url', $categoryUrl)
                ->whereNotNull('products_scanned_at')
                ->exists();

            $categoryHtml = $categoryUrl === $startUrl ? $html : $this->fetch($categoryUrl);
            if ($categoryHtml === null) {
                continue;
            }

            if ($categoryUrl !== $startUrl) {
                $stats['source_pages_fetched']++;
            }

            $page = $this->page($categoryHtml);
            $category = null;
            $categoryPayload = $this->categoryPayload($page, $categoryUrl);
            $parentCategory = $parentId !== null
                ? PartCatalogCategory::query()->find($parentId)
                : null;

            if ($categoryPayload !== null && $parentCategory !== null) {
                $categoryPayload['model_label'] = $parentCategory->model_label;
                $categoryPayload['model_name'] = $parentCategory->model_name;
                $categoryPayload['year_from'] = $parentCategory->year_from;
                $categoryPayload['year_to'] = $parentCategory->year_to;
                $categoryPayload['depth'] = ((int) $parentCategory->depth) + 1;
            }

            if ($categoryPayload !== null && ! $dryRun) {
                $category = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $categoryUrl],
                    $categoryPayload + ['parent_id' => $parentId]
                );
                $stats['categories_saved']++;
            }

            foreach ($this->categoryLinks($page, $baseUrl, $categoryUrl) as $childUrl) {
                if (isset($seen[$childUrl]) || isset($queued[$childUrl])) {
                    continue;
                }

                $queue[] = ['url' => $childUrl, 'parent_id' => $category?->id];
                $queued[$childUrl] = true;
                $stats['category_links_found']++;
            }

            $products = $alreadyScanned ? [] : $this->productsFromPage($page, $baseUrl, $categoryUrl);

            foreach ($products as $product) {
                if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                    break 2;
                }

                $stats['products_found']++;
                $product = $this->withProductDetails($product);

                if (! $dryRun) {
                    $item = $this->saveProduct($category, $categoryPayload, $product);
                    $this->saveOccurrence($item, $category, $product);
                    $stats['products_saved']++;
                }
            }

            if (! $dryRun && $category !== null) {
                $category->forceFill(['products_scanned_at' => now()])->save();
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }

    public function importLeafProducts(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://tsk.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $rescan = (bool) ($options['rescan'] ?? false);
        $maxCategories = max(0, (int) ($options['max_categories'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 150));
        $categoryId = max(0, (int) ($options['category_id'] ?? 0));

        $stats = [
            'leaf_categories_seen' => 0,
            'source_pages_fetched' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'products_created' => 0,
            'products_updated' => 0,
        ];

        $categories = PartCatalogCategory::query()
            ->where('source', $this->source)
            ->when($categoryId > 0, fn ($query) => $query->whereIn('id', $this->categoryBranchIds($categoryId)))
            ->doesntHave('children')
            ->when(! $rescan, fn ($query) => $query->whereNull('products_scanned_at'))
            ->with('parent.parent')
            ->orderByRaw('products_scanned_at is not null')
            ->orderBy('id')
            ->when($maxCategories > 0, fn ($query) => $query->limit($maxCategories))
            ->get();

        foreach ($categories as $category) {
            $stats['leaf_categories_seen']++;
            $this->progress($progress, $verbose, "Leaf category #{$stats['leaf_categories_seen']}: {$category->source_url}");

            $html = $this->fetch($category->source_url);
            if ($html === null) {
                continue;
            }

            $stats['source_pages_fetched']++;
            $page = $this->page($html);
            $products = $this->productsFromPage($page, $baseUrl, $category->source_url);

            foreach ($products as $product) {
                if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                    break 2;
                }

                $stats['products_found']++;
                $product = $this->withProductDetails($product);

                if (! $dryRun) {
                    $lookupSourceUrl = $this->productLookupSourceUrl($product);
                    $exists = PartCatalogItem::query()
                        ->where('source_url', $lookupSourceUrl)
                        ->exists();

                    $item = $this->saveProduct($category, null, $product, $lookupSourceUrl);
                    $this->saveOccurrence($item, $category, $product);

                    $stats['products_saved']++;
                    $stats[$exists ? 'products_updated' : 'products_created']++;

                    $this->progress(
                        $progress,
                        $verbose,
                        '  '.($exists ? 'updated' : 'created').': '.$product['part_number'].' | '.$product['name']
                    );
                }
            }

            if (! $dryRun) {
                $category->forceFill(['products_scanned_at' => now()])->save();
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }

    protected function categoryBranchIds(int $categoryId): array
    {
        $ids = [$categoryId];
        $frontier = [$categoryId];

        while ($frontier !== []) {
            $children = PartCatalogCategory::query()
                ->where('source', $this->source)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->values()
                ->all();

            $children = array_values(array_diff($children, $ids));
            if ($children === []) {
                break;
            }

            $ids = array_values(array_unique([...$ids, ...$children]));
            $frontier = $children;
        }

        return $ids;
    }

    protected function saveProduct(
        ?PartCatalogCategory $category,
        ?array $categoryPayload,
        array $product,
        ?string $lookupSourceUrl = null
    ): PartCatalogItem {
        $lookupSourceUrl ??= $this->productLookupSourceUrl($product);
        $payload = $this->productPayload($category, $categoryPayload, $product);

        return retry([500, 1000, 2000], function () use ($lookupSourceUrl, $payload): PartCatalogItem {
            $item = PartCatalogItem::query()->where('source_url', $lookupSourceUrl)->first();

            if ($item === null) {
                return PartCatalogItem::query()->create(['source_url' => $lookupSourceUrl] + $payload);
            }

            $item->forceFill($this->preserveExistingLocalizedNames($item, $payload))->save();

            return $item;
        }, 0, fn (Throwable $e): bool => $this->causedByDatabaseConcurrency($e));
    }

    protected function preserveExistingLocalizedNames(PartCatalogItem $item, array $payload): array
    {
        if (filled($item->name_ru) || filled($item->name_ua)) {
            unset($payload['name_ru'], $payload['name_ua']);

            if (array_key_exists('raw_attributes', $payload)) {
                $payload['raw_attributes'] = $this->withoutLanguageMarkerSource((array) $payload['raw_attributes'], 'ru');
                $payload['raw_attributes'] = $this->withoutLanguageMarkerSource((array) $payload['raw_attributes'], 'ua');
            }

            return $payload;
        }

        foreach (['ru' => 'name_ru', 'ua' => 'name_ua'] as $locale => $column) {
            if (! array_key_exists($column, $payload) || ! filled($item->{$column})) {
                continue;
            }

            unset($payload[$column]);

            if (array_key_exists('raw_attributes', $payload)) {
                $payload['raw_attributes'] = $this->withoutLanguageMarkerSource((array) $payload['raw_attributes'], $locale);
            }
        }

        return $payload;
    }

    protected function withoutLanguageMarkerSource(array $rawAttributes, string $locale): array
    {
        unset(
            $rawAttributes['name_source_type_'.$locale],
            $rawAttributes['name_source_marker_'.$locale]
        );

        return $rawAttributes;
    }

    protected function causedByDatabaseConcurrency(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Lock wait timeout exceeded')
            || str_contains($e->getMessage(), 'Deadlock found when trying to get lock');
    }

    public function productDetails(string $url): array
    {
        $html = $this->fetch($url);
        if ($html === null) {
            return [];
        }

        return $this->productDetailsFromPage($this->page($html), $url);
    }

    protected function withProductDetails(array $product): array
    {
        $productUrl = (string) ($product['product_url'] ?? '');

        if (($product['price_amount'] ?? null) !== null || $productUrl === '' || ! str_starts_with($productUrl, 'https://tsk.ua/')) {
            return $product;
        }

        $details = $this->productDetails($productUrl);

        if ($details === []) {
            return $product;
        }

        return array_filter($details + $product, fn ($value): bool => $value !== null && $value !== '');
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = $this->http
                ->timeout(30)
                ->retry(2, 500)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept-Language' => 'uk-UA,uk;q=0.9,ru;q=0.8,en;q=0.7',
                ])
                ->get($url);

            return $response->ok() ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function page(string $html): array
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return [
            'document' => $document,
            'xpath' => new DOMXPath($document),
        ];
    }

    protected function categoryLinks(array $page, string $baseUrl, ?string $currentUrl = null): array
    {
        $productUrls = collect($this->productsFromPage($page, $baseUrl))
            ->pluck('source_url')
            ->all();

        return $this->links($page, $baseUrl)
            ->reject(fn (string $url): bool => in_array($url, $productUrls, true))
            ->reject(fn (string $url): bool => $currentUrl !== null && $url === $currentUrl)
            ->filter(fn (string $url): bool => $this->isCatalogUrl($url, $baseUrl))
            ->values()
            ->all();
    }

    protected function links(array $page, string $baseUrl): Collection
    {
        $links = [];

        foreach ($page['xpath']->query('//a[@href]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($node->getAttribute('href'), $baseUrl);
            if ($url !== null) {
                $links[$url] = $url;
            }
        }

        return collect(array_values($links));
    }

    protected function isCatalogUrl(string $url, string $baseUrl): bool
    {
        if (parse_url($url, PHP_URL_HOST) !== parse_url($baseUrl, PHP_URL_HOST)) {
            return false;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '' || str_starts_with($path, 'en/') || str_starts_with($path, 'ru/') || str_contains($path, 'wp-') || str_contains($path, 'cart') || str_contains($path, 'checkout')) {
            return false;
        }

        if ($this->isEpcCatalogPath($path)) {
            return $path !== 'katalog-zapchastey296' && ! $this->isEpcProductPath($path);
        }

        return false;
    }

    protected function productsFromPage(array $page, string $baseUrl, ?string $pageUrl = null): array
    {
        $products = $this->epcProductsFromPage($page, $pageUrl);

        foreach ($page['xpath']->query('//a[@href]') as $linkNode) {
            if (! $linkNode instanceof DOMElement) {
                continue;
            }

            $name = $this->clean($linkNode->getAttribute('title') ?: $linkNode->textContent);
            if ($name === '') {
                continue;
            }

            $sourceUrl = $this->absoluteUrl($linkNode->getAttribute('href'), $baseUrl);
            if ($sourceUrl === null || isset($products[$sourceUrl])) {
                continue;
            }

            $container = $this->nearestProductContainer($linkNode);
            if ($container === null) {
                continue;
            }

            $text = $this->clean($container->textContent);
            $partNumber = $this->partNumber($text);
            if ($partNumber === null) {
                continue;
            }

            if (! $this->isProductUrl($sourceUrl, $partNumber) || ! $this->isUsableProductName($name)) {
                continue;
            }

            $imageNode = $page['xpath']->query('.//img[@src]', $container)->item(0);
            $imageUrl = $imageNode instanceof DOMElement ? $this->usableImageUrl($imageNode->getAttribute('src'), $baseUrl) : null;
            $products[$sourceUrl] = [
                'source_url' => $sourceUrl,
                'part_number' => $partNumber,
                'name' => $this->productName($name, $partNumber),
                'scheme_number' => null,
                'price_amount' => $this->priceAmount($text),
                'currency' => str_contains($text, 'USD') ? 'USD' : null,
                'availability' => str_contains(Str::lower($text), 'в наявності') ? 'В наявності' : null,
                'image_url' => $imageUrl,
            ];
        }

        return array_values($products);
    }

    protected function epcProductsFromPage(array $page, ?string $pageUrl = null): array
    {
        $products = [];

        foreach ($page['xpath']->query('//table//tr[td]') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = [];
            foreach ($page['xpath']->query('./td', $row) as $cell) {
                $cells[] = $this->clean($cell->textContent);
            }

            if (count($cells) < 4) {
                continue;
            }

            $partNumber = collect($cells)
                ->first(fn (string $cell): bool => preg_match('/^[A-Z0-9]{6,}-[A-Z0-9]{2,}(?:-[A-Z0-9]+)?$/iu', $cell) === 1);

            if ($partNumber === null) {
                continue;
            }

            $partIndex = array_search($partNumber, $cells, true);
            $name = $partIndex > 0 ? $cells[$partIndex - 1] : null;
            if ($name === null || $name === '') {
                continue;
            }

            $schemeNumber = $cells[0] !== '' && Str::lower($cells[0]) !== '#' ? $cells[0] : null;
            $availability = $cells[$partIndex + 2] ?? ($cells[$partIndex + 1] ?? null);
            $productUrl = null;
            $linkNode = $page['xpath']->query('.//a[@href]', $row)->item(0);
            if ($linkNode instanceof DOMElement) {
                $baseUrl = $pageUrl !== null && str_starts_with($pageUrl, 'http') ? $pageUrl : 'https://tsk.ua';
                $productUrl = $this->absoluteUrl($linkNode->getAttribute('href'), $baseUrl);
            }
            $sourceUrl = 'tsk-epc:'.md5(collect([$pageUrl, $schemeNumber, $partNumber, $name])->filter()->implode('|'));

            $products[$sourceUrl] = [
                'source_url' => $sourceUrl,
                'part_number' => Str::upper($partNumber),
                'name' => Str::limit($name, 255, ''),
                'scheme_number' => $schemeNumber,
                'price_amount' => null,
                'currency' => null,
                'availability' => $availability,
                'image_url' => null,
                'page_url' => $pageUrl,
                'product_url' => $productUrl,
                'quantity' => $cells[$partIndex + 1] ?? null,
            ];
        }

        return $products;
    }

    protected function nearestProductContainer(DOMElement $node): ?DOMElement
    {
        $current = $node;

        for ($depth = 0; $depth < 6; $depth++) {
            $parent = $current->parentNode;
            if (! $parent instanceof DOMElement) {
                return null;
            }

            $text = $this->clean($parent->textContent);
            if (str_contains($text, 'Парт номер:')) {
                return $parent;
            }

            $current = $parent;
        }

        return null;
    }

    protected function categoryPayload(array $page, string $url): ?array
    {
        $crumbs = $this->breadcrumbs($page);
        if ($crumbs === []) {
            $headline = $this->headline($page) ?: $this->pageTitle($page);
            if ($headline === null) {
                return null;
            }

            $crumbs = [$headline];
        }

        $current = Arr::last($crumbs);
        if ($current === null) {
            return null;
        }

        [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->modelFromUrl($url) ?? $this->modelFromCrumbs($crumbs);
        [$mainCode, $mainName] = $this->splitCodeName($crumbs[1] ?? null);
        [$subcategoryCode, $subcategoryName] = $this->splitCodeName($crumbs[2] ?? null);
        $depth = max(count($crumbs) - 1, 0);
        $name = $depth === 0 && $modelName !== null ? $modelName : $current;

        return [
            'source' => $this->source,
            'source_url' => $url,
            'depth' => $depth,
            'code' => $depth === 1 ? $mainCode : ($depth === 2 ? $subcategoryCode : null),
            'name' => Str::limit($name, 255, ''),
            'name_ru' => Str::limit($name, 255, ''),
            'model_label' => $modelLabel,
            'model_name' => $modelName,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
            'sort_order' => $this->categorySortOrder($depth === 1 ? $mainCode : $subcategoryCode),
            'children_scanned_at' => now(),
        ];
    }

    protected function productPayload(?PartCatalogCategory $category, ?array $categoryPayload, array $product): array
    {
        $mainCategory = $category?->parent?->parent ?: $category?->parent;
        $subcategory = $category?->parent;
        $productUrl = $this->productUrl($product);
        $localizedNameResolution = $this->localizedNameResolution($product['name']);
        $localizedNamePayload = $this->localizedNamePayloadFromResolution($localizedNameResolution);
        $rawAttributes = array_filter([
            'category_source_url' => $category?->source_url,
            'image_url' => $product['image_url'] ?? null,
            'image_urls' => $product['image_urls'] ?? null,
            'page_url' => $product['page_url'] ?? null,
            'product_url' => $productUrl,
            'quantity' => $product['quantity'] ?? null,
        ]);

        if (($localizedNameResolution['source'] ?? null) === 'language_marker') {
            foreach ($this->localizedNameSourceLocales($localizedNameResolution) as $locale) {
                $rawAttributes['name_source_type_'.$locale] = 'language_marker';
                $rawAttributes['name_source_marker_'.$locale] = $localizedNameResolution['marker'];
                $rawAttributes = $this->withLocalizedNameMarkerConflict($rawAttributes, $locale, $localizedNameResolution);
            }
        }

        return [
            'part_catalog_category_id' => $category?->id,
            'source' => $this->source,
            'part_number' => $product['part_number'],
            'name' => $product['name'],
            'scheme_number' => $this->numericSchemeNumber($product['scheme_number'] ?? null),
            'price_amount' => $product['price_amount'] ?? null,
            'currency' => $product['currency'] ?? null,
            'condition' => $product['condition'] ?? null,
            'model_label' => $category?->model_label ?: ($categoryPayload['model_label'] ?? null),
            'model_name' => $category?->model_name ?: ($categoryPayload['model_name'] ?? null),
            'year_from' => $category?->year_from ?: ($categoryPayload['year_from'] ?? null),
            'year_to' => $category?->year_to ?: ($categoryPayload['year_to'] ?? null),
            'main_category_code' => $mainCategory?->code,
            'main_category_name' => $mainCategory?->name,
            'subcategory_code' => $subcategory?->code,
            'subcategory_name' => $subcategory?->name,
            'node_name' => $category?->name,
            'compatibility_text' => $product['compatibility_text'] ?? ($category?->model_label ?: ($categoryPayload['model_label'] ?? null)),
            'availability' => $product['availability'] ?? null,
            'raw_attributes' => $rawAttributes,
            'source_updated_at' => now(),
        ] + $localizedNamePayload + [
            'name_ru' => null,
            'name_ua' => null,
        ];
    }

    protected function productLookupSourceUrl(array $product): string
    {
        $sourceUrl = (string) $product['source_url'];
        $productUrl = $this->productUrl($product);

        if ($productUrl !== null) {
            $existingSourceUrl = PartCatalogItem::query()
                ->where('source', $this->source)
                ->where(function ($query) use ($productUrl): void {
                    $query
                        ->where('source_url', $productUrl)
                        ->orWhere('raw_attributes->product_url', $productUrl);
                })
                ->orderByRaw('source_url = ? desc', [$productUrl])
                ->orderBy('id')
                ->value('source_url');

            return $existingSourceUrl ?: $sourceUrl;
        }

        return $sourceUrl;
    }

    protected function saveOccurrence(PartCatalogItem $item, ?PartCatalogCategory $category, array $product): void
    {
        $pageUrl = (string) ($product['page_url'] ?? '');
        $productUrl = $this->productUrl($product);
        $occurrenceKey = hash('sha256', collect([
            $this->source,
            $pageUrl,
            $product['scheme_number'] ?? null,
            $product['part_number'] ?? null,
            $product['name'] ?? null,
            $productUrl,
        ])->filter(fn (mixed $value): bool => $value !== null && $value !== '')->implode('|'));

        $payload = [
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $category?->id,
            'source' => $this->source,
            'page_url' => $pageUrl ?: null,
            'product_url' => $productUrl,
            'part_number' => $product['part_number'] ?? null,
            'name' => $product['name'] ?? null,
            'scheme_number' => $this->numericSchemeNumber($product['scheme_number'] ?? null),
            'quantity' => $product['quantity'] ?? null,
            'raw_attributes' => array_filter([
                'source_url' => $product['source_url'] ?? null,
                'image_url' => $product['image_url'] ?? null,
            ]),
        ];

        retry([500, 1000, 2000], function () use ($occurrenceKey, $payload): void {
            PartCatalogItemOccurrence::query()->updateOrCreate(
                ['occurrence_key' => $occurrenceKey],
                $payload
            );
        }, 0, fn (Throwable $e): bool => $this->causedByDatabaseConcurrency($e));
    }

    protected function productUrl(array $product): ?string
    {
        return $this->canonicalTskProductUrl($product['product_url'] ?? null)
            ?: $this->canonicalTskProductUrl($product['source_url'] ?? null)
            ?: $this->productUrlFromPartNumber($product['part_number'] ?? null);
    }

    protected function isRussianUrl(string $url): bool
    {
        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/ru/');
    }

    protected function productUrlFromPartNumber(mixed $partNumber): ?string
    {
        $partNumber = Str::lower(trim((string) $partNumber));

        if ($partNumber === '' || preg_match('/^[a-z0-9]{6,}(?:-[a-z0-9]{1,}){0,2}$/i', $partNumber) !== 1) {
            return null;
        }

        return 'https://tsk.ua/'.$partNumber.'/';
    }

    protected function canonicalTskProductUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        if ($url === '' || ! str_starts_with($url, 'http')) {
            return null;
        }

        $canonical = $this->canonicalUrl($url);

        return $canonical !== null && $this->isEpcProductPath(trim((string) parse_url($canonical, PHP_URL_PATH), '/'))
            ? $canonical
            : null;
    }

    protected function productDetailsFromPage(array $page, string $url): array
    {
        $productNode = $page['xpath']
            ->query('//*[contains(concat(" ", normalize-space(@class), " "), " one-tovar ")]')
            ->item(0);
        $scope = $productNode instanceof DOMElement ? $productNode : $page['document'];

        $nameNode = $page['xpath']
            ->query('.//*[contains(concat(" ", normalize-space(@class), " "), " one-tovar__name ")]', $scope)
            ->item(0);
        $priceNode = $page['xpath']
            ->query('.//*[contains(concat(" ", normalize-space(@class), " "), " one-tovar__price ") or contains(concat(" ", normalize-space(@class), " "), " tovar-anons__price ")]', $scope)
            ->item(0);
        $priceText = $priceNode instanceof DOMElement ? $this->clean($priceNode->textContent) : '';
        $currentPriceText = null;
        if ($priceNode instanceof DOMElement) {
            foreach ($page['xpath']->query('.//*[contains(text(), "USD")]', $priceNode) as $node) {
                $nodePriceText = $this->clean($node->textContent);

                if (preg_match('/\d/u', $nodePriceText) === 1) {
                    $currentPriceText = $nodePriceText;
                }
            }
        }
        $availabilityNode = $page['xpath']
            ->query('.//*[contains(concat(" ", normalize-space(@class), " "), " one-tovar__nal ") or contains(concat(" ", normalize-space(@class), " "), " tovar-anons__nonal ")]', $scope)
            ->item(0);
        $orderButtonNode = $page['xpath']
            ->query('.//a[contains(concat(" ", normalize-space(@class), " "), " btn ") and contains(normalize-space(.), "Під замовлення")]', $scope)
            ->item(0);
        $conditionNode = $page['xpath']
            ->query('.//*[contains(concat(" ", normalize-space(@class), " "), " one-tovar__specif ") and contains(normalize-space(.), "Стан:")]//button[@data-status]', $scope)
            ->item(0);
        $compatibilityNode = $page['xpath']
            ->query('.//*[contains(concat(" ", normalize-space(@class), " "), " one-tovar__specif ") and contains(normalize-space(.), "Модель авто:")]//b', $scope)
            ->item(0);
        $imageNode = $page['xpath']
            ->query('//meta[@property="og:image"]/@content | //img[@src]')
            ->item(0);
        $imageUrls = $this->productImageUrls($page, $url);
        $availability = $availabilityNode instanceof DOMElement ? $this->clean($availabilityNode->textContent) : null;
        if (($availability === null || $availability === '') && $orderButtonNode instanceof DOMElement) {
            $availability = $this->clean($orderButtonNode->textContent);
        }

        return array_filter([
            'name' => $nameNode instanceof DOMElement ? $this->productName($this->clean($nameNode->textContent), $this->partNumberFromUrl($url) ?? '') : null,
            'price_amount' => $this->priceAmount($currentPriceText ?: $priceText),
            'currency' => str_contains($currentPriceText ?: $priceText, 'USD') ? 'USD' : null,
            'condition' => $conditionNode instanceof DOMElement ? $this->productCondition($this->clean($conditionNode->textContent)) : null,
            'availability' => $availability,
            'compatibility_text' => $compatibilityNode instanceof DOMElement ? $this->clean($compatibilityNode->textContent) : null,
            'image_url' => $imageUrls[0] ?? ($imageNode !== null ? $this->usableImageUrl($imageNode->nodeValue, $url) : null),
            'image_urls' => $imageUrls,
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function productImageUrls(array $page, string $url): array
    {
        $urls = [];

        foreach ([
            '//*[contains(concat(" ", normalize-space(@class), " "), " one-tovar__gallery ")]//a[contains(concat(" ", normalize-space(@class), " "), " gallery-image ")][@href]/@href',
            '//*[contains(concat(" ", normalize-space(@class), " "), " one-tovar__gallery ")]//img[@src]/@src',
        ] as $query) {
            foreach ($page['xpath']->query($query) as $node) {
                $imageUrl = $this->absoluteUrl($node->nodeValue, $url);

                if ($imageUrl !== null && ! $this->isPlaceholderImageUrl($imageUrl)) {
                    $urls[] = $this->normalizeImageUrl($imageUrl);
                }
            }
        }

        if ($urls === []) {
            foreach ($page['xpath']->query('//meta[@property="og:image"]/@content') as $node) {
                $imageUrl = $this->absoluteUrl($node->nodeValue, $url);

                if ($imageUrl !== null && ! $this->isPlaceholderImageUrl($imageUrl)) {
                    $urls[] = $this->normalizeImageUrl($imageUrl);
                }
            }
        }

        return collect($urls)
            ->unique()
            ->values()
            ->all();
    }

    protected function usableImageUrl(string $url, string $baseUrl): ?string
    {
        $imageUrl = $this->absoluteUrl($url, $baseUrl);

        return $imageUrl !== null && ! $this->isPlaceholderImageUrl($imageUrl)
            ? $this->normalizeImageUrl($imageUrl)
            : null;
    }

    protected function isPlaceholderImageUrl(string $url): bool
    {
        $path = Str::lower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, '75bd7c3f97912998faad55cf0790b015b3feae79')
            || str_contains($path, 'no-photo')
            || str_contains($path, 'no_photo')
            || str_contains($path, 'placeholder');
    }

    protected function normalizeImageUrl(string $url): string
    {
        return preg_match('/\.(?:jpe?g|png|webp|gif)\/?$/iu', $url) === 1
            ? rtrim($url, '/')
            : $url;
    }

    protected function partNumberFromUrl(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return preg_match('/^[a-z0-9]{6,}(?:-[a-z0-9]{1,}){1,2}$/i', $path) === 1
            ? Str::upper($path)
            : null;
    }

    protected function numericSchemeNumber(mixed $value): ?int
    {
        $value = trim((string) $value);

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    protected function breadcrumbs(array $page): array
    {
        $crumbs = [];

        foreach ($page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")]//a | //*[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")]//span') as $node) {
            $text = $this->clean($node->textContent);
            $lower = Str::lower($text);

            if ($text === '' || in_array($lower, ['головна', 'каталог запчастин', 'epc tesla', 'tesla запчастини і аксесуари'], true)) {
                continue;
            }

            if (! in_array($text, $crumbs, true)) {
                $crumbs[] = $text;
            }
        }

        return $crumbs;
    }

    protected function parentCategory(array $payload): ?PartCatalogCategory
    {
        if ($payload['depth'] <= 0 || $payload['model_label'] === null) {
            return null;
        }

        return PartCatalogCategory::query()
            ->where('source', $this->source)
            ->where('model_label', $payload['model_label'])
            ->where('depth', $payload['depth'] - 1)
            ->latest('id')
            ->first();
    }

    protected function modelFromCrumbs(array $crumbs): array
    {
        $model = collect($crumbs)->first(fn (string $crumb): bool => preg_match('/model\s*[s3xy]/iu', $crumb) === 1);
        $model = $model ?: ($crumbs[0] ?? null);

        return match (true) {
            str_contains(Str::lower((string) $model), 'model s first') => ['Model S First Generation', 'Model S', 2012, 2016],
            str_contains(Str::lower((string) $model), 'model s feb 2012') => [$model, 'Model S', 2012, 2016],
            str_contains(Str::lower((string) $model), 'model s restail') => [$model, 'Model S', 2016, 2021],
            str_contains(Str::lower((string) $model), 'model s apr 2016') => [$model, 'Model S', 2016, 2021],
            str_contains(Str::lower((string) $model), 'model s plaid') => [$model, 'Model S Plaid', 2021, 2025],
            str_contains(Str::lower((string) $model), 'model s feb 2021') => [$model, 'Model S', 2021, 2025],
            str_contains(Str::lower((string) $model), 'model x plaid') => [$model, 'Model X Plaid', 2021, 2025],
            str_contains(Str::lower((string) $model), 'model x mar 2021') => [$model, 'Model X', 2021, 2025],
            str_contains(Str::lower((string) $model), 'model x') => [$model, 'Model X', 2015, 2021],
            str_contains(Str::lower((string) $model), 'model 3 sep 2023') => [$model, 'Model 3 Highland', 2024, null],
            str_contains(Str::lower((string) $model), 'model 3') => [$model, 'Model 3', 2017, 2023],
            str_contains(Str::lower((string) $model), 'model y') => [$model, 'Model Y', 2020, 2025],
            default => [$model, $model, null, null],
        };
    }

    protected function modelFromUrl(string $url): ?array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return match (true) {
            str_contains($path, 'model-s-parts-europe') => ['Model S Parts Europe', 'Model S', 2012, 2016],
            str_contains($path, 'model-sr-europe') => ['Model SR Europe', 'Model S', 2016, 2021],
            str_contains($path, 'model-s-feb-2021') => ['Model S Feb 2021', 'Model S', 2021, 2025],
            str_contains($path, 'model-s-parts-catalog487') => ['Model 3 Parts Catalog', 'Model 3', 2017, 2023],
            str_contains($path, 'model-3-sep-2023') => ['Model 3 Sep 2023', 'Model 3 Highland', 2024, null],
            str_contains($path, 'model-x-europe') => ['Model X Europe', 'Model X', 2015, 2021],
            str_contains($path, 'model-x-mar-2021') => ['Model X Mar 2021', 'Model X', 2021, 2025],
            str_contains($path, 'model-y-parts-catalog3166') => ['Model Y Parts Catalog', 'Model Y', 2020, 2025],
            default => null,
        };
    }

    protected function splitCodeName(?string $value): array
    {
        $value = $this->clean($value);

        if (preg_match('/^(\d+)\s*-\s*(.+)$/u', $value, $matches) === 1) {
            return [$matches[1], $this->clean($matches[2])];
        }

        return [null, $value ?: null];
    }

    protected function partNumber(string $text): ?string
    {
        if (preg_match('/Парт\s*номер:\s*([A-Z0-9][A-Z0-9.\-]+(?:-[A-Z0-9.\-]+)?)/iu', $text, $matches) !== 1) {
            return null;
        }

        return Str::upper($matches[1]);
    }

    protected function productName(string $name, string $partNumber): string
    {
        $name = preg_replace('/\s+'.preg_quote($partNumber, '/').'\s*$/iu', '', $name) ?? $name;

        return Str::limit($this->clean($name), 255, '');
    }

    protected function isProductUrl(string $url, string $partNumber): bool
    {
        $path = Str::lower((string) parse_url($url, PHP_URL_PATH));
        $needle = Str::lower(str_replace('.', '-', $partNumber));

        return str_contains($path, $needle);
    }

    protected function isUsableProductName(string $name): bool
    {
        $name = Str::lower($this->clean($name));

        if ($name === '' || preg_match('/^\d+$/', $name) === 1) {
            return false;
        }

        return ! in_array($name, [
            'цена (по убыванию)',
            'цена (по возрастанию)',
            'топ продаж',
            'новинки',
        ], true);
    }

    protected function priceAmount(string $text): ?float
    {
        if (preg_match_all('/([0-9]+(?:\s+[0-9]+)?(?:[.,][0-9]+)?)\s*USD/iu', $text, $matches) < 1) {
            return null;
        }

        $amount = Arr::last($matches[1]);

        return round((float) str_replace([' ', ','], ['', '.'], $amount), 2);
    }

    protected function productCondition(string $text): ?string
    {
        $text = Str::lower($this->clean($text));

        return match (true) {
            str_contains($text, 'б/у'), str_contains($text, 'бу'), str_contains($text, 'б у') => 'Б/У',
            str_contains($text, 'нов') => 'Новое',
            default => $text !== '' ? Str::limit($text, 255, '') : null,
        };
    }

    protected function categorySortOrder(?string $code): int
    {
        return $code === null ? 0 : (int) $code;
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($verbose && $progress !== null) {
            $progress($message);
        }
    }

    protected function bodyText(array $page): string
    {
        return $this->clean($page['document']->textContent);
    }

    protected function headline(array $page): ?string
    {
        foreach (['//h1', '//h2'] as $query) {
            $node = $page['xpath']->query($query)->item(0);
            if ($node !== null) {
                $text = $this->clean($node->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    protected function pageTitle(array $page): ?string
    {
        $node = $page['xpath']->query('//title')->item(0);
        $title = $node === null ? '' : $this->clean($node->textContent);
        $title = preg_replace('/\s*[|–-]\s*TSK.*$/iu', '', $title) ?? $title;

        return $title === '' ? null : $title;
    }

    protected function clean(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    protected function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || $url === '#' || str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $this->canonicalUrl(strtok($url, '#') ?: null);
        }

        if (str_starts_with($url, '/')) {
            $parts = parse_url($baseUrl);
            $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'tsk.ua');

            return $this->canonicalUrl($origin.'/'.ltrim((string) strtok($url, '#'), '/'));
        }

        return $this->canonicalUrl($baseUrl.'/'.ltrim((string) strtok($url, '#'), '/'));
    }

    protected function canonicalUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        $path = preg_replace('#^/(ua|ru|en)/#i', '/', $path) ?? $path;
        $path = preg_replace('#^/(ua|ru|en)(/katalog-zapchastey296/)#i', '$2', $path) ?? $path;
        $path = rtrim($path, '/').'/';

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'tsk.ua').$path;
    }

    protected function isEpcCatalogPath(string $path): bool
    {
        return $path === 'katalog-zapchastey296' || str_starts_with($path, 'katalog-zapchastey296/');
    }

    protected function isEpcProductPath(string $path): bool
    {
        $lastSegment = Str::lower(Arr::last(explode('/', trim($path, '/'))) ?? '');

        return preg_match('/^[a-z0-9]{6,}(?:-[a-z0-9]{1,}){0,2}$/i', $lastSegment) === 1;
    }
}
