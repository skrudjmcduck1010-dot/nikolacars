<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TeslaCompanyCatalogImporter
{
    protected string $source = 'teslacompany';

    protected const BASE_URL = 'https://teslacompany.com.ua';

    protected const MODEL_CATEGORIES = [
        ['label' => 'Tesla Model 3', 'url' => 'https://teslacompany.com.ua/category/tesla-model-3-552761/', 'sort' => 10],
        ['label' => 'Tesla Model 3 highland', 'url' => 'https://teslacompany.com.ua/category/tesla-model-3-highland/', 'sort' => 20],
        ['label' => 'Tesla Model S', 'url' => 'https://teslacompany.com.ua/category/tesla-model-s-552783/', 'sort' => 30],
        ['label' => 'Tesla Model S Plaid', 'url' => 'https://teslacompany.com.ua/category/tesla-model-s-plaid-552805/', 'sort' => 40],
        ['label' => 'Tesla Model S Restyle', 'url' => 'https://teslacompany.com.ua/category/tesla-model-s-restyle/', 'sort' => 50],
        ['label' => 'Tesla Model X', 'url' => 'https://teslacompany.com.ua/category/tesla-model-x-552816/', 'sort' => 60],
        ['label' => 'Tesla Model X Plaid', 'url' => 'https://teslacompany.com.ua/category/tesla-model-x-plaid-552827/', 'sort' => 70],
        ['label' => 'Tesla Model Y', 'url' => 'https://teslacompany.com.ua/category/tesla-model-y-552772/', 'sort' => 80],
    ];

    public function refreshModelListings(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $fresh = (bool) ($options['fresh'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 250));
        $maxPages = max(0, (int) ($options['max_pages'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));

        $stats = [
            'pages_scanned' => 0,
            'listing_products_seen' => 0,
            'detail_pages_fetched' => 0,
            'products_existing_skipped' => 0,
            'products_saved' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'products_deleted' => 0,
            'categories_deleted' => 0,
            'category_products_deleted' => 0,
            'rows_skipped' => 0,
            'categories_saved' => 0,
        ];

        if ($fresh && ! $dryRun) {
            $stats['products_deleted'] = PartCatalogItem::query()->where('source', $this->source)->delete();
            $stats['categories_deleted'] = PartCatalogCategory::query()->where('source', $this->source)->delete();
        } elseif (! $dryRun) {
            $stats['category_products_deleted'] = PartCatalogItem::query()
                ->where('source', $this->source)
                ->where('source_url', 'like', rtrim(self::BASE_URL, '/').'/category/%')
                ->delete();
        }

        foreach (self::MODEL_CATEGORIES as $model) {
            $modelLabel = $model['label'];
            $modelUrl = $model['url'];
            $pageUrls = [$modelUrl];
            $seenPageUrls = [];
            $modelCategory = null;

            if (! $dryRun) {
                $modelCategory = $this->modelCategory($modelLabel, $this->modelName($modelLabel), $modelUrl, (int) $model['sort']);
                $stats['categories_saved'] += $modelCategory->wasRecentlyCreated ? 1 : 0;
            }

            while ($pageUrls !== []) {
                if ($maxPages > 0 && $stats['pages_scanned'] >= $maxPages) {
                    return $stats;
                }

                $pageUrl = array_shift($pageUrls);
                $pageUrl = $this->absoluteUrl($this->clean($pageUrl));

                if ($pageUrl === '' || isset($seenPageUrls[$pageUrl])) {
                    continue;
                }

                $seenPageUrls[$pageUrl] = true;

                $stats['pages_scanned']++;
                $this->progress($progress, $verbose, 'TeslaCompany download: fetching: '.$stats['pages_scanned'].': '.$pageUrl);

                $html = $this->fetchHtml($pageUrl);
                if ($html === null) {
                    $stats['rows_skipped']++;
                    break;
                }

                $page = $this->domXPath($html);
                $listingRows = $this->parseListingRows($page, $pageUrl, $modelLabel, $modelUrl);
                $stats['listing_products_seen'] += count($listingRows);
                $this->progress($progress, $verbose, 'TeslaCompany download: '.$stats['pages_scanned'].': '.$pageUrl.' - '.count($listingRows).' items');

                foreach ($this->parseCategoryLinks($page) as $categoryUrl) {
                    if (! isset($seenPageUrls[$categoryUrl]) && ! in_array($categoryUrl, $pageUrls, true)) {
                        $pageUrls[] = $categoryUrl;
                    }
                }

                foreach ($listingRows as $row) {
                    if ($maxProducts > 0 && $maxProducts <= $stats['products_saved'] + $stats['products_existing_skipped']) {
                        return $stats;
                    }

                    $sourceUrl = $this->clean($row['url'] ?? '');
                    if ($sourceUrl === '' || ! $this->isProductUrl($sourceUrl)) {
                        $stats['rows_skipped']++;

                        continue;
                    }

                    $existingItem = PartCatalogItem::query()
                        ->where('source', $this->source)
                        ->where('source_url', $sourceUrl)
                        ->first(['id', 'price_amount', 'currency', 'raw_attributes']);

                    if ($existingItem instanceof PartCatalogItem) {
                        if (! $dryRun && $this->updateExistingProductPrice($existingItem, $row)) {
                            $stats['products_updated']++;
                            $this->progress($progress, $verbose, 'updated: '.$this->clean($row['name_ru'] ?? $sourceUrl));
                            $this->progress($progress, $verbose, 'TeslaCompany price updated: '.$sourceUrl);
                        } else {
                            $stats['products_existing_skipped']++;
                            $this->progress($progress, $verbose, 'TeslaCompany skipped existing: '.$sourceUrl);
                        }

                        continue;
                    }

                    $detailHtml = $this->fetchHtml($sourceUrl);
                    if ($detailHtml === null) {
                        $stats['rows_skipped']++;

                        continue;
                    }

                    $stats['detail_pages_fetched']++;
                    $row = array_merge($row, $this->parseDetailRow($this->domXPath($detailHtml), $sourceUrl));
                    $name = $this->clean($row['detail_name_ru'] ?? '') ?: $this->clean($row['name_ru'] ?? '');
                    if ($name === '') {
                        $stats['rows_skipped']++;

                        continue;
                    }

                    if (! $dryRun) {
                        $modelCategory ??= $this->modelCategory($modelLabel, $this->modelName($modelLabel), $modelUrl, (int) $model['sort']);
                        $category = $this->productCategory($modelCategory, $row);
                        $stats['categories_saved'] += $category->wasRecentlyCreated ? 1 : 0;

                        $item = PartCatalogItem::query()->updateOrCreate(
                            ['source_url' => $sourceUrl],
                            $this->productPayload($category, $row, $name, $modelLabel, $this->modelName($modelLabel))
                        );

                        if ($item->wasRecentlyCreated) {
                            $stats['products_created']++;
                            $this->progress($progress, $verbose, 'created: '.$name);
                        } elseif ($item->wasChanged()) {
                            $stats['products_updated']++;
                            $this->progress($progress, $verbose, 'updated: '.$name);
                        }
                    }

                    $stats['products_saved']++;
                    $this->progress($progress, $verbose, 'TeslaCompany product #'.$stats['products_saved'].': '.$name);

                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }

                $nextPageUrl = $this->clean($page->evaluate('string(//link[@rel="next"]/@href)'));
                $nextPageUrl = $nextPageUrl !== '' ? $this->absoluteUrl($nextPageUrl) : '';

                if ($nextPageUrl !== '' && ! isset($seenPageUrls[$nextPageUrl]) && ! in_array($nextPageUrl, $pageUrls, true)) {
                    $pageUrls[] = $nextPageUrl;
                }

                if ($pageUrls !== [] && $sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        return $stats;
    }

    public function import(string $path, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $fresh = (bool) ($options['fresh'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'rows_read' => 0,
            'rows_skipped' => 0,
            'categories_saved' => 0,
            'products_saved' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'products_deleted' => 0,
            'products_existing_skipped' => 0,
        ];

        if (! is_file($path)) {
            return $stats + ['error' => "File not found: {$path}"];
        }

        if ($fresh && ! $dryRun) {
            $stats['products_deleted'] = PartCatalogItem::query()->where('source', $this->source)->delete();
        }

        foreach ($this->rows($path) as $row) {
            $stats['rows_read']++;

            $sourceUrl = $this->clean($row['url'] ?? '');
            $name = $this->clean($row['detail_name_ru'] ?? '') ?: $this->clean($row['name_ru'] ?? '');
            if ($sourceUrl === '' || $name === '') {
                $stats['rows_skipped']++;

                continue;
            }

            if ($this->isExistingDetailSkippedRow($row)
                && PartCatalogItem::query()->where('source', $this->source)->where('source_url', $sourceUrl)->exists()) {
                $stats['products_existing_skipped']++;
                $this->progress($progress, $verbose, "TeslaCompany {$stats['rows_read']}: skipped existing {$name}");

                continue;
            }

            $modelLabel = $this->modelLabel($row);
            $modelName = $this->modelName($modelLabel);
            $modelCategory = null;
            $category = null;

            if (! $dryRun) {
                $modelCategory = $this->modelCategory($modelLabel, $modelName, $this->clean($row['model_source_url'] ?? ''));
                $category = $this->productCategory($modelCategory, $row);
                $stats['categories_saved'] += $category->wasRecentlyCreated ? 2 : 0;

                $item = PartCatalogItem::query()->updateOrCreate(
                    ['source_url' => $sourceUrl],
                    $this->productPayload($category, $row, $name, $modelLabel, $modelName)
                );
                $stats['products_saved']++;

                if ($item->wasRecentlyCreated) {
                    $stats['products_created']++;
                } elseif ($item->wasChanged()) {
                    $stats['products_updated']++;
                }
            }

            $this->progress($progress, $verbose, "TeslaCompany {$stats['rows_read']}: {$name}");
        }

        return $stats;
    }

    protected function rows(string $path): iterable
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);

            return;
        }

        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        }

        while (($values = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[(string) $header] = $values[$index] ?? '';
            }

            yield $row;
        }

        fclose($handle);
    }

    protected function modelCategory(string $modelLabel, string $modelName, string $sourceUrl = '', ?int $sortOrder = null): PartCatalogCategory
    {
        $sourceUrl = $sourceUrl !== '' ? $sourceUrl : 'https://teslacompany.com.ua/catalog/'.Str::slug($modelLabel);

        return PartCatalogCategory::query()->updateOrCreate(
            ['source_url' => $sourceUrl],
            [
                'source' => $this->source,
                'parent_id' => null,
                'depth' => 0,
                'code' => null,
                'name' => $modelLabel,
                'name_ru' => $modelLabel,
                'model_label' => $modelLabel,
                'model_name' => $modelName,
                'sort_order' => $sortOrder ?? $this->modelSortOrder($modelLabel),
                'children_scanned_at' => now(),
                'products_scanned_at' => now(),
            ]
        );
    }

    protected function productCategory(PartCatalogCategory $modelCategory, array $row): PartCatalogCategory
    {
        $segments = $this->categorySegments($row, (string) $modelCategory->model_label);
        $parent = $modelCategory;

        foreach ($segments as $index => $name) {
            $parent = PartCatalogCategory::query()->updateOrCreate(
                ['source_url' => rtrim((string) $parent->source_url, '/').'/'.Str::slug($name)],
                [
                    'source' => $this->source,
                    'parent_id' => $parent->id,
                    'depth' => $index + 1,
                    'code' => null,
                    'name' => $name,
                    'name_ru' => $name,
                    'model_label' => $modelCategory->model_label,
                    'model_name' => $modelCategory->model_name,
                    'sort_order' => 10 + $index,
                    'children_scanned_at' => now(),
                    'products_scanned_at' => now(),
                ]
            );
        }

        return $parent;
    }

    protected function productPayload(
        PartCatalogCategory $category,
        array $row,
        string $name,
        string $modelLabel,
        string $modelName
    ): array {
        $priceAmount = $this->priceAmountUsd($row);
        $publicationDate = $this->publicationDate($row);
        $rawAttributes = $this->rawAttributes($row);
        $sourceUrl = $this->clean($row['url'] ?? '');
        $isUkrainian = $this->isUkrainianUrl($sourceUrl);
        $description = $this->clean($row['description'] ?? '') ?: null;
        $segments = $this->categorySegments($row, $modelLabel);

        return [
            'part_catalog_category_id' => $category->id,
            'source' => $this->source,
            'part_number' => $this->clean($row['detail_part_number'] ?? '') ?: $this->clean($row['part_number'] ?? '') ?: null,
            'name' => $name,
            'name_ru' => $isUkrainian ? null : $name,
            'name_ua' => $isUkrainian ? $name : null,
            'price_amount' => $priceAmount,
            'currency' => $priceAmount !== null ? 'USD' : null,
            'model_label' => $modelLabel,
            'model_name' => $modelName,
            'main_category_name' => $segments[0] ?? $category->name,
            'subcategory_name' => $segments[1] ?? null,
            'node_name' => $segments !== [] ? $segments[count($segments) - 1] : $category->name,
            'compatibility_text' => $this->clean($row['make_model'] ?? '') ?: null,
            'notes_ru' => $isUkrainian ? null : $description,
            'notes_ua' => $isUkrainian ? $description : null,
            'condition' => $this->clean($row['condition'] ?? '') ?: null,
            'quality' => $this->clean($row['manufacturer'] ?? '') ?: null,
            'availability' => $this->clean($row['availability'] ?? '') ?: null,
            'raw_attributes' => $rawAttributes,
            'source_updated_at' => $publicationDate,
        ];
    }

    protected function isExistingDetailSkippedRow(array $row): bool
    {
        return $this->clean($row['detail_skipped_existing'] ?? '') === '1'
            && $this->clean($row['detail_name_ru'] ?? '') === ''
            && $this->clean($row['detail_error'] ?? '') === '';
    }

    protected function needsDetailRefresh(PartCatalogItem $item): bool
    {
        $raw = PartCatalogRawAttributes::from($item);

        return ! is_array($raw['category_path_items'] ?? null) || count($raw['category_path_items']) < 3;
    }

    protected function isUkrainianUrl(string $url): bool
    {
        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/ua/');
    }

    protected function rawAttributes(array $row): array
    {
        $raw = [
            'goods_id' => $this->clean($row['detail_goods_id'] ?? '') ?: $this->clean($row['goods_id'] ?? ''),
            'image_url' => $this->nonPlaceholderImageUrl($this->clean($row['image_url'] ?? '')),
            'image_urls' => $this->nonPlaceholderImageUrls($this->jsonArray($row['detail_image_urls'] ?? '')),
            'quantity' => $this->clean($row['quantity'] ?? ''),
            'price_text' => $this->clean($row['detail_price'] ?? '') ?: $this->clean($row['price_text'] ?? ''),
            'button_text' => $this->clean($row['detail_button_text'] ?? '') ?: $this->clean($row['button_text'] ?? ''),
            'category' => $this->clean($row['category'] ?? ''),
            'category_path' => $this->clean($row['category_path'] ?? ''),
            'category_path_items' => $this->jsonArray($row['category_path_json'] ?? ''),
            'model_source_url' => $this->clean($row['model_source_url'] ?? ''),
            'manufacturer' => $this->clean($row['manufacturer'] ?? ''),
            'publication_date' => $this->clean($row['publication_date'] ?? ''),
            'characteristics' => $this->jsonObject($row['characteristics_json'] ?? ''),
            'info' => $this->jsonObject($row['detail_info_json'] ?? ''),
            'source_row' => collect($row)->map(fn ($value) => is_string($value) ? $this->clean($value) : $value)->all(),
        ];

        return array_filter($raw, fn ($value): bool => $value !== '' && $value !== [] && $value !== null);
    }

    protected function priceAmountUsd(array $row): ?float
    {
        $text = $this->clean($row['detail_price'] ?? '') ?: $this->clean($row['price_text'] ?? '');
        if ($text === '') {
            return null;
        }

        if (preg_match('/\$([0-9\s.,]+)/u', $text, $matches) === 1) {
            $amount = str_replace([' ', ','], ['', '.'], $matches[1]);

            return is_numeric($amount) ? round((float) $amount, 2) : null;
        }

        return null;
    }

    protected function updateExistingProductPrice(PartCatalogItem $item, array $row): bool
    {
        $priceAmount = $this->priceAmountUsd($row);
        if ($priceAmount === null) {
            return false;
        }

        if ((float) $item->price_amount === $priceAmount && (string) $item->currency === 'USD') {
            return false;
        }

        $item->forceFill([
            'price_amount' => $priceAmount,
            'currency' => 'USD',
        ])->save();

        return true;
    }

    protected function publicationDate(array $row): ?Carbon
    {
        $value = $this->clean($row['publication_date'] ?? '');
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d.m.Y', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function modelLabel(array $row): string
    {
        $explicit = $this->clean($row['model_label'] ?? '');
        if ($explicit !== '') {
            return $explicit;
        }

        $text = Str::lower($this->clean(($row['make_model'] ?? '').' '.($row['name_ru'] ?? '')));

        return match (true) {
            str_contains($text, 'model 3') && str_contains($text, 'highland') => 'Tesla Model 3 highland',
            str_contains($text, 'model 3') => 'Tesla Model 3',
            str_contains($text, 'model y') => 'Tesla Model Y',
            str_contains($text, 'model x') && str_contains($text, 'plaid') => 'Tesla Model X Plaid',
            str_contains($text, 'model x') => 'Tesla Model X',
            str_contains($text, 'model s') && str_contains($text, 'plaid') => 'Tesla Model S Plaid',
            str_contains($text, 'model s') && (str_contains($text, 'restyl') || str_contains($text, '2016-2021')) => 'Tesla Model S Restyle',
            str_contains($text, 'model s') => 'Tesla Model S',
            default => 'Tesla Model 3',
        };
    }

    protected function modelName(string $modelLabel): string
    {
        $modelLabel = Str::lower($modelLabel);

        return match (true) {
            str_contains($modelLabel, 'model s') => 'Model S',
            str_contains($modelLabel, 'model x') => 'Model X',
            str_contains($modelLabel, 'model y') => 'Model Y',
            default => 'Model 3',
        };
    }

    protected function categoryFromPath(array $row): string
    {
        $path = $this->jsonArray($row['category_path_json'] ?? '');
        if (count($path) >= 2) {
            return (string) $path[count($path) - 2];
        }

        return '';
    }

    protected function categorySegments(array $row, string $modelLabel): array
    {
        $path = $this->jsonArray($row['category_path_json'] ?? '');

        if ($path === [] && $this->clean($row['category_path'] ?? '') !== '') {
            $path = array_map(fn (string $value): string => $this->clean($value), explode('>', (string) $row['category_path']));
        }

        if ($path !== []) {
            $productName = $this->clean($row['detail_name_ru'] ?? '') ?: $this->clean($row['name_ru'] ?? '');

            if ($this->sameText($path[0] ?? '', $modelLabel)) {
                array_shift($path);
            }

            if ($path !== [] && $this->sameText($path[count($path) - 1], $productName)) {
                array_pop($path);
            }

            if (count($path) === 1 && $this->sameText($path[0], $productName)) {
                $path = [];
            }
        }

        if ($path === []) {
            $category = $this->clean($row['category'] ?? '') ?: $this->categoryFromPath($row);
            $path = [$category !== '' ? $category : 'TeslaCompany'];
        }

        return collect($path)
            ->map(fn (string $value): string => $this->clean($value))
            ->filter()
            ->values()
            ->all();
    }

    protected function sameText(string $left, string $right): bool
    {
        return Str::lower($this->clean($left)) === Str::lower($this->clean($right));
    }

    protected function modelSortOrder(string $modelLabel): int
    {
        return match ($modelLabel) {
            'Tesla Model 3' => 10,
            'Tesla Model 3 highland' => 20,
            'Tesla Model S' => 30,
            'Tesla Model S Plaid' => 40,
            'Tesla Model S Restyle' => 50,
            'Tesla Model X' => 60,
            'Tesla Model X Plaid' => 70,
            'Tesla Model Y' => 80,
            default => 999,
        };
    }

    protected function jsonArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    protected function jsonObject(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function clean(?string $value): string
    {
        return trim(html_entity_decode(preg_replace('/\s+/u', ' ', (string) $value) ?: '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    protected function fetchHtml(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'header' => implode("\r\n", [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept-Language: ru,uk;q=0.8,en;q=0.6',
                ]),
            ],
        ]);

        $html = @file_get_contents($url, false, $context);

        return is_string($html) && $html !== '' ? $html : null;
    }

    protected function domXPath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

    protected function parseListingRows(\DOMXPath $page, string $pageUrl, string $modelLabel, string $modelUrl): array
    {
        $rows = [];
        foreach ($page->query('//div[contains(concat(" ", normalize-space(@class), " "), " avg-item ")]') ?: [] as $item) {
            $itemPath = new \DOMXPath($item->ownerDocument);
            $titleAnchor = $itemPath->query('.//div[contains(@class, "title")]//a[1]', $item)->item(0);
            $title = $titleAnchor instanceof \DOMNode ? $this->clean($itemPath->evaluate('string(.//span[@itemprop="name"])', $titleAnchor) ?: $titleAnchor->textContent) : '';
            $productUrl = $titleAnchor instanceof \DOMElement ? $this->absoluteUrl($this->clean($titleAnchor->getAttribute('href'))) : '';

            if (($title === '' && $productUrl === '') || ! $this->isProductUrl($productUrl)) {
                continue;
            }

            $rows[] = [
                'page_url' => $pageUrl,
                'model_label' => $modelLabel,
                'model_source_url' => $modelUrl,
                'goods_id' => $this->clean($itemPath->evaluate('string(.//button[@data-goods-id][1]/@data-goods-id)', $item)),
                'part_number' => $this->clean($itemPath->evaluate('string(.//div[contains(@class, "article")]//b[1])', $item)),
                'name_ru' => $title,
                'price_text' => $this->clean($itemPath->evaluate('string(.//div[contains(@class, "price-cont")])', $item)),
                'button_text' => $this->clean($itemPath->evaluate('string(.//div[contains(@class, "to-cart")])', $item)),
                'url' => $productUrl,
                'image_url' => $this->nonPlaceholderImageUrl($this->absoluteUrl($this->clean($itemPath->evaluate('string(.//img[@itemprop="image"][1]/@src)', $item)))),
            ];
        }

        return $rows;
    }

    protected function parseCategoryLinks(\DOMXPath $page): array
    {
        $urls = [];

        foreach ($page->query('//div[contains(concat(" ", normalize-space(@class), " "), " a-vizit-category-list ")]//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof \DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($this->clean($anchor->getAttribute('href')));
            if ($this->isCategoryUrl($url) && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    protected function parseDetailRow(\DOMXPath $page, string $sourceUrl): array
    {
        $container = $page->query('//div[contains(concat(" ", normalize-space(@class), " "), " goods-view-content ")]')->item(0);
        $context = $container instanceof \DOMNode ? $container : null;
        $info = $this->detailInfoFields($page, $context);
        $properties = $this->detailPropertyFields($page, $context);
        $categoryPath = $this->detailCategoryPath($page);
        $images = $this->detailImages($page, $context);

        return [
            'detail_final_url' => '',
            'detail_goods_id' => $this->clean($page->evaluate('string(.//button[@data-goods-id][1]/@data-goods-id)', $context)),
            'detail_part_number' => $this->clean($page->evaluate('string(.//div[contains(@class, "goods-title")]//div[contains(@class, "article")]//b[1])', $context)),
            'detail_name_ru' => $this->clean($page->evaluate('string(.//div[contains(@class, "goods-title")]//h1[@itemprop="name"][1])', $context)),
            'availability' => $info['Наличие'] ?? '',
            'condition' => $info['Состояние'] ?? '',
            'make_model' => $info['Марка/Модель'] ?? '',
            'category' => count($categoryPath) >= 2 ? (string) $categoryPath[count($categoryPath) - 2] : '',
            'category_path' => implode(' > ', $categoryPath),
            'category_path_json' => json_encode($categoryPath, JSON_UNESCAPED_UNICODE),
            'quantity' => $info['Количество'] ?? '',
            'detail_price' => $info['Цена'] ?? '',
            'publication_date' => $properties['Дата публикации'] ?? '',
            'manufacturer' => $properties['Производитель'] ?? '',
            'availability' => $this->fieldValue($info, ['availability']),
            'condition' => $this->fieldValue($info, ['condition']),
            'make_model' => $this->fieldValue($info, ['make_model']),
            'quantity' => $this->fieldValue($info, ['quantity']),
            'detail_price' => $this->fieldValue($info, ['price']),
            'publication_date' => $this->fieldValue($properties, ['publication_date']),
            'manufacturer' => $this->fieldValue($properties, ['manufacturer']),
            'description' => $this->clean($page->evaluate('string(.//div[@id="gt-description"])', $context)),
            'detail_button_text' => $this->clean($page->evaluate('string(.//button[contains(@class, "cart-button")][1])', $context)),
            'detail_image_urls' => json_encode($images, JSON_UNESCAPED_UNICODE),
            'detail_info_json' => json_encode($info, JSON_UNESCAPED_UNICODE),
            'characteristics_json' => json_encode($properties, JSON_UNESCAPED_UNICODE),
            'detail_error' => '',
            'source_url' => $sourceUrl,
        ];
    }

    protected function detailInfoFields(\DOMXPath $page, ?\DOMNode $context): array
    {
        $fields = [];
        foreach ($page->query('.//div[contains(concat(" ", normalize-space(@class), " "), " inf-block ")]', $context) ?: [] as $block) {
            $key = rtrim($this->clean($page->evaluate('string(.//div[contains(@class, "i-key")][1])', $block)), ':');
            $value = $this->clean($page->evaluate('string(.//div[contains(@class, "i-val")][1])', $block));
            if ($key !== '' && $value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    protected function fieldValue(array $fields, array $names): string
    {
        $labels = [
            'availability' => ['Наличие', 'Наявність'],
            'condition' => ['Состояние', 'Стан'],
            'make_model' => ['Марка/Модель'],
            'quantity' => ['Количество', 'Кількість'],
            'price' => ['Цена', 'Ціна'],
            'publication_date' => ['Дата публикации', 'Дата публікації'],
            'manufacturer' => ['Производитель', 'Виробник'],
        ];

        $expected = collect($names)
            ->flatMap(fn (string $name): array => $labels[$name] ?? [$name])
            ->flatMap(fn (string $label): array => [$label, $this->mojibake($label)])
            ->map(fn (string $label): string => $this->fieldKey($label))
            ->all();

        foreach ($fields as $key => $value) {
            if (in_array($this->fieldKey((string) $key), $expected, true)) {
                return $this->clean((string) $value);
            }
        }

        return '';
    }

    protected function fieldKey(string $value): string
    {
        return Str::lower(preg_replace('/[^\pL\pN]+/u', '', $this->clean($value)) ?: '');
    }

    protected function mojibake(string $value): string
    {
        if (! function_exists('mb_convert_encoding')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1251');
    }

    protected function detailPropertyFields(\DOMXPath $page, ?\DOMNode $context): array
    {
        $fields = [];
        foreach ($page->query('.//div[@id="gt-properties"]//li', $context) ?: [] as $item) {
            $key = rtrim($this->clean($page->evaluate('string(.//span[contains(@class, "name")][1])', $item)), ':');
            $value = $this->clean($page->evaluate('string(.//span[contains(@class, "value")][1])', $item));
            if ($key !== '' && $value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    protected function detailImages(\DOMXPath $page, ?\DOMNode $context): array
    {
        $urls = [];
        foreach ($page->query('.//div[contains(@class, "images")]//a[@href]', $context) ?: [] as $anchor) {
            if (! $anchor instanceof \DOMElement) {
                continue;
            }

            $url = $this->nonPlaceholderImageUrl($this->absoluteUrl($this->clean($anchor->getAttribute('href'))));
            if ($url !== '' && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    protected function detailCategoryPath(\DOMXPath $page): array
    {
        $items = [];
        foreach ($page->query('//div[contains(concat(" ", normalize-space(@class), " "), " bread-crumbs ")]/*[self::a or self::b]') ?: [] as $node) {
            $href = $node instanceof \DOMElement ? $this->absoluteUrl($this->clean($node->getAttribute('href'))) : '';
            $path = (string) parse_url($href, PHP_URL_PATH);
            if ($href !== '' && in_array(rtrim($path, '/'), ['', '/goods'], true)) {
                continue;
            }

            $value = $this->clean($node->textContent);
            if ($value !== '' && ! in_array($value, ['Главная', 'Каталог'], true)) {
                $items[] = $value;
            }
        }

        return $items;
    }

    protected function absoluteUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return rtrim(self::BASE_URL, '/').'/'.ltrim($url, '/');
    }

    protected function isProductUrl(string $url): bool
    {
        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/goods/');
    }

    protected function isCategoryUrl(string $url): bool
    {
        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/category/');
    }

    protected function nonPlaceholderImageUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $query = strtolower((string) parse_url($url, PHP_URL_QUERY));

        return str_contains($path, '/cap.')
            || str_contains($path, 'cap.jpg')
            || str_contains($query, 'cap.jpg')
            ? ''
            : $url;
    }

    protected function nonPlaceholderImageUrls(array $urls): array
    {
        return collect($urls)
            ->map(fn (string $url): string => $this->nonPlaceholderImageUrl($url))
            ->filter()
            ->values()
            ->all();
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
    }
}
