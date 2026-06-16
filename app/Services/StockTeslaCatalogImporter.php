<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Support\PartCatalogRawAttributes;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StockTeslaCatalogImporter
{
    protected string $source = 'stock-tesla';

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://stock-tesla.com'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $withRussian = (bool) ($options['with_russian'] ?? true);
        $categoriesOnly = (bool) ($options['categories_only'] ?? false);
        $rescanProducts = (bool) ($options['rescan_products'] ?? false);
        $rebuildCategories = (bool) ($options['rebuild_categories'] ?? false);
        $createCategories = (bool) ($options['create_categories'] ?? true);
        $downloadImages = (bool) ($options['download_images'] ?? true);
        $hasExistingCategories = PartCatalogCategory::query()
            ->where('source', $this->source)
            ->exists();
        $shouldCreateCategories = $rebuildCategories || ($createCategories && ! $hasExistingCategories);
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $maxCategories = max(0, (int) ($options['max_categories'] ?? 0));
        $maxCategoryPages = max(0, (int) ($options['max_category_pages'] ?? 0));
        $categoryUrl = trim((string) ($options['category_url'] ?? ''));
        $modelCategoryUrls = $this->modelCategoryUrls($options['model_category_urls'] ?? []);
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 1000));

        $stats = [
            'source_pages_fetched' => 0,
            'categories_found' => 0,
            'categories_saved' => 0,
            'site_categories_scanned' => 0,
            'site_category_pages_scanned' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'product_category_occurrences_saved' => 0,
            'site_products_found' => 0,
            'site_product_pages_fetched' => 0,
            'images_downloaded' => 0,
            'images_failed' => 0,
            'russian_pages_fetched' => 0,
            'russian_pages_failed' => 0,
        ];

        if ($rebuildCategories && ! $dryRun) {
            PartCatalogCategory::query()
                ->where('source', $this->source)
                ->delete();
        }

        $categoryUrls = $this->categoryUrls($baseUrl);
        $categories = $categoryUrls !== [] ? $this->siteCategories($categoryUrls) : [];
        $stats['source_pages_fetched'] += $categoryUrls === [] ? 0 : 3;
        $stats['categories_found'] = count($categories);

        $savedCategories = [];
        $savedCategoriesBySiteUrl = [];
        foreach ($this->orderedCategories($categories) as $category) {
            $payload = $this->categoryPayload($category);
            $siteUrl = $category['url'] ?? null;
            $parent = $category['parent_id'] !== null ? ($savedCategories[$category['parent_id']] ?? null) : null;
            if ($parent === null && $category['parent_id'] !== null && (int) $category['depth'] > 0) {
                $parent = $this->savedRootCategory($savedCategories, $category['model_label']);
            }

            if (! $dryRun && $shouldCreateCategories) {
                $savedCategories[$category['id']] = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $payload['source_url']],
                    $payload + ['parent_id' => $parent?->id]
                );
                $stats['categories_saved']++;

                if ($siteUrl !== null) {
                    foreach (['ua', 'ru', 'en'] as $locale) {
                        if (! empty($category[$locale.'_url'])) {
                            $savedCategoriesBySiteUrl[$this->canonicalCategoryUrl($category[$locale.'_url'])] = $savedCategories[$category['id']];
                        }
                    }
                }
            } elseif (! $dryRun) {
                $savedCategory = $this->existingCategory($payload, $category);
                if ($savedCategory !== null) {
                    $savedCategories[$category['id']] = $savedCategory;

                    foreach (['ua', 'ru', 'en'] as $locale) {
                        if (! empty($category[$locale.'_url'])) {
                            $savedCategoriesBySiteUrl[$this->canonicalCategoryUrl($category[$locale.'_url'])] = $savedCategory;
                        }
                    }
                }
            }
        }

        if ($categoriesOnly) {
            return $stats;
        }

        $seenProductUrls = [];

        if (! $rescanProducts) {
            $seenProductUrls = PartCatalogItem::query()
                ->where('source', $this->source)
                ->pluck('source_url')
                ->filter()
                ->mapWithKeys(fn (string $url): array => [rtrim($url, '/').'/' => true])
                ->all();
        }

        if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
            return $stats;
        }

        foreach ($this->siteCatalogCategories($categories, $categoryUrls, $maxCategories, $categoryUrl, $modelCategoryUrls) as $siteCategory) {
            $stats['site_categories_scanned']++;
            $this->progress($progress, $verbose, "Site category: {$siteCategory['name']} ({$siteCategory['url']})");
            $listingCategory = ! $dryRun
                ? $this->listingCategoryForSiteCategory($siteCategory, $savedCategoriesBySiteUrl)
                : null;

            foreach ($this->categoryPageUrls($siteCategory['url'], $baseUrl, $maxCategoryPages, $stats) as $pageUrl => $html) {
                $stats['site_category_pages_scanned']++;
                $this->progress($progress, $verbose, "  Page: {$pageUrl}");

                foreach ($this->productSummariesFromCategoryHtml($html, $baseUrl) as $listingProduct) {
                    $productUrl = $listingProduct['source_url'];
                    if (isset($seenProductUrls[$productUrl]) && ! $rescanProducts) {
                        if (! $dryRun) {
                            $item = $this->updateExistingProductFromListing($listingProduct);
                            if ($item instanceof PartCatalogItem && $listingCategory instanceof PartCatalogCategory) {
                                $this->saveListingOccurrence($item, $listingCategory, $listingProduct, $pageUrl);
                                $stats['product_category_occurrences_saved']++;
                            }

                            $stats['products_saved']++;
                            $stats['products_updated']++;
                        }

                        $stats['products_found']++;
                        $stats['site_products_found']++;
                        $this->progress($progress, $verbose, "  Listing product #{$stats['site_products_found']}: {$productUrl}");

                        if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                            break 3;
                        }

                        continue;
                    }

                    $seenProductUrls[$productUrl] = true;
                    $stats['products_found']++;
                    $stats['site_products_found']++;

                    $productHtml = $this->fetch($productUrl);
                    if ($productHtml === null) {
                        continue;
                    }

                    $stats['source_pages_fetched']++;
                    $stats['site_product_pages_fetched']++;

                    $offer = $this->siteProductOffer($productHtml, $productUrl, $baseUrl);
                    if ($offer === null) {
                        continue;
                    }

                    $category = null;
                    if (! $dryRun) {
                        $category = $this->ensureBreadcrumbCategory($offer, $savedCategoriesBySiteUrl);
                    }

                    if ($category === null) {
                        foreach (array_reverse($offer['category_urls']) as $categoryUrl) {
                            $category = $savedCategoriesBySiteUrl[$this->canonicalCategoryUrl($categoryUrl)] ?? null;
                            if ($category instanceof PartCatalogCategory) {
                                break;
                            }
                        }
                    }

                    if ($category === null && ! $dryRun) {
                        $category = $this->fallbackCategory($offer, $savedCategories);
                    }

                    $russian = null;
                    $fetchedRussian = false;
                    if ($withRussian) {
                        $russian = $this->russianProduct($offer['source_url'], $baseUrl);
                        if ($russian === null) {
                            $stats['russian_pages_failed']++;
                        } else {
                            $fetchedRussian = true;
                            $stats['russian_pages_fetched']++;
                        }

                        if ($fetchedRussian && $sleepMs > 0) {
                            usleep($sleepMs * 1000);
                        }
                    }

                    if (! $dryRun) {
                        $payload = $this->productPayload($category, null, $offer, $russian);
                        $item = $this->existingProductItem($offer['source_url'], $payload['part_number']);
                        if ($downloadImages && ! $this->itemHasImages($item)) {
                            $offer = $this->withDownloadedImages($offer, $stats);
                            $payload = $this->productPayload($category, null, $offer, $russian);
                        }
                        $payload = $this->withMergedSourceUrls($payload, $item, $offer['source_url']);

                        if ($item instanceof PartCatalogItem) {
                            if ($this->isVariantOffer($offer) && $item->source_url !== $offer['source_url']) {
                                $rawAttributes = $this->rawAttributesArray($item);
                                $rawAttributes['source_urls'] = $payload['raw_attributes']['source_urls'];
                                $rawAttributes['product_source_urls'] = $payload['raw_attributes']['product_source_urls'];
                                $item->raw_attributes = $rawAttributes;
                                $item->source_updated_at = now();
                            } else {
                                if (! $this->isVariantOffer($offer) && $item->source_url !== $offer['source_url']) {
                                    $payload['source_url'] = $offer['source_url'];
                                }

                                $item->fill($payload);
                            }

                            $item->save();
                            $stats['products_updated']++;
                        } else {
                            $item = PartCatalogItem::query()->create(['source_url' => $offer['source_url']] + $payload);
                            $stats['products_created']++;
                        }

                        if ($item instanceof PartCatalogItem && $listingCategory instanceof PartCatalogCategory) {
                            $this->saveListingOccurrence($item, $listingCategory, $listingProduct, $pageUrl);
                            $stats['product_category_occurrences_saved']++;
                        }

                        $stats['products_saved']++;
                    }

                    $this->progress($progress, $verbose, "  Site product #{$stats['site_products_found']}: {$offer['part_number']}");

                    if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                        break 3;
                    }
                }
            }
        }

        return $stats;
    }

    public function backfillMissingCategoriesFromProductPages(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://stock-tesla.com'), '/');
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 0));

        $stats = [
            'products_scanned' => 0,
            'products_updated' => 0,
            'products_without_breadcrumb' => 0,
            'products_without_category_match' => 0,
            'source_pages_fetched' => 0,
            'site_product_pages_fetched' => 0,
        ];

        $savedCategoriesBySiteUrl = $this->savedCategoriesBySiteUrl($baseUrl);

        PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereNull('part_catalog_category_id')
            ->whereNotNull('source_url')
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($baseUrl, $verbose, $progress, $maxProducts, $sleepMs, $savedCategoriesBySiteUrl, &$stats): bool {
                foreach ($items as $item) {
                    if ($maxProducts > 0 && $stats['products_scanned'] >= $maxProducts) {
                        return false;
                    }

                    $stats['products_scanned']++;
                    $offer = $this->siteProductFromUrl((string) $item->source_url, $baseUrl, $stats);

                    if ($offer === null || empty($offer['category_urls'])) {
                        $stats['products_without_breadcrumb']++;

                        continue;
                    }

                    $category = $this->categoryByBreadcrumbUrls($offer['category_urls'], $savedCategoriesBySiteUrl);
                    if (! $category instanceof PartCatalogCategory) {
                        $category = $this->fallbackCategory($offer, []);
                        if (! $category instanceof PartCatalogCategory) {
                            $stats['products_without_category_match']++;

                            continue;
                        }
                    }

                    $payload = $this->productPayload($category, null, $offer, null);
                    $payload['raw_attributes'] = $this->rawAttributesArray($item) + ($payload['raw_attributes'] ?? []);
                    $payload['raw_attributes']['breadcrumb_category_urls'] = $offer['category_urls'];
                    $payload['raw_attributes']['category_url'] = collect($offer['category_urls'])->last();

                    $item->fill($payload);
                    $item->save();
                    $stats['products_updated']++;

                    $this->progress($progress, $verbose, "Backfilled #{$stats['products_scanned']}: {$item->part_number}");

                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }

                return true;
            });

        return $stats;
    }

    public function backfillMissingRussianNames(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://stock-tesla.com'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 250));

        $stats = [
            'products_scanned' => 0,
            'russian_pages_fetched' => 0,
            'russian_pages_failed' => 0,
            'names_updated' => 0,
            'manual_locks_skipped' => 0,
            'unusable_names_skipped' => 0,
        ];

        PartCatalogItem::query()
            ->where('source', $this->source)
            ->where(fn ($query) => $query->whereNull('name_ru')->orWhere('name_ru', ''))
            ->whereNotNull('source_url')
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($baseUrl, $dryRun, $verbose, $progress, $maxProducts, $sleepMs, &$stats): bool {
                foreach ($items as $item) {
                    if ($maxProducts > 0 && $stats['products_scanned'] >= $maxProducts) {
                        return false;
                    }

                    $stats['products_scanned']++;

                    if ($item->name_ru_manually_locked_at !== null) {
                        $stats['manual_locks_skipped']++;

                        continue;
                    }

                    $russianUrl = $this->russianProductUrl($item, $baseUrl);
                    if ($russianUrl === null) {
                        $stats['russian_pages_failed']++;

                        continue;
                    }

                    $html = $this->fetch($russianUrl);
                    if ($html === null) {
                        $stats['russian_pages_failed']++;

                        continue;
                    }

                    $stats['russian_pages_fetched']++;

                    $product = $this->jsonLdProduct($html) ?? [];
                    $name = $this->clean((string) ($product['name'] ?? $this->htmlTitle($html)));
                    if (! $this->isUsableLocalizedText($name)) {
                        $stats['unusable_names_skipped']++;

                        continue;
                    }

                    if (! $dryRun) {
                        $rawAttributes = $this->rawAttributesArray($item);
                        $rawAttributes['url_ru'] = $russianUrl;

                        $category = $this->clean((string) ($product['category'] ?? ''));
                        if ($category !== '') {
                            $rawAttributes['category_ru'] = $category;
                        }

                        $description = $this->clean((string) ($product['description'] ?? ''));
                        if ($description !== '') {
                            $item->notes_ru = $description;
                        }

                        $item->name_ru = $name;
                        $item->raw_attributes = $rawAttributes;
                        $item->source_updated_at = now();
                        $item->save();
                    }

                    $stats['names_updated']++;
                    $this->progress($progress, $verbose, "Backfilled RU #{$stats['products_scanned']}: {$item->part_number}");

                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }

                return true;
            });

        return $stats;
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = $this->http
                ->timeout(60)
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

    protected function orderedCategories(array $categories): array
    {
        return collect($categories)
            ->sortBy(fn (array $category): string => implode('|', [
                str_pad((string) $category['depth'], 4, '0', STR_PAD_LEFT),
                str_pad((string) $this->categorySortOrder($this->categoryCode($category['name_ua'])), 6, '0', STR_PAD_LEFT),
                $category['id'],
            ]))
            ->values()
            ->all();
    }

    protected function savedRootCategory(array $savedCategories, ?string $modelLabel): ?PartCatalogCategory
    {
        foreach ($savedCategories as $category) {
            if ((int) $category->depth === 0 && $category->model_label === $modelLabel) {
                return $category;
            }
        }

        return null;
    }

    protected function categoryByBreadcrumbUrls(array $categoryUrls, array $savedCategoriesBySiteUrl): ?PartCatalogCategory
    {
        foreach (array_reverse($categoryUrls) as $categoryUrl) {
            $category = $savedCategoriesBySiteUrl[$this->canonicalCategoryUrl($categoryUrl)] ?? null;
            if ($category instanceof PartCatalogCategory) {
                return $category;
            }
        }

        return null;
    }

    protected function savedCategoriesBySiteUrl(string $baseUrl): array
    {
        $categories = PartCatalogCategory::query()
            ->where('source', $this->source)
            ->get();

        $map = [];
        foreach ($categories as $category) {
            if (! is_string($category->source_url) || ! str_contains($category->source_url, '/category/')) {
                continue;
            }

            $url = $this->canonicalCategoryUrl($category->source_url);
            $map[$url] = $category;

            $path = parse_url($url, PHP_URL_PATH) ?: '';
            if (str_starts_with($path, '/category/')) {
                $localizedPath = '/ru'.$path;
                $map[$this->canonicalCategoryUrl($baseUrl.$localizedPath)] = $category;
            }
        }

        return $map;
    }

    protected function categoryPayload(array $category): array
    {
        $nameUa = $category['name_ua'];
        $code = $this->categoryCode($nameUa);
        $url = $category['url'] ?? "https://stock-tesla.com/category-feed/{$category['id']}";

        return [
            'source' => $this->source,
            'source_url' => $url,
            'depth' => $category['depth'],
            'code' => $code,
            'name' => $nameUa,
            'name_en' => $category['name_en'] ?? null,
            'name_ua' => $nameUa,
            'name_ru' => $category['name_ru'] ?? null,
            'model_label' => $category['model_label'],
            'model_name' => $category['model_label'],
            'sort_order' => $this->categorySortOrder($code),
            'children_scanned_at' => now(),
            'products_scanned_at' => now(),
        ];
    }

    protected function existingCategory(array $payload, array $category): ?PartCatalogCategory
    {
        $query = PartCatalogCategory::query()
            ->where('source', $this->source);

        return (clone $query)
            ->where('source_url', $payload['source_url'])
            ->first()
            ?? (clone $query)
                ->where('depth', $payload['depth'])
                ->where('name_ua', $category['name_ua'])
                ->where('model_label', $payload['model_label'])
                ->first()
            ?? (clone $query)
                ->where('source_url', 'https://stock-tesla.com/category-feed/'.$category['id'])
                ->first();
    }

    protected function categoryUrls(string $baseUrl): array
    {
        $ukHtml = $this->fetch($baseUrl.'/categories/');
        $uk = $this->categoryLinks($ukHtml, $baseUrl);
        $ru = $this->categoryLinks($this->fetch($baseUrl.'/ru/categories/'), $baseUrl);
        $en = $this->categoryLinks($this->fetch($baseUrl.'/en/categories/'), $baseUrl);
        $ruBySlug = collect($ru)->keyBy('slug');
        $enBySlug = collect($en)->keyBy('slug');

        return [
            'tree' => $this->categoryTreeLinks($ukHtml, $baseUrl),
            'links' => collect($uk)->mapWithKeys(fn (array $link): array => [$link['slug'] => [
                'ua' => $link['url'],
                'ru' => $ruBySlug[$link['slug']]['url'] ?? null,
                'en' => $enBySlug[$link['slug']]['url'] ?? null,
                'ua_name' => $link['name'],
                'ru_name' => $ruBySlug[$link['slug']]['name'] ?? null,
                'en_name' => $enBySlug[$link['slug']]['name'] ?? null,
            ]])->all(),
        ];
    }

    protected function categoryLinks(?string $html, string $baseUrl): array
    {
        if ($html === null) {
            return [];
        }

        preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i', $html, $matches, PREG_SET_ORDER);

        $links = [];
        foreach ($matches as $match) {
            $path = parse_url(html_entity_decode($match[1]), PHP_URL_PATH) ?: '';
            if (! str_contains($path, '/category/')) {
                continue;
            }

            $name = $this->clean(strip_tags($match[2]));
            if ($name === '') {
                continue;
            }

            $slug = trim(Str::after($path, '/category/'), '/');
            if ($slug === $path) {
                $slug = trim(Str::after($path, '/ru/category/'), '/');
            }

            $links[$slug] = [
                'slug' => $slug,
                'url' => $this->absoluteUrl($path, $baseUrl),
                'name' => preg_replace('/\s+\d+\s+\x{0442}\x{043E}\x{0432}\x{0430}\x{0440}\x{0456}\x{0432}$/u', '', $name) ?: $name,
            ];
        }

        return array_values($links);
    }

    protected function categoryTreeLinks(?string $html, string $baseUrl): array
    {
        if ($html === null) {
            return [];
        }

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $menu = $document->getElementById('menu');
        if (! $menu instanceof DOMElement) {
            return [];
        }

        $items = [];
        foreach ($this->directCategoryItems($menu) as $li) {
            $this->appendCategoryTreeItem($li, [], null, $baseUrl, $items);
        }

        return $items;
    }

    protected function appendCategoryTreeItem(DOMElement $li, array $parentPath, ?string $parentId, string $baseUrl, array &$items): void
    {
        $anchor = $this->directCategoryAnchor($li);
        if (! $anchor instanceof DOMElement) {
            return;
        }

        $url = $this->absoluteUrl((string) $anchor->getAttribute('href'), $baseUrl);
        $slug = trim(Str::after((string) parse_url($url, PHP_URL_PATH), '/category/'), '/');
        if ($slug === '') {
            return;
        }

        $name = $this->clean($anchor->textContent);
        $path = [...$parentPath, $name];

        $items[$slug] = [
            'id' => $slug,
            'parent_id' => $parentId,
            'slug' => $slug,
            'url' => $this->canonicalCategoryUrl($url),
            'name_ua' => $name,
            'depth' => count($parentPath),
            'path' => $path,
        ];

        foreach ($this->directCategoryItems($li) as $child) {
            $this->appendCategoryTreeItem($child, $path, $slug, $baseUrl, $items);
        }
    }

    protected function directCategoryAnchor(DOMElement $element): ?DOMElement
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement
                && strcasecmp($child->tagName, 'a') === 0
                && str_contains((string) $child->getAttribute('href'), '/category/')) {
                return $child;
            }
        }

        return null;
    }

    protected function directCategoryItems(DOMElement $element): array
    {
        $items = [];

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement || strcasecmp($child->tagName, 'a') === 0) {
                continue;
            }

            if (strcasecmp($child->tagName, 'li') === 0) {
                if ($this->directCategoryAnchor($child) instanceof DOMElement) {
                    $items[] = $child;
                }

                continue;
            }

            if (strcasecmp($child->tagName, 'ul') === 0) {
                foreach ($child->childNodes as $li) {
                    if ($li instanceof DOMElement && strcasecmp($li->tagName, 'li') === 0 && $this->directCategoryAnchor($li) instanceof DOMElement) {
                        $items[] = $li;
                    }
                }

                continue;
            }

            foreach ($this->directCategoryItems($child) as $li) {
                $items[] = $li;
            }
        }

        return $items;
    }

    protected function siteCategories(array $categoryUrls): array
    {
        $links = $categoryUrls['links'] ?? [];

        return collect($categoryUrls['tree'] ?? [])
            ->map(function (array $category) use ($links): array {
                $localized = $links[$category['slug']] ?? [];

                return $category + [
                    'ua_url' => $localized['ua'] ?? $category['url'],
                    'ru_url' => $localized['ru'] ?? null,
                    'en_url' => $localized['en'] ?? null,
                    'name_ru' => $localized['ru_name'] ?? null,
                    'name_en' => $localized['en_name'] ?? null,
                    'model_label' => $this->canonicalModelLabel($category['path'][0] ?? $category['name_ua']),
                ];
            })
            ->all();
    }

    protected function siteCatalogCategories(array $categories, array $categoryUrls, int $maxCategories, string $categoryUrl = '', array $modelCategoryUrls = []): array
    {
        if ($modelCategoryUrls !== [] && $categoryUrl === '') {
            $siteCategories = collect($modelCategoryUrls)
                ->map(fn (string $url): array => [
                    'name' => $this->modelLabelFromCategoryUrl($url),
                    'url' => $url,
                ])
                ->unique('url')
                ->values();

            return ($maxCategories > 0 ? $siteCategories->take($maxCategories) : $siteCategories)
                ->all();
        }

        $siteCategories = collect($this->orderedCategories($categories))
            ->filter(fn (array $category): bool => (int) $category['depth'] === 0)
            ->map(fn (array $category): ?array => isset($category['url'])
                ? [
                    'name' => $category['name_ua'],
                    'url' => $category['url'],
                ]
                : null)
            ->filter()
            ->reject(fn (array $category): bool => $this->shouldSkipSiteCatalogCategory($category['url']))
            ->unique('url')
            ->values();

        if ($siteCategories->isEmpty()) {
            $siteCategories = collect($categoryUrls['links'] ?? [])
                ->map(fn (array $category, string $name): array => [
                    'name' => $category['ua_name'] ?? $name,
                    'url' => $category['ua'],
                ])
                ->reject(fn (array $category): bool => $this->shouldSkipSiteCatalogCategory($category['url']))
                ->unique('url')
                ->values();
        }

        if ($categoryUrl !== '') {
            $canonicalCategoryUrl = $this->canonicalCategoryUrl($categoryUrl);
            $siteCategories = $siteCategories
                ->filter(fn (array $category): bool => $this->canonicalCategoryUrl($category['url']) === $canonicalCategoryUrl)
                ->values();
        }

        return ($maxCategories > 0 ? $siteCategories->take($maxCategories) : $siteCategories)
            ->all();
    }

    protected function shouldSkipSiteCatalogCategory(string $url): bool
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return in_array($this->withoutLocalePrefix($path), ['category/10', 'category/11'], true);
    }

    protected function modelCategoryUrls(mixed $urls): array
    {
        return collect(Arr::wrap($urls))
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => $this->absoluteUrl($url, 'https://stock-tesla.com'))
            ->map(fn (string $url): string => rtrim($url, '/').'/')
            ->unique()
            ->values()
            ->all();
    }

    protected function modelLabelFromCategoryUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = $this->withoutLocalePrefix($path);
        $slug = trim(Str::after($path, 'category/'), '/');

        return match ($slug) {
            's-2016-1' => "MODEL S \u{0434}\u{043E} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
            's-2016' => "MODEL S \u{043F}\u{0456}\u{0441}\u{043B}\u{044F} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
            'x' => "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} X",
            'y' => "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} Y",
            default => $this->canonicalModelLabel(str_replace('-', ' ', $slug)),
        };
    }

    protected function categoryPageUrls(string $categoryUrl, string $baseUrl, int $maxCategoryPages, array &$stats): iterable
    {
        $firstHtml = $this->fetch($categoryUrl);
        if ($firstHtml === null) {
            return;
        }

        $stats['source_pages_fetched']++;
        yield $categoryUrl => $firstHtml;

        $lastPage = $this->categoryLastPage($firstHtml);
        if ($maxCategoryPages > 0) {
            $lastPage = min($lastPage, $maxCategoryPages);
        }

        for ($page = 2; $page <= $lastPage; $page++) {
            $pageUrl = $this->categoryPageUrl($categoryUrl, $page, $baseUrl);
            $html = $this->fetch($pageUrl);
            if ($html === null) {
                continue;
            }

            $stats['source_pages_fetched']++;
            yield $pageUrl => $html;
        }
    }

    protected function categoryLastPage(string $html): int
    {
        preg_match_all('/(?:[?&]page=|\/page\/)(\d+)/i', $html, $matches);

        $pages = collect($matches[1] ?? [])
            ->map(fn (string $page): int => (int) $page)
            ->filter(fn (int $page): bool => $page > 0);

        return max(1, (int) ($pages->max() ?? 1));
    }

    protected function categoryPageUrl(string $categoryUrl, int $page, string $baseUrl): string
    {
        $parts = parse_url($categoryUrl);
        $path = (string) ($parts['path'] ?? '/');

        return $this->absoluteUrl($path, $baseUrl).'?page='.$page;
    }

    protected function productLinksFromCategoryHtml(string $html, string $baseUrl): array
    {
        preg_match_all('/href=["\']([^"\']*\/product\/[^"\']+)["\']/i', $html, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $url): string => $this->canonicalProductUrl($this->absoluteUrl(html_entity_decode($url), $baseUrl)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function productSummariesFromCategoryHtml(string $html, string $baseUrl): array
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $cards = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " product-grid ")]');
        $products = [];

        foreach ($cards ?: [] as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $anchor = $xpath->query('.//a[contains(@href, "/product/")][1]', $card)->item(0);
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $priceText = $this->clean($xpath->evaluate('string(.//*[contains(concat(" ", normalize-space(@class), " "), " bx_price ")][1])', $card));
            $availabilityText = Str::lower($this->clean($xpath->evaluate('string(.//*[contains(concat(" ", normalize-space(@class), " "), " nalichie ")][1])', $card)));

            $products[] = [
                'source_url' => $this->canonicalProductUrl($this->absoluteUrl(html_entity_decode((string) $anchor->getAttribute('href')), $baseUrl)),
                'price_amount' => $this->priceFromListingText($priceText),
                'currency' => str_contains($priceText, '$') ? 'USD' : 'UAH',
                'available' => $availabilityText === '' || str_contains($availabilityText, "\u{043D}\u{0430}\u{044F}\u{0432}\u{043D}\u{043E}\u{0441}\u{0442}\u{0456}") || str_contains($availabilityText, "\u{043D}\u{0430}\u{043B}\u{0438}\u{0447}"),
            ];
        }

        if ($products !== []) {
            return collect($products)->unique('source_url')->values()->all();
        }

        return collect($this->productLinksFromCategoryHtml($html, $baseUrl))
            ->map(fn (string $url): array => [
                'source_url' => $url,
                'price_amount' => 0.0,
                'currency' => 'USD',
                'available' => true,
            ])
            ->all();
    }

    protected function priceFromListingText(string $text): float
    {
        if (preg_match('/([\d\s]+(?:[,.]\d+)?)/u', $text, $match) !== 1) {
            return 0.0;
        }

        return $this->decimalNumber(str_replace(' ', '', $match[1]));
    }

    protected function siteProductOffer(string $html, string $sourceUrl, string $baseUrl): ?array
    {
        $product = $this->jsonLdProduct($html) ?? [];
        $breadcrumb = $this->jsonLdBreadcrumb($html);
        $offers = collect(Arr::wrap($product['offers'] ?? []))
            ->filter(fn (mixed $offer): bool => is_array($offer))
            ->values();
        $offer = $offers->first(fn (array $offer): bool => strtoupper((string) ($offer['priceCurrency'] ?? '')) === 'UAH')
            ?? $offers->first(fn (array $offer): bool => strtoupper((string) ($offer['priceCurrency'] ?? '')) === 'USD')
            ?? $offers->first()
            ?? [];

        $partNumber = $this->clean((string) ($product['mpn'] ?? $product['sku'] ?? $this->metaContent($html, 'product:retailer_item_id') ?? ''));
        $name = $this->clean((string) ($product['name'] ?? $this->htmlTitle($html)));
        if ($name === '' || $partNumber === '') {
            return null;
        }

        $pictures = collect(Arr::wrap($product['image'] ?? []))
            ->push($this->metaContent($html, 'og:image'))
            ->merge($this->productImageUrlsFromHtml($html, $baseUrl))
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->map(fn (string $url): string => $this->absoluteUrl($url, $baseUrl))
            ->unique()
            ->values()
            ->all();
        $availability = (string) ($offer['availability'] ?? $this->metaContent($html, 'product:availability') ?? '');
        $currency = $this->clean((string) ($offer['priceCurrency'] ?? $this->metaContent($html, 'product:price:currency') ?? 'USD'));
        $price = $this->decimalNumber($offer['price'] ?? $this->metaContent($html, 'product:price:amount'));
        $categoryTrail = $this->breadcrumbCategoryTrail($breadcrumb);
        $categoryUrls = collect($categoryTrail)->pluck('url')->all();
        $categoryName = $this->clean((string) ($product['category'] ?? $this->metaContent($html, 'product:category') ?? ''));
        $condition = $this->productCondition($html, $product, $offer);

        return [
            'feed_id' => null,
            'available' => $this->isAvailable($availability),
            'name_ua' => $name,
            'source_url' => $this->canonicalProductUrl($sourceUrl),
            'price_amount' => $price,
            'currency' => $currency,
            'category_id' => null,
            'category_urls' => $categoryUrls,
            'category_trail' => $categoryTrail,
            'part_number' => $partNumber,
            'condition' => $condition,
            'description_uk' => $this->clean((string) ($product['description'] ?? $this->metaContent($html, 'og:description') ?? '')),
            'quantity_in_stock' => $this->isAvailable($availability) ? 1 : 0,
            'pictures' => $pictures,
            'raw_attributes' => [
                'category_url' => end($categoryUrls) ?: null,
                'breadcrumb_category_urls' => $categoryUrls,
                'breadcrumb_category_names' => collect($categoryTrail)->pluck('name')->all(),
                'breadcrumb_category_trail' => $categoryTrail,
                'category_ua' => $categoryName,
                'condition_source' => $condition,
                'site_catalog_scan' => true,
            ],
        ];
    }

    protected function productCondition(string $html, array $product, array $offer): ?string
    {
        $values = [
            $product['itemCondition'] ?? null,
            $offer['itemCondition'] ?? null,
        ];

        if (preg_match('/product-condition[^>]*>\s*[^<:]+:\s*<strong>([^<]+)<\/strong>/iu', $html, $match) === 1) {
            $values[] = $match[1];
        }

        foreach ($values as $value) {
            $condition = $this->normalizedCondition((string) $value);
            if ($condition !== null) {
                return $condition;
            }
        }

        return null;
    }

    protected function normalizedCondition(string $value): ?string
    {
        $value = Str::lower($this->clean($value));
        if ($value === '') {
            return null;
        }

        return match (true) {
            str_contains($value, 'usedcondition') || in_array($value, ['used', "\u{0431}/\u{0443}", "\u{0431}\u{0443}", "\u{0431}\u{0432}"], true) => 'used',
            str_contains($value, 'newcondition') || in_array($value, ['new', "\u{043D}\u{043E}\u{0432}\u{043E}\u{0435}", "\u{043D}\u{043E}\u{0432}\u{0438}\u{0439}", "\u{043D}\u{043E}\u{0432}\u{0435}"], true) => 'new',
            str_contains($value, 'refurbishedcondition') || str_contains($value, "\u{0432}\u{043E}\u{0441}\u{0441}\u{0442}\u{0430}\u{043D}\u{043E}\u{0432}") || str_contains($value, "\u{0432}\u{0456}\u{0434}\u{043D}\u{043E}\u{0432}") => 'refurbished',
            default => null,
        };
    }

    protected function siteProductFromUrl(string $sourceUrl, string $baseUrl, array &$stats): ?array
    {
        $html = $this->fetch($sourceUrl);
        if ($html === null) {
            return null;
        }

        $stats['source_pages_fetched']++;
        $stats['site_product_pages_fetched']++;

        return $this->siteProductOffer($html, $sourceUrl, $baseUrl);
    }

    protected function mergeOfferSiteDetails(array $offer, array $siteOffer): array
    {
        $rawAttributes = ($offer['raw_attributes'] ?? []) + ($siteOffer['raw_attributes'] ?? []);
        $rawAttributes['breadcrumb_category_urls'] = $siteOffer['category_urls'] ?? [];
        $rawAttributes['category_url'] = end($rawAttributes['breadcrumb_category_urls']) ?: null;

        return $offer + [
            'category_urls' => $siteOffer['category_urls'] ?? [],
            'raw_attributes' => $rawAttributes,
        ];
    }

    protected function productImageUrlsFromHtml(string $html, string $baseUrl): array
    {
        preg_match_all('/(?:href|src)=["\']([^"\']*\/media\/products\/[^"\']+\.(?:webp|jpe?g|png)(?:\?[^"\']*)?)["\']/i', $html, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $url): string => $this->absoluteUrl(html_entity_decode($url), $baseUrl))
            ->unique()
            ->values()
            ->all();
    }

    protected function withDownloadedImages(array $offer, array &$stats): array
    {
        $remoteUrls = collect($offer['pictures'] ?? [])
            ->filter(fn (mixed $url): bool => is_string($url) && Str::startsWith($url, ['http://', 'https://']))
            ->unique()
            ->values();

        if ($remoteUrls->isEmpty()) {
            return $offer;
        }

        $partNumber = $this->canonicalPartNumber((string) ($offer['part_number'] ?? '')) ?? 'unknown';
        $localPaths = [];
        $localUrls = [];

        foreach ($remoteUrls as $url) {
            $path = $this->downloadProductImage($partNumber, $url);
            if ($path === null) {
                $stats['images_failed']++;

                continue;
            }

            $stats['images_downloaded']++;
            $localPaths[] = $path;
            $localUrls[] = Storage::url($path);
        }

        if ($localUrls !== []) {
            $offer['remote_pictures'] = $remoteUrls->all();
            $offer['local_image_paths'] = array_values(array_unique($localPaths));
            $offer['pictures'] = array_values(array_unique($localUrls));
        }

        return $offer;
    }

    protected function downloadProductImage(string $partNumber, string $url): ?string
    {
        $path = $this->stockTeslaImagePath($partNumber, $url);
        if ($path === null) {
            return null;
        }

        if (Storage::disk('public')->exists($path)) {
            return $path;
        }

        try {
            $response = $this->http
                ->timeout(20)
                ->retry(1, 300)
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

    protected function stockTeslaImagePath(string $partNumber, string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = Str::slug(Str::limit($name, 80, ''), '-');
        if ($name === '') {
            $name = sha1($url);
        }

        return 'competitor-catalog/stock-tesla/'.$this->compactPartNumber($partNumber).'/'.$name.'-'.substr(sha1($url), 0, 10).'.'.$extension;
    }

    protected function existingProductItem(string $sourceUrl, ?string $partNumber): ?PartCatalogItem
    {
        return PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('source_url', $sourceUrl)
            ->first();
    }

    protected function updateExistingProductFromListing(array $listingProduct): ?PartCatalogItem
    {
        $item = PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('source_url', $listingProduct['source_url'])
            ->first();

        if (! $item instanceof PartCatalogItem) {
            return null;
        }

        $price = (float) ($listingProduct['price_amount'] ?? 0);
        if ($price > 0) {
            $item->price_amount = app(ExchangeRateService::class)->catalogPriceToUsd(
                $price,
                (string) ($listingProduct['currency'] ?? 'USD')
            );
            $item->currency = 'USD';
        }

        $item->availability = ($listingProduct['available'] ?? true) ? 'in stock' : 'out of stock';
        $rawAttributes = $this->rawAttributesArray($item);
        $rawAttributes['listing_price_amount'] = $price;
        $rawAttributes['listing_currency'] = (string) ($listingProduct['currency'] ?? 'USD');
        $rawAttributes['listing_available'] = (bool) ($listingProduct['available'] ?? true);
        $item->raw_attributes = $rawAttributes;
        $item->source_updated_at = now();
        $item->save();

        return $item;
    }

    protected function listingCategoryForSiteCategory(array $siteCategory, array &$savedCategoriesBySiteUrl): ?PartCatalogCategory
    {
        $url = $this->canonicalCategoryUrl((string) ($siteCategory['url'] ?? ''));
        if ($url === 'https://stock-tesla.com//') {
            return null;
        }

        $category = $savedCategoriesBySiteUrl[$url] ?? PartCatalogCategory::query()
            ->where('source', $this->source)
            ->where('source_url', $url)
            ->first();

        if ($category instanceof PartCatalogCategory) {
            $savedCategoriesBySiteUrl[$url] = $category;

            return $category;
        }

        return null;
    }

    protected function saveListingOccurrence(PartCatalogItem $item, PartCatalogCategory $category, array $listingProduct, string $pageUrl): void
    {
        $productUrl = (string) ($listingProduct['source_url'] ?? $item->source_url);
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
                'page_url' => $this->canonicalCategoryUrl($pageUrl),
                'product_url' => $productUrl,
                'part_number' => $item->part_number,
                'name' => $item->name,
                'raw_attributes' => array_filter([
                    'listing_category_url' => $category->source_url,
                    'listing_page_url' => $pageUrl,
                    'listing_price_amount' => $listingProduct['price_amount'] ?? null,
                    'listing_currency' => $listingProduct['currency'] ?? null,
                    'listing_available' => $listingProduct['available'] ?? null,
                ], fn (mixed $value): bool => $value !== null),
            ]
        );
    }

    protected function withMergedSourceUrls(array $payload, ?PartCatalogItem $item, string $sourceUrl): array
    {
        $rawAttributes = $payload['raw_attributes'] ?? [];
        $existingRawAttributes = $item instanceof PartCatalogItem ? $this->rawAttributesArray($item) : [];

        $sourceUrls = collect(data_get($existingRawAttributes, 'source_urls', []))
            ->merge(data_get($existingRawAttributes, 'product_source_urls', []))
            ->push(data_get($existingRawAttributes, 'url_uk'))
            ->push($item?->source_url)
            ->push($sourceUrl)
            ->push(data_get($rawAttributes, 'url_uk'))
            ->filter(fn (mixed $url): bool => is_string($url) && Str::startsWith($url, ['http://', 'https://']))
            ->map(fn (string $url): string => rtrim($url, '/').'/')
            ->unique()
            ->values()
            ->all();

        $payload['raw_attributes'] = $rawAttributes + [
            'source_urls' => $sourceUrls,
            'product_source_urls' => $sourceUrls,
        ];

        $payload['raw_attributes']['source_urls'] = $sourceUrls;
        $payload['raw_attributes']['product_source_urls'] = $sourceUrls;

        return $payload;
    }

    protected function rawAttributesArray(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function itemHasImages(?PartCatalogItem $item): bool
    {
        if (! $item instanceof PartCatalogItem) {
            return false;
        }

        return collect(data_get($this->rawAttributesArray($item), 'image_urls', []))
            ->push(data_get($this->rawAttributesArray($item), 'image_url'))
            ->contains(fn (mixed $url): bool => is_string($url) && $this->catalogImageExists($url));
    }

    protected function catalogImageExists(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return true;
        }

        $path = Str::startsWith($url, '/storage/')
            ? Str::after($url, '/storage/')
            : ltrim($url, '/');

        return $path !== '' && Storage::disk('public')->exists($path);
    }

    protected function isVariantOffer(array $offer): bool
    {
        $partNumber = $this->clean((string) ($offer['part_number'] ?? ''));
        $canonicalPartNumber = $this->canonicalPartNumber($partNumber);

        if ($canonicalPartNumber !== null && mb_strtoupper($partNumber) !== $canonicalPartNumber) {
            return true;
        }

        $path = parse_url((string) ($offer['source_url'] ?? ''), PHP_URL_PATH) ?: '';

        return preg_match('#/[0-9]{7}-[a-z0-9]{2}-[a-z0-9]-[rx]/?$#i', $path) === 1;
    }

    protected function russianProduct(string $ukUrl, string $baseUrl): ?array
    {
        $path = parse_url($ukUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $ruUrl = $baseUrl.'/ru'.($path[0] === '/' ? $path : '/'.$path);
        $html = $this->fetch($ruUrl);
        if ($html === null) {
            return null;
        }

        $product = $this->jsonLdProduct($html) ?? [];
        $breadcrumb = $this->jsonLdBreadcrumb($html);

        return [
            'source_url' => $ruUrl,
            'name_ru' => $this->clean((string) ($product['name'] ?? $this->htmlTitle($html))),
            'description_ru' => $this->clean((string) ($product['description'] ?? '')),
            'category_ru' => $this->clean((string) ($product['category'] ?? '')),
            'category_urls' => $this->breadcrumbCategoryUrls($breadcrumb),
            'part_number' => $this->clean((string) ($product['mpn'] ?? $product['sku'] ?? '')),
        ];
    }

    protected function russianProductUrl(PartCatalogItem $item, string $baseUrl): ?string
    {
        $rawAttributes = $this->rawAttributesArray($item);
        $url = data_get($rawAttributes, 'url_ru');

        if (is_string($url) && Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $path = parse_url((string) $item->source_url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (str_starts_with($path, '/ru/')) {
            return $baseUrl.$path;
        }

        return $baseUrl.'/ru'.($path[0] === '/' ? $path : '/'.$path);
    }

    protected function jsonLdProduct(string $html): ?array
    {
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>([\s\S]*?)<\/script>/i', $html, $matches);

        foreach ($matches[1] ?? [] as $json) {
            $payload = json_decode($json, true);
            if (is_array($payload)) {
                $product = $this->findJsonLdProduct($payload);
                if ($product !== null) {
                    return $product;
                }
            }
        }

        return null;
    }

    protected function jsonLdBreadcrumb(string $html): ?array
    {
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>([\s\S]*?)<\/script>/i', $html, $matches);

        foreach ($matches[1] ?? [] as $json) {
            $payload = json_decode($json, true);
            if (! is_array($payload)) {
                continue;
            }

            $breadcrumb = $this->findJsonLdBreadcrumb($payload);
            if ($breadcrumb !== null) {
                return $breadcrumb;
            }
        }

        return null;
    }

    protected function findJsonLdProduct(array $payload): ?array
    {
        if (Arr::get($payload, '@type') === 'Product') {
            return $payload;
        }

        foreach (Arr::wrap(Arr::get($payload, '@graph', $payload)) as $node) {
            if (is_array($node) && Arr::get($node, '@type') === 'Product') {
                return $node;
            }
        }

        return null;
    }

    protected function findJsonLdBreadcrumb(array $payload): ?array
    {
        if (Arr::get($payload, '@type') === 'BreadcrumbList') {
            return $payload;
        }

        foreach (Arr::wrap(Arr::get($payload, '@graph', $payload)) as $node) {
            if (is_array($node) && Arr::get($node, '@type') === 'BreadcrumbList') {
                return $node;
            }
        }

        return null;
    }

    protected function breadcrumbCategoryUrls(?array $breadcrumb): array
    {
        return collect($this->breadcrumbCategoryTrail($breadcrumb))
            ->pluck('url')
            ->values()
            ->all();
    }

    protected function breadcrumbCategoryTrail(?array $breadcrumb): array
    {
        return collect(Arr::wrap(Arr::get($breadcrumb ?? [], 'itemListElement', [])))
            ->map(function (mixed $item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $url = (string) Arr::get($item, 'item');
                if (! str_contains($url, '/category/')) {
                    return null;
                }

                $name = $this->clean((string) Arr::get($item, 'name'));
                if ($name === '') {
                    return null;
                }

                return [
                    'name' => $name,
                    'url' => $this->canonicalCategoryUrl($url),
                ];
            })
            ->filter()
            ->unique('url')
            ->values()
            ->all();
    }

    protected function metaContent(string $html, string $property): ?string
    {
        $property = preg_quote($property, '/');
        foreach ([
            '/<meta\b[^>]*(?:property|name)=["\']'.$property.'["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i',
            '/<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']'.$property.'["\'][^>]*>/i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $match) === 1) {
                return $this->clean($match[1]);
            }
        }

        return null;
    }

    protected function decimalNumber(mixed $value): float
    {
        $value = str_replace(',', '.', $this->clean((string) $value));

        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function isAvailable(string $availability): bool
    {
        $availability = Str::lower($availability);

        return str_contains($availability, 'instock') || str_contains($availability, 'in stock') || str_contains($availability, 'limitedavailability');
    }

    protected function htmlTitle(string $html): string
    {
        foreach ([
            '/<meta\b[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i',
            '/<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\'][^>]*>/i',
            '/<h1\b[^>]*>([\s\S]*?)<\/h1>/i',
            '/<title\b[^>]*>([\s\S]*?)<\/title>/i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $match) === 1) {
                $title = $this->clean(strip_tags($match[1]));
                $title = preg_replace('/\s*\|\s*Stock Tesla\s*$/iu', '', $title) ?: $title;

                if ($this->isUsableLocalizedText($title)) {
                    return $title;
                }
            }
        }

        return '';
    }

    protected function productPayload(?PartCatalogCategory $category, ?array $categoryPayload, array $offer, ?array $russian): array
    {
        $mainCategory = $category ? $this->ancestorAtDepth($category, 1) : null;
        $subcategory = $category ? $this->ancestorAtDepth($category, 2) : null;
        $partNumber = $this->canonicalPartNumber(($russian['part_number'] ?? null) ?: $offer['part_number']);
        $rawAttributes = ($offer['raw_attributes'] ?? []) + [
            'feed_id' => $offer['feed_id'],
            'category_id' => $offer['category_id'],
            'quantity_in_stock' => $offer['quantity_in_stock'],
            'image_url' => $offer['pictures'][0] ?? null,
            'image_urls' => $offer['pictures'],
            'remote_image_urls' => $offer['remote_pictures'] ?? null,
            'local_image_paths' => $offer['local_image_paths'] ?? null,
            'url_uk' => $offer['source_url'],
            'url_ru' => $russian['source_url'] ?? null,
            'category_ru' => $russian['category_ru'] ?? null,
            'breadcrumb_category_urls' => $offer['category_urls'] ?? ($russian['category_urls'] ?? null),
            'category_url' => collect($offer['category_urls'] ?? ($russian['category_urls'] ?? []))->last(),
        ];

        if (collect((array) ($offer['pictures'] ?? []))->filter()->isEmpty()) {
            $rawAttributes['catalog_image_missing'] = true;
            $rawAttributes['catalog_image_missing_reason'] = 'no_image_urls';
            $rawAttributes['catalog_image_missing_marked_at'] = now()->toDateTimeString();
        } else {
            unset($rawAttributes['catalog_image_missing'], $rawAttributes['catalog_image_missing_reason'], $rawAttributes['catalog_image_missing_marked_at']);
        }

        return [
            'part_catalog_category_id' => $category?->id,
            'source' => $this->source,
            'part_number' => $partNumber,
            'name' => $offer['name_ua'],
            'name_ua' => $offer['name_ua'],
            'name_ru' => $this->isUsableLocalizedText($russian['name_ru'] ?? null) ? $russian['name_ru'] : null,
            'price_amount' => app(ExchangeRateService::class)->catalogPriceToUsd($offer['price_amount'], $offer['currency'] ?: 'UAH'),
            'currency' => $offer['price_amount'] ? 'USD' : null,
            'model_label' => $category?->model_label,
            'model_name' => $category?->model_name,
            'main_category_code' => $mainCategory?->code,
            'main_category_name' => $mainCategory?->name,
            'subcategory_code' => $subcategory?->code,
            'subcategory_name' => $subcategory?->name,
            'node_name' => $category?->name ?? ($categoryPayload['name_ua'] ?? null),
            'notes_ua' => $offer['description_uk'],
            'notes_ru' => $russian['description_ru'] ?? null,
            'condition' => $offer['condition'] ?? null,
            'availability' => $offer['available'] ? 'in stock' : 'out of stock',
            'raw_attributes' => $rawAttributes,
            'source_updated_at' => now(),
        ];
    }

    protected function ensureBreadcrumbCategory(array $offer, array &$savedCategoriesBySiteUrl): ?PartCatalogCategory
    {
        $trail = collect($offer['category_trail'] ?? [])
            ->filter(fn (mixed $category): bool => is_array($category)
                && isset($category['url'], $category['name'])
                && str_contains((string) $category['url'], '/category/'))
            ->values();

        if ($trail->isEmpty()) {
            return null;
        }

        $modelLabel = $this->canonicalModelLabel((string) $trail->first()['name']);
        $parent = null;
        $last = null;

        foreach ($trail as $depth => $categoryData) {
            $url = $this->canonicalCategoryUrl((string) $categoryData['url']);
            $name = $this->clean((string) $categoryData['name']);
            if ($name === '') {
                continue;
            }

            $payload = [
                'source' => $this->source,
                'parent_id' => $parent?->id,
                'depth' => $depth,
                'code' => $this->categoryCode($name),
                'name' => $name,
                'name_ua' => $name,
                'model_label' => $modelLabel,
                'model_name' => $modelLabel,
                'sort_order' => $this->categorySortOrder($this->categoryCode($name)),
                'children_scanned_at' => now(),
                'products_scanned_at' => now(),
            ];

            $category = $savedCategoriesBySiteUrl[$url] ?? PartCatalogCategory::query()
                ->where('source', $this->source)
                ->where('source_url', $url)
                ->first();

            if ($category instanceof PartCatalogCategory) {
                if ($category->parent_id !== null && $depth > 0) {
                    unset($payload['parent_id']);
                }

                $category->fill($payload);
                $category->save();
            } else {
                $category = PartCatalogCategory::query()->create(['source_url' => $url] + $payload);
            }

            $savedCategoriesBySiteUrl[$url] = $category;
            $parent = $category;
            $last = $category;
        }

        return $last;
    }

    protected function fallbackCategory(array $offer, array $savedCategories): ?PartCatalogCategory
    {
        $modelLabel = $this->modelLabelFromProductText($offer['name_ua'].' '.$offer['description_uk']);
        $root = $this->savedRootCategory($savedCategories, $modelLabel)
            ?? PartCatalogCategory::query()
                ->where('source', $this->source)
                ->where('depth', 0)
                ->where('model_label', $modelLabel)
                ->first();

        if ($root === null) {
            return null;
        }

        return PartCatalogCategory::query()->updateOrCreate(
            ['source_url' => 'https://stock-tesla.com/category-feed/uncategorized/'.Str::slug($modelLabel)],
            [
                'source' => $this->source,
                'parent_id' => $root->id,
                'depth' => 1,
                'code' => null,
                'name' => "\u{0411}\u{0435}\u{0437} \u{043A}\u{0430}\u{0442}\u{0435}\u{0433}\u{043E}\u{0440}\u{0456}\u{0457}",
                'name_en' => 'Uncategorized',
                'name_ua' => "\u{0411}\u{0435}\u{0437} \u{043A}\u{0430}\u{0442}\u{0435}\u{0433}\u{043E}\u{0440}\u{0456}\u{0457}",
                'name_ru' => "\u{0411}\u{0435}\u{0437} \u{043A}\u{0430}\u{0442}\u{0435}\u{0433}\u{043E}\u{0440}\u{0438}\u{0438}",
                'model_label' => $modelLabel,
                'model_name' => $modelLabel,
                'sort_order' => 999999,
                'children_scanned_at' => now(),
                'products_scanned_at' => now(),
            ]
        );
    }

    protected function modelLabelFromProductText(string $text): string
    {
        $text = Str::lower($text);

        return match (true) {
            str_contains($text, 'model y') => "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} Y",
            str_contains($text, 'model x') => "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} X",
            str_contains($text, 'model 3') => "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} 3",
            str_contains($text, 'model s rest') || str_contains($text, 'model s feb 2021') => "MODEL S \u{043F}\u{0456}\u{0441}\u{043B}\u{044F} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
            str_contains($text, 'model s') => "MODEL S \u{0434}\u{043E} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
            default => "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} 3",
        };
    }

    protected function ancestorAtDepth(PartCatalogCategory $category, int $depth): ?PartCatalogCategory
    {
        while ((int) $category->depth > $depth && $category->parent_id !== null) {
            $category = PartCatalogCategory::query()->find($category->parent_id) ?: $category;
        }

        return (int) $category->depth === $depth ? $category : null;
    }

    protected function canonicalProductUrl(string $url): string
    {
        $path = $this->withoutLocalePrefix(trim((string) parse_url($url, PHP_URL_PATH), '/'));

        return 'https://stock-tesla.com/'.$path.'/';
    }

    protected function canonicalCategoryUrl(string $url): string
    {
        $path = $this->withoutLocalePrefix(trim((string) parse_url($url, PHP_URL_PATH), '/'));

        return 'https://stock-tesla.com/'.$path.'/';
    }

    protected function withoutLocalePrefix(string $path): string
    {
        return preg_replace('#^(?:ru|en)/#i', '', trim($path, '/')) ?: trim($path, '/');
    }

    protected function compactPartNumber(string $partNumber): string
    {
        $compact = preg_replace('/[^A-Z0-9]/', '', Str::upper($partNumber)) ?: '';

        return $compact !== '' ? $compact : 'UNKNOWN';
    }

    protected function canonicalPartNumber(string $partNumber): ?string
    {
        $partNumber = $this->clean($partNumber);
        $partNumber = preg_replace('/^([0-9]{7}-[A-Z0-9]{2}-[A-Z0-9])[\s-]+[RX]$/iu', '$1', $partNumber) ?: $partNumber;

        return $partNumber === '' ? null : mb_strtoupper($partNumber);
    }

    protected function categoryCode(string $name): ?string
    {
        if (preg_match('/^(\d{2})(?:\s*-\s*(\d{2}))?/', $name, $matches) !== 1) {
            return null;
        }

        return isset($matches[2]) && $matches[2] !== ''
            ? $matches[1].$matches[2]
            : $matches[1];
    }

    protected function categorySortOrder(?string $code): int
    {
        return $code !== null && ctype_digit($code) ? (int) $code : 0;
    }

    protected function canonicalModelLabel(string $name): string
    {
        $name = Str::lower($name);

        return match (true) {
            str_contains($name, 'model s '.mb_strtolower("\u{0434}\u{043E}")) || str_contains($name, 'model s before') => "MODEL S \u{0434}\u{043E} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
            str_contains($name, 'model s '.mb_strtolower("\u{043F}\u{0456}\u{0441}\u{043B}\u{044F}")) || str_contains($name, 'model s '.mb_strtolower("\u{043F}\u{043E}\u{0441}\u{043B}\u{0435}")) || str_contains($name, 'model s after') => "MODEL S \u{043F}\u{0456}\u{0441}\u{043B}\u{044F} 2016 \u{0440}\u{043E}\u{043A}\u{0443}",
            str_contains($name, 'model x') || str_contains($name, mb_strtolower("\u{043C}\u{043E}\u{0434}\u{0435}\u{043B}\u{044C} x")) => "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} X",
            str_contains($name, 'model y') || str_contains($name, mb_strtolower("\u{043C}\u{043E}\u{0434}\u{0435}\u{043B}\u{044C} y")) => "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} Y",
            default => "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} 3",
        };
    }

    protected function absoluteUrl(string $path, string $baseUrl): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    protected function clean(?string $value): string
    {
        return trim(html_entity_decode(preg_replace('/\s+/u', ' ', (string) $value) ?: '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    protected function isUsableLocalizedText(?string $value): bool
    {
        $value = $this->clean($value);

        return $value !== '' && preg_match('/[\x{0400}-\x{052F}]/u', $value) === 1;
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($verbose && $progress !== null) {
            $progress($message);
        }
    }
}
