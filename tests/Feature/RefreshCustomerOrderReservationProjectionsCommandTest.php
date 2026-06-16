<?php

namespace Tests\Feature;

use App\Models\CustomerOrder;
use App\Models\Location;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshCustomerOrderReservationProjectionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_syncs_product_stock_reservation_from_linked_catalog_order_item(): void
    {
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/1',
            'part_number' => '1127503-11-D',
            'name' => 'Legacy reserved catalog item',
            'raw_attributes' => [
                'stock_quantity' => 1,
                'reserved_quantity' => 0,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-LEGACY-RESERVE',
            'external_sku' => '1127503-11-D',
            'name' => 'Legacy reserved catalog item',
            'slug' => 'legacy-reserved-catalog-item',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 25,
            'currency' => 'USD',
        ]);
        $catalogItem->forceFill([
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
            'raw_attributes' => [
                'product_id' => $product->id,
                'stock_quantity' => 1,
                'reserved_quantity' => 0,
            ],
        ])->save();

        $stockItem = $this->createProductStockItem($product);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260616-0001',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 1000,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Legacy reserved catalog item',
            'code' => '1127503-11-D',
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'currency' => 'UAH',
        ]);

        $this->artisan('customer-orders:refresh-reservation-projections')
            ->expectsOutputToContain('Reservation projections refreshed. Changed records:')
            ->assertExitCode(0);

        $this->assertSame(1, $stockItem->refresh()->reserved_quantity);
        $this->assertSame(0, $stockItem->available_quantity);
        $this->assertSame(1.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'reserved_quantity'));
        $this->assertDatabaseHas('reservations', [
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'quantity' => 1,
            'status' => 'active',
            'customer_order_id' => 'customer-order:'.$order->id,
        ]);
    }

    private function createProductStockItem(Product $product): StockItem
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Command test warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'A1',
            'full_code' => 'COMMAND-TEST-A1',
            'is_active' => true,
        ]);

        return StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'available_quantity' => 1,
            'testing_status' => 'not_tested',
        ]);
    }
}
