<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\TeslaOfficialVinSpecificCatalogCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeslaOfficialVinSpecificCatalogCleanupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_relinks_product_to_canonical_official_item_and_deletes_vin_specific_row(): void
    {
        $donorCar = $this->donorCar();
        $canonicalItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1127503-11-D',
            'part_number' => '1127503-11-D',
            'name' => 'PANEL ASSEMBLY',
        ]);
        $vinItem = $this->vinSpecificItem($donorCar, '1127503-11-D');
        $product = $this->product($donorCar, $vinItem, '1127503-11-D');

        $stats = app(TeslaOfficialVinSpecificCatalogCleanupService::class)->cleanupDonor($donorCar);

        $this->assertSame(1, $stats['items_deleted']);
        $this->assertSame(1, $stats['products_relinked']);
        $this->assertSame($canonicalItem->id, $product->refresh()->source_part_catalog_item_id);
        $this->assertDatabaseMissing('part_catalog_items', ['id' => $vinItem->id]);
    }

    public function test_cleanup_creates_common_official_item_when_no_canonical_row_exists(): void
    {
        $donorCar = $this->donorCar('5YJYGDED4MF109751');
        $vinItem = $this->vinSpecificItem($donorCar, '1081401-E0-D');
        $product = $this->product($donorCar, $vinItem, '1081401-E0-D');

        $stats = app(TeslaOfficialVinSpecificCatalogCleanupService::class)->cleanupDonor($donorCar);
        $product->refresh()->load('sourcePartCatalogItem');

        $this->assertSame(1, $stats['items_deleted']);
        $this->assertSame(1, $stats['products_relinked']);
        $this->assertDatabaseMissing('part_catalog_items', ['id' => $vinItem->id]);
        $this->assertNotNull($product->sourcePartCatalogItem);
        $this->assertSame('tesla_official', $product->sourcePartCatalogItem->source);
        $this->assertSame('tesla-common://donor-product/1081401E0D', $product->sourcePartCatalogItem->source_url);
    }

    public function test_cleanup_dry_run_keeps_rows_and_product_links_unchanged(): void
    {
        $donorCar = $this->donorCar('5YJYGDED4MF109752');
        $vinItem = $this->vinSpecificItem($donorCar, '1081421-E0-C');
        $product = $this->product($donorCar, $vinItem, '1081421-E0-C');

        $stats = app(TeslaOfficialVinSpecificCatalogCleanupService::class)->cleanupDonor($donorCar, dryRun: true);

        $this->assertSame(1, $stats['items_would_delete']);
        $this->assertSame(0, $stats['items_deleted']);
        $this->assertSame(1, $stats['products_relinked']);
        $this->assertSame($vinItem->id, $product->refresh()->source_part_catalog_item_id);
        $this->assertDatabaseHas('part_catalog_items', ['id' => $vinItem->id]);
    }

    protected function donorCar(string $vin = '5YJYGDED4MF109750'): DonorCar
    {
        return DonorCar::query()->create([
            'vin' => $vin,
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);
    }

    protected function vinSpecificItem(DonorCar $donorCar, string $partNumber): PartCatalogItem
    {
        return PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm='.$partNumber.'&vin='.$donorCar->vin,
            'part_number' => $partNumber,
            'name' => 'VIN specific part',
            'raw_attributes' => [
                'donor_vin' => $donorCar->vin,
                'donor_car_id' => $donorCar->id,
                'recommendation_type' => 'RECOMMENDED',
            ],
        ]);
    }

    protected function product(DonorCar $donorCar, PartCatalogItem $item, string $partNumber): Product
    {
        return Product::query()->create([
            'sku' => 'DON'.$donorCar->id.'-0001',
            'external_sku' => $partNumber,
            'name' => $item->name,
            'slug' => 'donor-product-'.$donorCar->id.'-'.$partNumber,
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $item->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }
}
