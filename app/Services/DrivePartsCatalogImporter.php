<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Models\PartCatalogItemZone;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Support\PartCatalogRawAttributes;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DrivePartsCatalogImporter
{
    protected string $source = 'driveparts';

    protected array $localizedCategoryPathCache = [];

    protected const HIGH_RES_IMAGE_SIZE_SEGMENT = '1280x1706l80mc100';

    protected const PREFERRED_IMAGE_SIZE_SEGMENT = '450x600l80mc100';

    public const PLACEHOLDER_IMAGE_PATH = 'driveparts/placeholder.svg';

    protected const PLACEHOLDER_IMAGE_STEMS = [
        '35938788866351',
        '32578632436198',
        '65112127046566',
        '63823657639696',
        '44052052692034',
        '21836359960804',
    ];

    protected const PLACEHOLDER_IMAGE_SHA256_HASHES = [
        '6d503421b18c9f299b8173ad54144224ef17f79b262ec469e0a40f1a4ef15969',
        'e2445a8dcb74cb3da55bfd58f9b23a414d48de7b8e842f03179bc04031be48d5',
    ];

    protected const CHALLENGE_COOKIE = 'ea711ddd5b297885600ff1df0ef114b145ad0fa0fc6e6d02d637fbc6f4eb4666';

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://drive-parts.com.ua'), '/');
        $startUrl = $this->absoluteUrl((string) ($options['start_url'] ?? '/ru/kataloh/'), $baseUrl);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $recreate = (bool) ($options['recreate'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'source_pages_fetched' => 0,
            'models_saved' => 0,
            'main_categories_saved' => 0,
            'subcategories_saved' => 0,
            'schema_pages_saved' => 0,
            'localized_category_pages_fetched' => 0,
            'categories_deleted' => 0,
        ];

        $html = $this->fetch($startUrl);
        if ($html === null) {
            return $stats;
        }

        $stats['source_pages_fetched']++;
        $page = $this->page($html);
        $models = $this->models($page, $baseUrl);
        $localizedCategoryTails = $this->localizedCategoryTails($models, $baseUrl, $stats, $progress, $verbose);

        if ($recreate && ! $dryRun) {
            $stats['categories_deleted'] = PartCatalogCategory::query()
                ->where('source', $this->source)
                ->count();
            PartCatalogCategory::query()
                ->where('source', $this->source)
                ->delete();
        }

        foreach ($models as $model) {
            [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->canonicalModel($model['name']);
            $this->progress($progress, $verbose, "Model: {$modelLabel}");

            $modelCategory = null;

            $mainCategoriesByUrl = [];
            $subcategoriesByUrl = [];

            foreach ($model['subcategories'] as $subcategory) {
                $subcategoryUrl = $this->canonicalCategoryUrl($subcategory['url'], $baseUrl);
                if ($subcategoryUrl === null) {
                    continue;
                }

                $mainUrl = $this->mainCategoryUrl($subcategoryUrl);
                $mainCode = $this->mainCodeFromSubcategoryCode($subcategory['code']);
                $mainCategory = $mainCategoriesByUrl[$mainUrl] ?? null;
                $subcategoryModel = $subcategoriesByUrl[$subcategoryUrl] ?? null;

                foreach ($subcategory['children'] as $child) {
                    $childUrl = $this->canonicalCategoryUrl($child['url'], $baseUrl);
                    if ($childUrl === null) {
                        continue;
                    }

                    $tailKey = $this->categoryPathTailKey($childUrl);
                    $pathRu = array_merge([$this->localizedModelCategoryName($model['name'])], $localizedCategoryTails['ru'][$tailKey] ?? []);
                    $pathUa = array_merge([$this->localizedModelCategoryName($model['name'])], $localizedCategoryTails['ua'][$tailKey] ?? []);

                    $modelNameRu = $pathRu[0] ?? $modelLabel;
                    $modelNameUa = $pathUa[0] ?? $modelNameRu;
                    $mainRu = $pathRu[1] ?? trim(($mainCode ? $mainCode.' - ' : '').$this->mainNameFromUrl($mainUrl));
                    $mainUa = $pathUa[1] ?? $mainRu;
                    $subRu = $pathRu[2] ?? trim(($subcategory['code'] ? $subcategory['code'].' - ' : '').$subcategory['name']);
                    $subUa = $pathUa[2] ?? $subRu;
                    $childRu = $pathRu[3] ?? $child['name'];
                    $childUa = $pathUa[3] ?? $childRu;

                    if ($modelCategory === null && ! $dryRun) {
                        $modelCategory = PartCatalogCategory::query()->updateOrCreate(
                            ['source_url' => $this->canonicalCategoryUrl($model['url'], $baseUrl)],
                            [
                                'source' => $this->source,
                                'parent_id' => null,
                                'depth' => 0,
                                'code' => null,
                                'name' => $modelNameRu,
                                'name_en' => $modelLabel,
                                'name_ru' => $modelNameRu,
                                'name_ua' => $modelNameUa,
                                'model_label' => $modelLabel,
                                'model_name' => $modelName,
                                'year_from' => $yearFrom,
                                'year_to' => $yearTo,
                                'sort_order' => $this->modelSortOrder($modelLabel),
                                'children_scanned_at' => now(),
                            ]
                        );
                        $stats['models_saved']++;
                    }

                    if ($mainCategory === null && ! $dryRun) {
                        [$mainCodeFromPath, $mainNameRu] = $this->splitCodeName($mainRu);
                        [, $mainNameUa] = $this->splitCodeName($mainUa);
                        $mainCode = $mainCodeFromPath ?: $mainCode;
                        $mainCategory = PartCatalogCategory::query()->updateOrCreate(
                            ['source_url' => $mainUrl],
                            [
                                'source' => $this->source,
                                'parent_id' => $modelCategory?->id,
                                'depth' => 1,
                                'code' => $mainCode,
                                'name' => $mainNameRu ?: $mainRu,
                                'name_en' => $this->mainNameFromUrl($mainUrl),
                                'name_ru' => $mainNameRu ?: $mainRu,
                                'name_ua' => $mainNameUa ?: $mainUa,
                                'model_label' => $modelLabel,
                                'model_name' => $modelName,
                                'year_from' => $yearFrom,
                                'year_to' => $yearTo,
                                'sort_order' => $this->categorySortOrder($mainCode),
                                'children_scanned_at' => now(),
                            ]
                        );
                        $mainCategoriesByUrl[$mainUrl] = $mainCategory;
                        $stats['main_categories_saved']++;
                    }

                    if ($subcategoryModel === null && ! $dryRun) {
                        [$subcategoryCode, $subcategoryNameRu] = $this->splitCodeName($subRu);
                        [, $subcategoryNameUa] = $this->splitCodeName($subUa);
                        $subcategoryCode = $subcategoryCode ?: $subcategory['code'];
                        $subcategoryModel = PartCatalogCategory::query()->updateOrCreate(
                            ['source_url' => $subcategoryUrl],
                            [
                                'source' => $this->source,
                                'parent_id' => $mainCategory?->id,
                                'depth' => 2,
                                'code' => $subcategoryCode,
                                'name' => $subcategoryNameRu ?: $subRu,
                                'name_en' => $subcategory['name'],
                                'name_ru' => $subcategoryNameRu ?: $subRu,
                                'name_ua' => $subcategoryNameUa ?: $subUa,
                                'model_label' => $modelLabel,
                                'model_name' => $modelName,
                                'year_from' => $yearFrom,
                                'year_to' => $yearTo,
                                'sort_order' => $this->categorySortOrder($subcategoryCode),
                                'children_scanned_at' => now(),
                            ]
                        );
                        $subcategoriesByUrl[$subcategoryUrl] = $subcategoryModel;
                        $stats['subcategories_saved']++;
                    }

                    if (! $dryRun) {
                        PartCatalogCategory::query()->updateOrCreate(
                            ['source_url' => $childUrl],
                            [
                                'source' => $this->source,
                                'parent_id' => $subcategoryModel?->id,
                                'depth' => 3,
                                'code' => null,
                                'name' => $childRu,
                                'name_en' => $child['name'],
                                'name_ru' => $childRu,
                                'name_ua' => $childUa,
                                'model_label' => $modelLabel,
                                'model_name' => $modelName,
                                'year_from' => $yearFrom,
                                'year_to' => $yearTo,
                                'sort_order' => 0,
                                'children_scanned_at' => now(),
                                'products_scanned_at' => null,
                            ]
                        );
                        $stats['schema_pages_saved']++;
                    }
                }
            }
        }

        return $stats;
    }

    public function importProducts(array $options = []): array
    {
        if ((bool) ($options['all_products'] ?? false)) {
            return $this->importAllProducts($options);
        }

        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://drive-parts.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $rescan = (bool) ($options['rescan'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxCategories = max(0, (int) ($options['max_categories'] ?? 0));
        $maxPages = max(0, (int) ($options['max_pages'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $selectedCategory = $this->productImportCategory((string) ($options['category'] ?? ''));
        $fetchLocalizedNames = ! (bool) ($options['skip_localized'] ?? false);

        $stats = [
            'source_pages_fetched' => 0,
            'pages_scanned' => 0,
            'categories_scanned' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'product_ru_pages_fetched' => 0,
            'product_ua_pages_fetched' => 0,
            'product_category_occurrences_saved' => 0,
            'categories_marked_scanned' => 0,
            'category_not_found' => 0,
        ];

        if ((string) ($options['category'] ?? '') !== '' && $selectedCategory === null) {
            $stats['category_not_found'] = 1;

            return $stats;
        }

        $query = PartCatalogCategory::query()
            ->with('parent.parent')
            ->where('source', $this->source)
            ->where('depth', 3)
            ->orderBy('id');

        if ($selectedCategory !== null) {
            $query->whereKey($selectedCategory->id);
        } elseif (! $rescan) {
            $query->whereNull('products_scanned_at');
        }

        if ($maxCategories > 0) {
            $query->limit($maxCategories);
        }

        foreach ($query->get() as $category) {
            $this->progress($progress, $verbose, "Category #{$category->id}: {$category->name}");

            $stats['categories_scanned']++;
            $url = (string) $category->source_url;
            $seenPages = [];
            $seenUrls = [];

            while ($url !== '' && ! isset($seenPages[$url])) {
                if ($maxPages > 0 && $stats['pages_scanned'] >= $maxPages) {
                    break 2;
                }

                $seenPages[$url] = true;
                $html = $this->fetch($url);
                if ($html === null) {
                    break;
                }

                $stats['source_pages_fetched']++;
                $stats['pages_scanned']++;
                $page = $this->page($html);

                foreach ($this->productsFromCategoryPage($page, $baseUrl) as $product) {
                    if (isset($seenUrls[$product['source_url']])) {
                        continue;
                    }

                    $seenUrls[$product['source_url']] = true;
                    $stats['products_found']++;
                    if ($fetchLocalizedNames) {
                        $product = $this->withLocalizedProductNames($product, $baseUrl, $stats);
                    }

                    if (! $dryRun) {
                        $savedItem = PartCatalogItem::query()->updateOrCreate(
                            ['source_url' => $product['source_url']],
                            $this->productPayload($category, $product)
                        );
                        $this->syncProductCategoryOccurrences($savedItem, $product + [
                            'category_source_url' => $category->source_url,
                        ], [$category->id]);
                        $stats['products_saved']++;
                        $stats['product_category_occurrences_saved']++;
                    }

                    if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                        break 3;
                    }
                }

                $url = $this->nextPageUrl($page, $baseUrl) ?? '';

                if ($sleepMs > 0 && $url !== '') {
                    usleep($sleepMs * 1000);
                }
            }

            if (! $dryRun) {
                $category->forceFill(['products_scanned_at' => now()])->save();
                $stats['categories_marked_scanned']++;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }

    protected function productImportCategory(string $category): ?PartCatalogCategory
    {
        $category = trim($category);
        if ($category === '') {
            return null;
        }

        if (ctype_digit($category)) {
            return PartCatalogCategory::query()
                ->where('source', $this->source)
                ->where('depth', 3)
                ->whereKey((int) $category)
                ->first();
        }

        $sourceUrl = $this->canonicalCategoryUrl($category, 'https://drive-parts.com.ua');
        if ($sourceUrl !== null) {
            $match = PartCatalogCategory::query()
                ->where('source', $this->source)
                ->where('depth', 3)
                ->whereIn('source_url', $this->categoryUrlVariants($sourceUrl))
                ->first();

            if ($match !== null) {
                return $match;
            }
        }

        $path = trim((string) (parse_url($category, PHP_URL_PATH) ?: $category), '/');
        $path = Str::after($path, 'admin/driveparts-catalog/');
        $id = app(PartCatalogCategoryRouteService::class)->categoryIdByCatalogPath($this->source, $path);

        return $id > 0
            ? PartCatalogCategory::query()
                ->where('source', $this->source)
                ->where('depth', 3)
                ->whereKey($id)
                ->first()
            : null;
    }

    public function importAllProducts(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://drive-parts.com.ua'), '/');
        $startUrl = $this->absoluteUrl((string) ($options['start_url'] ?? '/ru/vsi-tovary/'), $baseUrl);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxPages = max(0, (int) ($options['max_pages'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));

        $stats = [
            'source_pages_fetched' => 0,
            'pages_scanned' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'product_ru_pages_fetched' => 0,
            'product_ua_pages_fetched' => 0,
            'product_listing_extra_pages_fetched' => 0,
            'product_listing_extra_pages_skipped' => 0,
            'product_compatibility_pages_fetched' => 0,
            'product_detail_pages_skipped' => 0,
            'product_images_downloaded' => 0,
        ];

        if ($startUrl === null) {
            return $stats;
        }

        $url = $startUrl;
        $seenPages = [];
        $seenProducts = [];

        while ($url !== null && ! isset($seenPages[$url])) {
            if ($maxPages > 0 && $stats['pages_scanned'] >= $maxPages) {
                break;
            }

            $seenPages[$url] = true;
            $this->progress($progress, $verbose, 'All products page #'.($stats['pages_scanned'] + 1).": {$url}");

            $html = $this->fetch($url);
            if ($html === null) {
                break;
            }

            $stats['source_pages_fetched']++;
            $stats['pages_scanned']++;
            $page = $this->page($html);
            $pageProducts = $this->productsFromCategoryPage($page, $baseUrl);
            $this->progress($progress, $verbose, 'products: '.count($pageProducts));

            foreach ($pageProducts as $product) {
                if (isset($seenProducts[$product['source_url']])) {
                    continue;
                }

                $seenProducts[$product['source_url']] = true;
                $stats['products_found']++;
                $this->progress($progress, $verbose, "Product #{$stats['products_found']}: {$product['part_number']}");
                $existingItem = $this->existingProductItem($product);
                if ($this->shouldFetchListingProductDetails($existingItem, $product)) {
                    $product = $this->withListingProductDetails($product, $baseUrl, $stats);
                } else {
                    $stats['product_listing_extra_pages_skipped']++;
                }

                if ($this->shouldFetchProductDetails($existingItem)) {
                    $product = $this->withLocalizedProductNames($product, $baseUrl, $stats, fetchUa: false);
                    $product = $this->withProductPageDetails($product, $baseUrl, $stats);
                    $product['_details_fetched'] = true;
                } else {
                    $stats['product_detail_pages_skipped']++;
                    $product['_details_fetched'] = false;
                }

                if (! $dryRun) {
                    $product = $this->withDownloadedProductImages($product, $stats);
                    $savedItem = $this->saveAllProduct($product, $existingItem);
                    $stats['products_saved']++;
                    if ($savedItem->wasRecentlyCreated) {
                        $stats['products_created']++;
                        $this->progress($progress, $verbose, "created: {$product['part_number']}");
                    } else {
                        $stats['products_updated']++;
                        $this->progress($progress, $verbose, "updated: {$product['part_number']}");
                    }
                }

                if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                    break 2;
                }
            }

            $url = $this->nextPageUrl($page, $baseUrl);

            if ($sleepMs > 0 && $url !== null) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }

    public function refreshProductTranslations(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://drive-parts.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $missingRuOnly = (bool) ($options['missing_ru_only'] ?? false);

        $stats = [
            'products_seen' => 0,
            'product_ru_pages_fetched' => 0,
            'product_ua_pages_fetched' => 0,
            'products_updated' => 0,
            'name_ru_updated' => 0,
            'name_ua_updated' => 0,
        ];

        $query = PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereNotNull('source_url')
            ->orderBy('id');

        if ($missingRuOnly) {
            $query->where(function ($query): void {
                $query->whereNull('name_ru')->orWhere('name_ru', '');
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->get() as $item) {
            $stats['products_seen']++;
            $this->progress($progress, $verbose, "DriveParts item #{$item->id}: {$item->part_number}");

            $ruUrl = $this->russianProductUrl((string) $item->source_url, $baseUrl);
            $ukUrl = $this->ukrainianProductUrl((string) $item->source_url, $baseUrl);
            $nameRu = $ruUrl === null ? null : $this->productNameFromUrl($ruUrl, $item->part_number, $stats, 'product_ru_pages_fetched');
            $nameUa = $missingRuOnly || $ukUrl === null ? null : $this->productNameFromUrl($ukUrl, $item->part_number, $stats, 'product_ua_pages_fetched');
            $updates = [];

            if (! $this->isUsableRussianProductName($nameRu, $item)) {
                $nameRu = null;
            }

            if ($nameRu !== null && $nameRu !== '' && $nameRu !== $item->name_ru) {
                $updates['name_ru'] = Str::limit($nameRu, 255, '');
            }

            if ($nameUa !== null && $nameUa !== '' && $nameUa !== $item->name_ua) {
                $updates['name_ua'] = Str::limit($nameUa, 255, '');
            }

            if ($updates !== []) {
                if (! $dryRun) {
                    $item->forceFill($updates)->save();
                }

                $stats['products_updated']++;
                $stats['name_ru_updated'] += array_key_exists('name_ru', $updates) ? 1 : 0;
                $stats['name_ua_updated'] += array_key_exists('name_ua', $updates) ? 1 : 0;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }

    public function refreshProductImages(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://drive-parts.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $missingOnly = (bool) ($options['missing_only'] ?? false);
        $withCards = (bool) ($options['with_cards'] ?? false);

        $stats = [
            'products_seen' => 0,
            'products_skipped_with_images' => 0,
            'products_with_stored_remote_images' => 0,
            'products_fetched_from_cards' => 0,
            'product_pages_fetched' => 0,
            'products_without_images' => 0,
            'products_with_images' => 0,
            'product_images_downloaded' => 0,
            'products_updated' => 0,
        ];

        $query = PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereNotNull('source_url')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $item) {
            $stats['products_seen']++;

            $rawAttributes = $this->rawAttributesArray($item);
            if ($missingOnly && $this->hasDownloadedAllProductImages($rawAttributes)) {
                $stats['products_skipped_with_images']++;

                continue;
            }

            $this->progress($progress, $verbose, "DriveParts image #{$item->id}: {$item->part_number}");

            $remoteUrls = $this->remoteImageUrlsFromRawAttributes($rawAttributes);
            if ($remoteUrls !== []) {
                $stats['products_with_stored_remote_images']++;
            }

            if ($remoteUrls === [] && $withCards) {
                $url = $this->ukrainianProductUrl((string) $item->source_url, $baseUrl);
                if ($url !== null) {
                    $details = $this->productDetailsFromUrl($url, $item->part_number, $stats, 'product_pages_fetched');
                    $remoteUrls = collect((array) ($details['image_urls'] ?? []))
                        ->push($details['image_url'] ?? null)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                    if ($remoteUrls !== []) {
                        $stats['products_fetched_from_cards']++;
                    }
                }
            }

            $product = [
                'source_url' => $item->source_url,
                'part_number' => $item->part_number,
                'image_url' => $remoteUrls[0] ?? null,
                'image_urls' => $remoteUrls,
            ];

            if ($remoteUrls === []) {
                $stats['products_without_images']++;

                continue;
            }

            if (! $dryRun) {
                $product = $this->withDownloadedProductImages($product, $stats);
                if ($this->saveProductImages($item, $product)) {
                    $stats['products_updated']++;
                }
            }

            $stats['products_with_images']++;

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = $this->httpRequest(['challenge_passed' => self::CHALLENGE_COOKIE])->get($url);

            if ($response->ok() && ! str_contains($response->body(), 'challenge_passed=')) {
                return $response->body();
            }

            $cookies = $this->cookiesFromHeaders((array) $response->header('Set-Cookie'));
            $cookies['challenge_passed'] = $this->challengeCookieFromBody($response->body()) ?? self::CHALLENGE_COOKIE;

            $response = $this->httpRequest($cookies)->get($url);

            return $response->ok() && ! str_contains($response->body(), 'challenge_passed=')
                ? $response->body()
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function httpRequest(array $cookies = [])
    {
        return $this->http
            ->timeout(30)
            ->retry(2, 500)
            ->withHeaders($this->requestHeaders($cookies));
    }

    protected function ajaxRequest(array $cookies = [], ?string $referer = null)
    {
        return $this->http
            ->timeout(30)
            ->retry(2, 500)
            ->withHeaders(array_filter([
                ...$this->requestHeaders($cookies),
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => $referer,
            ]));
    }

    protected function fetchAjax(string $url, ?string $referer = null): ?string
    {
        try {
            $response = $this->ajaxRequest([], $referer)->get($url);

            if ($response->ok() && ! str_contains($response->body(), 'challenge_passed=')) {
                return $response->body();
            }

            $cookies = $this->cookiesFromHeaders((array) $response->header('Set-Cookie'));
            $cookies['challenge_passed'] = $this->challengeCookieFromBody($response->body()) ?? self::CHALLENGE_COOKIE;

            $response = $this->ajaxRequest($cookies, $referer)->get($url);

            return $response->ok() && ! str_contains($response->body(), 'challenge_passed=')
                ? $response->body()
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function requestHeaders(array $cookies = []): array
    {
        $cookieHeader = collect($cookies)
            ->map(fn (string $value, string $name): string => "{$name}={$value}")
            ->implode('; ');

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept-Language' => 'ru-RU,ru;q=0.9,uk;q=0.8,en;q=0.7',
        ];

        if ($cookieHeader !== '') {
            $headers['Cookie'] = $cookieHeader;
        }

        return $headers;
    }

    protected function challengeCookieFromBody(string $body): ?string
    {
        if (preg_match('/defaultHash\s*=\s*"([^"]+)"/', $body, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function cookiesFromHeaders(array $headers): array
    {
        $cookies = [];

        foreach ($headers as $header) {
            foreach ((array) $header as $line) {
                $pair = strtok((string) $line, ';');
                if ($pair === false || ! str_contains($pair, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $pair, 2);
                $cookies[trim($name)] = trim($value);
            }
        }

        return $cookies;
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

        foreach ($page['xpath']->query('//li[contains(concat(" ", normalize-space(@class), " "), " products-menu__item ")]') as $modelNode) {
            if (! $modelNode instanceof DOMElement) {
                continue;
            }

            $modelLink = $page['xpath']->query('.//a[contains(concat(" ", normalize-space(@class), " "), " products-menu__title-link ")]', $modelNode)->item(0);
            if (! $modelLink instanceof DOMElement) {
                continue;
            }

            $name = $this->clean($modelLink->textContent);
            if ($name === '' || Str::lower($name) === 'все товары') {
                continue;
            }

            $subcategories = [];
            foreach ($page['xpath']->query('.//li[contains(concat(" ", normalize-space(@class), " "), " productsMenu-submenu-i ")]', $modelNode) as $subcategoryNode) {
                if (! $subcategoryNode instanceof DOMElement) {
                    continue;
                }

                $subcategoryLink = $page['xpath']->query('.//a[contains(concat(" ", normalize-space(@class), " "), " productsMenu-submenu-a ")]', $subcategoryNode)->item(0);
                if (! $subcategoryLink instanceof DOMElement) {
                    continue;
                }

                $subcategoryUrl = $this->absoluteUrl($subcategoryLink->getAttribute('href'), $baseUrl);
                $subcategoryName = $this->clean($subcategoryLink->textContent);
                [$subcategoryCode, $subcategoryCleanName] = $this->splitCodeName($subcategoryName);

                if ($subcategoryUrl === null || $subcategoryCode === null) {
                    continue;
                }

                $children = [];
                foreach ($page['xpath']->query('.//ul[contains(concat(" ", normalize-space(@class), " "), " productsMenu-list ")]//a[@href]', $subcategoryNode) as $childLink) {
                    if (! $childLink instanceof DOMElement) {
                        continue;
                    }

                    $childUrl = $this->absoluteUrl($childLink->getAttribute('href'), $baseUrl);
                    $childName = $this->clean($childLink->textContent);

                    if ($childUrl !== null && $childName !== '') {
                        $children[$childUrl] = ['url' => $childUrl, 'name' => $childName];
                    }
                }

                $subcategories[$subcategoryUrl] = [
                    'url' => $subcategoryUrl,
                    'code' => $subcategoryCode,
                    'name' => $subcategoryCleanName,
                    'children' => array_values($children),
                ];
            }

            $models[] = [
                'url' => $this->absoluteUrl($modelLink->getAttribute('href'), $baseUrl),
                'name' => $name,
                'subcategories' => array_values($subcategories),
            ];
        }

        return $models;
    }

    protected function productsFromCategoryPage(array $page, string $baseUrl): array
    {
        $products = [];

        foreach ($page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " j-product-container ")]') as $productNode) {
            if (! $productNode instanceof DOMElement) {
                continue;
            }

            $linkNode = $page['xpath']->query('.//div[contains(concat(" ", normalize-space(@class), " "), " catalogCard-title ")]//a[@href]', $productNode)->item(0)
                ?: $page['xpath']->query('.//a[@href]', $productNode)->item(0);

            if (! $linkNode instanceof DOMElement) {
                continue;
            }

            $sourceUrl = $this->absoluteUrl($linkNode->getAttribute('href'), $baseUrl);
            if ($sourceUrl === null) {
                continue;
            }

            $isRussianUrl = $this->isRussianUrl($sourceUrl);
            $sourceUrl = $this->canonicalProductUrl($sourceUrl, $baseUrl) ?? $sourceUrl;

            $title = $this->clean($linkNode->getAttribute('title') ?: $linkNode->textContent);
            $partNumber = $this->partNumberFromCard($page, $productNode, $title);
            $name = $this->productName($title, $partNumber);

            if ($partNumber === null && $name === '') {
                continue;
            }

            $imageNode = $page['xpath']->query('.//img[contains(concat(" ", normalize-space(@class), " "), " catalogCard-img ")]', $productNode)->item(0);
            $priceNode = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " catalogCard-price ")]', $productNode)->item(0);
            $priceText = $this->clean($priceNode?->textContent);
            $priceColor = $priceNode instanceof DOMElement ? $this->priceColor($priceNode) : null;

            $products[$sourceUrl] = [
                'source_url' => $sourceUrl,
                'driveparts_listing_id' => $this->clean($productNode->getAttribute('data-id')) ?: null,
                'part_number' => $partNumber,
                'name' => $name,
                'name_ru' => $isRussianUrl ? $name : null,
                'name_ua' => $isRussianUrl ? null : $name,
                'price_amount' => app(ExchangeRateService::class)->catalogPriceToUsd($this->priceAmount($priceText), $this->priceCurrency($priceText)),
                'currency' => $this->priceAmount($priceText) ? 'USD' : null,
                'availability' => $this->availabilityFromPriceColor($priceColor),
                'price_color' => $priceColor,
                'image_url' => $imageNode instanceof DOMElement
                    ? $this->drivePartsProductImageUrl($this->absoluteUrl($imageNode->getAttribute('src'), $baseUrl))
                    : null,
            ];
        }

        return array_values($products);
    }

    protected function productPayload(PartCatalogCategory $category, array $product): array
    {
        $subcategory = $category->parent;
        $mainCategory = $subcategory?->parent;

        return [
            'part_catalog_category_id' => $category->id,
            'source' => $this->source,
            'part_number' => $product['part_number'],
            'name' => $product['name'],
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
            'node_name' => $category->name,
            'compatibility_text' => $category->model_label,
            'condition' => $product['condition'] ?? null,
            'quality' => $product['quality'] ?? null,
            'availability' => $product['availability'] ?? null,
            'raw_attributes' => array_filter([
                'category_source_url' => $category->source_url,
                'image_url' => $product['image_url'],
            ]),
            'source_updated_at' => now(),
        ];
    }

    protected function allProductsPayload(array $product): array
    {
        $payload = [
            'source' => $this->source,
            'part_number' => $product['part_number'],
            'name' => $product['name'],
            'name_ru' => $product['name_ru'] ?? null,
            'name_ua' => $product['name_ua'] ?? null,
            'price_amount' => $product['price_amount'],
            'currency' => $product['currency'],
            'source_updated_at' => now(),
        ];

        foreach ([
            'model_label',
            'model_name',
            'year_from',
            'year_to',
            'main_category_code',
            'main_category_name',
            'subcategory_code',
            'subcategory_name',
            'node_name',
            'compatibility_text',
            'condition',
            'quality',
            'availability',
        ] as $key) {
            if (array_key_exists($key, $product)) {
                $payload[$key] = $product[$key];
            }
        }

        return $payload;
    }

    protected function saveAllProduct(array $product, ?PartCatalogItem $existingItem = null): PartCatalogItem
    {
        $sourceUrl = $this->canonicalProductUrl((string) $product['source_url'], 'https://drive-parts.com.ua') ?: (string) $product['source_url'];
        $product['source_url'] = $sourceUrl;
        $item = $existingItem ?: $this->existingProductItem($product) ?: new PartCatalogItem(['source_url' => $sourceUrl]);
        $rawAttributes = (array) ($item->raw_attributes ?? []);
        $rawAttributes['source_urls'] = collect((array) ($rawAttributes['source_urls'] ?? []))
            ->push($item->source_url)
            ->push($sourceUrl)
            ->filter()
            ->reject(fn (string $url): bool => $this->isRussianUrl($url))
            ->unique()
            ->values()
            ->all();

        if (($product['image_url'] ?? null) !== null && ! $this->isDrivePartsPlaceholderImageReference($product['image_url'])) {
            $rawAttributes['image_url'] = $product['image_url'];
        }

        if (($product['image_urls'] ?? []) !== []) {
            $rawAttributes['image_urls'] = collect((array) ($rawAttributes['image_urls'] ?? []))
                ->merge((array) $product['image_urls'])
                ->filter()
                ->reject(fn (string $url): bool => $this->isDrivePartsPlaceholderImageReference($url))
                ->unique(fn (string $url): string => $this->drivePartsLocalImageUniqueKey($url))
                ->unique()
                ->values()
                ->all();
        }

        if (($product['remote_image_urls'] ?? []) !== []) {
            $rawAttributes['remote_image_urls'] = collect((array) ($rawAttributes['remote_image_urls'] ?? []))
                ->merge((array) $product['remote_image_urls'])
                ->filter()
                ->reject(fn (string $url): bool => $this->isDrivePartsPlaceholderImageReference($url))
                ->unique()
                ->values()
                ->all();
        }

        if (($product['tesla_actual_part_number'] ?? null) !== null) {
            $rawAttributes['tesla_actual_part_number'] = $product['tesla_actual_part_number'];
        }

        if (($product['driveparts_sku'] ?? null) !== null) {
            $rawAttributes['driveparts_sku'] = $product['driveparts_sku'];
        }

        if (($product['driveparts_listing_id'] ?? null) !== null) {
            $rawAttributes['driveparts_listing_id'] = $product['driveparts_listing_id'];
        }

        if (($product['price_color'] ?? null) !== null) {
            $rawAttributes['price_color'] = $product['price_color'];
        }

        if (($product['compatibility_paths'] ?? []) !== []) {
            $rawAttributes['compatibility_paths'] = $product['compatibility_paths'];
        }

        if (($product['compatibility_models'] ?? []) !== []) {
            $rawAttributes['compatibility_models'] = $product['compatibility_models'];
        }

        if ($item->exists) {
            $this->mergeDuplicateProductItems($item, $product);
        }

        $detailsFetched = (bool) ($product['_details_fetched'] ?? true);
        $payload = $this->allProductsPayload($product);
        if ($item->exists && ! $detailsFetched) {
            $payload = collect($payload)
                ->only([
                    'source',
                    'part_number',
                    'name',
                    'name_ru',
                    'name_ua',
                    'price_amount',
                    'currency',
                    'condition',
                    'quality',
                    'availability',
                    'source_updated_at',
                ])
                ->reject(fn ($value, string $key): bool => in_array(
                    $key,
                    ['part_number', 'name', 'name_ru', 'name_ua', 'condition', 'quality', 'availability'],
                    true
                ) && blank($value))
                ->all();
        }
        $categoryIds = $this->productCategoryIds($product, $rawAttributes);
        $categoryId = $categoryIds[0] ?? null;
        if ($categoryId !== null && ($item->part_catalog_category_id === null || ! $item->exists)) {
            $payload['part_catalog_category_id'] = $categoryId;
        }

        $item->forceFill($payload + [
            'source_url' => $sourceUrl,
            'raw_attributes' => array_filter($rawAttributes),
        ])->save();

        $this->mergeDuplicateProductItems($item, $product);
        $this->syncProductCategoryOccurrences($item, $product, $categoryIds);

        return $item;
    }

    protected function productCategoryId(array $product, array $rawAttributes): ?int
    {
        return $this->productCategoryIds($product, $rawAttributes)[0] ?? null;
    }

    protected function productCategoryIds(array $product, array $rawAttributes): array
    {
        $urls = collect()
            ->push($product['category_source_url'] ?? null)
            ->push($rawAttributes['category_source_url'] ?? null)
            ->merge(collect((array) ($product['compatibility_paths'] ?? []))->pluck('url'))
            ->merge(collect((array) ($rawAttributes['compatibility_paths'] ?? []))->pluck('url'))
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->flatMap(fn (string $url): array => $this->categoryUrlVariants($url))
            ->unique()
            ->values()
            ->all();

        if ($urls !== []) {
            $ids = PartCatalogCategory::query()
                ->where('source', $this->source)
                ->whereIn('source_url', $urls)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->values()
                ->all();

            if ($ids !== []) {
                return $ids;
            }
        }

        $id = $this->productCategoryIdByPathDetails($product);

        return $id === null ? [] : [$id];
    }

    protected function syncProductCategoryOccurrences(PartCatalogItem $item, array $product, array $categoryIds): void
    {
        $categoryIds = collect($categoryIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        if ($categoryIds->isEmpty()) {
            return;
        }

        $productUrl = $this->canonicalProductUrl((string) $item->source_url, 'https://drive-parts.com.ua') ?: (string) $item->source_url;

        foreach ($categoryIds as $categoryId) {
            $occurrenceKey = sha1($this->source.'|'.$item->id.'|'.$categoryId.'|'.$productUrl);

            PartCatalogItemOccurrence::query()->updateOrCreate(
                ['occurrence_key' => $occurrenceKey],
                [
                    'part_catalog_item_id' => $item->id,
                    'part_catalog_category_id' => $categoryId,
                    'source' => $this->source,
                    'page_url' => $this->categorySourceUrlForOccurrence($product, $categoryId),
                    'product_url' => $productUrl,
                    'part_number' => $item->part_number,
                    'name' => $item->name,
                    'raw_attributes' => array_filter([
                        'compatibility_path' => $this->compatibilityPathForCategoryId($product, $categoryId),
                    ]),
                ]
            );
        }
    }

    protected function categorySourceUrlForOccurrence(array $product, int $categoryId): ?string
    {
        $categoryUrls = PartCatalogCategory::query()
            ->where('source', $this->source)
            ->whereKey($categoryId)
            ->pluck('source_url')
            ->flatMap(fn (?string $url): array => $this->categoryUrlVariants((string) $url))
            ->all();

        foreach ((array) ($product['compatibility_paths'] ?? []) as $path) {
            $url = (string) ($path['url'] ?? '');
            if ($url !== '' && array_intersect($this->categoryUrlVariants($url), $categoryUrls) !== []) {
                return $this->canonicalCategoryUrl($url, 'https://drive-parts.com.ua') ?: $url;
            }
        }

        return PartCatalogCategory::query()
            ->where('source', $this->source)
            ->whereKey($categoryId)
            ->value('source_url');
    }

    protected function compatibilityPathForCategoryId(array $product, int $categoryId): ?array
    {
        $categoryUrls = PartCatalogCategory::query()
            ->where('source', $this->source)
            ->whereKey($categoryId)
            ->pluck('source_url')
            ->flatMap(fn (?string $url): array => $this->categoryUrlVariants((string) $url))
            ->all();

        foreach ((array) ($product['compatibility_paths'] ?? []) as $path) {
            if (! is_array($path)) {
                continue;
            }

            $url = (string) ($path['url'] ?? '');
            if ($url !== '' && array_intersect($this->categoryUrlVariants($url), $categoryUrls) !== []) {
                return $path;
            }
        }

        return null;
    }

    protected function productCategoryIdByPathDetails(array $product): ?int
    {
        $modelLabel = trim((string) ($product['model_label'] ?? ''));
        $mainCategoryCode = trim((string) ($product['main_category_code'] ?? ''));
        $subcategoryCode = trim((string) ($product['subcategory_code'] ?? ''));
        $nodeName = trim((string) ($product['node_name'] ?? ''));

        if ($modelLabel === '' || $mainCategoryCode === '' || $subcategoryCode === '' || $nodeName === '') {
            return null;
        }

        $id = (int) (PartCatalogCategory::query()
            ->where('source', $this->source)
            ->where('depth', 3)
            ->where('model_label', $modelLabel)
            ->where('name_en', $nodeName)
            ->whereHas('parent', function ($subcategory) use ($subcategoryCode, $mainCategoryCode): void {
                $subcategory
                    ->where('code', $subcategoryCode)
                    ->whereHas('parent', fn ($mainCategory) => $mainCategory->where('code', $mainCategoryCode));
            })
            ->value('id') ?: 0);

        return $id > 0 ? $id : null;
    }

    protected function categoryUrlVariants(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        $ukrainianUrl = preg_replace('#://([^/]+)/ru/#', '://$1/', $url) ?: $url;
        $russianUrl = str_contains($ukrainianUrl, '://drive-parts.com.ua/ru/')
            ? $ukrainianUrl
            : (preg_replace('#://([^/]+)/#', '://$1/ru/', $ukrainianUrl, 1) ?: $ukrainianUrl);

        return collect([$url, $ukrainianUrl, $russianUrl])
            ->flatMap(fn (string $candidate): array => [
                rtrim($candidate, '/'),
                rtrim($candidate, '/').'/',
            ])
            ->unique()
            ->values()
            ->all();
    }

    protected function rawAttributesArray(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    public function purgePlaceholderImages(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $deleteFiles = (bool) ($options['delete_files'] ?? true);

        $stats = [
            'items_seen' => 0,
            'items_updated' => 0,
            'image_references_removed' => 0,
            'files_deleted' => 0,
        ];

        PartCatalogItem::query()
            ->where('source', $this->source)
            ->where(function ($query): void {
                foreach (self::PLACEHOLDER_IMAGE_STEMS as $stem) {
                    $query->orWhere('raw_attributes', 'like', '%'.$stem.'%');
                }

                $query->orWhere('raw_attributes', 'like', '%driveparts/part-images/%');
                $query->orWhere('raw_attributes', 'like', '%driveparts\\\\/part-images%');
                $query->orWhere('raw_attributes', 'like', '%part-images%');
            })
            ->orderBy('id')
            ->get()
            ->each(function (PartCatalogItem $item) use ($dryRun, $deleteFiles, &$stats): void {
                $stats['items_seen']++;
                $rawAttributes = $this->rawAttributesArray($item);
                $changed = false;

                foreach (['image_urls', 'remote_image_urls'] as $key) {
                    $kept = [];

                    foreach ((array) ($rawAttributes[$key] ?? []) as $url) {
                        if ($this->isDrivePartsPlaceholderImageReference($url)) {
                            $stats['image_references_removed']++;
                            $changed = true;
                            $this->deleteLocalImageReference($url, $deleteFiles, $dryRun, $stats);

                            continue;
                        }

                        $kept[] = $url;
                    }

                    if ($kept === []) {
                        unset($rawAttributes[$key]);
                    } else {
                        $rawAttributes[$key] = array_values(array_unique($kept));
                    }
                }

                foreach (['image_url', 'remote_image_url'] as $key) {
                    if (array_key_exists($key, $rawAttributes) && $this->isDrivePartsPlaceholderImageReference($rawAttributes[$key])) {
                        $stats['image_references_removed']++;
                        $changed = true;
                        $this->deleteLocalImageReference($rawAttributes[$key], $deleteFiles, $dryRun, $stats);
                        unset($rawAttributes[$key]);
                    }
                }

                $hasSharedPlaceholder = collect((array) ($rawAttributes['image_urls'] ?? []))
                    ->push($rawAttributes['image_url'] ?? null)
                    ->filter(fn ($url): bool => is_string($url) && trim(str_replace('\\', '/', $url), '/') === self::PLACEHOLDER_IMAGE_PATH)
                    ->isNotEmpty();

                if ($hasSharedPlaceholder) {
                    foreach (['image_url', 'remote_image_url'] as $key) {
                        if (($rawAttributes[$key] ?? null) !== self::PLACEHOLDER_IMAGE_PATH) {
                            unset($rawAttributes[$key]);
                            $changed = true;
                        }
                    }

                    foreach (['image_urls', 'remote_image_urls'] as $key) {
                        $values = array_values(array_filter(
                            (array) ($rawAttributes[$key] ?? []),
                            fn ($url): bool => is_string($url) && trim(str_replace('\\', '/', $url), '/') === self::PLACEHOLDER_IMAGE_PATH
                        ));

                        if ($key === 'image_urls') {
                            if ($values !== [self::PLACEHOLDER_IMAGE_PATH]) {
                                $changed = true;
                            }
                            $rawAttributes[$key] = [self::PLACEHOLDER_IMAGE_PATH];
                        } elseif (isset($rawAttributes[$key])) {
                            unset($rawAttributes[$key]);
                            $changed = true;
                        }
                    }

                    $rawAttributes['image_url'] = self::PLACEHOLDER_IMAGE_PATH;
                }

                $hasAnyImageReference = collect([
                    ...(array) ($rawAttributes['image_urls'] ?? []),
                    ...(array) ($rawAttributes['remote_image_urls'] ?? []),
                    $rawAttributes['image_url'] ?? null,
                    $rawAttributes['remote_image_url'] ?? null,
                ])->filter(fn ($url): bool => is_string($url) && trim($url) !== '')->isNotEmpty();

                if ($changed && ! $hasAnyImageReference) {
                    $rawAttributes['image_url'] = self::PLACEHOLDER_IMAGE_PATH;
                    $rawAttributes['image_urls'] = [self::PLACEHOLDER_IMAGE_PATH];
                }

                if (! $changed) {
                    return;
                }

                foreach (['image_urls', 'remote_image_urls'] as $listKey) {
                    $singleKey = $listKey === 'image_urls' ? 'image_url' : 'remote_image_url';
                    if (! isset($rawAttributes[$singleKey]) && isset($rawAttributes[$listKey][0])) {
                        $rawAttributes[$singleKey] = $rawAttributes[$listKey][0];
                    }
                }

                if (! $dryRun) {
                    $item->forceFill([
                        'raw_attributes' => array_filter($rawAttributes, fn ($value): bool => $value !== null && $value !== '' && $value !== []),
                    ])->save();
                }

                $stats['items_updated']++;
            });

        return $stats;
    }

    protected function remoteImageUrlsFromRawAttributes(array $rawAttributes): array
    {
        return collect()
            ->merge((array) data_get($rawAttributes, 'remote_image_urls', []))
            ->push(data_get($rawAttributes, 'remote_image_url'))
            ->merge((array) data_get($rawAttributes, 'image_urls', []))
            ->push(data_get($rawAttributes, 'image_url'))
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => trim($url))
            ->filter(fn (string $url): bool => Str::startsWith($url, ['http://', 'https://', '//']))
            ->reject(fn (string $url): bool => $this->isDrivePartsPlaceholderImageUrl($url))
            ->map(fn (string $url): string => $this->normalizedDrivePartsHighResolutionImageUrl($url))
            ->unique(fn (string $url): string => $this->drivePartsImageUniqueKey($url))
            ->unique()
            ->values()
            ->all();
    }

    protected function saveProductImages(PartCatalogItem $item, array $product): bool
    {
        $localImageUrls = collect((array) ($product['image_urls'] ?? []))
            ->push($product['image_url'] ?? null)
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->reject(fn (string $url): bool => Str::startsWith($url, ['http://', 'https://', '//']))
            ->unique()
            ->values()
            ->all();

        if ($localImageUrls === []) {
            return false;
        }

        $rawAttributes = $this->rawAttributesArray($item);
        $rawAttributes['image_urls'] = collect((array) ($rawAttributes['image_urls'] ?? []))
            ->merge($localImageUrls)
            ->filter()
            ->unique(fn (string $url): string => $this->drivePartsLocalImageUniqueKey($url))
            ->unique()
            ->values()
            ->all();
        $rawAttributes['image_url'] = $rawAttributes['image_urls'][0] ?? $localImageUrls[0];

        if (($product['remote_image_urls'] ?? []) !== []) {
            $rawAttributes['remote_image_urls'] = collect((array) $product['remote_image_urls'])
                ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
                ->reject(fn (string $url): bool => $this->isDrivePartsPlaceholderImageUrl($url))
                ->map(fn (string $url): string => $this->normalizedDrivePartsHighResolutionImageUrl($url))
                ->unique(fn (string $url): string => $this->drivePartsImageUniqueKey($url))
                ->unique()
                ->values()
                ->all();
        }

        $item->forceFill(['raw_attributes' => array_filter($rawAttributes)])->save();

        return true;
    }

    protected function existingProductItem(array $product): ?PartCatalogItem
    {
        $sourceUrl = (string) ($product['source_url'] ?? '');
        $urls = collect([
            $sourceUrl,
            $this->russianProductUrl($sourceUrl, 'https://drive-parts.com.ua'),
            $this->ukrainianProductUrl($sourceUrl, 'https://drive-parts.com.ua'),
        ])->filter()->unique()->values()->all();

        return PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereIn('source_url', $urls)
            ->orderByRaw('part_catalog_category_id is null')
            ->orderBy('id')
            ->first();
    }

    protected function shouldFetchProductDetails(?PartCatalogItem $item): bool
    {
        return $item === null || ! $item->exists;
    }

    protected function shouldFetchListingProductDetails(?PartCatalogItem $item, array $product): bool
    {
        if ($item === null || ! $item->exists) {
            return true;
        }

        $rawAttributes = $this->rawAttributesArray($item);

        return (blank($item->availability) && blank($product['availability'] ?? null))
            || blank($item->condition)
            || (blank(data_get($rawAttributes, 'driveparts_sku')) && blank($item->part_number));
    }

    protected function hasDownloadedAllProductImages(array $rawAttributes): bool
    {
        $localImagesCount = collect((array) data_get($rawAttributes, 'image_urls', []))
            ->push(data_get($rawAttributes, 'image_url'))
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->filter(fn (string $url): bool => ! Str::startsWith($url, ['http://', 'https://', '//']))
            ->reject(fn (string $url): bool => $this->isDrivePartsPlaceholderImageReference($url))
            ->unique()
            ->count();

        if ($localImagesCount === 0) {
            return false;
        }

        $remoteImagesCount = collect((array) data_get($rawAttributes, 'remote_image_urls', []))
            ->push(data_get($rawAttributes, 'remote_image_url'))
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->reject(fn (string $url): bool => $this->isDrivePartsPlaceholderImageReference($url))
            ->unique()
            ->count();

        return $remoteImagesCount === 0 || $localImagesCount >= $remoteImagesCount;
    }

    protected function mergeDuplicateProductItems(PartCatalogItem $keeper, array $product): void
    {
        $sourceUrl = (string) ($product['source_url'] ?? '');
        $urls = collect([
            $sourceUrl,
            $this->russianProductUrl($sourceUrl, 'https://drive-parts.com.ua'),
            $this->ukrainianProductUrl($sourceUrl, 'https://drive-parts.com.ua'),
        ])->filter()->unique()->values()->all();

        if ($urls === []) {
            return;
        }

        PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereIn('source_url', $urls)
            ->where('id', '!=', $keeper->id)
            ->get()
            ->each(fn (PartCatalogItem $duplicate): mixed => DB::transaction(function () use ($keeper, $duplicate): void {
                Product::query()->where('source_part_catalog_item_id', $duplicate->id)->update(['source_part_catalog_item_id' => $keeper->id]);
                PartSale::query()->where('part_catalog_item_id', $duplicate->id)->update(['part_catalog_item_id' => $keeper->id]);
                ProductPriceHistory::query()->where('part_catalog_item_id', $duplicate->id)->update(['part_catalog_item_id' => $keeper->id]);
                PartCatalogItemOccurrence::query()->where('part_catalog_item_id', $duplicate->id)->update(['part_catalog_item_id' => $keeper->id]);
                PartCatalogItemZone::query()->where('part_catalog_item_id', $duplicate->id)->delete();

                $keeperRaw = (array) ($keeper->raw_attributes ?? []);
                $duplicateRaw = (array) ($duplicate->raw_attributes ?? []);
                $keeperRaw['source_urls'] = collect((array) ($keeperRaw['source_urls'] ?? []))
                    ->push($keeper->source_url)
                    ->push($duplicate->source_url)
                    ->merge((array) ($duplicateRaw['source_urls'] ?? []))
                    ->filter()
                    ->reject(fn (string $url): bool => $this->isRussianUrl($url))
                    ->unique()
                    ->values()
                    ->all();
                $keeper->forceFill(['raw_attributes' => array_filter($keeperRaw)])->save();

                $duplicate->delete();
            }));
    }

    protected function withLocalizedProductNames(array $product, string $baseUrl, array &$stats, bool $fetchUa = true): array
    {
        $ruUrl = $this->russianProductUrl((string) $product['source_url'], $baseUrl);
        $ukUrl = $this->ukrainianProductUrl((string) $product['source_url'], $baseUrl);
        $product['name_ru'] = $ruUrl === null
            ? null
            : $this->productNameFromUrl($ruUrl, $product['part_number'] ?? null, $stats, 'product_ru_pages_fetched');
        if ($fetchUa) {
            $product['name_ua'] = $ukUrl === null
                ? null
                : $this->productNameFromUrl($ukUrl, $product['part_number'] ?? null, $stats, 'product_ua_pages_fetched');
        } else {
            $product['name_ua'] = $product['name_ua'] ?? null;
        }

        $product['name'] = $product['name_ru'] ?: ($product['name_ua'] ?: $product['name']);

        return $product;
    }

    protected function withListingProductDetails(array $product, string $baseUrl, array &$stats): array
    {
        $listingId = trim((string) ($product['driveparts_listing_id'] ?? ''));
        if ($listingId === '') {
            return $product;
        }

        $details = $this->listingProductDetails($listingId, $baseUrl, $stats);
        if ($details === []) {
            return $product;
        }

        if (($details['driveparts_sku'] ?? null) !== null) {
            $product['part_number'] = $details['driveparts_sku'];
        }

        foreach (['availability', 'condition', 'tesla_actual_part_number', 'driveparts_sku'] as $key) {
            if (($details[$key] ?? null) !== null && $details[$key] !== '') {
                $product[$key] = $details[$key];
            }
        }

        $this->splitConditionQuality($product);

        return $product;
    }

    protected function listingProductDetails(string $listingId, string $baseUrl, array &$stats): array
    {
        $url = rtrim($baseUrl, '/').'/ru/catalog/load-additional-data/'.rawurlencode($listingId);
        $html = $this->fetchAjax($url, rtrim($baseUrl, '/').'/ru/vsi-tovary/');
        if ($html === null) {
            return [];
        }

        $payload = json_decode($html, true);
        $extraHtml = is_array($payload) ? (string) data_get($payload, 'response.html', '') : '';
        if ($extraHtml === '') {
            return [];
        }

        $stats['product_listing_extra_pages_fetched'] = ($stats['product_listing_extra_pages_fetched'] ?? 0) + 1;
        $page = $this->page($extraHtml);

        return array_filter([
            'availability' => $this->listingModificationValueAny($page, [
                "\u{041D}\u{0430}\u{043B}\u{0438}\u{0447}\u{0438}\u{0435}",
                "\u{041D}\u{0430}\u{044F}\u{0432}\u{043D}\u{0456}\u{0441}\u{0442}\u{044C}",
            ]),
            'tesla_actual_part_number' => $this->listingModificationValueAny($page, [
                "\u{0410}\u{043A}\u{0442}\u{0443}\u{0430}\u{043B}\u{044C}\u{043D}\u{044B}\u{0439} \u{043F}\u{0430}\u{0440}\u{0442}-\u{043D}\u{043E}\u{043C}\u{0435}\u{0440} Tesla",
                "\u{0410}\u{043A}\u{0442}\u{0443}\u{0430}\u{043B}\u{044C}\u{043D}\u{0438}\u{0439} \u{043F}\u{0430}\u{0440}\u{0442}-\u{043D}\u{043E}\u{043C}\u{0435}\u{0440} Tesla",
            ]),
            'driveparts_sku' => $this->listingModificationValueAny($page, [
                "\u{0410}\u{0440}\u{0442}\u{0438}\u{043A}\u{0443}\u{043B}",
            ]),
            'condition' => $this->listingModificationValueAny($page, [
                "\u{0421}\u{043E}\u{0441}\u{0442}\u{043E}\u{044F}\u{043D}\u{0438}\u{0435}",
                "\u{0421}\u{0442}\u{0430}\u{043D}",
            ]),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    protected function listingModificationValueAny(array $page, array $labels): ?string
    {
        foreach ($labels as $label) {
            $value = $this->listingModificationValue($page, (string) $label);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function listingModificationValue(array $page, string $label): ?string
    {
        foreach ($page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " modification ")]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $title = $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " modification__title ")]', $node)->item(0)?->textContent);
            if ($title !== $label) {
                continue;
            }

            $button = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " modification__button--active ")]', $node)->item(0)
                ?: $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " modification__button ")]', $node)->item(0);

            if ($button instanceof DOMElement) {
                return $this->clean($button->textContent);
            }
        }

        return null;
    }

    protected function withProductPageDetails(array $product, string $baseUrl, array &$stats): array
    {
        $ukUrl = $this->ukrainianProductUrl((string) $product['source_url'], $baseUrl);
        if ($ukUrl === null) {
            return $product;
        }

        $details = $this->productDetailsFromUrl($ukUrl, $product['part_number'] ?? null, $stats, 'product_compatibility_pages_fetched');
        if ($details === null) {
            return $product;
        }

        if (($details['name'] ?? null) !== null) {
            $product['name_ua'] = $details['name'];
        }

        if (($details['driveparts_sku'] ?? null) !== null) {
            $product['part_number'] = $details['driveparts_sku'];
        }

        if (($product['name_ru'] ?? null) === null && ($product['name_ua'] ?? null) !== null) {
            $product['name'] = $product['name_ua'];
        }

        foreach ([
            'model_label',
            'model_name',
            'year_from',
            'year_to',
            'main_category_code',
            'main_category_name',
            'subcategory_code',
            'subcategory_name',
            'node_name',
            'compatibility_text',
            'compatibility_models',
            'compatibility_paths',
            'image_url',
            'image_urls',
            'condition',
            'availability',
            'tesla_actual_part_number',
            'driveparts_sku',
        ] as $key) {
            if (($details[$key] ?? null) !== null && $details[$key] !== []) {
                $product[$key] = $details[$key];
            }
        }

        $this->splitConditionQuality($product);

        return $product;
    }

    protected function splitConditionQuality(array &$product): void
    {
        $value = $this->clean((string) ($product['condition'] ?? ''));
        if ($value === '') {
            return;
        }

        $normalized = Str::lower($value);

        if (preg_match('/\b(?:оригинал|оригінал)\b/iu', $value, $matches) === 1) {
            $product['quality'] = $this->clean($matches[0]);
            $value = $this->clean((string) preg_replace('/\b(?:оригинал|оригінал)\b/iu', '', $value));
        }

        if (preg_match('/\b(?:б\s*\/?\s*у|б\s*\/?\s*в)\b/iu', $value, $matches) === 1) {
            $product['condition'] = str_contains($normalized, 'б/в') || str_contains($normalized, 'б в') ? 'Б/В' : 'Б/У';

            return;
        }

        $product['condition'] = $value;
    }

    protected function productNameFromUrl(string $url, ?string $partNumber, array &$stats, string $statKey): ?string
    {
        $html = $this->fetch($url);
        if ($html === null) {
            return null;
        }

        $stats[$statKey] = ($stats[$statKey] ?? 0) + 1;

        return $this->productNameFromPage($this->page($html), $partNumber);
    }

    protected function productDetailsFromUrl(string $url, ?string $partNumber, array &$stats, string $statKey): ?array
    {
        $html = $this->fetch($url);
        if ($html === null) {
            return null;
        }

        $stats[$statKey] = ($stats[$statKey] ?? 0) + 1;
        $page = $this->page($html);
        $details = [
            'name' => $this->productNameFromPage($page, $partNumber),
        ];

        return array_merge(
            $details,
            $this->productCompatibilityDetails($page),
            $this->productCardDetails($page, $url, $html)
        );
    }

    protected function productCardDetails(array $page, string $url, string $html = ''): array
    {
        $imageUrls = $this->productImageUrls($page, $url)
            ->merge($this->productImageUrlsFromHtml($html, $url))
            ->unique()
            ->values();

        return array_filter([
            'image_url' => $imageUrls->first(),
            'image_urls' => $imageUrls->all(),
            'tesla_actual_part_number' => $this->productDetailValueAny($page, [
                "\u{0410}\u{043A}\u{0442}\u{0443}\u{0430}\u{043B}\u{044C}\u{043D}\u{044B}\u{0439} \u{043F}\u{0430}\u{0440}\u{0442}-\u{043D}\u{043E}\u{043C}\u{0435}\u{0440} Tesla",
                "\u{0410}\u{043A}\u{0442}\u{0443}\u{0430}\u{043B}\u{044C}\u{043D}\u{0438}\u{0439} \u{043F}\u{0430}\u{0440}\u{0442}-\u{043D}\u{043E}\u{043C}\u{0435}\u{0440} Tesla",
            ]),
            'driveparts_sku' => $this->productDetailValue($page, "\u{0410}\u{0440}\u{0442}\u{0438}\u{043A}\u{0443}\u{043B}"),
            'condition' => $this->productDetailValueAny($page, [
                "\u{0421}\u{043E}\u{0441}\u{0442}\u{043E}\u{044F}\u{043D}\u{0438}\u{0435}",
                "\u{0421}\u{0442}\u{0430}\u{043D}",
            ]),
            'availability' => $this->productDetailValueAny($page, [
                "\u{041D}\u{0430}\u{043B}\u{0438}\u{0447}\u{0438}\u{0435}",
                "\u{041D}\u{0430}\u{044F}\u{0432}\u{043D}\u{0456}\u{0441}\u{0442}\u{044C}",
            ]),
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function productImageUrls(array $page, string $baseUrl): Collection
    {
        $urls = collect();

        foreach ($page['xpath']->query('//a[@href] | //img[@src] | //img[@data-src] | //source[@srcset]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (['href', 'src', 'data-src', 'srcset'] as $attribute) {
                $value = trim($node->getAttribute($attribute));
                if ($value === '') {
                    continue;
                }

                foreach (preg_split('/\s*,\s*/', $value) ?: [] as $candidate) {
                    $candidate = trim((string) preg_replace('/\s+\d+[wx]$/', '', $candidate));
                    $absoluteUrl = $this->absoluteUrl($candidate, $baseUrl);

                    if ($absoluteUrl !== null && $this->isProductImageUrl($absoluteUrl)) {
                        $urls->push($absoluteUrl);
                    }
                }
            }
        }

        return $urls->unique()->values();
    }

    protected function isProductImageUrl(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, '/content/images/')
            && preg_match('/\.(?:jpe?g|png|webp)(?:$|\?)/i', $path) === 1
            && ! $this->isDrivePartsPlaceholderImageUrl($url);
    }

    protected function productImageUrlsFromHtml(string $html, string $baseUrl): Collection
    {
        preg_match_all('#(?:https?:)?//[^\\s"\'<>]+/content/images/[^\\s"\'<>]+\\.(?:jpe?g|png|webp)#iu', $html, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $url): ?string => $this->absoluteUrl($url, $baseUrl))
            ->filter(fn (?string $url): bool => $url !== null && $this->isProductImageUrl($url))
            ->unique()
            ->values();
    }

    protected function productDetailValue(array $page, string $label): ?string
    {
        foreach ($page['xpath']->query('//*[normalize-space(.) = "'.$label.'"]') as $labelNode) {
            if (! $labelNode instanceof DOMElement) {
                continue;
            }

            $valueNode = $labelNode->nextSibling;
            while ($valueNode !== null) {
                $value = $this->clean($valueNode->textContent);
                if ($value !== '') {
                    return $value;
                }

                $valueNode = $valueNode->nextSibling;
            }
        }

        $text = $this->clean($page['document']->textContent);
        $quotedLabel = preg_quote($label, '/');
        $nextLabels = "\u{041D}\u{0430}\u{043B}\u{0438}\u{0447}\u{0438}\u{0435}|\u{041D}\u{0430}\u{044F}\u{0432}\u{043D}\u{0456}\u{0441}\u{0442}\u{044C}|\u{0410}\u{043A}\u{0442}\u{0443}\u{0430}\u{043B}\u{044C}\u{043D}\u{044B}\u{0439} \u{043F}\u{0430}\u{0440}\u{0442}-\u{043D}\u{043E}\u{043C}\u{0435}\u{0440} Tesla|\u{0410}\u{043A}\u{0442}\u{0443}\u{0430}\u{043B}\u{044C}\u{043D}\u{0438}\u{0439} \u{043F}\u{0430}\u{0440}\u{0442}-\u{043D}\u{043E}\u{043C}\u{0435}\u{0440} Tesla|\u{0410}\u{0440}\u{0442}\u{0438}\u{043A}\u{0443}\u{043B}|\u{0421}\u{043E}\u{0441}\u{0442}\u{043E}\u{044F}\u{043D}\u{0438}\u{0435}|\u{0421}\u{0442}\u{0430}\u{043D}|\u{041A}\u{0443}\u{043F}\u{0438}\u{0442}\u{044C}|\u{041A}\u{0443}\u{043F}\u{0438}\u{0442}\u{0438}";

        return preg_match('/'.$quotedLabel.'\s+(.+?)(?=\s+(?:'.$nextLabels.')\b|$)/u', $text, $matches) === 1
            ? $this->clean($matches[1])
            : null;
    }

    protected function productDetailValueAny(array $page, array $labels): ?string
    {
        foreach ($labels as $label) {
            $value = $this->productDetailValue($page, (string) $label);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function withDownloadedProductImages(array $product, array &$stats): array
    {
        $partNumber = (string) ($product['part_number'] ?? '');
        $remoteUrls = collect((array) ($product['image_urls'] ?? []))
            ->push($product['image_url'] ?? null)
            ->filter()
            ->unique()
            ->values();
        if ($partNumber === '' || $remoteUrls->isEmpty()) {
            return $product;
        }

        $localPaths = [];
        $downloadedRemoteUrls = [];
        $keptRemoteUrls = [];
        foreach ($remoteUrls as $url) {
            $url = (string) $url;
            if ($this->isDrivePartsPlaceholderImageUrl($url)) {
                continue;
            }

            $skippedPlaceholderContent = false;
            foreach ($this->drivePartsImageDownloadCandidates($url) as $candidateUrl) {
                $path = $this->downloadProductImage($partNumber, $candidateUrl);
                if ($path === false) {
                    $skippedPlaceholderContent = true;
                    break;
                }

                if ($path !== null) {
                    $localPaths[] = $path;
                    $downloadedRemoteUrls[] = $candidateUrl;
                    $stats['product_images_downloaded']++;
                    break;
                }
            }

            if (! $skippedPlaceholderContent) {
                $keptRemoteUrls[] = $url;
            }
        }

        if ($localPaths !== []) {
            $product['remote_image_urls'] = array_values(array_unique($downloadedRemoteUrls));
            $product['image_urls'] = array_values(array_unique($localPaths));
            $product['image_url'] = $product['image_urls'][0] ?? ($product['image_url'] ?? null);
        } elseif ($keptRemoteUrls !== $remoteUrls->all()) {
            $product['image_urls'] = array_values(array_unique($keptRemoteUrls));
            $product['image_url'] = $product['image_urls'][0] ?? null;
        }

        return $product;
    }

    protected function downloadProductImage(string $partNumber, string $url): string|false|null
    {
        $path = $this->drivePartsImagePath($partNumber, $url);
        if ($path === null) {
            return null;
        }

        if (Storage::disk('public')->exists($path)) {
            if ($this->localImageReferenceHasPlaceholderContent($path)) {
                return false;
            }

            return $path;
        }

        try {
            $response = $this->httpRequest(['challenge_passed' => self::CHALLENGE_COOKIE])
                ->timeout(20)
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return null;
        }

        $body = $response->body();
        if ($this->isDrivePartsPlaceholderImageContent($body)) {
            return false;
        }

        Storage::disk('public')->put($path, $body);

        return $path;
    }

    protected function drivePartsImageDownloadCandidates(string $url): array
    {
        if (! str_contains((string) parse_url($url, PHP_URL_HOST), 'drive-parts.com.ua')) {
            return [$url];
        }

        return collect([
            $this->normalizedDrivePartsHighResolutionImageUrl($url),
            $this->drivePartsImageUrlWithSizeSegment($url, self::HIGH_RES_IMAGE_SIZE_SEGMENT),
            $this->drivePartsImageUrlWithSizeSegment($url, self::PREFERRED_IMAGE_SIZE_SEGMENT),
            $url,
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function normalizedDrivePartsImageUrl(string $url): string
    {
        return $this->drivePartsImageUrlWithSizeSegment($url, self::PREFERRED_IMAGE_SIZE_SEGMENT) ?? $url;
    }

    protected function normalizedDrivePartsHighResolutionImageUrl(string $url): string
    {
        $normalized = $this->drivePartsImageUrlWithSizeSegment($url, self::HIGH_RES_IMAGE_SIZE_SEGMENT);
        if ($normalized === null) {
            return $url;
        }

        return (string) preg_replace('/\.(?:jpe?g|png)(?=([?#].*)?$)/iu', '.webp', $normalized);
    }

    protected function drivePartsImageUniqueKey(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('~/content/images/\d+/[^/]+/([^/?#]+)$~i', $path, $matches) === 1
            ? (string) preg_replace('/\.(?:jpe?g|png|webp)$/iu', '', $matches[1])
            : $url;
    }

    protected function drivePartsLocalImageUniqueKey(string $url): string
    {
        $path = str_replace('\\', '/', rawurldecode((string) parse_url($url, PHP_URL_PATH)));

        if (! Str::contains($path, ['driveparts/part-images/', 'competitor-catalog/driveparts/'])) {
            return $url;
        }

        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = preg_replace('/-[a-f0-9]{10,12}$/i', '', $name) ?: $name;

        return Str::lower($name);
    }

    protected function drivePartsImageUrlWithSizeSegment(string $url, string $sizeSegment): ?string
    {
        if (! str_contains((string) parse_url($url, PHP_URL_HOST), 'drive-parts.com.ua')) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('~/content/images/(\d+)/([^/]+)/([^/?#]+)$~i', $path, $matches) !== 1) {
            return null;
        }

        return 'https://drive-parts.com.ua/content/images/'.$matches[1].'/'.$sizeSegment.'/'.$matches[3];
    }

    protected function isDrivePartsPlaceholderImageUrl(string $url): bool
    {
        $filename = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);

        return in_array($filename, self::PLACEHOLDER_IMAGE_STEMS, true);
    }

    protected function isDrivePartsPlaceholderImageReference(mixed $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $path = str_replace('\\', '/', rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $storagePath = ltrim($path, '/');
        if (str_starts_with($storagePath, 'storage/')) {
            $storagePath = ltrim(Str::after($storagePath, 'storage/'), '/');
        }

        if ($storagePath === self::PLACEHOLDER_IMAGE_PATH) {
            return false;
        }

        if (Str::startsWith($url, ['http://', 'https://', '//'])) {
            return $this->isDrivePartsPlaceholderImageUrl($url);
        }

        if (str_starts_with($storagePath, 'driveparts/part-images/')
            && ! Storage::disk('public')->exists($storagePath)) {
            return true;
        }

        $filename = pathinfo($storagePath, PATHINFO_FILENAME);

        foreach (self::PLACEHOLDER_IMAGE_STEMS as $stem) {
            if ($filename === $stem || str_starts_with($filename, $stem.'-')) {
                return true;
            }
        }

        return $this->localImageReferenceHasPlaceholderContent($url);
    }

    protected function localImageReferenceHasPlaceholderContent(string $path): bool
    {
        if (Str::startsWith($path, ['http://', 'https://', '//']) || ! Storage::disk('public')->exists($path)) {
            return false;
        }

        $contents = Storage::disk('public')->get($path);

        return is_string($contents) && $this->isDrivePartsPlaceholderImageContent($contents);
    }

    protected function isDrivePartsPlaceholderImageContent(string $contents): bool
    {
        return in_array(hash('sha256', $contents), self::PLACEHOLDER_IMAGE_SHA256_HASHES, true);
    }

    protected function drivePartsProductImageUrl(?string $url): ?string
    {
        if ($url === null || ! $this->isProductImageUrl($url)) {
            return null;
        }

        return $url;
    }

    protected function deleteLocalImageReference(mixed $url, bool $deleteFiles, bool $dryRun, array &$stats): void
    {
        if (! $deleteFiles || ! is_string($url) || Str::startsWith($url, ['http://', 'https://', '//'])) {
            return;
        }

        if (trim(str_replace('\\', '/', $url), '/') === self::PLACEHOLDER_IMAGE_PATH) {
            return;
        }

        if (! Storage::disk('public')->exists($url)) {
            return;
        }

        if (! $dryRun) {
            Storage::disk('public')->delete($url);
        }

        $stats['files_deleted']++;
    }

    protected function drivePartsImagePath(string $partNumber, string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $name = Str::slug(pathinfo($path, PATHINFO_FILENAME) ?: sha1($url), '-');
        if ($name === '') {
            $name = sha1($url);
        }

        return 'driveparts/part-images/'.$this->compactPartNumber($partNumber).'/'.Str::limit($name, 80, '').'-'.substr(sha1($url), 0, 10).'.'.$extension;
    }

    protected function productNameFromPage(array $page, ?string $partNumber): ?string
    {
        $title = $this->clean($page['xpath']->query('//h1')->item(0)?->textContent);

        if ($title === '') {
            $title = $this->clean($page['xpath']->query('//meta[@property="og:title"]')->item(0)?->getAttribute('content'));
        }

        if ($title === '') {
            $title = $this->clean($page['xpath']->query('//title')->item(0)?->textContent);
            $title = trim((string) preg_replace('/\s*\|.*$/u', '', $title));
        }

        return $title === '' ? null : $this->productName($title, $partNumber);
    }

    protected function productCompatibilityDetails(array $page): array
    {
        $compatibility = [];
        $models = [];

        foreach ($page['xpath']->query('//h3[normalize-space(.) = "Сумісність"]/following-sibling::ul[1]/li') as $modelNode) {
            if (! $modelNode instanceof DOMElement) {
                continue;
            }

            $modelLabel = $this->clean($page['xpath']->query('./strong', $modelNode)->item(0)?->textContent);
            if ($modelLabel === '') {
                continue;
            }

            $models[] = $modelLabel;

            foreach ($page['xpath']->query('.//a[@href]', $modelNode) as $pathNode) {
                if (! $pathNode instanceof DOMElement) {
                    continue;
                }

                $path = $this->clean($pathNode->textContent);
                if ($path === '') {
                    continue;
                }

                $compatibility[] = [
                    'model' => $modelLabel,
                    'path' => $path,
                    'url' => $pathNode->getAttribute('href'),
                ];
            }
        }

        if ($compatibility === []) {
            return [];
        }

        $primary = $this->categoryDetailsFromCompatibilityPath($compatibility[0]['path']);

        return array_filter([
            'model_label' => $primary['model_label'] ?? null,
            'model_name' => $primary['model_name'] ?? null,
            'year_from' => $primary['year_from'] ?? null,
            'year_to' => $primary['year_to'] ?? null,
            'main_category_code' => $primary['main_category_code'] ?? null,
            'main_category_name' => $primary['main_category_name'] ?? null,
            'subcategory_code' => $primary['subcategory_code'] ?? null,
            'subcategory_name' => $primary['subcategory_name'] ?? null,
            'node_name' => $primary['node_name'] ?? null,
            'compatibility_text' => collect($models)->unique()->implode("\n"),
            'compatibility_models' => collect($models)->unique()->values()->all(),
            'compatibility_paths' => $compatibility,
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function categoryDetailsFromCompatibilityPath(string $path): array
    {
        $segments = array_values(array_filter(array_map([$this, 'clean'], explode('/', $path))));
        $details = [];

        if (($segments[0] ?? null) !== null) {
            [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->canonicalModelFromCompatibilitySegment($segments[0]);
            $details['model_label'] = $modelLabel;
            $details['model_name'] = $modelName;
            $details['year_from'] = $yearFrom;
            $details['year_to'] = $yearTo;
        }

        if (($segments[1] ?? null) !== null) {
            [$details['main_category_code'], $details['main_category_name']] = $this->splitCodeName($segments[1]);
        }

        if (($segments[2] ?? null) !== null) {
            [$details['subcategory_code'], $details['subcategory_name']] = $this->splitCodeName($segments[2]);
        }

        if (($segments[3] ?? null) !== null) {
            $details['node_name'] = $segments[3];
        }

        return $details;
    }

    protected function canonicalModelFromCompatibilitySegment(string $segment): array
    {
        if (preg_match('/^(Model\s+[A-Z0-9]+)\s+(\d{4})(?:\s*-\s*(\d{4}))?/u', $segment, $matches) === 1) {
            return [$segment, $matches[1], (int) $matches[2], isset($matches[3]) ? (int) $matches[3] : null];
        }

        return $this->canonicalModel($segment);
    }

    protected function russianProductUrl(string $url, string $baseUrl): ?string
    {
        $absoluteUrl = $this->absoluteUrl($url, $baseUrl);
        if ($absoluteUrl === null) {
            return null;
        }

        if ($this->isRussianUrl($absoluteUrl)) {
            return $absoluteUrl;
        }

        return preg_replace('#://([^/]+)/#', '://$1/ru/', $absoluteUrl, 1) ?: $absoluteUrl;
    }

    protected function ukrainianProductUrl(string $url, string $baseUrl): ?string
    {
        $absoluteUrl = $this->absoluteUrl($url, $baseUrl);
        if ($absoluteUrl === null) {
            return null;
        }

        return preg_replace('#://([^/]+)/ru/#', '://$1/', $absoluteUrl) ?: $absoluteUrl;
    }

    protected function canonicalProductUrl(string $url, string $baseUrl): ?string
    {
        return $this->ukrainianProductUrl($url, $baseUrl);
    }

    protected function canonicalCategoryUrl(string $url, string $baseUrl): ?string
    {
        return $this->ukrainianProductUrl($url, $baseUrl);
    }

    protected function localizedCategoryPath(string $url, string $baseUrl, string $locale, array &$stats, ?string $modelMenuName = null): array
    {
        $localizedUrl = $locale === 'ru'
            ? $this->russianProductUrl($url, $baseUrl)
            : $this->ukrainianProductUrl($url, $baseUrl);

        if ($localizedUrl === null) {
            return [];
        }

        $cacheKey = $locale.'|'.$this->categoryPathTailKey($localizedUrl);
        if (isset($this->localizedCategoryPathCache[$cacheKey])) {
            return array_merge(
                [$this->localizedModelCategoryName($modelMenuName)],
                $this->localizedCategoryPathCache[$cacheKey]
            );
        }

        $html = $this->fetch($localizedUrl);
        if ($html === null) {
            return [];
        }

        $stats['localized_category_pages_fetched'] = ($stats['localized_category_pages_fetched'] ?? 0) + 1;
        $page = $this->page($html);
        $heading = $this->clean($page['xpath']->query('//h1[contains(concat(" ", normalize-space(@class), " "), " main-h ")]')->item(0)?->textContent)
            ?: $this->clean($page['xpath']->query('//h1')->item(0)?->textContent);

        $segments = collect(explode('/', $heading))
            ->map(fn (string $segment): string => $this->clean($segment))
            ->filter()
            ->values()
            ->all();

        $tail = array_slice($segments, 1);
        $this->localizedCategoryPathCache[$cacheKey] = $tail;

        return array_merge(
            [$this->localizedModelCategoryName($modelMenuName) ?: ($segments[0] ?? '')],
            $tail
        );
    }

    protected function categoryPathTailKey(string $url): string
    {
        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));

        if (($segments[0] ?? null) === 'ru') {
            array_shift($segments);
        }

        if ($segments !== []) {
            array_shift($segments);
        }

        return implode('/', $segments);
    }

    protected function localizedModelCategoryName(?string $modelMenuName): string
    {
        $modelMenuName = $this->clean($modelMenuName);

        if (preg_match('/^Model\s+([A-Z0-9]+)(?:-\d+)?\s+(.+)$/u', $modelMenuName, $matches) !== 1) {
            return $modelMenuName;
        }

        $model = preg_replace('/^([SXY])\d+$/u', '$1', $matches[1]) ?: $matches[1];
        $years = trim($matches[2]);

        return trim("Tesla Model {$model} {$years}");
    }

    protected function localizedCategoryTails(array $models, string $baseUrl, array &$stats, ?callable $progress, bool $verbose): array
    {
        $urls = ['ru' => [], 'ua' => []];

        foreach ($models as $model) {
            foreach ($model['subcategories'] as $subcategory) {
                foreach ($subcategory['children'] as $child) {
                    $childUrl = $this->canonicalCategoryUrl($child['url'], $baseUrl);
                    if ($childUrl === null) {
                        continue;
                    }

                    $tailKey = $this->categoryPathTailKey($childUrl);
                    $urls['ru'][$tailKey] ??= $this->russianProductUrl($childUrl, $baseUrl);
                    $urls['ua'][$tailKey] ??= $this->ukrainianProductUrl($childUrl, $baseUrl);
                }
            }
        }

        $paths = ['ru' => [], 'ua' => []];

        foreach ($urls as $locale => $localeUrls) {
            $entries = collect($localeUrls)
                ->filter()
                ->map(fn (string $url, string $key): array => ['key' => $key, 'url' => $url])
                ->values();
            $total = $entries->count();
            $done = 0;
            $challengeCookie = $this->challengeCookieForUrl((string) ($entries->first()['url'] ?? '')) ?: self::CHALLENGE_COOKIE;

            foreach ($entries->chunk(20) as $chunk) {
                $responses = $this->http->pool(function ($pool) use ($chunk, $challengeCookie) {
                    return $chunk
                        ->map(fn (array $entry) => $pool
                            ->withHeaders($this->requestHeaders(['challenge_passed' => $challengeCookie]))
                            ->timeout(30)
                            ->get($entry['url']))
                        ->all();
                });

                foreach ($chunk->values() as $index => $entry) {
                    $response = $responses[$index] ?? null;
                    $body = method_exists($response, 'ok') && $response->ok() && ! str_contains($response->body(), 'challenge_passed=')
                        ? $response->body()
                        : $this->fetch($entry['url']);

                    if ($body !== null && ! str_contains($body, 'challenge_passed=')) {
                        $tail = array_slice($this->categoryPathFromHtml($body), 1);
                        if ($tail !== []) {
                            $paths[$locale][$entry['key']] = $tail;
                        }
                    }

                    $done++;
                    $stats['localized_category_pages_fetched'] = ($stats['localized_category_pages_fetched'] ?? 0) + 1;
                }

                $this->progress($progress, $verbose, "DriveParts {$locale} category pages: {$done}/{$total}");
            }
        }

        return $paths;
    }

    protected function challengeCookieForUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        try {
            $response = $this->http
                ->timeout(30)
                ->withHeaders($this->requestHeaders())
                ->get($url);

            return $this->challengeCookieFromBody($response->body());
        } catch (Throwable) {
            return null;
        }
    }

    protected function categoryPathFromHtml(string $html): array
    {
        $page = $this->page($html);
        $heading = $this->clean($page['xpath']->query('//h1[contains(concat(" ", normalize-space(@class), " "), " main-h ")]')->item(0)?->textContent)
            ?: $this->clean($page['xpath']->query('//h1')->item(0)?->textContent);

        return collect(explode('/', $heading))
            ->map(fn (string $segment): string => $this->clean($segment))
            ->filter()
            ->values()
            ->all();
    }

    protected function nextPageUrl(array $page, string $baseUrl): ?string
    {
        $next = $page['xpath']->query('//link[contains(concat(" ", normalize-space(@rel), " "), " next ")]/@href')->item(0)?->nodeValue;
        if ($next !== null && trim($next) !== '') {
            return $this->absoluteUrl($next, $baseUrl);
        }

        foreach ($page['xpath']->query('//a[@href]') as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $class = ' '.$link->getAttribute('class').' ';
            $rel = ' '.$link->getAttribute('rel').' ';
            $text = $this->clean($link->textContent);

            if (str_contains($class, ' next ') || str_contains($rel, ' next ') || $text === '›' || $text === '»') {
                return $this->absoluteUrl($link->getAttribute('href'), $baseUrl);
            }
        }

        return null;
    }

    protected function isRussianUrl(string $url): bool
    {
        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/ru/');
    }

    protected function partNumberFromCard(array $page, DOMElement $productNode, string $title): ?string
    {
        $code = $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " catalogCard-code ")]', $productNode)->item(0)?->textContent);

        if (preg_match('/(?:Артикул|Парт\s*№(?:\s*\(Артикул\))?):\s*([A-Z0-9][A-Z0-9.-]*(?:-[A-Z0-9.-]+)?)/iu', $code, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/^([A-Z0-9]{6,}[A-Z0-9.-]*)\b/iu', $title, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/\b([0-9]{6,}[A-Z0-9.-]*-[A-Z0-9.-]+)\b/iu', $title, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    protected function productName(string $title, ?string $partNumber): string
    {
        if ($partNumber !== null) {
            $title = preg_replace('/^'.preg_quote($partNumber, '/').'\s*/iu', '', $title) ?? $title;
            $title = preg_replace('/\s+'.preg_quote($partNumber, '/').'$/iu', '', $title) ?? $title;
        }

        return $this->clean($title);
    }

    protected function isUsableRussianProductName(?string $name, PartCatalogItem $item): bool
    {
        $name = $this->clean((string) $name);

        if ($name === ''
            || preg_match('/\p{Cyrillic}/u', $name) !== 1
            || preg_match('/^[A-Z0-9.\-]+$/i', $name) === 1
            || preg_match('/^\d+$/', $name) === 1) {
            return false;
        }

        foreach ([$item->name_ua, $item->name] as $fallbackName) {
            if ($this->normalizeNameForCompare((string) $fallbackName) === $this->normalizeNameForCompare($name)
                && $this->looksUkrainian($name)) {
                return false;
            }
        }

        return ! $this->looksUkrainian($name);
    }

    protected function looksUkrainian(string $name): bool
    {
        return preg_match('/[\x{0404}\x{0406}\x{0407}\x{0490}\x{0454}\x{0456}\x{0457}\x{0491}]/u', $name) === 1;
    }

    protected function normalizeNameForCompare(string $name): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', $name)));
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

    protected function priceColor(DOMElement $priceNode): ?string
    {
        $style = $priceNode->getAttribute('style');
        if (preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $matches) !== 1) {
            return null;
        }

        $color = Str::lower(trim($matches[1]));
        $color = preg_replace('/\s+/', '', $color) ?? $color;

        return $color === '' ? null : $color;
    }

    protected function availabilityFromPriceColor(?string $color): ?string
    {
        return match ($color) {
            '#000',
            '#000000',
            'rgb(0,0,0)' => "\u{0412} \u{043D}\u{0430}\u{043B}\u{0438}\u{0447}\u{0438}\u{0438}",
            '#767676',
            'rgb(118,118,118)' => "\u{041D}\u{0435}\u{0442} \u{0432} \u{043D}\u{0430}\u{043B}\u{0438}\u{0447}\u{0438}\u{0438}",
            default => null,
        };
    }

    protected function canonicalModel(string $label): array
    {
        [$modelName, $yearFrom, $yearTo] = $this->modelYears($label);

        return [$label, $modelName, $yearFrom, $yearTo];
    }

    protected function modelYears(string $label): array
    {
        $modelName = match (true) {
            str_starts_with($label, 'Model 3-2') => 'Model 3 Highland',
            str_starts_with($label, 'Model 3') => 'Model 3',
            str_starts_with($label, 'Model S') => 'Model S',
            str_starts_with($label, 'Model X') => 'Model X',
            str_starts_with($label, 'Model Y2') => 'Model Y Juniper',
            str_starts_with($label, 'Model Y') => 'Model Y',
            default => $label,
        };

        $yearFrom = null;
        $yearTo = null;

        if (preg_match('/(20\d{2})(?:\s*-\s*(20\d{2}))?/', $label, $matches) === 1) {
            $yearFrom = (int) $matches[1];
            $yearTo = isset($matches[2]) ? (int) $matches[2] : null;
        }

        return [$modelName, $yearFrom, $yearTo];
    }

    protected function canonicalCategoryName(string $modelLabel, int $depth, ?string $code, ?string $fallback): ?string
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

    protected function mainCategoryUrl(string $subcategoryUrl): string
    {
        return rtrim((string) preg_replace('#/[^/]+/?$#', '/', rtrim($subcategoryUrl, '/')), '/').'/';
    }

    protected function mainCodeFromSubcategoryCode(?string $code): ?string
    {
        if ($code === null || strlen($code) < 2) {
            return null;
        }

        return substr($code, 0, 2);
    }

    protected function mainNameFromUrl(string $url): string
    {
        $segments = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));
        $slug = end($segments) ?: '';

        return Str::headline(str_replace('-', ' ', preg_replace('/^\d+-/', '', $slug)));
    }

    protected function splitCodeName(?string $value): array
    {
        $value = $this->clean($value);

        if (preg_match('/^(\d+)\s*-\s*(.+)$/u', $value, $matches) === 1) {
            return [$matches[1], $this->clean($matches[2])];
        }

        return [null, $value ?: null];
    }

    protected function modelSortOrder(string $label): int
    {
        $order = [
            'Model S1 2012-2016',
            'Model S2 2016-2021',
            'Model S3 2021-',
            'Model X1 2015-2021',
            'Model X2 2021-',
            'Model 3-1 2017-2023',
            'Model 3 Highland 01.2024 -',
            'Model 3-2 2024-',
            'Model Y1 2020-2025',
            'Model Y Juniper 02.2025 -',
            'Model Y2 2025-',
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

    protected function compactPartNumber(string $partNumber): string
    {
        return Str::upper((string) preg_replace('/[^A-Z0-9]+/i', '', $partNumber));
    }

    protected function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.strtok($url, '#');
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return strtok($url, '#');
        }

        return $baseUrl.'/'.ltrim((string) strtok($url, '#'), '/');
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($verbose && $progress !== null) {
            $progress($message);
        }
    }
}
