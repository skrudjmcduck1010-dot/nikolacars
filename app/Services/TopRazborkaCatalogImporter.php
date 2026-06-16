<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use DOMElement;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class TopRazborkaCatalogImporter extends ErazborkaCatalogImporter
{
    protected string $source = 'toprazborka';

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://toprazborka.com.ua'), '/');
        $startUrl = $this->absoluteUrl((string) ($options['start_url'] ?? '/'), $baseUrl);
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
                        'name_ua' => $modelLabel,
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
                if (! $dryRun && $modelCategory !== null) {
                    $this->saveTopRazborkaCategoryTree($modelCategory, $category, 1, $index + 1, $stats);
                }
            }
        }

        return $stats;
    }

    public function importProducts(array $options = []): array
    {
        $options['base_url'] ??= 'https://toprazborka.com.ua';
        $includeModelRoots = (bool) ($options['include_model_roots'] ?? true);
        $isLimitedRun = (int) ($options['max_categories'] ?? 0) > 0 || (int) ($options['max_products'] ?? 0) > 0;

        $stats = parent::importProducts($options);

        if ($includeModelRoots && ! $isLimitedRun) {
            $rootStats = $this->importModelRootProducts($options);
            foreach ($rootStats as $key => $value) {
                $stats['model_root_'.$key] = $value;
            }
        }

        return $stats;
    }

    public function importModelRootProducts(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://toprazborka.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxPagesPerCategory = max(1, (int) ($options['max_pages_per_category'] ?? 80));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $model = trim((string) ($options['model'] ?? ''));

        $stats = [
            'source_pages_fetched' => 0,
            'categories_scanned' => 0,
            'category_pages_scanned' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'new_products_saved' => 0,
            'existing_products_refreshed' => 0,
            'product_images_saved' => 0,
        ];

        $existingProductUrls = PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereNotNull('source_url')
            ->pluck('source_url')
            ->flip()
            ->all();

        $query = PartCatalogCategory::query()
            ->where('source', $this->source)
            ->where('depth', 0)
            ->whereIn('source_url', [$baseUrl.'/electronics/', $baseUrl.'/odyag-ta-vzuttya/'])
            ->orderBy('id');

        if (in_array($model, ['3', 'model3', 'Model 3'], true)) {
            $query->where('source_url', $baseUrl.'/electronics/');
        } elseif (in_array($model, ['y', 'modely', 'Model Y'], true)) {
            $query->where('source_url', $baseUrl.'/odyag-ta-vzuttya/');
        }

        foreach ($query->get() as $category) {
            $stats['categories_scanned']++;
            $this->progress($progress, $verbose, "Model root #{$category->id}: {$category->name}");

            foreach ($this->categoryPageUrls((string) $category->source_url, $baseUrl, $maxPagesPerCategory, $sleepMs, $stats) as $pageUrl => $page) {
                $stats['category_pages_scanned']++;
                $this->progress($progress, $verbose, "  Page {$stats['category_pages_scanned']}: {$pageUrl}");

                foreach ($this->productsFromCategoryPage($page, $baseUrl, $existingProductUrls, $stats) as $product) {
                    $stats['products_found']++;

                    if ($dryRun) {
                        continue;
                    }

                    if (isset($existingProductUrls[$product['source_url']])) {
                        PartCatalogItem::query()
                            ->where('source', $this->source)
                            ->where('source_url', $product['source_url'])
                            ->first()
                            ?->forceFill($this->existingProductRefreshPayload($product))
                            ->save();

                        $stats['existing_products_refreshed']++;
                    } else {
                        PartCatalogItem::query()->updateOrCreate(
                            ['source_url' => $product['source_url']],
                            $this->productPayload($category, $product)
                        );

                        $existingProductUrls[$product['source_url']] = count($existingProductUrls);
                        $stats['new_products_saved']++;
                    }

                    $stats['products_saved']++;
                }
            }
        }

        return $stats;
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = $this->http
                ->timeout(30)
                ->retry(2, 500)
                ->withHeaders($this->requestHeaders())
                ->get($url);

            if (! $response->ok()) {
                return null;
            }

            $body = $response->body();
            $challengeHash = $this->challengeHash($body);
            if ($challengeHash === null) {
                return $body;
            }

            $response = $this->http
                ->timeout(30)
                ->retry(2, 500)
                ->withHeaders($this->requestHeaders(['Cookie' => 'challenge_passed='.$challengeHash]))
                ->get($url);

            return $response->ok() ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function catalogCategories(array $page, string $baseUrl): array
    {
        $models = [
            '3' => [
                'url' => $baseUrl.'/electronics/',
                'name' => 'Model 3',
                'children' => [],
            ],
            'y' => [
                'url' => $baseUrl.'/odyag-ta-vzuttya/',
                'name' => 'Model Y',
                'children' => [],
            ],
        ];

        foreach ($page['xpath']->query('//a[@href]') as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($link->getAttribute('href'), $baseUrl);
            if ($url === null || ! str_starts_with($url, $baseUrl.'/')) {
                continue;
            }

            $name = $this->clean($link->getAttribute('title') ?: $link->textContent);
            if ($name === '') {
                continue;
            }

            $rootModelKey = $this->topRazborkaRootModelKey($name, $url, $baseUrl);
            if ($rootModelKey !== null) {
                $root = $this->closestElement($link, 'li');
                if ($root !== null) {
                    $children = $this->topRazborkaMenuChildren($root, $baseUrl, $rootModelKey);
                    if ($children !== []) {
                        $models[$rootModelKey]['children'] = $children;
                    }
                }

                continue;
            }

            $modelKey = $this->topRazborkaModelKey($name);
            if ($modelKey === null || $models[$modelKey]['children'] !== []) {
                continue;
            }

            $models[$modelKey]['children'][$url] = [
                'url' => $url,
                'name' => $name,
                'children' => [],
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

    protected function saveTopRazborkaCategoryTree(
        PartCatalogCategory $parent,
        array $category,
        int $depth,
        int $sortOrder,
        array &$stats
    ): PartCatalogCategory {
        $saved = PartCatalogCategory::query()->updateOrCreate(
            ['source_url' => $category['url']],
            [
                'source' => $this->source,
                'parent_id' => $parent->id,
                'depth' => $depth,
                'code' => null,
                'name' => $category['name'],
                'name_ru' => $category['name'],
                'name_ua' => $category['name'],
                'model_label' => $parent->model_label,
                'model_name' => $parent->model_name,
                'year_from' => $parent->year_from,
                'year_to' => $parent->year_to,
                'sort_order' => $sortOrder,
                'children_scanned_at' => now(),
            ]
        );

        $stats['categories_saved']++;

        foreach ($category['children'] ?? [] as $index => $child) {
            $this->saveTopRazborkaCategoryTree($saved, $child, $depth + 1, $index + 1, $stats);
        }

        return $saved;
    }

    protected function topRazborkaMenuChildren(DOMElement $root, string $baseUrl, string $modelKey): array
    {
        $categories = [];

        foreach ($this->topRazborkaChildLists($root) as $list) {
            foreach ($this->directChildElements($list, 'li') as $item) {
                $category = $this->topRazborkaMenuCategory($item, $baseUrl, $modelKey);
                if ($category === null) {
                    continue;
                }

                $categories[$category['url']] = $category;
            }
        }

        return array_values($categories);
    }

    protected function topRazborkaChildLists(DOMElement $root): array
    {
        if ($this->hasClass($root, 'products-menu__item')) {
            foreach ($root->getElementsByTagName('ul') as $list) {
                if ($this->hasClass($list, 'productsMenu-submenu-w')) {
                    return [$list];
                }
            }

            return [];
        }

        return $this->directChildElements($root, 'ul');
    }

    protected function topRazborkaMenuCategory(DOMElement $item, string $baseUrl, string $modelKey): ?array
    {
        $link = $this->directChildLink($item);
        if (! $link instanceof DOMElement) {
            return null;
        }

        $url = $this->absoluteUrl($link->getAttribute('href'), $baseUrl);
        if ($url === null || ! str_starts_with($url, $baseUrl.'/')) {
            return null;
        }

        $name = $this->clean($link->getAttribute('title') ?: $link->textContent);
        if ($name === '' || $this->topRazborkaModelKey($name) !== $modelKey) {
            return null;
        }

        return [
            'url' => $url,
            'name' => $name,
            'children' => $this->topRazborkaMenuChildren($item, $baseUrl, $modelKey),
        ];
    }

    protected function directChildLink(DOMElement $node): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && mb_strtolower($child->tagName, 'UTF-8') === 'a') {
                return $child;
            }
        }

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || mb_strtolower($child->tagName, 'UTF-8') === 'ul') {
                continue;
            }

            foreach ($child->getElementsByTagName('a') as $link) {
                return $link;
            }
        }

        return null;
    }

    protected function directChildElements(DOMElement $node, string $tagName): array
    {
        $tagName = mb_strtolower($tagName, 'UTF-8');
        $children = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && mb_strtolower($child->tagName, 'UTF-8') === $tagName) {
                $children[] = $child;
            }
        }

        return $children;
    }

    protected function hasClass(DOMElement $node, string $class): bool
    {
        return str_contains(' '.$node->getAttribute('class').' ', ' '.$class.' ');
    }

    protected function closestElement(DOMElement $node, string $tagName): ?DOMElement
    {
        $tagName = mb_strtolower($tagName, 'UTF-8');

        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (mb_strtolower($parent->tagName, 'UTF-8') === $tagName) {
                return $parent;
            }
        }

        return null;
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

            $url = rtrim($categoryUrl, '/').'/filter/page='.$pageNumber.'/';
            $html = $this->fetch($url);
            if ($html === null) {
                continue;
            }

            $stats['source_pages_fetched']++;
            $pages[$url] = $this->page($html);
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
            if (preg_match('#/filter/page=(\d+)/#', $href, $matches) === 1) {
                $lastPage = max($lastPage, (int) $matches[1]);
            }
        }

        return $lastPage;
    }

    protected function productsFromCategoryPage(array $page, string $baseUrl, array $existingProductUrls = [], array &$stats = []): array
    {
        $products = [];

        foreach ($page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " catalogCard ")]') as $productNode) {
            if (! $productNode instanceof DOMElement) {
                continue;
            }

            $linkNode = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " catalogCard-title ")]//a[@href]', $productNode)->item(0)
                ?: $page['xpath']->query('.//a[contains(concat(" ", normalize-space(@class), " "), " catalogCard-image ")][@href]', $productNode)->item(0);

            if (! $linkNode instanceof DOMElement) {
                continue;
            }

            $sourceUrl = $this->absoluteUrl($linkNode->getAttribute('href'), $baseUrl);
            $name = $this->clean($linkNode->getAttribute('title') ?: $linkNode->textContent);
            if ($sourceUrl === null || $name === '') {
                continue;
            }
            $isExistingProduct = isset($existingProductUrls[$sourceUrl]);
            if ($isExistingProduct) {
                $stats['product_detail_pages_skipped'] = (int) ($stats['product_detail_pages_skipped'] ?? 0) + 1;
            }

            $priceNode = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " catalogCard-price ")]', $productNode)->item(0);
            $imageNode = $page['xpath']->query('.//img[@data-src or @src]', $productNode)->item(0);
            $imageUrl = $imageNode instanceof DOMElement
                ? $this->absoluteUrl($imageNode->getAttribute('data-src') ?: $imageNode->getAttribute('src'), $baseUrl)
                : null;
            $partNumber = $this->partNumberFromCard($productNode, $name);
            $localImagePath = $imageUrl !== null
                ? $this->downloadProductImage($partNumber ?: 'unknown', $imageUrl)
                : null;

            if ($localImagePath !== null) {
                $stats['product_images_saved'] = (int) ($stats['product_images_saved'] ?? 0) + 1;
            }

            $products[$sourceUrl] = [
                'source_url' => $sourceUrl,
                'part_number' => $partNumber,
                'name' => $name,
                'name_ru' => null,
                'name_ua' => $name,
                'price_amount' => $priceNode instanceof DOMElement ? $this->priceAmount($priceNode->textContent) : null,
                'currency' => 'UAH',
                'availability' => $this->availabilityFromCard($productNode),
                'image_url' => $localImagePath ?: $imageUrl,
                'image_urls' => $localImagePath !== null ? [$localImagePath] : array_values(array_filter([$imageUrl])),
                'remote_image_url' => $imageUrl,
                'remote_image_urls' => array_values(array_filter([$imageUrl])),
                'source_url_ru' => null,
                'source_url_ua' => $sourceUrl,
            ];
        }

        return array_values($products);
    }

    protected function productPayload(PartCatalogCategory $category, array $product): array
    {
        $payload = parent::productPayload($category, $product);
        $payload['raw_attributes'] = array_filter([
            ...($payload['raw_attributes'] ?? []),
            'image_urls' => $product['image_urls'] ?? null,
            'remote_image_url' => $product['remote_image_url'] ?? null,
            'remote_image_urls' => $product['remote_image_urls'] ?? null,
        ]);

        return $payload;
    }

    protected function localizedProductUrl(string $url, string $locale): ?string
    {
        return $locale === 'ua' || $locale === 'uk' ? $url : null;
    }

    protected function partNumberFromCard(DOMElement $productNode, string $name): ?string
    {
        $text = $this->clean($productNode->textContent);

        if (preg_match('/Артикул:\s*([A-ZА-Я0-9][A-ZА-Я0-9.,\/-]*)/iu', $text, $matches) === 1) {
            return $this->canonicalPartNumber($matches[1]);
        }

        return parent::partNumberFromCard($productNode, $name);
    }

    protected function availabilityFromCard(DOMElement $productNode): ?string
    {
        $text = mb_strtolower($this->clean($productNode->textContent), 'UTF-8');

        return match (true) {
            str_contains($text, 'немає в наявності') => 'out of stock',
            str_contains($text, 'купити') || str_contains($text, 'в наявності') => 'in stock',
            default => null,
        };
    }

    protected function priceAmount(?string $value): ?string
    {
        $value = preg_replace('/[^\d,.]/u', '', $this->clean($value));

        return parent::priceAmount($value);
    }

    protected function canonicalModel(string $label, string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return match (true) {
            $path === 'odyag-ta-vzuttya' => ['Model Y', 'Model Y', null, null],
            default => ['Model 3', 'Model 3', null, null],
        };
    }

    protected function productImportCategoryQuery()
    {
        return PartCatalogCategory::query()
            ->with('parent.parent')
            ->where('source', $this->source)
            ->where('depth', '>', 0)
            ->orderBy('id');
    }

    protected function modelSortOrder(string $label): int
    {
        return match ($label) {
            'Model 3' => 30,
            'Model Y' => 40,
            default => 999,
        };
    }

    protected function topRazborkaModelKey(string $name): ?string
    {
        return match (true) {
            str_starts_with($name, "\u{041C}3") || str_starts_with($name, 'M3') => '3',
            str_starts_with($name, "\u{041C}\u{0423}") || str_starts_with($name, 'MU') => 'y',
            default => null,
        };
    }

    protected function topRazborkaRootModelKey(string $name, string $url, string $baseUrl): ?string
    {
        return match (true) {
            $name === 'Model 3' || $url === $baseUrl.'/electronics/' => '3',
            $name === 'Model Y' || $url === $baseUrl.'/odyag-ta-vzuttya/' => 'y',
            default => null,
        };
    }

    protected function requestHeaders(array $extra = []): array
    {
        return array_merge([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept-Language' => 'uk-UA,uk;q=0.9,ru;q=0.8,en;q=0.7',
        ], $extra);
    }

    protected function challengeHash(string $html): ?string
    {
        if (preg_match('/defaultHash\s*=\s*"([^"]+)"/', $html, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected function downloadProductImage(string $partNumber, string $url): ?string
    {
        $path = $this->topRazborkaImagePath($partNumber, $url);
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
                ->withHeaders($this->requestHeaders())
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

    protected function topRazborkaImagePath(string $partNumber, string $url): ?string
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

        return 'toprazborka/part-images/'.$this->compactPartNumber($partNumber).'/'.$name.'-'.substr(sha1($url), 0, 10).'.'.$extension;
    }

    protected function compactPartNumber(string $partNumber): string
    {
        $compact = preg_replace('/[^A-Z0-9]/i', '', $partNumber) ?: 'UNKNOWN';

        return Str::upper($compact);
    }
}
