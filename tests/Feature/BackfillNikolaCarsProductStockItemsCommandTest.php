<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\Location;
use App\Models\Movement;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillNikolaCarsProductStockItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_candidate_without_creating_stock_item(): void
    {
        [$product] = $this->donorProductWithProjectionStock(2);

        $this->artisan('parts:backfill-nikolacars-product-stock-items', [
            '--product-id' => [$product->id],
        ])
            ->expectsOutputToContain('would_create')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('stock_items', [
            'product_id' => $product->id,
        ]);
    }

    public function test_write_creates_donor_stock_item_from_projection_stock(): void
    {
        [$product] = $this->donorProductWithProjectionStock(2);

        $this->artisan('parts:backfill-nikolacars-product-stock-items', [
            '--write' => true,
            '--product-id' => [$product->id],
        ])
            ->expectsOutputToContain('created')
            ->assertExitCode(0);

        $stockItem = StockItem::query()->where('product_id', $product->id)->firstOrFail();

        $this->assertSame(2, (int) $stockItem->quantity);
        $this->assertSame(2, (int) $stockItem->available_quantity);
        $this->assertSame(Warehouse::TYPE_DONOR, $stockItem->warehouse->type);
        $this->assertSame('ON-DONOR-'.$product->donor_car_id, $stockItem->location->full_code);
        $this->assertDatabaseHas('movements', [
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'to_location_id' => $stockItem->location_id,
            'type' => 'intake',
            'quantity' => 2,
            'document_number' => 'nikolacars-stock-projection-backfill',
        ]);
    }

    public function test_write_creates_in_stock_item_in_given_location(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Main warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'A1',
            'full_code' => 'MAIN-A1',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-IN-STOCK',
            'external_sku' => '1100088-00-D',
            'name' => 'In stock legacy projection part',
            'slug' => 'in-stock-legacy-projection-part',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'testing_status' => 'not_tested',
            'selling_price' => 25,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
            'part_number' => '1100088-00-D',
            'name' => 'In stock legacy projection part',
            'raw_attributes' => [
                'product_id' => $product->id,
                'stock_quantity' => 3,
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        $this->artisan('parts:backfill-nikolacars-product-stock-items', [
            '--write' => true,
            '--product-id' => [$product->id],
            '--location-id' => $location->id,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('stock_items', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 3,
            'available_quantity' => 3,
        ]);
    }

    public function test_write_skips_fractional_projection_stock(): void
    {
        [$product] = $this->donorProductWithProjectionStock(1.5);

        $this->artisan('parts:backfill-nikolacars-product-stock-items', [
            '--write' => true,
            '--product-id' => [$product->id],
        ])
            ->expectsOutputToContain('skipped_fractional_stock')
            ->assertExitCode(0);

        $this->assertSame(0, StockItem::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, Movement::query()->where('product_id', $product->id)->count());
    }

    protected function donorProductWithProjectionStock(float|int $stockQuantity): array
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF010001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON1-0001',
            'external_sku' => '1100087-00-D',
            'name' => 'Donor legacy projection part',
            'slug' => 'donor-legacy-projection-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'testing_status' => 'not_tested',
            'selling_price' => 25,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1100087-00-D',
            'name' => 'Donor legacy projection part',
            'raw_attributes' => [
                'product_id' => $product->id,
                'stock_quantity' => $stockQuantity,
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        return [$product, $item];
    }
}
