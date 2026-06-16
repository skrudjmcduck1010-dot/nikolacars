<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Support\PartCatalogRawAttributes;
use App\Support\PartNumberNormalizer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class DkPartsCatalogImporter
{
    protected string $source = 'dkparts';

    protected const MODEL_ROOT_LISTINGS = [
        '/ru/model-s-before-2016/' => ['name' => 'Model S до 2016', 'compatibility' => 'Model S', 'year_from' => 2012, 'year_to' => 2016, 'sort_order' => 1],
        '/ru/model-s-after-2016/' => ['name' => 'Model S после 2016', 'compatibility' => 'Model S', 'year_from' => 2016, 'year_to' => 2021, 'sort_order' => 2],
        '/ru/model-s-plaid/' => ['name' => 'Model S Plaid', 'compatibility' => 'Model S Plaid', 'year_from' => 2021, 'year_to' => 2025, 'sort_order' => 3],
        '/ru/model-x/' => ['name' => 'Tesla Model X', 'compatibility' => 'Model X', 'year_from' => 2015, 'year_to' => 2021, 'sort_order' => 4],
        '/ru/model-x-plaid/' => ['name' => 'Model X Plaid', 'compatibility' => 'Model X Plaid', 'year_from' => 2021, 'year_to' => 2025, 'sort_order' => 5],
        '/ru/model-3/' => ['name' => 'Tesla Model 3', 'compatibility' => 'Model 3', 'year_from' => 2017, 'year_to' => 2023, 'sort_order' => 6],
        '/ru/model-y/' => ['name' => 'TESLA MODEL Y', 'compatibility' => 'Model Y', 'year_from' => 2020, 'year_to' => 2025, 'sort_order' => 7],
    ];

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://dk-parts.com.ua'), '/');
        $startUrl = $this->absoluteUrl((string) ($options['start_url'] ?? '/ru'), $baseUrl);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'source_pages_fetched' => 0,
            'models_saved' => 0,
            'main_categories_saved' => 0,
            'subcategories_saved' => 0,
        ];

        $html = $this->fetch($startUrl);
        if ($html === null) {
            return $stats;
        }

        $stats['source_pages_fetched']++;

        foreach ($this->models($this->page($html), $baseUrl) as $model) {
            $modelMeta = $this->modelMetadata($model['name'], $model['url'] ?? null);
            $modelName = $modelMeta['compatibility'];
            $yearFrom = $modelMeta['year_from'];
            $yearTo = $modelMeta['year_to'];
            $this->progress($progress, $verbose, "Model: {$model['name']}");

            $modelCategory = null;
            if (! $dryRun) {
                $modelCategory = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $model['url']],
                    [
                        'source' => $this->source,
                        'parent_id' => null,
                        'depth' => 0,
                        'code' => null,
                        'name' => $model['name'],
                        'model_label' => $model['name'],
                        'model_name' => $modelName,
                        'year_from' => $yearFrom,
                        'year_to' => $yearTo,
                        'sort_order' => $modelMeta['sort_order'],
                        'children_scanned_at' => now(),
                    ]
                );

                $stats['models_saved']++;
            }

            foreach ($model['categories'] as $main) {
                [$mainCode, $mainName] = $this->splitCodeName($main['name']);
                $mainName = $this->sourceCategoryName($model['name'], 1, $mainCode, $mainName);
                $mainCategory = null;

                if (! $dryRun) {
                    $mainCategory = PartCatalogCategory::query()->updateOrCreate(
                        ['source_url' => $main['url']],
                        [
                            'source' => $this->source,
                            'parent_id' => $modelCategory?->id,
                            'depth' => 1,
                            'code' => $mainCode,
                            'name' => $mainName,
                            'model_label' => $model['name'],
                            'model_name' => $modelName,
                            'year_from' => $yearFrom,
                            'year_to' => $yearTo,
                            'sort_order' => $this->categorySortOrder($mainCode),
                            'children_scanned_at' => now(),
                        ]
                    );

                    $stats['main_categories_saved']++;
                }

                foreach ($main['children'] as $subcategory) {
                    [$subcategoryCode, $subcategoryName] = $this->splitCodeName($subcategory['name']);
                    $subcategoryName = $this->sourceCategoryName($model['name'], 2, $subcategoryCode, $subcategoryName);

                    if (! $dryRun) {
                        PartCatalogCategory::query()->updateOrCreate(
                            ['source_url' => $subcategory['url']],
                            [
                                'source' => $this->source,
                                'parent_id' => $mainCategory?->id,
                                'depth' => 2,
                                'code' => $subcategoryCode,
                                'name' => $subcategoryName,
                                'model_label' => $model['name'],
                                'model_name' => $modelName,
                                'year_from' => $yearFrom,
                                'year_to' => $yearTo,
                                'sort_order' => $this->categorySortOrder($subcategoryCode),
                                'children_scanned_at' => now(),
                                'products_scanned_at' => null,
                            ]
                        );

                        $stats['subcategories_saved']++;
                    }
                }
            }
        }

        return $stats;
    }

    public function importProducts(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://dk-parts.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $rescan = (bool) ($options['rescan'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxCategories = max(0, (int) ($options['max_categories'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $maxPagesPerCategory = max(1, (int) ($options['max_pages_per_category'] ?? 20));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $modelRootListings = (bool) ($options['model_root_listings'] ?? true);
        $fetchLocalizedNames = (bool) ($options['fetch_localized_names'] ?? false);

        $stats = [
            'source_pages_fetched' => 0,
            'localized_name_pages_fetched' => 0,
            'localized_names_filled' => 0,
            'categories_scanned' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'product_detail_pages_fetched' => 0,
            'product_detail_pages_failed' => 0,
            'product_images_found' => 0,
            'categories_marked_scanned' => 0,
        ];

        if ($modelRootListings) {
            $categories = $this->modelRootCategories($baseUrl, $dryRun);

            if (! $rescan) {
                $categories = $categories
                    ->filter(fn (PartCatalogCategory $category): bool => $category->products_scanned_at === null)
                    ->values();
            }

            if ($maxCategories > 0) {
                $categories = $categories->take($maxCategories)->values();
            }
        } else {
            $query = PartCatalogCategory::query()
                ->with('parent.parent')
                ->where('source', $this->source)
                ->where('depth', 2)
                ->orderBy('id');

            if (! $rescan) {
                $query->whereNull('products_scanned_at');
            }

            if ($maxCategories > 0) {
                $query->limit($maxCategories);
            }

            $categories = $query->get();
        }

        $seenUrls = [];

        foreach ($categories as $category) {
            $this->progress($progress, $verbose, "Category #{$category->id}: {$category->name}");
            $categoryProductCount = 0;

            foreach ($this->categoryPages((string) $category->source_url, $baseUrl, $maxPagesPerCategory, $sleepMs) as $html) {
                $stats['source_pages_fetched']++;

                foreach ($this->productsFromCategoryPage($this->page($html), $baseUrl) as $product) {
                    $sourceUrl = (string) ($product['source_url'] ?? '');
                    $alreadySeen = isset($seenUrls[$sourceUrl]);
                    $seenUrls[$sourceUrl] = true;
                    $stats['products_found']++;
                    $categoryProductCount++;

                    if (! $dryRun) {
                        $existingItem = PartCatalogItem::query()
                            ->where('source', $this->source)
                            ->where('source_url', $sourceUrl)
                            ->first();

                        if ($alreadySeen) {
                            if ($existingItem !== null) {
                                $this->saveOccurrence($existingItem, $category, $product);
                            }

                            continue;
                        }

                        if ($existingItem === null) {
                            $product = $this->withProductDetails($product, $baseUrl, $stats, $sleepMs);
                        }

                        if ($existingItem === null && $fetchLocalizedNames) {
                            $product = $this->withLocalizedNamesOnSave($product, $existingItem, $stats, $sleepMs);
                        } else {
                            $product['name_ru'] = $product['name_ru'] ?: $existingItem?->name_ru;
                            $product['name_ua'] = $existingItem?->name_ua;
                        }

                        $productCategory = $this->categoryForProduct($product, $category, $baseUrl);
                        $payload = $this->productPayload($productCategory, $product, $existingItem);

                        $item = PartCatalogItem::query()->updateOrCreate(
                            ['source_url' => $sourceUrl],
                            $payload
                        );
                        $this->saveOccurrence($item, $category, $product);
                        $stats['products_saved']++;
                        $stats[$existingItem === null ? 'products_created' : 'products_updated']++;
                        $this->progress($progress, $verbose, ($existingItem === null ? 'created' : 'updated').": {$product['source_url']}");
                    }

                    if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                        break 3;
                    }
                }
            }

            $stats['categories_scanned']++;

            if (! $dryRun) {
                $category->forceFill(['products_scanned_at' => now()])->save();
                $stats['categories_marked_scanned']++;
            }

            $this->progress($progress, $verbose, "  products: {$categoryProductCount}");
        }

        return $stats;
    }

    public function refreshLocalizedNames(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $progress = $options['progress'] ?? null;

        $stats = [
            'items_seen' => 0,
            'items_updated' => 0,
            'items_skipped' => 0,
            'source_pages_fetched' => 0,
        ];

        $query = PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereNotNull('source_url')
            ->where(fn ($query) => $query
                ->whereNull('name_ru')
                ->orWhere('name_ru', '')
                ->orWhereNull('name_ua')
                ->orWhere('name_ua', '')
                ->orWhereNull('raw_attributes->source_url_ru')
                ->orWhere('raw_attributes->source_url_ru', '')
                ->orWhereNull('raw_attributes->source_url_ua')
                ->orWhere('raw_attributes->source_url_ua', ''))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->get() as $item) {
            $stats['items_seen']++;
            $sourceUrl = (string) $item->source_url;
            $ruUrl = $this->localizedProductUrl($sourceUrl, 'ru');
            $ukUrl = $this->localizedProductUrl($sourceUrl, 'uk');
            $rawAttributes = PartCatalogRawAttributes::from($item);
            $updates = [];

            if (! filled($item->name_ru) && $ruUrl !== null) {
                $nameRu = $this->productNameFromUrl($ruUrl);
                $stats['source_pages_fetched']++;

                if ($nameRu !== null) {
                    $updates['name_ru'] = $nameRu;
                }

                $this->pause($sleepMs);
            }

            if (! filled($item->name_ua) && $ukUrl !== null) {
                $nameUa = $this->productNameFromUrl($ukUrl);
                $stats['source_pages_fetched']++;

                if ($nameUa !== null) {
                    $updates['name_ua'] = $nameUa;
                }

                $this->pause($sleepMs);
            }

            $rawAttributes['source_url_ru'] = $ruUrl;
            $rawAttributes['source_url_ua'] = $ukUrl;
            $updates['raw_attributes'] = array_filter($rawAttributes, fn ($value) => $value !== null && $value !== '');

            if (! $dryRun) {
                $item->forceFill($updates)->save();
            }

            $stats['items_updated']++;

            if ($progress !== null) {
                $progress("#{$item->id} {$item->part_number}: ".($updates['name_ru'] ?? $item->name_ru ?? '-'));
            }
        }

        return $stats;
    }

    protected function categoryPages(string $url, string $baseUrl, int $maxPages, int $sleepMs): array
    {
        $pages = [];
        $queue = [$this->categoryListUrl($url)];
        $seen = [];

        while ($queue !== [] && count($pages) < $maxPages) {
            $pageUrl = array_shift($queue);
            if (! is_string($pageUrl) || isset($seen[$pageUrl])) {
                continue;
            }

            $seen[$pageUrl] = true;
            $html = $this->fetch($pageUrl);
            if ($html === null) {
                continue;
            }

            $pages[] = $html;

            foreach ($this->paginationUrls($this->page($html), $baseUrl) as $paginationUrl) {
                if (! isset($seen[$paginationUrl])) {
                    $queue[] = $paginationUrl;
                }
            }

            if ($sleepMs > 0 && count($pages) < $maxPages) {
                usleep($sleepMs * 1000);
            }
        }

        return $pages;
    }

    protected function modelRootCategories(string $baseUrl, bool $dryRun): Collection
    {
        return collect(self::MODEL_ROOT_LISTINGS)
            ->map(function (array $model, string $path) use ($baseUrl, $dryRun): PartCatalogCategory {
                $sourceUrl = $this->absoluteUrl($path, $baseUrl) ?? $baseUrl.'/'.ltrim($path, '/');

                $attributes = [
                    'source' => $this->source,
                    'parent_id' => null,
                    'depth' => 0,
                    'code' => null,
                    'name' => $model['name'],
                    'model_label' => $model['name'],
                    'model_name' => $model['compatibility'],
                    'year_from' => $model['year_from'],
                    'year_to' => $model['year_to'],
                    'sort_order' => $model['sort_order'],
                    'children_scanned_at' => now(),
                ];

                if ($dryRun) {
                    return new PartCatalogCategory([
                        ...$attributes,
                        'source_url' => $sourceUrl,
                    ]);
                }

                return PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $sourceUrl],
                    $attributes
                );
            })
            ->values();
    }

    protected function categoryForProduct(array $product, PartCatalogCategory $fallbackCategory, string $baseUrl): PartCatalogCategory
    {
        $sourceUrl = (string) ($product['source_url'] ?? '');
        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn (string $segment): bool => $segment !== ''));

        if (($segments[0] ?? null) === 'ru') {
            array_shift($segments);
        }

        if (count($segments) < 2) {
            return $fallbackCategory;
        }

        array_pop($segments);
        $modelSlug = $segments[0];
        $modelPath = '/ru/'.$modelSlug.'/';
        $model = self::MODEL_ROOT_LISTINGS[$modelPath] ?? null;

        if ($model === null) {
            return $fallbackCategory;
        }

        $parent = PartCatalogCategory::query()->updateOrCreate(
            ['source_url' => $this->absoluteUrl($modelPath, $baseUrl)],
            [
                'source' => $this->source,
                'parent_id' => null,
                'depth' => 0,
                'code' => null,
                'name' => $model['name'],
                'model_label' => $model['name'],
                'model_name' => $model['compatibility'],
                'year_from' => $model['year_from'],
                'year_to' => $model['year_to'],
                'sort_order' => $model['sort_order'],
                'children_scanned_at' => now(),
            ]
        );

        $pathParts = ['ru', $modelSlug];
        foreach (array_slice($segments, 1) as $index => $segment) {
            $depth = $index + 1;
            $pathParts[] = $segment;
            [$code, $name] = $this->categoryNameFromSlug($segment, $depth);

            $parent = PartCatalogCategory::query()->updateOrCreate(
                ['source_url' => $baseUrl.'/'.implode('/', $pathParts)],
                [
                    'source' => $this->source,
                    'parent_id' => $parent->id,
                    'depth' => $depth,
                    'code' => $code,
                    'name' => $name,
                    'model_label' => $model['name'],
                    'model_name' => $model['compatibility'],
                    'year_from' => $model['year_from'],
                    'year_to' => $model['year_to'],
                    'sort_order' => $this->categorySortOrder($code),
                    'children_scanned_at' => now(),
                ]
            );
        }

        return $parent;
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = $this->http
                ->timeout(30)
                ->retry(2, 500)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,uk;q=0.8,en;q=0.7',
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

    protected function models(array $page, string $baseUrl): array
    {
        $models = [];

        foreach ($page['xpath']->query('//ul[contains(concat(" ", normalize-space(@class), " "), " navbar-nav ")]/li[contains(concat(" ", normalize-space(@class), " "), " dropdown ")]') as $modelNode) {
            if (! $modelNode instanceof DOMElement) {
                continue;
            }

            $modelLink = $page['xpath']->query('./a[contains(concat(" ", normalize-space(@class), " "), " dropdown-toggle ")]', $modelNode)->item(0);
            if (! $modelLink instanceof DOMElement) {
                continue;
            }

            $modelUrl = $this->absoluteUrl($modelLink->getAttribute('href'), $baseUrl);
            $modelName = $this->clean($modelLink->textContent);
            if ($modelUrl === null || ! str_contains(Str::lower($modelName), 'model')) {
                continue;
            }

            $categories = [];
            foreach ($page['xpath']->query('.//li[contains(@class, "head-child-")]', $modelNode) as $mainNode) {
                if (! $mainNode instanceof DOMElement) {
                    continue;
                }

                $mainLink = $page['xpath']->query('./a[contains(concat(" ", normalize-space(@class), " "), " liheader ")]', $mainNode)->item(0);
                if (! $mainLink instanceof DOMElement) {
                    continue;
                }

                $mainUrl = $this->absoluteUrl($mainLink->getAttribute('href'), $baseUrl);
                $mainName = $this->clean($mainLink->textContent);
                if ($mainUrl === null || $mainName === '') {
                    continue;
                }

                $children = [];
                foreach ($page['xpath']->query('./ul[contains(concat(" ", normalize-space(@class), " "), " visible-xs ")]/li/a[@href]', $mainNode) as $childLink) {
                    if (! $childLink instanceof DOMElement) {
                        continue;
                    }

                    $childUrl = $this->absoluteUrl($childLink->getAttribute('href'), $baseUrl);
                    $childName = $this->clean($childLink->textContent);

                    if ($childUrl !== null && $childName !== '') {
                        $children[$childUrl] = ['url' => $childUrl, 'name' => $childName];
                    }
                }

                $categories[$mainUrl] = [
                    'url' => $mainUrl,
                    'name' => $mainName,
                    'children' => array_values($children),
                ];
            }

            $models[$modelUrl] = [
                'url' => $modelUrl,
                'name' => $modelName,
                'categories' => array_values($categories),
            ];
        }

        return array_values($models);
    }

    protected function productsFromCategoryPage(array $page, string $baseUrl): array
    {
        $products = [];

        foreach ($page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " product-layout ")]//div[contains(concat(" ", normalize-space(@class), " "), " product-thumb ")]') as $productNode) {
            if (! $productNode instanceof DOMElement) {
                continue;
            }

            $linkNode = $page['xpath']->query('.//div[contains(concat(" ", normalize-space(@class), " "), " h4 ")]//a[@href]', $productNode)->item(0);
            if (! $linkNode instanceof DOMElement) {
                continue;
            }

            $sourceUrl = $this->absoluteUrl($linkNode->getAttribute('href'), $baseUrl);
            if ($sourceUrl === null) {
                continue;
            }

            $title = $this->clean($linkNode->textContent);
            $partNumber = $this->normalizePartNumber($this->fieldText($page, $productNode, 'model_'));
            $sku = $this->fieldText($page, $productNode, 'upc_');
            $condition = $this->fieldText($page, $productNode, 'condition');
            $description = $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " des ")]', $productNode)->item(0)?->textContent);
            $imageNode = $page['xpath']->query('.//img[@src]', $productNode)->item(0);
            $imageUrls = $this->productCardImageUrls($page, $productNode, $baseUrl);
            $priceText = $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " price-new ")]', $productNode)->item(0)?->textContent)
                ?: $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " price ")]', $productNode)->item(0)?->textContent);
            $stockNode = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " stock ")]', $productNode)->item(0);

            $products[$sourceUrl] = [
                'source_url' => $sourceUrl,
                'source_url_ru' => $this->localizedProductUrl($sourceUrl, 'ru'),
                'source_url_ua' => $this->localizedProductUrl($sourceUrl, 'uk'),
                'part_number' => $partNumber ?: $this->partNumberFromTitle($title),
                'name' => $this->productName($title, $partNumber),
                'name_ru' => $this->isRussianUrl($sourceUrl) ? $this->productName($title, $partNumber) : null,
                'name_ua' => $this->isRussianUrl($sourceUrl) ? null : $this->productName($title, $partNumber),
                'sku' => $sku,
                'condition' => $condition,
                'description' => $description,
                'availability' => $stockNode instanceof DOMElement ? $this->clean($stockNode->getAttribute('title')) : null,
                'price_amount' => app(ExchangeRateService::class)->catalogPriceToUsd($this->priceAmount($priceText), $this->priceCurrency($priceText)),
                'currency' => $this->priceAmount($priceText) ? 'USD' : null,
                'image_url' => $imageNode instanceof DOMElement ? $this->absoluteUrl($imageNode->getAttribute('src'), $baseUrl) : null,
                'image_urls' => $imageUrls,
            ];
        }

        return array_values($products);
    }

    protected function productCardImageUrls(array $page, DOMElement $productNode, string $baseUrl): array
    {
        $urls = [];

        foreach ($page['xpath']->query('.//div[contains(concat(" ", normalize-space(@class), " "), " image ")]//img[@src or @data-zoom-image]', $productNode) as $imageNode) {
            if (! $imageNode instanceof DOMElement) {
                continue;
            }

            foreach (['data-zoom-image', 'src'] as $attribute) {
                $url = $this->absoluteUrl($imageNode->getAttribute($attribute), $baseUrl);

                if ($url !== null && $this->isDkPartsProductImageUrl($url)) {
                    $urls[$url] = $url;
                }
            }
        }

        return array_values($urls);
    }

    protected function fieldText(array $page, DOMElement $productNode, string $class): ?string
    {
        $text = $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]', $productNode)->item(0)?->textContent);
        $text = preg_replace('/^(Код товара|Модель|Состояние):\s*/u', '', $text) ?? $text;

        return $text === '' ? null : $text;
    }

    protected function productPayload(PartCatalogCategory $category, array $product, ?PartCatalogItem $existingItem = null): array
    {
        [$mainCategory, $subcategory, $nodeName] = $this->productCategoryPath($category);

        $name = $product['name'] ?: ($product['part_number'] ?: 'DK-Parts product');
        $rawAttributes = array_filter([
            'category_source_url' => $category->source_url,
            'sku' => $product['sku'],
            'image_url' => $product['image_url'],
            'image_urls' => array_values(array_unique(array_filter((array) ($product['image_urls'] ?? [])))),
            'remote_image_urls' => array_values(array_unique(array_filter((array) ($product['image_urls'] ?? [])))),
            'description' => $product['description'],
            'source_url_ru' => $product['source_url_ru'] ?? null,
            'source_url_ua' => $product['source_url_ua'] ?? null,
        ]);

        if ($existingItem !== null) {
            $existingRawAttributes = PartCatalogRawAttributes::from($existingItem);
            $existingLocalImages = collect((array) ($existingRawAttributes['image_urls'] ?? []))
                ->filter(fn ($url): bool => is_string($url) && str_starts_with($url, 'competitor-catalog/dkparts/'))
                ->values()
                ->all();

            if ($existingLocalImages !== []) {
                $rawAttributes['image_urls'] = $existingLocalImages;
                $rawAttributes['image_url'] = $existingLocalImages[0];
                $rawAttributes['remote_image_urls'] = (array) ($existingRawAttributes['remote_image_urls'] ?? ($rawAttributes['remote_image_urls'] ?? []));
                $rawAttributes['remote_image_url'] = $existingRawAttributes['remote_image_url'] ?? ($rawAttributes['remote_image_urls'][0] ?? null);
            }
        }

        return [
            'part_catalog_category_id' => $category->id,
            'source' => $this->source,
            'part_number' => $product['part_number'],
            'name' => $name,
            'name_ru' => $product['name_ru'] ?? null,
            'name_ua' => $product['name_ua'] ?? null,
            'price_amount' => $product['price_amount'],
            'currency' => $product['currency'],
            'model_label' => $category->model_label,
            'model_name' => $category->model_name,
            'year_from' => $category->year_from,
            'year_to' => $category->year_to,
            'main_category_code' => $mainCategory?->code,
            'main_category_name' => $mainCategory?->name,
            'subcategory_code' => $subcategory?->code,
            'subcategory_name' => $subcategory?->name,
            'node_name' => $nodeName,
            'compatibility_text' => $this->compatibilityTextForCategory($category),
            'condition' => $product['condition'],
            'quality' => null,
            'availability' => $product['availability'],
            'raw_attributes' => array_filter($rawAttributes),
            'source_updated_at' => now(),
        ];
    }

    protected function productCategoryPath(PartCatalogCategory $category): array
    {
        $chain = collect([$category]);
        $current = $category;

        while ($current->parent !== null) {
            $current = $current->parent;
            $chain->prepend($current);
        }

        $byDepth = $chain->keyBy(fn (PartCatalogCategory $item): int => (int) $item->depth);
        $deepest = $chain->last();

        return [
            $byDepth->get(1),
            $byDepth->get(2),
            ((int) ($deepest?->depth ?? 0)) >= 3 ? $deepest->name : null,
        ];
    }

    protected function compatibilityTextForCategory(PartCatalogCategory $category): string
    {
        foreach (self::MODEL_ROOT_LISTINGS as $model) {
            if (($model['name'] ?? null) === $category->model_label || ($model['label'] ?? null) === $category->model_label) {
                return $model['compatibility'];
            }
        }

        return (string) ($category->model_name ?: $category->model_label);
    }

    protected function saveOccurrence(PartCatalogItem $item, PartCatalogCategory $category, array $product): void
    {
        $productUrl = (string) ($product['source_url'] ?? $item->source_url);
        if ($productUrl === '') {
            return;
        }

        $occurrenceKey = hash('sha256', collect([
            $this->source,
            $category->source_url,
            $productUrl,
        ])->filter(fn (mixed $value): bool => $value !== null && $value !== '')->implode('|'));

        PartCatalogItemOccurrence::query()->updateOrCreate(
            ['occurrence_key' => $occurrenceKey],
            [
                'part_catalog_item_id' => $item->id,
                'part_catalog_category_id' => $category->id,
                'source' => $this->source,
                'page_url' => $category->source_url,
                'product_url' => $productUrl,
                'part_number' => $product['part_number'] ?? $item->part_number,
                'name' => $product['name'] ?? $item->name,
                'raw_attributes' => array_filter([
                    'listing_category_url' => $category->source_url,
                    'image_url' => $product['image_url'] ?? null,
                ]),
            ]
        );
    }

    protected function withProductDetails(array $product, string $baseUrl, array &$stats, int $sleepMs): array
    {
        $details = $this->productDetailsFromUrl((string) $product['source_url'], $baseUrl, $stats);
        $this->pause($sleepMs);

        if ($details === null) {
            return $product;
        }

        foreach ([
            'part_number',
            'name',
            'name_ru',
            'name_ua',
            'sku',
            'condition',
            'description',
            'availability',
            'price_amount',
            'currency',
            'image_url',
        ] as $key) {
            if (filled($details[$key] ?? null)) {
                $product[$key] = $details[$key];
            }
        }

        $imageUrls = collect((array) ($product['image_urls'] ?? []))
            ->merge((array) ($details['image_urls'] ?? []))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($imageUrls !== []) {
            $product['image_urls'] = $imageUrls;
            $product['image_url'] = $imageUrls[0];
        }

        return $product;
    }

    protected function productDetailsFromUrl(string $url, string $baseUrl, array &$stats): ?array
    {
        $html = $this->fetch($url);

        if ($html === null) {
            $stats['product_detail_pages_failed']++;

            return null;
        }

        $stats['product_detail_pages_fetched']++;

        $page = $this->page($html);
        $title = $this->clean($page['xpath']->query('//*[@id="content"]//h1')->item(0)?->textContent)
            ?: $this->clean($page['xpath']->query('//h1')->item(0)?->textContent);
        $partNumber = $this->normalizePartNumber($this->clean($page['xpath']->query('//*[@id="content"]//*[contains(concat(" ", normalize-space(@class), " "), " product_model ")]//*[@itemprop="model"]')->item(0)?->textContent))
            ?: $this->partNumberFromTitle($title);
        $priceText = $this->clean($page['xpath']->query('//*[@id="formated_special"]')->item(0)?->textContent)
            ?: $this->clean($page['xpath']->query('//*[@id="formated_price"]')->item(0)?->textContent);
        $imageUrls = $this->productDetailImageUrls($page, $baseUrl);
        $stats['product_images_found'] += count($imageUrls);

        return [
            'part_number' => $partNumber,
            'name' => $title !== '' ? $this->productName($title, $partNumber) : null,
            'name_ru' => $this->isRussianUrl($url) && $title !== '' ? $this->productName($title, $partNumber) : null,
            'name_ua' => ! $this->isRussianUrl($url) && $title !== '' ? $this->productName($title, $partNumber) : null,
            'sku' => $this->clean($page['xpath']->query('//*[@id="content"]//*[contains(concat(" ", normalize-space(@class), " "), " review-line ")]//*[@itemprop="model"]')->item(0)?->textContent) ?: null,
            'condition' => $this->clean($page['xpath']->query('//*[@id="content"]//*[contains(concat(" ", normalize-space(@class), " "), " list-custom ")]//*[contains(concat(" ", normalize-space(@class), " "), " condition ")]/span')->item(0)?->textContent) ?: null,
            'description' => $this->productDetailDescription($page),
            'availability' => $this->clean($page['xpath']->query('//*[@id="content"]//*[contains(concat(" ", normalize-space(@class), " "), " not_availabel ")]/span')->item(0)?->textContent) ?: null,
            'price_amount' => app(ExchangeRateService::class)->catalogPriceToUsd($this->priceAmount($priceText), $this->priceCurrency($priceText)),
            'currency' => $this->priceAmount($priceText) ? 'USD' : null,
            'image_url' => $imageUrls[0] ?? null,
            'image_urls' => $imageUrls,
        ];
    }

    protected function productDetailImageUrls(array $page, string $baseUrl): array
    {
        $urls = [];
        $queries = [
            '//*[@id="one-image"]//a[@href]',
            '//*[@id="one-image"]//img[@data-zoom-image]',
            '//*[@id="one-image"]//img[@src]',
            '//*[@id="image-additional"]//a[@href]',
            '//*[@id="image-additional"]//img[@data-zoom-image]',
            '//*[@id="image-additional"]//img[@src]',
            '//meta[@property="og:image" or @name="twitter:image"]',
        ];

        foreach ($queries as $query) {
            foreach ($page['xpath']->query($query) as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                foreach (['href', 'data-zoom-image', 'src', 'content'] as $attribute) {
                    $url = $this->absoluteUrl($node->getAttribute($attribute), $baseUrl);

                    if ($url !== null && $this->isDkPartsProductImageUrl($url) && ! isset($urls[$this->productImageIdentity($url)])) {
                        $urls[$this->productImageIdentity($url)] = $url;
                    }
                }
            }
        }

        return array_values($urls);
    }

    protected function productDetailDescription(array $page): ?string
    {
        $parts = [];

        foreach ($page['xpath']->query('//*[@id="content"]//*[contains(concat(" ", normalize-space(@class), " "), " product-info ")]//p[not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " spanel ")])]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text = $this->clean($node->textContent);
            $lower = Str::lower($text);

            if (str_contains($lower, 'нашли дешевле') || str_contains($lower, 'мы рассмотрим возможность')) {
                continue;
            }

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    protected function isDkPartsProductImageUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_contains($host, 'dk-parts.com.ua')
            && str_contains($path, '/image/')
            && preg_match('/\.(?:jpe?g|png|webp)(?:$|\?)/i', $path) === 1
            && ! str_contains($path, '/logo')
            && ! str_contains($path, 'placeholder')
            && ! str_contains($path, 'no-photo')
            && ! str_contains($path, 'no_photo')
            && ! str_contains($path, '/flags/')
            && ! str_contains($path, '/custom/');
    }

    protected function productImageIdentity(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return (string) preg_replace('/-\d+x\d+(?=\.(?:jpe?g|png|webp)$)/i', '', $path);
    }

    protected function withLocalizedNamesOnSave(array $product, ?PartCatalogItem $existingItem, array &$stats, int $sleepMs): array
    {
        foreach (['ru' => 'name_ru', 'ua' => 'name_ua'] as $locale => $column) {
            if (filled($product[$column] ?? null)) {
                continue;
            }

            if ($existingItem !== null && filled($existingItem->{$column})) {
                $product[$column] = $existingItem->{$column};

                continue;
            }

            $url = $product['source_url_'.$locale] ?? null;
            if (! filled($url)) {
                continue;
            }

            $name = $this->productNameFromUrl((string) $url);
            $stats['localized_name_pages_fetched']++;

            if ($name !== null) {
                $product[$column] = $name;
                $stats['localized_names_filled']++;
            }

            $this->pause($sleepMs);
        }

        return $product;
    }

    protected function productNameFromUrl(string $url): ?string
    {
        $html = $this->fetch($url);

        if ($html === null) {
            return null;
        }

        $page = $this->page($html);
        $node = $page['xpath']->query('//h1')->item(0)
            ?: $page['xpath']->query('//meta[@property="og:title"]')->item(0)
            ?: $page['xpath']->query('//title')->item(0);
        $name = $node instanceof DOMElement
            ? $this->clean($node->getAttribute('content') ?: $node->textContent)
            : '';

        return $name !== '' ? $this->productName($name, null) : null;
    }

    protected function isUsableLocalizedText(?string $value): bool
    {
        $value = $this->clean((string) $value);

        return $value !== ''
            && preg_match('/[А-Яа-яЁёІіЇїЄєҐґ]/u', $value) === 1;
    }

    protected function paginationUrls(array $page, string $baseUrl): array
    {
        $urls = [];

        foreach ($page['xpath']->query('//ul[contains(concat(" ", normalize-space(@class), " "), " pagination ")]//a[@href]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($node->getAttribute('href'), $baseUrl);
            if ($url !== null) {
                $urls[$url] = $url;
            }
        }

        return array_values($urls);
    }

    protected function categoryListUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['limit'] = '10000';

        $rebuilt = $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '');
        $queryString = http_build_query($query);

        return $queryString === '' ? $rebuilt : $rebuilt.'?'.$queryString;
    }

    protected function modelMetadata(string $label, ?string $url = null): array
    {
        $path = is_string($url) ? (string) parse_url($url, PHP_URL_PATH) : '';

        foreach (self::MODEL_ROOT_LISTINGS as $rootPath => $model) {
            if ($path !== '' && str_ends_with(rtrim($path, '/').'/', $rootPath)) {
                return $model;
            }

            if ($label === $model['name']) {
                return $model;
            }
        }

        return [
            'name' => $label,
            'compatibility' => $label,
            'year_from' => null,
            'year_to' => null,
            'sort_order' => $this->modelSortOrder($label),
        ];
    }

    protected function sourceCategoryName(string $modelLabel, int $depth, ?string $code, ?string $fallback): ?string
    {
        if ($code === null) {
            return $fallback;
        }

        return PartCatalogCategory::query()
            ->where('source', 'tcarservice')
            ->where('model_label', $modelLabel)
            ->where('depth', $depth)
            ->where('code', $code)
            ->value('name')
            ?: PartCatalogCategory::query()
                ->where('source', 'tcarservice')
                ->where('depth', $depth)
                ->where('code', $code)
                ->value('name')
            ?: $fallback;
    }

    protected function splitCodeName(?string $value): array
    {
        $value = $this->clean($value);

        if (preg_match('/^(\d+)\s*-\s*(.+)$/u', $value, $matches) === 1) {
            return [$matches[1], $this->clean($matches[2])];
        }

        return [null, $value ?: null];
    }

    protected function categoryNameFromSlug(string $slug, int $depth): array
    {
        $slug = trim($slug, '/');
        $code = null;
        $nameSlug = $slug;

        if (preg_match('/^(\d{2,4})-(.+)$/', $slug, $matches) === 1) {
            $code = $matches[1];
            $nameSlug = $matches[2];
        }

        $separatorToken = ' DKPARTSSEPARATOR ';
        $name = str_replace('---', $separatorToken, $nameSlug);
        $name = str_replace('-', ' ', $name);
        $name = str_replace($separatorToken, ' - ', $name);
        $name = $this->clean(Str::title($name));

        if ($depth === 1) {
            $name = Str::upper($name);
        }

        return [$code, $code !== null ? "{$code} - {$name}" : $name];
    }

    protected function productName(string $title, ?string $partNumber): string
    {
        if ($partNumber !== null) {
            $title = preg_replace('/\s+'.preg_quote($partNumber, '/').'\s*$/iu', '', $title) ?? $title;
        }

        return $this->clean($title);
    }

    protected function partNumberFromTitle(string $title): ?string
    {
        if (preg_match('/\b([0-9]{6,}[A-ZА-Я0-9.-]*-[A-ZА-Я0-9.-]+)\b/iu', $title, $matches) === 1) {
            return $this->normalizePartNumber($matches[1]);
        }

        return null;
    }

    protected function normalizePartNumber(?string $value): ?string
    {
        return PartNumberNormalizer::normalize($value);
    }

    protected function priceAmount(string $priceText): ?string
    {
        if (preg_match('/([0-9]+(?:[.,][0-9]+)?)/', $priceText, $matches) !== 1) {
            return null;
        }

        return number_format((float) str_replace(',', '.', $matches[1]), 2, '.', '');
    }

    protected function priceCurrency(string $priceText): string
    {
        $lower = Str::lower($priceText);

        return str_contains($priceText, '$') || str_contains($lower, 'usd') ? 'USD' : 'UAH';
    }

    protected function modelSortOrder(string $label): int
    {
        $order = [
            'Model S до 2016',
            'Model S после 2016',
            'Model S Plaid',
            'Tesla Model X',
            'Model X Plaid',
            'Tesla Model 3',
            'TESLA MODEL Y',
        ];

        $position = array_search($label, $order, true);

        return $position === false ? 999 : $position + 1;
    }

    protected function categorySortOrder(?string $code): int
    {
        return $code === null ? 0 : (int) $code;
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
            return strtok($url, '#') ?: null;
        }

        return $baseUrl.'/'.ltrim((string) strtok($url, '#'), '/');
    }

    protected function localizedProductUrl(string $url, string $locale): ?string
    {
        if ($locale === 'ru') {
            if ($this->isRussianUrl($url)) {
                return $url;
            }

            return preg_replace('#://([^/]+)/#', '://$1/ru/', $url, 1) ?: $url;
        }

        if ($locale === 'uk') {
            return preg_replace('#://([^/]+)/ru/#', '://$1/', $url, 1) ?: $url;
        }

        return null;
    }

    protected function isRussianUrl(string $url): bool
    {
        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/ru/');
    }

    protected function pause(int $sleepMs): void
    {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($verbose && $progress !== null) {
            $progress($message);
        }
    }
}
