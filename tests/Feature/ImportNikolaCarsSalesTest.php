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
            'quality' => 'Р‘РµР· РїРѕРІСЂРµР¶РґРµРЅРёР№',
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
            'notes' => 'Р‘РµР· РїРѕРІСЂРµР¶РґРµРЅРёР№',
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
            'Р С™Р С•Р Т‘',
            'Р СњР В°Р С‘Р СР ВµР Р…Р С•Р Р†Р В°Р Р…Р С‘Р Вµ',
            'Р С’РЎР‚РЎвЂљР С‘Р С”РЎС“Р В»',
            'Р С™Р В°РЎвЂљР ВµР С–Р С•РЎР‚Р С‘РЎРЏ',
            'Р С™Р С•Р В»Р С‘РЎвЂЎР ВµРЎРѓРЎвЂљР Р†Р С•',
            'Р В¦Р ВµР Р…Р В°',
            'Р вЂќР В°РЎвЂљР В°',
            'Р СњР С•Р СР ВµРЎР‚',
            'Р С™Р С•Р Р…РЎвЂљРЎР‚Р В°Р С–Р ВµР Р…РЎвЂљ',
        ])."\n".implode(';', [
            '726',
            'Battery assembly',
            '',
            'Tesla Model Y '.$donorCar->vin,
            '1',
            '5 800',
            '11.12.2025 14:06:18',
            'РќР¤РќР¤-000021',
            'РџРѕРєСѓРїРµС†СЊ',
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
            'document_number' => 'РќР¤РќР¤-000021',
        ]);
        $this->assertDatabaseHas('movements', [
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'type' => 'adjustment',
            'quantity' => 1,
            'reason' => 'sold_product_stock_cleanup',
            'document_number' => 'РќР¤РќР¤-000021',
        ]);
    }
}
