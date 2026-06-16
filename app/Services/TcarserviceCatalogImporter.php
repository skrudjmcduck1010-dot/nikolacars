<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class TcarserviceCatalogImporter
{
    protected const DEFAULT_EXCLUDED_PATH_PATTERNS = [
        '/zapchasty/cybertruck-',
        '/zapchasty/rivian-',
        '/zapchasty/lucid-air-',
    ];

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://tcarservice.com'), '/');
        $startUrl = $this->absoluteUrl((string) ($options['start_url'] ?? '/zapchasty'), $baseUrl);
        $excludedPathPatterns = $options['excluded_path_patterns'] ?? self::DEFAULT_EXCLUDED_PATH_PATTERNS;
        $maxCategoryPages = (int) ($options['max_category_pages'] ?? 0);
        $maxProducts = (int) ($options['max_products'] ?? 0);
        $sleepMs = (int) ($options['sleep_ms'] ?? 250);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $categoriesOnly = (bool) ($options['categories_only'] ?? false);
        $skipExistingCategories = (bool) ($options['skip_existing_categories'] ?? true);
        $categoriesOnly = (bool) ($options['categories_only'] ?? false);

        $queue = [$startUrl];
        $visitedCategoryUrls = [];
        $visitedProductUrls = [];
        $previewImageUrls = [];
        $stats = [
            'category_pages_seen' => 0,
            'category_pages_fetched' => 0,
            'category_pages_skipped_existing' => 0,
            'category_links_found' => 0,
            'categories_saved' => 0,
            'categories_only' => $categoriesOnly ? 1 : 0,
            'product_pages_seen' => 0,
            'product_links_found' => 0,
            'products_saved' => 0,
            'skipped_product_links' => 0,
        ];

        while ($queue !== []) {
            $categoryUrl = array_shift($queue);
            $categoryPath = rtrim(parse_url($categoryUrl, PHP_URL_PATH) ?: '', '/');
            $isCatalogRoot = $categoryPath === '/zapchasty';

            if (isset($visitedCategoryUrls[$categoryUrl]) || $this->isExcludedUrl($categoryUrl, $excludedPathPatterns)) {
                continue;
            }

            $visitedCategoryUrls[$categoryUrl] = true;
            $stats['category_pages_seen']++;

            $this->progress($progress, $verbose, "Category #{$stats['category_pages_seen']}: {$categoryUrl}");

            if (! $dryRun && $skipExistingCategories && ! $isCatalogRoot) {
                $existingCategory = PartCatalogCategory::query()
                    ->where('source_url', $categoryUrl)
                    ->first();

                if ($existingCategory !== null) {
                    $childUrls = $existingCategory->children()->pluck('source_url');

                    if ($existingCategory->children_scanned_at !== null && ($categoriesOnly || $childUrls->isNotEmpty())) {
                        foreach ($childUrls->reverse()->values() as $childUrl) {
                            if (! isset($visitedCategoryUrls[$childUrl]) && ! in_array($childUrl, $queue, true)) {
                                array_unshift($queue, $childUrl);
                            }
                        }

                        $stats['category_pages_skipped_existing']++;
                        $this->progress($progress, $verbose, "  skipped existing category with {$childUrls->count()} saved child categories");

                        continue;
                    }
                }
            }

            if ($maxCategoryPages > 0 && $stats['category_pages_fetched'] >= $maxCategoryPages) {
                break;
            }

            $html = $this->fetch($categoryUrl);
            if ($html === null) {
                $this->progress($progress, $verbose, '  fetch failed');

                continue;
            }

            $stats['category_pages_fetched']++;
            $this->progress($progress, $verbose, '  fetched');

            $page = $this->page($html);
            $previewImageUrls = array_replace($previewImageUrls, $this->categoryPreviewImageUrls($page, $baseUrl));
            $categoryPayload = $this->categoryPayload($page, $categoryUrl, $baseUrl, $previewImageUrls[$categoryUrl] ?? null);
            $category = null;

            if (! $dryRun && $categoryPayload !== null) {
                $parent = $this->parentCategory($categoryPayload);
                $category = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $categoryUrl],
                    $categoryPayload + ['parent_id' => $parent?->id]
                );
                $stats['categories_saved']++;
            }

            $categoryLinks = $this->categoryLinks($page, $categoryUrl, $baseUrl);
            $stats['category_links_found'] += count($categoryLinks);

            if ($categoryLinks !== []) {
                $this->progress($progress, $verbose, '  category links found: '.count($categoryLinks));
            } else {
                $this->progress($progress, $verbose, '  no child categories found');
            }

            foreach (array_reverse($categoryLinks) as $link) {
                if (! isset($visitedCategoryUrls[$link]) && ! in_array($link, $queue, true) && ! $this->isExcludedUrl($link, $excludedPathPatterns)) {
                    array_unshift($queue, $link);
                }
            }

            if (! $dryRun && $category !== null) {
                $category->forceFill(['children_scanned_at' => now()])->save();
            }

            if ($categoriesOnly) {
                $this->pause($sleepMs);

                continue;
            }

            if ($categoriesOnly) {
                $this->pause($sleepMs);

                continue;
            }

            $productLinks = $this->productLinks($page, $baseUrl);
            $stats['product_links_found'] += count($productLinks);

            if ($productLinks !== []) {
                $this->progress($progress, $verbose, '  product links found: '.count($productLinks));
            }

            foreach ($productLinks as $productUrl) {
                if (isset($visitedProductUrls[$productUrl]) || $this->isExcludedUrl($productUrl, $excludedPathPatterns)) {
                    continue;
                }

                if ($maxProducts > 0 && count($visitedProductUrls) >= $maxProducts) {
                    break 2;
                }

                $visitedProductUrls[$productUrl] = true;
                $stats['product_pages_seen']++;

                $this->progress($progress, $verbose, "  product #{$stats['product_pages_seen']}: {$productUrl}");

                $productHtml = $this->fetch($productUrl);
                if ($productHtml === null) {
                    continue;
                }

                $productPage = $this->page($productHtml);
                $payload = $this->productPayload($productPage, $productUrl);

                if ($payload === null) {
                    $stats['skipped_product_links']++;
                    $this->progress($progress, $verbose, '    skipped: part number not found');

                    continue;
                }

                if (! $dryRun) {
                    $payload = $this->withRussianNameOnCreate($payload, $productUrl);

                    PartCatalogItem::query()->updateOrCreate(
                        ['source_url' => $productUrl],
                        $payload + [
                            'part_catalog_category_id' => $category?->id,
                            'source_updated_at' => now(),
                        ]
                    );
                    $stats['products_saved']++;
                }

                $this->progress($progress, $verbose, '    saved: '.$payload['part_number'].' | '.$payload['name']);

                $this->pause($sleepMs);
            }

            $this->pause($sleepMs);
        }

        return $stats;
    }

    public function importCategoryPreviews(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://tcarservice.com'), '/');
        $sourceUrl = $this->absoluteUrl((string) ($options['source_url'] ?? '/zapchasty/model-s-321'), $baseUrl);
        $allModels = (bool) ($options['all_models'] ?? false);
        $excludeSourceUrls = collect($options['exclude_source_urls'] ?? [])
            ->map(fn (string $url): ?string => $this->absoluteUrl($url, $baseUrl))
            ->filter()
            ->values()
            ->all();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $stats = [
            'source_pages_seen' => 0,
            'source_pages_fetched' => 0,
            'preview_links_found' => 0,
            'categories_updated' => 0,
        ];

        $sources = $allModels
            ? PartCatalogCategory::query()
                ->where('depth', 0)
                ->whereNotNull('source_url')
                ->when($excludeSourceUrls !== [], fn ($query) => $query->whereNotIn('source_url', $excludeSourceUrls))
                ->orderBy('id')
                ->get(['source_url'])
                ->pluck('source_url')
                ->all()
            : array_filter([$sourceUrl]);

        if ($sources === []) {
            return $stats;
        }

        foreach ($sources as $sourceUrl) {
            $stats['source_pages_seen']++;
            $this->progress($progress, $verbose, "Preview source page #{$stats['source_pages_seen']}: {$sourceUrl}");

            $html = $this->fetch($sourceUrl);
            if ($html === null) {
                $this->progress($progress, $verbose, '  fetch failed');

                continue;
            }

            $stats['source_pages_fetched']++;
            $previews = $this->categoryPreviewImageUrls($this->page($html), $baseUrl);
            $stats['preview_links_found'] += count($previews);
            $this->progress($progress, $verbose, '  preview links found: '.count($previews));

            foreach ($previews as $categoryUrl => $imageUrl) {
                $query = PartCatalogCategory::query()->where('source_url', $categoryUrl);
                $count = (clone $query)->count();

                if ($count > 0 && ! $dryRun) {
                    $query->update(['preview_image_url' => $imageUrl]);
                }

                $stats['categories_updated'] += $count;
            }
        }

        return $stats;
    }

    public function importLeafProducts(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://tcarservice.com'), '/');
        $maxCategories = (int) ($options['max_categories'] ?? 0);
        $maxProducts = (int) ($options['max_products'] ?? 0);
        $maxPagesPerCategory = (int) ($options['max_pages_per_category'] ?? 20);
        $sleepMs = (int) ($options['sleep_ms'] ?? 250);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $rescanProducts = (bool) ($options['rescan_products'] ?? false);
        $discoverChildCategories = (bool) ($options['discover_child_categories'] ?? true);
        $categoryUrl = $options['category_url'] ?? null;
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $excludedPathPatterns = $options['excluded_path_patterns'] ?? self::DEFAULT_EXCLUDED_PATH_PATTERNS;

        $stats = [
            'leaf_categories_seen' => 0,
            'category_pages_fetched' => 0,
            'child_category_links_found' => 0,
            'child_categories_saved' => 0,
            'pagination_links_found' => 0,
            'product_links_found' => 0,
            'product_pages_seen' => 0,
            'product_pages_skipped_existing' => 0,
            'listing_prices_checked' => 0,
            'prices_changed' => 0,
            'products_saved' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'skipped_product_links' => 0,
        ];

        $categoriesQuery = PartCatalogCategory::query()
            ->where('source', 'tcarservice')
            ->doesntHave('children');

        if ($categoryUrl !== null && $categoryUrl !== '') {
            $categoriesQuery->where('source_url', $this->absoluteUrl((string) $categoryUrl, $baseUrl));
        } else {
            $categoriesQuery
                ->when(! $rescanProducts, fn ($query) => $query->whereNull('products_scanned_at'))
                ->orderByRaw('products_scanned_at is not null')
                ->orderBy('id')
                ->when($maxCategories > 0, fn ($query) => $query->limit($maxCategories));
        }

        $categoryQueue = $categoriesQuery
            ->get()
            ->reject(fn (PartCatalogCategory $category): bool => $this->isExcludedUrl($category->source_url, $excludedPathPatterns))
            ->values()
            ->all();
        $queuedCategoryIds = collect($categoryQueue)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();
        $visitedProductUrls = [];

        while ($categoryQueue !== []) {
            $category = array_shift($categoryQueue);
            $stats['leaf_categories_seen']++;
            $this->progress($progress, $verbose, "Leaf category #{$stats['leaf_categories_seen']}: {$category->source_url}");

            $categoryPageUrls = [$category->source_url];
            $visitedCategoryPageUrls = [];
            $foundChildCategories = false;

            while ($categoryPageUrls !== []) {
                $categoryPageUrl = array_shift($categoryPageUrls);

                if (isset($visitedCategoryPageUrls[$categoryPageUrl])) {
                    continue;
                }

                if (count($visitedCategoryPageUrls) >= $maxPagesPerCategory) {
                    $this->progress($progress, $verbose, '  pagination limit reached');
                    break;
                }

                $visitedCategoryPageUrls[$categoryPageUrl] = true;

                $html = $this->fetch($categoryPageUrl);
                if ($html === null) {
                    $this->progress($progress, $verbose, "  fetch failed: {$categoryPageUrl}");

                    continue;
                }

                $stats['category_pages_fetched']++;
                $this->progress($progress, $verbose, "  fetched page: {$categoryPageUrl}");

                $page = $this->page($html);
                $childCategoryLinks = $discoverChildCategories
                    ? collect($this->childCategoryLinks($page, $category->source_url, $baseUrl))
                        ->reject(fn (string $url): bool => $this->isExcludedUrl($url, $excludedPathPatterns))
                        ->values()
                        ->all()
                    : [];
                $stats['child_category_links_found'] += count($childCategoryLinks);

                if ($childCategoryLinks !== []) {
                    $foundChildCategories = true;
                    $this->progress($progress, $verbose, '  child category links found: '.count($childCategoryLinks));

                    foreach ($childCategoryLinks as $childCategoryLink) {
                        $childHtml = $this->fetch($childCategoryLink);

                        if ($childHtml === null) {
                            $this->progress($progress, $verbose, "    child category fetch failed: {$childCategoryLink}");

                            continue;
                        }

                        $childCategoryPayload = $this->categoryPayload($this->page($childHtml), $childCategoryLink, $baseUrl, null);

                        if ($childCategoryPayload === null) {
                            continue;
                        }

                        if (! $dryRun) {
                            $childCategory = PartCatalogCategory::query()->updateOrCreate(
                                ['source_url' => $childCategoryLink],
                                $childCategoryPayload + ['parent_id' => $category->id]
                            );

                            $stats['child_categories_saved']++;

                            if (! isset($queuedCategoryIds[(int) $childCategory->id])) {
                                $categoryQueue[] = $childCategory;
                                $queuedCategoryIds[(int) $childCategory->id] = true;
                            }
                        }
                    }

                    if (! $dryRun) {
                        $category->forceFill(['children_scanned_at' => now()])->save();
                    }

                    break;
                }

                $paginationLinks = $this->paginationLinks($page, $category->source_url, $baseUrl);
                $stats['pagination_links_found'] += count($paginationLinks);

                foreach ($paginationLinks as $paginationLink) {
                    if (! isset($visitedCategoryPageUrls[$paginationLink]) && ! in_array($paginationLink, $categoryPageUrls, true) && ! $this->isExcludedUrl($paginationLink, $excludedPathPatterns)) {
                        $categoryPageUrls[] = $paginationLink;
                    }
                }

                $productLinks = collect($this->productLinks($page, $baseUrl))
                    ->reject(fn (string $url): bool => $this->isExcludedUrl($url, $excludedPathPatterns))
                    ->values()
                    ->all();
                $listingPayloads = $this->productListingPayloads($page, $baseUrl);
                $stats['product_links_found'] += count($productLinks);
                $this->progress($progress, $verbose, '  product links found: '.count($productLinks));

                foreach ($productLinks as $productUrl) {
                    if (isset($visitedProductUrls[$productUrl])) {
                        continue;
                    }

                    if ($maxProducts > 0 && count($visitedProductUrls) >= $maxProducts) {
                        break 3;
                    }

                    $visitedProductUrls[$productUrl] = true;
                    $stats['product_pages_seen']++;

                    $existingItem = PartCatalogItem::query()
                        ->where('source_url', $productUrl)
                        ->first();
                    $listingPayload = $listingPayloads[$productUrl] ?? [];

                    if ($existingItem !== null && array_key_exists('price_amount', $listingPayload)) {
                        $stats['listing_prices_checked']++;
                        $stats['product_pages_skipped_existing']++;

                        $newPrice = $listingPayload['price_amount'];
                        $oldPrice = $existingItem->price_amount;
                        $priceChanged = $oldPrice !== null
                            && $newPrice !== null
                            && abs(round((float) $newPrice, 2) - round((float) $oldPrice, 2)) > 1.0;

                        if (! $dryRun) {
                            $existingItem->forceFill([
                                'price_amount' => $newPrice,
                                'currency' => $listingPayload['currency'] ?? ($newPrice !== null ? 'USD' : $existingItem->currency),
                                'part_catalog_category_id' => $category->id,
                                'source_updated_at' => now(),
                            ])->save();
                        }

                        if ($priceChanged) {
                            $stats['prices_changed']++;
                        }

                        $stats['products_saved']++;
                        $stats['products_updated']++;
                        $this->progress($progress, $verbose, '    price checked from listing: '.$existingItem->part_number);

                        continue;
                    }

                    $this->progress($progress, $verbose, "  product #{$stats['product_pages_seen']}: {$productUrl}");

                    $productHtml = $this->fetch($productUrl);
                    if ($productHtml === null) {
                        continue;
                    }

                    $payload = $this->productPayload($this->page($productHtml), $productUrl);

                    if ($payload === null) {
                        $stats['skipped_product_links']++;
                        $this->progress($progress, $verbose, '    skipped: part number not found');

                        continue;
                    }

                    if (! $dryRun) {
                        $payload = $this->withRussianNameOnCreate($payload, $productUrl);

                        PartCatalogItem::query()->updateOrCreate(
                            ['source_url' => $productUrl],
                            $payload + [
                                'part_catalog_category_id' => $category->id,
                                'source_updated_at' => now(),
                            ]
                        );
                        $stats['products_saved']++;
                        $stats[$existingItem !== null ? 'products_updated' : 'products_created']++;
                    }

                    $this->progress($progress, $verbose, '    '.($existingItem !== null ? 'updated' : 'created').': '.$payload['part_number'].' | '.$payload['name']);
                    $this->pause($sleepMs);
                }

                $this->pause($sleepMs);
            }

            if (! $dryRun && ! $foundChildCategories) {
                $category->forceFill(['products_scanned_at' => now()])->save();
            }
        }

        return $stats;
    }

    public function refreshRussianNames(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $refreshUrls = (bool) ($options['refresh_urls'] ?? false);
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
            ->where('source', 'tcarservice')
            ->whereNotNull('source_url')
            ->when(! $refreshUrls, fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('name_ru')
                ->orWhere('name_ru', '')
                ->orWhereNull('raw_attributes->source_url_ru')
                ->orWhere('raw_attributes->source_url_ru', '')
                ->orWhereNull('raw_attributes->source_url_ua')
                ->orWhere('raw_attributes->source_url_ua', '')))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->get() as $item) {
            $stats['items_seen']++;
            $sourceUrl = (string) $item->source_url;
            $sourcePage = null;
            if ($refreshUrls) {
                $sourceHtml = $this->fetch($sourceUrl);
                if ($sourceHtml !== null) {
                    $sourcePage = $this->page($sourceHtml);
                    $stats['source_pages_fetched']++;
                }
            }

            $rawAttributes = PartCatalogRawAttributes::from($item);

            $ruUrl = filled($rawAttributes['source_url_ru'] ?? null)
                ? (string) $rawAttributes['source_url_ru']
                : null;
            $ukUrl = filled($rawAttributes['source_url_ua'] ?? null)
                ? (string) $rawAttributes['source_url_ua']
                : null;

            $ruUrl ??= $sourcePage !== null
                ? ($this->languageUrl($sourcePage, $sourceUrl, 'ru') ?: $this->localizedProductUrl($sourceUrl, 'ru'))
                : $this->localizedProductUrl($sourceUrl, 'ru');
            $ukUrl ??= $sourcePage !== null
                ? ($this->languageUrl($sourcePage, $sourceUrl, 'uk') ?: $this->localizedProductUrl($sourceUrl, 'uk'))
                : $this->localizedProductUrl($sourceUrl, 'uk');

            if ($ruUrl === null || $ruUrl === $sourceUrl) {
                $stats['items_skipped']++;

                continue;
            }

            if (filled($item->name_ru)) {
                if (! $dryRun) {
                    $rawAttributes['source_url_ua'] = $ukUrl;
                    $rawAttributes['source_url_ru'] = $ruUrl;

                    $item->forceFill([
                        'raw_attributes' => array_filter($rawAttributes, fn ($value) => $value !== null && $value !== ''),
                    ])->save();
                }

                $stats['items_updated']++;

                continue;
            }

            $html = $this->fetch($ruUrl);
            if ($html === null) {
                $stats['items_skipped']++;
                $this->pause($sleepMs);

                continue;
            }

            $stats['source_pages_fetched']++;
            $page = $this->page($html);
            $name = $this->headline($page) ?: $this->pageTitle($page);

            if ($name === null || ! $this->isUsableLocalizedText($name)) {
                $stats['items_skipped']++;
                $this->pause($sleepMs);

                continue;
            }

            if (! $dryRun) {
                $rawAttributes['source_url_ua'] = $ukUrl;
                $rawAttributes['source_url_ru'] = $ruUrl;

                $item->forceFill([
                    'name_ru' => $name,
                    'raw_attributes' => array_filter($rawAttributes, fn ($value) => $value !== null && $value !== ''),
                ])->save();
            }

            $stats['items_updated']++;

            if ($progress !== null) {
                $progress("#{$item->id} {$item->part_number}: {$name}");
            }

            $this->pause($sleepMs);
        }

        return $stats;
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = $this->http
                ->timeout(20)
                ->retry(2, 500)
                ->withHeaders(['User-Agent' => 'NikolaCars catalog importer/1.0'])
                ->get($url);

            if (! $response->ok()) {
                return null;
            }

            return $response->body();
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

    protected function categoryLinks(array $page, string $currentUrl, string $baseUrl): array
    {
        return $this->links($page, $baseUrl)
            ->filter(fn (string $url): bool => str_starts_with(parse_url($url, PHP_URL_PATH) ?: '', '/zapchasty'))
            ->filter(fn (string $url): bool => $url !== $currentUrl)
            ->values()
            ->all();
    }

    protected function childCategoryLinks(array $page, string $currentUrl, string $baseUrl): array
    {
        $currentPath = rtrim(parse_url($currentUrl, PHP_URL_PATH) ?: '', '/');

        if ($currentPath === '') {
            return [];
        }

        return collect($this->categoryLinks($page, $currentUrl, $baseUrl))
            ->filter(function (string $url) use ($currentPath): bool {
                $path = rtrim(parse_url($url, PHP_URL_PATH) ?: '', '/');

                return str_starts_with($path, $currentPath.'/');
            })
            ->values()
            ->all();
    }

    protected function productLinks(array $page, string $baseUrl): array
    {
        return $this->links($page, $baseUrl)
            ->reject(fn (string $url): bool => str_starts_with(parse_url($url, PHP_URL_PATH) ?: '', '/zapchasty'))
            ->filter(fn (string $url): bool => preg_match('/(?:\d{6,}-[a-z0-9]{2}-[a-z0-9]+|\d{6,})(?:\/)?$/i', parse_url($url, PHP_URL_PATH) ?: '') === 1)
            ->reject(fn (string $url): bool => str_contains($url, 'facebook.com') || str_contains($url, 'youtube.com') || str_contains($url, 'instagram.com') || str_contains($url, 't.me'))
            ->filter(fn (string $url): bool => parse_url($url, PHP_URL_HOST) === parse_url($baseUrl, PHP_URL_HOST))
            ->values()
            ->all();
    }

    protected function productListingPayloads(array $page, string $baseUrl): array
    {
        $payloads = [];
        $productUrls = array_flip($this->productLinks($page, $baseUrl));

        foreach ($page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " card-parts ")]') as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $link = $page['xpath']->query('.//a[@href]', $card)->item(0);
            if (! $link instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($link->getAttribute('href'), $baseUrl);
            if ($url === null || ! isset($productUrls[$url])) {
                continue;
            }

            $price = $this->listingPrice($page, $card);
            if ($price === null) {
                continue;
            }

            $payloads[$url] = [
                'price_amount' => app(ExchangeRateService::class)->catalogPriceToUsd($price, 'UAH'),
                'currency' => 'USD',
            ];
        }

        return $payloads;
    }

    protected function listingPrice(array $page, DOMElement $card): ?float
    {
        foreach ($page['xpath']->query('.//*[@data-sum]', $card) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $price = $this->numericPrice($node->getAttribute('data-sum'));
            if ($price !== null) {
                return $price;
            }
        }

        $priceNode = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " price ")]', $card)->item(0);

        return $priceNode instanceof DOMElement ? $this->price($this->clean($priceNode->textContent)) : null;
    }

    protected function numericPrice(?string $value): ?float
    {
        $value = $this->clean($value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^\d,.\s]/u', '', $value) ?? '';
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return (float) str_replace([' ', ','], ['', '.'], $value);
    }

    protected function paginationLinks(array $page, string $categoryUrl, string $baseUrl): array
    {
        $categoryPath = rtrim(parse_url($categoryUrl, PHP_URL_PATH) ?: '', '/');

        return $this->links($page, $baseUrl)
            ->filter(function (string $url) use ($categoryPath): bool {
                $path = rtrim(parse_url($url, PHP_URL_PATH) ?: '', '/');

                if ($path !== $categoryPath) {
                    return false;
                }

                $query = parse_url($url, PHP_URL_QUERY) ?: '';

                return $query !== '' && preg_match('/(^|&)(page|p)=\d+/i', $query) === 1;
            })
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
                $links[] = strtok($url, '#');
            }
        }

        return collect($links)->unique()->values();
    }

    protected function categoryPayload(array $page, string $url, string $baseUrl, ?string $previewImageUrl = null): ?array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if (! str_starts_with($path, '/zapchasty') || rtrim($path, '/') === '/zapchasty') {
            return null;
        }

        $crumbs = $this->breadcrumbs($page);
        $current = $this->pageTitle($page) ?: $this->headline($page) ?: Arr::last($crumbs);

        if ($current === null || Str::lower($current) === 'запчастини tesla') {
            $current = 'Запчастини Tesla';
        }

        [$code, $name] = $this->splitCodeName($current);
        $modelLabel = $this->modelLabel($crumbs);
        [$modelName, $yearFrom, $yearTo] = $this->modelYears($modelLabel);
        $depth = max(count($crumbs) - 1, 0);
        $displayName = $depth === 0 && $modelName !== null ? $modelName : $name;

        return [
            'source' => 'tcarservice',
            'source_url' => $url,
            'preview_image_url' => $previewImageUrl ?? $this->modelPreviewImageUrl($url),
            'depth' => $depth,
            'code' => $code,
            'name' => $displayName,
            'name_ru' => $depth === 0 ? $displayName : null,
            'name_ua' => $depth === 0 ? $displayName : null,
            'model_label' => $modelLabel,
            'model_name' => $modelName,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
            'sort_order' => $this->categorySortOrder($code),
        ];
    }

    protected function productPayload(array $page, string $url): ?array
    {
        $text = $this->bodyText($page);
        $partNumber = $this->match('/Парт\s*№\s*:\s*([A-Z0-9\-]+(?:-[A-Z0-9]+)?)/u', $text);

        if ($partNumber === null) {
            return null;
        }

        $crumbs = $this->breadcrumbs($page);
        $name = $this->headline($page) ?: $this->pageTitle($page) ?: Arr::last($crumbs);
        $attributes = $this->attributes($text);
        $modelLabel = $this->modelLabel($crumbs) ?: ($attributes['Сумісність (запчастини)'] ?? null);
        [$modelName, $yearFrom, $yearTo] = $this->modelYears($modelLabel);
        [$mainCode, $mainName] = $this->splitCodeName($crumbs[1] ?? null);
        [$subcategoryCode, $subcategoryName] = $this->splitCodeName($crumbs[2] ?? null);
        $partOrigin = $this->partOriginFromQuality($attributes['Якість (запчастини)'] ?? null);
        $imageUrls = $this->productImageUrls($page, $url);
        $conditionKey = 'Стан (запчастини)';
        [$condition, $conditionNote] = $this->conditionAndNote($attributes[$conditionKey] ?? null);

        if ($condition !== null) {
            $attributes[$conditionKey] = $condition;
        }

        return [
            'source' => 'tcarservice',
            'source_url' => $url,
            'part_number' => $partNumber,
            'name' => $name,
            'name_ua' => ! $this->isRussianUrl($url) && $this->isUsableLocalizedText($name) ? $name : null,
            'name_ru' => $this->isRussianUrl($url) && $this->isUsableLocalizedText($name) ? $name : null,
            'scheme_number' => $this->schemeNumber($text),
            'price_amount' => app(ExchangeRateService::class)->catalogPriceToUsd($this->price($text), str_contains($text, '₴') ? 'UAH' : 'USD'),
            'currency' => $this->price($text) ? 'USD' : null,
            'model_label' => $modelLabel,
            'model_name' => $modelName,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
            'main_category_code' => $mainCode,
            'main_category_name' => $mainName,
            'subcategory_code' => $subcategoryCode,
            'subcategory_name' => $subcategoryName,
            'node_name' => $crumbs[3] ?? null,
            'notes_ua' => $conditionNote,
            'compatibility_text' => $attributes['Сумісність (запчастини)'] ?? $modelLabel,
            'condition' => $condition,
            'quality' => $attributes['Якість (запчастини)'] ?? null,
            'availability' => $attributes['Наявність (запчастини)'] ?? null,
            'raw_attributes' => array_filter($attributes + [
                'part_origin' => $partOrigin,
                'part_origin_label' => $this->partOriginLabel($partOrigin),
                'Тип запчасти' => $this->partOriginLabel($partOrigin),
                'image_urls' => $imageUrls,
                'source_url_ua' => $this->languageUrl($page, $url, 'uk') ?: $this->localizedProductUrl($url, 'uk'),
                'source_url_ru' => $this->languageUrl($page, $url, 'ru') ?: $this->localizedProductUrl($url, 'ru'),
            ], fn ($value) => $value !== null && $value !== '' && $value !== []),
        ];
    }

    protected function conditionAndNote(?string $condition): array
    {
        $condition = $this->clean($condition);

        if ($condition === '') {
            return [null, null];
        }

        if (preg_match('/^(.*?)\s+(Рік\s+\d{4})(?:\b.*)?$/iu', $condition, $matches) === 1) {
            return [
                $this->clean($matches[1]) ?: null,
                $this->clean($matches[2]) ?: null,
            ];
        }

        return [$condition, null];
    }

    protected function productImageUrls(array $page, string $baseUrl): array
    {
        $urls = [];

        foreach ($page['xpath']->query('//*[@src or @srcset or @data-src or @data-lazy-src or @data-original or @href]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (['src', 'data-src', 'data-lazy-src', 'data-original', 'href'] as $attribute) {
                $this->pushProductImageUrl($urls, $node->getAttribute($attribute), $baseUrl);
            }

            foreach (explode(',', $node->getAttribute('srcset')) as $candidate) {
                $this->pushProductImageUrl($urls, preg_split('/\s+/', trim($candidate))[0] ?? '', $baseUrl);
            }
        }

        return array_values($urls);
    }

    protected function pushProductImageUrl(array &$urls, ?string $url, string $baseUrl): void
    {
        $url = $this->absoluteUrl((string) $url, $this->baseUrl($baseUrl));

        if ($url === null) {
            return;
        }

        $url = preg_replace('~(/storage/editor/fotos/)(?:\d+x\d+/)([^/?#]+\.(?:jpe?g|png|webp))~iu', '$1$2', $url) ?? $url;

        if (preg_match('~/storage/editor/fotos/[^?#]+\.(?:jpe?g|png|webp)(?:[?#].*)?$~iu', $url) !== 1) {
            return;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        if ($filename === '' || preg_match('/^\d+x\d+$/', basename(dirname($path))) === 1) {
            return;
        }

        if ($this->isPlaceholderImageUrl($url)) {
            return;
        }

        $urls[$url] = $url;
    }

    protected function isPlaceholderImageUrl(string $url): bool
    {
        return preg_match('~/storage/editor/fotos/(?:6f46fee0ab4e187090a1f63b7a570bb2|59968e2a90ed37d309bb00d2e4423600)_\d+\.(?:jpe?g|png|webp)(?:[?#].*)?$~iu', $url) === 1;
    }

    protected function partOriginFromQuality(?string $quality): ?string
    {
        $quality = mb_strtolower(trim((string) $quality));

        return match (true) {
            preg_match('/(?<![\pL\pN])ориг(?:і|и)нал(?![\pL\pN])/iu', $quality) === 1 => 'original',
            preg_match('/(?<![\pL\pN])аналог(?![\pL\pN])/iu', $quality) === 1 => 'analog',
            default => null,
        };
    }

    protected function partOriginLabel(?string $origin): ?string
    {
        return match ($origin) {
            'original' => 'Оригинал',
            'analog' => 'Аналог',
            default => null,
        };
    }

    protected function withRussianNameOnCreate(array $payload, string $productUrl): array
    {
        $rawAttributes = (array) ($payload['raw_attributes'] ?? []);
        $ruUrl = filled($rawAttributes['source_url_ru'] ?? null)
            ? (string) $rawAttributes['source_url_ru']
            : $this->localizedProductUrl($productUrl, 'ru');

        if ($ruUrl === null || $ruUrl === $productUrl) {
            return $payload;
        }

        $html = $this->fetch($ruUrl);
        if ($html === null) {
            return $payload;
        }

        $name = $this->headline($this->page($html));
        if ($name !== null && $this->isUsableLocalizedText($name)) {
            $payload['name_ru'] = $name;
        }

        return $payload;
    }

    protected function languageUrl(array $page, string $currentUrl, string $locale): ?string
    {
        $label = Str::upper($locale === 'uk' ? 'ua' : $locale);
        $baseUrl = $this->baseUrl($currentUrl);

        foreach ($page['xpath']->query('//a[@href]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if (Str::upper($this->clean($node->textContent)) !== $label) {
                continue;
            }

            return $this->absoluteUrl($node->getAttribute('href'), $baseUrl);
        }

        return null;
    }

    protected function localizedProductUrl(string $url, string $locale): ?string
    {
        if ($locale === 'ru') {
            return preg_replace('#://([^/]+)/(?!ru/)#', '://$1/ru/', $url) ?: $url;
        }

        if ($locale === 'uk') {
            return preg_replace('#://([^/]+)/ru/#', '://$1/', $url) ?: $url;
        }

        return null;
    }

    protected function isRussianUrl(string $url): bool
    {
        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/ru/');
    }

    protected function baseUrl(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST) ?: 'tcarservice.com';

        return $scheme.'://'.$host;
    }

    protected function isUsableLocalizedText(?string $value): bool
    {
        $value = $this->clean((string) $value);

        return $value !== ''
            && preg_match('/[А-Яа-яЁёІіЇїЄєҐґ]/u', $value) === 1;
    }

    protected function breadcrumbs(array $page): array
    {
        $crumbs = [];

        foreach ($page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")]//*[@itemprop="name"]') as $node) {
            $text = $this->clean($node->textContent);

            if ($text === '' || in_array(Str::lower($text), ['головна', 'запчастини tesla', 'запчасти'], true)) {
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

        $path = parse_url($payload['source_url'], PHP_URL_PATH);

        if ($path !== null && $path !== '') {
            $parentPath = rtrim(str_replace('\\', '/', dirname($path)), '/');
            $scheme = parse_url($payload['source_url'], PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($payload['source_url'], PHP_URL_HOST);

            if ($host !== null && $parentPath !== '') {
                $parent = PartCatalogCategory::query()
                    ->where('source_url', $scheme.'://'.$host.$parentPath)
                    ->first();

                if ($parent !== null) {
                    return $parent;
                }
            }
        }

        return PartCatalogCategory::query()
            ->where('model_label', $payload['model_label'])
            ->where('depth', $payload['depth'] - 1)
            ->latest('id')
            ->first();
    }

    protected function headline(array $page): ?string
    {
        foreach (['//h1', '//h2', '//h3'] as $query) {
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

        if ($node === null) {
            return null;
        }

        $title = preg_replace('/\s*-\s*tcarservice\.com\s*$/iu', '', $this->clean($node->textContent));

        return $title === '' ? null : $title;
    }

    protected function attributes(string $text): array
    {
        $keys = [
            'Сумісність (запчастини)',
            'Стан (запчастини)',
            'Якість (запчастини)',
            'Наявність (запчастини)',
        ];

        $attributes = [];
        $keyPattern = collect($keys)
            ->map(fn (string $key): string => preg_quote($key, '/'))
            ->implode('|');

        foreach ($keys as $key) {
            $pattern = '/'.preg_quote($key, '/').'\s+(.+?)(?=\s+(?:'.$keyPattern.')|\s+Опис|\z)/u';
            $value = $this->match($pattern, $text);

            if ($value !== null) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    protected function bodyText(array $page): string
    {
        return $this->clean($page['document']->textContent);
    }

    protected function clean(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    protected function match(string $pattern, string $text): ?string
    {
        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        return $this->clean($matches[1]);
    }

    protected function splitCodeName(?string $value): array
    {
        $value = $this->clean($value);

        if ($value === '') {
            return [null, null];
        }

        if (preg_match('/^(\d+)\s*-\s*(.+)$/u', $value, $matches) === 1) {
            return [$matches[1], $this->clean($matches[2])];
        }

        return [null, $value];
    }

    protected function modelLabel(array $crumbs): ?string
    {
        foreach ($crumbs as $crumb) {
            if (preg_match('/^(Model\s+[S3XY]|Cybertruck|RIVIAN|LUCID AIR)/iu', $crumb) === 1) {
                return $crumb;
            }
        }

        return null;
    }

    protected function modelYears(?string $label): array
    {
        if ($label === null) {
            return [null, null, null];
        }

        $modelName = $this->clean(preg_replace('/\s+\d{2}\.\d{4}.*$/u', '', $label));
        $modelName = $this->clean(preg_replace('/\s+\d{4}\s*-\s*\d{4}.*$/u', '', $modelName));
        $yearFrom = null;
        $yearTo = null;

        if (preg_match('/(\d{2})\.(\d{4})\s*-\s*(?:(\d{2})\.(\d{4}))?/u', $label, $matches) === 1) {
            $yearFrom = (int) $matches[2];
            $yearTo = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : null;
        } elseif (preg_match('/(\d{4})\s*-\s*(\d{4})?/u', $label, $matches) === 1) {
            $yearFrom = (int) $matches[1];
            $yearTo = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null;
        }

        return [$modelName, $yearFrom, $yearTo];
    }

    protected function schemeNumber(string $text): ?int
    {
        $value = $this->match('/Номер на схемі:\s*(\d+)/u', $text);

        return $value === null ? null : (int) $value;
    }

    protected function price(string $text): ?float
    {
        $value = $this->match('/(?<!\d)((?:\d{1,3}(?:[\s.]\d{3})+|\d{1,5})(?:,\d{2})?)\s*₴/u', $text);

        if ($value === null) {
            return null;
        }

        return (float) str_replace([' ', ','], ['', '.'], $value);
    }

    protected function categorySortOrder(?string $code): int
    {
        return $code === null ? 0 : (int) $code;
    }

    protected function categoryPreviewImageUrls(array $page, string $baseUrl): array
    {
        $previews = [];

        foreach ($page['xpath']->query('//a[contains(concat(" ", normalize-space(@class), " "), " card ")][@href]') as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($card->getAttribute('href'), $baseUrl);
            $imageUrl = $this->cardImageUrl($page, $card, $baseUrl);

            if ($url !== null && $imageUrl !== null && str_starts_with(parse_url($url, PHP_URL_PATH) ?: '', '/zapchasty')) {
                $previews[$url] = $imageUrl;
            }
        }

        foreach ($page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " product-card ")]') as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $imageUrl = $this->cardImageUrl($page, $card, $baseUrl);
            $firstChildLink = $page['xpath']->query('.//ul//a[@href]', $card)->item(0);

            if (! $firstChildLink instanceof DOMElement || $imageUrl === null) {
                continue;
            }

            $childUrl = $this->absoluteUrl($firstChildLink->getAttribute('href'), $baseUrl);
            $parentUrl = $childUrl ? $this->parentUrl($childUrl) : null;

            if ($parentUrl !== null) {
                $previews[$parentUrl] = $imageUrl;
            }
        }

        return $previews;
    }

    protected function productCardPreviewImages(array $page, string $baseUrl): array
    {
        $previews = [];

        foreach ($page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " product-card ")]') as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $imageUrl = $this->cardImageUrl($page, $card, $baseUrl);
            $title = $this->clean($page['xpath']->query('.//h3', $card)->item(0)?->textContent);

            if ($title === '') {
                $image = $page['xpath']->query('.//img[@alt]', $card)->item(0);
                $title = $image instanceof DOMElement ? $this->clean($image->getAttribute('alt')) : '';
            }

            [$code] = $this->splitCodeName($title);

            if ($code !== null && $imageUrl !== null) {
                $previews[$code] = $imageUrl;
            }
        }

        return $previews;
    }

    protected function cardImageUrl(array $page, DOMElement $card, string $baseUrl): ?string
    {
        $image = $page['xpath']->query('.//img[@srcset or @src]', $card)->item(0);

        if (! $image instanceof DOMElement) {
            return null;
        }

        $srcset = $image->getAttribute('srcset');
        $src = $srcset !== ''
            ? preg_replace('/\s+\d+w$/', '', trim(explode(',', $srcset)[0]))
            : $image->getAttribute('src');

        return $this->absoluteUrl((string) $src, $baseUrl);
    }

    protected function parentUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $host = parse_url($url, PHP_URL_HOST);

        if ($path === null || $host === null) {
            return null;
        }

        $parentPath = rtrim(str_replace('\\', '/', dirname($path)), '/');

        if ($parentPath === '') {
            return null;
        }

        return (parse_url($url, PHP_URL_SCHEME) ?: 'https').'://'.$host.$parentPath;
    }

    protected function modelPreviewImageUrl(string $url): ?string
    {
        return [
            'https://tcarservice.com/zapchasty/model-s-321' => 'https://tcarservice.com/storage/editor/fotos/530x0/f44e47113dfec8631ed0c55d60e910c7_1713955563.webp',
            'https://tcarservice.com/zapchasty/model-s2-322' => 'https://tcarservice.com/storage/editor/fotos/530x0/86776dd9c9d8c52b2eebbbb8b4d060ba_1713958154.webp',
            'https://tcarservice.com/zapchasty/model-s-plaid-323' => 'https://tcarservice.com/storage/editor/fotos/530x0/e1c438d32ca54fddd1f49b628e5117c6_1713954573.webp',
            'https://tcarservice.com/zapchasty/model-x-324' => 'https://tcarservice.com/storage/editor/fotos/530x0/2b062b5d710e6dd9d54fbed643d74840_1713958401.webp',
            'https://tcarservice.com/zapchasty/model-x-plaid-325' => 'https://tcarservice.com/storage/editor/fotos/530x0/7051f412da109ca7841207fce7ff8ba5_1713959002.webp',
            'https://tcarservice.com/zapchasty/model-y-327' => 'https://tcarservice.com/storage/editor/fotos/530x0/42da0e97dd99831fb6d56a1ac8c7dff2_1713959189.webp',
            'https://tcarservice.com/zapchasty/model-y-juniper-2684' => 'https://tcarservice.com/storage/editor/fotos/530x0/afcae58ec18c6a009ab6c1d5901310d3_1745331939.webp',
            'https://tcarservice.com/zapchasty/model-3-326' => 'https://tcarservice.com/storage/editor/fotos/530x0/996a68040be17e96ac4a367d3fd16f32_1713959319.webp',
            'https://tcarservice.com/zapchasty/model-3highland-1587' => 'https://tcarservice.com/storage/editor/fotos/530x0/9f59852d084a40813ae8771350e544c4_1713960938.webp',
        ][$url] ?? null;
    }

    protected function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//storage/')) {
            return $baseUrl.'/'.ltrim($url, '/');
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $baseUrl.$url;
        }

        return $baseUrl.'/'.ltrim($url, '/');
    }

    protected function isExcludedUrl(string $url, array $patterns): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        foreach ($patterns as $pattern) {
            if (str_contains($path, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function pause(int $sleepMs): void
    {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if (! $verbose || $progress === null) {
            return;
        }

        $progress($message);
    }
}
