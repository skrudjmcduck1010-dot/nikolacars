<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditNikolaCarsCatalogIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_active_orphan_without_creating_product(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/orphan-active',
            'part_number' => '1081421-E0-C',
            'name' => 'Orphan active part',
            'price_amount' => 100,
            'currency' => 'USD',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);

        $this->artisan('parts:audit-nikolacars-catalog-integrity', [
            '--dry-run' => true,
            '--active-only' => true,
            '--item-id' => [$item->id],
        ])
            ->expectsOutputToContain('active_missing_linked_product')
            ->assertExitCode(0);

        $this->assertSame(0, Product::query()->count());
        $this->assertNull(data_get($item->refresh()->raw_attributes, 'product_id'));
    }

    public function test_repair_rehomes_legacy_projection_for_existing_product(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/legacy-70282',
            'part_number' => '1081421-E0-C',
            'name' => 'Legacy projection',
            'price_amount' => 125,
            'currency' => 'USD',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-70282',
            'external_sku' => '1081421-E0-C',
            'name' => 'Product 70282',
            'slug' => 'product-70282',
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 125,
            'currency' => 'USD',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'is_active' => true,
        ]);

        $this->artisan('parts:audit-nikolacars-catalog-integrity', [
            '--repair' => true,
            '--item-id' => [$item->id],
        ])
            ->expectsOutputToContain('repaired')
            ->assertExitCode(0);

        $item->refresh();
        $product->refresh();

        $this->assertSame('nikolacars://inventory-product/'.$product->id, $item->source_url);
        $this->assertSame($product->id, data_get($item->raw_attributes, 'product_id'));
        $this->assertSame($item->id, $product->source_part_catalog_item_id);
        $this->assertSame('1081421-E0-C', $item->part_number);
    }

    public function test_repair_skips_projection_when_canonical_target_belongs_to_another_product(): void
    {
        $legacyItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/legacy-conflict',
            'part_number' => '1127503-11-D',
            'name' => 'Legacy conflict projection',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-74342',
            'external_sku' => '1127503-11-D',
            'name' => 'Product 74342',
            'slug' => 'product-74342',
            'source_part_catalog_item_id' => $legacyItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'currency' => 'USD',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'is_active' => true,
        ]);
        $otherProduct = Product::query()->create([
            'sku' => 'NC-OTHER',
            'external_sku' => '9000000-00-A',
            'name' => 'Other product',
            'slug' => 'other-product',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'currency' => 'USD',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'is_active' => true,
        ]);
        $canonicalConflict = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
            'part_number' => '9000000-00-A',
            'name' => 'Conflicting canonical projection',
            'raw_attributes' => [
                'product_id' => $otherProduct->id,
            ],
        ]);
        $otherProduct->forceFill(['source_part_catalog_item_id' => $canonicalConflict->id])->save();

        $this->artisan('parts:audit-nikolacars-catalog-integrity', [
            '--repair' => true,
            '--item-id' => [$legacyItem->id],
        ])
            ->expectsOutputToContain('target_conflict')
            ->expectsOutputToContain('conflict')
            ->assertExitCode(0);

        $this->assertSame('nikolacars://product/legacy-conflict', $legacyItem->refresh()->source_url);
        $this->assertSame($legacyItem->id, $product->refresh()->source_part_catalog_item_id);
        $this->assertSame($otherProduct->id, data_get($canonicalConflict->refresh()->raw_attributes, 'product_id'));
    }
}
