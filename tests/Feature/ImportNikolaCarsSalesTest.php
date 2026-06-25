<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\NikolaCarsInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportNikolaCarsSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_marks_linked_nikolacars_inventory_sold(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '7SAYGDEE0PA189237',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2023,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/726',
            'part_number' => '726',
            'name' => 'Battery assembly',
            'quality' => $this->u('\u0411\u0435\u0437 \u043f\u043e\u0432\u0440\u0435\u0436\u0434\u0435\u043d\u0438\u0439'),
            'raw_attributes' => [
                'code' => '726',
                'donor_vin' => $donorCar->vin,
                'stock_quantity' => 1,
                'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            ],
        ]);

        $product = Product::query()->create([
            'sku' => 'NC-726',
            'external_sku' => '726',
            'name' => 'Battery assembly',
            'slug' => 'nc-726',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 5800,
            'currency' => 'USD',
            'notes' => $this->u('\u0411\u0435\u0437 \u043f\u043e\u0432\u0440\u0435\u0436\u0434\u0435\u043d\u0438\u0439'),
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Donor warehouse',
            'type' => Warehouse::TYPE_DONOR,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'donor',
            'full_code' => 'DONOR-1',
            'cell' => '1',
            'is_active' => true,
        ]);
        $stockItem = $product->stockItems()->create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'testing_status' => 'tested',
        ]);

        $rawAttributes = $item->raw_attributes->getArrayCopy();
        $rawAttributes['product_id'] = $product->id;
        $item->forceFill(['raw_attributes' => $rawAttributes])->save();

        $csvPath = storage_path('framework/testing/nikolacars-sales.csv');
        if (! is_dir(dirname($csvPath))) {
            mkdir(dirname($csvPath), 0777, true);
        }

        file_put_contents($csvPath, implode(';', [
            $this->u('\u041a\u043e\u0434'),
            $this->u('\u041d\u0430\u0438\u043c\u0435\u043d\u043e\u0432\u0430\u043d\u0438\u0435'),
            $this->u('\u0410\u0440\u0442\u0438\u043a\u0443\u043b'),
            $this->u('\u041a\u0430\u0442\u0435\u0433\u043e\u0440\u0438\u044f'),
            $this->u('\u041a\u043e\u043b\u0438\u0447\u0435\u0441\u0442\u0432\u043e'),
            $this->u('\u0426\u0435\u043d\u0430'),
            $this->u('\u0414\u0430\u0442\u0430'),
            $this->u('\u041d\u043e\u043c\u0435\u0440'),
            $this->u('\u041a\u043e\u043d\u0442\u0440\u0430\u0433\u0435\u043d\u0442'),
        ])."\n".implode(';', [
            '726',
            'Battery assembly',
            '',
            'Tesla Model Y '.$donorCar->vin,
            '1',
            '5 800',
            '11.12.2025 14:06:18',
            $this->u('\u041d\u0424\u041d\u0424-000021'),
            $this->u('\u041f\u043e\u043a\u0443\u043f\u0435\u0446\u044c'),
        ])."\n");

        $this->artisan('nikolacars:sales:import', ['path' => $csvPath])
            ->assertSuccessful();

        $freshItem = $item->refresh();

        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->refresh()->storage_status);
        $this->assertFalse((bool) $product->is_active);
        $this->assertSame(0, (int) $stockItem->refresh()->quantity);
        $this->assertSame(0, (int) $stockItem->available_quantity);
        $this->assertSame(Product::STORAGE_STATUS_SOLD, data_get($freshItem->raw_attributes, 'storage_status'));
        $this->assertSame(0.0, (float) data_get($freshItem->raw_attributes, 'stock_quantity'));
        $this->assertFalse(
            app(NikolaCarsInventoryService::class)->activeItemsQuery()->whereKey($freshItem->id)->exists()
        );
        $this->assertDatabaseHas('part_sales', [
            'source' => 'nikolacars',
            'part_catalog_item_id' => $freshItem->id,
            'document_number' => json_decode('"\u041d\u0424\u041d\u0424-000021"', true, 512, JSON_THROW_ON_ERROR),
        ]);
        $this->assertDatabaseHas('movements', [
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'type' => 'adjustment',
            'quantity' => 1,
            'reason' => 'sold_product_stock_cleanup',
            'document_number' => json_decode('"\u041d\u0424\u041d\u0424-000021"', true, 512, JSON_THROW_ON_ERROR),
        ]);
    }

    protected function u(string $value): string
    {
        return json_decode('"'.$value.'"', true, 512, JSON_THROW_ON_ERROR);
    }

}
