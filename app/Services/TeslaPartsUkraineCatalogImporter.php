<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Services\Concerns\DetectsPartCatalogLocalizedNames;
use App\Support\PartCatalogRawAttributes;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class TeslaPartsUkraineCatalogImporter
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

    public const DEFAULT_LOCALIZED_NAME_MARKER_PAIRS = [
        ['ua' => 'передній', 'ru' => 'передний'],
        ['ua' => 'переднього', 'ru' => 'переднего'],
        ['ua' => 'передня', 'ru' => 'передняя'],
        ['ua' => 'задній', 'ru' => 'задний'],
        ['ua' => 'заднього', 'ru' => 'заднего'],
        ['ua' => 'задня', 'ru' => 'задняя'],
        ['ua' => 'лівий', 'ru' => 'левый'],
        ['ua' => 'ліва', 'ru' => 'левая'],
        ['ua' => 'лівої', 'ru' => 'левой'],
        ['ua' => 'правий', 'ru' => 'правый'],
        ['ua' => 'права', 'ru' => 'правая'],
        ['ua' => 'правої', 'ru' => 'правой'],
        ['ua' => 'оригінал', 'ru' => 'оригинал'],
        ['ua' => 'оригінальний', 'ru' => 'оригинальный'],
        ['ua' => 'в зборі', 'ru' => 'в сборе'],
        ['ua' => 'у зборі', 'ru' => 'в сборе'],
        ['ua' => 'б/в', 'ru' => 'б/у'],
        ['ua' => 'бв', 'ru' => 'бу'],
        ['ua' => 'кришка', 'ru' => 'крышка'],
        ['ua' => 'кришки', 'ru' => 'крышки'],
        ['ua' => 'двері', 'ru' => 'двери'],
        ['ua' => 'багажника', 'ru' => 'багажника'],
        ['ua' => 'скла', 'ru' => 'стекла'],
        ['ua' => 'крило', 'ru' => 'крыло'],
        ['ua' => 'кріплення', 'ru' => 'крепления'],
        ['ua' => 'підсилювач', 'ru' => 'усилитель'],
        ['ua' => 'підкрилок', 'ru' => 'подкрыльник'],
        ['ua' => 'охолоджуючої', 'ru' => 'охлаждающей'],
        ['ua' => 'рідини', 'ru' => 'жидкости'],
        ['ua' => 'керування', 'ru' => 'управления'],
    ];

    protected string $source = 'teslapartsukraine';

    protected array $listingCategoryIndexes = [];

    protected array $localizedNameMarkersCache = [];

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://teslapartsukraine.com.ua'), '/');
        $startUrl = $this->absoluteUrl((string) ($options['start_url'] ?? '/katalog'), $baseUrl);
        $maxModels = (int) ($options['max_models'] ?? 0);
        $maxCategories = (int) ($options['max_categories'] ?? 0);
        $maxProducts = (int) ($options['max_products'] ?? 0);
        $sleepMs = (int) ($options['sleep_ms'] ?? 250);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'model_pages_seen' => 0,
            'model_pages_fetched' => 0,
            'main_categories_saved' => 0,
            'subcategories_saved' => 0,
            'product_pages_seen' => 0,
            'product_pages_fetched' => 0,
            'product_nodes_saved' => 0,
            'product_items_saved' => 0,
            'schematic_rows_seen' => 0,
        ];

        $rootHtml = $this->fetch($startUrl);
        if ($rootHtml === null) {
            return $stats;
        }

        $modelLinks = $this->modelLinks($this->page($rootHtml), $baseUrl);
        if ($maxModels > 0) {
            $modelLinks = array_slice($modelLinks, 0, $maxModels, true);
        }

        foreach ($modelLinks as $modelUrl => $modelLabel) {
            $stats['model_pages_seen']++;
            $this->progress($progress, $verbose, "Model #{$stats['model_pages_seen']}: {$modelLabel}");

            $html = $this->fetch($modelUrl);
            if ($html === null) {
                $this->progress($progress, $verbose, '  fetch failed');

                continue;
            }

            $stats['model_pages_fetched']++;
            $page = $this->page($html);
            [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->canonicalModel($this->headline($page) ?: $modelLabel);

            $modelCategory = null;
            if (! $dryRun) {
                $modelCategory = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $modelUrl],
                    [
                        'source' => $this->source,
                        'parent_id' => null,
                        'depth' => 0,
                        'code' => null,
                        'name' => $modelLabel,
                        'model_label' => $modelLabel,
                        'model_name' => $modelName,
                        'year_from' => $yearFrom,
                        'year_to' => $yearTo,
                        'sort_order' => $this->modelSortOrder($modelLabel),
                        'children_scanned_at' => now(),
                    ]
                );
            }

            foreach ($this->modelMainCategories($page, $modelUrl, $baseUrl) as $main) {
                if ($maxCategories > 0 && $stats['subcategories_saved'] >= $maxCategories) {
                    break 2;
                }

                [$mainCode, $mainName] = $this->splitCodeName($main['name']);
                $mainName = $this->canonicalCategoryName($modelLabel, 1, $mainCode, $mainName);
                $mainCategory = null;

                if (! $dryRun) {
                    $mainCategory = PartCatalogCategory::query()->updateOrCreate(
                        ['source_url' => $main['source_url']],
                        [
                            'source' => $this->source,
                            'parent_id' => $modelCategory?->id,
                            'preview_image_url' => $main['preview_image_url'],
                            'depth' => 1,
                            'code' => $mainCode,
                            'name' => $mainName,
                            'model_label' => $modelLabel,
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
                    if ($maxCategories > 0 && $stats['subcategories_saved'] >= $maxCategories) {
                        break 3;
                    }

                    [$subcategoryCode, $subcategoryName] = $this->splitCodeName($subcategory['name']);
                    $subcategoryName = $this->canonicalCategoryName($modelLabel, 2, $subcategoryCode, $subcategoryName);
                    $subcategoryModel = null;

                    if (! $dryRun) {
                        $subcategoryModel = PartCatalogCategory::query()->updateOrCreate(
                            ['source_url' => $subcategory['url']],
                            [
                                'source' => $this->source,
                                'parent_id' => $mainCategory?->id,
                                'depth' => 2,
                                'code' => $subcategoryCode,
                                'name' => $subcategoryName,
                                'model_label' => $modelLabel,
                                'model_name' => $modelName,
                                'year_from' => $yearFrom,
                                'year_to' => $yearTo,
                                'sort_order' => $this->categorySortOrder($subcategoryCode),
                                'children_scanned_at' => now(),
                            ]
                        );

                        $stats['subcategories_saved']++;
                    }

                    $this->importSubcategoryProducts(
                        $subcategory['url'],
                        $subcategoryModel,
                        [
                            'model_label' => $modelLabel,
                            'model_name' => $modelName,
                            'year_from' => $yearFrom,
                            'year_to' => $yearTo,
                            'main_category_code' => $mainCode,
                            'main_category_name' => $mainName,
                            'subcategory_code' => $subcategoryCode,
                            'subcategory_name' => $subcategoryName,
                        ],
                        $stats,
                        $baseUrl,
                        $maxProducts,
                        $sleepMs,
                        $dryRun,
                        $verbose,
                        $progress
                    );

                    if ($maxProducts > 0 && $stats['schematic_rows_seen'] >= $maxProducts) {
                        break 3;
                    }
                }
            }

            $this->pause($sleepMs);
        }

        return $stats;
    }

    public function refreshModelListings(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://teslapartsukraine.com.ua'), '/');
        $modelUrls = array_values(array_unique(array_filter($options['model_urls'] ?? [
            'https://teslapartsukraine.com.ua/model-x-berezen-2021-r-model-x-mar-2021?limit=10000',
            'https://teslapartsukraine.com.ua/model-x-veresen-2015-r-%E2%80%93-lyutyj-2021-r-model-x-sep-2015-feb-2021?limit=10000',
            'https://teslapartsukraine.com.ua/tesla-model-3?limit=10000',
            'https://teslapartsukraine.com.ua/tesla-model-y?limit=10000',
            'https://teslapartsukraine.com.ua/model-s-lyutyj-2012-r-%E2%80%93-berezen-2016-r-model-s-feb-2012-mar-2016?limit=10000',
            'https://teslapartsukraine.com.ua/model-s-lyutyj-2021-r-model-s-feb-2021?limit=10000',
            'https://teslapartsukraine.com.ua/model-s-kviten-2016-r-%E2%80%93-sichen-2021-r-model-s-apr-2016-jan-2021?limit=10000',
        ])));
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 150));
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'listing_pages_seen' => 0,
            'listing_pages_fetched' => 0,
            'listing_products_seen' => 0,
            'listing_products_matched' => 0,
            'listing_products_unknown' => 0,
            'products_created' => 0,
            'products_saved' => 0,
            'products_updated' => 0,
            'products_unchanged' => 0,
            'prices_changed' => 0,
            'availability_changed' => 0,
            'listing_product_images_saved' => 0,
            'category_pages_seen' => 0,
            'category_pages_fetched' => 0,
            'categories_saved' => 0,
            'product_categories_matched' => 0,
            'product_pages_fetched' => 0,
        ];

        foreach ($modelUrls as $modelUrl) {
            $modelUrl = $this->withLimit($modelUrl);
            $stats['listing_pages_seen']++;
            $this->progress($progress, $verbose, "All products page #{$stats['listing_pages_seen']}: {$modelUrl}");

            $html = $this->fetch($modelUrl);
            if ($html === null) {
                continue;
            }

            $stats['listing_pages_fetched']++;
            $listingPage = $this->page($html);
            [$listingModelLabel, $listingModelName, $listingYearFrom, $listingYearTo] = $this->modelContextFromListingUrl($modelUrl);
            $listingModelCategory = $this->storeListingModelCategory($modelUrl, $listingModelLabel, $listingModelName, $listingYearFrom, $listingYearTo, $dryRun);
            $products = $this->listingProducts($listingPage, $baseUrl);
            $stats['listing_products_seen'] += count($products);
            $this->progress($progress, $verbose, 'products: '.count($products));

            foreach ($products as $position => $product) {
                $product['listing_model_sort_order'] = $stats['listing_pages_seen'];
                $product['listing_sort_order'] = $position + 1;
                $product['listing_source_url'] = $this->normalizedProductUrl($modelUrl);
                $product['listing_model_category_id'] = $listingModelCategory?->id;
                $product['listing_model_label'] = $listingModelLabel;
                $product['listing_model_name'] = $listingModelName;
                $product['listing_year_from'] = $listingYearFrom;
                $product['listing_year_to'] = $listingYearTo;

                $items = $this->itemsForListingProduct($product);

                if ($items->isEmpty()) {
                    $stats['listing_products_unknown']++;

                    if (! $dryRun) {
                        $product = $this->enrichedListingProduct($product, $baseUrl, $stats);
                        $this->createListingProductItem($product, $modelUrl);
                    }

                    $stats['products_created']++;
                    $stats['products_saved']++;
                    $stats['listing_product_images_saved'] += count((array) ($product['image_urls'] ?? []));
                    $this->progress($progress, $verbose, 'created: '.$product['part_number'].' | '.$product['name']);

                    continue;
                }

                $stats['listing_products_matched']++;
                $changedAny = false;

                foreach ($items as $item) {
                    $changes = $this->listingProductPriceChanges($item, $product);

                    if ($changes === []) {
                        continue;
                    }

                    $changedAny = true;
                    $stats['prices_changed']++;

                    if (! $dryRun) {
                        $item->forceFill($changes)->save();
                    }
                }

                if ($changedAny) {
                    $stats['products_saved']++;
                    $stats['products_updated']++;
                    $this->progress($progress, $verbose, 'updated [price_amount]: '.$product['part_number'].' | '.$product['name']);
                } else {
                    $stats['products_unchanged']++;
                }
            }

            $this->pause($sleepMs);
        }

        return $stats;
    }

    public function refreshProductImages(array $options = []): array
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://teslapartsukraine.com.ua'), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $missingOnly = (bool) ($options['missing_only'] ?? true);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 150));
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'items_seen' => 0,
            'product_pages_fetched' => 0,
            'items_updated' => 0,
            'items_skipped' => 0,
            'images_found' => 0,
        ];

        $query = PartCatalogItem::query()
            ->where('source', $this->source)
            ->where(function ($query): void {
                $query
                    ->whereNull('source_url')
                    ->orWhere('source_url', 'not like', '%route=tesla/catalog/product%');
            })
            ->where(function ($query): void {
                $query
                    ->whereNotNull('raw_attributes->product_url')
                    ->orWhereNotNull('raw_attributes->buy_url');
            })
            ->when($missingOnly, fn ($query) => $query->where(function ($query): void {
                $query
                    ->whereNull('raw_attributes->image_urls')
                    ->orWhere('raw_attributes->image_urls', '[]')
                    ->orWhere('raw_attributes->image_urls', '');
            }))
            ->orderBy('id')
            ->when($limit > 0, fn ($query) => $query->limit($limit));

        foreach ($query->get() as $item) {
            $stats['items_seen']++;
            $productUrl = $this->productUrlForItem($item);

            if ($productUrl === null) {
                $stats['items_skipped']++;

                continue;
            }

            $this->progress($progress, $verbose, "#{$item->id} {$item->part_number}: {$productUrl}");

            $html = $this->fetch($productUrl);
            if ($html === null) {
                $stats['items_skipped']++;
                $this->pause($sleepMs);

                continue;
            }

            $stats['product_pages_fetched']++;
            $imageUrls = $this->productImageUrls($this->page($html), $baseUrl);
            $stats['images_found'] += $imageUrls->count();

            if ($imageUrls->isEmpty()) {
                $stats['items_skipped']++;
                $this->pause($sleepMs);

                continue;
            }

            if (! $dryRun) {
                $rawAttributes = PartCatalogRawAttributes::from($item);
                $rawAttributes['image_urls'] = $imageUrls->all();
                $rawAttributes['product_images_refreshed_at'] = now()->toDateTimeString();

                $item->forceFill([
                    'raw_attributes' => array_filter($rawAttributes, fn ($value): bool => $value !== null && $value !== ''),
                ])->save();
            }

            $stats['items_updated']++;
            $this->progress($progress, $verbose, '  images: '.$imageUrls->count());
            $this->pause($sleepMs);
        }

        return $stats;
    }

    protected function importSubcategoryProducts(
        string $subcategoryUrl,
        ?PartCatalogCategory $subcategory,
        array $context,
        array &$stats,
        string $baseUrl,
        int $maxProducts,
        int $sleepMs,
        bool $dryRun,
        bool $verbose,
        ?callable $progress,
    ): void {
        $html = $this->fetch($subcategoryUrl);
        if ($html === null) {
            return;
        }

        $page = $this->page($html);

        if (! $dryRun && $subcategory !== null) {
            $subcategory->forceFill(['children_scanned_at' => now()])->save();
        }

        foreach ($this->productNodeLinks($page, $baseUrl) as $nodeUrl => $nodeName) {
            if ($maxProducts > 0 && $stats['schematic_rows_seen'] >= $maxProducts) {
                return;
            }

            $stats['product_pages_seen']++;
            $this->progress($progress, $verbose, "  Product page #{$stats['product_pages_seen']}: {$nodeName}");

            $nodeHtml = $this->fetch($nodeUrl);
            if ($nodeHtml === null) {
                continue;
            }

            $stats['product_pages_fetched']++;
            $nodePage = $this->page($nodeHtml);
            $nodeName = $this->headline($nodePage) ?: $nodeName;
            $nodeCategory = null;

            if (! $dryRun) {
                $nodeCategory = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $nodeUrl],
                    [
                        'source' => $this->source,
                        'parent_id' => $subcategory?->id,
                        'preview_image_url' => $this->mainImageUrl($nodePage, $baseUrl),
                        'depth' => 3,
                        'code' => null,
                        'name' => $nodeName,
                        'model_label' => $context['model_label'],
                        'model_name' => $context['model_name'],
                        'year_from' => $context['year_from'],
                        'year_to' => $context['year_to'],
                        'sort_order' => 0,
                        'children_scanned_at' => now(),
                        'products_scanned_at' => now(),
                    ]
                );

                $stats['product_nodes_saved']++;
            }

            foreach ($this->partRows($nodePage, $baseUrl) as $row) {
                $stats['schematic_rows_seen']++;

                if ($dryRun) {
                    continue;
                }

                $row = $this->enrichedPartRow($row, $baseUrl);

                $this->persistPartRow($nodeCategory, $context, $nodeName, $nodeUrl, $row);

                $stats['product_items_saved']++;
            }

            $this->pause($sleepMs);
        }

        if (! $dryRun && $subcategory !== null) {
            $subcategory->forceFill(['products_scanned_at' => now()])->save();
        }
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = $this->http
                ->timeout(30)
                ->retry(2, 500)
                ->withHeaders(['User-Agent' => 'NikolaCars catalog importer/1.0'])
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

    protected function modelLinks(array $page, string $baseUrl): array
    {
        $links = [];

        foreach ($page['xpath']->query('//a[contains(@href, "route=tesla") and contains(@href, "catalog") and contains(@href, "model_id=")]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($node->getAttribute('href'), $baseUrl);
            $text = $this->clean($node->textContent);

            if ($url !== null && $text !== '') {
                $links[$url] = $this->clean(preg_replace('/\s*Подивитися каталог\s*$/u', '', $text));
            }
        }

        return $links;
    }

    protected function modelMainCategories(array $page, string $modelUrl, string $baseUrl): array
    {
        $categories = [];

        foreach ($page['xpath']->query('//li[contains(concat(" ", normalize-space(@class), " "), " category_list_item ")]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $title = $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " title ")]', $node)->item(0)?->textContent);
            if ($title === '') {
                continue;
            }

            [$code] = $this->splitCodeName($title);
            $children = [];

            foreach ($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " subCat ")]//a[@href]', $node) as $link) {
                if (! $link instanceof DOMElement) {
                    continue;
                }

                $url = $this->absoluteUrl($link->getAttribute('href'), $baseUrl);
                $name = $this->clean($link->getAttribute('title') ?: $link->textContent);

                if ($url !== null && $name !== '') {
                    $children[$url] = ['url' => $url, 'name' => $name];
                }
            }

            $image = $page['xpath']->query('.//img[@src]', $node)->item(0);
            $categories[] = [
                'source_url' => $modelUrl.'#'.($code ?: Str::slug($title)),
                'name' => $title,
                'preview_image_url' => $image instanceof DOMElement ? $this->absoluteUrl($image->getAttribute('src'), $baseUrl) : null,
                'children' => array_values($children),
            ];
        }

        return $categories;
    }

    protected function productNodeLinks(array $page, string $baseUrl): array
    {
        $links = [];

        foreach ($page['xpath']->query('//a[contains(@href, "route=tesla") and contains(@href, "catalog") and contains(@href, "product") and contains(@href, "tesla_category_id=")]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($node->getAttribute('href'), $baseUrl);
            $text = $this->clean($node->getAttribute('title') ?: $node->textContent);

            if ($url !== null && $text !== '') {
                $links[$url] = $text;
            }
        }

        return $links;
    }

    protected function listingProducts(array $page, string $baseUrl): array
    {
        $products = [];

        foreach ($page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " product-thumb ")]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $nameLink = $page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " name ")]//a[@href]', $node)->item(0);
            $imageLink = $page['xpath']->query('.//a[contains(concat(" ", normalize-space(@class), " "), " product-img ")][@href]', $node)->item(0);
            $link = $nameLink instanceof DOMElement ? $nameLink : $imageLink;

            if (! $link instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($link->getAttribute('href'), $baseUrl);
            $name = $this->clean($link->textContent);
            $text = $this->clean($node->textContent);
            $partNumber = preg_match('/Артикул:\s*([A-Z0-9][A-Z0-9-]+)/u', $text, $matches) === 1
                ? strtoupper($this->clean($matches[1]))
                : null;
            $priceText = $this->clean($page['xpath']->query('.//*[contains(concat(" ", normalize-space(@class), " "), " price-normal ")]', $node)->item(0)?->textContent);
            $priceAmount = $this->priceAmount($priceText);
            $productId = $this->productIdFromUrl($url)
                ?: $this->clean($page['xpath']->query('.//input[@name="product_id"]/@value', $node)->item(0)?->nodeValue);
            $image = $page['xpath']->query('.//img[@src]', $node)->item(0);
            $imageUrl = $image instanceof DOMElement ? $this->absoluteUrl($image->getAttribute('src'), $baseUrl) : null;
            $availability = str_contains(' '.$node->getAttribute('class').' ', ' out-of-stock ')
                ? 'відсутній на складі'
                : 'на складі';

            if ($url === null || $partNumber === null) {
                continue;
            }

            $products[] = [
                'product_id' => $productId !== '' ? $productId : null,
                'url' => $this->normalizedProductUrl($url),
                'part_number' => $partNumber,
                'name' => $name,
                'price_amount' => $priceAmount,
                'currency' => $priceAmount !== null ? 'USD' : null,
                'availability' => $availability,
                'image_url' => $imageUrl,
            ];
        }

        return $products;
    }

    protected function createListingProductItem(array $product, string $modelUrl, ?string $modelTitle = null): PartCatalogItem
    {
        [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->modelContextFromListingUrl($modelUrl, $modelTitle);
        $nameOrigin = $this->partOriginFromName($product['name'] ?? null);
        $nameCondition = $this->partConditionFromName($nameOrigin['name'] ?? ($product['name'] ?? null));
        $partOrigin = $nameOrigin['part_origin'] ?? null;
        $condition = $nameCondition['condition'] ?? null;
        $compatibilityText = $this->listingCompatibilityText($modelLabel, $product['name'] ?? null);
        $cleanName = $this->withoutCompatibilityModelNames(
            $nameCondition['name'] ?? ($nameOrigin['name'] ?? ($product['name'] ?? null)),
            $compatibilityText
        );

        $rawAttributes = array_filter([
            'product_url' => $product['url'] ?? null,
            'listing_product_url' => $product['url'] ?? null,
            'listing_product_id' => $product['product_id'] ?? null,
            'listing_model_sort_order' => $product['listing_model_sort_order'] ?? null,
            'listing_sort_order' => $product['listing_sort_order'] ?? null,
            'image_urls' => $product['image_urls'] ?? (
                ($product['image_url'] ?? null) !== null && $this->isProductImageUrl($product['image_url'])
                    ? [$product['image_url']]
                    : null
            ),
            'product_images_refreshed_at' => ($product['image_urls'] ?? []) !== [] ? now()->toDateTimeString() : null,
            'listing_image_saved_at' => (($product['image_urls'] ?? []) !== [] || (($product['image_url'] ?? null) !== null && $this->isProductImageUrl($product['image_url'])))
                ? now()->toDateTimeString()
                : null,
            'listing_source_url' => $product['listing_source_url'] ?? $this->normalizedProductUrl($modelUrl),
            'category_source_url' => data_get($product, 'category_match.source_url'),
            'category_path' => data_get($product, 'category_match.path_label'),
            'original_name' => $partOrigin !== null || $condition !== null ? ($product['name'] ?? null) : null,
            'part_origin' => $partOrigin,
            'part_origin_label' => $this->partOriginLabel($partOrigin),
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);
        $localizedNameResolution = $this->localizedNameResolution((string) ($cleanName ?? ''), $product['name'] ?? null);

        if (($localizedNameResolution['source'] ?? null) === 'language_marker') {
            foreach ($this->localizedNameSourceLocales($localizedNameResolution) as $locale) {
                $rawAttributes['name_source_type_'.$locale] = 'language_marker';
                $rawAttributes['name_source_marker_'.$locale] = $localizedNameResolution['marker'];
                $rawAttributes = $this->withLocalizedNameMarkerConflict($rawAttributes, $locale, $localizedNameResolution);
            }
        }

        return PartCatalogItem::query()->create([
            'source' => $this->source,
            'source_url' => $product['url'] ?? null,
            'part_number' => $product['part_number'] ?? null,
            'name' => $cleanName,
            'notes_ua' => $product['description'] ?? null,
            'price_amount' => $product['price_amount'] ?? null,
            'currency' => $product['currency'] ?? null,
            'condition' => $condition,
            'availability' => $product['availability'] ?? null,
            'model_label' => $modelLabel,
            'model_name' => $modelName,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
            'compatibility_text' => $compatibilityText,
            'raw_attributes' => $rawAttributes,
            'source_updated_at' => now(),
        ] + $this->localizedNamePayloadFromResolution($localizedNameResolution) + $this->categoryPayloadFromListingProduct($product));
    }

    protected function enrichedListingProduct(array $product, string $baseUrl, array &$stats): array
    {
        $url = (string) ($product['url'] ?? '');

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            return $product;
        }

        $html = $this->fetch($url);
        if ($html === null) {
            return $product;
        }

        $stats['product_pages_fetched']++;
        $page = $this->page($html);
        $text = $this->clean($page['document']->textContent);
        $title = $this->headline($page);
        $partNumber = $this->productTextValue($text, 'Артикул');
        $priceAmount = $this->priceAmount($text);
        $imageUrls = $this->productImageUrls($page, $baseUrl)->all();

        return array_filter([
            ...$product,
            'name' => $title ?: ($product['name'] ?? null),
            'part_number' => $partNumber ?: ($product['part_number'] ?? null),
            'availability' => $this->productTextValue($text, 'Наявність') ?: ($product['availability'] ?? null),
            'price_amount' => $priceAmount ?? ($product['price_amount'] ?? null),
            'currency' => ($priceAmount ?? ($product['price_amount'] ?? null)) !== null ? 'USD' : null,
            'description' => $this->productDescription($page),
            'image_urls' => $imageUrls !== [] ? $imageUrls : (
                ($product['image_url'] ?? null) !== null && $this->isProductImageUrl($product['image_url'])
                    ? [$product['image_url']]
                    : null
            ),
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function modelContextFromListingUrl(string $modelUrl, ?string $modelTitle = null): array
    {
        $path = Str::lower((string) parse_url($modelUrl, PHP_URL_PATH));
        $title = Str::lower($this->clean($modelTitle));

        return match (true) {
            str_contains($path, 'model-s-lyutyj-2012') || str_contains($title, 'feb 2012') => ['Model S Feb 2012', 'Model S', 2012, 2016],
            str_contains($path, 'model-s-kviten-2016') || str_contains($title, 'apr 2016') => ['Model S Apr 2016', 'Model S', 2016, 2021],
            str_contains($path, 'model-s-lyutyj-2021') || str_contains($title, 'feb 2021') => ['Model S Feb 2021', 'Model S', 2021, null],
            str_contains($path, 'model-x-veresen-2015') || str_contains($title, 'sep 2015') => ['Model X Sep 2015', 'Model X', 2015, 2021],
            str_contains($path, 'model-x-berezen-2021') || str_contains($title, 'mar 2021') => ['Model X Mar 2021', 'Model X', 2021, null],
            str_contains($path, 'tesla-model-s') => ['Tesla Model S', 'Tesla Model S', null, null],
            str_contains($path, 'tesla-model-x') => ['Tesla Model X', 'Tesla Model X', null, null],
            str_contains($path, 'tesla-model-y') => ['Model Y', 'Model Y', null, null],
            default => ['Model 3', 'Model 3', null, null],
        };
    }

    protected function listingModelPages(string $modelUrl, array $modelPage, string $baseUrl): array
    {
        $path = Str::lower((string) parse_url($modelUrl, PHP_URL_PATH));

        if (! str_contains($path, 'tesla-model-s') && ! str_contains($path, 'tesla-model-x')) {
            return [[
                'url' => $modelUrl,
                'label' => null,
                'page' => $modelPage,
            ]];
        }

        $links = collect($modelPage['xpath']->query('//a[@href]'))
            ->map(function (DOMElement $node) use ($baseUrl): array {
                return [
                    'url' => $this->absoluteUrl($node->getAttribute('href'), $baseUrl),
                    'label' => $this->clean($node->textContent),
                ];
            })
            ->filter(function (array $link) use ($path): bool {
                $urlPath = Str::lower((string) parse_url($link['url'], PHP_URL_PATH));

                return str_contains($urlPath, str_contains($path, 'tesla-model-s') ? 'model-s-' : 'model-x-')
                    && ! str_contains($urlPath, 'tesla-model-');
            })
            ->unique(fn (array $link): string => (string) $this->normalizedProductUrl($link['url']))
            ->values()
            ->all();

        return $links !== [] ? $links : [[
            'url' => $modelUrl,
            'label' => null,
            'page' => $modelPage,
        ]];
    }

    protected function shouldIndexListingCategories(string $listingUrl): bool
    {
        $path = Str::lower((string) parse_url($listingUrl, PHP_URL_PATH));

        return ! in_array(trim($path, '/'), ['tesla-model-s', 'tesla-model-x'], true);
    }

    protected function itemsForListingProduct(array $product): Collection
    {
        $productId = $product['product_id'] ?? null;
        $url = $product['url'] ?? null;

        $query = PartCatalogItem::query()->where('source', $this->source);

        return (clone $query)
            ->where(function ($query): void {
                $query
                    ->whereNull('source_url')
                    ->orWhere('source_url', 'not like', '%route=tesla/catalog/product%');
            })
            ->where(function ($query) use ($productId, $url): void {
                if ($url !== null) {
                    $query
                        ->orWhere('source_url', $url)
                        ->orWhere('raw_attributes->product_url', $url)
                        ->orWhere('raw_attributes->buy_url', $url)
                        ->orWhere('raw_attributes->listing_product_url', $url);
                }

                if ($productId !== null && $productId !== '') {
                    $query
                        ->orWhere('raw_attributes->product_url', 'like', '%product_id='.$productId.'%')
                        ->orWhere('raw_attributes->buy_url', 'like', '%product_id='.$productId.'%')
                        ->orWhere('raw_attributes->listing_product_url', 'like', '%product_id='.$productId.'%');
                }
            })
            ->get();
    }

    protected function listingProductPriceChanges(PartCatalogItem $item, array $product): array
    {
        $priceAmount = $product['price_amount'] ?? null;

        if ($priceAmount === null || round((float) $item->price_amount, 2) === round((float) $priceAmount, 2)) {
            return [];
        }

        return [
            'price_amount' => $priceAmount,
            'currency' => 'USD',
            'source_updated_at' => now(),
        ];
    }

    protected function listingProductChanges(PartCatalogItem $item, array $product): array
    {
        $changes = [];
        $priceAmount = $product['price_amount'] ?? null;
        $listingModelLabel = $this->clean($product['listing_model_label'] ?? null);
        $listingModelName = $this->clean($product['listing_model_name'] ?? null);
        $listingYearFrom = $product['listing_year_from'] ?? null;
        $listingYearTo = $product['listing_year_to'] ?? null;
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $canReassignListingContext = $this->canReassignListingContext(
            (string) ($rawAttributes['listing_source_url'] ?? ''),
            (string) ($product['listing_source_url'] ?? '')
        );

        if ($priceAmount !== null && ($item->price_amount === null || round((float) $item->price_amount, 2) !== round((float) $priceAmount, 2))) {
            $changes['price_amount'] = $priceAmount;
            $changes['currency'] = 'USD';
        }

        if ($canReassignListingContext && $listingModelLabel !== '' && (string) $item->model_label !== $listingModelLabel) {
            $changes['model_label'] = $listingModelLabel;
        }

        if ($canReassignListingContext && $listingModelName !== '' && (string) $item->model_name !== $listingModelName) {
            $changes['model_name'] = $listingModelName;
        }

        if ($canReassignListingContext && $listingYearFrom !== null && (int) $item->year_from !== (int) $listingYearFrom) {
            $changes['year_from'] = $listingYearFrom;
        }

        if ($canReassignListingContext && (int) ($item->year_to ?? 0) !== (int) ($listingYearTo ?? 0)) {
            $changes['year_to'] = $listingYearTo;
        }

        if (($product['availability'] ?? null) !== null && (string) $item->availability !== (string) $product['availability']) {
            $changes['availability'] = $product['availability'];
        }

        $compatibilityText = $this->listingCompatibilityText(
            $item->compatibility_text ?: ($item->model_label ?: $item->model_name),
            $product['name'] ?? null
        );

        if ($compatibilityText !== '' && (string) $item->compatibility_text !== $compatibilityText) {
            $changes['compatibility_text'] = $compatibilityText;
        }

        $localizedNameChanges = $this->localizedNameChanges($item, (string) ($product['name'] ?? ''));
        if ($localizedNameChanges !== []) {
            $changes = array_replace($changes, $localizedNameChanges);
        }

        $originChanges = $this->partOriginChanges($item, $product['name'] ?? null);
        if ($originChanges !== []) {
            $changes = array_replace($changes, Arr::except($originChanges, ['raw_attributes']));
        }

        if ($canReassignListingContext) {
            $categoryChanges = ($product['category_match'] ?? null) !== null
                ? $this->categoryChangesFromMatch($item, $product['category_match'])
                : $this->categoryChangesFromListingModel($item, $product);
            if ($categoryChanges !== []) {
                $changes = array_replace($changes, $categoryChanges);
            }
        }

        $originalRawAttributes = $rawAttributes;
        $rawChanges = [];

        if ($canReassignListingContext && $this->listingProductMatchedByUrl($item, $product)) {
            $rawChanges = [
                'listing_product_url' => $product['url'] ?? null,
                'listing_product_id' => $product['product_id'] ?? null,
                'listing_model_sort_order' => $product['listing_model_sort_order'] ?? null,
                'listing_sort_order' => $product['listing_sort_order'] ?? null,
                'listing_source_url' => $product['listing_source_url'] ?? null,
            ];

            if (($product['category_match'] ?? null) !== null) {
                $rawChanges['category_source_url'] = data_get($product, 'category_match.source_url');
                $rawChanges['category_path'] = data_get($product, 'category_match.path_label');
            } else {
                unset($rawAttributes['category_source_url'], $rawAttributes['category_path']);
            }
        }

        if ($this->listingProductMatchedByUrl($item, $product) && ($product['image_url'] ?? null) !== null) {
            $rawChanges['image_urls'] = array_values(array_unique(array_filter([
                ...(array) ($rawAttributes['image_urls'] ?? []),
                $product['image_url'],
            ])));
        }

        foreach ($rawChanges as $key => $value) {
            if ($value !== null && ($rawAttributes[$key] ?? null) !== $value) {
                $rawAttributes[$key] = $value;
            }
        }

        if (isset($originChanges['raw_attributes']) && is_array($originChanges['raw_attributes'])) {
            foreach ($originChanges['raw_attributes'] as $key => $value) {
                if ($value !== null && ($rawAttributes[$key] ?? null) !== $value) {
                    $rawAttributes[$key] = $value;
                }
            }
        }

        if ($rawAttributes !== $originalRawAttributes) {
            $changes['raw_attributes'] = array_filter($rawAttributes, fn ($value): bool => $value !== null && $value !== '');
        }

        if ($changes !== []) {
            $changes = $this->preserveExistingNames($item, $changes);
        }

        if ($changes !== []) {
            $changes['source_updated_at'] = now();
        }

        return $changes;
    }

    protected function canReassignListingContext(string $existingListingUrl, string $newListingUrl): bool
    {
        $existing = $this->normalizedProductUrl($existingListingUrl);
        $new = $this->normalizedProductUrl($newListingUrl);

        if ($existing === null || $new === null || $existing === $new) {
            return true;
        }

        if (
            $this->isGenerationListingUrl($existing)
            && $this->isGenerationListingUrl($new)
            && $this->generationListingKey($existing) === $this->generationListingKey($new)
        ) {
            return true;
        }

        return ! $this->isGenerationListingUrl($existing);
    }

    protected function isGenerationListingUrl(string $url): bool
    {
        $path = Str::lower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, 'model-s-')
            || str_contains($path, 'model-x-');
    }

    protected function generationListingKey(string $url): string
    {
        return Str::lower(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
    }

    public function listingCompatibilityText(?string $baseCompatibility, ?string $name): string
    {
        $compatibility = $this->clean($baseCompatibility);
        $name = Str::lower($this->clean($name));

        if ($compatibility === '') {
            $compatibility = 'Tesla Model 3';
        }

        $compatibilityModels = collect(preg_split('/\s*,\s*/u', $compatibility) ?: [])
            ->filter()
            ->values();
        $compatibilityText = Str::lower($compatibilityModels->implode(', '));

        foreach (['s' => 'Tesla Model S', '3' => 'Tesla Model 3', 'x' => 'Tesla Model X', 'y' => 'Tesla Model Y'] as $model => $label) {
            if (
                preg_match('/(?<![\pL\pN])model\s*'.preg_quote($model, '/').'(?![\pL\pN])/iu', $name) === 1
                && ! str_contains($compatibilityText, 'model '.$model)
            ) {
                $compatibilityModels->push($label);
                $compatibilityText .= ', '.Str::lower($label);
            }
        }

        return $compatibilityModels
            ->unique(fn (string $value): string => Str::lower($value))
            ->implode(', ');
    }

    protected function listingCategoryIndex(
        string $modelUrl,
        array $modelPage,
        string $baseUrl,
        bool $dryRun,
        int $sleepMs,
        bool $verbose,
        ?callable $progress,
        array &$stats,
        ?string $modelTitle = null,
    ): array {
        $cacheKey = (string) $this->normalizedProductUrl($modelUrl);

        if (isset($this->listingCategoryIndexes[$cacheKey])) {
            return $this->listingCategoryIndexes[$cacheKey];
        }

        [$modelLabel, $modelName, $yearFrom, $yearTo] = $this->modelContextFromListingUrl($modelUrl, $modelTitle);
        $modelCategory = $this->storeListingModelCategory($modelUrl, $modelLabel, $modelName, $yearFrom, $yearTo, $dryRun);
        $index = [];
        $queue = [];
        $visited = [];

        foreach ($this->listingCategoryLinks($modelPage, $baseUrl) as $link) {
            $queue[] = [
                'url' => $link['url'],
                'name' => $link['name'],
                'path' => [],
                'parent_id' => $modelCategory?->id,
            ];
        }

        while ($queue !== []) {
            $entry = array_shift($queue);
            $url = $this->withLimit($entry['url']);
            $visitedKey = (string) $this->normalizedProductUrl($url);

            if (isset($visited[$visitedKey])) {
                continue;
            }

            $visited[$visitedKey] = true;
            $stats['category_pages_seen']++;
            $this->progress($progress, $verbose, 'category: '.$url);

            $html = $this->fetch($url);
            if ($html === null) {
                continue;
            }

            $stats['category_pages_fetched']++;
            $page = $this->page($html);
            $category = $this->storeListingCategory($entry, $page, $modelLabel, $modelName, $yearFrom, $yearTo, $baseUrl, $dryRun);

            if ($category !== null) {
                $stats['categories_saved']++;
            }

            [$code, $name] = $this->splitCodeName($entry['name']);
            $pathNode = [
                'id' => $category?->id,
                'source_url' => $url,
                'code' => $code,
                'name' => $name,
            ];
            $path = [...$entry['path'], $pathNode];
            $match = [
                'category_id' => $category?->id,
                'source_url' => $url,
                'path' => $path,
                'path_label' => collect($path)->pluck('name')->filter()->implode(' / '),
            ];

            foreach ($this->listingProducts($page, $baseUrl) as $product) {
                foreach ($this->productIndexKeys($product) as $productKey) {
                    $index[$productKey] = $match;
                }
            }

            foreach ($this->listingCategoryLinks($page, $baseUrl) as $childLink) {
                $childKey = (string) $this->normalizedProductUrl($childLink['url']);

                if ($childKey === $visitedKey || isset($visited[$childKey])) {
                    continue;
                }

                $queue[] = [
                    'url' => $childLink['url'],
                    'name' => $childLink['name'],
                    'path' => $path,
                    'parent_id' => $category?->id,
                ];
            }

            $this->pause($sleepMs);
        }

        return $this->listingCategoryIndexes[$cacheKey] = $index;
    }

    protected function storeListingModelCategory(
        string $modelUrl,
        string $modelLabel,
        string $modelName,
        ?int $yearFrom,
        ?int $yearTo,
        bool $dryRun
    ): ?PartCatalogCategory {
        if ($dryRun) {
            return null;
        }

        $sourceUrl = $this->withLimit($modelUrl);
        $category = PartCatalogCategory::query()
            ->where('source_url', $sourceUrl)
            ->first()
            ?: PartCatalogCategory::query()
                ->where('source', $this->source)
                ->whereNull('parent_id')
                ->where('depth', 0)
                ->where('name', $modelLabel)
                ->first();

        $payload = [
            'source' => $this->source,
            'parent_id' => null,
            'preview_image_url' => null,
            'depth' => 0,
            'code' => null,
            'name' => $modelLabel,
            'model_label' => $modelLabel,
            'model_name' => $modelName,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
            'sort_order' => $this->modelSortOrder($modelLabel),
            'children_scanned_at' => now(),
            'products_scanned_at' => now(),
        ];

        if ($category === null) {
            return PartCatalogCategory::query()->create($payload + ['source_url' => $sourceUrl]);
        }

        if (
            $sourceUrl !== null
            && (string) $category->source_url !== (string) $sourceUrl
            && ! PartCatalogCategory::query()->where('source_url', $sourceUrl)->whereKeyNot($category->id)->exists()
        ) {
            $payload['source_url'] = $sourceUrl;
        }

        $category->forceFill($payload)->save();

        return $category;
    }

    protected function storeListingCategory(
        array $entry,
        array $page,
        string $modelLabel,
        string $modelName,
        ?int $yearFrom,
        ?int $yearTo,
        string $baseUrl,
        bool $dryRun
    ): ?PartCatalogCategory {
        if ($dryRun) {
            return null;
        }

        $path = $entry['path'] ?? [];
        $depth = count($path) + 1;
        [$code, $name] = $this->splitCodeName($entry['name']);

        return PartCatalogCategory::query()->updateOrCreate(
            ['source_url' => $this->withLimit($entry['url'])],
            [
                'source' => $this->source,
                'parent_id' => $entry['parent_id'] ?? null,
                'preview_image_url' => $this->mainImageUrl($page, $baseUrl),
                'depth' => $depth,
                'code' => $code,
                'name' => $name,
                'model_label' => $modelLabel,
                'model_name' => $modelName,
                'year_from' => $yearFrom,
                'year_to' => $yearTo,
                'sort_order' => $this->categorySortOrder($code),
                'children_scanned_at' => now(),
                'products_scanned_at' => now(),
            ]
        );
    }

    protected function categoryMatchForProduct(array $index, array $product): ?array
    {
        foreach ($this->productIndexKeys($product) as $key) {
            if (isset($index[$key])) {
                return $index[$key];
            }
        }

        return null;
    }

    protected function productIndexKeys(array $product): array
    {
        return array_values(array_unique(array_filter([
            ($product['url'] ?? null) !== null ? 'url:'.$this->normalizedProductUrl($product['url']) : null,
            ($product['product_id'] ?? null) !== null && $product['product_id'] !== '' ? 'id:'.$product['product_id'] : null,
            ($product['part_number'] ?? null) !== null && $product['part_number'] !== '' ? 'part:'.Str::upper($product['part_number']) : null,
        ])));
    }

    protected function listingCategoryLinks(array $page, string $baseUrl): array
    {
        $links = [];

        foreach ($page['xpath']->query('//*[@id="content"]//*[contains(concat(" ", normalize-space(@class), " "), " refine-categories ")]//a[@href]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $name = $this->clean($node->textContent);
            $name = $this->clean(preg_replace('/(?:\s+|(?<=[\pL)]))\d+$/u', '', $name));

            if (preg_match('/^\d{2,4}\s*-/u', $name) !== 1) {
                continue;
            }

            $url = $this->absoluteUrl($node->getAttribute('href'), $baseUrl);

            if ($url === null || str_contains($url, 'route=product/compare')) {
                continue;
            }

            $url = $this->withLimit($url);
            $key = (string) $this->normalizedProductUrl($url);

            $links[$key] = [
                'url' => $url,
                'name' => $name,
            ];
        }

        return array_values($links);
    }

    protected function categoryPayloadFromMatch(?array $match): array
    {
        if ($match === null) {
            return [];
        }

        $path = $match['path'] ?? [];
        $main = $path[0] ?? null;
        $sub = $path[1] ?? null;
        $node = $path[2] ?? null;

        return array_filter([
            'part_catalog_category_id' => $match['category_id'] ?? null,
            'main_category_code' => $main['code'] ?? null,
            'main_category_name' => $main['name'] ?? null,
            'subcategory_code' => $sub['code'] ?? null,
            'subcategory_name' => $sub['name'] ?? null,
            'node_name' => $node['name'] ?? null,
        ], fn ($value): bool => filled($value));
    }

    protected function categoryPayloadFromListingProduct(array $product): array
    {
        $payload = $this->categoryPayloadFromMatch($product['category_match'] ?? null);

        if ($payload !== []) {
            return $payload;
        }

        return array_filter([
            'part_catalog_category_id' => $product['listing_model_category_id'] ?? null,
        ], fn ($value): bool => filled($value));
    }

    protected function categoryChangesFromListingModel(PartCatalogItem $item, array $product): array
    {
        $categoryId = $product['listing_model_category_id'] ?? null;

        if (! filled($categoryId)) {
            return $this->categoryChangesFromMatch($item, null);
        }

        $changes = [];

        if ((int) $item->part_catalog_category_id !== (int) $categoryId) {
            $changes['part_catalog_category_id'] = $categoryId;
        }

        foreach (['main_category_code', 'main_category_name', 'subcategory_code', 'subcategory_name', 'node_name'] as $column) {
            if (filled($item->{$column})) {
                $changes[$column] = null;
            }
        }

        return $changes;
    }

    protected function categoryChangesFromMatch(PartCatalogItem $item, ?array $match): array
    {
        if ($match === null) {
            $columns = [
                'part_catalog_category_id',
                'main_category_code',
                'main_category_name',
                'subcategory_code',
                'subcategory_name',
                'node_name',
            ];

            return collect($columns)
                ->filter(fn (string $column): bool => filled($item->{$column}))
                ->mapWithKeys(fn (string $column): array => [$column => null])
                ->all();
        }

        $payload = $this->categoryPayloadFromMatch($match);

        if ($payload === []) {
            return [];
        }

        $changes = [];

        foreach ($payload as $column => $value) {
            if ((string) $item->{$column} !== (string) $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    protected function localizedNameChanges(PartCatalogItem $item, string $name): array
    {
        if (filled($item->name_ru) || filled($item->name_ua)) {
            return [];
        }

        $payload = $this->localizedNamePayload($name);

        if ($payload === []) {
            return [];
        }

        $changes = [];

        foreach ($payload as $column => $value) {
            if ($value !== '' && ! filled($item->{$column}) && trim((string) $item->{$column}) !== $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    protected function partOriginChanges(PartCatalogItem $item, ?string $name): array
    {
        $nameOrigin = $this->partOriginFromName($name);
        $partOrigin = $nameOrigin['part_origin'] ?? null;
        $nameCondition = $this->partConditionFromName($nameOrigin['name'] ?? $name);
        $condition = $nameCondition['condition'] ?? null;
        $cleanName = $this->withoutCompatibilityModelNames(
            $nameCondition['name'] ?? ($nameOrigin['name'] ?? null),
            $item->compatibility_text ?: ($item->model_name ?: $item->model_label)
        );
        $changes = [];

        if ($partOrigin !== null || $condition !== null) {
            $changes['raw_attributes'] = [
                'original_name' => $name,
                'part_origin' => $partOrigin,
                'part_origin_label' => $this->partOriginLabel($partOrigin),
            ];
        }

        if ($condition !== null && (string) $item->condition !== $condition) {
            $changes['condition'] = $condition;
        }

        if ($cleanName !== null && $cleanName !== '' && (string) $item->name !== $cleanName) {
            $changes['name'] = $cleanName;
        }

        if (! filled($item->name_ru) && ! filled($item->name_ua)) {
            foreach ($this->localizedNamePayload((string) $cleanName, $name) as $column => $value) {
                if ($value !== '' && ! filled($item->{$column}) && trim((string) $item->{$column}) !== $value) {
                    $changes[$column] = $value;
                }
            }
        }

        return $changes;
    }

    protected function listingProductImageChanges(PartCatalogItem $item, array $product, string $baseUrl): array
    {
        $previewUrl = $product['image_url'] ?? null;

        if ($previewUrl === null || $this->isPlaceholderImageUrl($previewUrl)) {
            return [];
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);

        if (collect((array) ($rawAttributes['image_urls'] ?? []))->filter()->isNotEmpty()) {
            return [];
        }

        if (! $this->isProductImageUrl($previewUrl)) {
            return [];
        }

        $rawAttributes['image_urls'] = [$previewUrl];
        $rawAttributes['listing_image_saved_at'] = now()->toDateTimeString();

        return [
            'raw_attributes' => array_filter($rawAttributes, fn ($value): bool => $value !== null && $value !== ''),
            'source_updated_at' => now(),
        ];
    }

    protected function listingProductMatchedByUrl(PartCatalogItem $item, array $product): bool
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $url = $product['url'] ?? null;
        $productId = $product['product_id'] ?? null;

        foreach (['product_url', 'buy_url', 'listing_product_url'] as $key) {
            $value = $rawAttributes[$key] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($url !== null && $this->normalizedProductUrl($value) === $url) {
                return true;
            }

            if ($productId !== null && $productId !== '' && str_contains($value, 'product_id='.$productId)) {
                return true;
            }
        }

        return false;
    }

    protected function partRows(array $page, string $baseUrl): array
    {
        $rows = [];

        foreach ($page['xpath']->query('//div[contains(concat(" ", normalize-space(@class), " "), " table-responsive ") and contains(concat(" ", normalize-space(@class), " "), " hidden-xs ")]//tbody/tr') as $row) {
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

            $partNumber = strtoupper($cells[2]);
            if (preg_match('/^[A-Z0-9][A-Z0-9-]+$/', $partNumber) !== 1) {
                $partNumber = null;
            }

            $buyLink = $page['xpath']->query('.//a[@href]', $row)->item(0);

            $rows[] = [
                'scheme_number' => is_numeric($cells[0]) ? (int) $cells[0] : null,
                'name' => $cells[1],
                'part_number' => $partNumber,
                'quantity' => $cells[3] !== '' ? $cells[3] : null,
                'buy_url' => $buyLink instanceof DOMElement ? $this->absoluteUrl($buyLink->getAttribute('href'), $baseUrl) : null,
            ];
        }

        return $rows;
    }

    protected function partPayload(?PartCatalogCategory $category, array $context, string $nodeName, array $row): array
    {
        $nameOrigin = $this->partOriginFromName($row['name'] ?? null);
        $nameUaOrigin = $this->partOriginFromName($row['name_ua'] ?? null);
        $nameCondition = $this->partConditionFromName($nameOrigin['name'] ?? ($row['name'] ?? null));
        $nameUaCondition = $this->partConditionFromName($nameUaOrigin['name'] ?? ($row['name_ua'] ?? null));
        $partOrigin = $row['part_origin'] ?? $nameOrigin['part_origin'] ?? $nameUaOrigin['part_origin'];
        $partOriginLabel = $this->partOriginLabel($partOrigin);
        $condition = $row['condition'] ?? $nameCondition['condition'] ?? $nameUaCondition['condition'];
        $name = $this->withoutCompatibilityModelNames($nameCondition['name'] ?? ($nameOrigin['name'] ?? ($row['name'] ?? null)), $context['model_label'] ?? null);
        $nameUa = $this->withoutCompatibilityModelNames($nameUaCondition['name'] ?? ($nameUaOrigin['name'] ?? ($row['name_ua'] ?? null)), $context['model_label'] ?? null);

        return [
            'part_catalog_category_id' => $category?->id,
            'source' => $this->source,
            'part_number' => $row['part_number'] ?? null,
            'name' => $name,
            'name_ua' => $nameUa,
            'notes_ua' => $row['description'] ?? null,
            'scheme_number' => $row['scheme_number'] ?? null,
            'price_amount' => $row['price_amount'] ?? null,
            'currency' => $row['currency'] ?? null,
            'model_label' => $context['model_label'],
            'model_name' => $context['model_name'],
            'year_from' => $context['year_from'],
            'year_to' => $context['year_to'],
            'main_category_code' => $context['main_category_code'],
            'main_category_name' => $context['main_category_name'],
            'subcategory_code' => $context['subcategory_code'],
            'subcategory_name' => $context['subcategory_name'],
            'node_name' => $nodeName,
            'compatibility_text' => $context['model_label'],
            'condition' => $condition,
            'quality' => null,
            'availability' => $row['availability'] ?? null,
            'raw_attributes' => array_filter([
                'quantity' => $row['quantity'] ?? null,
                'buy_url' => $row['buy_url'] ?? null,
                'product_url' => $row['product_url'] ?? null,
                'image_urls' => $row['image_urls'] ?? null,
                'original_name' => $partOrigin !== null || $condition !== null ? ($row['name'] ?? null) : null,
                'part_origin' => $partOrigin,
                'part_origin_label' => $partOriginLabel,
                'schematic_name' => $row['schematic_name'] ?? null,
                'schematic_source_url' => $category?->source_url,
            ]),
            'source_updated_at' => now(),
        ];
    }

    protected function persistPartRow(?PartCatalogCategory $category, array $context, string $nodeName, string $nodeUrl, array $row): PartCatalogItem
    {
        $sourceUrl = $this->schematicRowUrl($nodeUrl, $row);
        $payload = $this->partPayload($category, $context, $nodeName, $row);
        $item = $this->existingItemForProductUrl($row)
            ?: PartCatalogItem::query()
                ->where('source', $this->source)
                ->where('source_url', $sourceUrl)
                ->first();

        if ($item === null) {
            return PartCatalogItem::query()->create([
                'source_url' => $sourceUrl,
                ...$payload,
            ]);
        }

        $item->fill($this->preserveExistingNames($item, [
            'source_url' => $sourceUrl,
            ...$payload,
        ]));
        $item->save();

        return $item;
    }

    protected function preserveExistingNames(PartCatalogItem $item, array $changes): array
    {
        foreach (['name', 'name_en', 'name_ru', 'name_ua'] as $column) {
            if (array_key_exists($column, $changes) && filled($item->{$column})) {
                unset($changes[$column]);
            }
        }

        return $changes;
    }

    protected function existingItemForProductUrl(array $row): ?PartCatalogItem
    {
        $productUrl = $this->stableProductUrl($row);

        if ($productUrl === null) {
            return null;
        }

        return PartCatalogItem::query()
            ->where('source', $this->source)
            ->where(function ($query) use ($productUrl): void {
                $query
                    ->where('raw_attributes->product_url', $productUrl)
                    ->orWhere('raw_attributes->buy_url', $productUrl);
            })
            ->orderBy('id')
            ->first();
    }

    protected function stableProductUrl(array $row): ?string
    {
        $url = trim((string) ($row['product_url'] ?? $row['buy_url'] ?? ''));

        if (
            ! Str::startsWith($url, ['http://', 'https://'])
            || str_contains($url, 'route=tesla/catalog/product')
        ) {
            return null;
        }

        return $url;
    }

    protected function enrichedPartRow(array $row, string $baseUrl): array
    {
        $buyUrl = (string) ($row['buy_url'] ?? '');

        if (
            ! Str::startsWith($buyUrl, ['http://', 'https://'])
            || str_contains($buyUrl, 'route=product/search')
            || str_contains($buyUrl, 'route=tesla/catalog/product')
        ) {
            return $row;
        }

        $html = $this->fetch($buyUrl);
        if ($html === null) {
            return $row;
        }

        $page = $this->page($html);
        $text = $this->clean($page['document']->textContent);
        $title = $this->headline($page);
        $partNumber = $this->productTextValue($text, 'Артикул');

        $nameOrigin = $this->partOriginFromName($title ?: ($row['name'] ?? null));
        $nameCondition = $this->partConditionFromName($nameOrigin['name'] ?? ($title ?: ($row['name'] ?? null)));

        return array_filter([
            ...$row,
            'schematic_name' => $row['name'] ?? null,
            'name' => $nameCondition['name'] ?? ($nameOrigin['name'] ?? ($title ?: ($row['name'] ?? null))),
            'name_ua' => $title !== null ? ($nameCondition['name'] ?? ($nameOrigin['name'] ?? $title)) : null,
            'part_origin' => $nameOrigin['part_origin'] ?? null,
            'condition' => $nameCondition['condition'] ?? null,
            'part_number' => $partNumber ?: ($row['part_number'] ?? null),
            'availability' => $this->productTextValue($text, 'Наявність'),
            'price_amount' => $this->priceAmount($text),
            'currency' => $this->priceAmount($text) !== null ? 'USD' : null,
            'description' => $this->productDescription($page),
            'image_urls' => $this->productImageUrls($page, $baseUrl)->all(),
            'product_url' => $buyUrl,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    protected function partOriginFromName(?string $name): array
    {
        $name = $this->clean($name);

        if ($name === '') {
            return ['name' => null, 'part_origin' => null];
        }

        $partOrigin = match (true) {
            preg_match('/(?<![\pL\pN])аналог(?![\pL\pN])/iu', $name) === 1 => 'analog',
            preg_match('/(?<![\pL\pN])(оригінал|оригинал)(?![\pL\pN])/iu', $name) === 1 => 'original',
            default => null,
        };

        $cleanName = preg_replace('/(?<![\pL\pN])(?:аналог|оригінал|оригинал)(?![\pL\pN])/iu', '', $name);
        $cleanName = $this->clean(preg_replace('/\s+([,.;:)])/u', '$1', (string) $cleanName));
        $cleanName = $this->clean(preg_replace('/([(])\s+/u', '$1', $cleanName));

        return [
            'name' => $cleanName !== '' ? $cleanName : $name,
            'part_origin' => $partOrigin,
        ];
    }

    protected function partConditionFromName(?string $name): array
    {
        $name = $this->clean($name);

        if ($name === '') {
            return ['name' => null, 'condition' => null];
        }

        $usedConditionPattern = '/(?<![\pL\pN])(?:БВ|Б\s*\/?\s*У)(?![\pL\pN])/u';
        $condition = preg_match($usedConditionPattern, $name) === 1 ? 'Б/У' : null;
        $cleanName = preg_replace($usedConditionPattern, '', $name);
        $cleanName = $this->clean(preg_replace('/\s+([,.;:)])/u', '$1', (string) $cleanName));
        $cleanName = $this->clean(preg_replace('/([(])\s+/u', '$1', $cleanName));

        return [
            'name' => $cleanName !== '' ? $cleanName : $name,
            'condition' => $condition,
        ];
    }

    protected function withoutListingModelName(?string $name, ?string $modelName): ?string
    {
        return $this->withoutCompatibilityModelNames($name, $modelName);
    }

    public function withoutCompatibilityModelNames(?string $name, ?string $compatibilityText): ?string
    {
        $name = $this->clean($name);

        if ($name === '') {
            return null;
        }

        $compatibilityText = Str::lower($this->clean($compatibilityText));
        if ($compatibilityText === '') {
            return $name;
        }

        $patterns = [];

        foreach (['s', '3', 'x', 'y'] as $model) {
            if (str_contains($compatibilityText, 'model '.$model)) {
                $patterns[] = '/(?<![\pL\pN])(?:tesla\s+)?model\s*'.preg_quote($model, '/').'(?![\pL\pN])/iu';
            }
        }

        if ($patterns === []) {
            return $name;
        }

        $cleanName = preg_replace($patterns, '', $name);
        $cleanName = $this->clean(preg_replace('/\s+([,.;:)])/u', '$1', (string) $cleanName));
        $cleanName = $this->clean(preg_replace('/([(])\s+/u', '$1', $cleanName));

        return $cleanName !== '' ? $cleanName : $name;
    }

    protected function partOriginLabel(?string $partOrigin): ?string
    {
        return match ($partOrigin) {
            'original' => 'Оригинал',
            'analog' => 'Аналог',
            default => null,
        };
    }

    protected function productTextValue(string $text, string $label): ?string
    {
        if ($label === 'Артикул') {
            return preg_match('/Артикул:\s*([A-Z0-9][A-Z0-9-]+)/u', $text, $matches) === 1
                ? $this->clean($matches[1])
                : null;
        }

        if ($label === 'Наявність') {
            return preg_match('/Наявність:\s*(.+?)\s+Артикул:/u', $text, $matches) === 1
                ? $this->clean($matches[1])
                : null;
        }

        return preg_match('/'.$label.':\s*(.+?)(?=\s+(?:Наявність|Артикул|Купити|Кількість|Опис|[0-9]+(?:[.,][0-9]+)?\s*\$)\b|$)/u', $text, $matches) === 1
            ? $this->clean($matches[1])
            : null;
    }

    protected function priceAmount(string $text): ?float
    {
        if (preg_match_all('/(\d+(?:[.,]\d{2})?)\s*\$/u', $text, $matches) < 1) {
            return null;
        }

        $prices = collect($matches[1])
            ->map(fn (string $value): float => round((float) str_replace(',', '.', $value), 2))
            ->filter(fn (float $value): bool => $value > 0)
            ->values();

        return $prices->isNotEmpty() ? $prices->last() : null;
    }

    protected function productDescription(array $page): ?string
    {
        $description = $page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " tab-pane ") and @id="tab-description"]')->item(0);

        return $description !== null ? $this->clean($description->textContent) : null;
    }

    protected function productImageUrls(array $page, string $baseUrl): Collection
    {
        $urls = collect();

        foreach ($page['xpath']->query('//meta[@property="og:image" or @name="twitter:image"]/@content') as $node) {
            $urls->push($node->nodeValue);
        }

        foreach ($page['xpath']->query('//*[@data-largeimg or @data-zoom-image]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $urls->push($node->getAttribute('data-largeimg'));
            $urls->push($node->getAttribute('data-zoom-image'));
        }

        foreach ($page['xpath']->query('//a[@href]') as $node) {
            if ($node instanceof DOMElement) {
                $urls->push($node->getAttribute('href'));
            }
        }

        foreach ($page['xpath']->query('//img[@src or @srcset]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $urls->push($node->getAttribute('src'));

            foreach (explode(',', $node->getAttribute('srcset')) as $srcset) {
                $urls->push(trim((string) preg_replace('/\s+\d+[wx]$/', '', trim($srcset))));
            }
        }

        return $urls
            ->map(fn (?string $url): ?string => $this->absoluteUrl((string) $url, $baseUrl))
            ->filter(fn (?string $url): bool => is_string($url) && $this->isProductImageUrl($url))
            ->unique()
            ->values();
    }

    protected function isProductImageUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if (preg_match('/\.(?:jpe?g|png|webp)(?:$|\?)/i', $url) !== 1) {
            return false;
        }

        if (! str_contains($path, '/1c_image/') && ! str_contains($path, '/image/')) {
            return false;
        }

        return ! Str::contains(Str::lower($path), [
            'logo',
            'placeholder',
            'tesla_logo',
            'favicon',
            '/cache/catalog/categories/',
        ]);
    }

    protected function isPlaceholderImageUrl(string $url): bool
    {
        return Str::contains(Str::lower(parse_url($url, PHP_URL_PATH) ?: ''), [
            'placeholder',
            'no_image',
            'no-image',
        ]);
    }

    protected function productUrlForItem(PartCatalogItem $item): ?string
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        foreach (['product_url', 'buy_url', 'listing_product_url'] as $key) {
            $url = trim((string) ($rawAttributes[$key] ?? ''));

            if (Str::startsWith($url, ['http://', 'https://']) && ! str_contains($url, 'route=tesla/catalog/product')) {
                return $url;
            }
        }

        return null;
    }

    protected function schematicRowUrl(string $nodeUrl, array $row): string
    {
        return $nodeUrl.'#'.md5(implode('|', [
            $row['scheme_number'] ?? '',
            $row['name'] ?? '',
            $row['part_number'] ?? '',
            $row['quantity'] ?? '',
        ]));
    }

    protected function mainImageUrl(array $page, string $baseUrl): ?string
    {
        $image = $page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " last_category_image ")]//img[@src]')->item(0);

        return $image instanceof DOMElement ? $this->absoluteUrl($image->getAttribute('src'), $baseUrl) : null;
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

    protected function canonicalModel(?string $label): array
    {
        $label = $this->clean($label);

        [$modelName, $yearFrom, $yearTo] = $this->modelYears($label);

        return [$label, $modelName, $yearFrom, $yearTo];
    }

    protected function modelYears(?string $label): array
    {
        if ($label === null) {
            return [null, null, null];
        }

        $modelName = $this->clean(preg_replace('/\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{4}.*$/iu', '', $label));
        $yearFrom = null;
        $yearTo = null;

        if (preg_match('/(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{4})(?:\s*-\s*(?:(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+)?(\d{4}))?/iu', $label, $matches) === 1) {
            $yearFrom = (int) $matches[1];
            $yearTo = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null;
        }

        return [$modelName, $yearFrom, $yearTo];
    }

    protected function modelSortOrder(string $label): int
    {
        $order = [
            'Model S' => 10,
            'Model X' => 20,
            'Model 3' => 30,
            'Model Y' => 40,
        ];

        foreach ($order as $prefix => $value) {
            if (str_starts_with($label, $prefix)) {
                return $value + (int) (preg_match('/(\d{4})/', $label, $matches) ? $matches[1] : 0);
            }
        }

        return 9999;
    }

    protected function categorySortOrder(?string $code): int
    {
        return $code === null ? 0 : (int) $code;
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
            ->value('name') ?: $fallback;
    }

    protected function clean(?string $value): string
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = $this->repairMojibake($value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    protected function repairMojibake(string $value): string
    {
        for ($i = 0; $i < 3 && $this->looksLikeMojibake($value); $i++) {
            $bytes = $this->looksLikeLatinMojibake($value)
                ? iconv('UTF-8', 'Windows-1252//IGNORE', $value)
                : $this->mojibakeBytes($value);

            if ($bytes !== false && $bytes !== null && $bytes !== '' && ! mb_check_encoding($bytes, 'UTF-8')) {
                while ($bytes !== '' && ! mb_check_encoding($bytes, 'UTF-8')) {
                    $bytes = substr($bytes, 0, -1);
                }
            }

            if ($bytes === false || $bytes === null || $bytes === '' || ! mb_check_encoding($bytes, 'UTF-8')) {
                break;
            }

            $decoded = $bytes;
            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        return $value;
    }

    protected function looksLikeMojibake(string $value): bool
    {
        preg_match_all('/[РС][\x{0080}-\x{00BF}\x{0400}-\x{040F}\x{0450}-\x{045F}\x{2010}-\x{203A}]|в[\x{0080}-\x{00BF}\x{2010}-\x{203A}]|В[·№]/u', $value, $matches);

        return count($matches[0]) >= 2
            || preg_match('/[\x{0080}-\x{009F}]|в[\x{0080}-\x{00BF}\x{2010}-\x{203A}]|В[·№]|[ÐÑ][\x{0080}-\x{00BF}\x{2010}-\x{203A}]/u', $value) === 1;
    }

    protected function looksLikeLatinMojibake(string $value): bool
    {
        return preg_match('/[ÐÑ][\x{0080}-\x{00BF}\x{2010}-\x{203A}]/u', $value) === 1;
    }

    protected function mojibakeBytes(string $value): ?string
    {
        $bytes = '';

        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $codepoint = mb_ord($char, 'UTF-8');

            if ($codepoint >= 0x80 && $codepoint <= 0x9F) {
                $bytes .= chr($codepoint);

                continue;
            }

            $encoded = iconv('UTF-8', 'Windows-1251//IGNORE', $char);
            if ($encoded === false) {
                return null;
            }

            $bytes .= $encoded;
        }

        return $bytes;
    }

    protected function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return strtok($url, '#');
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.strtok($url, '#');
        }

        if (str_starts_with($url, '/')) {
            return $baseUrl.strtok($url, '#');
        }

        return $baseUrl.'/'.ltrim((string) strtok($url, '#'), '/');
    }

    protected function normalizedProductUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        unset($query['limit']);

        $normalized = $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '');

        if ($query !== []) {
            $normalized .= '?'.http_build_query($query);
        }

        return $normalized;
    }

    protected function withLimit(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        $query['limit'] = 10000;

        return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '').'?'.http_build_query($query);
    }

    protected function productIdFromUrl(?string $url): ?string
    {
        parse_str(parse_url((string) $url, PHP_URL_QUERY) ?: '', $query);

        return isset($query['product_id']) ? (string) $query['product_id'] : null;
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
