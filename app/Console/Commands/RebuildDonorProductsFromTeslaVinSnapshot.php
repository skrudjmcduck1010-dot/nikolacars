<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\DonorProductGenerationService;
use App\Services\DonorProductLocalizedNameAutofillService;
use App\Services\DonorProductSkuService;
use App\Services\StockService;
use App\Services\TeslaCatalogDonorProductSync;
use App\Services\TeslaOfficialCatalogImporter;
use App\Services\TeslaOfficialVinSpecificCatalogCleanupService;
use App\Support\PartCatalogRawAttributes;
use App\Support\ProductPhotoNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RebuildDonorProductsFromTeslaVinSnapshot extends Command
{
    protected $signature = 'donor-cars:rebuild-from-tesla-vin-snapshot
        {donorCarId}
        {snapshotPath}
        {--dry-run : Show what would change without writing to the database}
        {--delete-current : Delete unchecked/unsold donor products before creating snapshot products}
        {--recommended-only : Create donor products only from Tesla RECOMMENDED snapshot rows}';

    protected $description = 'Rebuild unchecked donor products from a saved Tesla VIN catalog snapshot.';

    public function handle(
        TeslaOfficialCatalogImporter $importer,
        DonorProductSkuService $skuService,
        DonorProductLocalizedNameAutofillService $nameAutofillService,
        DonorProductGenerationService $donorProductGenerationService,
        TeslaOfficialVinSpecificCatalogCleanupService $vinSpecificCleanup,
    ): int {
        $donorCar = DonorCar::query()->findOrFail((int) $this->argument('donorCarId'));
        $snapshotPath = $this->snapshotPath((string) $this->argument('snapshotPath'));
        $dryRun = (bool) $this->option('dry-run');
        $deleteCurrent = (bool) $this->option('delete-current');
        $recommendedOnly = (bool) $this->option('recommended-only');

        if (! is_file($snapshotPath)) {
            $this->error("Snapshot file not found: {$snapshotPath}");

            return self::FAILURE;
        }

        $snapshotContents = (string) file_get_contents($snapshotPath);
        $snapshotContents = preg_replace('/^\xEF\xBB\xBF/', '', $snapshotContents) ?? $snapshotContents;
        $snapshot = json_decode($snapshotContents, true);
        if (! is_array($snapshot)) {
            $this->error('Snapshot JSON is invalid.');

            return self::FAILURE;
        }

        $vin = Str::upper(trim((string) ($snapshot['vin'] ?? $donorCar->vin)));
        if ($vin === '') {
            $this->error('Snapshot VIN is empty.');

            return self::FAILURE;
        }

        $snapshotRows = $this->snapshotPartRows($snapshot)
            ->when($recommendedOnly, fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => Str::upper($row['recommendation_type']) === 'RECOMMENDED')
                ->values());
        $snapshotByPart = $snapshotRows
            ->groupBy(fn (array $row): string => $this->compactPartNumber($row['part_number']))
            ->map(fn (Collection $rows): array => $this->preferredSnapshotRow($rows))
            ->filter(fn (array $row): bool => $row['part_number'] !== '')
            ->values();

        $protectedProducts = $this->protectedProducts($donorCar);
        $deleteCandidates = $this->deleteCandidates($donorCar, $protectedProducts->pluck('id')->all());

        $this->line("Snapshot VIN: {$vin}");
        $this->line('Snapshot rows: '.$snapshotRows->count());
        $this->line('Recommended only: '.($recommendedOnly ? 'yes' : 'no'));
        $this->line('Unique part numbers: '.$snapshotByPart->count());
        $this->line('Protected donor products: '.$protectedProducts->count());
        $this->line('Unchecked/unsold delete candidates: '.$deleteCandidates->count());

        if ($dryRun) {
            $this->warn('Dry run only. No database changes were made.');

            return self::SUCCESS;
        }

        $importStats = $importer->importBrowserSnapshot($snapshot, [
            'dry_run' => false,
            'max_parts' => 0,
            'skip_translations' => true,
            'raw_attributes_extra' => [
                'donor_vin' => $vin,
                'donor_car_id' => $donorCar->id,
                'vin_catalog_imported_at' => now()->toIso8601String(),
            ],
        ]);

        if ($deleteCurrent) {
            $deleteCandidates->each(function (Product $product): void {
                $product->delete();
            });
        }

        $existingPartNumbers = Product::query()
            ->where('donor_car_id', $donorCar->id)
            ->whereNotNull('external_sku')
            ->pluck('external_sku')
            ->map(fn (string $partNumber): string => $this->compactPartNumber($partNumber))
            ->filter()
            ->unique()
            ->flip();

        $officialItemsByPart = $this->officialItemsForSnapshot($snapshotByPart->pluck('part_number')->all());
        $smallCatalogFlagsUpdated = $donorProductGenerationService->refreshSmallVinCatalogFlags($officialItemsByPart->values());

        $created = 0;
        $skippedExisting = 0;
        $missingOfficialItem = 0;

        DB::transaction(function () use (
            $snapshotByPart,
            $officialItemsByPart,
            $existingPartNumbers,
            $donorCar,
            $skuService,
            $nameAutofillService,
            &$created,
            &$skippedExisting,
            &$missingOfficialItem,
        ): void {
            foreach ($snapshotByPart as $row) {
                $compact = $this->compactPartNumber($row['part_number']);
                if ($compact === '' || $existingPartNumbers->has($compact)) {
                    $skippedExisting++;

                    continue;
                }

                $catalogItem = $officialItemsByPart->get($compact);
                if (! $catalogItem instanceof PartCatalogItem) {
                    $missingOfficialItem++;

                    continue;
                }

                $product = $this->createProduct($donorCar, $catalogItem, $row, $skuService);
                $this->intakeProduct($donorCar, $product, (int) max(1, $row['quantity']));
                app(TeslaCatalogDonorProductSync::class)->syncProduct($product);
                $nameAutofillService->fillMissingNames($product);

                $existingPartNumbers->put($compact, true);
                $created++;
            }
        });
        $cleanupStats = $vinSpecificCleanup->cleanupDonor($donorCar);

        $this->info('Rebuilt donor products from Tesla VIN snapshot.');
        foreach ($importStats as $name => $value) {
            $this->line(" - import_{$name}: {$value}");
        }
        $this->line(' - products_deleted: '.($deleteCurrent ? $deleteCandidates->count() : 0));
        $this->line(" - products_created: {$created}");
        $this->line(" - products_skipped_existing: {$skippedExisting}");
        $this->line(" - missing_official_items: {$missingOfficialItem}");
        $this->line(" - small_catalog_flags_updated: {$smallCatalogFlagsUpdated}");
        $this->line(" - vin_specific_items_deleted: {$cleanupStats['items_deleted']}");
        $this->line(" - vin_specific_products_relinked: {$cleanupStats['products_relinked']}");

        return self::SUCCESS;
    }

    protected function snapshotPath(string $path): string
    {
        if (preg_match('/^[A-Z]:\\\\/i', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    protected function snapshotPartRows(array $snapshot): Collection
    {
        $rows = collect();

        foreach ((array) ($snapshot['catalogs'] ?? []) as $catalogSnapshot) {
            $categoriesBySystemGroup = $this->categoryContextBySystemGroup((array) ($catalogSnapshot['categories'] ?? []));

            foreach ((array) ($catalogSnapshot['system_group_details'] ?? []) as $detailRow) {
                $details = (array) ($detailRow['details'] ?? []);
                $systemGroupExternalReference = (string) ($detailRow['system_group_external_reference'] ?? $details['externalReference'] ?? '');
                $context = $categoriesBySystemGroup[$systemGroupExternalReference] ?? [];

                foreach ($this->partsFromDetails($details) as $part) {
                    $part = (array) $part;
                    $partNumber = trim((string) ($part['partNumber'] ?? $part['catalogPartNumber'] ?? ''));
                    $recommendedPartNumber = trim((string) ($part['recommendedPartNumber'] ?? ''));

                    if (Str::upper(trim((string) ($part['recommendationType'] ?? ''))) === 'RECOMMENDED'
                        && $recommendedPartNumber !== '') {
                        $partNumber = $recommendedPartNumber;
                    }

                    if ($partNumber === '') {
                        continue;
                    }

                    $rows->push([
                        'part_number' => $partNumber,
                        'catalog_part_number' => trim((string) ($part['catalogPartNumber'] ?? '')),
                        'recommended_part_number' => $recommendedPartNumber,
                        'recommendation_type' => trim((string) ($part['recommendationType'] ?? '')),
                        'name' => trim((string) ($part['title'] ?? $part['name'] ?? $part['description'] ?? $partNumber)),
                        'quantity' => (int) max(1, (int) ($part['quantity'] ?? $part['itemQuantity'] ?? 1)),
                        'item_quantity' => $part['itemQuantity'] ?? null,
                        'catalog_quantity' => $part['catalogQuantity'] ?? null,
                        'annotation' => $part['annotation'] ?? null,
                        'system_group_external_reference' => $systemGroupExternalReference,
                        'system_group_name' => trim((string) ($details['title'] ?? $context['system_group_name'] ?? '')),
                        'main_category_name' => trim((string) ($details['categoryTitleOriginal'] ?? $details['categoryTitle'] ?? $context['main_category_name'] ?? '')),
                        'subcategory_name' => trim((string) ($details['subcategoryTitleOriginal'] ?? $details['subcategoryTitle'] ?? $context['subcategory_name'] ?? '')),
                        'raw_part' => $part,
                    ]);
                }
            }
        }

        return $rows;
    }

    protected function partsFromDetails(array $details): Collection
    {
        $parts = collect();

        $visit = function (mixed $node) use (&$visit, $parts): void {
            if (! is_array($node)) {
                return;
            }

            if (array_is_list($node)) {
                foreach ($node as $child) {
                    $visit($child);
                }

                return;
            }

            $nodeParts = $node['parts'] ?? null;
            if (is_array($nodeParts)) {
                foreach ($nodeParts as $part) {
                    if (is_array($part)) {
                        $parts->push($part);
                    }
                }
            }

            foreach ($node as $key => $value) {
                if ($key === 'parts') {
                    continue;
                }

                if (is_array($value)) {
                    $visit($value);
                }
            }
        };

        $visit($details);

        return $parts;
    }

    protected function categoryContextBySystemGroup(array $categories): array
    {
        $contexts = [];

        foreach ($categories as $category) {
            $category = (array) $category;
            $mainName = trim((string) ($category['title'] ?? $category['name'] ?? ''));

            foreach ((array) ($category['subCategories'] ?? $category['subcategories'] ?? []) as $subcategory) {
                $subcategory = (array) $subcategory;
                $subcategoryName = trim((string) ($subcategory['title'] ?? $subcategory['name'] ?? ''));

                foreach ((array) ($subcategory['systemGroups'] ?? $subcategory['systemgroups'] ?? []) as $systemGroup) {
                    $systemGroup = (array) $systemGroup;
                    $reference = (string) ($systemGroup['externalReference'] ?? $systemGroup['id'] ?? '');
                    if ($reference === '') {
                        continue;
                    }

                    $contexts[$reference] = [
                        'main_category_name' => $mainName,
                        'subcategory_name' => $subcategoryName,
                        'system_group_name' => trim((string) ($systemGroup['title'] ?? $systemGroup['name'] ?? '')),
                    ];
                }
            }
        }

        return $contexts;
    }

    protected function preferredSnapshotRow(Collection $rows): array
    {
        return $rows
            ->sortBy([
                fn (array $row): int => Str::upper($row['recommendation_type']) === 'RECOMMENDED' ? 0 : 1,
                fn (array $row): int => (int) $row['quantity'] > 1 ? 0 : 1,
                fn (array $row): string => $row['system_group_name'],
            ])
            ->first();
    }

    protected function officialItemsForSnapshot(array $partNumbers): Collection
    {
        $compacts = collect($partNumbers)
            ->map(fn (string $partNumber): string => $this->compactPartNumber($partNumber))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->whereIn('part_number_compact', $compacts)
            ->get()
            ->sortBy([
                fn (PartCatalogItem $item): int => str_starts_with((string) $item->source_url, 'https://parts.tesla.com/') ? 0 : 1,
                fn (PartCatalogItem $item): int => $item->id,
            ])
            ->unique(fn (PartCatalogItem $item): string => $this->compactPartNumber((string) $item->part_number))
            ->keyBy(fn (PartCatalogItem $item): string => $this->compactPartNumber((string) $item->part_number));
    }

    protected function protectedProducts(DonorCar $donorCar): Collection
    {
        $soldProductIds = $donorCar->partSales()
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id): int => (int) $id);
        $soldCatalogItemIds = $donorCar->partSales()
            ->pluck('part_catalog_item_id')
            ->filter()
            ->map(fn ($id): int => (int) $id);

        return Product::query()
            ->where('donor_car_id', $donorCar->id)
            ->where(function (Builder $query) use ($soldProductIds, $soldCatalogItemIds): void {
                $query
                    ->where('storage_status', Product::STORAGE_STATUS_SOLD)
                    ->orWhereNotIn('notes', ['', 'Неизвестно'])
                    ->orWhereHas('stockItems', fn (Builder $stockQuery) => $stockQuery->where('reserved_quantity', '>', 0))
                    ->orWhereHas('stoWorkOrderParts');

                if ($soldProductIds->isNotEmpty()) {
                    $query->orWhereIn('id', $soldProductIds->all());
                }

                if ($soldCatalogItemIds->isNotEmpty()) {
                    $query->orWhereIn('source_part_catalog_item_id', $soldCatalogItemIds->all());
                }
            })
            ->get();
    }

    protected function deleteCandidates(DonorCar $donorCar, array $protectedProductIds): Collection
    {
        return Product::query()
            ->where('donor_car_id', $donorCar->id)
            ->when($protectedProductIds !== [], fn (Builder $query) => $query->whereNotIn('id', $protectedProductIds))
            ->get();
    }

    protected function createProduct(
        DonorCar $donorCar,
        PartCatalogItem $catalogItem,
        array $row,
        DonorProductSkuService $skuService,
    ): Product {
        $sku = $skuService->uniqueAutoCode($donorCar);
        $category = $this->categoryFromCatalogItem($catalogItem, $row);
        $imageUrls = $this->catalogItemImageUrls($catalogItem);
        $quantity = (int) max(1, $row['quantity']);

        return Product::query()->create([
            'sku' => $sku,
            'external_sku' => $row['part_number'],
            'name' => $catalogItem->name ?: $row['name'],
            'slug' => $this->uniqueProductSlug($catalogItem->name ?: $row['name'], $sku),
            'category_id' => $category?->id,
            'brand_id' => $this->teslaBrand()?->id,
            'donor_car_id' => $donorCar->id,
            'part_origin' => Product::PART_ORIGIN_ORIGINAL,
            'source_part_catalog_item_id' => $catalogItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'generated_at' => now(),
            'description' => collect([
                'Auto-generated from Tesla VIN catalog snapshot.',
                'Tesla VIN quantity: '.$quantity,
                'Source: tesla_official',
                $catalogItem->source_url ? 'URL: '.$catalogItem->source_url : null,
            ])->filter()->implode(PHP_EOL),
            'compatibility' => $catalogItem->compatibility_text,
            'model' => $donorCar->model,
            'generation' => $catalogItem->model_label ?: $catalogItem->model_name,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'purchase_price' => 0,
            'selling_price' => 0,
            'currency' => 'USD',
            'barcode' => $sku,
            'qr_code' => $sku,
            'main_image' => $imageUrls[0] ?? null,
            'images_json' => $imageUrls !== [] ? $imageUrls : null,
            'notes' => '',
            'is_active' => true,
        ]);
    }

    protected function intakeProduct(DonorCar $donorCar, Product $product, int $quantity): StockItem
    {
        $location = $this->donorStockLocation($donorCar);

        return app(StockService::class)->intake([
            'product_id' => $product->id,
            'warehouse_id' => $location->warehouse_id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'comment' => 'Auto-generated donor part from Tesla VIN catalog snapshot.',
        ]);
    }

    protected function donorStockLocation(DonorCar $donorCar): Location
    {
        $warehouse = Warehouse::query()
            ->where('type', Warehouse::TYPE_DONOR)
            ->orWhere('name', Warehouse::DONOR_WAREHOUSE_NAME)
            ->firstOrCreate(
                ['name' => Warehouse::DONOR_WAREHOUSE_NAME],
                ['type' => Warehouse::TYPE_DONOR, 'floor_count' => 1, 'is_active' => true],
            );

        return Location::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouse->id,
                'full_code' => 'ON-DONOR-'.$donorCar->id,
            ],
            [
                'floor' => 'floor_1',
                'cell' => Str::limit($donorCar->vin ?: 'DONOR-'.$donorCar->id, 50, ''),
                'is_active' => true,
            ],
        );
    }

    protected function categoryFromCatalogItem(PartCatalogItem $catalogItem, array $row): ?Category
    {
        $categoryName = collect([
            $catalogItem->main_category_name ?: $row['main_category_name'],
            $catalogItem->subcategory_name ?: $row['subcategory_name'],
            $catalogItem->node_name ?: $row['system_group_name'],
        ])->filter()->implode(' / ');

        if ($categoryName === '') {
            return null;
        }

        $slug = Str::limit('tesla-official-'.(Str::slug($categoryName) ?: md5($categoryName)), 255, '');

        return Category::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => Str::limit($categoryName, 255, ''), 'is_active' => true],
        );
    }

    protected function catalogItemImageUrls(PartCatalogItem $catalogItem): array
    {
        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);

        return collect((array) data_get($rawAttributes, 'part_image_urls', []))
            ->filter()
            ->reject(fn (string $url): bool => ProductPhotoNormalizer::isCatalogSchemeImage($url))
            ->sortBy(fn (string $url): int => str_contains($url, 'tesla-official/part-images/') ? 0 : 1)
            ->unique(fn (string $url): string => ProductPhotoNormalizer::imageKey($url))
            ->values()
            ->all();
    }

    protected function teslaBrand(): ?Brand
    {
        return Brand::query()->firstOrCreate(
            ['slug' => 'tesla'],
            ['name' => 'Tesla', 'is_active' => true],
        );
    }

    protected function uniqueProductSlug(string $name, string $sku): string
    {
        $base = Str::slug($name) ?: Str::slug($sku);
        $slug = $base;
        $counter = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    protected function compactPartNumber(string $value): string
    {
        return Str::upper(preg_replace('/[^A-Z0-9]/i', '', $value) ?: '');
    }
}
