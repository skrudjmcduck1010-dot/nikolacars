<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Support\CatalogTextEncoding;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

class TeslaWestPartsCatalogImporter
{
    protected string $source = 'teslawestparts';

    protected const MODEL_CATEGORIES = [
        ['id' => 241, 'label' => 'Model S', 'name' => 'Model S', 'url' => 'https://teslawestparts.com.ua/category/zapchasti-tesla-model-s/'],
        ['id' => 237, 'label' => 'Model X', 'name' => 'Model X', 'url' => 'https://teslawestparts.com.ua/category/zapchasti-tesla-model-x/'],
        ['id' => 236, 'label' => 'Model 3', 'name' => 'Model 3', 'url' => 'https://teslawestparts.com.ua/category/zapchasti-tesla-model-3/'],
        ['id' => 235, 'label' => 'Model Y', 'name' => 'Model Y', 'url' => 'https://teslawestparts.com.ua/category/zapchasti-tesla-model-y/'],
    ];

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://teslawestparts.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxPages = max(0, (int) ($options['max_pages'] ?? 0));
        $maxProducts = max(0, (int) ($options['max_products'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $fresh = (bool) ($options['fresh'] ?? false);

        $stats = [
            'source_pages_fetched' => 0,
            'products_found' => 0,
            'products_saved' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'products_merged_by_part_number' => 0,
            'models_saved' => 0,
            'categories_saved' => 0,
            'products_skipped' => 0,
        ];

        if ($fresh && ! $dryRun) {
            PartCatalogItem::query()->where('source', $this->source)->delete();
            PartCatalogCategory::query()->where('source', $this->source)->delete();
        }

        $seenCategoryUrls = [];
        $seenProductUrls = [];

        foreach ($this->modelCategories($baseUrl) as $modelCategory) {
            for ($page = 1; $maxPages === 0 || $page <= $maxPages; $page++) {
                $response = $this->fetchProducts($baseUrl, $page, (int) $modelCategory['id']);
                if ($response === null) {
                    break;
                }

                $stats['source_pages_fetched']++;
                $products = is_array($response->json()) ? $response->json() : [];
                if ($products === []) {
                    break;
                }

                foreach ($products as $product) {
                    if (! is_array($product)) {
                        continue;
                    }

                    $sourceUrl = $this->clean($product['permalink'] ?? '');
                    if ($sourceUrl !== '' && isset($seenProductUrls[$sourceUrl])) {
                        continue;
                    }
                    if ($sourceUrl !== '') {
                        $seenProductUrls[$sourceUrl] = true;
                    }

                    $stats['products_found']++;
                    $this->progress($progress, $verbose, '#'.($product['id'] ?? '?').' '.$modelCategory['label'].' '.$this->clean($product['name'] ?? ''));

                    $payload = $this->productPayload($product, $modelCategory);
                    if ($payload === null) {
                        $stats['products_skipped']++;

                        continue;
                    }

                    $category = null;
                    if (! $dryRun) {
                        $category = $this->categoryForProduct($product, $baseUrl, $seenCategoryUrls, $stats, $modelCategory);
                        $wasMergedByPartNumber = false;
                        $wasCreated = $this->saveProduct($payload, $category, $wasMergedByPartNumber)->wasRecentlyCreated;
                        $stats['products_saved']++;
                        $stats[$wasCreated ? 'products_created' : 'products_updated']++;
                        $stats['products_merged_by_part_number'] += $wasMergedByPartNumber ? 1 : 0;
                    }

                    if ($maxProducts > 0 && $stats['products_found'] >= $maxProducts) {
                        break 3;
                    }
                }

                if ($this->lastPageReached($response, $page, count($products))) {
                    break;
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        return $stats;
    }

    protected function saveProduct(array $payload, ?PartCatalogCategory $category, bool &$wasMergedByPartNumber): PartCatalogItem
    {
        $wasMergedByPartNumber = false;

        $item = PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('source_url', $payload['source_url'])
            ->first();

        if ($item === null) {
            return PartCatalogItem::query()->create(array_merge($payload, [
                'part_catalog_category_id' => $category?->id,
                'source' => $this->source,
            ]));
        }

        $item->fill($this->mergedPayload($item, $payload, $category));
        $item->save();

        return $item;
    }

    protected function mergedPayload(PartCatalogItem $item, array $payload, ?PartCatalogCategory $category): array
    {
        $isSameSourceUrl = $item->source_url === $payload['source_url'];
        $rawAttributes = $this->mergeRawAttributes($this->rawAttributesArray($item), (array) ($payload['raw_attributes'] ?? []), $item->source_url, $payload['source_url']);
        $compatibilityText = $this->mergeTextValues($item->compatibility_text, $payload['compatibility_text']);

        return array_merge($payload, [
            'part_catalog_category_id' => $item->part_catalog_category_id ?: $category?->id,
            'source' => $this->source,
            'source_url' => $item->source_url ?: $payload['source_url'],
            'name' => $isSameSourceUrl ? $payload['name'] : $item->name,
            'name_ru' => $isSameSourceUrl ? $payload['name_ru'] : $item->name_ru,
            'name_ua' => $isSameSourceUrl ? $payload['name_ua'] : $item->name_ua,
            'model_label' => $this->mergeListText($item->model_label, $payload['model_label']),
            'model_name' => $this->mergeListText($item->model_name, $payload['model_name']),
            'year_from' => $this->earliestYear($item->year_from, $payload['year_from']),
            'year_to' => $this->latestYear($item->year_to, $payload['year_to']),
            'compatibility_text' => $compatibilityText,
            'raw_attributes' => $rawAttributes,
        ]);
    }

    protected function rawAttributesArray(PartCatalogItem $item): array
    {
        $rawAttributes = $item->raw_attributes;

        if ($rawAttributes instanceof \ArrayObject) {
            return $rawAttributes->getArrayCopy();
        }

        return is_array($rawAttributes) ? $rawAttributes : [];
    }

    protected function mergeRawAttributes(array $current, array $incoming, ?string $currentUrl, ?string $incomingUrl): array
    {
        $merged = array_merge($current, $incoming);
        $currentLocalImages = $this->localImageUrls($current);
        if ($currentLocalImages !== []) {
            $incomingRemoteImages = collect([
                ...(array) ($incoming['image_urls'] ?? []),
                ...(array) ($incoming['remote_image_urls'] ?? []),
                $incoming['image_url'] ?? null,
                $incoming['remote_image_url'] ?? null,
                $incoming['primary_image_url'] ?? null,
            ])
                ->filter(fn (mixed $url): bool => is_string($url) && $this->isRemoteImageUrl($url))
                ->unique()
                ->values()
                ->all();

            $merged['image_urls'] = $currentLocalImages;
            $merged['image_url'] = $currentLocalImages[0] ?? null;
            $merged['remote_image_urls'] = array_values(array_unique(array_filter([
                ...(array) ($current['remote_image_urls'] ?? []),
                ...$incomingRemoteImages,
            ])));
            $merged['remote_image_url'] = $merged['remote_image_urls'][0] ?? null;
            unset($merged['primary_image_url']);
        }
        $merged['source_urls'] = collect($current['source_urls'] ?? [])
            ->merge([$currentUrl, $incomingUrl])
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();
        $merged['wp_product_ids'] = collect($current['wp_product_ids'] ?? [])
            ->merge([$current['wp_product_id'] ?? null, $incoming['wp_product_id'] ?? null])
            ->filter(fn (mixed $id): bool => filled($id))
            ->unique()
            ->values()
            ->all();
        $merged['sku_values'] = collect($current['sku_values'] ?? [])
            ->merge([$current['sku'] ?? null, $incoming['sku'] ?? null])
            ->filter(fn (mixed $sku): bool => is_string($sku) && trim($sku) !== '')
            ->unique()
            ->values()
            ->all();
        $merged['compatibility_values'] = collect($current['compatibility_values'] ?? [])
            ->merge([
                $this->attributeCompatibility($current['attributes'] ?? []),
                $this->attributeCompatibility($incoming['attributes'] ?? []),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return array_filter($merged, fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    protected function localImageUrls(array $rawAttributes): array
    {
        return collect([
            ...(array) ($rawAttributes['image_urls'] ?? []),
            $rawAttributes['image_url'] ?? null,
        ])
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '' && ! $this->isRemoteImageUrl($url))
            ->unique()
            ->values()
            ->all();
    }

    protected function isRemoteImageUrl(string $url): bool
    {
        return Str::startsWith(trim($url), ['http://', 'https://']);
    }

    protected function attributeCompatibility(mixed $attributes): ?string
    {
        if (! is_array($attributes)) {
            return null;
        }

        $canonical = $this->formatCompatibility($attributes['model'] ?? null, $attributes['year'] ?? null);
        if ($canonical !== '') {
            return $canonical;
        }

        $year = $attributes['Рік'] ?? null;
        $year ??= $attributes['Рі'] ?? null;

        return collect([
            $attributes['Модель'] ?? $attributes['??????'] ?? null,
            $year,
        ])->filter()->implode(' | ') ?: null;
    }

    protected function mergeListText(?string $current, ?string $incoming): ?string
    {
        $values = collect([$current, $incoming])
            ->flatMap(fn (?string $value): array => preg_split('/\s*,\s*/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values();

        return $values->isEmpty() ? null : $values->implode(', ');
    }

    protected function mergeTextValues(?string $current, ?string $incoming): ?string
    {
        $values = collect([$current, $incoming])
            ->map(fn (?string $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        return $values->isEmpty() ? null : $values->implode(', ');
    }

    protected function formatCompatibility(mixed $model, mixed $years): string
    {
        $model = trim((string) $model);
        if ($model === '') {
            return '';
        }

        $yearLabel = $this->formatYearList($years);

        return trim($model.' '.$yearLabel);
    }

    protected function formatYearList(mixed $years): string
    {
        preg_match_all('/\b(19\d{2}|20\d{2})\b/u', (string) $years, $matches);
        $values = collect($matches[1] ?? [])
            ->map(fn (string $year): int => (int) $year)
            ->unique()
            ->sort()
            ->values();

        if ($values->isEmpty()) {
            return '';
        }

        $isConsecutive = $values->count() === ($values->last() - $values->first() + 1);

        return $isConsecutive
            ? $values->first().'-'.$values->last()
            : $values->implode(', ');
    }

    protected function yearRange(mixed $years): array
    {
        preg_match_all('/\b(19\d{2}|20\d{2})\b/u', (string) $years, $matches);
        $values = collect($matches[1] ?? [])
            ->map(fn (string $year): int => (int) $year)
            ->unique()
            ->sort()
            ->values();

        return $values->isEmpty()
            ? [null, null]
            : [$values->first(), $values->last()];
    }

    protected function earliestYear(mixed $current, mixed $incoming): ?int
    {
        $years = collect([$current, $incoming])
            ->filter(fn (mixed $year): bool => filled($year))
            ->map(fn (mixed $year): int => (int) $year);

        return $years->isEmpty() ? null : $years->min();
    }

    protected function latestYear(mixed $current, mixed $incoming): ?int
    {
        if ($current === null) {
            return $incoming === null ? null : (int) $incoming;
        }

        if ($incoming === null) {
            return (int) $current;
        }

        return max((int) $current, (int) $incoming);
    }

    protected function fetchProducts(string $baseUrl, int $page, ?int $categoryId = null): ?Response
    {
        try {
            $query = [
                'per_page' => 100,
                'page' => $page,
            ];

            if ($categoryId !== null) {
                $query['category'] = $categoryId;
            }

            $response = $this->http
                ->timeout(30)
                ->retry(2, 500)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept-Language' => 'uk-UA,uk;q=0.9,ru;q=0.8,en;q=0.7',
                ])
                ->get($baseUrl.'/wp-json/wc/store/v1/products', $query);

            return $response->ok() && is_array($response->json()) ? $response : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function categoryForProduct(array $product, string $baseUrl, array &$seenCategoryUrls, array &$stats, ?array $listingModelCategory = null): ?PartCatalogCategory
    {
        [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->canonicalModel($this->attributeValue($product, 'pa_model') ?: ($listingModelCategory['name'] ?? null) ?: $this->modelFromCategories($product));
        [$attributeYearFrom, $attributeYearTo] = $this->yearRange($this->attributeValue($product, 'pa_year-attr') ?: $this->attributeValue($product, 'pa_year-car'));
        $yearFrom ??= $attributeYearFrom;
        $yearTo ??= $attributeYearTo;
        $modelSourceUrl = $this->modelCategoryUrl($product, $baseUrl, $modelName);

        $modelCategory = PartCatalogCategory::query()->updateOrCreate(
            ['source_url' => $modelSourceUrl],
            [
                'source' => $this->source,
                'parent_id' => null,
                'depth' => 0,
                'code' => null,
                'name' => $modelLabel,
                'name_ua' => $modelLabel,
                'model_label' => $modelLabel,
                'model_name' => $modelName,
                'year_from' => $yearFrom,
                'year_to' => $yearTo,
                'sort_order' => $this->modelSortOrder($modelLabel),
                'children_scanned_at' => now(),
            ]
        );

        if (! isset($seenCategoryUrls[$modelSourceUrl])) {
            $seenCategoryUrls[$modelSourceUrl] = true;
            $stats['models_saved']++;
        }

        $main = $this->mainCategory($product, $baseUrl, $modelName);
        if ($main === null) {
            return $modelCategory;
        }

        $sourceUrl = $main['url'].'#model='.Str::slug($modelLabel);
        $category = PartCatalogCategory::query()->updateOrCreate(
            ['source_url' => $sourceUrl],
            [
                'source' => $this->source,
                'parent_id' => $modelCategory->id,
                'depth' => 1,
                'code' => null,
                'name' => $main['name'],
                'name_ua' => $main['name'],
                'model_label' => $modelLabel,
                'model_name' => $modelName,
                'year_from' => $yearFrom,
                'year_to' => $yearTo,
                'sort_order' => 0,
                'children_scanned_at' => now(),
                'products_scanned_at' => now(),
            ]
        );

        if (! isset($seenCategoryUrls[$sourceUrl])) {
            $seenCategoryUrls[$sourceUrl] = true;
            $stats['categories_saved']++;
        }

        return $category;
    }

    protected function productPayload(array $product, ?array $listingModelCategory = null): ?array
    {
        $sourceUrl = $this->clean($product['permalink'] ?? '');
        $name = $this->clean($product['name'] ?? '');
        if ($sourceUrl === '' || $name === '') {
            return null;
        }

        $attributes = $this->attributes($product);
        [$attributeYearFrom, $attributeYearTo] = $this->yearRange($attributes['year'] ?? null);
        [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->canonicalModel($this->attributeValue($product, 'pa_model') ?: ($listingModelCategory['name'] ?? null) ?: $this->modelFromCategories($product));
        $yearFrom ??= $attributeYearFrom;
        $yearTo ??= $attributeYearTo;
        $mainCategory = $this->mainCategory($product, '', $modelName);
        $condition = $this->condition($this->attributeValue($product, 'pa_stan'));
        $availability = $this->availability($product);
        $partNumber = $this->partNumber($product, $name);
        $price = $this->price($product['prices'] ?? []);
        $images = collect($product['images'] ?? [])
            ->filter(fn (mixed $image): bool => is_array($image) && $this->clean($image['src'] ?? '') !== '')
            ->map(fn (array $image): string => $this->clean($image['src'] ?? ''))
            ->values()
            ->all();

        return [
            'source_url' => $sourceUrl,
            'part_number' => $partNumber,
            'name' => $name,
            'name_ru' => null,
            'name_ua' => $name,
            'price_amount' => $price['amount'],
            'currency' => $price['currency'],
            'model_label' => $modelLabel,
            'model_name' => $modelName,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
            'main_category_code' => null,
            'main_category_name' => $mainCategory['name'] ?? null,
            'subcategory_code' => null,
            'subcategory_name' => null,
            'node_name' => $mainCategory['name'] ?? null,
            'compatibility_text' => $this->compatibilityText($attributes, $modelLabel),
            'notes_ua' => $this->description($product['short_description'] ?? '') ?: $this->description($product['description'] ?? ''),
            'condition' => $condition,
            'quality' => null,
            'availability' => $availability,
            'raw_attributes' => array_filter([
                'wp_product_id' => $product['id'] ?? null,
                'sku' => $this->clean($product['sku'] ?? ''),
                'slug' => $this->clean($product['slug'] ?? ''),
                'categories' => $this->categories($product),
                'attributes' => $attributes,
                'images' => $images,
                'image_url' => $images[0] ?? null,
                'image_urls' => $images,
                'remote_image_url' => $images[0] ?? null,
                'remote_image_urls' => $images,
                'primary_image_url' => $images[0] ?? null,
                'model_listing_category' => $listingModelCategory,
                'is_purchasable' => $product['is_purchasable'] ?? null,
                'is_on_backorder' => $product['is_on_backorder'] ?? null,
                'raw_price' => $product['prices'] ?? null,
            ]),
            'source_updated_at' => now(),
        ];
    }

    protected function price(array $prices): array
    {
        $raw = $this->clean($prices['price'] ?? '');
        $minorUnit = max(0, (int) ($prices['currency_minor_unit'] ?? 2));
        $currency = $this->clean($prices['currency_code'] ?? '') ?: null;

        if ($raw === '' || ! is_numeric($raw)) {
            return ['amount' => null, 'currency' => $currency];
        }

        $amount = (float) $raw / (10 ** $minorUnit);

        return [
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
        ];
    }

    protected function isUkrainianUrl(string $url): bool
    {
        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/ua/');
    }

    protected function partNumber(array $product, string $name): ?string
    {
        $sku = $this->clean($product['sku'] ?? '');
        $sku = preg_replace('/\s*\(\d+\)\s*$/u', '', $sku) ?: $sku;

        foreach ([$sku, $name, $this->clean($product['slug'] ?? '')] as $value) {
            if (preg_match('/\b([0-9]{5,}[A-Z0-9.-]*-[A-Z0-9.-]+)\b/iu', $value, $matches) === 1) {
                return Str::upper(trim($matches[1], " \t\n\r\0\x0B,.;"));
            }
        }

        return $sku !== '' ? Str::upper($sku) : null;
    }

    protected function attributes(array $product): array
    {
        return collect($product['attributes'] ?? [])
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->mapWithKeys(function (array $attribute): array {
                $name = $this->clean($attribute['name'] ?? $attribute['taxonomy'] ?? '');
                $taxonomy = $this->clean($attribute['taxonomy'] ?? '');
                $values = collect($attribute['terms'] ?? [])
                    ->filter(fn (mixed $term): bool => is_array($term))
                    ->map(fn (array $term): string => $this->clean($term['name'] ?? ''))
                    ->filter()
                    ->values()
                    ->all();

                $value = implode(', ', $values);
                if ($name === '') {
                    return [];
                }

                return array_filter([
                    $name => $value,
                    'model' => $taxonomy === 'pa_model' ? $value : null,
                    'year' => str_starts_with($taxonomy, 'pa_year') ? $value : null,
                ]);
            })
            ->all();
    }

    protected function attributeValue(array $product, string $taxonomy): ?string
    {
        foreach ($product['attributes'] ?? [] as $attribute) {
            if (! is_array($attribute) || ($attribute['taxonomy'] ?? '') !== $taxonomy) {
                continue;
            }

            $values = collect($attribute['terms'] ?? [])
                ->filter(fn (mixed $term): bool => is_array($term))
                ->map(fn (array $term): string => $this->clean($term['name'] ?? ''))
                ->filter()
                ->values();

            return $values->first();
        }

        return null;
    }

    protected function categories(array $product): array
    {
        return collect($product['categories'] ?? [])
            ->filter(fn (mixed $category): bool => is_array($category))
            ->map(fn (array $category): array => [
                'id' => $category['id'] ?? null,
                'name' => $this->clean($category['name'] ?? ''),
                'slug' => $this->clean($category['slug'] ?? ''),
                'link' => $this->clean($category['link'] ?? ''),
            ])
            ->filter(fn (array $category): bool => $category['name'] !== '')
            ->values()
            ->all();
    }

    protected function mainCategory(array $product, string $baseUrl, ?string $modelName): ?array
    {
        foreach ($this->categories($product) as $category) {
            $name = $category['name'];
            $lower = Str::lower($name);

            if (str_contains($lower, 'tesla model') || ($modelName && str_contains($lower, Str::lower($modelName)))) {
                continue;
            }

            return [
                'name' => $name,
                'url' => $category['link'] ?: $baseUrl.'/category/'.($category['slug'] ?: Str::slug($name)).'/',
            ];
        }

        return null;
    }

    protected function modelCategoryUrl(array $product, string $baseUrl, ?string $modelName): string
    {
        return $baseUrl.'/teslawestparts-catalog-models/'.Str::slug($modelName ?: 'unknown-model');
    }

    protected function modelFromCategories(array $product): ?string
    {
        foreach ($this->categories($product) as $category) {
            if (preg_match('/Model\s+(3\s+Highland|S|3|X|Y)/iu', $category['name'], $matches) === 1) {
                return 'Model '.$matches[1];
            }
        }

        return null;
    }

    protected function canonicalModel(?string $model): array
    {
        $lower = Str::lower((string) $model);

        return match (true) {
            str_contains($lower, '3 highland') => ['Model 3 Highland 01.2024 -', 'Model 3 Highland', 2024, null],
            str_contains($lower, 'model s') => ['Model S', 'Model S', null, null],
            str_contains($lower, 'model 3') => ['Model 3', 'Model 3', null, null],
            str_contains($lower, 'model x') => ['Model X', 'Model X', null, null],
            str_contains($lower, 'model y') => ['Model Y', 'Model Y', null, null],
            default => ['Tesla West Parts', 'Tesla', null, null],
        };
    }

    protected function condition(?string $condition): ?string
    {
        $lower = Str::lower((string) $condition);

        return match (true) {
            str_contains($lower, 'нов') => 'new',
            str_contains($lower, 'вжив') || str_contains($lower, 'б/у') => 'used',
            default => $condition ?: null,
        };
    }

    protected function availability(array $product): string
    {
        if ((bool) ($product['is_on_backorder'] ?? false)) {
            return 'on backorder';
        }

        return (bool) ($product['is_in_stock'] ?? false) ? 'in stock' : 'out of stock';
    }

    protected function compatibilityText(array $attributes, string $modelLabel): ?string
    {
        $canonical = $this->formatCompatibility($attributes['model'] ?? null, $attributes['year'] ?? null);
        if ($canonical !== '') {
            return $canonical;
        }

        $parts = [];
        if (($attributes['Модель'] ?? '') !== '') {
            $parts[] = $attributes['Модель'];
        } elseif ($modelLabel !== 'Tesla West Parts') {
            $parts[] = $modelLabel;
        }

        if (($attributes['г'] ?? '') !== '') {
            $parts[] = $attributes['г'];
        }

        return $parts === [] ? null : implode(' | ', $parts);
    }

    protected function description(?string $value): ?string
    {
        $text = $this->clean(strip_tags((string) $value));

        return $text === '' ? null : Str::limit($text, 1000, '');
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

    protected function clean(mixed $value): string
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = CatalogTextEncoding::repair($value) ?? $value;

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($verbose && $progress !== null) {
            $progress($message);
        }
    }

    protected function modelCategories(string $baseUrl): array
    {
        return array_map(function (array $category) use ($baseUrl): array {
            return array_merge($category, [
                'url' => $this->absoluteModelCategoryUrl($baseUrl, $category['url']),
            ]);
        }, self::MODEL_CATEGORIES);
    }

    protected function absoluteModelCategoryUrl(string $baseUrl, string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return $path ? $baseUrl.$path : $url;
    }

    protected function lastPageReached(Response $response, int $page, int $count): bool
    {
        $totalPages = (int) ($response->header('X-WP-TotalPages') ?: 0);
        if ($totalPages > 0) {
            return $page >= $totalPages;
        }

        return $count < 100;
    }
}
