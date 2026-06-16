<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class TeslaHelpCatalogImporter
{
    protected string $source = 'teslahelp';

    protected array $teslaShopCache = [];

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://teslahelp.ru'), '/');
        $startUrl = (string) ($options['start_url'] ?? '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $rescan = (bool) ($options['rescan'] ?? false);
        $fresh = (bool) ($options['fresh'] ?? false);
        $withTeslaShop = (bool) ($options['with_teslashop'] ?? true);
        $maxCategories = max(0, (int) ($options['max_categories'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 250));

        $stats = [
            'source_pages_fetched' => 0,
            'category_links_found' => 0,
            'categories_seen' => 0,
            'categories_saved' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'teslashop_pages_fetched' => 0,
        ];

        if ($fresh && ! $dryRun) {
            PartCatalogItem::query()->where('source', $this->source)->delete();
            PartCatalogCategory::query()->where('source', $this->source)->delete();
        }

        $rootUrl = $this->absoluteUrl($startUrl, $baseUrl);
        if ($rootUrl === null) {
            return $stats;
        }

        $rootHtml = $this->fetch($rootUrl);
        if ($rootHtml === null) {
            return $stats;
        }

        $stats['source_pages_fetched']++;
        $rootPage = $this->page($rootHtml);
        $modelUrls = $this->modelLinks($rootPage, $baseUrl);
        $startUrls = trim((string) parse_url($rootUrl, PHP_URL_PATH), '/') === ''
            ? $modelUrls
            : [$rootUrl];

        $queue = collect($startUrls)
            ->map(fn (string $url): array => ['url' => $url, 'parent_id' => null])
            ->values()
            ->all();
        $queued = collect($queue)->pluck('url')->flip()->all();
        $seen = [];
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
            $this->progress($progress, $verbose, "TeslaHelp category #{$stats['categories_seen']}: {$categoryUrl}");

            $alreadyScanned = ! $rescan && PartCatalogCategory::query()
                ->where('source', $this->source)
                ->where('source_url', $categoryUrl)
                ->whereNotNull('products_scanned_at')
                ->exists();

            $categoryHtml = $categoryUrl === $rootUrl ? $rootHtml : $this->fetch($categoryUrl);
            if ($categoryHtml === null) {
                continue;
            }

            if ($categoryUrl !== $rootUrl) {
                $stats['source_pages_fetched']++;
            }

            $page = $this->page($categoryHtml);
            $parentCategory = $parentId !== null ? PartCatalogCategory::query()->find($parentId) : null;
            $categoryPayload = $this->categoryPayload($page, $categoryUrl, $parentCategory);
            $category = null;

            if ($categoryPayload !== null && ! $dryRun) {
                $categoryColumns = Arr::except($categoryPayload, ['catalog_path']);
                $category = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $categoryUrl],
                    $categoryColumns + ['parent_id' => $parentId]
                );
                $stats['categories_saved']++;
            }

            if ($this->isModelRootUrl($categoryUrl)) {
                foreach ($this->modelPageGroups($page, $baseUrl, $categoryUrl, $category, $categoryPayload, $dryRun) as $group) {
                    foreach (array_reverse($group['child_urls']) as $childUrl) {
                        if (isset($seen[$childUrl]) || isset($queued[$childUrl])) {
                            continue;
                        }

                        array_unshift($queue, ['url' => $childUrl, 'parent_id' => $group['category_id']]);
                        $queued[$childUrl] = true;
                        $stats['category_links_found']++;
                    }
                }
            } else {
                foreach (array_reverse($this->childCategoryLinks($page, $baseUrl, $categoryUrl)) as $childUrl) {
                    if (isset($seen[$childUrl]) || isset($queued[$childUrl])) {
                        continue;
                    }

                    array_unshift($queue, ['url' => $childUrl, 'parent_id' => $category?->id]);
                    $queued[$childUrl] = true;
                    $stats['category_links_found']++;
                }
            }

            $products = $alreadyScanned ? [] : $this->productsFromPage($page, $categoryUrl);
            foreach ($products as $product) {
                if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                    break 2;
                }

                $stats['products_found']++;
                $shopMeta = $withTeslaShop ? $this->teslaShopMeta($product['base_part_number'], $stats) : null;

                if (! $dryRun) {
                    PartCatalogItem::query()->updateOrCreate(
                        ['source_url' => $product['source_url']],
                        $this->productPayload($category, $categoryPayload, $product, $shopMeta)
                    );
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

    protected function productsFromPage(array $page, string $pageUrl): array
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
            $nameEn = $partIndex > 0 ? $cells[$partIndex - 1] : null;
            if ($nameEn === null || $nameEn === '') {
                continue;
            }

            $schemeNumber = $cells[0] !== '' && Str::lower($cells[0]) !== '#' ? $cells[0] : null;
            $quantity = $cells[$partIndex + 1] ?? null;
            $sourceUrl = 'teslahelp:'.md5(collect([$pageUrl, $schemeNumber, $partNumber, $nameEn])->filter()->implode('|'));

            $products[$sourceUrl] = [
                'source_url' => $sourceUrl,
                'page_url' => $pageUrl,
                'part_number' => Str::upper($partNumber),
                'base_part_number' => $this->basePartNumber($partNumber),
                'name_en' => Str::limit($nameEn, 255, ''),
                'scheme_number' => $schemeNumber,
                'quantity' => $quantity,
            ];
        }

        return array_values($products);
    }

    protected function teslaShopMeta(string $basePartNumber, array &$stats): ?array
    {
        if (isset($this->teslaShopCache[$basePartNumber])) {
            return $this->teslaShopCache[$basePartNumber];
        }

        $url = "https://teslashop.ru/auto-parts/mark_tesla?number={$basePartNumber}";
        $html = $this->fetch($url);
        if ($html === null) {
            return $this->teslaShopCache[$basePartNumber] = null;
        }

        $stats['teslashop_pages_fetched']++;
        $page = $this->page($html);
        $headline = $this->headline($page);
        $nameRu = null;
        if ($headline !== null && preg_match('/^\S+\s*-\s*(.+)$/u', $headline, $matches) === 1) {
            $nameRu = $this->clean($matches[1]);
        }

        $aliases = [];
        $body = $this->bodyText($page);
        if (preg_match('/Также называют\s*:\s*(.+?)(?:\s+Загрузка|\s+№\s|\z)/u', $body, $matches) === 1) {
            $aliases = collect(explode(',', $matches[1]))
                ->map(fn (string $alias): string => $this->clean($alias))
                ->filter()
                ->values()
                ->all();
        }

        return $this->teslaShopCache[$basePartNumber] = [
            'url' => $url,
            'name_ru' => $nameRu !== '' ? $nameRu : null,
            'aliases_ru' => $aliases,
        ];
    }

    protected function productPayload(?PartCatalogCategory $category, ?array $categoryPayload, array $product, ?array $shopMeta): array
    {
        $mainCategory = $category;
        while ($mainCategory?->parent && $mainCategory->parent->depth > 0) {
            $mainCategory = $mainCategory->parent;
        }

        $nameRu = $shopMeta['name_ru'] ?? null;
        $aliases = $shopMeta['aliases_ru'] ?? [];

        return [
            'part_catalog_category_id' => $category?->id,
            'source' => $this->source,
            'part_number' => $product['part_number'],
            'name' => $nameRu ?: $product['name_en'],
            'name_en' => $product['name_en'],
            'name_ru' => $nameRu,
            'scheme_number' => $this->smallIntegerOrNull($product['scheme_number']),
            'price_amount' => null,
            'currency' => null,
            'model_label' => $category?->model_label ?: ($categoryPayload['model_label'] ?? null),
            'model_name' => $category?->model_name ?: ($categoryPayload['model_name'] ?? null),
            'year_from' => $category?->year_from ?: ($categoryPayload['year_from'] ?? null),
            'year_to' => $category?->year_to ?: ($categoryPayload['year_to'] ?? null),
            'main_category_code' => $mainCategory?->code,
            'main_category_name' => $mainCategory?->name,
            'subcategory_code' => null,
            'subcategory_name' => null,
            'node_name' => $category?->name,
            'compatibility_text' => $category?->model_label ?: ($categoryPayload['model_label'] ?? null),
            'notes_en' => null,
            'notes_ru' => $aliases === [] ? null : implode(', ', $aliases),
            'availability' => null,
            'raw_attributes' => array_filter([
                'teslahelp_page_url' => $product['page_url'],
                'teslashop_url' => $shopMeta['url'] ?? null,
                'base_part_number' => $product['base_part_number'],
                'quantity' => $product['quantity'],
                'aliases_ru' => $aliases,
                'catalog_path' => $categoryPayload['catalog_path'] ?? null,
            ]),
            'source_updated_at' => now(),
        ];
    }

    protected function categoryPayload(array $page, string $url, ?PartCatalogCategory $parentCategory): ?array
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

        [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->modelFromCrumbs($crumbs);
        $depth = max(count($crumbs) - 1, 0);
        [$code, $nameWithoutCode] = $this->splitCodeName($current);

        return [
            'source' => $this->source,
            'source_url' => $url,
            'depth' => $parentCategory ? ((int) $parentCategory->depth) + 1 : $depth,
            'code' => $code,
            'name' => Str::limit($nameWithoutCode ?: $current, 255, ''),
            'name_ru' => Str::limit($nameWithoutCode ?: $current, 255, ''),
            'model_label' => $parentCategory?->model_label ?: $modelLabel,
            'model_name' => $parentCategory?->model_name ?: $modelName,
            'year_from' => $parentCategory?->year_from ?: $yearFrom,
            'year_to' => $parentCategory?->year_to ?: $yearTo,
            'sort_order' => $code === null ? 0 : (int) $code,
            'children_scanned_at' => now(),
            'catalog_path' => implode(' > ', $crumbs),
        ];
    }

    protected function childCategoryLinks(array $page, string $baseUrl, string $currentUrl): array
    {
        $links = $this->linksByQuery($page, '//div[contains(concat(" ", normalize-space(@class), " "), " teslacat__svganons ")]//a[@href]', $baseUrl);

        if ($links === []) {
            $links = $this->linksByQuery($page, '//a[contains(concat(" ", normalize-space(@class), " "), " teslacat__tree__item ")][@href]', $baseUrl);
        }

        if ($links === [] && count($this->breadcrumbs($page)) <= 1) {
            $links = collect($this->linksByQuery($page, '//a[@href]', $baseUrl))
                ->reject(fn (string $url): bool => $this->isModelRootUrl($url))
                ->values()
                ->all();
        }

        return collect($links)
            ->filter(fn (string $url): bool => $url !== $currentUrl && $this->isCatalogUrl($url, $baseUrl))
            ->unique()
            ->values()
            ->all();
    }

    protected function modelLinks(array $page, string $baseUrl): array
    {
        return collect($this->linksByQuery($page, '//a[@href]', $baseUrl))
            ->filter(fn (string $url): bool => $this->isModelRootUrl($url))
            ->unique()
            ->values()
            ->all();
    }

    protected function modelPageGroups(array $page, string $baseUrl, string $modelUrl, ?PartCatalogCategory $modelCategory, ?array $modelPayload, bool $dryRun): array
    {
        $groups = [];

        foreach ($page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " teslacat__anonsicon ")]') as $groupNode) {
            if (! $groupNode instanceof DOMElement) {
                continue;
            }

            $nameNode = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " teslacat__anonsicon__name ")]', $groupNode)->item(0);
            $name = $this->clean($nameNode?->textContent);
            if ($name === '') {
                continue;
            }

            [$code, $nameWithoutCode] = $this->splitCodeName($name);
            $imageNode = $page['xpath']->query('.//img[@src]', $groupNode)->item(0);
            $groupSourceUrl = $modelUrl.'#'.($groupNode->getAttribute('id') ?: md5($name));
            $groupCategory = null;

            if (! $dryRun && $modelCategory !== null) {
                $groupCategory = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $groupSourceUrl],
                    [
                        'parent_id' => $modelCategory->id,
                        'source' => $this->source,
                        'preview_image_url' => $imageNode instanceof DOMElement ? $this->absoluteUrl($imageNode->getAttribute('src'), $baseUrl) : null,
                        'depth' => 1,
                        'code' => $code,
                        'name' => Str::limit($nameWithoutCode ?: $name, 255, ''),
                        'name_ru' => Str::limit($nameWithoutCode ?: $name, 255, ''),
                        'model_label' => $modelCategory->model_label ?: ($modelPayload['model_label'] ?? null),
                        'model_name' => $modelCategory->model_name ?: ($modelPayload['model_name'] ?? null),
                        'year_from' => $modelCategory->year_from ?: ($modelPayload['year_from'] ?? null),
                        'year_to' => $modelCategory->year_to ?: ($modelPayload['year_to'] ?? null),
                        'sort_order' => $code === null ? 0 : (int) $code,
                        'children_scanned_at' => now(),
                    ]
                );
            }

            $childUrls = collect($this->linksByQuery($this->scopedPage($page, $groupNode), './/a[contains(concat(" ", normalize-space(@class), " "), " teslacat__anonsicon__subname ")][@href]', $baseUrl))
                ->filter(fn (string $url): bool => $this->isCatalogUrl($url, $baseUrl))
                ->unique()
                ->values()
                ->all();

            $groups[] = [
                'category_id' => $groupCategory?->id,
                'child_urls' => $childUrls,
            ];
        }

        return $groups;
    }

    protected function scopedPage(array $page, DOMElement $node): array
    {
        return [
            'document' => $page['document'],
            'xpath' => new class($page['xpath'], $node)
            {
                public function __construct(
                    protected DOMXPath $xpath,
                    protected DOMElement $node,
                ) {}

                public function query(string $expression): mixed
                {
                    return $this->xpath->query($expression, $this->node);
                }
            },
        ];
    }

    protected function isModelRootUrl(string $url): bool
    {
        return preg_match('#/catalog/(model-[3xy]-parts-catalog|us-model-s-parts-catalog|model-s-parts-catalog481)/?$#i', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    protected function linksByQuery(array $page, string $query, string $baseUrl): array
    {
        $links = [];

        foreach ($page['xpath']->query($query) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($node->getAttribute('href'), $baseUrl);
            if ($url !== null) {
                $links[$url] = $url;
            }
        }

        return array_values($links);
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = $this->http
                ->timeout(120)
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

    protected function breadcrumbs(array $page): array
    {
        $crumbs = [];

        foreach ($page['xpath']->query('//ol[contains(concat(" ", normalize-space(@class), " "), " breadcrumb ")]//a | //ol[contains(concat(" ", normalize-space(@class), " "), " breadcrumb ")]//span') as $node) {
            $text = $this->clean($node->textContent);
            $lower = Str::lower($text);

            if ($text === '' || in_array($lower, ['каталог запчастей'], true)) {
                continue;
            }

            if (! in_array($text, $crumbs, true)) {
                $crumbs[] = $text;
            }
        }

        return $crumbs;
    }

    protected function modelFromCrumbs(array $crumbs): array
    {
        $model = collect($crumbs)->first(fn (string $crumb): bool => preg_match('/model\s*[s3xy]/iu', $crumb) === 1);

        return match (true) {
            str_contains(Str::lower((string) $model), 'model s') && str_contains(Str::lower((string) $model), '2012') => [$model, 'Model S', 2012, 2016],
            str_contains(Str::lower((string) $model), 'model s') && str_contains(Str::lower((string) $model), '2016') => [$model, 'Model S', 2016, 2021],
            str_contains(Str::lower((string) $model), 'model s') => [$model, 'Model S', 2012, 2016],
            str_contains(Str::lower((string) $model), 'model 3') => [$model, 'Model 3', 2017, 2023],
            str_contains(Str::lower((string) $model), 'model x') => [$model, 'Model X', 2015, 2021],
            str_contains(Str::lower((string) $model), 'model y') => [$model, 'Model Y', 2020, 2025],
            default => [$model, $model, null, null],
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

    protected function basePartNumber(string $partNumber): string
    {
        if (preg_match('/^([A-Z0-9]+)/iu', $partNumber, $matches) === 1) {
            return Str::upper($matches[1]);
        }

        return Str::upper($partNumber);
    }

    protected function isCatalogUrl(string $url, string $baseUrl): bool
    {
        if (parse_url($url, PHP_URL_HOST) !== parse_url($baseUrl, PHP_URL_HOST)) {
            return false;
        }

        return str_starts_with(trim((string) parse_url($url, PHP_URL_PATH), '/'), 'catalog/');
    }

    protected function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || $url === '#' || str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $this->canonicalUrl($url);
        }

        return $this->canonicalUrl($baseUrl.'/'.ltrim($url, '/'));
    }

    protected function canonicalUrl(string $url): string
    {
        $parts = parse_url(strtok($url, '#') ?: $url);
        $path = (string) ($parts['path'] ?? '/');

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/').'/';
        }

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'teslahelp.ru').$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
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
        $title = preg_replace('/\s*[|–-]\s*TeslaHelp.*$/iu', '', $title) ?? $title;

        return $title === '' ? null : $title;
    }

    protected function bodyText(array $page): string
    {
        return $this->clean($page['document']->textContent);
    }

    protected function clean(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    protected function smallIntegerOrNull(?string $value): ?int
    {
        $value = $this->clean($value);

        return ctype_digit($value) && (int) $value <= 65535 ? (int) $value : null;
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($verbose && $progress !== null) {
            $progress($message);
        }
    }
}
