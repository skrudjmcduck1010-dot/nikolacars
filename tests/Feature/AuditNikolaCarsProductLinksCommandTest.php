<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditNikolaCarsProductLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_stale_projection_without_changing_it(): void
    {
        $product = Product::query()->create([
            'sku' => 'NC-70282',
            'external_sku' => '1081421-E0-C',
            'name' => 'Current product',
            'slug' => 'current-product',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 125,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
            'part_number' => '1081439-S0-A',
            'name' => 'Old projection',
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        $this->artisan('parts:audit-nikolacars-product-links', [
            '--dry-run' => true,
            '--product-id' => [$product->id],
        ])
            ->expectsOutputToContain('part_number_mismatch')
            ->expectsOutputToContain('would_repair')
            ->assertExitCode(0);

        $this->assertSame('1081439-S0-A', $item->refresh()->part_number);
        $this->assertSame($item->id, $product->refresh()->source_part_catalog_item_id);
    }

    public function test_repair_updates_stale_projection_for_linked_product(): void
    {
        $product = Product::query()->create([
            'sku' => 'NC-70282',
            'external_sku' => '1081421-E0-C',
            'name' => 'Current product',
            'slug' => 'current-product',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 125,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
            'part_number' => '1081439-S0-A',
            'name' => 'Old projection',
            'price_amount' => 10,
            'currency' => 'USD',
            'raw_attributes' => [
                'stock_quantity' => 2,
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        $this->artisan('parts:audit-nikolacars-product-links', [
            '--repair' => true,
            '--product-id' => [$product->id],
        ])
            ->expectsOutputToContain('repaired')
            ->assertExitCode(0);

        $item->refresh();
        $product->refresh();

        $this->assertSame($item->id, $product->source_part_catalog_item_id);
        $this->assertSame('nikolacars://inventory-product/'.$product->id, $item->source_url);
        $this->assertSame('1081421-E0-C', $item->part_number);
        $this->assertSame('125.00', (string) $item->price_amount);
        $this->assertSame($product->id, data_get($item->raw_attributes, 'product_id'));
    }

    public function test_repair_rehomes_safe_legacy_projection_url(): void
    {
        $product = Product::query()->create([
            'sku' => 'NC-70282',
            'external_sku' => '1081421-E0-C',
            'name' => 'Current product',
            'slug' => 'current-product',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 125,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/legacy-70282',
            'part_number' => '1081421-E0-C',
            'name' => 'Legacy projection',
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        $this->artisan('parts:audit-nikolacars-product-links', [
            '--repair' => true,
            '--product-id' => [$product->id],
        ])->assertExitCode(0);

        $this->assertSame('nikolacars://inventory-product/'.$product->id, $item->refresh()->source_url);
        $this->assertSame(1, PartCatalogItem::query()->where('source', 'nikolacars')->count());
    }

    public function test_repair_skips_projection_owned_by_another_product(): void
    {
        $otherProduct = Product::query()->create([
            'sku' => 'NC-90001',
            'external_sku' => '9000001-00-A',
            'name' => 'Other product',
            'slug' => 'other-product',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $conflictItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/'.$otherProduct->id,
            'part_number' => '9000001-00-A',
            'name' => 'Other projection',
            'raw_attributes' => [
                'product_id' => $otherProduct->id,
            ],
        ]);
        $otherProduct->forceFill(['source_part_catalog_item_id' => $conflictItem->id])->save();
        $product = Product::query()->create([
            'sku' => 'NC-70282',
            'external_sku' => '1081421-E0-C',
            'name' => 'Current product',
            'slug' => 'current-product',
            'source_part_catalog_item_id' => $conflictItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->artisan('parts:audit-nikolacars-product-links', [
            '--repair' => true,
            '--product-id' => [$product->id],
        ])
            ->expectsOutputToContain('conflict')
            ->assertExitCode(0);

        $this->assertSame($conflictItem->id, $product->refresh()->source_part_catalog_item_id);
        $this->assertDatabaseMissing('part_catalog_items', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
        ]);
    }
}
