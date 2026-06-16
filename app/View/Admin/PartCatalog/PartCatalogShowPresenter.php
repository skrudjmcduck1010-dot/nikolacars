<?php

namespace App\View\Admin\PartCatalog;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\PartCatalogDisplayService;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PartCatalogShowPresenter
{
    public function __construct(
        protected PartCatalogIndexPresenter $indexPresenter,
        protected PartCatalogImagePresenter $imagePresenter,
        protected PartCatalogDisplayService $catalogDisplay,
    ) {}

    public function localizedNameConflictText(PartCatalogItem $item, string $locale): string
    {
        return $this->indexPresenter->localizedNameConflictText($item, $locale);
    }

    public function nameBadge(?string $value): array
    {
        return [
            'text' => trim((string) $value),
            'is_auto' => false,
        ];
    }

    public function undeterminedNameBadge(PartCatalogItem $item): array
    {
        if (
            ! in_array($item->source, ['teslapartsukraine', 'tsk'], true)
            || trim((string) $item->name) === ''
            || preg_match('/\p{Cyrillic}/u', (string) $item->name) !== 1
            || trim((string) $item->name_ru) !== ''
            || trim((string) $item->name_ua) !== ''
        ) {
            return $this->nameBadge('');
        }

        return $this->nameBadge($item->name);
    }

    public function localizedNameBadges(PartCatalogItem $item): array
    {
        $nameRuBadge = $this->nameBadge($item->name_ru);
        $nameUaBadge = $this->nameBadge($item->name_ua);

        if ($item->source === 'teslapartsukraine') {
            $nameRuBadge['text'] = $this->stripTeslaPartsUkraineOrigin($item, $nameRuBadge['text']);
            $nameUaBadge['text'] = $this->stripTeslaPartsUkraineOrigin($item, $nameUaBadge['text']);
        }

        return [
            'ru' => $nameRuBadge,
            'ua' => $nameUaBadge,
            'undetermined' => $this->undeterminedNameBadge($item),
        ];
    }

    public function localizedNameConflicts(PartCatalogItem $item): array
    {
        return [
            'ru' => $this->localizedNameConflictText($item, 'ru'),
            'ua' => $this->localizedNameConflictText($item, 'ua'),
        ];
    }

    public function catalogCategoryPath(PartCatalogItem $item): string
    {
        $parts = collect();
        $category = $item->category;

        while ($category) {
            if ((int) $category->depth > 0 || $category->parent_id !== null) {
                $parts->prepend($this->catalogCategoryName($category));
            }

            $category = $category->parent;
        }

        $localizedPath = $parts->filter()->implode(' / ');

        return $localizedPath !== ''
            ? $localizedPath
            : collect([$item->main_category_name, $item->subcategory_name, $item->node_name])->filter()->implode(' / ');
    }

    public function isInvalidNikolaCarsPartNumber(PartCatalogItem $item): bool
    {
        return $item->source === 'nikolacars'
            && $this->indexPresenter->isInvalidNikolaCarsPartNumber($item->part_number);
    }

    public function isNikolaCarsSoldItem(PartCatalogItem $item): bool
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        return $item->source === 'nikolacars'
            && (
                data_get($rawAttributes, 'manual_sold_at')
                || data_get($rawAttributes, 'storage_status') === Product::STORAGE_STATUS_SOLD
                || $item->sales->where('source', 'nikolacars')->isNotEmpty()
            );
    }

    public function schemeNumberBadge(PartCatalogItem $item): string
    {
        return trim((string) ($item->scheme_number ?: data_get(PartCatalogRawAttributes::from($item), 'annotation')));
    }

    public function availabilitySourceLabel(array $catalog): string
    {
        return trim((string) preg_replace('/^Источник\s+/u', '', (string) ($catalog['source_label'] ?? '')));
    }

    public function hasTeslaExactPresence(PartCatalogItem $item): bool
    {
        return in_array((string) data_get(PartCatalogRawAttributes::from($item), 'official_presence'), ['part_search_exact', 'official_catalog_exact'], true);
    }

    public function findPartFoundByRequestedPartNumbers(PartCatalogItem $item): Collection
    {
        return collect(data_get(PartCatalogRawAttributes::from($item), 'find_part_found_by_requested_part_numbers', []))
            ->filter(fn (mixed $partNumber): bool => filled($partNumber))
            ->values();
    }

    public function officialPartMatchStatus(PartCatalogItem $item): string
    {
        return (string) data_get(PartCatalogRawAttributes::from($item), 'official_part_match_status');
    }

    public function teslaPartSearchSimilarPartNumbersText(PartCatalogItem $item): string
    {
        return collect(data_get(PartCatalogRawAttributes::from($item), 'tesla_part_search_similar_part_numbers', []))
            ->filter(fn (mixed $partNumber): bool => filled($partNumber))
            ->implode(', ');
    }

    public function teslaPartSearchRequestedPartNumber(PartCatalogItem $item): string
    {
        return trim((string) data_get(PartCatalogRawAttributes::from($item), 'tesla_part_search_requested_part_number'));
    }

    public function teslaOfficialPresence(PartCatalogItem $item): string
    {
        return (string) data_get(PartCatalogRawAttributes::from($item), 'official_presence');
    }

    public function teslaPartSearchCheckedAt(PartCatalogItem $item): string
    {
        return trim((string) data_get(PartCatalogRawAttributes::from($item), 'tesla_part_search_checked_at'));
    }

    public function sourceUrls(PartCatalogItem $item, array $catalog, ?string $sourceUrl): Collection
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $sourceUrls = ($catalog['source'] ?? null) === 'tesla_official'
            ? collect([$sourceUrl])
            : collect(data_get($rawAttributes, 'source_urls', []))
                ->merge(data_get($rawAttributes, 'product_source_urls', []))
                ->push($sourceUrl);

        return $sourceUrls
            ->filter(fn (mixed $url): bool => is_string($url) && Str::startsWith($url, ['http://', 'https://']))
            ->map(fn (string $url): string => rtrim($url, '/').'/')
            ->unique()
            ->values();
    }

    public function tskHasNoProductPage(PartCatalogItem $item, ?string $sourceUrl): bool
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        return $item->source === 'tsk'
            && Str::startsWith((string) $item->source_url, 'tsk-epc:')
            && ! Str::startsWith((string) data_get($rawAttributes, 'product_url'), ['http://', 'https://'])
            && trim((string) $sourceUrl) !== '';
    }

    public function drivePartsTeslaActualPartNumber(PartCatalogItem $item): string
    {
        return $item->source === 'driveparts'
            ? trim((string) data_get(PartCatalogRawAttributes::from($item), 'tesla_actual_part_number'))
            : '';
    }

    public function imageUrls(PartCatalogItem $item, array $catalog): Collection
    {
        $source = (string) ($catalog['source'] ?? '');
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $localImageUrls = collect((array) data_get($rawAttributes, 'image_urls', []))
            ->merge((array) data_get($rawAttributes, 'part_image_urls', []))
            ->when(data_get($rawAttributes, 'image_url'), fn (Collection $collection, mixed $url): Collection => $collection->push($url))
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->reject(fn (string $url): bool => Str::startsWith($url, ['http://', 'https://']));

        $imageUrls = $source === 'tesla_official'
            ? collect((array) data_get($rawAttributes, 'part_image_urls', []))
                ->when(data_get($rawAttributes, 'image_url'), fn (Collection $collection, mixed $url): Collection => $collection->push($url))
            : ($localImageUrls->isNotEmpty()
                ? $localImageUrls
                : collect()
                    ->merge((array) data_get($rawAttributes, 'remote_image_urls', []))
                    ->when(data_get($rawAttributes, 'image_url'), fn (Collection $collection, mixed $url): Collection => $collection->push($url))
                    ->when(data_get($rawAttributes, 'remote_image_url'), fn (Collection $collection, mixed $url): Collection => $collection->push($url))
                    ->when(data_get($rawAttributes, 'primary_image_url'), fn (Collection $collection, mixed $url): Collection => $collection->push($url)));

        return $imageUrls
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => $source === 'driveparts' && $this->isDrivePartsPlaceholderImageReference($url)
                ? $this->drivePartsPlaceholderImageUrl()
                : $this->catalogImageUrl($url))
            ->reject(fn (string $url): bool => $source === 'tesla_official' && $this->isTeslaOfficialSchemeImageUrl($url))
            ->reject(fn (string $url): bool => $this->isPlaceholderImageUrl($url))
            ->unique(fn (string $url): string => $this->catalogImageIdentityKey($url))
            ->values()
            ->pipe(fn (Collection $imageUrls): Collection => $source === 'driveparts' && $imageUrls->contains(fn (string $url): bool => $this->isDrivePartsSharedPlaceholderImageUrl($url))
                ? collect([$this->drivePartsPlaceholderImageUrl()])
                : $imageUrls);
    }

    public function schemeImageUrls(PartCatalogItem $item, array $catalog, ?Collection $teslaOfficialOccurrenceCategories): Collection
    {
        if (($catalog['source'] ?? null) !== 'tesla_official') {
            return collect();
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);

        return collect((array) data_get($rawAttributes, 'system_group_image_urls', []))
            ->merge((array) data_get($rawAttributes, 'image_urls', []))
            ->merge(($teslaOfficialOccurrenceCategories ?? collect())->pluck('preview_image_url'))
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->filter(fn (string $url): bool => $this->isTeslaOfficialSchemeImageUrl($url))
            ->map(fn (string $url): string => $this->catalogImageUrl($url))
            ->unique(fn (string $url): string => $this->teslaSchemeImageKey($url))
            ->unique(fn (string $url): string => $this->teslaSchemeImageContentKey($url))
            ->values();
    }

    public function lightboxImageUrls(Collection $imageUrls, Collection $schemeImageUrls): Collection
    {
        return $imageUrls
            ->merge($schemeImageUrls)
            ->unique()
            ->values();
    }

    public function catalogNameSource(
        PartCatalogItem $item,
        array $catalog,
        ?string $sourceUrl,
        array $nameRuBadge,
        array $nameUaBadge,
        array $undeterminedNameBadge,
        array $localizedNameSources,
    ): array {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $nameRuManual = (bool) (data_get($item, 'name_ru_manually_locked_at') || data_get($rawAttributes, 'manual_name_locks.ru'));
        $nameUaManual = (bool) (data_get($item, 'name_ua_manually_locked_at') || data_get($rawAttributes, 'manual_name_locks.ua'));
        $hasManualName = $nameRuManual || $nameUaManual;
        $hasVisibleCatalogNameSource = ($nameRuBadge['text'] !== '' && ! $nameRuManual)
            || ($nameUaBadge['text'] !== '' && ! $nameUaManual)
            || $undeterminedNameBadge['text'] !== '';

        $catalogNameSourceUrl = $hasVisibleCatalogNameSource
            ? collect([
                $nameRuManual ? null : data_get($rawAttributes, 'name_source_url_ru'),
                $nameUaManual ? null : data_get($rawAttributes, 'name_source_url_ua'),
                $hasManualName ? null : data_get($rawAttributes, 'name_source_url'),
                data_get($rawAttributes, 'product_url'),
                data_get($rawAttributes, 'listing_product_url'),
                $sourceUrl,
            ])->first(fn (mixed $value): bool => filled($value))
            : null;

        $catalogNameSourceSite = $hasVisibleCatalogNameSource
            ? collect([
                $nameRuManual ? null : data_get($rawAttributes, 'name_source_site_ru'),
                $nameUaManual ? null : data_get($rawAttributes, 'name_source_site_ua'),
                $hasManualName ? null : data_get($rawAttributes, 'name_source_site'),
                $catalog['source_label'] ?? null,
            ])->first(fn (mixed $value): bool => filled($value))
            : null;

        if (! is_string($catalogNameSourceUrl) || ! Str::startsWith($catalogNameSourceUrl, ['http://', 'https://'])) {
            $catalogNameSourceUrl = null;
        }

        $catalogNameSourceLabel = 'Источник '.(trim((string) preg_replace('/^Источник\s+/u', '', (string) $catalogNameSourceSite)) ?: 'TeslaPartsUkraine');

        return [
            'url' => $catalogNameSourceUrl,
            'site' => $catalogNameSourceSite,
            'label' => $catalogNameSourceLabel,
            'manual' => [
                'ru' => $nameRuManual,
                'ua' => $nameUaManual,
            ],
            'show_for' => [
                'ru' => $this->shouldShowCatalogNameSource('ru', $catalogNameSourceUrl, $localizedNameSources, $nameRuManual, $nameUaManual),
                'ua' => $this->shouldShowCatalogNameSource('ua', $catalogNameSourceUrl, $localizedNameSources, $nameRuManual, $nameUaManual),
            ],
        ];
    }

    public function characteristics(PartCatalogItem $item): Collection
    {
        return collect(data_get(PartCatalogRawAttributes::from($item), 'characteristics', []))
            ->filter(fn (mixed $value): bool => filled($value));
    }

    public function colorValue(PartCatalogItem $item): mixed
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        return collect([
            data_get($rawAttributes, 'characteristics.Цвет'),
            data_get($rawAttributes, 'characteristics.Колір'),
            data_get($rawAttributes, 'prom.attributes.Цвет'),
            data_get($rawAttributes, 'prom.attributes.Колір'),
        ])->filter(fn (mixed $value): bool => filled($value))->first();
    }

    public function partTypeValue(PartCatalogItem $item, ?callable $itemPartType = null): mixed
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        return collect([
            $itemPartType ? $itemPartType($item) : null,
            data_get($rawAttributes, 'part_origin_label'),
            data_get($rawAttributes, 'part_type'),
            data_get($rawAttributes, 'partType'),
            data_get($rawAttributes, 'product_type'),
            data_get($rawAttributes, 'spare_part_type'),
            data_get($rawAttributes, 'Тип запчасти'),
            data_get($rawAttributes, 'Тип запчастини'),
            data_get($rawAttributes, 'Тип товару'),
            data_get($rawAttributes, 'Тип товара'),
            data_get($rawAttributes, 'Вид запчасти'),
            data_get($rawAttributes, 'Вид запчастини'),
            data_get($rawAttributes, 'characteristics.Тип запчасти'),
            data_get($rawAttributes, 'characteristics.Тип запчастини'),
            data_get($rawAttributes, 'characteristics.Тип товару'),
            data_get($rawAttributes, 'characteristics.Тип товара'),
            data_get($rawAttributes, 'prom.attributes.Тип запчасти'),
            data_get($rawAttributes, 'prom.attributes.Тип запчастини'),
            data_get($rawAttributes, 'prom.attributes.Тип товару'),
            data_get($rawAttributes, 'prom.attributes.Тип товара'),
        ])->filter(fn (mixed $value): bool => filled($value))->first();
    }

    public function compatibilitySummary(
        PartCatalogItem $item,
        array $catalog,
        callable $modelLabel,
        Collection $nikolaCarsDonorCarsByVin,
    ): array {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $source = (string) ($catalog['source'] ?? '');
        $donorVin = $source === 'nikolacars'
            ? Str::upper(trim((string) data_get($rawAttributes, 'donor_vin', '')))
            : '';
        $versionRestriction = collect([
            $item->notes_en,
            data_get($rawAttributes, 'notes'),
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->first();

        return [
            'label' => match ($source) {
                'nikolacars' => 'Снято с донора',
                'teslapartsukraine' => 'Модели Tesla',
                default => 'Совместимость',
            },
            'text' => $modelLabel($item->compatibility_text) ?: $modelLabel($item),
            'donor_vin' => $donorVin,
            'donor_car' => $donorVin !== '' ? $nikolaCarsDonorCarsByVin->get($donorVin) : null,
            'version_restriction' => $versionRestriction,
            'show_version_restriction' => $source !== 'nikolacars' && filled($versionRestriction),
        ];
    }

    public function priceSummary(PartCatalogItem $item, array $usdRate, callable $priceSource): array
    {
        $source = $priceSource($item);
        $sourceUrl = $source['url'] ?? null;

        return [
            'has_price' => $item->price_amount !== null,
            'amount_usd' => $item->price_amount !== null ? $item->priceAmountUsd($usdRate) : null,
            'amount_uah' => $item->price_amount !== null ? $item->priceAmountUah($usdRate) : null,
            'source_url' => is_string($sourceUrl) && trim($sourceUrl) !== '' ? $sourceUrl : null,
            'source_label' => (string) ($source['label'] ?? ''),
            'rate_label' => (string) ($usdRate['label'] ?? ''),
        ];
    }

    public function nikolaCarsRelatedRows(
        Collection $items,
        PartCatalogItem $currentItem,
        array $usdRate,
        callable $priceSource,
        callable $itemName,
        Collection $donorCarsByVin,
    ): Collection {
        return $items
            ->map(function (PartCatalogItem $relatedItem) use ($currentItem, $usdRate, $priceSource, $itemName, $donorCarsByVin): array {
                $rawAttributes = PartCatalogRawAttributes::from($relatedItem);
                $stock = data_get($rawAttributes, 'stock_quantity');
                $stockValue = $stock !== null && $stock !== '' ? (float) $stock : 0.0;
                $priceUsd = $relatedItem->priceAmountUsd($usdRate);
                $donorVin = Str::upper(trim((string) data_get($rawAttributes, 'donor_vin', '')));
                $priceSourceData = $priceSource($relatedItem);
                $priceSourceUrl = $priceSourceData['url'] ?? null;

                return [
                    'item' => $relatedItem,
                    'is_current' => $relatedItem->is($currentItem),
                    'code' => data_get($rawAttributes, 'code') ?: '-',
                    'name' => $itemName($relatedItem),
                    'donor_vin' => $donorVin,
                    'donor_car' => $donorVin !== '' ? $donorCarsByVin->get($donorVin) : null,
                    'location' => $donorVin
                        ?: data_get($rawAttributes, 'category_display')
                        ?: data_get($rawAttributes, 'category_path')
                        ?: '-',
                    'price_usd' => $priceUsd,
                    'price_source_url' => is_string($priceSourceUrl) && trim($priceSourceUrl) !== '' ? $priceSourceUrl : null,
                    'price_source_label' => (string) ($priceSourceData['label'] ?? ''),
                    'stock_text' => rtrim(rtrim(number_format($stockValue, 3, '.', ''), '0'), '.'),
                    'value_usd' => $priceUsd !== null ? $priceUsd * $stockValue : null,
                ];
            })
            ->values();
    }

    public function info(PartCatalogItem $item): Collection
    {
        return collect(data_get(PartCatalogRawAttributes::from($item), 'info', []))
            ->filter(fn (mixed $value): bool => filled($value));
    }

    public function extraRows(PartCatalogItem $item): Collection
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        return collect([
            'Goods ID' => data_get($rawAttributes, 'goods_id'),
            html_entity_decode('&#1040;&#1082;&#1090;&#1091;&#1072;&#1083;&#1100;&#1085;&#1099;&#1081; &#1087;&#1072;&#1088;&#1090;-&#1085;&#1086;&#1084;&#1077;&#1088; Tesla') => data_get($rawAttributes, 'tesla_actual_part_number'),
            html_entity_decode('&#1040;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083; DriveParts') => data_get($rawAttributes, 'driveparts_sku'),
            'Код НиколаКарз' => data_get($rawAttributes, 'code'),
            'Штрихкод' => data_get($rawAttributes, 'barcode'),
            'VIN донора' => data_get($rawAttributes, 'donor_vin'),
            'Донор' => data_get($rawAttributes, 'donor_label'),
            'Остаток' => data_get($rawAttributes, 'stock_quantity'),
            $this->categoryPathLabel($item) => data_get($rawAttributes, 'category_path'),
            'Отображать в списке' => data_get($rawAttributes, 'category_display'),
            'Категория TeslaCompany' => data_get($rawAttributes, 'category'),
            'Цена на сайте' => data_get($rawAttributes, 'price_text'),
            'Кнопка' => data_get($rawAttributes, 'button_text'),
            'Производитель' => data_get($rawAttributes, 'manufacturer'),
            'Дата публикации' => data_get($rawAttributes, 'publication_date'),
        ])->filter(fn (mixed $value): bool => filled($value));
    }

    public function teslaOfficialNameWithAnnotation(PartCatalogItem $item, ?string $name): string
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $name = trim((string) $name);
        $annotation = trim((string) data_get($rawAttributes, 'annotation'));

        if ($name === '' || $annotation === '' || ! ctype_digit($annotation)) {
            return $name;
        }

        return preg_match('/^'.preg_quote($annotation, '/').'\s*\./', $name) === 1
            ? $name
            : "{$annotation}. {$name}";
    }

    public function teslaResults(PartCatalogItem $item, mixed $teslaRelatedFindPartResults = null): Collection
    {
        $teslaPartSearchResults = $this->teslaPartSearchResults($item, $teslaRelatedFindPartResults);

        return $teslaPartSearchResults->isNotEmpty()
            ? $teslaPartSearchResults
            : $this->teslaOfficialCatalogResults($item);
    }

    public function teslaResultsFromSavedCatalog(PartCatalogItem $item, mixed $teslaRelatedFindPartResults = null): bool
    {
        return $this->teslaPartSearchResults($item, $teslaRelatedFindPartResults)->isEmpty()
            && $this->teslaOfficialCatalogResults($item)->isNotEmpty();
    }

    public function teslaFoundRequestLinks(Collection $partNumbers, array $itemIds): Collection
    {
        return $partNumbers
            ->map(function (mixed $partNumber) use ($itemIds): array {
                $partNumber = (string) $partNumber;

                return [
                    'part_number' => $partNumber,
                    'item_id' => $itemIds[$partNumber] ?? null,
                ];
            })
            ->values();
    }

    public function teslaResultRows(Collection $results, array $itemIds): Collection
    {
        return $results
            ->map(function (mixed $result) use ($itemIds): array {
                $partNumber = (string) data_get($result, 'part_number');

                return [
                    'part_number' => $partNumber,
                    'item_id' => $itemIds[$partNumber] ?? null,
                    'description' => data_get($result, 'description') ?: '',
                    'visibility' => (string) data_get($result, 'visibility'),
                    'localized_description' => data_get($result, 'localized_description') ?: '',
                    'model' => data_get($result, 'model') ?: '',
                    'category' => data_get($result, 'category') ?: '',
                    'subcategory' => data_get($result, 'subcategory') ?: '',
                    'group' => data_get($result, 'group') ?: '',
                ];
            })
            ->values();
    }

    public function teslaFindPartUrl(PartCatalogItem $item): ?string
    {
        $searchTerm = trim((string) (data_get(PartCatalogRawAttributes::from($item), 'tesla_part_search_requested_part_number') ?: $item->part_number));

        return $searchTerm !== ''
            ? 'https://parts.tesla.com/en-US/find-part?searchTerm='.rawurlencode($searchTerm)
            : null;
    }

    public function catalogImageUrl(string $url): string
    {
        return $this->imagePresenter->imageUrl($url);
    }

    public function drivePartsPlaceholderImageUrl(): string
    {
        return $this->imagePresenter->drivePartsPlaceholderImageUrl();
    }

    public function isDrivePartsPlaceholderImageReference(string $url): bool
    {
        return $this->imagePresenter->isDrivePartsPlaceholderImageReference($url);
    }

    public function isDrivePartsSharedPlaceholderImageUrl(string $url): bool
    {
        return $this->imagePresenter->isDrivePartsSharedPlaceholderImageUrl($url);
    }

    public function isPlaceholderImageUrl(string $url): bool
    {
        return $this->imagePresenter->isPlaceholderImageUrl($url);
    }

    public function catalogImageIdentityKey(string $url): string
    {
        return $this->imagePresenter->identityKey($url);
    }

    public function teslaSchemeImageKey(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $basename = preg_replace('/(?<=[a-f0-9]{12})-[a-f0-9]{12}$/i', '', $basename) ?: $basename;
        $basename = preg_replace('/[^a-z0-9]+/iu', '', $basename) ?: $basename;

        return Str::lower(trim($basename));
    }

    public function teslaSchemeImageContentKey(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        if (Str::startsWith($path, '/storage/')) {
            $fullPath = public_path(ltrim($path, '/'));

            if (is_file($fullPath)) {
                return 'file:'.sha1_file($fullPath);
            }
        }

        return 'url:'.$this->teslaSchemeImageKey($url);
    }

    public function isTeslaOfficialSchemeImageUrl(string $url): bool
    {
        return Str::contains($url, [
            'tesla-official/resources-images/',
            'epc.tesla.com/resources/images/',
            '/storage/tesla-official/resources-images/',
        ]);
    }

    protected function catalogCategoryName(mixed $category): string
    {
        return trim((string) (
            $category?->name_ru
            ?: $category?->name_ua
            ?: $category?->name_en
            ?: $category?->name
            ?: ''
        ));
    }

    protected function stripTeslaPartsUkraineOrigin(PartCatalogItem $item, string $value): string
    {
        $value = $this->catalogDisplay->withoutTeslaPartsUkraineNameMarkers($value, $item);
        $patterns = [];
        $replacements = [];

        $compatibilityText = Str::lower((string) ($item->compatibility_text ?: ($item->model_name ?: $item->model_label)));
        foreach (['s', '3', 'x', 'y'] as $model) {
            if (Str::contains($compatibilityText, 'model '.$model)) {
                $patterns[] = '/(?<![\pL\pN])(?:tesla\s+)?model\s*'.preg_quote($model, '/').'(?![\pL\pN])/iu';
                $replacements[] = '';
            }
        }

        return trim(preg_replace('/\s+/u', ' ', (string) preg_replace([
            ...$patterns,
            '/\s+([,.;:)])/u',
            '/([(])\s+/u',
        ], [
            ...$replacements,
            '$1',
            '$1',
        ], $value)));
    }

    protected function categoryPathLabel(PartCatalogItem $item): string
    {
        return match ($item->source) {
            'teslapartsukraine' => 'Категория TeslaPartsUkraine',
            'nikolacars' => 'Категория НиколаКарз',
            default => 'Категория источника',
        };
    }

    protected function shouldShowCatalogNameSource(
        string $locale,
        ?string $catalogNameSourceUrl,
        array $localizedNameSources,
        bool $nameRuManual,
        bool $nameUaManual,
    ): bool {
        if (($locale === 'ru' && $nameRuManual) || ($locale === 'ua' && $nameUaManual)) {
            return false;
        }

        $localizedSource = $localizedNameSources[$locale] ?? [];
        $localizedLabel = trim((string) ($localizedSource['site'] ?? ''));
        $localizedUrl = trim((string) ($localizedSource['url'] ?? ''));

        if ($localizedLabel !== '') {
            return false;
        }

        return $localizedUrl === '' || rtrim($localizedUrl, '/') !== rtrim((string) $catalogNameSourceUrl, '/');
    }

    protected function teslaPartSearchResults(PartCatalogItem $item, mixed $teslaRelatedFindPartResults): Collection
    {
        $teslaPartSearchResults = collect(data_get(PartCatalogRawAttributes::from($item), 'tesla_part_search_results', []));

        return $teslaPartSearchResults->isNotEmpty()
            ? $teslaPartSearchResults
            : collect($teslaRelatedFindPartResults ?? []);
    }

    protected function teslaOfficialCatalogResults(PartCatalogItem $item): Collection
    {
        return collect(data_get(PartCatalogRawAttributes::from($item), 'official_catalog_occurrences', []))
            ->filter(fn (mixed $result): bool => is_array($result))
            ->map(fn (array $result): array => [
                'part_number' => $item->part_number,
                'description' => $this->teslaOfficialNameWithAnnotation($item, $item->name_en ?: $item->name),
                'localized_description' => null,
                'model' => $result['model_label'] ?? $result['model_name'] ?? null,
                'category' => trim(collect([$result['main_category_code'] ?? null, $result['main_category_name'] ?? null])->filter()->implode(' - ')),
                'subcategory' => trim(collect([$result['subcategory_code'] ?? null, $result['subcategory_name'] ?? null])->filter()->implode(' - ')),
                'group' => $result['node_name'] ?? null,
                'visibility' => 'saved_official_catalog',
            ])
            ->unique(fn (array $result): string => implode('|', [
                $result['part_number'] ?? '',
                $result['model'] ?? '',
                $result['category'] ?? '',
                $result['subcategory'] ?? '',
                $result['group'] ?? '',
            ]))
            ->values();
    }
}
