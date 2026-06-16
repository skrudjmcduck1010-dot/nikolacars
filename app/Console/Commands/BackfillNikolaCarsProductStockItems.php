<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Movement;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillNikolaCarsProductStockItems extends Command
{
    protected $signature = 'parts:backfill-nikolacars-product-stock-items
        {--write : Create missing stock_items}
        {--product-id=* : Limit to one or more product IDs}
        {--location-id= : Location for non-donor in-stock products}
        {--limit=0 : Maximum products to inspect}
        {--examples=10 : Candidate examples to show}';

    protected $description = 'Backfill real product stock_items from legacy NikolaCars stock_quantity projections.';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $limit = max(0, (int) $this->option('limit'));
        $examplesLimit = max(0, (int) $this->option('examples'));
        $productIds = collect((array) $this->option('product-id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $defaultLocation = $this->defaultMainLocation();

        $stats = [
            'products_seen' => 0,
            'candidate_products' => 0,
            'would_create' => 0,
            'created' => 0,
            'skipped_no_nikolacars_projection' => 0,
            'skipped_inactive_or_sold' => 0,
            'skipped_existing_stock_items' => 0,
            'skipped_no_projection_stock' => 0,
            'skipped_fractional_stock' => 0,
            'skipped_missing_donor' => 0,
            'skipped_missing_location' => 0,
        ];
        $examples = [];

        $query = Product::query()
            ->with(['donorCar', 'sourcePartCatalogItem', 'stockItems'])
            ->when($productIds->isNotEmpty(), fn (Builder $query) => $query->whereIn('id', $productIds->all()))
            ->orderBy('id');

        $inspect = function (Product $product) use ($write, $defaultLocation, &$stats, &$examples, $examplesLimit): void {
            $this->inspectProduct($product, $write, $defaultLocation, $stats, $examples, $examplesLimit);
        };

        if ($limit > 0) {
            $query->limit($limit)->get()->each($inspect);
        } else {
            $query->chunkById(300, fn ($products) => $products->each($inspect));
        }

        $this->info(($write ? 'Backfilled' : 'Scanned').' NikolaCars product stock items.');
        $this->table(
            ['metric', 'count'],
            collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        if ($examples !== []) {
            $this->newLine();
            $this->warn('Examples');
            $this->table(
                ['product_id', 'sku', 'item_id', 'stock_quantity', 'location', 'action'],
                $examples
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $examples
     */
    protected function inspectProduct(
        Product $product,
        bool $write,
        ?Location $defaultLocation,
        array &$stats,
        array &$examples,
        int $examplesLimit,
    ): void {
        $stats['products_seen']++;

        $item = $product->sourcePartCatalogItem;
        if (! $item instanceof PartCatalogItem || $item->source !== 'nikolacars') {
            $stats['skipped_no_nikolacars_projection']++;

            return;
        }

        if (! $product->is_active || ! in_array($product->storage_status, [
            Product::STORAGE_STATUS_IN_STOCK,
            Product::STORAGE_STATUS_ON_DONOR,
        ], true)) {
            $stats['skipped_inactive_or_sold']++;

            return;
        }

        if ($product->stockItems->isNotEmpty()) {
            $stats['skipped_existing_stock_items']++;

            return;
        }

        $stockQuantity = data_get(PartCatalogRawAttributes::from($item), 'stock_quantity');
        if ($stockQuantity === null || $stockQuantity === '' || (float) $stockQuantity <= 0) {
            $stats['skipped_no_projection_stock']++;

            return;
        }

        $quantity = (float) $stockQuantity;
        $roundedQuantity = (int) round($quantity);
        if (abs($quantity - $roundedQuantity) > 0.001) {
            $stats['skipped_fractional_stock']++;

            return;
        }

        $location = $this->locationForProduct($product, $defaultLocation, $write);
        if (! $location instanceof Location) {
            if ($product->donor_car_id && $product->storage_status === Product::STORAGE_STATUS_ON_DONOR) {
                $stats['skipped_missing_donor']++;
            } else {
                $stats['skipped_missing_location']++;
            }

            return;
        }

        $stats['candidate_products']++;
        $stats[$write ? 'created' : 'would_create']++;

        if (count($examples) < $examplesLimit) {
            $examples[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'item_id' => $item->id,
                'stock_quantity' => $roundedQuantity,
                'location' => $location->full_code,
                'action' => $write ? 'created' : 'would_create',
            ];
        }

        if ($write) {
            $this->createStockItem($product, $location, $roundedQuantity);
        }
    }

    protected function locationForProduct(Product $product, ?Location $defaultLocation, bool $write): ?Location
    {
        if ($product->donor_car_id && $product->storage_status === Product::STORAGE_STATUS_ON_DONOR) {
            if (! $product->donorCar) {
                return null;
            }

            return $write
                ? $this->donorLocation($product)
                : $this->existingDonorLocation($product) ?? $this->previewDonorLocation($product);
        }

        return $defaultLocation;
    }

    protected function createStockItem(Product $product, Location $location, int $quantity): void
    {
        DB::transaction(function () use ($product, $location, $quantity): void {
            $stockItem = StockItem::query()->firstOrNew([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'testing_status' => $product->testing_status ?: 'not_tested',
            ]);

            $stockItem->warehouse_id = $location->warehouse_id;
            $stockItem->quantity = $quantity;
            $stockItem->reserved_quantity = (int) ($stockItem->reserved_quantity ?? 0);
            $stockItem->received_at = now();
            $stockItem->save();

            Movement::query()->create([
                'product_id' => $product->id,
                'stock_item_id' => $stockItem->id,
                'from_location_id' => null,
                'to_location_id' => $location->id,
                'type' => 'intake',
                'quantity' => $quantity,
                'document_number' => 'nikolacars-stock-projection-backfill',
                'comment' => 'Backfilled from legacy NikolaCars part_catalog_items.raw_attributes.stock_quantity.',
            ]);
        });
    }

    protected function defaultMainLocation(): ?Location
    {
        $locationId = (int) $this->option('location-id');
        if ($locationId > 0) {
            return Location::query()->where('is_active', true)->find($locationId);
        }

        return Location::query()
            ->where('is_active', true)
            ->whereHas('warehouse', fn (Builder $query) => $query
                ->where('is_active', true)
                ->where('type', Warehouse::TYPE_MAIN))
            ->orderBy('id')
            ->first();
    }

    protected function donorLocation(Product $product): Location
    {
        $warehouse = Warehouse::query()
            ->where('type', Warehouse::TYPE_DONOR)
            ->orWhere('name', Warehouse::DONOR_WAREHOUSE_NAME)
            ->first();

        if (! $warehouse instanceof Warehouse) {
            $warehouse = Warehouse::query()->create([
                'name' => Warehouse::DONOR_WAREHOUSE_NAME,
                'type' => Warehouse::TYPE_DONOR,
                'floor_count' => 1,
                'is_active' => true,
            ]);
        }

        if ($warehouse->name !== Warehouse::DONOR_WAREHOUSE_NAME || $warehouse->type !== Warehouse::TYPE_DONOR || ! $warehouse->is_active) {
            $warehouse->forceFill([
                'name' => Warehouse::DONOR_WAREHOUSE_NAME,
                'type' => Warehouse::TYPE_DONOR,
                'floor_count' => max(1, (int) ($warehouse->floor_count ?: 1)),
                'is_active' => true,
            ])->save();
        }

        return Location::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouse->id,
                'full_code' => $this->donorLocationCode($product),
            ],
            [
                'floor' => 'floor_1',
                'cell' => Str::limit((string) ($product->donorCar?->vin ?: 'DONOR-'.$product->donor_car_id), 50, ''),
                'is_active' => true,
            ],
        );
    }

    protected function existingDonorLocation(Product $product): ?Location
    {
        return Location::query()
            ->where('full_code', $this->donorLocationCode($product))
            ->whereHas('warehouse', fn (Builder $query) => $query->where('type', Warehouse::TYPE_DONOR))
            ->first();
    }

    protected function previewDonorLocation(Product $product): Location
    {
        return new Location([
            'full_code' => $this->donorLocationCode($product),
        ]);
    }

    protected function donorLocationCode(Product $product): string
    {
        return 'ON-DONOR-'.$product->donor_car_id;
    }
}
