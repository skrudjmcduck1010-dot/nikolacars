<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Support\CatalogTextEncoding;
use App\Support\PartCatalogRawAttributes;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ErazborkaCatalogImporter
{
    protected string $source = 'erazborka';

    protected const NON_PRODUCT_IMAGE_MARKERS = [
        '29045041cb5adb6891529f10523e1944',
        '2405d98817edee475802cc1775c8750c',
        '572d383670318c85f0b1eddfac714f0d',
        'logo.png',
        'noimage_product',
    ];

    protected const MODEL_ROOT_PATHS = [
        '/catalog/zapchasti-tesla-model-x/',
        '/catalog/zapchasti-tesla-model-3/',
        '/catalog/zapchasti-tesla-model-s/',
        '/catalog/zapchasti-tesla-model-y/',
        '/catalog/zapchasti-tesla-model-3-highland/',
    ];

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://erazborka.com.ua'), '/');
        $startUrl = $this->absoluteUrl((string) ($options['start_url'] ?? '/catalog/'), $baseUrl);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'source_pages_fetched' => 0,
            'models_saved' => 0,
            'categories_saved' => 0,
        ];

        $html = $this->fetch($startUrl);
        if ($html === null) {
            return $stats;
        }

        $stats['source_pages_fetched']++;

        foreach ($this->catalogCategories($this->page($html), $baseUrl) as $model) {
            [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->canonicalModel($model['name'], $model['url']);
            $this->progress($progress, $verbose, "Model: {$modelLabel}");

            $modelCategory = null;
            if (! $dryRun) {
                $modelCategory = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $model['url']],
                    [
                        'source' => $this->source,
                        'parent_id' => null,
                        'depth' => 0,
                        'code' => null,
                        'name' => $modelLabel,
                        'name_ru' => $modelLabel,
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

            foreach ($model['children'] as $index => $category) {
                if (! $dryRun) {
                    PartCatalogCategory::query()->updateOrCreate(
                        ['source_url' => $category['url']],
                        [
                            'source' => $this->source,
                            'parent_id' => $modelCategory?->id,
                            'depth' => 1,
                            'code' => null,
                            'name' => $category['name'],
                            'name_ru' => $category['name'],
                            'model_label' => $modelLabel,
                            'model_name' => $modelName,
                            'year_from' => $yearFrom,
                            'year_to' => $yearTo,
                            'sort_order' => $index + 1,
                            'children_scanned_at' => now(),
                        ]
                    );
                    $stats['categories_saved']++;
                }
            }
        }

        return $stats;
    }

    public function importProducts(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://erazborka.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $rescan = (bool) ($options['rescan'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxCategories = max(0, (int) ($options['max_categories'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $maxPagesPerCategory = max(1, (int) ($options['max_pages_per_category'] ?? 50));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $downloadImages = (bool) ($options['download_images'] ?? true);

        $stats = [
            'source_pages_fetched' => 0,
            'categories_scanned' => 0,
            'category_pages_scanned' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'new_products_saved' => 0,
            'existing_products_refreshed' => 0,
            'product_detail_pages_skipped' => 0,
            'product_detail_pages_fetched' => 0,
            'categories_marked_scanned' => 0,
            'product_images_saved' => 0,
        ];

        $existingProductItemsByUrl = $this->existingProductItemsByUrl();
        $existingProductUrls = array_fill_keys(array_keys($existingProductItemsByUrl), true);

        $query = $this->productImportCategoryQuery();

        if (! $rescan) {
            $query->whereNull('products_scanned_at');
        }

        if ($maxCategories > 0) {
            $query->limit($maxCategories);
        }

        foreach ($query->get() as $category) {
            $stats['categories_scanned']++;
            $this->progress($progress, $verbose, "Category #{$stats['categories_scanned']}: {$category->name}");

            if ($this->isModelRootListingUrl((string) $category->source_url)) {
                $this->progress($progress, $verbose, "  Skipped model root listing: {$category->source_url}");

                if (! $dryRun) {
                    $category->forceFill(['products_scanned_at' => now()])->save();
                    $stats['categories_marked_scanned']++;
                }

                continue;
            }

            $seenUrls = [];
            $pagesScannedForCategory = 0;

            foreach ($this->categoryPageUrls((string) $category->source_url, $baseUrl, $maxPagesPerCategory, $sleepMs, $stats, $progress, $verbose) as $pageUrl => $page) {
                $pagesScannedForCategory++;
                $stats['category_pages_scanned']++;
                $this->listingPagesProgress($progress, $stats);
                $this->progress($progress, $verbose, "  Page {$stats['source_pages_fetched']}: {$pageUrl}");

                foreach ($this->productsFromCategoryPage($page, $baseUrl, $existingProductUrls, $stats) as $product) {
                    if (isset($seenUrls[$product['source_url']])) {
                        continue;
                    }

                    $seenUrls[$product['source_url']] = true;
                    $stats['products_found']++;
                    $this->listingProductsProgress($progress, $stats);

                    if (! $dryRun) {
                        $existingItem = $this->existingProductItemFromMap($product, $existingProductItemsByUrl);
                        if ($existingItem !== null) {
                            $existingItem->forceFill($this->existingProductRefreshPayload($product, $existingItem, $category))->save();
                            $this->recordProductOccurrence($existingItem, $category, $product, $pageUrl);

                            $stats['existing_products_refreshed']++;
                        } else {
                            if (filled($product['source_url'])) {
                                $product = $this->withProductPageDetails($product, (string) $product['source_url'], $baseUrl, $stats, $downloadImages, $progress);
                                $stats['product_detail_pages_fetched']++;
                                $this->pause($sleepMs);
                            }

                            $item = PartCatalogItem::query()->updateOrCreate(
                                ['source_url' => $product['source_url']],
                                $this->productPayload($category, $product)
                            );
                            $this->recordProductOccurrence($item, $category, $product, $pageUrl);

                            $existingProductUrls[$product['source_url']] = true;
                            $this->rememberExistingProductItem($existingProductItemsByUrl, $product, $item);
                            $stats['new_products_saved']++;
                        }

                        $stats['products_saved']++;
                    }

                    if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                        break 3;
                    }
                }
            }

            if (! $dryRun) {
                $category->forceFill(['products_scanned_at' => now()])->save();
                $stats['categories_marked_scanned']++;
            }
        }

        return $stats;
    }

    public function importModelRootProducts(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://erazborka.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $rescan = (bool) ($options['rescan'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxPagesPerCategory = max(1, (int) ($options['max_pages_per_category'] ?? 80));
        $maxCategories = max(0, (int) ($options['max_categories'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $downloadImages = (bool) ($options['download_images'] ?? true);

        $stats = [
            'source_pages_fetched' => 0,
            'models_scanned' => 0,
            'categories_discovered' => 0,
            'categories_saved' => 0,
            'categories_scanned' => 0,
            'category_pages_scanned' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'new_products_saved' => 0,
            'existing_products_refreshed' => 0,
            'product_detail_pages_skipped' => 0,
            'product_detail_pages_fetched' => 0,
            'product_images_saved' => 0,
        ];

        $existingProductUrls = PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereNotNull('source_url')
            ->pluck('source_url')
            ->flip()
            ->all();

        foreach ($this->modelRootUrls($baseUrl) as $rootUrl) {
            $modelCategory = $dryRun ? $this->modelRootCategoryStub($rootUrl) : $this->ensureModelRootCategory($rootUrl);
            $stats['models_scanned']++;
            $this->progress($progress, $verbose, "Model root: {$modelCategory->name}");

            $rootHtml = $this->fetch($rootUrl);
            if ($rootHtml === null) {
                continue;
            }

            $stats['source_pages_fetched']++;
            $leafCategories = $this->discoverLeafCategories(
                $this->page($rootHtml),
                $modelCategory,
                $baseUrl,
                $dryRun,
                $stats,
                $progress,
                $verbose,
                $sleepMs
            );

            foreach ($leafCategories as $category) {
                if (! $rescan && $category->products_scanned_at !== null) {
                    continue;
                }

                if ($maxCategories > 0 && $stats['categories_scanned'] >= $maxCategories) {
                    break 2;
                }

                $stats['categories_scanned']++;
                $this->progress($progress, $verbose, "Leaf category #{$stats['categories_scanned']}: {$category->name}");

                foreach ($this->categoryPageUrls((string) $category->source_url, $baseUrl, $maxPagesPerCategory, $sleepMs, $stats, $progress, $verbose) as $pageUrl => $page) {
                    $stats['category_pages_scanned']++;
                    $this->listingPagesProgress($progress, $stats);
                    $this->progress($progress, $verbose, "  Page {$stats['source_pages_fetched']}: {$pageUrl}");

                    foreach ($this->productsFromCategoryPage($page, $baseUrl, $existingProductUrls, $stats) as $product) {
                        if (! $this->isProductUrlForModel($product['source_url'], (string) $modelCategory->model_name)) {
                            continue;
                        }

                        $stats['products_found']++;
                        $this->listingProductsProgress($progress, $stats);

                        if ($dryRun) {
                            if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                                break 4;
                            }

                            continue;
                        }

                        $existingItem = $this->existingProductItemFromMap($product, $existingProductItemsByUrl);
                        if ($existingItem !== null) {
                            $existingItem
                                ->forceFill($this->existingProductRefreshPayload($product, $existingItem, $category))
                                ->save();
                            $this->recordProductOccurrence($existingItem, $category, $product, $pageUrl);

                            $stats['existing_products_refreshed']++;
                        } else {
                            $product = $this->withProductPageDetails($product, $product['source_url'], $baseUrl, $stats, $downloadImages, $progress);
                            $stats['product_detail_pages_fetched']++;

                            $item = PartCatalogItem::query()->updateOrCreate(
                                ['source_url' => $product['source_url']],
                                $this->productPayload($category, $product)
                            );
                            $this->recordProductOccurrence($item, $category, $product, $pageUrl);

                            $existingProductUrls[$product['source_url']] = true;
                            $this->rememberExistingProductItem($existingProductItemsByUrl, $product, $item);
                            $stats['new_products_saved']++;
                            $this->pause($sleepMs);
                        }

                        $stats['products_saved']++;

                        if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                            break 4;
                        }
                    }
                }

                if (! $dryRun) {
                    $category->forceFill(['products_scanned_at' => now()])->save();
                }
            }
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
            $ruUrl = $this->localizedProductUrl($sourceUrl, 'ru') ?: $sourceUrl;
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

    public function importSavedLeafProducts(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://erazborka.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $rescan = (bool) ($options['rescan'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxCategories = max(0, (int) ($options['max_categories'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $maxPagesPerCategory = max(1, (int) ($options['max_pages_per_category'] ?? 50));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $downloadImages = (bool) ($options['download_images'] ?? true);
        $categoryIds = collect((array) ($options['category_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        $stats = [
            'source_pages_fetched' => 0,
            'categories_scanned' => 0,
            'category_pages_scanned' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'new_products_saved' => 0,
            'existing_products_refreshed' => 0,
            'product_detail_pages_fetched' => 0,
            'product_images_saved' => 0,
            'categories_marked_scanned' => 0,
        ];

        $existingProductItemsByUrl = $this->existingProductItemsByUrl();
        $existingProductUrls = array_fill_keys(array_keys($existingProductItemsByUrl), true);

        $query = PartCatalogCategory::query()
            ->with('parent.parent')
            ->where('source', $this->source)
            ->where('source_url', 'not like', '%/ua/%')
            ->where('depth', '>', 0)
            ->whereDoesntHave('children')
            ->orderBy('id');

        if ($categoryIds !== []) {
            $query->whereIn('id', $categoryIds);
        }

        if (! $rescan) {
            $query->whereNull('products_scanned_at');
        }

        if ($maxCategories > 0) {
            $query->limit($maxCategories);
        }

        foreach ($query->get() as $category) {
            $stats['categories_scanned']++;
            $this->progress($progress, $verbose, "Saved leaf #{$category->id}: {$category->name}");

            if ($this->isModelRootListingUrl((string) $category->source_url)) {
                $this->progress($progress, $verbose, "  Skipped model root listing: {$category->source_url}");

                if (! $dryRun) {
                    $category->forceFill(['products_scanned_at' => now()])->save();
                    $stats['categories_marked_scanned']++;
                }

                continue;
            }

            foreach ($this->categoryPageUrls((string) $category->source_url, $baseUrl, $maxPagesPerCategory, $sleepMs, $stats, $progress, $verbose) as $pageUrl => $page) {
                $stats['category_pages_scanned']++;
                $this->listingPagesProgress($progress, $stats);
                $this->progress($progress, $verbose, "  Page {$stats['source_pages_fetched']}: {$pageUrl}");

                foreach ($this->productsFromCategoryPage($page, $baseUrl, $existingProductUrls, $stats) as $product) {
                    $stats['products_found']++;
                    $this->listingProductsProgress($progress, $stats);

                    if (! $dryRun) {
                        $existingItem = $this->existingProductItemFromMap($product, $existingProductItemsByUrl);
                        if ($existingItem !== null) {
                            $existingItem
                                ->forceFill($this->existingProductRefreshPayload($product, $existingItem, $category))
                                ->save();
                            $this->recordProductOccurrence($existingItem, $category, $product, $pageUrl);

                            $stats['existing_products_refreshed']++;
                        } else {
                            $product = $this->withProductPageDetails($product, $product['source_url'], $baseUrl, $stats, $downloadImages, $progress);
                            $stats['product_detail_pages_fetched']++;

                            $item = PartCatalogItem::query()->updateOrCreate(
                                ['source_url' => $product['source_url']],
                                $this->productPayload($category, $product)
                            );
                            $this->recordProductOccurrence($item, $category, $product, $pageUrl);

                            $existingProductUrls[$product['source_url']] = true;
                            $this->rememberExistingProductItem($existingProductItemsByUrl, $product, $item);
                            $stats['new_products_saved']++;
                            $this->pause($sleepMs);
                        }

                        $stats['products_saved']++;
                    }

                    if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                        break 3;
                    }
                }
            }

            if (! $dryRun) {
                $category->forceFill(['products_scanned_at' => now()])->save();
                $stats['categories_marked_scanned']++;
            }
        }

        return $stats;
    }

    public function refreshProductImages(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://erazborka.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $missingOnly = (bool) ($options['missing_only'] ?? true);
        $onlySuspicious = (bool) ($options['only_suspicious'] ?? false);
        $onlyMismatchedImageCounts = (bool) ($options['only_mismatched_image_counts'] ?? false);
        $startId = max(0, (int) ($options['start_id'] ?? 0));
        $progress = $options['progress'] ?? null;

        $stats = [
            'items_seen' => 0,
            'items_skipped' => 0,
            'items_updated' => 0,
            'product_detail_pages_fetched' => 0,
            'product_images_saved' => 0,
        ];

        $query = PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereNotNull('source_url')
            ->orderBy('id');

        if ($startId > 0) {
            $query->where('id', '>=', $startId);
        }

        if ($onlySuspicious) {
            $query->where(function ($query): void {
                foreach (self::NON_PRODUCT_IMAGE_MARKERS as $marker) {
                    $query->orWhere('raw_attributes', 'like', '%'.$marker.'%');
                }

                $query->orWhere('raw_attributes', 'like', '%/upload/resize_cache/%');
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->get() as $item) {
            $stats['items_seen']++;

            if ($missingOnly && ! $this->itemMissingLocalImages($item)) {
                $stats['items_skipped']++;

                continue;
            }

            $rawAttributes = PartCatalogRawAttributes::from($item);

            if ($onlyMismatchedImageCounts && ! $this->itemHasMismatchedImageCounts($rawAttributes)) {
                $stats['items_skipped']++;

                continue;
            }

            $detailUrl = (string) ($rawAttributes['source_url_ua'] ?? $item->source_url);
            $product = [
                'source_url' => (string) $item->source_url,
                'source_url_ru' => $rawAttributes['source_url_ru'] ?? $this->localizedProductUrl((string) $item->source_url, 'ru'),
                'source_url_ua' => $rawAttributes['source_url_ua'] ?? $this->localizedProductUrl((string) $item->source_url, 'uk'),
                'part_number' => $item->part_number,
                'name' => $item->name,
                'name_ru' => $item->name_ru,
                'name_ua' => $item->name_ua,
                'price_amount' => $item->price_amount === null ? null : (string) $item->price_amount,
                'currency' => $item->currency,
                'availability' => $item->availability,
                'image_url' => $rawAttributes['image_url'] ?? null,
                'image_urls' => (array) ($rawAttributes['image_urls'] ?? []),
                'remote_image_url' => $rawAttributes['remote_image_url'] ?? null,
                'remote_image_urls' => (array) ($rawAttributes['remote_image_urls'] ?? []),
            ];

            $product = $this->withProductPageDetails($product, $detailUrl, $baseUrl, $stats, ! $dryRun, $progress);
            $stats['product_detail_pages_fetched']++;

            if (! $dryRun) {
                $item->forceFill($this->existingProductRefreshPayload($product, $item))->save();
                $stats['items_updated']++;
            }

            if ($progress !== null && $stats['items_seen'] % 25 === 0) {
                $progress("Erazborka images: {$stats['items_seen']} items, {$stats['product_images_saved']} images saved.");
            }

            $this->pause($sleepMs);
        }

        return $stats;
    }

    protected function itemHasMismatchedImageCounts(array $rawAttributes): bool
    {
        $remoteImageUrls = (array) ($rawAttributes['remote_image_urls'] ?? []);

        return $remoteImageUrls !== []
            && count((array) ($rawAttributes['image_urls'] ?? [])) !== count($remoteImageUrls);
    }

    public function purgeNonProductImages(array $options = []): array
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
                foreach (self::NON_PRODUCT_IMAGE_MARKERS as $marker) {
                    $query->orWhere('raw_attributes', 'like', '%'.$marker.'%');
                }
            })
            ->orderBy('id')
            ->get()
            ->each(function ($item) use ($dryRun, $deleteFiles, &$stats): void {
                $stats['items_seen']++;
                $rawAttributes = PartCatalogRawAttributes::from($item);

                $changed = false;

                foreach (['image_urls', 'remote_image_urls'] as $key) {
                    $urls = (array) ($rawAttributes[$key] ?? []);
                    $kept = [];

                    foreach ($urls as $url) {
                        if ($this->isNonProductImageUrl($url)) {
                            $stats['image_references_removed']++;
                            $changed = true;

                            if ($deleteFiles && is_string($url) && ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://') && Storage::disk('public')->exists($url)) {
                                if (! $dryRun) {
                                    Storage::disk('public')->delete($url);
                                }

                                $stats['files_deleted']++;
                            }

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
                    if (array_key_exists($key, $rawAttributes) && $this->isNonProductImageUrl($rawAttributes[$key])) {
                        $stats['image_references_removed']++;
                        $changed = true;

                        if ($deleteFiles && is_string($rawAttributes[$key]) && ! str_starts_with($rawAttributes[$key], 'http://') && ! str_starts_with($rawAttributes[$key], 'https://') && Storage::disk('public')->exists($rawAttributes[$key])) {
                            if (! $dryRun) {
                                Storage::disk('public')->delete($rawAttributes[$key]);
                            }

                            $stats['files_deleted']++;
                        }

                        unset($rawAttributes[$key]);
                    }
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

    protected function itemMissingLocalImages(PartCatalogItem $item): bool
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        foreach ((array) ($rawAttributes['image_urls'] ?? []) as $url) {
            if (is_string($url) && $url !== '' && ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                return ! Storage::disk('public')->exists($url);
            }
        }

        $imageUrl = $rawAttributes['image_url'] ?? null;
        if (is_string($imageUrl) && $imageUrl !== '' && ! str_starts_with($imageUrl, 'http://') && ! str_starts_with($imageUrl, 'https://')) {
            return ! Storage::disk('public')->exists($imageUrl);
        }

        return true;
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = $this->http
                ->connectTimeout(5)
                ->timeout(15)
                ->retry(1, 500)
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

    protected function catalogCategories(array $page, string $baseUrl): array
    {
        $models = [];

        foreach ($page['xpath']->query('//a[@href]') as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($link->getAttribute('href'), $baseUrl);
            if ($url === null || ! str_starts_with($url, $baseUrl.'/catalog/')) {
                continue;
            }

            $path = $this->catalogPath($url);
            $segments = explode('/', $path);
            if (count($segments) !== 2 || $segments[0] !== 'catalog') {
                continue;
            }

            $slug = $segments[1];
            $modelKey = $this->modelKeyFromSlug($slug);
            if ($modelKey === null) {
                continue;
            }

            $name = $this->clean($link->getAttribute('title') ?: $link->textContent);
            if ($name === '') {
                continue;
            }

            if (str_starts_with($slug, 'zapchasti-tesla-model-')) {
                $models[$modelKey] ??= [
                    'url' => $url,
                    'name' => $name,
                    'children' => [],
                ];
                $models[$modelKey]['url'] = $url;
                $models[$modelKey]['name'] = $name;

                continue;
            }

            $models[$modelKey] ??= [
                'url' => $baseUrl.'/catalog/zapchasti-tesla-model-'.$modelKey.'/',
                'name' => 'Запчасти TESLA MODEL '.strtoupper(str_replace('-', ' ', $modelKey)),
                'children' => [],
            ];
            $models[$modelKey]['children'][$url] = [
                'url' => $url,
                'name' => $name,
            ];
        }

        foreach ($models as $key => $model) {
            if ($model['children'] === []) {
                unset($models[$key]);

                continue;
            }

            $models[$key]['children'] = array_values($model['children']);
        }

        return array_values($models);
    }

    protected function modelKeyFromSlug(string $slug): ?string
    {
        foreach (['3-highland', 'y', 'x', 's', '3'] as $modelKey) {
            if ($slug === 'zapchasti-tesla-model-'.$modelKey || str_ends_with($slug, 'tesla-model-'.$modelKey)) {
                return $modelKey;
            }
        }

        return null;
    }

    protected function catalogPath(string $url): string
    {
        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));

        if (($segments[0] ?? null) === 'ua') {
            array_shift($segments);
        }

        return implode('/', $segments);
    }

    protected function modelRootUrls(string $baseUrl): array
    {
        return array_map(
            fn (string $path): string => $this->absoluteUrl($path, $baseUrl) ?: $baseUrl.$path,
            self::MODEL_ROOT_PATHS
        );
    }

    protected function ensureModelRootCategory(string $rootUrl): PartCatalogCategory
    {
        [$label, $modelName, $yearFrom, $yearTo] = $this->canonicalModel($this->modelLabelFromUrl($rootUrl), $rootUrl);

        return PartCatalogCategory::query()->updateOrCreate(
            ['source_url' => $rootUrl],
            [
                'source' => $this->source,
                'parent_id' => null,
                'depth' => 0,
                'code' => null,
                'name' => $label,
                'name_ru' => $label,
                'name_ua' => $label,
                'model_label' => $label,
                'model_name' => $modelName,
                'year_from' => $yearFrom,
                'year_to' => $yearTo,
                'sort_order' => $this->modelSortOrder($label),
                'children_scanned_at' => now(),
            ]
        );
    }

    protected function modelRootCategoryStub(string $rootUrl): PartCatalogCategory
    {
        [$label, $modelName, $yearFrom, $yearTo] = $this->canonicalModel($this->modelLabelFromUrl($rootUrl), $rootUrl);

        $category = new PartCatalogCategory;
        $category->forceFill([
            'source_url' => $rootUrl,
            'source' => $this->source,
            'depth' => 0,
            'name' => $label,
            'name_ru' => $label,
            'name_ua' => $label,
            'model_label' => $label,
            'model_name' => $modelName,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
        ]);

        return $category;
    }

    protected function productImportCategoryQuery()
    {
        return PartCatalogCategory::query()
            ->with('parent')
            ->where('source', $this->source)
            ->where('source_url', 'not like', '%/ua/%')
            ->where('depth', '>', 0)
            ->orderBy('id');
    }

    protected function isModelRootListingUrl(string $url): bool
    {
        return preg_match(
            '#^catalog/zapchasti-tesla-model-(?:3-highland|3|s|x|y)/?$#',
            $this->catalogPath($url)
        ) === 1;
    }

    protected function modelLabelFromUrl(string $url): string
    {
        $path = $this->catalogPath($url);

        return match (true) {
            str_contains($path, 'model-3-highland') => 'Model 3 Highland 01.2024 -',
            str_contains($path, 'model-3') => 'Model 3',
            str_contains($path, 'model-s') => 'Model S',
            str_contains($path, 'model-x') => 'Model X',
            str_contains($path, 'model-y') => 'Model Y',
            default => 'Tesla',
        };
    }

    protected function discoverLeafCategories(
        array $modelPage,
        PartCatalogCategory $modelCategory,
        string $baseUrl,
        bool $dryRun,
        array &$stats,
        ?callable $progress,
        bool $verbose,
        int $sleepMs
    ): array {
        $leaves = [];

        foreach ($this->sectionCategoriesFromPage($modelPage, $baseUrl) as $index => $mainCategoryData) {
            $mainCategory = $dryRun
                ? $this->categoryStub($modelCategory, $mainCategoryData, 1, $index + 1)
                : $this->saveCategory($modelCategory, $mainCategoryData, 1, $index + 1, $stats);

            $this->progress($progress, $verbose, "  Category: {$mainCategory->name}");

            $html = $this->fetch((string) $mainCategory->source_url);
            if ($html === null) {
                $leaves[] = $mainCategory;

                continue;
            }

            $stats['source_pages_fetched']++;
            $subcategories = $this->sectionCategoriesFromPage($this->page($html), $baseUrl);
            if ($subcategories === []) {
                $leaves[] = $mainCategory;

                continue;
            }

            foreach ($subcategories as $subIndex => $subcategoryData) {
                $subcategory = $dryRun
                    ? $this->categoryStub($mainCategory, $subcategoryData, 2, $subIndex + 1)
                    : $this->saveCategory($mainCategory, $subcategoryData, 2, $subIndex + 1, $stats);

                $this->progress($progress, $verbose, "    Subcategory: {$subcategory->name}");
                $leaves[] = $subcategory;
            }

            $this->pause($sleepMs);
        }

        return $leaves;
    }

    protected function sectionCategoriesFromPage(array $page, string $baseUrl): array
    {
        $categories = [];

        foreach ($page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " section-compact-list__item ")]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $link = $page['xpath']->query('.//a[contains(concat(" ", normalize-space(@class), " "), " section-compact-list__link ")][@href]', $node)->item(0)
                ?: $page['xpath']->query('.//a[@href]', $node)->item(0);

            if (! $link instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($link->getAttribute('href'), $baseUrl);
            $name = $this->clean($link->getAttribute('title') ?: $link->textContent);
            if ($url === null || $name === '' || ! str_starts_with($this->catalogPath($url), 'catalog/')) {
                continue;
            }

            $imageNode = $page['xpath']->query('.//img[@data-src or @src]', $node)->item(0);
            $imageUrl = $imageNode instanceof DOMElement
                ? $this->absoluteUrl($imageNode->getAttribute('data-src') ?: $imageNode->getAttribute('src'), $baseUrl)
                : null;

            $categories[$url] = [
                'url' => $url,
                'name' => $name,
                'preview_image_url' => $imageUrl,
            ];
        }

        return array_values($categories);
    }

    protected function saveCategory(PartCatalogCategory $parent, array $category, int $depth, int $sortOrder, array &$stats): PartCatalogCategory
    {
        $stats['categories_discovered']++;
        $modelLabel = $parent->model_label;
        $modelName = $parent->model_name;
        $yearFrom = $parent->year_from;
        $yearTo = $parent->year_to;

        $saved = PartCatalogCategory::query()->updateOrCreate(
            ['source_url' => $category['url']],
            [
                'source' => $this->source,
                'parent_id' => $parent->id,
                'depth' => $depth,
                'code' => null,
                'name' => $category['name'],
                'name_ru' => $category['name'],
                'model_label' => $modelLabel,
                'model_name' => $modelName,
                'year_from' => $yearFrom,
                'year_to' => $yearTo,
                'sort_order' => $sortOrder,
                'preview_image_url' => $category['preview_image_url'] ?? null,
                'children_scanned_at' => now(),
            ]
        );

        $stats['categories_saved']++;
        $saved->setRelation('parent', $parent);

        return $saved;
    }

    protected function categoryStub(PartCatalogCategory $parent, array $category, int $depth, int $sortOrder): PartCatalogCategory
    {
        $stub = new PartCatalogCategory;
        $stub->forceFill([
            'source_url' => $category['url'],
            'source' => $this->source,
            'parent_id' => $parent->id,
            'depth' => $depth,
            'name' => $category['name'],
            'name_ru' => $category['name'],
            'model_label' => $parent->model_label,
            'model_name' => $parent->model_name,
            'year_from' => $parent->year_from,
            'year_to' => $parent->year_to,
            'sort_order' => $sortOrder,
        ]);
        $stub->setRelation('parent', $parent);

        return $stub;
    }

    protected function isProductUrlForModel(string $url, string $modelName): bool
    {
        $path = $this->catalogPath($url);

        return match ($modelName) {
            'Model 3 Highland' => str_contains($path, 'model-3-highland'),
            'Model 3' => str_contains($path, 'model-3') && ! str_contains($path, 'model-3-highland'),
            'Model S' => str_contains($path, 'model-s'),
            'Model X' => str_contains($path, 'model-x'),
            'Model Y' => str_contains($path, 'model-y'),
            default => true,
        };
    }

    protected function categoryPageUrls(
        string $categoryUrl,
        string $baseUrl,
        int $maxPagesPerCategory,
        int $sleepMs,
        array &$stats,
        ?callable $progress = null,
        bool $verbose = false
    ): array {
        $pages = [];
        $html = $this->fetch($categoryUrl);
        if ($html === null) {
            return $pages;
        }

        $stats['source_pages_fetched']++;
        $page = $this->page($html);
        $pages[$categoryUrl] = $page;

        $lastPage = min($this->lastPageNumber($page), $maxPagesPerCategory);
        for ($pageNumber = 2; $pageNumber <= $lastPage; $pageNumber++) {
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            $url = $categoryUrl.(str_contains($categoryUrl, '?') ? '&' : '?').'PAGEN_1='.$pageNumber;
            $html = $this->fetch($url);
            if ($html === null) {
                continue;
            }

            $stats['source_pages_fetched']++;
            $page = $this->page($html);
            if (! $this->categoryPageHasProducts($page)) {
                break;
            }

            $pages[$url] = $page;
        }

        return $pages;
    }

    protected function lastPageNumber(array $page): int
    {
        $lastPage = 1;

        foreach ($page['xpath']->query('//a[@href]') as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $href = html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/[?&]PAGEN_1=(\d+)/', $href, $matches) === 1) {
                $lastPage = max($lastPage, (int) $matches[1]);
            }
        }

        return $lastPage;
    }

    protected function categoryPageHasProducts(array $page): bool
    {
        return $page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " catalog_item_wrapp ")]')->length > 0
            || $page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " item-title ")]//a[@href]')->length > 0;
    }

    protected function productsFromCategoryPage(array $page, string $baseUrl, array $existingProductUrls = [], array &$stats = []): array
    {
        $products = [];

        foreach ($page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " catalog_item_wrapp ")]') as $productNode) {
            if (! $productNode instanceof DOMElement) {
                continue;
            }

            $linkNode = $page['xpath']->query('.//div[contains(concat(" ", normalize-space(@class), " "), " item-title ")]//a[@href]', $productNode)->item(0)
                ?: $page['xpath']->query('.//a[contains(concat(" ", normalize-space(@class), " "), " thumb ")][@href]', $productNode)->item(0);

            if (! $linkNode instanceof DOMElement) {
                continue;
            }

            $sourceUrl = $this->absoluteUrl($linkNode->getAttribute('href'), $baseUrl);
            $name = $this->clean($linkNode->getAttribute('title') ?: $linkNode->textContent);
            if ($sourceUrl === null || $name === '') {
                continue;
            }
            $sourceUrlUa = $this->localizedProductUrl($sourceUrl, 'uk');
            $isUaUrl = str_contains((string) parse_url($sourceUrl, PHP_URL_PATH), '/ua/catalog/');
            if (isset($existingProductUrls[$sourceUrl])) {
                $stats['product_detail_pages_skipped'] = ($stats['product_detail_pages_skipped'] ?? 0) + 1;
            }

            $priceNode = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " price ") and @data-value]', $productNode)->item(0);
            $imageNode = $page['xpath']->query('.//img[@data-src or @src]', $productNode)->item(0);
            $imageUrl = null;
            if ($imageNode instanceof DOMElement) {
                $imageUrl = $this->absoluteUrl($imageNode->getAttribute('data-src') ?: $imageNode->getAttribute('src'), $baseUrl);
            }

            $products[$sourceUrl] = [
                'source_url' => $sourceUrl,
                'part_number' => $this->partNumberFromCard($productNode, $name),
                'name' => $name,
                'name_ru' => $isUaUrl ? null : $name,
                'name_ua' => $isUaUrl ? $name : null,
                'price_amount' => $priceNode instanceof DOMElement ? $this->priceAmount($priceNode->getAttribute('data-value')) : null,
                'currency' => $priceNode instanceof DOMElement ? ($priceNode->getAttribute('data-currency') ?: 'UAH') : null,
                'availability' => $this->availabilityFromCard($productNode),
                'image_url' => $imageUrl,
                'image_urls' => array_values(array_filter([$imageUrl])),
                'remote_image_url' => $imageUrl,
                'remote_image_urls' => array_values(array_filter([$imageUrl])),
                'source_url_ru' => $this->localizedProductUrl($sourceUrl, 'ru') ?: $sourceUrl,
                'source_url_ua' => $sourceUrlUa,
            ];
        }

        foreach ($page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " item-title ")]//a[@href]') as $linkNode) {
            if (! $linkNode instanceof DOMElement) {
                continue;
            }

            $sourceUrl = $this->absoluteUrl($linkNode->getAttribute('href'), $baseUrl);
            $name = $this->clean($linkNode->getAttribute('title') ?: $linkNode->textContent);
            if ($sourceUrl === null || $name === '' || isset($products[$sourceUrl]) || ! str_contains($this->catalogPath($sourceUrl), '/zapchasti-tesla-model-')) {
                continue;
            }

            $productNode = $page['xpath']->query('./ancestor::div[contains(concat(" ", normalize-space(@class), " "), " item ")][1]', $linkNode)->item(0)
                ?: $linkNode->parentNode;
            if (! $productNode instanceof DOMElement) {
                continue;
            }

            $sourceUrlUa = $this->localizedProductUrl($sourceUrl, 'uk');
            $isUaUrl = str_contains((string) parse_url($sourceUrl, PHP_URL_PATH), '/ua/catalog/');
            if (isset($existingProductUrls[$sourceUrl])) {
                $stats['product_detail_pages_skipped'] = ($stats['product_detail_pages_skipped'] ?? 0) + 1;
            }

            $priceNode = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " price ") and @data-value]', $productNode)->item(0)
                ?: $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " price ")]', $productNode)->item(0);
            $imageNode = $page['xpath']->query('.//img[@data-src or @src]', $productNode)->item(0);
            $imageUrl = $imageNode instanceof DOMElement
                ? $this->absoluteUrl($imageNode->getAttribute('data-src') ?: $imageNode->getAttribute('src'), $baseUrl)
                : null;

            $products[$sourceUrl] = [
                'source_url' => $sourceUrl,
                'part_number' => $this->partNumberFromCard($productNode, $name),
                'name' => $name,
                'name_ru' => $isUaUrl ? null : $name,
                'name_ua' => $isUaUrl ? $name : null,
                'price_amount' => $priceNode instanceof DOMElement ? $this->priceAmount($priceNode->getAttribute('data-value') ?: $priceNode->textContent) : null,
                'currency' => $priceNode instanceof DOMElement ? ($priceNode->getAttribute('data-currency') ?: 'UAH') : null,
                'availability' => $this->availabilityFromCard($productNode),
                'image_url' => $imageUrl,
                'image_urls' => array_values(array_filter([$imageUrl])),
                'remote_image_url' => $imageUrl,
                'remote_image_urls' => array_values(array_filter([$imageUrl])),
                'source_url_ru' => $this->localizedProductUrl($sourceUrl, 'ru') ?: $sourceUrl,
                'source_url_ua' => $sourceUrlUa,
            ];
        }

        return array_values($products);
    }

    protected function existingProductRefreshPayload(array $product, ?PartCatalogItem $item = null, ?PartCatalogCategory $category = null): array
    {
        return array_filter([
            ...($category === null ? [] : $this->categoryItemPayload($category)),
            'part_number' => $product['part_number'],
            'name' => $product['name'],
            'name_ru' => $product['name_ru'],
            'name_ua' => $product['name_ua'],
            'price_amount' => $product['price_amount'],
            'currency' => $product['currency'],
            'availability' => $product['availability'],
            'raw_attributes' => $this->mergedRawAttributesForProduct($product, $item, $category),
            'source_updated_at' => now(),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    protected function productPayload(PartCatalogCategory $category, array $product): array
    {
        return [
            ...$this->categoryItemPayload($category),
            'source' => $this->source,
            'source_url' => $product['source_url'],
            'part_number' => $product['part_number'],
            'name' => $product['name'],
            'name_ru' => $product['name_ru'],
            'name_ua' => $product['name_ua'],
            'price_amount' => $product['price_amount'],
            'currency' => $product['currency'],
            'condition' => 'used',
            'availability' => $product['availability'],
            'raw_attributes' => array_filter([
                'category_source_url' => $category->source_url,
                'image_url' => $product['image_url'],
                'image_urls' => $product['image_urls'] ?? null,
                'remote_image_url' => $product['remote_image_url'] ?? null,
                'remote_image_urls' => $product['remote_image_urls'] ?? null,
                'attributes' => $product['attributes'] ?? null,
                'source_url_ru' => $product['source_url_ru'] ?? null,
                'source_url_ua' => $product['source_url_ua'] ?? null,
            ]),
            'source_updated_at' => now(),
        ];
    }

    protected function recordProductOccurrence(PartCatalogItem $item, PartCatalogCategory $category, array $product, string $pageUrl): void
    {
        $productUrl = (string) ($product['source_url'] ?? $item->source_url);
        $occurrenceKey = sha1(implode('|', [
            $this->source,
            $category->id,
            $productUrl,
        ]));

        PartCatalogItemOccurrence::query()->updateOrCreate(
            ['occurrence_key' => $occurrenceKey],
            [
                'part_catalog_item_id' => $item->id,
                'part_catalog_category_id' => $category->id,
                'source' => $this->source,
                'page_url' => $pageUrl,
                'product_url' => $productUrl,
                'part_number' => $product['part_number'] ?? $item->part_number,
                'name' => $product['name'] ?? $item->name,
                'quantity' => null,
                'raw_attributes' => array_filter([
                    'category_source_url' => $category->source_url,
                    'source_url_ru' => $product['source_url_ru'] ?? null,
                    'source_url_ua' => $product['source_url_ua'] ?? null,
                ], fn ($value): bool => $value !== null && $value !== '' && $value !== []),
            ]
        );
    }

    protected function categoryItemPayload(PartCatalogCategory $category): array
    {
        $parent = $category->parent;
        $mainCategory = $category->depth >= 2 ? $parent : $category;
        $modelCategory = $category->depth >= 2 ? $parent?->parent : $parent;
        $path = collect([$modelCategory?->name ?: $category->model_label, $mainCategory?->name, $category->depth >= 2 ? $category->name : null])
            ->filter()
            ->implode(' / ');

        return [
            'part_catalog_category_id' => $category->id,
            'model_label' => $category->model_label,
            'model_name' => $category->model_name,
            'year_from' => $category->year_from,
            'year_to' => $category->year_to,
            'main_category_code' => null,
            'main_category_name' => $mainCategory?->name ?: $category->name,
            'subcategory_code' => null,
            'subcategory_name' => $category->depth >= 2 ? $category->name : null,
            'node_name' => $category->name,
            'compatibility_text' => $path,
        ];
    }

    protected function mergedRawAttributesForProduct(array $product, ?PartCatalogItem $item = null, ?PartCatalogCategory $category = null): array
    {
        $item ??= $this->existingProductItem($product);
        $rawAttributes = PartCatalogRawAttributes::from($item);

        if ($category !== null) {
            $rawAttributes['category_source_url'] = $category->source_url;
        }

        foreach ([
            'image_url',
            'image_urls',
            'remote_image_url',
            'remote_image_urls',
            'source_url_ru',
            'source_url_ua',
        ] as $key) {
            if (array_key_exists($key, $product)) {
                if ($item !== null && str_starts_with($key, 'image') && empty($product['images_from_detail']) && $this->hasLocalImageReference($rawAttributes)) {
                    continue;
                }

                if (($product[$key] ?? null) !== null && ($product[$key] ?? []) !== []) {
                    $rawAttributes[$key] = $product[$key];
                } else {
                    unset($rawAttributes[$key]);
                }
            }
        }

        return array_filter($rawAttributes, fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function hasLocalImageReference(array $rawAttributes): bool
    {
        foreach ((array) ($rawAttributes['image_urls'] ?? []) as $url) {
            if (is_string($url) && $url !== '' && ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                return true;
            }
        }

        $imageUrl = $rawAttributes['image_url'] ?? null;

        return is_string($imageUrl) && $imageUrl !== '' && ! str_starts_with($imageUrl, 'http://') && ! str_starts_with($imageUrl, 'https://');
    }

    protected function existingProductItem(array $product): ?PartCatalogItem
    {
        $urls = collect([
            $product['source_url'] ?? null,
            $product['source_url_ru'] ?? null,
            $product['source_url_ua'] ?? null,
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($urls === []) {
            return null;
        }

        return PartCatalogItem::query()
            ->where('source', $this->source)
            ->where(function ($query) use ($urls): void {
                $query->whereIn('source_url', $urls);

                foreach ($urls as $url) {
                    $query
                        ->orWhere('raw_attributes->source_url_ru', $url)
                        ->orWhere('raw_attributes->source_url_ua', $url);
                }
            })
            ->orderBy('id')
            ->first();
    }

    protected function existingProductItemsByUrl(): array
    {
        $items = [];

        PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereNotNull('source_url')
            ->get()
            ->each(function (PartCatalogItem $item) use (&$items): void {
                $rawAttributes = PartCatalogRawAttributes::from($item);

                foreach ([$item->source_url, $rawAttributes['source_url_ru'] ?? null, $rawAttributes['source_url_ua'] ?? null] as $url) {
                    if (is_string($url) && $url !== '') {
                        $items[$url] = $item;
                    }
                }
            });

        return $items;
    }

    protected function existingProductItemFromMap(array $product, array $itemsByUrl): ?PartCatalogItem
    {
        foreach ([$product['source_url'] ?? null, $product['source_url_ru'] ?? null, $product['source_url_ua'] ?? null] as $url) {
            if (is_string($url) && isset($itemsByUrl[$url])) {
                return $itemsByUrl[$url];
            }
        }

        return null;
    }

    protected function rememberExistingProductItem(array &$itemsByUrl, array $product, PartCatalogItem $item): void
    {
        foreach ([$product['source_url'] ?? null, $product['source_url_ru'] ?? null, $product['source_url_ua'] ?? null] as $url) {
            if (is_string($url) && $url !== '') {
                $itemsByUrl[$url] = $item;
            }
        }
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

        return $name !== '' ? $name : null;
    }

    protected function withProductPageDetails(
        array $product,
        string $url,
        string $baseUrl,
        array &$stats,
        bool $downloadImages = true,
        ?callable $progress = null
    ): array {
        if ($progress !== null) {
            $progress("Opening product page: {$url}");
        }

        $html = $this->fetch($url);
        if ($html === null) {
            return $product;
        }

        $stats['source_pages_fetched'] = (int) ($stats['source_pages_fetched'] ?? 0) + 1;
        $page = $this->page($html);
        $name = $this->productNameFromPage($page);
        if ($name !== null) {
            $product['name'] = $name;

            if (str_contains((string) parse_url($url, PHP_URL_PATH), '/ua/catalog/')) {
                $product['name_ua'] = $name;
            } else {
                $product['name_ru'] = $name;
            }
        }

        $partNumber = $this->partNumberFromProductPage($page, $product['part_number'] ?? null);
        if ($partNumber !== null) {
            $product['part_number'] = $partNumber;
        }

        $price = $this->priceFromProductPage($page);
        if ($price['amount'] !== null) {
            $product['price_amount'] = $price['amount'];
            $product['currency'] = $price['currency'] ?? $product['currency'];
        }

        $availability = $this->availabilityFromProductPage($page);
        if ($availability !== null) {
            $product['availability'] = $availability;
        }

        $attributes = $this->attributesFromProductPage($page);
        if ($attributes !== []) {
            $product['attributes'] = $attributes;
        }

        $remoteImageUrls = $this->imageUrlsFromProductPage($page, $baseUrl);
        $product['images_from_detail'] = true;
        $product['remote_image_url'] = $remoteImageUrls[0] ?? null;
        $product['remote_image_urls'] = $remoteImageUrls;
        $product['image_url'] = $remoteImageUrls[0] ?? null;
        $product['image_urls'] = $remoteImageUrls;

        $localImagePaths = [];
        if ($downloadImages) {
            foreach ($remoteImageUrls as $imageUrl) {
                $path = $this->downloadProductImage($product['part_number'] ?: 'unknown', $imageUrl);
                if ($path !== null) {
                    $localImagePaths[] = $path;
                    $stats['product_images_saved'] = (int) ($stats['product_images_saved'] ?? 0) + 1;
                }
            }
        }

        if ($localImagePaths !== []) {
            $product['image_url'] = $localImagePaths[0];
            $product['image_urls'] = array_values(array_unique($localImagePaths));
        }

        return $product;
    }

    protected function productNameFromPage(array $page): ?string
    {
        $node = $page['xpath']->query('//h1')->item(0)
            ?: $page['xpath']->query('//meta[@property="og:title"]')->item(0)
            ?: $page['xpath']->query('//title')->item(0);
        $name = $node instanceof DOMElement
            ? $this->clean($node->getAttribute('content') ?: $node->textContent)
            : '';

        return $name !== '' ? $name : null;
    }

    protected function partNumberFromProductPage(array $page, ?string $fallback): ?string
    {
        $text = $this->clean($page['document']->textContent);

        if (preg_match('/(?:Артикул|Арт\.?):\s*([A-Z0-9][A-Z0-9.,\/\s-]*)/iu', $text, $matches) === 1) {
            return $this->canonicalPartNumber($matches[1]);
        }

        return $fallback;
    }

    protected function priceFromProductPage(array $page): array
    {
        $priceNode = $page['xpath']->query('//*[@data-value and contains(concat(" ", normalize-space(@class), " "), " price ")]')->item(0)
            ?: $page['xpath']->query('//*[@data-value and @data-currency]')->item(0);

        if ($priceNode instanceof DOMElement) {
            return [
                'amount' => $this->priceAmount($priceNode->getAttribute('data-value')),
                'currency' => $priceNode->getAttribute('data-currency') ?: 'UAH',
            ];
        }

        $text = $this->clean($page['document']->textContent);
        if (preg_match('/([0-9][0-9\s.,]*)\s*(?:грн|UAH)/iu', $text, $matches) === 1) {
            return ['amount' => $this->priceAmount($matches[1]), 'currency' => 'UAH'];
        }

        return ['amount' => null, 'currency' => null];
    }

    protected function availabilityFromProductPage(array $page): ?string
    {
        $text = mb_strtolower($this->clean($page['document']->textContent), 'UTF-8');

        return match (true) {
            str_contains($text, 'немає в наявності') || str_contains($text, 'нет в наличии') => 'out of stock',
            str_contains($text, 'в наявності') || str_contains($text, 'в наличии') => 'in stock',
            default => null,
        };
    }

    protected function attributesFromProductPage(array $page): array
    {
        $attributes = [];

        foreach ($page['xpath']->query('//tr[th or td]') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = $page['xpath']->query('./th|./td', $row);
            if ($cells->length < 2) {
                continue;
            }

            $key = $this->clean($cells->item(0)?->textContent);
            $value = $this->clean($cells->item(1)?->textContent);

            if ($key !== '' && $value !== '' && mb_strlen($key) <= 80 && mb_strlen($value) <= 255) {
                $attributes[$key] = $value;
            }
        }

        foreach ($page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " properties__item ")]') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $key = $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " properties__name ")]', $row)->item(0)?->textContent);
            $value = $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " properties__value ")]', $row)->item(0)?->textContent);

            if ($key !== '' && $value !== '') {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    protected function imageUrlsFromProductPage(array $page, string $baseUrl): array
    {
        $urls = [];

        foreach ($page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " product-detail-gallery ")]//a[@href] | //*[contains(concat(" ", normalize-space(@class), " "), " product-detail-gallery ")]//link[@itemprop="image" and @href] | //*[contains(concat(" ", normalize-space(@class), " "), " product-detail-gallery ")]//img[@data-src or @src]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $node->hasAttribute('href')
                ? $node->getAttribute('href')
                : ($node->getAttribute('data-src') ?: $node->getAttribute('src'));
            $absoluteUrl = $this->absoluteUrl($url, $baseUrl);

            if ($this->isProductImageUrl($absoluteUrl) && ! str_contains($absoluteUrl, '/upload/resize_cache/')) {
                $urls[] = $absoluteUrl;
            }
        }

        return collect($urls)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function isProductImageUrl(?string $url): bool
    {
        return $url !== null
            && ! $this->isNonProductImageUrl($url)
            && preg_match('~/upload/iblock/.+\.(?:jpe?g|png|webp)(?:[?#].*)?$~iu', $url) === 1;
    }

    protected function isNonProductImageUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        foreach (self::NON_PRODUCT_IMAGE_MARKERS as $marker) {
            if (str_contains($url, $marker)) {
                return true;
            }
        }

        return false;
    }

    protected function localizedProductUrl(string $url, string $locale): ?string
    {
        if ($locale === 'uk') {
            if (str_contains((string) parse_url($url, PHP_URL_PATH), '/ua/catalog/')) {
                return $url;
            }

            return preg_replace('#://([^/]+)/catalog/#', '://$1/ua/catalog/', $url) ?: $url;
        }

        if ($locale === 'ru') {
            return preg_replace('#://([^/]+)/ua/catalog/#', '://$1/catalog/', $url) ?: $url;
        }

        return null;
    }

    protected function pause(int $sleepMs): void
    {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    protected function partNumberFromCard(DOMElement $productNode, string $name): ?string
    {
        $text = $this->clean($productNode->textContent);

        if (preg_match('/(?:Арт\.?|Артикул):\s*([A-Z0-9][A-Z0-9.,\/\s-]*)/iu', $text, $matches) === 1) {
            return $this->canonicalPartNumber($matches[1]);
        }

        if (preg_match('/\b([0-9]{6,}[A-Z0-9.,-]*-[A-Z0-9.,-]+)\b/iu', $name, $matches) === 1) {
            return $this->canonicalPartNumber($matches[1]);
        }

        return null;
    }

    protected function canonicalPartNumber(string $value): ?string
    {
        $value = $this->clean($value);
        $value = trim($value, " \t\n\r\0\x0B,.;");
        $value = preg_replace('/\s+/u', '', $value) ?: $value;

        if (preg_match('/^([0-9]{7}-[A-Z0-9]{2}-[A-ZА-Я]{1,2})(?:-(?:ASR|TC|SR|GR)|[0-9]|$)/iu', $value, $matches) === 1) {
            $value = $matches[1];
        }

        return $value === '' ? null : Str::upper($value);
    }

    protected function availabilityFromCard(DOMElement $productNode): ?string
    {
        $text = Str::lower($this->clean($productNode->textContent));

        return match (true) {
            str_contains($text, 'немає в наявності') => 'out of stock',
            str_contains($text, 'в наявності') => 'in stock',
            str_contains($text, 'нет в наличии') => 'out of stock',
            str_contains($text, 'в наличии') => 'in stock',
            default => null,
        };
    }

    protected function priceAmount(?string $value): ?string
    {
        $value = $this->clean($value);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $value);
        if (! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    protected function canonicalModel(string $label, string $url): array
    {
        $slug = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return match (true) {
            str_contains($slug, 'model-3-highland') => ['Model 3 Highland 01.2024 -', 'Model 3 Highland', 2024, null],
            str_contains($slug, 'model-3') => ['Model 3', 'Model 3', null, null],
            str_contains($slug, 'model-s') => ['Model S', 'Model S', null, null],
            str_contains($slug, 'model-x') => ['Model X', 'Model X', null, null],
            str_contains($slug, 'model-y') => ['Model Y', 'Model Y', null, null],
            default => [$label, $label, null, null],
        };
    }

    protected function modelSortOrder(string $label): int
    {
        return match ($label) {
            'Model S' => 10,
            'Model X' => 20,
            'Model 3' => 30,
            'Model 3 Highland 01.2024 -' => 31,
            'Model Y' => 40,
            default => 999,
        };
    }

    protected function downloadProductImage(string $partNumber, string $url): ?string
    {
        $path = $this->productImagePath($partNumber, $url);
        if ($path === null) {
            return null;
        }

        if (Storage::disk('public')->exists($path)) {
            return $path;
        }

        try {
            $response = $this->http
                ->connectTimeout(10)
                ->timeout(20)
                ->retry(1, 300)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                    'Referer' => 'https://erazborka.com.ua/',
                ])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return null;
        }

        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    protected function productImagePath(string $partNumber, string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $name = Str::slug(Str::limit(pathinfo($path, PATHINFO_FILENAME) ?: sha1($url), 80, ''), '-');
        if ($name === '') {
            $name = sha1($url);
        }

        return 'erazborka/part-images/'.$this->compactPartNumber($partNumber).'/'.$name.'-'.substr(sha1($url), 0, 10).'.'.$extension;
    }

    protected function compactPartNumber(string $partNumber): string
    {
        $compact = preg_replace('/[^A-Z0-9]/i', '', $partNumber) ?: 'UNKNOWN';

        return Str::upper($compact);
    }

    protected function clean(?string $value): string
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = CatalogTextEncoding::repair($value) ?? $value;

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    protected function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return strtok($url, '#');
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.strtok($url, '#');
        }

        return $baseUrl.'/'.ltrim((string) strtok($url, '#'), '/');
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($verbose && $progress !== null) {
            $progress($message);
        }
    }

    protected function listingProductsProgress(?callable $progress, array $stats): void
    {
        if ($progress !== null && ((int) $stats['products_found'] === 1 || (int) $stats['products_found'] % 50 === 0)) {
            $progress("Listing products seen {$stats['products_found']}");
        }
    }

    protected function listingPagesProgress(?callable $progress, array $stats): void
    {
        if ($progress !== null) {
            $progress("Opened listing pages {$stats['category_pages_scanned']}");
        }
    }
}
