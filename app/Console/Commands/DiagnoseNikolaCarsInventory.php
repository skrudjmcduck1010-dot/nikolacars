<?php

namespace App\Console\Commands;

use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\ExchangeRateService;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsOfficialPartMatch;
use App\Services\NikolaCarsOfficialPartMatcher;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Services\NikolaCarsPromYmlFeed;
use App\Services\NikolaCarsTeslaCategoryTreeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DiagnoseNikolaCarsInventory extends Command
{
    protected $signature = 'parts:diagnose-nikolacars-inventory
        {--limit=0 : Maximum NikolaCars catalog items to inspect}
        {--examples=5 : Problem examples per bucket}
        {--focus=all : Report focus: all, category, category-localization, or sellability}
        {--json : Output the report as JSON}';

    protected $description = 'Diagnose NikolaCars sellable inventory links, donor identity, and Tesla.com official matches.';

    public function handle(
        NikolaCarsInventoryService $inventoryService,
        NikolaCarsOfficialPartMatcher $officialPartMatcher,
    ): int {
        $limit = max(0, (int) $this->option('limit'));
        $examplesLimit = max(0, (int) $this->option('examples'));
        $focus = $this->reportFocus();
        $donorsByVin = DonorCar::query()
            ->get(['id', 'vin'])
            ->keyBy(fn (DonorCar $donorCar): string => $this->identityKey($donorCar->vin));

        $stats = $this->emptyStats();
        $examples = $this->emptyExamples();
        $officialMatchCache = [];
        $stats['default_admin_list_items'] = $limit > 0
            ? 0
            : $inventoryService->activeItemsQuery()->count();

        if ($focus === 'category-localization') {
            $this->inspectCategoryLocalization($stats, $examples, $examplesLimit);
        } elseif ($focus === 'sellability') {
            $this->inspectSellability($inventoryService, $stats, $examples, $examplesLimit);
        } else {
            $query = PartCatalogItem::query()
                ->where('source', 'nikolacars')
                ->with(['products:id,source_part_catalog_item_id,donor_car_id,storage_status,is_active,external_sku,sku'])
                ->orderBy('id');

            if ($limit > 0) {
                $query->limit($limit)->get()->each(function (PartCatalogItem $item) use (
                    $inventoryService,
                    $officialPartMatcher,
                    $donorsByVin,
                    &$stats,
                    &$examples,
                    &$officialMatchCache,
                    $examplesLimit,
                ): void {
                    $this->inspectItem($item, $inventoryService, $officialPartMatcher, $donorsByVin, $stats, $examples, $officialMatchCache, $examplesLimit);
                });
            } else {
                $query->chunkById(500, function (Collection $items) use (
                    $inventoryService,
                    $officialPartMatcher,
                    $donorsByVin,
                    &$stats,
                    &$examples,
                    &$officialMatchCache,
                    $examplesLimit,
                ): void {
                    $items->each(function (PartCatalogItem $item) use (
                        $inventoryService,
                        $officialPartMatcher,
                        $donorsByVin,
                        &$stats,
                        &$examples,
                        &$officialMatchCache,
                        $examplesLimit,
                    ): void {
                        $this->inspectItem($item, $inventoryService, $officialPartMatcher, $donorsByVin, $stats, $examples, $officialMatchCache, $examplesLimit);
                    });
                });
            }
        }

        $report = [
            'focus' => $focus,
            'stats' => $this->reportStats($stats, $focus),
            'examples' => $this->reportExamples($examples, $focus),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(match ($focus) {
            'category' => 'NikolaCars category diagnostic report',
            'category-localization' => 'NikolaCars category localization diagnostic report',
            'sellability' => 'NikolaCars sellability diagnostic report',
            default => 'NikolaCars inventory diagnostic report',
        });
        $this->table(
            ['metric', 'count'],
            collect($report['stats'])->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        foreach ($report['examples'] as $bucket => $rows) {
            $this->newLine();
            $this->warn($bucket);
            $this->table($this->exampleHeaders($focus), $rows);
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, DonorCar>  $donorsByVin
     * @param  array<string, int>  $stats
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     * @param  array<string, NikolaCarsOfficialPartMatch>  $officialMatchCache
     */
    protected function inspectItem(
        PartCatalogItem $item,
        NikolaCarsInventoryService $inventoryService,
        NikolaCarsOfficialPartMatcher $officialPartMatcher,
        Collection $donorsByVin,
        array &$stats,
        array &$examples,
        array &$officialMatchCache,
        int $examplesLimit,
    ): void {
        $stats['catalog_items_total']++;

        if (! $inventoryService->isManuallySold($item)) {
            $stats['catalog_items_not_manual_sold']++;
        } else {
            $stats['manual_sold_items']++;
        }

        $rawStorageStatus = trim((string) data_get($item->raw_attributes, 'storage_status', ''));
        if (in_array($rawStorageStatus, [Product::STORAGE_STATUS_SOLD, Product::STORAGE_STATUS_WRITTEN_OFF], true)) {
            $stats['raw_sold_or_written_off_items']++;
        }

        if (trim((string) $item->quality) === NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS) {
            $stats['broken_damage_items']++;
        }

        $stockQuantity = data_get($item->raw_attributes, 'stock_quantity');
        if ($stockQuantity === null || $stockQuantity === '' || (float) $stockQuantity <= 0) {
            $stats['zero_or_missing_stock_quantity_items']++;
        }

        if ((float) data_get($item->raw_attributes, 'reserved_quantity', 0) > 0) {
            $stats['reserved_items']++;
        }

        $officialMatch = $this->inspectOfficialMatch($item, $inventoryService, $officialPartMatcher, $stats, $examples, $officialMatchCache, $examplesLimit);
        $this->inspectDonorIdentity($item, $donorsByVin, $stats, $examples, $examplesLimit);
        $this->inspectProducts($item, $donorsByVin, $stats, $examples, $examplesLimit);
        $this->inspectCategory($item, $inventoryService, $officialMatch, $stats, $examples, $examplesLimit);
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     * @param  array<string, NikolaCarsOfficialPartMatch>  $officialMatchCache
     */
    protected function inspectOfficialMatch(
        PartCatalogItem $item,
        NikolaCarsInventoryService $inventoryService,
        NikolaCarsOfficialPartMatcher $officialPartMatcher,
        array &$stats,
        array &$examples,
        array &$officialMatchCache,
        int $examplesLimit,
    ): NikolaCarsOfficialPartMatch {
        $match = $this->officialMatch($item, $inventoryService, $officialPartMatcher, $officialMatchCache);
        $normalizedPartNumber = $match->normalizedPartNumber;

        if (! $inventoryService->isTeslaPartNumberShape($normalizedPartNumber)) {
            $stats['official_match_invalid_article']++;
            $this->rememberExample($examples, 'official_match_invalid_article', $item, 'Not a full Tesla part number.', $examplesLimit);

            return $match;
        }

        match ($match->matchType) {
            NikolaCarsOfficialPartMatch::TYPE_EXACT => $stats['official_match_exact']++,
            NikolaCarsOfficialPartMatch::TYPE_SEVEN_DIGIT_PREFIX => $stats['official_match_seven_digit_prefix']++,
            default => $stats['official_match_none']++,
        };

        if ($match->matchType === NikolaCarsOfficialPartMatch::TYPE_NONE) {
            $this->rememberExample($examples, 'official_match_none', $item, 'Valid Tesla-shaped article, but no Tesla.com item found.', $examplesLimit);
        }

        return $match;
    }

    /**
     * @param  Collection<string, DonorCar>  $donorsByVin
     * @param  array<string, int>  $stats
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     */
    protected function inspectDonorIdentity(
        PartCatalogItem $item,
        Collection $donorsByVin,
        array &$stats,
        array &$examples,
        int $examplesLimit,
    ): void {
        $donorVin = $this->donorVin($item);

        if ($donorVin === '') {
            $stats['donor_vin_missing_purchase_or_warehouse_candidates']++;

            return;
        }

        $stats['donor_vin_present']++;

        if ($donorsByVin->has($this->identityKey($donorVin))) {
            $stats['donor_vin_known']++;

            return;
        }

        $stats['donor_vin_unmatched']++;
        $this->rememberExample($examples, 'donor_vin_unmatched', $item, 'raw_attributes.donor_vin does not match donor_cars.vin.', $examplesLimit);
    }

    /**
     * @param  Collection<string, DonorCar>  $donorsByVin
     * @param  array<string, int>  $stats
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     */
    protected function inspectProducts(
        PartCatalogItem $item,
        Collection $donorsByVin,
        array &$stats,
        array &$examples,
        int $examplesLimit,
    ): void {
        $products = $item->products;
        $productsCount = $products->count();

        if ($productsCount === 0) {
            $stats['linked_products_missing']++;
            $this->rememberExample($examples, 'linked_products_missing', $item, 'No products.source_part_catalog_item_id points to this NikolaCars row.', $examplesLimit);

            return;
        }

        $stats['linked_products_present']++;

        if ($productsCount > 1) {
            $stats['linked_products_multiple']++;
            $this->rememberExample($examples, 'linked_products_multiple', $item, $productsCount.' linked products for one catalog row.', $examplesLimit);
        }

        foreach ($products as $product) {
            if ($product->donor_car_id === null) {
                $stats['linked_products_without_donor_purchase_or_warehouse']++;
            } else {
                $stats['linked_products_with_donor']++;
            }

            if ($product->storage_status === Product::STORAGE_STATUS_SOLD || $product->is_active === false) {
                $stats['linked_products_sold_or_inactive']++;
            }
        }

        $donorVin = $this->donorVin($item);
        $knownDonor = $donorVin !== '' ? $donorsByVin->get($this->identityKey($donorVin)) : null;

        if ($knownDonor instanceof DonorCar && ! $products->contains(fn (Product $product): bool => (int) $product->donor_car_id === (int) $knownDonor->id)) {
            $stats['linked_product_donor_mismatch']++;
            $this->rememberExample($examples, 'linked_product_donor_mismatch', $item, 'Known donor VIN exists, but linked product is not attached to that donor.', $examplesLimit);
        }
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     */
    protected function inspectCategory(
        PartCatalogItem $item,
        NikolaCarsInventoryService $inventoryService,
        NikolaCarsOfficialPartMatch $officialMatch,
        array &$stats,
        array &$examples,
        int $examplesLimit,
    ): void {
        $category = Str::lower($this->categoryValue($item));

        if ($category === '') {
            $stats['category_missing']++;
            $this->rememberCategoryIssue($item, $inventoryService, $officialMatch, 'missing', $stats, $examples, $examplesLimit);

            return;
        }

        if (in_array($category, ['не определено', 'не визначено'], true)) {
            $stats['category_undetermined']++;
            $this->rememberCategoryIssue($item, $inventoryService, $officialMatch, 'undetermined', $stats, $examples, $examplesLimit);
        }
    }

    /**
     * @param  array<string, NikolaCarsOfficialPartMatch>  $officialMatchCache
     */
    protected function officialMatch(
        PartCatalogItem $item,
        NikolaCarsInventoryService $inventoryService,
        NikolaCarsOfficialPartMatcher $officialPartMatcher,
        array &$officialMatchCache,
    ): NikolaCarsOfficialPartMatch {
        $normalizedPartNumber = $inventoryService->normalizePartNumber((string) $item->part_number);
        $cacheKey = $normalizedPartNumber !== '' ? $normalizedPartNumber : 'item:'.$item->id;

        return $officialMatchCache[$cacheKey] ??= $officialPartMatcher->match($normalizedPartNumber);
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     */
    protected function rememberCategoryIssue(
        PartCatalogItem $item,
        NikolaCarsInventoryService $inventoryService,
        NikolaCarsOfficialPartMatch $officialMatch,
        string $categoryStatus,
        array &$stats,
        array &$examples,
        int $examplesLimit,
    ): void {
        $stats['category_issue_total']++;
        $cause = $this->officialMatchCause($inventoryService, $officialMatch);
        $stats['category_issue_'.$cause]++;

        $note = match ($cause) {
            'official_exact' => 'Category is '.$categoryStatus.', but Tesla.com exact match exists.',
            'official_seven_digit_prefix' => 'Category is '.$categoryStatus.', and Tesla.com match is only by first seven digits.',
            'official_none' => 'Category is '.$categoryStatus.', and no Tesla.com item was found.',
            default => 'Category is '.$categoryStatus.', and article is not a full Tesla part number.',
        };

        $this->rememberExample($examples, 'category_issue_'.$cause, $item, $note, $examplesLimit);
    }

    protected function officialMatchCause(NikolaCarsInventoryService $inventoryService, NikolaCarsOfficialPartMatch $match): string
    {
        if (! $inventoryService->isTeslaPartNumberShape($match->normalizedPartNumber)) {
            return 'official_invalid_article';
        }

        return match ($match->matchType) {
            NikolaCarsOfficialPartMatch::TYPE_EXACT => 'official_exact',
            NikolaCarsOfficialPartMatch::TYPE_SEVEN_DIGIT_PREFIX => 'official_seven_digit_prefix',
            default => 'official_none',
        };
    }

    protected function categoryValue(PartCatalogItem $item): string
    {
        return trim((string) (
            data_get($item->raw_attributes, 'category_display')
            ?: data_get($item->raw_attributes, 'category_path')
            ?: $item->main_category_name
            ?: ''
        ));
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     */
    protected function inspectCategoryLocalization(array &$stats, array &$examples, int $examplesLimit): void
    {
        $mirrorsBySourceUrl = PartCatalogCategory::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'like', NikolaCarsTeslaCategoryTreeSyncService::SOURCE_URL_PREFIX.'%')
            ->get()
            ->keyBy('source_url');

        PartCatalogCategory::query()
            ->where('source', 'tesla_official')
            ->orderBy('id')
            ->chunkById(500, function (Collection $categories) use (&$stats, &$examples, $examplesLimit, $mirrorsBySourceUrl): void {
                foreach ($categories as $category) {
                    $stats['tesla_categories_total']++;

                    if ($this->filledString($category->name_en)) {
                        $stats['tesla_categories_with_name_en']++;
                    }

                    if ($this->filledString($category->name_ru)) {
                        $stats['tesla_categories_with_name_ru']++;
                    } else {
                        $stats['tesla_categories_missing_name_ru']++;
                        $this->rememberCategoryLocalizationExample($examples, 'category_localization_missing_ru', $category, null, 'Tesla official category has no name_ru.', $examplesLimit);
                    }

                    if ($this->filledString($category->name_ua)) {
                        $stats['tesla_categories_with_name_ua']++;
                    } else {
                        $stats['tesla_categories_missing_name_ua']++;
                        $this->rememberCategoryLocalizationExample($examples, 'category_localization_missing_ua', $category, null, 'Tesla official category has no name_ua.', $examplesLimit);
                    }

                    if ($this->normalizedText($category->name) !== '' && $this->normalizedText($category->name_en) !== ''
                        && $this->normalizedText($category->name) !== $this->normalizedText($category->name_en)) {
                        $stats['tesla_categories_name_differs_from_name_en']++;
                        $this->rememberCategoryLocalizationExample($examples, 'category_localization_name_differs_from_name_en', $category, null, 'Tesla official category name differs from name_en.', $examplesLimit);
                    }

                    $mirror = $mirrorsBySourceUrl->get(NikolaCarsTeslaCategoryTreeSyncService::SOURCE_URL_PREFIX.$category->id);

                    if (! $mirror instanceof PartCatalogCategory) {
                        $stats['nikolacars_mirrored_categories_missing']++;
                        $this->rememberCategoryLocalizationExample($examples, 'category_localization_mirror_missing', $category, null, 'NikolaCars mirror category is missing.', $examplesLimit);

                        continue;
                    }

                    $stats['nikolacars_mirrored_categories_total']++;

                    if ($this->filledString($mirror->name_ru)) {
                        $stats['nikolacars_mirrored_categories_with_name_ru']++;
                    }

                    if ($this->filledString($mirror->name_ua)) {
                        $stats['nikolacars_mirrored_categories_with_name_ua']++;
                    }

                    foreach (['name_en', 'name_ru', 'name_ua'] as $field) {
                        if ($this->normalizedText($category->{$field}) === $this->normalizedText($mirror->{$field})) {
                            continue;
                        }

                        $stats['nikolacars_mirror_'.$field.'_mismatch']++;
                        $this->rememberCategoryLocalizationExample(
                            $examples,
                            'category_localization_mirror_'.$field.'_mismatch',
                            $category,
                            $mirror,
                            "NikolaCars mirror {$field} differs from Tesla official.",
                            $examplesLimit
                        );
                    }
                }
            });
    }

    protected function filledString(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }

    protected function normalizedText(mixed $value): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     */
    protected function inspectSellability(
        NikolaCarsInventoryService $inventoryService,
        array &$stats,
        array &$examples,
        int $examplesLimit,
    ): void {
        $usdRate = app(ExchangeRateService::class)->displayUsdRate();
        $activeItems = $inventoryService->activeItemsQuery()
            ->with(['products:id,source_part_catalog_item_id,donor_car_id,storage_status,is_active'])
            ->orderBy('id')
            ->get();
        $promExportableItemIds = app(NikolaCarsPromYmlFeed::class)
            ->exportableGroups($usdRate)
            ->pluck('item.id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $promExportableItemIds = array_fill_keys($promExportableItemIds, true);

        $stats['sellability_active_admin_items'] = $activeItems->count();
        $stats['sellability_prom_exportable_items'] = count($promExportableItemIds);

        foreach ($activeItems as $item) {
            $stockQuantity = $this->stockQuantity($item);
            $reservedQuantity = $inventoryService->reservedQuantity(collect([$item]));
            $availableQuantity = round($stockQuantity - $reservedQuantity, 3);
            $priceUsd = $item->priceAmountUsd($usdRate);
            $blocksCustomerOrderSearchByPrice = $item->price_amount !== null && (float) ($priceUsd ?? 0) <= 0.0;
            $imageUrls = collect((array) data_get($item->raw_attributes, 'image_urls', []))->filter();

            if ($stockQuantity > 0) {
                $stats['sellability_active_positive_stock_items']++;
            } else {
                $stats['sellability_active_zero_or_missing_stock_items']++;
                $this->rememberSellabilityExample($examples, 'sellability_active_zero_or_missing_stock', $item, $stockQuantity, $reservedQuantity, 'Active item has zero or missing stock quantity.', $examplesLimit);
            }

            if ($reservedQuantity > 0) {
                $stats['sellability_active_reserved_items']++;
            }

            if ($stockQuantity > 0 && $reservedQuantity >= $stockQuantity) {
                $stats['sellability_active_fully_reserved_items']++;
                $this->rememberSellabilityExample($examples, 'sellability_active_fully_reserved', $item, $stockQuantity, $reservedQuantity, 'Active item is fully reserved.', $examplesLimit);
            }

            if ($availableQuantity > 0) {
                $stats['sellability_available_to_sell_items']++;
                if (! $blocksCustomerOrderSearchByPrice) {
                    $stats['sellability_customer_order_search_candidates']++;
                }
            }

            if ((float) ($priceUsd ?? 0) <= 0) {
                $stats['sellability_prom_excluded_zero_price_items']++;
                $this->rememberSellabilityExample($examples, 'sellability_prom_excluded_zero_price', $item, $stockQuantity, $reservedQuantity, 'Prom export excludes item without positive USD price.', $examplesLimit);
            }

            if ($imageUrls->isEmpty()) {
                $stats['sellability_prom_excluded_no_image_items']++;
                $this->rememberSellabilityExample($examples, 'sellability_prom_excluded_no_image', $item, $stockQuantity, $reservedQuantity, 'Prom export excludes item without images.', $examplesLimit);
            }

            if (! isset($promExportableItemIds[(int) $item->id])) {
                $stats['sellability_prom_excluded_items']++;
            }

            if ($item->products->contains(fn (Product $product): bool => $product->storage_status === Product::STORAGE_STATUS_SOLD || $product->is_active === false)) {
                $stats['sellability_active_with_sold_or_inactive_product_risk_items']++;
                $this->rememberSellabilityExample($examples, 'sellability_active_with_sold_or_inactive_product_risk', $item, $stockQuantity, $reservedQuantity, 'Active catalog row has a sold or inactive linked product.', $examplesLimit);
            }
        }
    }

    protected function stockQuantity(PartCatalogItem $item): float
    {
        $quantity = data_get($item->raw_attributes, 'stock_quantity');

        return $quantity !== null && $quantity !== '' ? round((float) $quantity, 3) : 0.0;
    }

    protected function donorVin(PartCatalogItem $item): string
    {
        return trim((string) data_get($item->raw_attributes, 'donor_vin', ''));
    }

    protected function identityKey(mixed $value): string
    {
        return Str::lower(trim((string) $value));
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     */
    protected function rememberExample(array &$examples, string $bucket, PartCatalogItem $item, string $note, int $limit): void
    {
        if ($limit <= 0 || count($examples[$bucket] ?? []) >= $limit) {
            return;
        }

        $examples[$bucket][] = [
            'catalog_item_id' => $item->id,
            'part_number' => (string) $item->part_number,
            'donor_vin' => $this->donorVin($item),
            'note' => $note,
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     */
    protected function rememberCategoryLocalizationExample(
        array &$examples,
        string $bucket,
        PartCatalogCategory $teslaCategory,
        ?PartCatalogCategory $mirrorCategory,
        string $note,
        int $limit,
    ): void {
        if ($limit <= 0 || count($examples[$bucket] ?? []) >= $limit) {
            return;
        }

        $examples[$bucket][] = [
            'tesla_category_id' => $teslaCategory->id,
            'mirror_category_id' => $mirrorCategory?->id,
            'name' => (string) $teslaCategory->name,
            'name_en' => (string) $teslaCategory->name_en,
            'name_ru' => (string) $teslaCategory->name_ru,
            'name_ua' => (string) $teslaCategory->name_ua,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     */
    protected function rememberSellabilityExample(
        array &$examples,
        string $bucket,
        PartCatalogItem $item,
        float $stockQuantity,
        float $reservedQuantity,
        string $note,
        int $limit,
    ): void {
        if ($limit <= 0 || count($examples[$bucket] ?? []) >= $limit) {
            return;
        }

        $examples[$bucket][] = [
            'catalog_item_id' => $item->id,
            'part_number' => (string) $item->part_number,
            'stock_quantity' => $stockQuantity,
            'reserved_quantity' => $reservedQuantity,
            'price_amount' => $item->price_amount !== null ? (string) $item->price_amount : null,
            'images_count' => count((array) data_get($item->raw_attributes, 'image_urls', [])),
            'note' => $note,
        ];
    }

    protected function exampleHeaders(string $focus): array
    {
        if ($focus === 'category-localization') {
            return ['tesla_category_id', 'mirror_category_id', 'name', 'name_en', 'name_ru', 'name_ua', 'note'];
        }

        if ($focus === 'sellability') {
            return ['catalog_item_id', 'part_number', 'stock_quantity', 'reserved_quantity', 'price_amount', 'images_count', 'note'];
        }

        return ['catalog_item_id', 'part_number', 'donor_vin', 'note'];
    }

    /** @return array<string, int> */
    protected function emptyStats(): array
    {
        return [
            'catalog_items_total' => 0,
            'default_admin_list_items' => 0,
            'catalog_items_not_manual_sold' => 0,
            'manual_sold_items' => 0,
            'raw_sold_or_written_off_items' => 0,
            'broken_damage_items' => 0,
            'zero_or_missing_stock_quantity_items' => 0,
            'reserved_items' => 0,
            'official_match_exact' => 0,
            'official_match_seven_digit_prefix' => 0,
            'official_match_none' => 0,
            'official_match_invalid_article' => 0,
            'donor_vin_present' => 0,
            'donor_vin_known' => 0,
            'donor_vin_unmatched' => 0,
            'donor_vin_missing_purchase_or_warehouse_candidates' => 0,
            'linked_products_present' => 0,
            'linked_products_missing' => 0,
            'linked_products_multiple' => 0,
            'linked_products_with_donor' => 0,
            'linked_products_without_donor_purchase_or_warehouse' => 0,
            'linked_products_sold_or_inactive' => 0,
            'linked_product_donor_mismatch' => 0,
            'category_missing' => 0,
            'category_undetermined' => 0,
            'category_issue_total' => 0,
            'category_issue_official_exact' => 0,
            'category_issue_official_seven_digit_prefix' => 0,
            'category_issue_official_none' => 0,
            'category_issue_official_invalid_article' => 0,
            'tesla_categories_total' => 0,
            'tesla_categories_with_name_en' => 0,
            'tesla_categories_name_differs_from_name_en' => 0,
            'tesla_categories_with_name_ru' => 0,
            'tesla_categories_with_name_ua' => 0,
            'tesla_categories_missing_name_ru' => 0,
            'tesla_categories_missing_name_ua' => 0,
            'nikolacars_mirrored_categories_total' => 0,
            'nikolacars_mirrored_categories_missing' => 0,
            'nikolacars_mirrored_categories_with_name_ru' => 0,
            'nikolacars_mirrored_categories_with_name_ua' => 0,
            'nikolacars_mirror_name_en_mismatch' => 0,
            'nikolacars_mirror_name_ru_mismatch' => 0,
            'nikolacars_mirror_name_ua_mismatch' => 0,
            'sellability_active_admin_items' => 0,
            'sellability_customer_order_search_candidates' => 0,
            'sellability_available_to_sell_items' => 0,
            'sellability_customer_order_search_unavailable_risk_items' => 0,
            'sellability_active_positive_stock_items' => 0,
            'sellability_active_zero_or_missing_stock_items' => 0,
            'sellability_active_reserved_items' => 0,
            'sellability_active_fully_reserved_items' => 0,
            'sellability_active_with_sold_or_inactive_product_risk_items' => 0,
            'sellability_prom_exportable_items' => 0,
            'sellability_prom_excluded_items' => 0,
            'sellability_prom_excluded_zero_price_items' => 0,
            'sellability_prom_excluded_no_image_items' => 0,
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    protected function emptyExamples(): array
    {
        return [
            'official_match_invalid_article' => [],
            'official_match_none' => [],
            'donor_vin_unmatched' => [],
            'linked_products_missing' => [],
            'linked_products_multiple' => [],
            'linked_product_donor_mismatch' => [],
            'category_issue_official_exact' => [],
            'category_issue_official_seven_digit_prefix' => [],
            'category_issue_official_none' => [],
            'category_issue_official_invalid_article' => [],
            'category_localization_missing_ru' => [],
            'category_localization_missing_ua' => [],
            'category_localization_name_differs_from_name_en' => [],
            'category_localization_mirror_missing' => [],
            'category_localization_mirror_name_en_mismatch' => [],
            'category_localization_mirror_name_ru_mismatch' => [],
            'category_localization_mirror_name_ua_mismatch' => [],
            'sellability_active_zero_or_missing_stock' => [],
            'sellability_active_fully_reserved' => [],
            'sellability_customer_order_search_unavailable_risk' => [],
            'sellability_prom_excluded_zero_price' => [],
            'sellability_prom_excluded_no_image' => [],
            'sellability_active_with_sold_or_inactive_product_risk' => [],
        ];
    }

    protected function reportFocus(): string
    {
        $focus = Str::lower(trim((string) $this->option('focus')));

        if (in_array($focus, ['all', 'category', 'category-localization', 'sellability'], true)) {
            return $focus;
        }

        $this->warn("Unknown focus '{$focus}', falling back to all.");

        return 'all';
    }

    /**
     * @param  array<string, int>  $stats
     * @return array<string, int>
     */
    protected function reportStats(array $stats, string $focus): array
    {
        if ($focus === 'category-localization') {
            return collect($stats)
                ->filter(fn (int $count, string $metric): bool => str_starts_with($metric, 'tesla_categories_')
                    || str_starts_with($metric, 'nikolacars_mirrored_categories_')
                    || str_starts_with($metric, 'nikolacars_mirror_'))
                ->all();
        }

        if ($focus === 'sellability') {
            return collect($stats)
                ->filter(fn (int $count, string $metric): bool => str_starts_with($metric, 'sellability_'))
                ->all();
        }

        if ($focus !== 'category') {
            return $stats;
        }

        return collect($stats)
            ->filter(fn (int $count, string $metric): bool => str_starts_with($metric, 'category_')
                || in_array($metric, ['catalog_items_total', 'default_admin_list_items'], true))
            ->all();
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $examples
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function reportExamples(array $examples, string $focus): array
    {
        $examples = array_filter($examples, fn (array $rows): bool => $rows !== []);

        if ($focus === 'category-localization') {
            return array_filter(
                $examples,
                fn (string $bucket): bool => str_starts_with($bucket, 'category_localization_'),
                ARRAY_FILTER_USE_KEY
            );
        }

        if ($focus === 'sellability') {
            return array_filter(
                $examples,
                fn (string $bucket): bool => str_starts_with($bucket, 'sellability_'),
                ARRAY_FILTER_USE_KEY
            );
        }

        if ($focus !== 'category') {
            return $examples;
        }

        return array_filter(
            $examples,
            fn (string $bucket): bool => str_starts_with($bucket, 'category_issue_'),
            ARRAY_FILTER_USE_KEY
        );
    }
}
