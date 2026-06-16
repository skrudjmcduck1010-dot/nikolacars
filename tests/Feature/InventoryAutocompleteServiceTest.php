<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\InventoryAutocompleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAutocompleteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_options_match_name_sku_and_external_sku(): void
    {
        $matchedByName = Product::query()->create([
            'sku' => 'BRK-001',
            'external_sku' => 'TES-111',
            'name' => 'Brake caliper',
            'slug' => 'brake-caliper',
        ]);
        $matchedByExternalSku = Product::query()->create([
            'sku' => 'LGT-002',
            'external_sku' => 'EXT-BRAKE-222',
            'name' => 'Headlight',
            'slug' => 'headlight',
        ]);
        Product::query()->create([
            'sku' => 'WNG-003',
            'external_sku' => 'TES-333',
            'name' => 'Wing mirror',
            'slug' => 'wing-mirror',
        ]);

        $options = app(InventoryAutocompleteService::class)
            ->productOptions('brake')
            ->keyBy('id');

        $this->assertTrue($options->has($matchedByName->id));
        $this->assertTrue($options->has($matchedByExternalSku->id));
        $this->assertCount(2, $options);
        $this->assertStringContainsString('BRK-001', $options[$matchedByName->id]['label']);
        $this->assertStringContainsString('TES-111', $options[$matchedByName->id]['meta']);
    }

    public function test_stock_item_options_can_include_product_and_exclude_unavailable_items(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Main warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'zone' => 'A',
            'row' => '1',
            'shelf' => '2',
            'cell' => '3',
            'full_code' => 'A-1-2-3',
        ]);
        $availableProduct = Product::query()->create([
            'sku' => 'DOOR-001',
            'external_sku' => 'EXT-DOOR-001',
            'name' => 'Door handle',
            'slug' => 'door-handle',
        ]);
        $unavailableProduct = Product::query()->create([
            'sku' => 'DOOR-002',
            'external_sku' => 'EXT-DOOR-002',
            'name' => 'Door trim',
            'slug' => 'door-trim',
        ]);
        $available = StockItem::query()->create([
            'product_id' => $availableProduct->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'reserved_quantity' => 1,
        ]);
        StockItem::query()->create([
            'product_id' => $unavailableProduct->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 2,
            'reserved_quantity' => 2,
        ]);

        $options = app(InventoryAutocompleteService::class)
            ->stockItemOptions('door', onlyAvailable: true, includeProduct: true);

        $this->assertCount(1, $options);
        $this->assertSame($available->id, $options[0]['id']);
        $this->assertSame($availableProduct->id, $options[0]['product_id']);
        $this->assertStringContainsString('DOOR-001', $options[0]['label']);
        $this->assertStringContainsString('A-1-2-3', $options[0]['label']);
        $this->assertStringContainsString('4', $options[0]['meta']);
    }

    public function test_short_queries_return_no_options(): void
    {
        $service = app(InventoryAutocompleteService::class);

        $this->assertTrue($service->productOptions('a')->isEmpty());
        $this->assertTrue($service->stockItemOptions('a')->isEmpty());
    }
}
