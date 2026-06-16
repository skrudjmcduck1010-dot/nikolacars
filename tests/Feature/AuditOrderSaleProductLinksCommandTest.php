<?php

namespace Tests\Feature;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditOrderSaleProductLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_missing_order_item_product_id_without_changing_it(): void
    {
        [$product, $catalogItem] = $this->linkedProductAndCatalogItem();
        $order = $this->order();
        $orderItem = CustomerOrderItem::query()->create([
            'customer_order_id' => $order->id,
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => null,
            'name' => 'Door order item',
            'part_number' => $catalogItem->part_number,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'currency' => 'UAH',
        ]);

        $this->artisan('parts:audit-order-sale-product-links', [
            '--table' => 'orders',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('order_items_missing_product_id')
            ->expectsOutputToContain('order_items_would_repair')
            ->assertExitCode(0);

        $this->assertNull($orderItem->refresh()->product_id);
        $this->assertSame($product->id, data_get($catalogItem->refresh()->raw_attributes, 'product_id'));
    }

    public function test_repair_fills_missing_product_id_for_order_items_and_sales(): void
    {
        [$product, $catalogItem] = $this->linkedProductAndCatalogItem();
        $order = $this->order();
        $orderItem = CustomerOrderItem::query()->create([
            'customer_order_id' => $order->id,
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => null,
            'name' => 'Door order item',
            'part_number' => $catalogItem->part_number,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'currency' => 'UAH',
        ]);
        $sale = PartSale::query()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => null,
            'source' => 'nikolacars',
            'part_number' => $catalogItem->part_number,
            'name' => 'Door sale',
            'quantity' => 1,
            'unit_price' => 100,
            'currency' => 'USD',
            'source_row_hash' => 'sale-missing-product-id',
            'raw_attributes' => [
                'product_id' => $product->id,
            ],
        ]);

        $this->artisan('parts:audit-order-sale-product-links', [
            '--repair' => true,
        ])
            ->expectsOutputToContain('order_items_repaired')
            ->expectsOutputToContain('sales_repaired')
            ->assertExitCode(0);

        $this->assertSame($product->id, $orderItem->refresh()->product_id);
        $this->assertSame($product->id, $sale->refresh()->product_id);
    }

    public function test_repair_skips_existing_product_conflict(): void
    {
        [$product, $catalogItem] = $this->linkedProductAndCatalogItem();
        $otherProduct = Product::query()->create([
            'sku' => 'NC-OTHER',
            'external_sku' => '9000000-00-A',
            'name' => 'Other product',
            'slug' => 'other-product',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $order = $this->order();
        $orderItem = CustomerOrderItem::query()->create([
            'customer_order_id' => $order->id,
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $otherProduct->id,
            'name' => 'Conflicting order item',
            'part_number' => $catalogItem->part_number,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'currency' => 'UAH',
        ]);

        $this->artisan('parts:audit-order-sale-product-links', [
            '--table' => 'orders',
            '--repair' => true,
        ])
            ->expectsOutputToContain('order_items_product_mismatch')
            ->expectsOutputToContain('order_items_catalog_mismatch')
            ->expectsOutputToContain('order_items_conflict')
            ->assertExitCode(0);

        $this->assertSame($otherProduct->id, $orderItem->refresh()->product_id);
        $this->assertSame($product->id, data_get($catalogItem->refresh()->raw_attributes, 'product_id'));
    }

    public function test_repair_can_create_missing_sold_product_for_legacy_sale(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3 06.2017 - 12.2023',
            'year' => 2019,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1084168-S0-E',
            'part_number' => '1084168-S0-E',
            'name' => 'FRONT FASCIA UNPAINTED WITH PARKING ASSIST BRACKETS',
            'name_ru' => 'Р‘Р°РјРїРµСЂ РїРµСЂРµРґРЅРёР№',
            'name_ua' => 'Р‘Р°РјРїРµСЂ РїРµСЂРµРґРЅС–Р№',
            'price_amount' => 857.95,
            'currency' => 'USD',
        ]);
        $sale = PartSale::query()->create([
            'part_catalog_item_id' => null,
            'product_id' => null,
            'donor_car_id' => $donorCar->id,
            'source' => 'nikolacars',
            'code' => '499',
            'part_number' => '1084168-S0-E',
            'name' => 'Р‘Р°РјРїРµСЂ РїРµСЂРµРґРЅС–Р№ Tesla M3 2018 - 2021 1084168S0E',
            'quantity' => 1,
            'unit_price' => 235,
            'currency' => 'USD',
            'document_number' => 'РќР¤РќР¤-000009',
            'donor_vin' => $donorCar->vin,
            'source_file' => 'РџСЂРѕРґР°Р¶ (2).xls',
            'source_row_number' => 36,
            'source_row_hash' => 'legacy-sale-without-product',
            'raw_attributes' => [
                'source_file' => 'РџСЂРѕРґР°Р¶ (2).xls',
            ],
        ]);

        $this->artisan('parts:audit-order-sale-product-links', [
            '--table' => 'sales',
            '--repair' => true,
            '--create-missing-sale-products' => true,
        ])
            ->expectsOutputToContain('sales_created_product')
            ->assertExitCode(0);

        $sale->refresh();
        $product = Product::query()->findOrFail((int) $sale->product_id);
        $catalogItem = PartCatalogItem::query()->findOrFail((int) $sale->part_catalog_item_id);

        $this->assertSame('NC-499', $product->sku);
        $this->assertSame('1084168-S0-E', $product->external_sku);
        $this->assertSame($donorCar->id, $product->donor_car_id);
        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->storage_status);
        $this->assertFalse((bool) $product->is_active);
        $this->assertSame('235.00', (string) $product->selling_price);
        $this->assertSame($catalogItem->id, $product->source_part_catalog_item_id);

        $this->assertSame('nikolacars', $catalogItem->source);
        $this->assertSame('nikolacars://donor-product/'.$product->id, $catalogItem->source_url);
        $this->assertSame($product->id, data_get($catalogItem->raw_attributes, 'product_id'));
        $this->assertSame($officialItem->id, data_get($catalogItem->raw_attributes, 'source_catalog_item_id'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, data_get($catalogItem->raw_attributes, 'storage_status'));
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'stock_quantity'));
        $this->assertSame($product->id, data_get($sale->raw_attributes, 'product_id'));
    }

    protected function linkedProductAndCatalogItem(): array
    {
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/pending',
            'part_number' => '1081421-E0-C',
            'name' => 'Door',
            'price_amount' => 100,
            'currency' => 'USD',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-70282',
            'external_sku' => '1081421-E0-C',
            'name' => 'Door product',
            'slug' => 'door-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 100,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $catalogItem->forceFill([
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
            'raw_attributes' => [
                'product_id' => $product->id,
            ],
        ])->save();

        return [$product, $catalogItem->refresh()];
    }

    protected function order(): CustomerOrder
    {
        return CustomerOrder::query()->create([
            'number' => 'ORD-20260615-0001',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 100,
            'currency' => 'UAH',
        ]);
    }
}
